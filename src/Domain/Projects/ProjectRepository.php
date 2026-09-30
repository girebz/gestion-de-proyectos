<?php
/**
 * Repositorio de proyectos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Domain\Projects;

use GDP\Core\Audit;
use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persistencia de la entidad proyecto. Todas las escrituras validan, comprueban
 * la versión (control optimista) y anotan la bitácora.
 */
final class ProjectRepository {

	/**
	 * Campos editables y su tipo de formato para $wpdb.
	 *
	 * @var array<string,string>
	 */
	private const FIELDS = array(
		'code'             => '%s',
		'name'             => '%s',
		'short_name'       => '%s',
		'description'      => '%s',
		'funder'           => '%s',
		'funding_code'     => '%s',
		'executing_entity' => '%s',
		'status'           => '%s',
		'start_date'       => '%s',
		'end_date'         => '%s',
		'budget_total'     => '%f',
		'currency'         => '%s',
		'settings'         => '%s',
	);

	/**
	 * Estados admitidos.
	 *
	 * @var string[]
	 */
	public const STATUSES = array( 'planificacion', 'ejecucion', 'suspendido', 'cierre', 'cerrado' );

	/**
	 * Busca un proyecto por identificador.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Busca un proyecto por código.
	 *
	 * @param string $code Código.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_code( string $code ): ?array {
		global $wpdb;

		$table = Schema::table( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Lista proyectos, opcionalmente restringida a un conjunto de identificadores.
	 *
	 * @param int[]|null $ids    Null = todos.
	 * @param string     $status Filtrar por estado ('' = todos).
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( ?array $ids = null, string $status = '' ): array {
		global $wpdb;

		$table = Schema::table( 'projects' );
		$where = array( '1=1' );
		$args  = array();

		if ( is_array( $ids ) ) {
			if ( empty( $ids ) ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where[]      = "id IN ({$placeholders})";
			$args         = array_merge( $args, array_map( 'intval', $ids ) );
		}

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC';

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Número total de proyectos.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;

		$table = Schema::table( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Valida y normaliza los datos de un proyecto.
	 *
	 * @param array<string,mixed> $data       Datos de entrada.
	 * @param int                 $exclude_id Identificador a excluir en la comprobación de unicidad.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $exclude_id = 0 ) {
		$errors = new WP_Error();
		$clean  = array();

		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];

			switch ( $field ) {
				case 'code':
					$value = sanitize_title( (string) $value );
					if ( '' === $value ) {
						$errors->add( 'code', __( 'El código del proyecto es obligatorio.', 'gestion-de-proyectos' ) );
					} else {
						$existing = self::find_by_code( $value );
						if ( $existing && (int) $existing['id'] !== $exclude_id ) {
							$errors->add( 'code', __( 'Ya existe un proyecto con ese código.', 'gestion-de-proyectos' ) );
						}
					}
					break;

				case 'name':
					$value = sanitize_text_field( (string) $value );
					if ( '' === $value ) {
						$errors->add( 'name', __( 'El nombre del proyecto es obligatorio.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'status':
					$value = sanitize_key( (string) $value );
					if ( ! in_array( $value, self::STATUSES, true ) ) {
						$errors->add( 'status', __( 'Estado de proyecto no válido.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'start_date':
				case 'end_date':
					$value = self::normalize_date( $value );
					if ( false === $value ) {
						$errors->add( $field, __( 'Fecha no válida; use el formato AAAA-MM-DD.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'budget_total':
					if ( null === $value || '' === $value ) {
						$value = null;
					} elseif ( ! is_numeric( $value ) || (float) $value < 0 ) {
						$errors->add( 'budget_total', __( 'El presupuesto debe ser un número no negativo.', 'gestion-de-proyectos' ) );
					} else {
						$value = round( (float) $value, 2 );
					}
					break;

				case 'currency':
					$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
					if ( 3 !== strlen( $value ) ) {
						$errors->add( 'currency', __( 'La moneda debe ser un código de tres letras (por ejemplo, CLP).', 'gestion-de-proyectos' ) );
					}
					break;

				case 'description':
					$value = wp_kses_post( (string) $value );
					break;

				case 'settings':
					if ( is_array( $value ) ) {
						$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
					} elseif ( is_string( $value ) && '' !== $value && null === json_decode( $value, true ) ) {
						$errors->add( 'settings', __( 'La configuración del proyecto debe ser JSON válido.', 'gestion-de-proyectos' ) );
					}
					break;

				default:
					$value = sanitize_text_field( (string) $value );
			}

			$clean[ $field ] = $value;
		}

		if ( isset( $clean['start_date'], $clean['end_date'] ) && $clean['start_date'] && $clean['end_date'] && $clean['end_date'] < $clean['start_date'] ) {
			$errors->add( 'end_date', __( 'La fecha de término no puede ser anterior a la de inicio.', 'gestion-de-proyectos' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $clean;
	}

	/**
	 * Crea un proyecto.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return int|WP_Error Identificador o error.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$data = array_merge(
			array(
				'status'   => 'planificacion',
				'currency' => 'CLP',
			),
			$data
		);

		if ( empty( $data['code'] ) && ! empty( $data['name'] ) ) {
			$data['code'] = sanitize_title( (string) $data['name'] );
		}

		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( empty( $clean['code'] ) || empty( $clean['name'] ) ) {
			return new WP_Error( 'required', __( 'Código y nombre son obligatorios.', 'gestion-de-proyectos' ) );
		}

		$now                 = current_time( 'mysql', true );
		$clean['version']    = 1;
		$clean['created_by'] = get_current_user_id();
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;

		$formats = self::formats( $clean );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( Schema::table( 'projects' ), $clean, $formats );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el proyecto.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;

		// Quien crea el proyecto queda como director salvo que se indique otro perfil.
		if ( get_current_user_id() > 0 ) {
			MemberRepository::set_role( $id, get_current_user_id(), 'director' );
		}

		Audit::log( 'project', $id, 'create', $id, sprintf( 'Proyecto creado: %s', $clean['name'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza un proyecto con control optimista de versión.
	 *
	 * @param int                 $id               Identificador.
	 * @param array<string,mixed> $data             Campos a cambiar.
	 * @param int|null            $expected_version Versión que el cliente vio; null para omitir la comprobación.
	 * @return array<string,mixed>|WP_Error Proyecto actualizado o error.
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
		}

		if ( null !== $expected_version && (int) $current['version'] !== $expected_version ) {
			return new WP_Error(
				'version_conflict',
				sprintf(
					/* translators: 1: versión esperada, 2: versión actual */
					__( 'Conflicto de versión: se esperaba la versión %1$d pero el proyecto está en la %2$d. Vuelva a leerlo antes de modificarlo.', 'gestion-de-proyectos' ),
					$expected_version,
					(int) $current['version']
				)
			);
		}

		$clean = self::validate( $data, $id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( empty( $clean ) ) {
			return $current;
		}

		$clean['version']    = (int) $current['version'] + 1;
		$clean['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( Schema::table( 'projects' ), $clean, array( 'id' => $id ), self::formats( $clean ), array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar el proyecto.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}

		$updated = self::find( $id );
		Audit::log( 'project', $id, 'update', $id, sprintf( 'Proyecto actualizado: %s', $updated['name'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Elimina un proyecto. Los módulos deben limpiar sus datos mediante gdp_project_deleted.
	 *
	 * @param int $id Identificador.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'projects' ), array( 'id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'project_members' ), array( 'project_id' => $id ), array( '%d' ) );

		Audit::log( 'project', $id, 'delete', $id, sprintf( 'Proyecto eliminado: %s', $current['name'] ), $current, null );

		/**
		 * Permite a los módulos limpiar los datos del proyecto eliminado.
		 *
		 * @param int                 $id      Identificador.
		 * @param array<string,mixed> $project Proyecto eliminado.
		 */
		do_action( 'gdp_project_deleted', $id, $current );

		return true;
	}

	/**
	 * Convierte una fila en la representación pública del proyecto.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['version']      = (int) $row['version'];
		$row['created_by']   = (int) $row['created_by'];
		$row['budget_total'] = null === $row['budget_total'] ? null : (float) $row['budget_total'];

		$settings        = is_string( $row['settings'] ) ? json_decode( $row['settings'], true ) : null;
		$row['settings'] = is_array( $settings ) ? $settings : array();

		return $row;
	}

	/**
	 * Formatos de $wpdb para un conjunto de campos.
	 *
	 * @param array<string,mixed> $data Campos.
	 * @return string[]
	 */
	private static function formats( array $data ): array {
		$formats = array();
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( self::FIELDS[ $field ] ) ) {
				$formats[] = null === $data[ $field ] ? '%s' : self::FIELDS[ $field ];
			} elseif ( in_array( $field, array( 'version', 'created_by' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}

	/**
	 * Normaliza una fecha a AAAA-MM-DD; null si viene vacía; false si es inválida.
	 *
	 * @param mixed $value Valor.
	 * @return string|null|false
	 */
	private static function normalize_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = trim( (string) $value );
		$date  = \DateTime::createFromFormat( 'Y-m-d', $value );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return false;
		}

		return $value;
	}
}
