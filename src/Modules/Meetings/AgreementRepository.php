<?php
/**
 * Repositorio de acuerdos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Modules\Planning\ActivityRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Acuerdos tomados en una reunión: descripción, responsable (usuario del
 * sitio o nombre externo), plazo y estado; pueden convertirse en una actividad
 * del cronograma (el estado sigue entonces a la actividad) y se revisan de
 * reunión en reunión, con un registro de seguimiento por revisión.
 */
final class AgreementRepository {

	public const STATUSES = array( 'pendiente', 'en_curso', 'cumplido', 'cancelado' );
	public const OPEN     = array( 'pendiente', 'en_curso' );

	/**
	 * Etiquetas de estado.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'pendiente' => __( 'Pendiente', 'gestion-de-proyectos' ),
			'en_curso'  => __( 'En curso', 'gestion-de-proyectos' ),
			'cumplido'  => __( 'Cumplido', 'gestion-de-proyectos' ),
			'cancelado' => __( 'Cancelado', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Un acuerdo.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( self::select_sql() . ' WHERE a.id = %d', $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Acuerdos de una reunión.
	 *
	 * @param int $meeting_id Reunión.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_meeting( int $meeting_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( self::select_sql() . ' WHERE a.meeting_id = %d ORDER BY a.seq_no ASC, a.id ASC', $meeting_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Acuerdos de un proyecto con filtros.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $filters    status, open (bool), overdue (bool), owner_id, meeting_id, search, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, array $filters = array() ): array {
		global $wpdb;

		$where = array( 'a.project_id = %d' );
		$args  = array( $project_id );
		if ( ! empty( $filters['status'] ) ) {
			$where[] = self::effective_sql() . ' = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['open'] ) ) {
			$where[] = self::effective_sql() . " IN ('pendiente','en_curso')";
		}
		if ( ! empty( $filters['overdue'] ) ) {
			$where[] = self::effective_sql() . " IN ('pendiente','en_curso') AND a.due_date IS NOT NULL AND a.due_date < %s";
			$args[]  = current_time( 'Y-m-d' );
		}
		foreach ( array( 'owner_id', 'meeting_id' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[] = "a.{$field} = %d";
				$args[]  = (int) $filters[ $field ];
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(a.description LIKE %s OR a.code LIKE %s OR a.owner_name LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$limit = isset( $filters['limit'] ) ? max( 1, min( 1000, (int) $filters['limit'] ) ) : 500;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( self::select_sql() . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY COALESCE(a.due_date, '9999-12-31') ASC, a.id ASC LIMIT {$limit}", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Consulta base: acuerdos con el estado efectivo calculado a partir de la actividad asociada.
	 *
	 * @return string
	 */
	private static function select_sql(): string {
		$table      = Schema::table( 'agreements' );
		$activities = Schema::table( 'activities' );

		return 'SELECT a.*, ' . self::effective_sql() . " AS effective_status FROM {$table} a LEFT JOIN {$activities} act ON act.id = a.activity_id";
	}

	/**
	 * Expresión SQL del estado efectivo: una actividad terminada cumple el acuerdo,
	 * una cancelada lo cancela y una en curso lo pone en curso.
	 *
	 * @return string
	 */
	private static function effective_sql(): string {
		return "(CASE WHEN act.status = 'terminada' THEN 'cumplido' WHEN act.status = 'cancelada' THEN 'cancelado' WHEN act.status = 'en_curso' AND a.status = 'pendiente' THEN 'en_curso' ELSE a.status END)";
	}

	/**
	 * Acuerdos abiertos de reuniones anteriores a una, para revisarlos en ella.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $meeting_id Reunión en curso (sus propios acuerdos se excluyen).
	 * @return array<int,array<string,mixed>>
	 */
	public static function carried_over( int $project_id, int $meeting_id ): array {
		return array_values( array_filter( self::for_project( $project_id, array( 'open' => true ) ), static fn( array $a ): bool => $a['meeting_id'] !== $meeting_id ) );
	}

	/**
	 * Resumen para la ficha del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{total:int,open:int,overdue:int,fulfilled:int}
	 */
	public static function stats( int $project_id ): array {
		$all   = self::for_project( $project_id, array( 'limit' => 1000 ) );
		$today = current_time( 'Y-m-d' );
		$open  = 0;
		$over  = 0;
		$done  = 0;
		foreach ( $all as $a ) {
			$status = self::effective_status( $a );
			if ( in_array( $status, self::OPEN, true ) ) {
				++$open;
				if ( $a['due_date'] && $a['due_date'] < $today ) {
					++$over;
				}
			} elseif ( 'cumplido' === $status ) {
				++$done;
			}
		}

		return array( 'total' => count( $all ), 'open' => $open, 'overdue' => $over, 'fulfilled' => $done );
	}

