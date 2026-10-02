<?php
/**
 * Base de los repositorios del módulo de finanzas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Procurement\PurchaseRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Acceso uniforme a las tablas finance_*: lectura con conversión de tipos,
 * inserción, actualización con control optimista de versión, borrado y
 * bitácora. Cada repositorio declara su tabla, sus conversiones y su
 * validación.
 */
abstract class Repository {

	protected const TABLE  = '';
	protected const ENTITY = '';
	protected const CASTS  = array();

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	public static function table(): string {
		return Schema::table( static::TABLE );
	}

	/**
	 * Un registro por identificador.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = static::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? static::hydrate( $row ) : null;
	}

	/**
	 * Registros de un proyecto.
	 *
	 * @param int      $project_id Proyecto.
	 * @param string   $order      Orden SQL.
	 * @param string[] $where      Condiciones adicionales (con marcadores).
	 * @param mixed[]  $args       Argumentos de los marcadores.
	 * @param int      $limit      Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, string $order = 'id ASC', array $where = array(), array $args = array(), int $limit = 5000 ): array {
		global $wpdb;

		$table = static::table();
		$sql   = "SELECT * FROM {$table} WHERE project_id = %d" . ( $where ? ' AND ' . implode( ' AND ', $where ) : '' ) . " ORDER BY {$order} LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $project_id ), $args, array( $limit ) ) ), ARRAY_A );

		return array_map( array( static::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Convierte una fila en un registro con tipos nativos.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( static::CASTS as $column => $cast ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}
			switch ( $cast ) {
				case 'int':
					$row[ $column ] = (int) $row[ $column ];
					break;
				case 'float':
					$row[ $column ] = (float) $row[ $column ];
					break;
				case 'bool':
					$row[ $column ] = (bool) $row[ $column ];
					break;
				case 'json':
					$decoded        = is_string( $row[ $column ] ) ? json_decode( $row[ $column ], true ) : $row[ $column ];
					$row[ $column ] = is_array( $decoded ) ? $decoded : array();
					break;
				case 'date':
					$row[ $column ] = empty( $row[ $column ] ) || '0000-00-00' === $row[ $column ] ? null : (string) $row[ $column ];
					break;
				default:
					$row[ $column ] = null === $row[ $column ] ? '' : (string) $row[ $column ];
			}
		}
		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}
		if ( isset( $row['project_id'] ) ) {
			$row['project_id'] = (int) $row['project_id'];
		}
		if ( isset( $row['version'] ) ) {
			$row['version'] = (int) $row['version'];
		}

		return $row;
	}

	/**
	 * Prepara los valores para la base de datos (JSON codificado).
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>
	 */
	protected static function dehydrate( array $data ): array {
		foreach ( static::CASTS as $column => $cast ) {
			if ( 'json' === $cast && array_key_exists( $column, $data ) && ! is_string( $data[ $column ] ) ) {
				$data[ $column ] = wp_json_encode( $data[ $column ], JSON_UNESCAPED_UNICODE );
			}
		}

		return $data;
	}

