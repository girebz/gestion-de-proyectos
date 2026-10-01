<?php
/**
 * Herramientas del conector para reuniones y acuerdos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\OperationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura de reuniones (con asistentes y acuerdos) y de acuerdos con filtros,
 * y propuesta de cambios: crear reuniones con acuerdos, registrar acuerdos
 * (por ejemplo, los que el asistente extrae de una transcripción), revisar
 * acuerdos de reunión en reunión y convertirlos en actividades.
 */
final class MeetingsTools {

	/**
	 * Añade las herramientas del módulo al catálogo del conector.
	 *
	 * @param array<string,array<string,mixed>> $definitions Definiciones.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_abilities( array $definitions ): array {
		$read  = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
		$write = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false );
		$out   = array( 'type' => 'object', 'additionalProperties' => true );

		$project_props = array(
			'project_id' => array( 'type' => 'integer', 'description' => __( 'Identificador numérico del proyecto.', 'gestion-de-proyectos' ) ),
			'code'       => array( 'type' => 'string', 'description' => __( 'Código del proyecto (alternativa al identificador).', 'gestion-de-proyectos' ) ),
		);

		$definitions['list-meetings'] = array(
			'label'            => __( 'Listar reuniones', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve las reuniones de un proyecto (código, título, tipo, estado programada|realizada|cancelada, fecha, lugar, organizador, número de asistentes y de acuerdos, acuerdos abiertos). Filtros: status, kind (equipo|financiador|proveedor|comite|terreno|otra), from, to (AAAA-MM-DD), search.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'status' => array( 'type' => 'string', 'enum' => MeetingRepository::STATUSES ),
					'kind'   => array( 'type' => 'string', 'enum' => MeetingRepository::KINDS ),
					'from'   => array( 'type' => 'string' ),
					'to'     => array( 'type' => 'string' ),
					'search' => array( 'type' => 'string' ),
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_meetings' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-meeting'] = array(
			'label'            => __( 'Obtener reunión', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve una reunión con asistentes, agenda, resumen, transcripción (si se guardó), acuerdos tomados (código, descripción, responsable, plazo, estado, actividad asociada, seguimiento), acuerdos anteriores pendientes de revisar en ella y la versión del registro.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'meeting_id' => array( 'type' => 'integer' ) ), 'required' => array( 'meeting_id' ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_meeting' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['list-agreements'] = array(
			'label'            => __( 'Listar acuerdos', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los acuerdos de un proyecto con reunión de origen, responsable, plazo, estado efectivo (sigue a la actividad si la hay), vencidos y seguimiento. Filtros: status (pendiente|en_curso|cumplido|cancelado), open, overdue, owner_id, meeting_id, search.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'status'     => array( 'type' => 'string', 'enum' => AgreementRepository::STATUSES ),
					'open'       => array( 'type' => 'boolean' ),
					'overdue'    => array( 'type' => 'boolean' ),
					'owner_id'   => array( 'type' => 'integer' ),
					'meeting_id' => array( 'type' => 'integer' ),
					'search'     => array( 'type' => 'string' ),
					'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_agreements' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['propose-meeting-change'] = array(
			'label'               => __( 'Proponer cambio en reuniones', 'gestion-de-proyectos' ),
			'description'         => __( 'Propone un cambio en las reuniones y acuerdos de un proyecto. NO aplica nada: devuelve una vista previa y un operation_id que debe confirmarse con confirm-operation. Acciones: create (data: title, kind, status, meeting_date, start_time, end_time, location, agenda, summary, transcript, organizer_id, activity_id; attendees: lista de user_id o {name, organization, email, attended}; agreements: lista de {description, owner_name u owner_id, due_date, status}), update (meeting_id, data, attendees, expected_version), delete (meeting_id; reversible), set_status (meeting_id, status), set_attendees (meeting_id, attendees), add_agreements (meeting_id, agreements, origin manual|conector: use esta acción para registrar los acuerdos que usted extraiga de una transcripción, con responsable y plazo), propose_from_text (meeting_id, text, save_transcript: análisis automático determinista del texto; la vista previa lista los acuerdos detectados con su confianza), update_agreement (agreement_id, data, expected_version), delete_agreement (agreement_id), review_agreement (agreement_id, meeting_id, status, note: seguimiento de reunión en reunión), agreement_to_activity (agreement_id, parent_id, duration, name: crea la actividad en el cronograma con el plazo como restricción). Los responsables indicados por nombre se resuelven a usuarios del sitio cuando coinciden con miembros del proyecto.', 'gestion-de-proyectos' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'action'           => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'delete', 'set_status', 'set_attendees', 'add_agreements', 'propose_from_text', 'update_agreement', 'delete_agreement', 'review_agreement', 'agreement_to_activity' ) ),
					'project_id'       => array( 'type' => 'integer' ),
					'meeting_id'       => array( 'type' => 'integer' ),
					'agreement_id'     => array( 'type' => 'integer' ),
					'data'             => array( 'type' => 'object', 'additionalProperties' => true ),
					'attendees'        => array( 'type' => 'array', 'items' => array( 'type' => array( 'object', 'integer', 'string' ), 'additionalProperties' => true ) ),
					'agreements'       => array( 'type' => 'array', 'items' => array( 'type' => array( 'object', 'string' ), 'additionalProperties' => true ) ),
					'origin'           => array( 'type' => 'string', 'enum' => array( 'manual', 'propuesto', 'conector' ) ),
					'text'             => array( 'type' => 'string' ),
					'save_transcript'  => array( 'type' => 'boolean' ),
					'expected_version' => array( 'type' => 'integer' ),
					'status'           => array( 'type' => 'string' ),
					'note'             => array( 'type' => 'string' ),
					'parent_id'        => array( 'type' => 'integer' ),
					'duration'         => array( 'type' => 'integer' ),
					'name'             => array( 'type' => 'string' ),
				),
				'required'             => array( 'action', 'project_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'propose_meeting_change' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => $write ),
		);

		return $definitions;
	}

	/**
	 * Reuniones.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_meetings( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$filters = array_intersect_key( $input, array_flip( array( 'status', 'kind', 'from', 'to', 'search', 'limit' ) ) );
		$rows    = array();
		foreach ( MeetingRepository::for_project( (int) $project['id'], $filters ) as $m ) {
			$rows[] = self::brief( $m );
		}

		return array( 'project_id' => (int) $project['id'], 'count' => count( $rows ), 'agreements' => AgreementRepository::stats( (int) $project['id'] ), 'meetings' => $rows );
	}

	/**
	 * Reunión completa.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_meeting( array $input = array() ) {
		$meeting = MeetingRepository::find( (int) ( $input['meeting_id'] ?? 0 ) );
		if ( ! $meeting ) {
			return new WP_Error( 'not_found', __( 'La reunión no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can( 'meetings.view', $meeting['project_id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver las reuniones de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$out                  = self::brief( $meeting );
		$out['agenda']        = $meeting['agenda'];
		$out['summary']       = $meeting['summary'];
		$out['transcript']    = $meeting['transcript'];
		$out['notes']         = $meeting['notes'];
		$out['attendees']     = $meeting['attendees'];
		$out['agreements']    = array_map( array( self::class, 'agreement_brief' ), AgreementRepository::for_meeting( $meeting['id'] ) );
		$out['carried_over']  = array_map( array( self::class, 'agreement_brief' ), AgreementRepository::carried_over( $meeting['project_id'], $meeting['id'] ) );
		$out['links']         = \GDP\Modules\Documents\LinkRepository::for_entity( 'meeting', $meeting['id'] );
		$out['version']       = $meeting['version'];

		return $out;
	}

	/**
	 * Acuerdos con filtros.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_agreements( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$filters = array_intersect_key( $input, array_flip( array( 'status', 'open', 'overdue', 'owner_id', 'meeting_id', 'search', 'limit' ) ) );
		$rows    = array_map( array( self::class, 'agreement_brief' ), AgreementRepository::for_project( (int) $project['id'], $filters ) );

		return array( 'project_id' => (int) $project['id'], 'count' => count( $rows ), 'stats' => AgreementRepository::stats( (int) $project['id'] ), 'agreements' => $rows );
	}

	/**
	 * Propone una operación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_meeting_change( array $input = array() ) {
		$action     = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$project_id = (int) ( $input['project_id'] ?? 0 );
		if ( $project_id <= 0 || ! ProjectRepository::find( $project_id ) ) {
			return new WP_Error( 'missing_project', __( 'Indique un project_id válido.', 'gestion-de-proyectos' ) );
		}
		$payload = array_intersect_key( $input, array_flip( array( 'meeting_id', 'agreement_id', 'data', 'attendees', 'agreements', 'origin', 'text', 'save_transcript', 'expected_version', 'status', 'note', 'parent_id', 'duration', 'name' ) ) );
		if ( 'add_agreements' === $action && empty( $payload['origin'] ) ) {
			$payload['origin'] = 'conector';
		}

		return OperationManager::propose( 'meeting', $action, $payload, $project_id, 'connector' );
	}

	/**
	 * Resumen de una reunión.
	 *
	 * @param array<string,mixed> $m Reunión.
	 * @return array<string,mixed>
	 */
	public static function brief( array $m ): array {
		$agreements = AgreementRepository::for_meeting( $m['id'] );
		$organizer  = $m['organizer_id'] > 0 ? get_userdata( $m['organizer_id'] ) : null;

		return array(
			'id'              => $m['id'],
			'code'            => $m['code'],
			'title'           => $m['title'],
			'kind'            => $m['kind'],
			'kind_label'      => MeetingRepository::kind_labels()[ $m['kind'] ] ?? $m['kind'],
			'status'          => $m['status'],
			'meeting_date'    => $m['meeting_date'],
			'start_time'      => $m['start_time'],
			'end_time'        => $m['end_time'],
			'location'        => $m['location'],
			'organizer'       => $organizer ? $organizer->display_name : '',
			'activity_id'     => $m['activity_id'],
			'minutes_document_id' => $m['minutes_document_id'],
			'attendee_count'  => count( $m['attendees'] ),
			'agreement_count' => count( $agreements ),
			'open_agreements' => count( array_filter( $agreements, static fn( array $a ): bool => in_array( AgreementRepository::effective_status( $a ), AgreementRepository::OPEN, true ) ) ),
		);
	}

