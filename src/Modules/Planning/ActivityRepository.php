<?php
/**
 * Repositorio de actividades (estructura de desglose del trabajo).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Planning\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Actividades, hitos y resúmenes de un proyecto, organizados en árbol.
 *
 * Los campos de programación (start_date, end_date, late_start, late_finish,
 * total_float, free_float, is_critical, schedule_conflicts) los escribe
 * únicamente ScheduleService.
 */
final class ActivityRepository {

	public const KINDS      = array( 'summary', 'activity', 'milestone' );
	public const STATUSES   = array( 'pendiente', 'en_curso', 'terminada', 'suspendida', 'cancelada' );
	public const PRIORITIES = array( 1, 2, 3 );

	/**
	 * Campos editables y su formato para $wpdb.
	 *
	 * @var array<string,string>
	 */
	private const FIELDS = array(
		'parent_id'       => '%d',
		'name'            => '%s',
		'description'     => '%s',
		'kind'            => '%s',
		'work_front'      => '%s',
		'status'          => '%s',
		'priority'        => '%d',
		'sort_order'      => '%d',
		'duration'        => '%d',
		'constraint_type' => '%s',
		'constraint_date' => '%s',
		'actual_start'    => '%s',
		'actual_finish'   => '%s',
		'percent'         => '%d',
		'owner_id'        => '%d',
		'deliverable'     => '%s',
		'budget_line'     => '%s',
		'cost_planned'    => '%f',
		'notes'           => '%s',
	);

	/**
	 * Campos calculados por el motor de programación.
	 *
	 * @var array<string,string>
	 */
	private const SCHEDULE_FIELDS = array(
		'start_date'         => '%s',
		'end_date'           => '%s',
		'late_start'         => '%s',
		'late_finish'        => '%s',
		'total_float'        => '%d',
		'free_float'         => '%d',
		'is_critical'        => '%d',
		'schedule_conflicts' => '%s',
	);

