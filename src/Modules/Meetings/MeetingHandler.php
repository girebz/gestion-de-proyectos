<?php
/**
 * Manejador de operaciones sobre reuniones y acuerdos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

use GDP\Core\Access;
use GDP\Core\Schema;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Escrituras en dos tiempos: reuniones (crear, actualizar, eliminar
 * reversible, estado, asistentes), acuerdos (añadir en lote, actualizar,
 * eliminar, revisar en una reunión, convertir en actividad) y propuesta de
 * acuerdos a partir de un texto (el texto se analiza al proponer y la lista
 * resultante se crea al confirmar).
 */
final class MeetingHandler implements HandlerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'meeting';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array( 'create', 'update', 'delete', 'set_status', 'set_attendees', 'add_agreements', 'update_agreement', 'delete_agreement', 'review_agreement', 'agreement_to_activity', 'propose_from_text' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( $project_id <= 0 ) {
			return false;
		}
		if ( 'agreement_to_activity' === $action ) {
			return Access::can( 'meetings.edit', $project_id, $user_id ) && Access::can( 'planning.edit', $project_id, $user_id );
		}

		return Access::can( 'meetings.edit', $project_id, $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$clean = MeetingRepository::validate( array_merge( array( 'kind' => 'equipo', 'status' => 'programada', 'meeting_date' => current_time( 'Y-m-d' ) ), $data ), $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['title'] ) ) {
					return new WP_Error( 'required', __( 'El título de la reunión es obligatorio.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean, 'attendees' => $this->attendees( $payload ), 'agreements' => $this->agreements( $payload, $project_id ) );

			case 'update':
				$meeting = $this->meeting( $payload, $project_id );
				if ( is_wp_error( $meeting ) ) {
					return $meeting;
				}
				$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				if ( empty( $data ) && ! isset( $payload['attendees'] ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				$clean = MeetingRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array( 'meeting_id' => $meeting['id'], 'data' => $clean, 'attendees' => isset( $payload['attendees'] ) ? $this->attendees( $payload ) : null, 'expected_version' => isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null );

			case 'delete':
				$meeting = $this->meeting( $payload, $project_id );
				return is_wp_error( $meeting ) ? $meeting : array( 'meeting_id' => $meeting['id'] );

			case 'set_status':
				$meeting = $this->meeting( $payload, $project_id );
				if ( is_wp_error( $meeting ) ) {
					return $meeting;
				}
				$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
				if ( ! in_array( $status, MeetingRepository::STATUSES, true ) ) {
					return new WP_Error( 'status', __( 'Estado no válido.', 'gestion-de-proyectos' ) );
				}
				return array( 'meeting_id' => $meeting['id'], 'status' => $status );

			case 'set_attendees':
				$meeting = $this->meeting( $payload, $project_id );
				return is_wp_error( $meeting ) ? $meeting : array( 'meeting_id' => $meeting['id'], 'attendees' => $this->attendees( $payload ) );

			case 'add_agreements':
				$meeting = $this->meeting( $payload, $project_id );
				if ( is_wp_error( $meeting ) ) {
					return $meeting;
				}
				$agreements = $this->agreements( $payload, $project_id );
				if ( empty( $agreements ) ) {
					return new WP_Error( 'empty', __( 'Indique al menos un acuerdo con descripción.', 'gestion-de-proyectos' ) );
				}
				return array( 'meeting_id' => $meeting['id'], 'agreements' => $agreements, 'origin' => in_array( $payload['origin'] ?? '', array( 'manual', 'propuesto', 'conector' ), true ) ? (string) $payload['origin'] : 'manual' );

			case 'propose_from_text':
				$meeting = $this->meeting( $payload, $project_id );
				if ( is_wp_error( $meeting ) ) {
					return $meeting;
				}
				// Idempotente: al confirmar llega la lista ya extraída.
				if ( isset( $payload['agreements'] ) && is_array( $payload['agreements'] ) ) {
					return array( 'meeting_id' => $meeting['id'], 'agreements' => $this->agreements( $payload, $project_id ), 'origin' => 'propuesto', 'save_transcript' => ! empty( $payload['save_transcript'] ), 'text' => (string) ( $payload['text'] ?? '' ) );
				}
				$text = sanitize_textarea_field( (string) ( $payload['text'] ?? '' ) );
				if ( '' === trim( $text ) ) {
					// Sin texto: se analiza la transcripción guardada o, en su defecto, el resumen.
					$text = sanitize_textarea_field( '' !== $meeting['transcript'] ? $meeting['transcript'] : $meeting['summary'] );
				}
				if ( mb_strlen( trim( $text ) ) < 20 ) {
					return new WP_Error( 'empty', __( 'Indique el resumen o la transcripción de la reunión (al menos unas líneas).', 'gestion-de-proyectos' ) );
				}
				$names     = array_map( static fn( array $a ): string => $a['name'], $meeting['attendees'] );
				$extracted = AgreementExtractor::extract( $text, $meeting['meeting_date'], $names );
				if ( empty( $extracted ) ) {
					return new WP_Error( 'none', __( 'No se encontraron frases con marcas de acuerdo (se acuerda, a cargo de, deberá, enviará, viñetas bajo "Acuerdos"). Puede registrar los acuerdos a mano.', 'gestion-de-proyectos' ) );
				}
				$rows = array();
				foreach ( $extracted as $e ) {
					$rows[] = array( 'description' => $e['description'], 'owner_name' => $e['owner_name'], 'due_date' => $e['due_date'], 'confidence' => $e['confidence'] );
				}
				return array( 'meeting_id' => $meeting['id'], 'agreements' => $this->agreements( array( 'agreements' => $rows ), $project_id ), 'origin' => 'propuesto', 'save_transcript' => ! empty( $payload['save_transcript'] ), 'text' => ! empty( $payload['save_transcript'] ) ? $text : '' );

			case 'update_agreement':
				$agreement = $this->agreement( $payload, $project_id );
				if ( is_wp_error( $agreement ) ) {
					return $agreement;
				}
				$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				if ( empty( $data ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				$clean = AgreementRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array( 'agreement_id' => $agreement['id'], 'data' => $clean, 'expected_version' => isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null );

			case 'delete_agreement':
				$agreement = $this->agreement( $payload, $project_id );
				return is_wp_error( $agreement ) ? $agreement : array( 'agreement_id' => $agreement['id'] );

			case 'review_agreement':
				$agreement = $this->agreement( $payload, $project_id );
				if ( is_wp_error( $agreement ) ) {
					return $agreement;
				}
				$meeting = $this->meeting( $payload, $project_id );
				if ( is_wp_error( $meeting ) ) {
					return $meeting;
				}
				$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
				if ( ! in_array( $status, AgreementRepository::STATUSES, true ) ) {
					return new WP_Error( 'status', __( 'Estado no válido.', 'gestion-de-proyectos' ) );
				}
				return array( 'agreement_id' => $agreement['id'], 'meeting_id' => $meeting['id'], 'status' => $status, 'note' => sanitize_text_field( (string) ( $payload['note'] ?? '' ) ) );

			case 'agreement_to_activity':
				$agreement = $this->agreement( $payload, $project_id );
				if ( is_wp_error( $agreement ) ) {
					return $agreement;
				}
				if ( $agreement['activity_id'] > 0 && ActivityRepository::find( $agreement['activity_id'] ) ) {
					return new WP_Error( 'exists', __( 'El acuerdo ya tiene actividad asociada.', 'gestion-de-proyectos' ) );
				}
				$parent_id = (int) ( $payload['parent_id'] ?? 0 );
				if ( $parent_id > 0 ) {
					$parent = ActivityRepository::find( $parent_id );
					if ( ! $parent || $parent['project_id'] !== $project_id || 'summary' !== $parent['kind'] ) {
						return new WP_Error( 'parent', __( 'El resumen de destino no existe en este proyecto.', 'gestion-de-proyectos' ) );
					}
				}
				return array( 'agreement_id' => $agreement['id'], 'parent_id' => $parent_id, 'duration' => max( 1, (int) ( $payload['duration'] ?? 5 ) ), 'name' => sanitize_text_field( (string) ( $payload['name'] ?? '' ) ) );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$preview = array( 'summary' => '', 'changes' => array(), 'warnings' => array(), 'conflicts' => array() );
		$labels  = AgreementRepository::status_labels();

		switch ( $action ) {
			case 'create':
				$preview['summary'] = sprintf( 'Crear la reunión "%s" del %s', $payload['data']['title'], $payload['data']['meeting_date'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( null !== $value && '' !== $value ) {
						$preview['changes'][ $field ] = array( 'before' => null, 'after' => $value );
					}
				}
				$preview['changes']['code'] = array( 'before' => null, 'after' => MeetingRepository::next_code( $project_id, (string) $payload['data']['meeting_date'] )['number'] );
				if ( ! empty( $payload['attendees'] ) ) {
					$preview['changes']['attendees'] = array( 'before' => null, 'after' => count( $payload['attendees'] ) );
				}
				if ( ! empty( $payload['agreements'] ) ) {
					$preview['changes']['agreements'] = array( 'before' => null, 'after' => count( $payload['agreements'] ) );
				}
				break;

			case 'update':
				$current            = MeetingRepository::find( $payload['meeting_id'] );
				$preview['summary'] = sprintf( 'Actualizar la reunión %s', $current['code'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( (string) ( $current[ $field ] ?? '' ) !== (string) $value ) {
						$preview['changes'][ $field ] = array( 'before' => $current[ $field ] ?? null, 'after' => $value );
					}
				}
				if ( null !== $payload['attendees'] ) {
					$preview['changes']['attendees'] = array( 'before' => count( $current['attendees'] ), 'after' => count( $payload['attendees'] ) );
				}
				if ( null !== $payload['expected_version'] && $current['version'] !== $payload['expected_version'] ) {
					$preview['conflicts'][] = sprintf( 'La reunión está en la versión %d y la propuesta se basó en la versión %d.', $current['version'], $payload['expected_version'] );
				}
				if ( empty( $preview['changes'] ) ) {
					$preview['warnings'][] = 'Ningún campo cambia respecto del estado actual.';
				}
				break;

			case 'delete':
				$current            = MeetingRepository::find( $payload['meeting_id'] );
				$agreements         = AgreementRepository::for_meeting( $current['id'] );
				$preview['summary'] = sprintf( 'Eliminar la reunión %s "%s"', $current['code'], $current['title'] );
				$preview['changes']['meeting'] = array( 'before' => $current['code'] . ' ' . $current['title'], 'after' => null );
				if ( ! empty( $agreements ) ) {
					$preview['warnings'][] = sprintf( 'Se eliminarán %d %s (recuperables al restaurar); las actividades creadas desde ellos se conservan.', count( $agreements ), 1 === count( $agreements ) ? 'acuerdo' : 'acuerdos' );
				}
				break;

			case 'set_status':
				$current            = MeetingRepository::find( $payload['meeting_id'] );
				$preview['summary'] = sprintf( 'Marcar la reunión %s como %s', $current['code'], MeetingRepository::status_labels()[ $payload['status'] ] );
				$preview['changes']['status'] = array( 'before' => $current['status'], 'after' => $payload['status'] );
				break;

			case 'set_attendees':
				$current            = MeetingRepository::find( $payload['meeting_id'] );
				$preview['summary'] = sprintf( 'Fijar %d %s en la reunión %s', count( $payload['attendees'] ), 1 === count( $payload['attendees'] ) ? 'asistente' : 'asistentes', $current['code'] );
				$preview['changes']['attendees'] = array( 'before' => array_map( static fn( array $a ): string => $a['name'], $current['attendees'] ), 'after' => array_map( static fn( array $a ): string => (string) ( $a['name'] ?? $a['user_id'] ), $payload['attendees'] ) );
				break;

			case 'add_agreements':
			case 'propose_from_text':
				$current            = MeetingRepository::find( $payload['meeting_id'] );
				$preview['summary'] = sprintf( '%s %d %s en la reunión %s', 'propose_from_text' === $action ? 'Proponer' : 'Registrar', count( $payload['agreements'] ), 1 === count( $payload['agreements'] ) ? 'acuerdo' : 'acuerdos', $current['code'] );
				$preview['changes']['agreements'] = array( 'before' => count( AgreementRepository::for_meeting( $current['id'] ) ), 'after' => count( AgreementRepository::for_meeting( $current['id'] ) ) + count( $payload['agreements'] ) );
				$preview['agreements'] = $payload['agreements'];
				foreach ( $payload['agreements'] as $i => $a ) {
					if ( empty( $a['owner_id'] ) && '' === (string) ( $a['owner_name'] ?? '' ) ) {
						$preview['warnings'][] = sprintf( 'Acuerdo %d sin responsable: "%s".', $i + 1, mb_substr( $a['description'], 0, 60 ) );
					}
					if ( ! empty( $a['owner_name'] ) && empty( $a['owner_id'] ) ) {
						$preview['warnings'][] = sprintf( 'Responsable "%s" no es usuario del sitio; queda como nombre.', $a['owner_name'] );
					}
					if ( empty( $a['due_date'] ) ) {
						$preview['warnings'][] = sprintf( 'Acuerdo %d sin plazo: "%s".', $i + 1, mb_substr( $a['description'], 0, 60 ) );
					}
				}
				if ( 'propose_from_text' === $action ) {
					$preview['warnings'][] = 'Los acuerdos propuestos provienen de un análisis automático del texto: revise descripción, responsable y plazo antes de confirmar.';
				}
				break;

			case 'update_agreement':
				$current            = AgreementRepository::find( $payload['agreement_id'] );
				$preview['summary'] = sprintf( 'Actualizar el acuerdo %s', $current['code'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( (string) ( $current[ $field ] ?? '' ) !== (string) $value ) {
						$preview['changes'][ $field ] = array( 'before' => $current[ $field ] ?? null, 'after' => $value );
					}
				}
				if ( null !== $payload['expected_version'] && $current['version'] !== $payload['expected_version'] ) {
					$preview['conflicts'][] = sprintf( 'El acuerdo está en la versión %d y la propuesta se basó en la versión %d.', $current['version'], $payload['expected_version'] );
				}
				if ( $current['activity_id'] > 0 && isset( $payload['data']['status'] ) ) {
					$preview['warnings'][] = 'El acuerdo tiene actividad asociada: su estado efectivo sigue a la actividad.';
				}
				break;

			case 'delete_agreement':
				$current            = AgreementRepository::find( $payload['agreement_id'] );
				$preview['summary'] = sprintf( 'Eliminar el acuerdo %s "%s"', $current['code'], mb_substr( $current['description'], 0, 80 ) );
				$preview['changes']['agreement'] = array( 'before' => $current['code'], 'after' => null );
				if ( $current['activity_id'] > 0 ) {
					$preview['warnings'][] = 'La actividad creada desde el acuerdo se conserva.';
				}
				break;

			case 'review_agreement':
				$current            = AgreementRepository::find( $payload['agreement_id'] );
				$meeting            = MeetingRepository::find( $payload['meeting_id'] );
				$preview['summary'] = sprintf( 'Revisar el acuerdo %s en la reunión %s: %s', $current['code'], $meeting['code'], $labels[ $payload['status'] ] );
				$preview['changes']['status'] = array( 'before' => $current['status'], 'after' => $payload['status'] );
				if ( $current['meeting_id'] === $meeting['id'] ) {
					$preview['warnings'][] = 'El acuerdo se revisa en la misma reunión en que se tomó.';
				}
				break;

			case 'agreement_to_activity':
				$current            = AgreementRepository::find( $payload['agreement_id'] );
				$preview['summary'] = sprintf( 'Crear una actividad a partir del acuerdo %s', $current['code'] );
				$preview['changes']['activity'] = array( 'before' => null, 'after' => '' !== $payload['name'] ? $payload['name'] : mb_substr( $current['description'], 0, 200 ) );
				if ( $current['due_date'] ) {
					$preview['changes']['constraint'] = array( 'before' => null, 'after' => 'fnlt ' . $current['due_date'] );
				} else {
					$preview['warnings'][] = 'El acuerdo no tiene plazo; la actividad se programa lo antes posible.';
				}
				if ( 0 === $current['owner_id'] ) {
					$preview['warnings'][] = 'El responsable del acuerdo no es usuario del sitio; la actividad queda sin responsable.';
				}
				break;
		}

		return $preview;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $action, array $payload, int $project_id ) {
		global $wpdb;

		switch ( $action ) {
			case 'create':
				$id = MeetingRepository::create( $project_id, $payload['data'], $payload['attendees'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$meeting = MeetingRepository::find( $id );
				$created = array();
				foreach ( $payload['agreements'] as $a ) {
					$aid = AgreementRepository::create( $meeting, $a );
					if ( ! is_wp_error( $aid ) ) {
						$created[] = $aid;
					}
				}
				return array( 'before' => null, 'result' => array( 'meeting_id' => $id, 'meeting' => MeetingRepository::find( $id ), 'agreement_ids' => $created ) );

			case 'update':
				$before = MeetingRepository::find( $payload['meeting_id'] );
				$result = ! empty( $payload['data'] ) ? MeetingRepository::update( $payload['meeting_id'], $payload['data'], $payload['expected_version'] ) : $before;
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( null !== $payload['attendees'] ) {
					MeetingRepository::set_attendees( $payload['meeting_id'], $payload['attendees'] );
				}
				return array( 'before' => $before, 'result' => array( 'meeting_id' => $payload['meeting_id'], 'meeting' => MeetingRepository::find( $payload['meeting_id'] ) ) );

			case 'delete':
				$snapshot = MeetingRepository::snapshot( $payload['meeting_id'] );
				$ok       = MeetingRepository::delete( $payload['meeting_id'] );
				return is_wp_error( $ok ) ? $ok : array( 'before' => $snapshot, 'result' => array( 'meeting_id' => $payload['meeting_id'] ) );

			case 'set_status':
				$before  = MeetingRepository::find( $payload['meeting_id'] );
				$updated = MeetingRepository::update( $payload['meeting_id'], array( 'status' => $payload['status'] ), null );
				return is_wp_error( $updated ) ? $updated : array( 'before' => $before, 'result' => array( 'meeting_id' => $updated['id'], 'status' => $updated['status'] ) );

			case 'set_attendees':
				$before = MeetingRepository::find( $payload['meeting_id'] );
				$rows   = MeetingRepository::set_attendees( $payload['meeting_id'], $payload['attendees'] );
				return array( 'before' => $before, 'result' => array( 'meeting_id' => $payload['meeting_id'], 'attendees' => $rows ) );

			case 'add_agreements':
			case 'propose_from_text':
				$meeting = MeetingRepository::find( $payload['meeting_id'] );
				$created = array();
				foreach ( $payload['agreements'] as $a ) {
					$a['origin'] = $payload['origin'];
					$aid         = AgreementRepository::create( $meeting, $a );
					if ( is_wp_error( $aid ) ) {
						foreach ( $created as $undo ) {
							AgreementRepository::delete( $undo );
						}
						return $aid;
					}
					$created[] = $aid;
				}
				if ( 'propose_from_text' === $action && ! empty( $payload['save_transcript'] ) && '' !== $payload['text'] && '' === $meeting['transcript'] ) {
					MeetingRepository::update( $meeting['id'], array( 'transcript' => $payload['text'] ), null );
				}
				return array( 'before' => null, 'result' => array( 'meeting_id' => $meeting['id'], 'agreement_ids' => $created, 'count' => count( $created ) ) );

			case 'update_agreement':
				$before  = AgreementRepository::find( $payload['agreement_id'] );
				$updated = AgreementRepository::update( $payload['agreement_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $updated ) ? $updated : array( 'before' => $before, 'result' => array( 'agreement_id' => $updated['id'], 'agreement' => $updated ) );

			case 'delete_agreement':
				$before = AgreementRepository::snapshot( $payload['agreement_id'] );
				$ok     = AgreementRepository::delete( $payload['agreement_id'] );
				return is_wp_error( $ok ) ? $ok : array( 'before' => $before, 'result' => array( 'agreement_id' => $payload['agreement_id'] ) );

			case 'review_agreement':
				$before  = AgreementRepository::find( $payload['agreement_id'] );
				$updated = AgreementRepository::review( $payload['agreement_id'], $payload['meeting_id'], $payload['status'], $payload['note'] );
				return is_wp_error( $updated ) ? $updated : array( 'before' => $before, 'result' => array( 'agreement_id' => $updated['id'], 'status' => $updated['status'] ) );

			case 'agreement_to_activity':
				$before   = AgreementRepository::find( $payload['agreement_id'] );
				$activity = AgreementRepository::to_activity( $payload['agreement_id'], array( 'parent_id' => $payload['parent_id'], 'duration' => $payload['duration'], 'name' => $payload['name'] ) );
				return is_wp_error( $activity ) ? $activity : array( 'before' => $before, 'result' => array( 'agreement_id' => $payload['agreement_id'], 'activity_id' => $activity['id'], 'activity' => $activity ) );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id ) {
		global $wpdb;

		switch ( $action ) {
			case 'create':
				return MeetingRepository::delete( (int) ( $result['meeting_id'] ?? 0 ) );

			case 'update':
			case 'set_status':
			case 'set_attendees':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$row = array_intersect_key( $before, array_flip( MeetingRepository::COLUMNS ) );
				unset( $row['id'], $row['project_id'], $row['version'], $row['created_by'], $row['created_at'] );
				$row['updated_at'] = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'meetings' ), $row, array( 'id' => (int) $before['id'] ) );
				MeetingRepository::set_attendees( (int) $before['id'], (array) ( $before['attendees'] ?? array() ) );
				return true;

			case 'delete':
				return $before ? MeetingRepository::restore( $before ) : new WP_Error( 'nothing_to_revert', __( 'No hay instantánea de la reunión.', 'gestion-de-proyectos' ) );

			case 'add_agreements':
			case 'propose_from_text':
				foreach ( (array) ( $result['agreement_ids'] ?? array() ) as $id ) {
					AgreementRepository::delete( (int) $id );
				}
				return true;

			case 'update_agreement':
			case 'review_agreement':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$row = array_intersect_key( $before, array_flip( array( 'description', 'owner_id', 'owner_name', 'due_date', 'status', 'fulfilled_at', 'last_review_meeting_id' ) ) );
				$row['follow_up']  = wp_json_encode( (array) ( $before['follow_up'] ?? array() ), JSON_UNESCAPED_UNICODE );
				$row['updated_at'] = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'agreements' ), $row, array( 'id' => (int) $before['id'] ) );
				return true;

			case 'delete_agreement':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay instantánea del acuerdo.', 'gestion-de-proyectos' ) );
				}
				AgreementRepository::restore( array( $before ) );
				return true;

			case 'agreement_to_activity':
				$activity_id = (int) ( $result['activity_id'] ?? 0 );
				if ( $activity_id > 0 ) {
					ActivityRepository::delete( $activity_id );
					\GDP\Modules\Planning\ScheduleService::recalculate( $project_id );
				}
				if ( $before ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( Schema::table( 'agreements' ), array( 'activity_id' => (int) $before['activity_id'] ), array( 'id' => (int) $before['id'] ) );
				}
				return true;
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Asistentes normalizados del payload.
	 *
	 * @param array $payload Datos.
	 * @return array<int,array<string,mixed>>
	 */
	private function attendees( array $payload ): array {
		$out = array();
		foreach ( (array) ( $payload['attendees'] ?? array() ) as $a ) {
			if ( is_int( $a ) || ( is_string( $a ) && ctype_digit( $a ) ) ) {
				$a = array( 'user_id' => (int) $a );
			} elseif ( is_string( $a ) ) {
				$a = array( 'name' => $a );
			}
			if ( ! is_array( $a ) ) {
				continue;
			}
			$user_id = (int) ( $a['user_id'] ?? 0 );
			$name    = sanitize_text_field( (string) ( $a['name'] ?? '' ) );
			if ( $user_id <= 0 && '' === $name ) {
				continue;
			}
			$out[] = array(
				'user_id'      => $user_id,
				'name'         => $name,
				'organization' => sanitize_text_field( (string) ( $a['organization'] ?? '' ) ),
				'email'        => sanitize_email( (string) ( $a['email'] ?? '' ) ),
				'attended'     => ! isset( $a['attended'] ) || ! empty( $a['attended'] ),
			);
		}

		return $out;
	}

	/**
	 * Acuerdos normalizados del payload; el responsable se resuelve a usuario por nombre, usuario o correo.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	private function agreements( array $payload, int $project_id ): array {
		$out = array();
		foreach ( (array) ( $payload['agreements'] ?? array() ) as $a ) {
			if ( is_string( $a ) ) {
				$a = array( 'description' => $a );
			}
			if ( ! is_array( $a ) ) {
				continue;
			}
			$description = sanitize_textarea_field( (string) ( $a['description'] ?? '' ) );
			if ( '' === trim( $description ) ) {
				continue;
			}
			$owner_id   = (int) ( $a['owner_id'] ?? 0 );
			$owner_name = sanitize_text_field( (string) ( $a['owner_name'] ?? $a['owner'] ?? '' ) );
			if ( $owner_id <= 0 && '' !== $owner_name ) {
				$owner_id = $this->resolve_user( $owner_name, $project_id );
			}
			$due = ActivityRepository::normalize_date( $a['due_date'] ?? null );
			$out[] = array(
				'description' => $description,
				'owner_id'    => $owner_id > 0 ? $owner_id : 0,
				'owner_name'  => $owner_name,
				'due_date'    => $due ? $due : null,
				'status'      => in_array( $a['status'] ?? '', AgreementRepository::STATUSES, true ) ? (string) $a['status'] : 'pendiente',
				'confidence'  => (string) ( $a['confidence'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Usuario del sitio a partir de un nombre, usuario o correo; prioriza a los miembros del proyecto.
	 *
	 * @param string $text       Texto.
	 * @param int    $project_id Proyecto.
	 * @return int
	 */
	private function resolve_user( string $text, int $project_id ): int {
		$user = get_user_by( 'email', $text ) ?: get_user_by( 'login', $text );
		if ( $user ) {
			return (int) $user->ID;
		}
		$members = \GDP\Domain\Projects\MemberRepository::for_project( $project_id );
		$lower   = mb_strtolower( $text );
		foreach ( $members as $m ) {
			if ( mb_strtolower( (string) $m['display_name'] ) === $lower ) {
				return (int) $m['user_id'];
			}
		}
		$first = explode( ' ', $lower )[0];
		$hits  = array();
		foreach ( $members as $m ) {
			$name = mb_strtolower( (string) $m['display_name'] );
			if ( mb_strlen( $first ) >= 3 && ( 0 === strpos( $name, $first ) || false !== strpos( $name, ' ' . $first ) ) ) {
				$hits[] = (int) $m['user_id'];
			}
		}
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}
		$found = get_users( array( 'search' => '*' . $text . '*', 'search_columns' => array( 'display_name', 'user_login' ), 'number' => 2 ) );

		return 1 === count( $found ) ? (int) $found[0]->ID : 0;
	}

	/**
	 * Reunión del payload dentro del proyecto.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	private function meeting( array $payload, int $project_id ) {
		$meeting = MeetingRepository::find( (int) ( $payload['meeting_id'] ?? 0 ) );
		if ( ! $meeting || $meeting['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'La reunión no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}

		return $meeting;
	}

	/**
	 * Acuerdo del payload dentro del proyecto.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	private function agreement( array $payload, int $project_id ) {
		$agreement = AgreementRepository::find( (int) ( $payload['agreement_id'] ?? 0 ) );
		if ( ! $agreement || $agreement['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'El acuerdo no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}

		return $agreement;
	}
}