	/**
	 * Resumen de un acuerdo.
	 *
	 * @param array<string,mixed> $a Acuerdo.
	 * @return array<string,mixed>
	 */
	public static function agreement_brief( array $a ): array {
		$meeting = MeetingRepository::find( $a['meeting_id'] );

		return array(
			'id'               => $a['id'],
			'code'             => $a['code'],
			'meeting_id'       => $a['meeting_id'],
			'meeting_code'     => $meeting ? $meeting['code'] : '',
			'meeting_date'     => $meeting ? $meeting['meeting_date'] : null,
			'description'      => $a['description'],
			'owner_id'         => $a['owner_id'],
			'owner_name'       => $a['owner_name'],
			'due_date'         => $a['due_date'],
			'status'           => $a['status'],
			'effective_status' => AgreementRepository::effective_status( $a ),
			'overdue'          => $a['overdue'],
			'activity_id'      => $a['activity_id'],
			'fulfilled_at'     => $a['fulfilled_at'],
			'origin'           => $a['origin'],
			'follow_up'        => $a['follow_up'],
			'version'          => $a['version'],
		);
	}

	/**
	 * Resuelve el proyecto y comprueba el permiso de lectura.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function project( array $input ) {
		$project = null;
		if ( ! empty( $input['project_id'] ) ) {
			$project = ProjectRepository::find( (int) $input['project_id'] );
		} elseif ( ! empty( $input['code'] ) ) {
			$project = ProjectRepository::find_by_code( sanitize_title( (string) $input['code'] ) );
		} else {
			return new WP_Error( 'missing_identifier', __( 'Indique project_id o code.', 'gestion-de-proyectos' ) );
		}
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can( 'meetings.view', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver las reuniones de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return $project;
	}
}