	/**
	 * Valida y normaliza.
	 *
	 * @param array<string,mixed> $data       Datos.
	 * @param int                 $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $project_id ) {
		$clean  = array();
		$errors = new WP_Error();
		if ( array_key_exists( 'description', $data ) ) {
			$clean['description'] = sanitize_textarea_field( (string) $data['description'] );
			if ( '' === trim( $clean['description'] ) ) {
				$errors->add( 'description', __( 'La descripción del acuerdo es obligatoria.', 'gestion-de-proyectos' ) );
			}
		}
		if ( array_key_exists( 'owner_id', $data ) ) {
			$owner = (int) $data['owner_id'];
			if ( $owner > 0 && ! get_userdata( $owner ) ) {
				$errors->add( 'owner_id', __( 'El responsable no existe.', 'gestion-de-proyectos' ) );
			} else {
				$clean['owner_id'] = max( 0, $owner );
			}
		}
		if ( array_key_exists( 'owner_name', $data ) ) {
			$clean['owner_name'] = sanitize_text_field( (string) $data['owner_name'] );
		}
		if ( array_key_exists( 'due_date', $data ) ) {
			$date = ActivityRepository::normalize_date( $data['due_date'] );
			if ( false === $date ) {
				$errors->add( 'due_date', __( 'Plazo no válido (use AAAA-MM-DD).', 'gestion-de-proyectos' ) );
			} else {
				$clean['due_date'] = $date;
			}
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				$errors->add( 'status', __( 'Estado no válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['status'] = $status;
			}
		}
		if ( array_key_exists( 'origin', $data ) ) {
			$clean['origin'] = in_array( $data['origin'], array( 'manual', 'propuesto', 'conector' ), true ) ? (string) $data['origin'] : 'manual';
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea un acuerdo en una reunión.
	 *
	 * @param array<string,mixed> $meeting Reunión.
	 * @param array<string,mixed> $data    Datos.
	 * @return int|WP_Error
	 */
	public static function create( array $meeting, array $data ) {
		global $wpdb;

		$data  = array_merge( array( 'status' => 'pendiente', 'origin' => 'manual' ), $data );
		$clean = self::validate( $data, (int) $meeting['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['description'] ) ) {
			return new WP_Error( 'required', __( 'La descripción del acuerdo es obligatoria.', 'gestion-de-proyectos' ) );
		}
		if ( ! empty( $clean['owner_id'] ) ) {
			$user                = get_userdata( (int) $clean['owner_id'] );
			$clean['owner_name'] = $user ? $user->display_name : (string) ( $clean['owner_name'] ?? '' );
		}
		$table = Schema::table( 'agreements' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) + 1 FROM {$table} WHERE meeting_id = %d", (int) $meeting['id'] ) );
		$now = current_time( 'mysql', true );
		$clean += array(
			'project_id' => (int) $meeting['project_id'],
			'meeting_id' => (int) $meeting['id'],
			'seq_no'     => $seq,
			'code'       => $meeting['code'] . '.' . $seq,
			'version'    => 1,
			'created_by' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( $table, $clean ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el acuerdo.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		Audit::log( 'agreement', $id, 'create', (int) $meeting['project_id'], sprintf( 'Acuerdo %s: %s', $clean['code'], mb_substr( $clean['description'], 0, 120 ) ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza un acuerdo.
	 *
	 * @param int                 $id               Acuerdo.
	 * @param array<string,mixed> $data             Campos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El acuerdo no existe.', 'gestion-de-proyectos' ) );
		}
		if ( null !== $expected_version && $expected_version !== $current['version'] ) {
			return new WP_Error( 'version_conflict', sprintf( 'El acuerdo cambió (versión %d, se esperaba %d).', $current['version'], $expected_version ) );
		}
		$clean = self::validate( $data, $current['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean ) ) {
			return $current;
		}
		if ( isset( $clean['owner_id'] ) && $clean['owner_id'] > 0 ) {
			$user                = get_userdata( (int) $clean['owner_id'] );
			$clean['owner_name'] = $user ? $user->display_name : (string) ( $clean['owner_name'] ?? $current['owner_name'] );
		}
		if ( isset( $clean['status'] ) ) {
			if ( 'cumplido' === $clean['status'] && empty( $current['fulfilled_at'] ) ) {
				$clean['fulfilled_at'] = current_time( 'Y-m-d' );
			} elseif ( 'cumplido' !== $clean['status'] ) {
				$clean['fulfilled_at'] = null;
			}
		}
		$clean['version']    = $current['version'] + 1;
		$clean['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( Schema::table( 'agreements' ), $clean, array( 'id' => $id ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar el acuerdo.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = self::find( $id );
		Audit::log( 'agreement', $id, 'update', $current['project_id'], sprintf( 'Acuerdo %s actualizado', $current['code'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Registra una revisión del acuerdo en una reunión (estado y nota) y la anota en el seguimiento.
	 *
	 * @param int    $id         Acuerdo.
	 * @param int    $meeting_id Reunión en que se revisa.
	 * @param string $status     Nuevo estado.
	 * @param string $note       Nota de seguimiento.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function review( int $id, int $meeting_id, string $status, string $note = '' ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El acuerdo no existe.', 'gestion-de-proyectos' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'Estado no válido.', 'gestion-de-proyectos' ) );
		}
		$meeting = MeetingRepository::find( $meeting_id );
		$log     = $current['follow_up'];
		$log[]   = array(
			'meeting_id'   => $meeting_id,
			'meeting_code' => $meeting ? $meeting['code'] : '',
			'date'         => $meeting ? $meeting['meeting_date'] : current_time( 'Y-m-d' ),
			'from'         => $current['status'],
			'to'           => $status,
			'note'         => sanitize_text_field( $note ),
			'user'         => wp_get_current_user()->display_name,
		);
		$data = array(
			'status'                 => $status,
			'follow_up'              => wp_json_encode( $log, JSON_UNESCAPED_UNICODE ),
			'last_review_meeting_id' => $meeting_id,
			'fulfilled_at'           => 'cumplido' === $status ? ( $current['fulfilled_at'] ?? ( $meeting ? $meeting['meeting_date'] : current_time( 'Y-m-d' ) ) ) : null,
			'version'                => $current['version'] + 1,
			'updated_at'             => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'agreements' ), $data, array( 'id' => $id ) );
		$updated = self::find( $id );
		Audit::log( 'agreement', $id, 'review', $current['project_id'], sprintf( 'Acuerdo %s revisado en %s: %s', $current['code'], $meeting ? $meeting['code'] : '#' . $meeting_id, self::status_labels()[ $status ] ), $current, $updated );

		return $updated;
	}

	/**
	 * Convierte un acuerdo en una actividad del cronograma.
	 *
	 * @param int                 $id      Acuerdo.
	 * @param array<string,mixed> $options parent_id, duration, name.
	 * @return array<string,mixed>|WP_Error La actividad creada.
	 */
	public static function to_activity( int $id, array $options = array() ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El acuerdo no existe.', 'gestion-de-proyectos' ) );
		}
		if ( $current['activity_id'] > 0 && ActivityRepository::find( $current['activity_id'] ) ) {
			return new WP_Error( 'exists', __( 'El acuerdo ya tiene actividad asociada.', 'gestion-de-proyectos' ) );
		}
		$data = array(
			'name'        => ! empty( $options['name'] ) ? sanitize_text_field( (string) $options['name'] ) : mb_substr( $current['description'], 0, 200 ),
			'parent_id'   => (int) ( $options['parent_id'] ?? 0 ),
			'duration'    => max( 1, (int) ( $options['duration'] ?? 5 ) ),
			'owner_id'    => $current['owner_id'],
			/* translators: código del acuerdo. */
			'deliverable' => sprintf( __( 'Acuerdo %s', 'gestion-de-proyectos' ), $current['code'] ),
			'status'      => 'en_curso' === $current['status'] ? 'en_curso' : 'pendiente',
		);
		if ( $current['due_date'] ) {
			$data['constraint_type'] = 'fnlt';
			$data['constraint_date'] = $current['due_date'];
		}
		$activity_id = ActivityRepository::create( $current['project_id'], $data );
		if ( is_wp_error( $activity_id ) ) {
			return $activity_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'agreements' ), array( 'activity_id' => $activity_id, 'version' => $current['version'] + 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		\GDP\Modules\Documents\LinkRepository::add( $current['project_id'], 'agreement', $id, 'activity', $activity_id, 'related', __( 'Actividad creada desde el acuerdo', 'gestion-de-proyectos' ) );
		\GDP\Modules\Planning\ScheduleService::recalculate( $current['project_id'] );
		Audit::log( 'agreement', $id, 'to_activity', $current['project_id'], sprintf( 'Acuerdo %s convertido en actividad', $current['code'] ), $current, self::find( $id ) );

		return ActivityRepository::find( $activity_id );
	}

	/**
	 * Elimina un acuerdo.
	 *
	 * @param int $id Acuerdo.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El acuerdo no existe.', 'gestion-de-proyectos' ) );
		}
		\GDP\Modules\Documents\LinkRepository::delete_for_entity( 'agreement', $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'agreements' ), array( 'id' => $id ) );
		Audit::log( 'agreement', $id, 'delete', $current['project_id'], sprintf( 'Acuerdo %s eliminado', $current['code'] ), $current, null );

		return true;
	}

	/**
	 * Elimina los acuerdos de una reunión.
	 *
	 * @param int $meeting_id Reunión.
	 * @return void
	 */
	public static function delete_for_meeting( int $meeting_id ): void {
		foreach ( self::for_meeting( $meeting_id ) as $a ) {
			self::delete( (int) $a['id'] );
		}
	}

	/**
	 * Instantánea de un acuerdo con sus vínculos, para revertir una eliminación.
	 *
	 * @param int $id Acuerdo.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $id ): ?array {
		$agreement = self::find( $id );
		if ( ! $agreement ) {
			return null;
		}
		$agreement['links'] = \GDP\Modules\Documents\LinkRepository::rows_for_entity( 'agreement', $id );

		return $agreement;
	}

	/**
	 * Reinserta acuerdos (restauración), con sus vínculos si la instantánea los incluye.
	 *
	 * @param array<int,array<string,mixed>> $rows Filas.
	 * @return void
	 */
	public static function restore( array $rows ): void {
		global $wpdb;

		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$links = isset( $row['links'] ) && is_array( $row['links'] ) ? $row['links'] : array();
			if ( isset( $row['follow_up'] ) && is_array( $row['follow_up'] ) ) {
				$row['follow_up'] = wp_json_encode( $row['follow_up'], JSON_UNESCAPED_UNICODE );
			}
			$row = array_intersect_key( $row, array_flip( array( 'id', 'project_id', 'meeting_id', 'seq_no', 'code', 'description', 'owner_id', 'owner_name', 'due_date', 'status', 'activity_id', 'fulfilled_at', 'origin', 'follow_up', 'last_review_meeting_id', 'version', 'created_by', 'created_at', 'updated_at' ) ) );
			if ( ! empty( $row['id'] ) && self::find( (int) $row['id'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( Schema::table( 'agreements' ), $row );
			\GDP\Modules\Documents\LinkRepository::restore( $links );
		}
	}

	/**
	 * Estado efectivo: si hay actividad asociada, sigue a la actividad.
	 *
	 * @param array<string,mixed> $a Acuerdo.
	 * @return string
	 */
	public static function effective_status( array $a ): string {
		if ( isset( $a['effective_status'] ) && in_array( $a['effective_status'], self::STATUSES, true ) ) {
			return (string) $a['effective_status'];
		}
		if ( $a['activity_id'] > 0 ) {
			$activity = ActivityRepository::find( $a['activity_id'] );
			if ( $activity ) {
				if ( 'terminada' === $activity['status'] ) {
					return 'cumplido';
				}
				if ( 'cancelada' === $activity['status'] ) {
					return 'cancelado';
				}
				if ( 'en_curso' === $activity['status'] && 'pendiente' === $a['status'] ) {
					return 'en_curso';
				}
			}
		}

		return (string) $a['status'];
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'meeting_id', 'seq_no', 'owner_id', 'activity_id', 'last_review_meeting_id', 'version', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'due_date', 'fulfilled_at' ) as $date ) {
			$row[ $date ] = $row[ $date ] ? (string) $row[ $date ] : null;
		}
		$log              = json_decode( (string) $row['follow_up'], true );
		$row['follow_up'] = is_array( $log ) ? $log : array();
		$row['effective_status'] = in_array( $row['effective_status'] ?? '', self::STATUSES, true ) ? (string) $row['effective_status'] : (string) $row['status'];
		$row['overdue']          = in_array( $row['effective_status'], self::OPEN, true ) && $row['due_date'] && $row['due_date'] < current_time( 'Y-m-d' );
		if ( $row['owner_id'] > 0 && '' === (string) $row['owner_name'] ) {
			$user              = get_userdata( $row['owner_id'] );
			$row['owner_name'] = $user ? $user->display_name : '';
		}

		return $row;
	}
}
