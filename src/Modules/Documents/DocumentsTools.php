<?php
/**
 * Herramientas del conector para el control documental.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Búsqueda y lectura de documentos (con versiones, vínculos y referencias
 * externas) y propuesta de cambios en dos tiempos a través de DocumentHandler.
 */
final class DocumentsTools {

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

		$definitions['list-documents'] = array(
			'label'            => __( 'Listar documentos', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los documentos de un proyecto (cartas, oficios, contratos, órdenes, cotizaciones, facturas, actas, informes) con número, fecha, emisor, destinatario, asunto, estado, plazo de respuesta y responsable. Filtros: type (slug del catálogo de tipos), status (borrador|enviado|recibido|respondido|aprobado|cerrado|anulado), direction (out|in|internal), open (solo abiertos), overdue (solo con respuesta vencida), owner_id, activity_id, search (texto en asunto, número, emisor, destinatario o cuerpo). Incluye el resumen de vencimientos.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'type'        => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string', 'enum' => DocumentRepository::STATUSES ),
					'direction'   => array( 'type' => 'string', 'enum' => DocumentRepository::DIRECTIONS ),
					'open'        => array( 'type' => 'boolean' ),
					'overdue'     => array( 'type' => 'boolean' ),
					'owner_id'    => array( 'type' => 'integer' ),
					'activity_id' => array( 'type' => 'integer' ),
					'search'      => array( 'type' => 'string' ),
					'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_documents' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-document'] = array(
			'label'            => __( 'Obtener documento', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve un documento con todos sus campos (incluido el cuerpo), sus versiones de archivo (nombre, tamaño, huella, quién y cuándo), sus vínculos con otros documentos, actividades y demás entidades, sus referencias en sistemas externos y la versión del registro, necesaria para proponer cambios sin conflictos.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => array( 'document_id' => array( 'type' => 'integer' ) ),
				'required'             => array( 'document_id' ),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_document' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['propose-document-change'] = array(
			'label'               => __( 'Proponer cambio documental', 'gestion-de-proyectos' ),
			'description'         => __( 'Propone un cambio sobre los documentos de un proyecto. NO aplica nada: devuelve una vista previa y un operation_id que debe confirmarse con confirm-operation. Acciones: create (data: type del catálogo, subject, doc_date, sender, recipient, body, status, response_due, owner_id, activity_id, notes; number opcional: los tipos numerados reciben correlativo automático), update (document_id, data, expected_version), delete (document_id; reversible desde la papelera), set_status (document_id, status; con respondido admite responded_at y response_document_id, que crea el vínculo "responde a"; aprobado exige el permiso de aprobación), link (document_id, to_type document|activity|..., to_id, relation responds_to|refers_to|supports|related, note), unlink (link_id), set_external_ref (document_id, system, number, status, url), remove_external_ref (ref_id). Los archivos se suben desde el panel.', 'gestion-de-proyectos' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'action'               => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'delete', 'set_status', 'link', 'unlink', 'set_external_ref', 'remove_external_ref' ) ),
					'project_id'           => array( 'type' => 'integer' ),
					'document_id'          => array( 'type' => 'integer' ),
					'data'                 => array( 'type' => 'object', 'additionalProperties' => true, 'description' => __( 'Campos del documento (create, update).', 'gestion-de-proyectos' ) ),
					'expected_version'     => array( 'type' => 'integer' ),
					'status'               => array( 'type' => 'string', 'enum' => DocumentRepository::STATUSES ),
					'responded_at'         => array( 'type' => 'string' ),
					'response_document_id' => array( 'type' => 'integer' ),
					'note'                 => array( 'type' => 'string' ),
					'to_type'              => array( 'type' => 'string' ),
					'to_id'                => array( 'type' => 'integer' ),
					'relation'             => array( 'type' => 'string', 'enum' => LinkRepository::RELATIONS ),
					'link_id'              => array( 'type' => 'integer' ),
					'system'               => array( 'type' => 'string' ),
					'number'               => array( 'type' => 'string' ),
					'url'                  => array( 'type' => 'string' ),
					'ref_id'               => array( 'type' => 'integer' ),
				),
				'required'             => array( 'action', 'project_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'propose_document_change' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => $write ),
		);

		return $definitions;
	}