	/**
	 * Inserta un registro.
	 *
	 * @param array<string,mixed> $data    Datos limpios (con project_id).
	 * @param string              $summary Resumen para la bitácora.
	 * @return int|WP_Error
	 */
	protected static function insert( array $data, string $summary = '' ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		if ( in_array( 'created_at', static::columns(), true ) ) {
			$data['created_at'] = $now;
		}
		if ( in_array( 'updated_at', static::columns(), true ) ) {
			$data['updated_at'] = $now;
		}
		if ( in_array( 'created_by', static::columns(), true ) && empty( $data['created_by'] ) ) {
			$data['created_by'] = get_current_user_id();
		}
		if ( in_array( 'version', static::columns(), true ) ) {
			$data['version'] = 1;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( static::table(), static::dehydrate( $data ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el registro.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		if ( '' !== $summary && '' !== static::ENTITY ) {
			Audit::log( static::ENTITY, $id, 'create', (int) ( $data['project_id'] ?? 0 ), $summary, null, static::find( $id ) );
		}

		return $id;
	}

	/**
	 * Actualiza un registro con control optimista de versión.
	 *
	 * @param int                 $id               Registro.
	 * @param array<string,mixed> $data             Campos limpios.
	 * @param int|null            $expected_version Versión esperada.
	 * @param string              $summary          Resumen para la bitácora.
	 * @return array<string,mixed>|WP_Error
	 */
	protected static function update_row( int $id, array $data, ?int $expected_version = null, string $summary = '' ) {
		global $wpdb;

		$current = static::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El registro no existe.', 'gestion-de-proyectos' ) );
		}
		if ( null !== $expected_version && isset( $current['version'] ) && $expected_version !== $current['version'] ) {
			return new WP_Error( 'version_conflict', sprintf( 'El registro cambió (versión %d, se esperaba %d). Vuelva a leerlo antes de modificarlo.', $current['version'], $expected_version ) );
		}
		if ( empty( $data ) ) {
			return $current;
		}
		if ( in_array( 'updated_at', static::columns(), true ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
		}
		if ( isset( $current['version'] ) ) {
			$data['version'] = $current['version'] + 1;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( static::table(), static::dehydrate( $data ), array( 'id' => $id ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar el registro.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = static::find( $id );
		if ( '' !== $summary && '' !== static::ENTITY ) {
			Audit::log( static::ENTITY, $id, 'update', (int) ( $current['project_id'] ?? 0 ), $summary, $current, $updated );
		}

		return $updated ?? $current;
	}

	/**
	 * Elimina un registro.
	 *
	 * @param int    $id      Registro.
	 * @param string $summary Resumen para la bitácora.
	 * @return bool
	 */
	protected static function delete_row( int $id, string $summary = '' ): bool {
		global $wpdb;

		$current = static::find( $id );
		if ( ! $current ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( static::table(), array( 'id' => $id ), array( '%d' ) );
		if ( '' !== $summary && '' !== static::ENTITY ) {
			Audit::log( static::ENTITY, $id, 'delete', (int) ( $current['project_id'] ?? 0 ), $summary, $current, null );
		}

		return true;
	}

	/**
	 * Elimina los registros de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( static::table(), array( 'project_id' => $project_id ), array( '%d' ) );
	}

	/**
	 * Columnas de la tabla según el esquema.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		static $cache = array();
		if ( ! isset( $cache[ static::TABLE ] ) ) {
			$sql = Schema::definitions()[ static::TABLE ] ?? '';
			preg_match_all( '/^\s*([a-z_]+)\s+(?:bigint|int|tinyint|varchar|char|text|longtext|date|datetime|decimal)/mi', $sql, $m );
			$cache[ static::TABLE ] = $m[1] ?? array();
		}

		return $cache[ static::TABLE ];
	}

	/**
	 * Normaliza una fecha; null si está vacía, false si no es válida.
	 *
	 * @param mixed $value Valor.
	 * @return string|null|false
	 */
	public static function date( $value ) {
		return ActivityRepository::normalize_date( $value );
	}

	/**
	 * Convierte un monto escrito con separadores chilenos en número.
	 *
	 * @param mixed $value Valor.
	 * @return float|null
	 */
	public static function number( $value ): ?float {
		return PurchaseRepository::number( $value );
	}

	/**
	 * Limpia un conjunto de campos según su tipo.
	 *
	 * @param array<string,mixed>  $data   Datos.
	 * @param array<string,string> $fields campo => tipo (text, textarea, date, money, int, key, period, bool, json).
	 * @param WP_Error             $errors Acumulador de errores.
	 * @return array<string,mixed>
	 */
	protected static function clean( array $data, array $fields, WP_Error $errors ): array {
		$clean = array();
		foreach ( $fields as $field => $type ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];
			switch ( $type ) {
				case 'text':
					$clean[ $field ] = sanitize_text_field( (string) $value );
					break;
				case 'textarea':
					$clean[ $field ] = sanitize_textarea_field( (string) $value );
					break;
				case 'key':
					$clean[ $field ] = sanitize_key( (string) $value );
					break;
				case 'date':
					$date = self::date( $value );
					if ( false === $date ) {
						/* translators: nombre del campo. */
						$errors->add( $field, sprintf( __( 'La fecha de %s no es válida (use AAAA-MM-DD).', 'gestion-de-proyectos' ), $field ) );
					} else {
						$clean[ $field ] = $date;
					}
					break;
				case 'money':
					$number = self::number( $value );
					if ( null === $number && '' !== trim( (string) $value ) ) {
						/* translators: nombre del campo. */
						$errors->add( $field, sprintf( __( 'El monto de %s no es válido.', 'gestion-de-proyectos' ), $field ) );
					} else {
						$clean[ $field ] = round( (float) $number, 2 );
					}
					break;
				case 'int':
					$clean[ $field ] = (int) $value;
					break;
				case 'bool':
					$clean[ $field ] = ! empty( $value ) ? 1 : 0;
					break;
				case 'period':
					$period = trim( (string) $value );
					if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $period ) ) {
						$errors->add( $field, __( 'El período debe tener la forma AAAA-MM.', 'gestion-de-proyectos' ) );
					} else {
						$clean[ $field ] = $period;
					}
					break;
				case 'json':
					$clean[ $field ] = is_array( $value ) ? $value : ( is_string( $value ) && '' !== $value ? (array) json_decode( $value, true ) : array() );
					break;
			}
		}

		return $clean;
	}
}