	/**
	 * Etiquetas de los estados.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'pendiente'  => __( 'Pendiente', 'gestion-de-proyectos' ),
			'en_curso'   => __( 'En curso', 'gestion-de-proyectos' ),
			'terminada'  => __( 'Terminada', 'gestion-de-proyectos' ),
			'suspendida' => __( 'Suspendida', 'gestion-de-proyectos' ),
			'cancelada'  => __( 'Cancelada', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de los tipos.
	 *
	 * @return array<string,string>
	 */
	public static function kind_labels(): array {
		return array(
			'summary'   => __( 'Resumen (fase o paquete de trabajo)', 'gestion-de-proyectos' ),
			'activity'  => __( 'Actividad', 'gestion-de-proyectos' ),
			'milestone' => __( 'Hito', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de las restricciones.
	 *
	 * @return array<string,string>
	 */
	public static function constraint_labels(): array {
		return array(
			'asap' => __( 'Lo antes posible', 'gestion-de-proyectos' ),
			'snet' => __( 'No empezar antes de', 'gestion-de-proyectos' ),
			'snlt' => __( 'No empezar después de', 'gestion-de-proyectos' ),
			'fnet' => __( 'No terminar antes de', 'gestion-de-proyectos' ),
			'fnlt' => __( 'No terminar después de', 'gestion-de-proyectos' ),
			'mso'  => __( 'Debe empezar el', 'gestion-de-proyectos' ),
			'mfo'  => __( 'Debe terminar el', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de prioridad.
	 *
	 * @return array<int,string>
	 */
	public static function priority_labels(): array {
		return array(
			1 => __( 'Alta', 'gestion-de-proyectos' ),
			2 => __( 'Media', 'gestion-de-proyectos' ),
			3 => __( 'Baja', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Una actividad.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Actividades de un proyecto en orden de árbol (profundidad primero), con nivel.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $filters    Filtros: status, kind, owner_id, work_front, search, critical.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, array $filters = array() ): array {
		global $wpdb;

		$table = Schema::table( 'activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY parent_id ASC, sort_order ASC, id ASC", $project_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$children = array();
		foreach ( $rows as $row ) {
			$children[ (int) $row['parent_id'] ][] = self::hydrate( $row );
		}

		$ordered = array();
		self::walk( $children, 0, 0, $ordered );

		if ( empty( $filters ) ) {
			return $ordered;
		}

		return array_values( array_filter( $ordered, static fn( array $a ): bool => self::matches( $a, $filters ) ) );
	}

	/**
	 * Árbol anidado (cada nodo con "children").
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function tree( int $project_id ): array {
		$flat  = self::for_project( $project_id );
		$index = array();
		foreach ( $flat as $a ) {
			$a['children']      = array();
			$index[ $a['id'] ]  = $a;
		}
		$roots = array();
		foreach ( $index as $id => $a ) {
			$parent = $a['parent_id'];
			if ( $parent > 0 && isset( $index[ $parent ] ) ) {
				$index[ $parent ]['children'][] = &$index[ $id ];
			} else {
				$roots[] = &$index[ $id ];
			}
		}

		return $roots;
	}

	/**
	 * Hijos directos.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $parent_id  Padre (0 = raíz).
	 * @return array<int,array<string,mixed>>
	 */
	public static function children( int $project_id, int $parent_id ): array {
		return array_values( array_filter( self::for_project( $project_id ), static fn( array $a ): bool => $a['parent_id'] === $parent_id ) );
	}

	/**
	 * Identificadores de los descendientes de una actividad.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $id         Actividad.
	 * @return int[]
	 */
	public static function descendant_ids( int $project_id, int $id ): array {
		$out   = array();
		$stack = array( $id );
		$all   = self::for_project( $project_id );

		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			foreach ( $all as $a ) {
				if ( $a['parent_id'] === $current ) {
					$out[]   = $a['id'];
					$stack[] = $a['id'];
				}
			}
		}

		return $out;
	}

	/**
	 * Número de actividades por proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return int
	 */
	public static function count( int $project_id ): int {
		global $wpdb;

		$table = Schema::table( 'activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id = %d", $project_id ) );
	}

	/**
	 * Valida y normaliza datos de una actividad.
	 *
	 * @param array<string,mixed> $data       Datos.
	 * @param int                 $project_id Proyecto.
	 * @param int                 $exclude_id Actividad que se edita (0 al crear).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $project_id, int $exclude_id = 0 ) {
		$errors  = new WP_Error();
		$clean   = array();
		$current = $exclude_id > 0 ? self::find( $exclude_id ) : null;

		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];

			switch ( $field ) {
				case 'name':
					$value = sanitize_text_field( (string) $value );
					if ( '' === $value ) {
						$errors->add( 'name', __( 'El nombre de la actividad es obligatorio.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'kind':
					$value = sanitize_key( (string) $value );
					if ( ! in_array( $value, self::KINDS, true ) ) {
						$errors->add( 'kind', __( 'Tipo de actividad no válido (summary, activity, milestone).', 'gestion-de-proyectos' ) );
					}
					break;

				case 'parent_id':
					$value = (int) $value;
					if ( $value > 0 ) {
						$parent = self::find( $value );
						if ( ! $parent || $parent['project_id'] !== $project_id ) {
							$errors->add( 'parent_id', __( 'La actividad padre no existe en este proyecto.', 'gestion-de-proyectos' ) );
						} elseif ( 'summary' !== $parent['kind'] ) {
							$errors->add( 'parent_id', __( 'Solo un resumen puede contener otras actividades.', 'gestion-de-proyectos' ) );
						} elseif ( $exclude_id > 0 && ( $value === $exclude_id || in_array( $value, self::descendant_ids( $project_id, $exclude_id ), true ) ) ) {
							$errors->add( 'parent_id', __( 'Una actividad no puede colgar de sí misma ni de sus descendientes.', 'gestion-de-proyectos' ) );
						}
					}
					break;

				case 'status':
					$value = sanitize_key( (string) $value );
					if ( ! in_array( $value, self::STATUSES, true ) ) {
						$errors->add( 'status', __( 'Estado de actividad no válido.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'priority':
					$value = (int) $value;
					if ( ! in_array( $value, self::PRIORITIES, true ) ) {
						$errors->add( 'priority', __( 'La prioridad debe ser 1 (alta), 2 (media) o 3 (baja).', 'gestion-de-proyectos' ) );
					}
					break;

				case 'duration':
					$value = (int) $value;
					if ( $value < 0 || $value > 3650 ) {
						$errors->add( 'duration', __( 'La duración se expresa en días hábiles (0 a 3650).', 'gestion-de-proyectos' ) );
					}
					break;

				case 'constraint_type':
					$value = sanitize_key( (string) $value );
					if ( ! in_array( $value, Scheduler::CONSTRAINTS, true ) ) {
						$errors->add( 'constraint_type', __( 'Restricción no válida (asap, snet, snlt, fnet, fnlt, mso, mfo).', 'gestion-de-proyectos' ) );
					}
					break;

				case 'constraint_date':
				case 'actual_start':
				case 'actual_finish':
					$value = self::normalize_date( $value );
					if ( false === $value ) {
						$errors->add( $field, __( 'Fecha no válida; use el formato AAAA-MM-DD.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'percent':
					$value = (int) $value;
					if ( $value < 0 || $value > 100 ) {
						$errors->add( 'percent', __( 'El avance se expresa de 0 a 100.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'owner_id':
					$value = (int) $value;
					if ( $value > 0 && ! get_userdata( $value ) ) {
						$errors->add( 'owner_id', __( 'El responsable indicado no existe.', 'gestion-de-proyectos' ) );
					}
					break;

				case 'cost_planned':
					if ( null === $value || '' === $value ) {
						$value = null;
					} elseif ( ! is_numeric( $value ) || (float) $value < 0 ) {
						$errors->add( 'cost_planned', __( 'El costo planificado debe ser un número no negativo.', 'gestion-de-proyectos' ) );
					} else {
						$value = round( (float) $value, 2 );
					}
					break;

				case 'sort_order':
					$value = (int) $value;
					break;

				case 'work_front':
				case 'budget_line':
					$value = sanitize_key( (string) $value );
					break;

				case 'description':
				case 'notes':
					$value = wp_kses_post( (string) $value );
					break;

				default:
					$value = sanitize_text_field( (string) $value );
			}

			$clean[ $field ] = $value;
		}

		$merged = array_merge( $current ?? array(), $clean );

		if ( isset( $merged['constraint_type'] ) && 'asap' !== $merged['constraint_type'] && empty( $merged['constraint_date'] ) ) {
			$errors->add( 'constraint_date', __( 'La restricción elegida necesita una fecha.', 'gestion-de-proyectos' ) );
		}

		$kind = (string) ( $merged['kind'] ?? 'activity' );
		if ( 'milestone' === $kind ) {
			$clean['duration'] = 0;
		} elseif ( 'activity' === $kind && isset( $merged['duration'] ) && (int) $merged['duration'] < 1 ) {
			$errors->add( 'duration', __( 'Una actividad dura al menos un día hábil; para duración cero use un hito.', 'gestion-de-proyectos' ) );
		}

		// Un resumen con hijos no puede pasar a actividad ni hito.
		if ( $current && 'summary' === $current['kind'] && 'summary' !== $kind && ! empty( self::descendant_ids( $project_id, $exclude_id ) ) ) {
			$errors->add( 'kind', __( 'El resumen tiene actividades dentro; muévalas antes de cambiar su tipo.', 'gestion-de-proyectos' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$clean  = self::progress_rules( $clean, $current );
		$merged = array_merge( $current ?? array(), $clean );
		if ( ! empty( $merged['actual_start'] ) && ! empty( $merged['actual_finish'] ) && $merged['actual_finish'] < $merged['actual_start'] ) {
			return new WP_Error( 'actual_finish', __( 'La fecha real de término no puede ser anterior a la de inicio.', 'gestion-de-proyectos' ) );
		}

		return $clean;
	}

	/**
	 * Reglas de coherencia entre avance, estado y fechas reales.
	 *
	 * @param array<string,mixed>      $clean   Campos validados.
	 * @param array<string,mixed>|null $current Estado actual.
	 * @return array<string,mixed>
	 */
	private static function progress_rules( array $clean, ?array $current ): array {
		$merged = array_merge( $current ?? array(), $clean );
		$kind   = (string) ( $merged['kind'] ?? 'activity' );
		if ( 'summary' === $kind ) {
			return $clean;
		}

		$status  = (string) ( $merged['status'] ?? 'pendiente' );
		$percent = (int) ( $merged['percent'] ?? 0 );

		// Reapertura explícita: bajar el avance de una actividad terminada (sin tocar estado ni término real) la reabre.
		$was_done = 'terminada' === (string) ( $current['status'] ?? '' );
		if ( $was_done && array_key_exists( 'percent', $clean ) && $percent < 100 && ! array_key_exists( 'status', $clean ) && ! array_key_exists( 'actual_finish', $clean ) ) {
			$status                  = $percent > 0 ? 'en_curso' : 'pendiente';
			$clean['actual_finish']  = null;
			$merged['actual_finish'] = null;
		}
		// Reapertura por estado: cambiar el estado de una actividad terminada descarta el término real.
		if ( $was_done && array_key_exists( 'status', $clean ) && 'terminada' !== $status && ! array_key_exists( 'actual_finish', $clean ) ) {
			$clean['actual_finish']  = null;
			$merged['actual_finish'] = null;
			if ( 100 === $percent ) {
				$percent = 'pendiente' === $status ? 0 : 90;
			}
		}

		// Volver a pendiente explícitamente borra el avance y las fechas reales.
		if ( array_key_exists( 'status', $clean ) && 'pendiente' === $status && ! array_key_exists( 'actual_start', $clean ) && ! array_key_exists( 'percent', $clean ) ) {
			$percent                 = 0;
			$clean['actual_start']   = null;
			$clean['actual_finish']  = null;
			$merged['actual_start']  = null;
			$merged['actual_finish'] = null;
		}

		if ( ! empty( $merged['actual_finish'] ) || 'terminada' === $status ) {
			$percent = 100;
			$status  = 'terminada';
			if ( empty( $merged['actual_finish'] ) && array_key_exists( 'status', $clean ) ) {
				$clean['actual_finish'] = max( current_time( 'Y-m-d' ), (string) ( $merged['actual_start'] ?? '' ) );
			}
			if ( 'milestone' !== $kind && empty( $merged['actual_start'] ) ) {
				$clean['actual_start'] = $clean['actual_finish'] ?? $merged['actual_finish'] ?? current_time( 'Y-m-d' );
			}
		} elseif ( 100 === $percent ) {
			$status = 'terminada';
			if ( empty( $merged['actual_finish'] ) ) {
				$clean['actual_finish'] = max( current_time( 'Y-m-d' ), (string) ( $merged['actual_start'] ?? '' ) );
			}
			if ( 'milestone' !== $kind && empty( $merged['actual_start'] ) ) {
				$clean['actual_start'] = current_time( 'Y-m-d' );
			}
		} elseif ( ( $percent > 0 || ! empty( $merged['actual_start'] ) ) && in_array( $status, array( 'pendiente', 'terminada' ), true ) ) {
			$status = 'en_curso';
		}

		$clean['status']  = $status;
		$clean['percent'] = $percent;

		return $clean;
	}

	/**
	 * Crea una actividad.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$data = array_merge(
			array(
				'parent_id'       => 0,
				'kind'            => 'activity',
				'status'          => 'pendiente',
				'priority'        => 2,
				'duration'        => 1,
				'constraint_type' => 'asap',
				'percent'         => 0,
			),
			$data
		);

		$clean = self::validate( $data, $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['name'] ) ) {
			return new WP_Error( 'required', __( 'El nombre de la actividad es obligatorio.', 'gestion-de-proyectos' ) );
		}

		if ( ! array_key_exists( 'sort_order', $data ) ) {
			$table = Schema::table( 'activities' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clean['sort_order'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table} WHERE project_id = %d AND parent_id = %d", $project_id, (int) $clean['parent_id'] ) );
		}

		$now                 = current_time( 'mysql', true );
		$clean['project_id'] = $project_id;
		$clean['version']    = 1;
		$clean['created_by'] = get_current_user_id();
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( Schema::table( 'activities' ), $clean, self::formats( $clean ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la actividad.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;
		self::recompute_codes( $project_id );

		Audit::log( 'activity', $id, 'create', $project_id, sprintf( 'Actividad creada: %s', $clean['name'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza una actividad con control optimista de versión.
	 *
	 * @param int                 $id               Actividad.
	 * @param array<string,mixed> $data             Campos.
	 * @param int|null            $expected_version Versión esperada (null = sin comprobar).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La actividad no existe.', 'gestion-de-proyectos' ) );
		}

		if ( null !== $expected_version && $current['version'] !== $expected_version ) {
			return new WP_Error(
				'version_conflict',
				sprintf(
					/* translators: 1: versión esperada, 2: versión actual */
					__( 'Conflicto de versión: se esperaba la versión %1$d pero la actividad está en la %2$d. Vuelva a leerla antes de modificarla.', 'gestion-de-proyectos' ),
					$expected_version,
					$current['version']
				)
			);
		}

		$clean = self::validate( $data, $current['project_id'], $id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// Sin cambios efectivos: no se toca la versión.
		$changed = array();
		foreach ( $clean as $field => $value ) {
			if ( ( $current[ $field ] ?? null ) !== $value ) {
				$changed[ $field ] = $value;
			}
		}
		if ( empty( $changed ) ) {
			return $current;
		}

		$changed['version']    = $current['version'] + 1;
		$changed['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( Schema::table( 'activities' ), $changed, array( 'id' => $id ), self::formats( $changed ), array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar la actividad.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}

		if ( isset( $changed['parent_id'] ) || isset( $changed['sort_order'] ) ) {
			self::recompute_codes( $current['project_id'] );
		}

		$updated = self::find( $id );
		Audit::log( 'activity', $id, 'update', $current['project_id'], sprintf( 'Actividad actualizada: %s', $updated['name'] ), $current, $updated );

		// Todo cambio de avance, estado o fechas reales deja huella para el informe semanal.
		if ( 'summary' !== $updated['kind'] && array_intersect_key( $changed, array_flip( array( 'percent', 'status', 'actual_start', 'actual_finish' ) ) ) ) {
			ProgressRepository::record(
				$current['project_id'],
				$id,
				$updated['percent'],
				$current['percent'],
				$updated['status'],
				$updated['actual_start'],
				$updated['actual_finish'],
				isset( $data['progress_note'] ) ? (string) $data['progress_note'] : '',
				Audit::channel()
			);
		}

		return $updated;
	}

	/**
	 * Elimina una actividad con sus descendientes, dependencias, asignaciones y avances.
	 *
	 * @param int $id Actividad.
	 * @return array<string,mixed>|WP_Error Instantánea eliminada (para revertir) o error.
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La actividad no existe.', 'gestion-de-proyectos' ) );
		}

		$project_id = $current['project_id'];
		$ids        = array_merge( array( $id ), self::descendant_ids( $project_id, $id ) );
		$snapshot   = array(
			'activities'   => array(),
			'dependencies' => array(),
			'assignments'  => array(),
		);

		foreach ( $ids as $aid ) {
			$snapshot['activities'][]   = self::find( $aid );
			$snapshot['dependencies']   = array_merge( $snapshot['dependencies'], DependencyRepository::for_activity( $aid ) );
			$snapshot['assignments']    = array_merge( $snapshot['assignments'], AssignmentRepository::for_activity( $aid ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$tables       = array(
			Schema::table( 'activities' )   => 'id',
			Schema::table( 'assignments' )  => 'activity_id',
			Schema::table( 'progress' )     => 'activity_id',
		);
		foreach ( $tables as $table => $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", $ids ) );
		}
		$deps = Schema::table( 'dependencies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$deps} WHERE project_id = %d AND ((predecessor_type = 'activity' AND predecessor_id IN ({$placeholders})) OR successor_id IN ({$placeholders}))", array_merge( array( $project_id ), $ids, $ids ) ) );

		self::recompute_codes( $project_id );

		Audit::log( 'activity', $id, 'delete', $project_id, sprintf( 'Actividad eliminada: %s (%d registros)', $current['name'], count( $ids ) ), $current, null );

		return $snapshot;
	}

	/**
	 * Restaura una instantánea creada por delete() (mismos identificadores).
	 *
	 * @param array<string,mixed> $snapshot Instantánea.
	 * @return bool
	 */
	public static function restore( array $snapshot ): bool {
		global $wpdb;

		$project_id = 0;
		foreach ( $snapshot['activities'] ?? array() as $a ) {
			if ( ! is_array( $a ) || empty( $a['id'] ) ) {
				continue;
			}
			$project_id = (int) $a['project_id'];
			$row        = array_intersect_key( $a, array_merge( self::FIELDS, self::SCHEDULE_FIELDS, array_flip( array( 'id', 'project_id', 'code', 'version', 'created_by', 'created_at', 'updated_at' ) ) ) );
			if ( is_array( $row['schedule_conflicts'] ?? null ) ) {
				$row['schedule_conflicts'] = empty( $row['schedule_conflicts'] ) ? null : wp_json_encode( $row['schedule_conflicts'], JSON_UNESCAPED_UNICODE );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace( Schema::table( 'activities' ), $row, self::formats( $row ) );
		}
		foreach ( $snapshot['dependencies'] ?? array() as $d ) {
			DependencyRepository::add( (int) $d['project_id'], (int) $d['predecessor_id'], (int) $d['successor_id'], (string) $d['type'], (int) $d['lag'], (string) ( $d['predecessor_type'] ?? 'activity' ) );
		}
		foreach ( $snapshot['assignments'] ?? array() as $s ) {
			AssignmentRepository::set( (int) $s['project_id'], (int) $s['activity_id'], (int) $s['user_id'], (string) $s['role'], (int) $s['allocation'] );
		}

		if ( $project_id > 0 ) {
			self::recompute_codes( $project_id );
		}

		return true;
	}

	/**
	 * Mueve una actividad a otro padre o posición.
	 *
	 * @param int      $id         Actividad.
	 * @param int|null $parent_id  Nuevo padre (null = sin cambio).
	 * @param int|null $sort_order Nueva posición (null = al final).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function move( int $id, ?int $parent_id, ?int $sort_order ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La actividad no existe.', 'gestion-de-proyectos' ) );
		}
		$parent_id = $parent_id ?? $current['parent_id'];
		$data      = array( 'parent_id' => $parent_id );

		if ( null === $sort_order ) {
			$table = Schema::table( 'activities' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sort_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table} WHERE project_id = %d AND parent_id = %d AND id <> %d", $current['project_id'], $parent_id, $id ) );
		}
		$data['sort_order'] = $sort_order;

		$updated = self::update( $id, $data, null );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Reasigna posiciones consecutivas entre los hermanos, dejando la movida donde se pidió.
		$siblings = self::children( $current['project_id'], $parent_id );
		usort(
			$siblings,
			static function ( array $a, array $b ) use ( $id ): int {
				if ( $a['sort_order'] === $b['sort_order'] ) {
					return $a['id'] === $id ? -1 : ( $b['id'] === $id ? 1 : $a['id'] <=> $b['id'] );
				}
				return $a['sort_order'] <=> $b['sort_order'];
			}
		);
		$position = 1;
		foreach ( $siblings as $s ) {
			if ( $s['sort_order'] !== $position ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'activities' ), array( 'sort_order' => $position ), array( 'id' => $s['id'] ), array( '%d' ), array( '%d' ) );
			}
			++$position;
		}
		self::recompute_codes( $current['project_id'] );

		return self::find( $id );
	}

	/**
	 * Recalcula los códigos de la estructura de desglose (1, 1.1, 1.2, 2...).
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function recompute_codes( int $project_id ): void {
		global $wpdb;

		$table = Schema::table( 'activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, parent_id, code FROM {$table} WHERE project_id = %d ORDER BY parent_id ASC, sort_order ASC, id ASC", $project_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}

		$children = array();
		foreach ( $rows as $row ) {
			$children[ (int) $row['parent_id'] ][] = $row;
		}

		$assign = static function ( int $parent, string $prefix ) use ( &$assign, &$children, $wpdb, $table ): void {
			$position = 1;
			foreach ( $children[ $parent ] ?? array() as $row ) {
				$code = '' === $prefix ? (string) $position : $prefix . '.' . $position;
				if ( $row['code'] !== $code ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( $table, array( 'code' => $code ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
				}
				$assign( (int) $row['id'], $code );
				++$position;
			}
		};
		$assign( 0, '' );
	}

	/**
	 * Guarda los resultados del motor de programación.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param array<int,array<string,mixed>> $nodes      Nodos calculados (id => datos).
	 * @return void
	 */
	public static function store_schedule( int $project_id, array $nodes ): void {
		global $wpdb;

		$table = Schema::table( 'activities' );
		foreach ( $nodes as $id => $n ) {
			$data = array(
				'start_date'         => $n['start_date'],
				'end_date'           => $n['end_date'],
				'late_start'         => $n['late_start'],
				'late_finish'        => $n['late_finish'],
				'total_float'        => (int) $n['total_float'],
				'free_float'         => (int) $n['free_float'],
				'is_critical'        => $n['critical'] ? 1 : 0,
				'schedule_conflicts' => empty( $n['conflicts'] ) ? null : wp_json_encode( $n['conflicts'], JSON_UNESCAPED_UNICODE ),
			);
			if ( $n['is_summary'] ) {
				$data['percent']  = (int) $n['percent'];
				$data['duration'] = (int) $n['duration'];
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => (int) $id, 'project_id' => $project_id ), self::formats( $data ), array( '%d', '%d' ) );
		}
	}

	/**
	 * Resumen numérico del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function stats( int $project_id ): array {
		$all     = self::for_project( $project_id );
		$leaves  = array_values( array_filter( $all, static fn( array $a ): bool => 'summary' !== $a['kind'] ) );
		$by      = array_fill_keys( self::STATUSES, 0 );
		$weight  = 0;
		$earned  = 0.0;
		$crit    = 0;
		$today   = current_time( 'Y-m-d' );
		$overdue = 0;

		foreach ( $leaves as $a ) {
			++$by[ $a['status'] ];
			$w       = max( 1, $a['duration'] );
			$weight += $w;
			$earned += $w * $a['percent'];
			if ( $a['is_critical'] ) {
				++$crit;
			}
			if ( $a['end_date'] && $a['end_date'] < $today && 'terminada' !== $a['status'] && 'cancelada' !== $a['status'] ) {
				++$overdue;
			}
		}

		return array(
			'total'          => count( $all ),
			'leaves'         => count( $leaves ),
			'milestones'     => count( array_filter( $leaves, static fn( array $a ): bool => 'milestone' === $a['kind'] ) ),
			'by_status'      => $by,
			'percent'        => $weight > 0 ? (int) round( $earned / $weight ) : 0,
			'critical'       => $crit,
			'overdue'        => $overdue,
		);
	}

	/**
	 * Convierte una fila en su representación pública.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'parent_id', 'priority', 'sort_order', 'duration', 'percent', 'owner_id', 'version', 'created_by' ) as $int ) {
			$row[ $int ] = (int) ( $row[ $int ] ?? 0 );
		}
		$row['is_critical']  = ! empty( $row['is_critical'] );
		$row['total_float']  = null === ( $row['total_float'] ?? null ) ? null : (int) $row['total_float'];
		$row['free_float']   = null === ( $row['free_float'] ?? null ) ? null : (int) $row['free_float'];
		$row['cost_planned'] = null === ( $row['cost_planned'] ?? null ) ? null : (float) $row['cost_planned'];

		$conflicts                 = is_string( $row['schedule_conflicts'] ?? null ) ? json_decode( $row['schedule_conflicts'], true ) : null;
		$row['schedule_conflicts'] = is_array( $conflicts ) ? $conflicts : array();

		foreach ( array( 'constraint_date', 'actual_start', 'actual_finish', 'start_date', 'end_date', 'late_start', 'late_finish' ) as $date ) {
			if ( isset( $row[ $date ] ) && ( '' === $row[ $date ] || '0000-00-00' === $row[ $date ] ) ) {
				$row[ $date ] = null;
			}
		}

		return $row;
	}

	/**
	 * Recorrido en profundidad para ordenar el árbol.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $children Hijos por padre.
	 * @param int                                       $parent   Padre.
	 * @param int                                       $level    Nivel.
	 * @param array<int,array<string,mixed>>            $out      Salida.
	 * @return void
	 */
	private static function walk( array $children, int $parent, int $level, array &$out ): void {
		foreach ( $children[ $parent ] ?? array() as $a ) {
			$a['level'] = $level;
			$out[]      = $a;
			self::walk( $children, $a['id'], $level + 1, $out );
		}
	}

	/**
	 * Comprueba filtros sobre una actividad.
	 *
	 * @param array<string,mixed> $a       Actividad.
	 * @param array<string,mixed> $filters Filtros.
	 * @return bool
	 */
	private static function matches( array $a, array $filters ): bool {
		foreach ( $filters as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			switch ( $key ) {
				case 'status':
				case 'kind':
				case 'work_front':
					if ( $a[ $key ] !== (string) $value ) {
						return false;
					}
					break;
				case 'owner_id':
					if ( $a['owner_id'] !== (int) $value ) {
						return false;
					}
					break;
				case 'critical':
					if ( $value && ! $a['is_critical'] ) {
						return false;
					}
					break;
				case 'search':
					if ( false === mb_stripos( $a['name'] . ' ' . $a['code'] . ' ' . (string) $a['deliverable'], (string) $value ) ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Formatos de $wpdb.
	 *
	 * @param array<string,mixed> $data Campos.
	 * @return string[]
	 */
	private static function formats( array $data ): array {
		$formats = array();
		foreach ( $data as $field => $value ) {
			if ( null === $value ) {
				$formats[] = '%s';
			} elseif ( isset( self::FIELDS[ $field ] ) ) {
				$formats[] = self::FIELDS[ $field ];
			} elseif ( isset( self::SCHEDULE_FIELDS[ $field ] ) ) {
				$formats[] = self::SCHEDULE_FIELDS[ $field ];
			} elseif ( in_array( $field, array( 'id', 'project_id', 'version', 'created_by' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}

	/**
	 * Normaliza una fecha; null si vacía; false si inválida.
	 *
	 * @param mixed $value Valor.
	 * @return string|null|false
	 */
	public static function normalize_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : false;
	}
}