	/**
	 * Documentos con filtros.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_documents( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$filters = array_intersect_key( $input, array_flip( array( 'type', 'status', 'direction', 'open', 'overdue', 'owner_id', 'activity_id', 'search', 'limit' ) ) );
		$types   = DocumentRepository::types( (int) $project['id'] );
		$rows    = array();
		foreach ( DocumentRepository::for_project( (int) $project['id'], $filters ) as $d ) {
			$rows[] = self::brief( $d, $types );
		}

		return array(
			'project_id'   => (int) $project['id'],
			'project_code' => $project['code'],
			'count'        => count( $rows ),
			'stats'        => DocumentRepository::stats( (int) $project['id'] ),
			'types'        => array_values( $types ),
			'documents'    => $rows,
		);
	}

	/**
	 * Documento completo.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_document( array $input = array() ) {
		$document = DocumentRepository::find( (int) ( $input['document_id'] ?? 0 ) );
		if ( ! $document ) {
			return new WP_Error( 'not_found', __( 'El documento no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can( 'documents.view', $document['project_id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver los documentos de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$types                     = DocumentRepository::types( $document['project_id'] );
		$document['type_label']    = $types[ $document['type'] ]['label'] ?? $document['type'];
		$document['status_label']  = DocumentRepository::status_labels()[ $document['status'] ] ?? $document['status'];
		$document['owner']         = ScheduleService::user_name( $document['owner_id'] );
		$document['overdue']       = self::is_overdue( $document );
		$document['versions']      = array_map( static fn( array $v ): array => array( 'version_no' => $v['version_no'], 'filename' => $v['filename'], 'size' => $v['size'], 'mime' => $v['mime'], 'sha256' => $v['sha256'], 'note' => $v['note'], 'uploaded_by' => $v['uploaded_name'], 'created_at' => $v['created_at'] ), VersionRepository::for_document( $document['id'] ) );
		$document['links']         = LinkRepository::for_entity( 'document', $document['id'] );
		$document['external_refs'] = array_map( static fn( array $r ): array => array( 'id' => $r['id'], 'system' => $r['system_name'], 'number' => $r['ref_number'], 'status' => $r['ref_status'], 'url' => $r['url'], 'updated_at' => $r['updated_at'] ), ExternalRefRepository::for_entity( 'document', $document['id'] ) );

		return $document;
	}

	/**
	 * Propone una operación documental.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_document_change( array $input = array() ) {
		$action     = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$project_id = (int) ( $input['project_id'] ?? 0 );
		if ( $project_id <= 0 || ! ProjectRepository::find( $project_id ) ) {
			return new WP_Error( 'missing_project', __( 'Indique un project_id válido.', 'gestion-de-proyectos' ) );
		}
		$payload = array_intersect_key( $input, array_flip( array( 'document_id', 'data', 'expected_version', 'status', 'responded_at', 'response_document_id', 'note', 'to_type', 'to_id', 'relation', 'link_id', 'system', 'number', 'url', 'ref_id' ) ) );

		return OperationManager::propose( 'document', $action, $payload, $project_id, 'connector' );
	}

	/**
	 * Resumen de un documento para listas.
	 *
	 * @param array<string,mixed>               $d     Documento.
	 * @param array<string,array<string,mixed>> $types Tipos.
	 * @return array<string,mixed>
	 */
	public static function brief( array $d, array $types ): array {
		return array(
			'id'           => $d['id'],
			'type'         => $d['type'],
			'type_label'   => $types[ $d['type'] ]['label'] ?? $d['type'],
			'direction'    => $d['direction'],
			'number'       => $d['number'],
			'doc_date'     => $d['doc_date'],
			'sender'       => $d['sender'],
			'recipient'    => $d['recipient'],
			'subject'      => $d['subject'],
			'status'       => $d['status'],
			'response_due' => $d['response_due'],
			'responded_at' => $d['responded_at'],
			'overdue'      => self::is_overdue( $d ),
			'owner_id'     => $d['owner_id'],
			'owner'        => ScheduleService::user_name( $d['owner_id'] ),
			'activity_id'  => $d['activity_id'],
			'versions'     => $d['current_version'],
			'version'      => $d['version'],
		);
	}

	/**
	 * Indica si la respuesta está vencida.
	 *
	 * @param array<string,mixed> $d Documento.
	 * @return bool
	 */
	public static function is_overdue( array $d ): bool {
		return null !== $d['response_due'] && in_array( $d['status'], array( 'enviado', 'recibido' ), true ) && $d['response_due'] < current_time( 'Y-m-d' );
	}

	/**
	 * Resuelve el proyecto y comprueba el permiso de lectura documental.
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
		if ( ! Access::can( 'documents.view', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver los documentos de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return $project;
	}
}
