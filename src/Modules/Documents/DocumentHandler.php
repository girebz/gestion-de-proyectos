<?php
/**
 * Manejador de operaciones sobre documentos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Core\Access;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Escrituras en dos tiempos sobre documentos: crear, actualizar, eliminar
 * (reversible desde la papelera), cambiar de estado (la aprobación exige
 * documents.approve), vincular y desvincular entidades y fijar o quitar
 * referencias en sistemas externos. Las subidas de archivo se hacen desde el
 * panel y quedan en la bitácora como versiones.
 */
final class DocumentHandler implements HandlerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'document';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array( 'create', 'update', 'delete', 'set_status', 'link', 'unlink', 'set_external_ref', 'remove_external_ref' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( $project_id <= 0 ) {
			return false;
		}
		if ( 'set_status' === $action && 'aprobado' === sanitize_key( (string) ( $payload['status'] ?? '' ) ) ) {
			return Access::can( 'documents.approve', $project_id, $user_id );
		}

		return Access::can( 'documents.edit', $project_id, $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$data  = array_merge( array( 'type' => 'otro', 'status' => 'borrador', 'number' => '', 'doc_date' => current_time( 'Y-m-d' ) ), $data );
				$clean = DocumentRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['subject'] ) ) {
					return new WP_Error( 'required', __( 'El asunto es obligatorio.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'update':
				$document = $this->document( $payload, $project_id );
				if ( is_wp_error( $document ) ) {
					return $document;
				}
				$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				if ( empty( $data ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				$clean = DocumentRepository::validate( $data, $project_id, $document['id'] );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array(
					'document_id'      => $document['id'],
					'data'             => $clean,
					'expected_version' => isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null,
				);

			case 'delete':
				$document = $this->document( $payload, $project_id );
				if ( is_wp_error( $document ) ) {
					return $document;
				}
				return array( 'document_id' => $document['id'] );

			case 'set_status':
				$document = $this->document( $payload, $project_id );
				if ( is_wp_error( $document ) ) {
					return $document;
				}
				$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
				if ( ! in_array( $status, DocumentRepository::STATUSES, true ) ) {
					return new WP_Error( 'status', __( 'El estado no es válido.', 'gestion-de-proyectos' ) );
				}
				$out = array( 'document_id' => $document['id'], 'status' => $status, 'note' => sanitize_text_field( (string) ( $payload['note'] ?? '' ) ) );
				if ( 'respondido' === $status ) {
					$date = \GDP\Modules\Planning\ActivityRepository::normalize_date( $payload['responded_at'] ?? current_time( 'Y-m-d' ) );
					if ( false === $date ) {
						return new WP_Error( 'date', __( 'Fecha de respuesta no válida.', 'gestion-de-proyectos' ) );
					}
					$out['responded_at'] = $date ? $date : current_time( 'Y-m-d' );
					if ( ! empty( $payload['response_document_id'] ) ) {
						$response = DocumentRepository::find( (int) $payload['response_document_id'] );
						if ( ! $response || $response['project_id'] !== $project_id ) {
							return new WP_Error( 'not_found', __( 'El documento de respuesta no existe en este proyecto.', 'gestion-de-proyectos' ) );
						}
						$out['response_document_id'] = $response['id'];
					}
				}
				return $out;

			case 'link':
				$document = $this->document( $payload, $project_id );
				if ( is_wp_error( $document ) ) {
					return $document;
				}
				$to_type  = sanitize_key( (string) ( $payload['to_type'] ?? '' ) );
				$to_id    = (int) ( $payload['to_id'] ?? 0 );
				$relation = sanitize_key( (string) ( $payload['relation'] ?? 'refers_to' ) );
				$entities = LinkRepository::entities();
				if ( ! isset( $entities[ $to_type ] ) || $to_id <= 0 ) {
					return new WP_Error( 'entity', __( 'Indique la entidad de destino (to_type, to_id).', 'gestion-de-proyectos' ) );
				}
				if ( ! in_array( $relation, LinkRepository::RELATIONS, true ) ) {
					return new WP_Error( 'relation', __( 'Relación no válida.', 'gestion-de-proyectos' ) );
				}
				if ( 'document' === $to_type && $to_id === $document['id'] ) {
					return new WP_Error( 'self', __( 'Un documento no puede vincularse consigo mismo.', 'gestion-de-proyectos' ) );
				}
				$target = call_user_func( $entities[ $to_type ]['resolve'], $to_id );
				if ( ! is_array( $target ) ) {
					return new WP_Error( 'not_found', __( 'La entidad de destino no existe.', 'gestion-de-proyectos' ) );
				}
				if ( isset( $target['project_id'] ) && (int) $target['project_id'] !== $project_id ) {
					return new WP_Error( 'project', __( 'La entidad de destino pertenece a otro proyecto.', 'gestion-de-proyectos' ) );
				}
				return array(
					'document_id' => $document['id'],
					'to_type'     => $to_type,
					'to_id'       => $to_id,
					'relation'    => $relation,
					'note'        => sanitize_text_field( (string) ( $payload['note'] ?? '' ) ),
				);

			case 'unlink':
				$link = LinkRepository::find( (int) ( $payload['link_id'] ?? 0 ) );
				if ( ! $link || $link['project_id'] !== $project_id ) {
					return new WP_Error( 'not_found', __( 'El vínculo no existe en este proyecto.', 'gestion-de-proyectos' ) );
				}
				return array( 'link_id' => $link['id'] );

			case 'set_external_ref':
				$document = $this->document( $payload, $project_id );
				if ( is_wp_error( $document ) ) {
					return $document;
				}
				$system = sanitize_text_field( (string) ( $payload['system'] ?? '' ) );
				if ( '' === $system ) {
					return new WP_Error( 'system', __( 'Indique el sistema externo.', 'gestion-de-proyectos' ) );
				}
				$url = esc_url_raw( (string) ( $payload['url'] ?? '' ) );
				if ( '' !== $url && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
					return new WP_Error( 'url', __( 'El enlace no es válido.', 'gestion-de-proyectos' ) );
				}
				return array(
					'document_id' => $document['id'],
					'system'      => $system,
					'number'      => sanitize_text_field( (string) ( $payload['number'] ?? '' ) ),
					'status'      => sanitize_text_field( (string) ( $payload['status'] ?? '' ) ),
					'url'         => $url,
				);

			case 'remove_external_ref':
				$ref = ExternalRefRepository::find( (int) ( $payload['ref_id'] ?? 0 ) );
				if ( ! $ref || $ref['project_id'] !== $project_id ) {
					return new WP_Error( 'not_found', __( 'La referencia no existe en este proyecto.', 'gestion-de-proyectos' ) );
				}
				return array( 'ref_id' => $ref['id'] );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$preview = array( 'summary' => '', 'changes' => array(), 'warnings' => array(), 'conflicts' => array() );
		$labels  = DocumentRepository::status_labels();

		switch ( $action ) {
			case 'create':
				$types = DocumentRepository::types( $project_id );
				$type  = $types[ $payload['data']['type'] ] ?? null;
				$preview['summary'] = sprintf( 'Crear el documento "%s" (%s)', $payload['data']['subject'], $type ? $type['label'] : $payload['data']['type'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( null !== $value && '' !== $value ) {
						$preview['changes'][ $field ] = array( 'before' => null, 'after' => $value );
					}
				}
				if ( $type && $type['numbered'] && '' === ( $payload['data']['number'] ?? '' ) ) {
					$next                          = DocumentRepository::next_number( $project_id, $payload['data']['type'], $payload['data']['doc_date'] ?? null );
					$preview['changes']['number']  = array( 'before' => null, 'after' => $next['number'] );
					$preview['warnings'][]         = sprintf( 'Se asignará el número correlativo %s.', $next['number'] );
				}
				break;

			case 'update':
				$current            = DocumentRepository::find( $payload['document_id'] );
				$preview['summary'] = sprintf( 'Actualizar el documento "%s"', $current['number'] ? $current['number'] . ' ' . $current['subject'] : $current['subject'] );
				foreach ( $payload['data'] as $field => $value ) {
					$before = $current[ $field ] ?? null;
					if ( (string) $before !== (string) $value ) {
						$preview['changes'][ $field ] = array( 'before' => $before, 'after' => $value );
					}
				}
				if ( null !== $payload['expected_version'] && $current['version'] !== $payload['expected_version'] ) {
					$preview['conflicts'][] = sprintf( 'El documento está en la versión %d y la propuesta se basó en la versión %d.', $current['version'], $payload['expected_version'] );
				}
				if ( empty( $preview['changes'] ) ) {
					$preview['warnings'][] = 'Ningún campo cambia respecto del estado actual.';
				}
				break;

			case 'delete':
				$current            = DocumentRepository::find( $payload['document_id'] );
				$versions           = VersionRepository::for_document( $current['id'] );
				$links              = LinkRepository::rows_for_entity( 'document', $current['id'] );
				$preview['summary'] = sprintf( 'Eliminar el documento "%s"', $current['number'] ? $current['number'] . ' ' . $current['subject'] : $current['subject'] );
				$preview['changes']['document'] = array( 'before' => $current['number'] . ' ' . $current['subject'], 'after' => null );
				if ( ! empty( $versions ) ) {
					$preview['warnings'][] = sprintf( 'Se apartarán %d versiones de archivo (recuperables al restaurar).', count( $versions ) );
				}
				if ( ! empty( $links ) ) {
					$preview['warnings'][] = sprintf( 'Se eliminarán %d vínculos con otras entidades.', count( $links ) );
				}
				if ( $current['number'] && $current['sequence'] > 0 ) {
					$preview['warnings'][] = 'El número correlativo quedará sin usar; los siguientes documentos no lo reutilizan.';
				}
				break;

			case 'set_status':
				$current            = DocumentRepository::find( $payload['document_id'] );
				$preview['summary'] = sprintf( 'Pasar el documento "%s" de %s a %s', $current['number'] ? $current['number'] : $current['subject'], $labels[ $current['status'] ] ?? $current['status'], $labels[ $payload['status'] ] ?? $payload['status'] );
				$preview['changes']['status'] = array( 'before' => $current['status'], 'after' => $payload['status'] );
				if ( isset( $payload['responded_at'] ) ) {
					$preview['changes']['responded_at'] = array( 'before' => $current['responded_at'], 'after' => $payload['responded_at'] );
				}
				if ( $current['status'] === $payload['status'] ) {
					$preview['warnings'][] = 'El documento ya está en ese estado.';
				}
				if ( 'aprobado' === $payload['status'] ) {
					$preview['warnings'][] = 'La aprobación es una decisión formal del director del proyecto.';
				}
				if ( 'anulado' === $payload['status'] && $current['sequence'] > 0 ) {
					$preview['warnings'][] = 'Un documento anulado conserva su número para no romper el correlativo.';
				}
				break;

			case 'link':
				$current            = DocumentRepository::find( $payload['document_id'] );
				$target             = LinkRepository::resolve( $payload['to_type'], $payload['to_id'] );
				$preview['summary'] = sprintf( 'Vincular "%s" (%s) con %s "%s"', $current['number'] ? $current['number'] : $current['subject'], LinkRepository::relation_labels()[ $payload['relation'] ], $target['label'], trim( $target['code'] . ' ' . $target['title'] ) );
				$preview['changes']['link'] = array( 'before' => null, 'after' => $payload['relation'] . ' ' . $payload['to_type'] . '#' . $payload['to_id'] );
				break;

			case 'unlink':
				$link               = LinkRepository::find( $payload['link_id'] );
				$from               = LinkRepository::resolve( $link['from_type'], $link['from_id'] );
				$to                 = LinkRepository::resolve( $link['to_type'], $link['to_id'] );
				$preview['summary'] = sprintf( 'Quitar el vínculo entre %s "%s" y %s "%s"', $from['label'], trim( $from['code'] . ' ' . $from['title'] ), $to['label'], trim( $to['code'] . ' ' . $to['title'] ) );
				$preview['changes']['link'] = array( 'before' => $link['relation'], 'after' => null );
				break;

			case 'set_external_ref':
				$current            = DocumentRepository::find( $payload['document_id'] );
				$preview['summary'] = sprintf( 'Fijar la referencia de "%s" en %s: %s', $current['number'] ? $current['number'] : $current['subject'], $payload['system'], trim( $payload['number'] . ' ' . $payload['status'] ) );
				foreach ( ExternalRefRepository::for_entity( 'document', $current['id'] ) as $ref ) {
					if ( $ref['system_name'] === $payload['system'] ) {
						$preview['changes']['number'] = array( 'before' => $ref['ref_number'], 'after' => $payload['number'] );
						$preview['changes']['status'] = array( 'before' => $ref['ref_status'], 'after' => $payload['status'] );
					}
				}
				if ( empty( $preview['changes'] ) ) {
					$preview['changes']['reference'] = array( 'before' => null, 'after' => $payload['system'] . ' ' . $payload['number'] );
				}
				break;

			case 'remove_external_ref':
				$ref                = ExternalRefRepository::find( $payload['ref_id'] );
				$preview['summary'] = sprintf( 'Quitar la referencia en %s (%s)', $ref['system_name'], $ref['ref_number'] );
				$preview['changes']['reference'] = array( 'before' => $ref['system_name'] . ' ' . $ref['ref_number'], 'after' => null );
				break;
		}

		return $preview;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$id = DocumentRepository::create( $project_id, $payload['data'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return array( 'before' => null, 'result' => array( 'document_id' => $id, 'document' => DocumentRepository::find( $id ) ) );

			case 'update':
				$before  = DocumentRepository::find( $payload['document_id'] );
				$updated = DocumentRepository::update( $payload['document_id'], $payload['data'], $payload['expected_version'] );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				return array( 'before' => $before, 'result' => array( 'document_id' => $updated['id'], 'document' => $updated ) );

			case 'delete':
				$snapshot = DocumentRepository::snapshot( $payload['document_id'] );
				$ok       = DocumentRepository::delete( $payload['document_id'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				return array( 'before' => $snapshot, 'result' => array( 'document_id' => $payload['document_id'] ) );

			case 'set_status':
				$before = DocumentRepository::find( $payload['document_id'] );
				$data   = array( 'status' => $payload['status'] );
				if ( isset( $payload['responded_at'] ) ) {
					$data['responded_at'] = $payload['responded_at'];
				}
				$updated = DocumentRepository::update( $payload['document_id'], $data, null );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$link_id = 0;
				if ( ! empty( $payload['response_document_id'] ) ) {
					$link = LinkRepository::add( $project_id, 'document', (int) $payload['response_document_id'], 'document', $updated['id'], 'responds_to', $payload['note'] );
					$link_id = is_wp_error( $link ) ? 0 : (int) $link;
				}
				return array( 'before' => $before, 'result' => array( 'document_id' => $updated['id'], 'status' => $updated['status'], 'link_id' => $link_id ) );

			case 'link':
				$id = LinkRepository::add( $project_id, 'document', $payload['document_id'], $payload['to_type'], $payload['to_id'], $payload['relation'], $payload['note'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return array( 'before' => null, 'result' => array( 'link_id' => $id ) );

			case 'unlink':
				$row = LinkRepository::remove( $payload['link_id'] );
				return array( 'before' => $row, 'result' => array( 'link_id' => $payload['link_id'] ) );

			case 'set_external_ref':
				$before = null;
				foreach ( ExternalRefRepository::for_entity( 'document', $payload['document_id'] ) as $ref ) {
					if ( $ref['system_name'] === $payload['system'] ) {
						$before = $ref;
					}
				}
				$id = ExternalRefRepository::set( $project_id, 'document', $payload['document_id'], $payload['system'], $payload['number'], $payload['status'], $payload['url'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return array( 'before' => $before, 'result' => array( 'ref_id' => $id ) );

			case 'remove_external_ref':
				$row = ExternalRefRepository::remove( $payload['ref_id'] );
				return array( 'before' => $row, 'result' => array( 'ref_id' => $payload['ref_id'] ) );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$id = (int) ( $result['document_id'] ?? 0 );
				return $id > 0 ? DocumentRepository::delete( $id ) : new WP_Error( 'nothing_to_revert', __( 'No hay documento que revertir.', 'gestion-de-proyectos' ) );

			case 'update':
			case 'set_status':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$fields = array_intersect_key( $before, array_flip( array( 'type', 'direction', 'number', 'doc_date', 'sender', 'recipient', 'subject', 'body', 'status', 'response_due', 'responded_at', 'owner_id', 'activity_id', 'notes' ) ) );
				$r      = DocumentRepository::update( (int) $before['id'], $fields, null );
				if ( ! is_wp_error( $r ) && ! empty( $result['link_id'] ) ) {
					LinkRepository::remove( (int) $result['link_id'] );
				}
				return is_wp_error( $r ) ? $r : true;

			case 'delete':
				return $before ? DocumentRepository::restore( $before ) : new WP_Error( 'nothing_to_revert', __( 'No hay instantánea del documento.', 'gestion-de-proyectos' ) );

			case 'link':
				LinkRepository::remove( (int) ( $result['link_id'] ?? 0 ) );
				return true;

			case 'unlink':
				if ( $before ) {
					LinkRepository::restore( array( $before ) );
				}
				return true;

			case 'set_external_ref':
				if ( $before ) {
					ExternalRefRepository::set( (int) $before['project_id'], (string) $before['entity_type'], (int) $before['entity_id'], (string) $before['system_name'], (string) $before['ref_number'], (string) $before['ref_status'], (string) $before['url'] );
				} else {
					ExternalRefRepository::remove( (int) ( $result['ref_id'] ?? 0 ) );
				}
				return true;

			case 'remove_external_ref':
				if ( $before ) {
					ExternalRefRepository::restore( array( $before ) );
				}
				return true;
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Documento del payload, comprobando que pertenece al proyecto.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	private function document( array $payload, int $project_id ) {
		$document = DocumentRepository::find( (int) ( $payload['document_id'] ?? 0 ) );
		if ( ! $document || $document['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'El documento no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}

		return $document;
	}
}
