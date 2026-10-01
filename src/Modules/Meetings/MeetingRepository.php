<?php
/**
 * Repositorio de reuniones.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\Numbering;
use GDP\Modules\Planning\ActivityRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reuniones con código correlativo, fecha, tipo, asistentes (integrantes del
 * sitio o personas externas), agenda, resumen o acta, transcripción y
 * acuerdos (AgreementRepository). El acta puede exportarse como documento.
 */
final class MeetingRepository {

	public const STATUSES = array( 'programada', 'realizada', 'cancelada' );
	public const KINDS    = array( 'equipo', 'financiador', 'proveedor', 'comite', 'terreno', 'otra' );

	/**
	 * Etiquetas de estado.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'programada' => __( 'Programada', 'gestion-de-proyectos' ),
			'realizada'  => __( 'Realizada', 'gestion-de-proyectos' ),
			'cancelada'  => __( 'Cancelada', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de tipo.
	 *
	 * @return array<string,string>
	 */
	public static function kind_labels(): array {
		return array(
			'equipo'      => __( 'Equipo del proyecto', 'gestion-de-proyectos' ),
			'financiador' => __( 'Con el financiador', 'gestion-de-proyectos' ),
			'proveedor'   => __( 'Con proveedor o laboratorio', 'gestion-de-proyectos' ),
			'comite'      => __( 'Comité o consejo', 'gestion-de-proyectos' ),
			'terreno'     => __( 'Terreno', 'gestion-de-proyectos' ),
			'otra'        => __( 'Otra', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Una reunión con asistentes.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'meetings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Reuniones de un proyecto, de la más reciente a la más antigua.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $filters    status, kind, from, to, search, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, array $filters = array() ): array {
		global $wpdb;

		$table = Schema::table( 'meetings' );
		$where = array( 'project_id = %d' );
		$args  = array( $project_id );
		foreach ( array( 'status', 'kind' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[] = "{$field} = %s";
				$args[]  = (string) $filters[ $field ];
			}
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 'meeting_date >= %s';
			$args[]  = (string) $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 'meeting_date <= %s';
			$args[]  = (string) $filters['to'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(title LIKE %s OR code LIKE %s OR summary LIKE %s OR agenda LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$limit = isset( $filters['limit'] ) ? max( 1, min( 1000, (int) $filters['limit'] ) ) : 500;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY meeting_date DESC, id DESC LIMIT {$limit}", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Reunión anterior a una fecha (para el seguimiento de acuerdos).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $before     Fecha.
	 * @param int    $exclude_id Reunión a excluir.
	 * @return array<string,mixed>|null
	 */
	public static function previous( int $project_id, string $before, int $exclude_id = 0 ): ?array {
		global $wpdb;

		$table = Schema::table( 'meetings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND meeting_date <= %s AND id <> %d AND status <> 'cancelada' ORDER BY meeting_date DESC, id DESC LIMIT 1", $project_id, $before, $exclude_id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
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
		if ( array_key_exists( 'title', $data ) ) {
			$clean['title'] = sanitize_text_field( (string) $data['title'] );
			if ( '' === $clean['title'] ) {
				$errors->add( 'title', __( 'El título de la reunión es obligatorio.', 'gestion-de-proyectos' ) );
			}
		}
		if ( array_key_exists( 'kind', $data ) ) {
			$kind = sanitize_key( (string) $data['kind'] );
			if ( ! in_array( $kind, self::KINDS, true ) ) {
				$errors->add( 'kind', __( 'Tipo de reunión no válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['kind'] = $kind;
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
		if ( array_key_exists( 'meeting_date', $data ) ) {
			$date = ActivityRepository::normalize_date( $data['meeting_date'] );
			if ( ! $date ) {
				$errors->add( 'meeting_date', __( 'Indique la fecha de la reunión (AAAA-MM-DD).', 'gestion-de-proyectos' ) );
			} else {
				$clean['meeting_date'] = $date;
			}
		}
		foreach ( array( 'start_time', 'end_time' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$time = trim( (string) $data[ $field ] );
				if ( '' === $time ) {
					$clean[ $field ] = null;
				} elseif ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
					$clean[ $field ] = sprintf( '%02d:%02d:00', (int) $m[1], (int) $m[2] );
				} else {
					$errors->add( $field, __( 'Hora no válida (use HH:MM).', 'gestion-de-proyectos' ) );
				}
			}
		}
		foreach ( array( 'location', 'code' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}
		foreach ( array( 'agenda', 'summary', 'notes' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = wp_kses_post( (string) $data[ $field ] );
			}
		}
		if ( array_key_exists( 'transcript', $data ) ) {
			$clean['transcript'] = sanitize_textarea_field( (string) $data['transcript'] );
		}
		if ( array_key_exists( 'organizer_id', $data ) ) {
			$organizer = (int) $data['organizer_id'];
			if ( $organizer > 0 && ! get_userdata( $organizer ) ) {
				$errors->add( 'organizer_id', __( 'El organizador no existe.', 'gestion-de-proyectos' ) );
			} else {
				$clean['organizer_id'] = max( 0, $organizer );
			}
		}
		if ( array_key_exists( 'activity_id', $data ) ) {
			$activity_id = (int) $data['activity_id'];
			if ( $activity_id > 0 ) {
				$activity = ActivityRepository::find( $activity_id );
				if ( ! $activity || $activity['project_id'] !== $project_id ) {
					$errors->add( 'activity_id', __( 'La actividad no existe en este proyecto.', 'gestion-de-proyectos' ) );
				}
			}
			$clean['activity_id'] = max( 0, $activity_id );
		}
		if ( array_key_exists( 'minutes_document_id', $data ) ) {
			$doc_id = (int) $data['minutes_document_id'];
			if ( $doc_id > 0 ) {
				$doc = \GDP\Modules\Documents\DocumentRepository::find( $doc_id );
				if ( ! $doc || $doc['project_id'] !== $project_id ) {
					$errors->add( 'minutes_document_id', __( 'El documento del acta no existe en este proyecto.', 'gestion-de-proyectos' ) );
				}
			}
			$clean['minutes_document_id'] = max( 0, $doc_id );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una reunión con código correlativo y asistentes.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param array<string,mixed>            $data       Datos.
	 * @param array<int,array<string,mixed>> $attendees  Asistentes (user_id o name, organization, email, attended).
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data, array $attendees = array() ) {
		global $wpdb;

		$data  = array_merge( array( 'kind' => 'equipo', 'status' => 'programada', 'meeting_date' => current_time( 'Y-m-d' ), 'organizer_id' => get_current_user_id() ), $data );
		$clean = self::validate( $data, $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['title'] ) ) {
			return new WP_Error( 'required', __( 'El título de la reunión es obligatorio.', 'gestion-de-proyectos' ) );
		}
		if ( empty( $clean['code'] ) ) {
			$next            = self::next_code( $project_id, (string) $clean['meeting_date'] );
			$clean['seq_no'] = $next['sequence'];
			$clean['code']   = $next['number'];
		}
		$now                 = current_time( 'mysql', true );
		$clean['project_id'] = $project_id;
		$clean['version']    = 1;
		$clean['created_by'] = get_current_user_id();
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'meetings' ), $clean ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la reunión.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! empty( $attendees ) ) {
			self::set_attendees( $id, $attendees );
		}
		Audit::log( 'meeting', $id, 'create', $project_id, sprintf( 'Reunión creada: %s %s', $clean['code'], $clean['title'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza una reunión con control optimista de versión.
	 *
	 * @param int                 $id               Reunión.
	 * @param array<string,mixed> $data             Campos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La reunión no existe.', 'gestion-de-proyectos' ) );
		}
		if ( null !== $expected_version && $expected_version !== $current['version'] ) {
			return new WP_Error( 'version_conflict', sprintf( 'La reunión cambió (versión %d, se esperaba %d). Vuelva a leerla antes de modificarla.', $current['version'], $expected_version ) );
		}
		$clean = self::validate( $data, $current['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean ) ) {
			return $current;
		}
		$clean['version']    = $current['version'] + 1;
		$clean['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( Schema::table( 'meetings' ), $clean, array( 'id' => $id ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar la reunión.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = self::find( $id );
		Audit::log( 'meeting', $id, 'update', $current['project_id'], sprintf( 'Reunión actualizada: %s', $updated['title'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Reemplaza los asistentes.
	 *
	 * @param int                            $meeting_id Reunión.
	 * @param array<int,array<string,mixed>> $attendees  Asistentes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function set_attendees( int $meeting_id, array $attendees ): array {
		global $wpdb;

		$table = Schema::table( 'meeting_attendees' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'meeting_id' => $meeting_id ) );
		$seen = array();
		foreach ( $attendees as $a ) {
			$a       = (array) $a;
			$user_id = (int) ( $a['user_id'] ?? 0 );
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
			$name    = sanitize_text_field( (string) ( $a['name'] ?? '' ) );
			if ( $user ) {
				$name = $user->display_name;
			} elseif ( '' === $name ) {
				continue;
			}
			$key = $user ? 'u' . $user_id : 'n' . mb_strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'meeting_id'   => $meeting_id,
					'user_id'      => $user ? $user_id : 0,
					'name'         => $name,
					'organization' => sanitize_text_field( (string) ( $a['organization'] ?? '' ) ),
					'email'        => $user ? (string) $user->user_email : sanitize_email( (string) ( $a['email'] ?? '' ) ),
					'attended'     => ! isset( $a['attended'] ) || ! empty( $a['attended'] ) ? 1 : 0,
				)
			);
		}

		return self::attendees( $meeting_id );
	}

	/**
	 * Asistentes de una reunión.
	 *
	 * @param int $meeting_id Reunión.
	 * @return array<int,array<string,mixed>>
	 */
	public static function attendees( int $meeting_id ): array {
		global $wpdb;

		$table = Schema::table( 'meeting_attendees' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE meeting_id = %d ORDER BY id ASC", $meeting_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array(
				'id'           => (int) $r['id'],
				'user_id'      => (int) $r['user_id'],
				'name'         => (string) $r['name'],
				'organization' => (string) $r['organization'],
				'email'        => (string) $r['email'],
				'attended'     => (bool) $r['attended'],
			);
		}

		return $out;
	}

	/**
	 * Elimina una reunión con asistentes y acuerdos.
	 *
	 * @param int $id Reunión.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La reunión no existe.', 'gestion-de-proyectos' ) );
		}
		AgreementRepository::delete_for_meeting( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'meeting_attendees' ), array( 'meeting_id' => $id ) );
		\GDP\Modules\Documents\LinkRepository::delete_for_entity( 'meeting', $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'meetings' ), array( 'id' => $id ) );
		Audit::log( 'meeting', $id, 'delete', $current['project_id'], sprintf( 'Reunión eliminada: %s %s', $current['code'], $current['title'] ), $current, null );

		return true;
	}

	/**
	 * Instantánea para revertir una eliminación.
	 *
	 * @param int $id Reunión.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $id ): ?array {
		$meeting = self::find( $id );
		if ( ! $meeting ) {
			return null;
		}

		return array(
			'meeting'    => $meeting,
			'attendees'  => $meeting['attendees'],
			'agreements' => array_values( array_filter( array_map( static fn( array $a ): ?array => AgreementRepository::snapshot( (int) $a['id'] ), AgreementRepository::for_meeting( $id ) ) ) ),
			'links'      => \GDP\Modules\Documents\LinkRepository::rows_for_entity( 'meeting', $id ),
		);
	}

	/**
	 * Restaura una reunión desde su instantánea.
	 *
	 * @param array<string,mixed> $snapshot Instantánea.
	 * @return bool|WP_Error
	 */
	public static function restore( array $snapshot ) {
		global $wpdb;

		$m = $snapshot['meeting'] ?? null;
		if ( ! is_array( $m ) || empty( $m['id'] ) ) {
			return new WP_Error( 'snapshot', __( 'La instantánea de la reunión está incompleta.', 'gestion-de-proyectos' ) );
		}
		if ( self::find( (int) $m['id'] ) ) {
			return new WP_Error( 'exists', __( 'La reunión ya existe; no se puede restaurar sobre ella.', 'gestion-de-proyectos' ) );
		}
		$row = array_intersect_key( $m, array_flip( self::COLUMNS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'meetings' ), $row ) ) {
			return new WP_Error( 'db', __( 'No se pudo restaurar la reunión.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		self::set_attendees( (int) $m['id'], (array) ( $snapshot['attendees'] ?? array() ) );
		AgreementRepository::restore( (array) ( $snapshot['agreements'] ?? array() ) );
		\GDP\Modules\Documents\LinkRepository::restore( (array) ( $snapshot['links'] ?? array() ) );
		Audit::log( 'meeting', (int) $m['id'], 'restore', (int) $m['project_id'], sprintf( 'Reunión restaurada: %s %s', $m['code'], $m['title'] ), null, self::find( (int) $m['id'] ) );

		return true;
	}

	public const COLUMNS = array( 'id', 'project_id', 'seq_no', 'code', 'title', 'kind', 'status', 'meeting_date', 'start_time', 'end_time', 'location', 'agenda', 'summary', 'transcript', 'organizer_id', 'activity_id', 'minutes_document_id', 'notes', 'version', 'created_by', 'created_at', 'updated_at' );

	/**
	 * Siguiente código correlativo (anual).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $date       Fecha de la reunión.
	 * @return array{sequence:int,number:string}
	 */
	public static function next_code( int $project_id, string $date ): array {
		global $wpdb;

		$project = ProjectRepository::find( $project_id );
		$pattern = (string) ( $project['settings']['meetings']['pattern'] ?? 'REU-{NNN}/{AAAA}' );
		$year    = (int) substr( $date, 0, 4 );
		$table   = Schema::table( 'meetings' );
		if ( Numbering::yearly( $pattern ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) FROM {$table} WHERE project_id = %d AND meeting_date >= %s AND meeting_date <= %s", $project_id, $year . '-01-01', $year . '-12-31' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) FROM {$table} WHERE project_id = %d", $project_id ) );
		}
		$sequence = $max + 1;

		return array( 'sequence' => $sequence, 'number' => Numbering::format( $pattern, 'REU', $sequence, $year, (string) ( $project['code'] ?? '' ) ) );
	}

	/**
	 * Elimina las reuniones de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		foreach ( self::for_project( $project_id, array( 'limit' => 1000 ) ) as $m ) {
			self::delete( (int) $m['id'] );
		}
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'seq_no', 'organizer_id', 'activity_id', 'minutes_document_id', 'version', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'start_time', 'end_time' ) as $time ) {
			$row[ $time ] = $row[ $time ] ? substr( (string) $row[ $time ], 0, 5 ) : null;
		}
		foreach ( array( 'agenda', 'summary', 'transcript', 'notes' ) as $text ) {
			$row[ $text ] = (string) $row[ $text ];
		}
		$row['attendees'] = self::attendees( $row['id'] );

		return $row;
	}
}
