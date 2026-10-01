<?php
/**
 * Manejador de operaciones sobre compras, cotizaciones, proveedores y presupuesto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Access;
use GDP\Core\Schema;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Escrituras en dos tiempos del módulo de adquisiciones. Al avanzar a la
 * etapa de orden de compra, la vista previa incorpora las comprobaciones de
 * reajuste y valor implícito de la unidad de fomento; una discrepancia es un
 * conflicto que impide confirmar hasta revisar la orden.
 */
final class PurchaseHandler implements HandlerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'purchase';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array( 'create', 'update', 'delete', 'set_stage', 'approve', 'add_quote', 'update_quote', 'delete_quote', 'choose_quote', 'set_quote_items', 'create_supplier', 'update_supplier', 'delete_supplier', 'set_budget' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( $project_id <= 0 ) {
			return false;
		}
		if ( 'approve' === $action ) {
			return Access::can( 'procurement.approve', $project_id, $user_id );
		}
		if ( 'set_budget' === $action ) {
			return Access::can( 'procurement.edit', $project_id, $user_id ) && Access::can( 'procurement.view_amounts', $project_id, $user_id );
		}

		return Access::can( 'procurement.edit', $project_id, $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$clean = PurchaseRepository::validate( array_merge( array( 'status' => 'abierta', 'currency' => 'CLP', 'tax_rate' => 19.0 ), $data ), $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['title'] ) ) {
					return new WP_Error( 'required', __( 'El título de la compra es obligatorio.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'update':
				$purchase = $this->purchase( $payload, $project_id );
				if ( is_wp_error( $purchase ) ) {
					return $purchase;
				}
				$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				if ( empty( $data ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				unset( $data['stage'] );
				$clean = PurchaseRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array( 'purchase_id' => $purchase['id'], 'data' => $clean, 'expected_version' => isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null );

			case 'delete':
			case 'approve':
				$purchase = $this->purchase( $payload, $project_id );
				if ( is_wp_error( $purchase ) ) {
					return $purchase;
				}
				return array( 'purchase_id' => $purchase['id'] );

			case 'set_stage':
				$purchase = $this->purchase( $payload, $project_id );
				if ( is_wp_error( $purchase ) ) {
					return $purchase;
				}
				$stage = sanitize_key( (string) ( $payload['stage'] ?? '' ) );
				if ( ! isset( PurchaseRepository::stages( $project_id )[ $stage ] ) ) {
					return new WP_Error( 'stage', __( 'La etapa no está en el catálogo.', 'gestion-de-proyectos' ) );
				}
				$date = \GDP\Modules\Planning\ActivityRepository::normalize_date( $payload['date'] ?? current_time( 'Y-m-d' ) );
				if ( false === $date ) {
					return new WP_Error( 'date', __( 'Fecha no válida.', 'gestion-de-proyectos' ) );
				}
				$document_id = (int) ( $payload['document_id'] ?? 0 );
				if ( $document_id > 0 ) {
					$doc = \GDP\Modules\Documents\DocumentRepository::find( $document_id );
					if ( ! $doc || $doc['project_id'] !== $project_id ) {
						return new WP_Error( 'not_found', __( 'El documento no existe en este proyecto.', 'gestion-de-proyectos' ) );
					}
				}
				$out = array( 'purchase_id' => $purchase['id'], 'stage' => $stage, 'date' => $date ? $date : current_time( 'Y-m-d' ), 'note' => sanitize_text_field( (string) ( $payload['note'] ?? '' ) ), 'document_id' => $document_id );
				foreach ( array( 'order_number', 'invoice_number' ) as $field ) {
					if ( isset( $payload[ $field ] ) ) {
						$out[ $field ] = sanitize_text_field( (string) $payload[ $field ] );
					}
				}
				return $out;

			case 'add_quote':
				$purchase = $this->purchase( $payload, $project_id );
				if ( is_wp_error( $purchase ) ) {
					return $purchase;
				}
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$clean = QuoteRepository::validate( array_merge( array( 'status' => 'solicitada', 'currency' => $purchase['currency'], 'tax_rate' => 19.0 ), $data ), $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array( 'purchase_id' => $purchase['id'], 'data' => $clean, 'items' => $this->items( $payload ) );

			case 'update_quote':
				$quote = $this->quote( $payload, $project_id );
				if ( is_wp_error( $quote ) ) {
					return $quote;
				}
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$clean = QuoteRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean ) && ! isset( $payload['items'] ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				return array( 'quote_id' => $quote['id'], 'data' => $clean, 'items' => isset( $payload['items'] ) ? $this->items( $payload ) : null );

			case 'delete_quote':
			case 'choose_quote':
				$quote = $this->quote( $payload, $project_id );
				if ( is_wp_error( $quote ) ) {
					return $quote;
				}
				return array( 'quote_id' => $quote['id'] );

			case 'set_quote_items':
				$quote = $this->quote( $payload, $project_id );
				if ( is_wp_error( $quote ) ) {
					return $quote;
				}
				$items = $this->items( $payload );
				if ( empty( $items ) ) {
					return new WP_Error( 'items', __( 'Indique al menos un ítem con descripción.', 'gestion-de-proyectos' ) );
				}
				return array( 'quote_id' => $quote['id'], 'items' => $items );

			case 'create_supplier':
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$clean = SupplierRepository::validate( array_merge( array( 'active' => 1, 'project_id' => ! empty( $payload['global'] ) ? 0 : $project_id ), $data ) );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['name'] ) ) {
					return new WP_Error( 'required', __( 'El nombre del proveedor es obligatorio.', 'gestion-de-proyectos' ) );
				}
				if ( 0 === (int) $clean['project_id'] && ! Access::is_manager() ) {
					$clean['project_id'] = $project_id;
				}
				return array( 'data' => $clean );

			case 'update_supplier':
			case 'delete_supplier':
				$supplier = SupplierRepository::find( (int) ( $payload['supplier_id'] ?? 0 ) );
				if ( ! $supplier || ( $supplier['project_id'] > 0 && $supplier['project_id'] !== $project_id ) ) {
					return new WP_Error( 'not_found', __( 'El proveedor no existe o pertenece a otro proyecto.', 'gestion-de-proyectos' ) );
				}
				if ( 0 === $supplier['project_id'] && ! Access::is_manager() ) {
					return new WP_Error( 'forbidden', __( 'Los proveedores globales solo los editan los administradores del plugin.', 'gestion-de-proyectos' ) );
				}
				if ( 'delete_supplier' === $action ) {
					return array( 'supplier_id' => $supplier['id'] );
				}
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				unset( $data['project_id'] );
				$clean = SupplierRepository::validate( $data );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				return array( 'supplier_id' => $supplier['id'], 'data' => $clean );

			case 'set_budget':
				$code = sanitize_key( (string) ( $payload['budget_line'] ?? '' ) );
				if ( '' === $code || ! isset( PurchaseRepository::budget_lines( $project_id )[ $code ] ) ) {
					return new WP_Error( 'budget_line', __( 'La partida no está en el catálogo del proyecto.', 'gestion-de-proyectos' ) );
				}
				$amount = PurchaseRepository::number( $payload['assigned'] ?? null );
				if ( null === $amount || $amount < 0 ) {
					return new WP_Error( 'amount', __( 'Monto asignado no válido.', 'gestion-de-proyectos' ) );
				}
				return array( 'budget_line' => $code, 'assigned' => $amount, 'notes' => sanitize_text_field( (string) ( $payload['notes'] ?? '' ) ) );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$preview = array( 'summary' => '', 'changes' => array(), 'warnings' => array(), 'conflicts' => array() );
		$stages  = PurchaseRepository::stages( $project_id );

		switch ( $action ) {
			case 'create':
				$preview['summary'] = sprintf( 'Crear la compra "%s"', $payload['data']['title'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( null !== $value && '' !== $value ) {
						$preview['changes'][ $field ] = array( 'before' => null, 'after' => $value );
					}
				}
				if ( empty( $payload['data']['code'] ) ) {
					$preview['changes']['code'] = array( 'before' => null, 'after' => PurchaseRepository::next_code( $project_id )['number'] );
				}
				if ( empty( $payload['data']['budget_line'] ) ) {
					$preview['warnings'][] = 'La compra no tiene partida presupuestaria; asígnela antes de emitir la orden.';
				}
				break;

			case 'update':
				$current            = PurchaseRepository::find( $payload['purchase_id'] );
				$preview['summary'] = sprintf( 'Actualizar la compra %s', $current['code'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( (string) ( $current[ $field ] ?? '' ) !== (string) $value ) {
						$preview['changes'][ $field ] = array( 'before' => $current[ $field ] ?? null, 'after' => $value );
					}
				}
				if ( null !== $payload['expected_version'] && $current['version'] !== $payload['expected_version'] ) {
					$preview['conflicts'][] = sprintf( 'La compra está en la versión %d y la propuesta se basó en la versión %d.', $current['version'], $payload['expected_version'] );
				}
				if ( empty( $preview['changes'] ) ) {
					$preview['warnings'][] = 'Ningún campo cambia respecto del estado actual.';
				}
				if ( $current['committed'] && array_intersect_key( $payload['data'], array_flip( array( 'amount_net', 'currency', 'tax_rate' ) ) ) ) {
					$preview['warnings'][] = 'La compra ya tiene orden emitida: cambiar el monto altera lo comprometido en la partida.';
				}
				break;

			case 'delete':
				$current            = PurchaseRepository::find( $payload['purchase_id'] );
				$quotes             = QuoteRepository::for_purchase( $current['id'] );
				$preview['summary'] = sprintf( 'Eliminar la compra %s "%s"', $current['code'], $current['title'] );
				$preview['changes']['purchase'] = array( 'before' => $current['code'] . ' ' . $current['title'], 'after' => null );
				if ( ! empty( $quotes ) ) {
					$preview['warnings'][] = sprintf( 'Se eliminarán %d cotizaciones (recuperables al restaurar).', count( $quotes ) );
				}
				if ( $current['committed'] ) {
					$preview['warnings'][] = 'La compra tiene orden emitida; su monto dejará de contarse como comprometido.';
				}
				break;

			case 'set_stage':
				$current            = PurchaseRepository::find( $payload['purchase_id'] );
				$preview['summary'] = sprintf( 'Pasar la compra %s de %s a %s', $current['code'], $stages[ $current['stage'] ] ?? $current['stage'], $stages[ $payload['stage'] ] ?? $payload['stage'] );
				$preview['changes']['stage'] = array( 'before' => $current['stage'], 'after' => $payload['stage'] );
				$from = PurchaseRepository::stage_index( $current['stage'], $project_id );
				$to   = PurchaseRepository::stage_index( $payload['stage'], $project_id );
				if ( $to < $from ) {
					$preview['warnings'][] = 'La compra retrocede de etapa.';
				}
				if ( $to > $from + 1 ) {
					$preview['warnings'][] = sprintf( 'Se saltan %d etapas intermedias.', $to - $from - 1 );
				}
				if ( 'anulada' === $current['status'] ) {
					$preview['conflicts'][] = 'La compra está anulada.';
				}
				if ( 'orden_compra' === $payload['stage'] ) {
					$check                = ProcurementChecks::order( $current, $payload['date'] );
					$preview['warnings']  = array_merge( $preview['warnings'], $check['warnings'] );
					$preview['conflicts'] = array_merge( $preview['conflicts'], $check['conflicts'] );
					$preview['facts']     = $check['facts'];
				}
				break;

			case 'approve':
				$current            = PurchaseRepository::find( $payload['purchase_id'] );
				$preview['summary'] = sprintf( 'Aprobar la compra %s "%s"', $current['code'], $current['title'] );
				$preview['changes']['approved'] = array( 'before' => $current['approved_at'], 'after' => current_time( 'Y-m-d' ) );
				if ( $current['approved_at'] ) {
					$preview['warnings'][] = 'La compra ya estaba aprobada; se renovará la aprobación.';
				}
				if ( null === $current['amount_net'] ) {
					$preview['warnings'][] = 'La compra no tiene monto.';
				}
				break;

			case 'add_quote':
				$current            = PurchaseRepository::find( $payload['purchase_id'] );
				$supplier           = ! empty( $payload['data']['supplier_id'] ) ? SupplierRepository::find( (int) $payload['data']['supplier_id'] ) : null;
				$preview['summary'] = sprintf( 'Registrar cotización de %s en la compra %s', $supplier ? $supplier['name'] : 'proveedor sin indicar', $current['code'] );
				$preview['changes']['quote'] = array( 'before' => null, 'after' => $payload['data'] );
				if ( ! empty( $payload['items'] ) ) {
					$preview['changes']['items'] = array( 'before' => null, 'after' => count( $payload['items'] ) );
				}
				break;

			case 'update_quote':
				$quote              = QuoteRepository::find( $payload['quote_id'] );
				$preview['summary'] = sprintf( 'Actualizar la cotización %s', $quote['quote_number'] ? $quote['quote_number'] : '#' . $quote['id'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( (string) ( $quote[ $field ] ?? '' ) !== (string) $value ) {
						$preview['changes'][ $field ] = array( 'before' => $quote[ $field ] ?? null, 'after' => $value );
					}
				}
				if ( null !== $payload['items'] ) {
					$preview['changes']['items'] = array( 'before' => count( $quote['items'] ), 'after' => count( $payload['items'] ) );
				}
				break;

			case 'delete_quote':
				$quote              = QuoteRepository::find( $payload['quote_id'] );
				$preview['summary'] = sprintf( 'Eliminar la cotización %s de %s', $quote['quote_number'] ? $quote['quote_number'] : '#' . $quote['id'], $quote['supplier'] );
				$preview['changes']['quote'] = array( 'before' => $quote['quote_number'], 'after' => null );
				$purchase = PurchaseRepository::find( $quote['purchase_id'] );
				if ( $purchase && $purchase['chosen_quote_id'] === $quote['id'] ) {
					$preview['warnings'][] = 'Es la cotización elegida; la compra quedará sin cotización elegida.';
				}
				break;

			case 'choose_quote':
				$quote              = QuoteRepository::find( $payload['quote_id'] );
				$purchase           = PurchaseRepository::find( $quote['purchase_id'] );
				$preview['summary'] = sprintf( 'Elegir la cotización %s de %s para la compra %s', $quote['quote_number'] ? $quote['quote_number'] : '#' . $quote['id'], $quote['supplier'], $purchase['code'] );
				$preview['changes']['chosen_quote_id'] = array( 'before' => $purchase['chosen_quote_id'], 'after' => $quote['id'] );
				$preview['changes']['amount_net']      = array( 'before' => $purchase['amount_net'], 'after' => $quote['amount_net'] );
				$preview['changes']['currency']        = array( 'before' => $purchase['currency'], 'after' => $quote['currency'] );
				if ( null === $quote['amount_net'] ) {
					$preview['warnings'][] = 'La cotización no tiene monto.';
				}
				$others = array_filter( QuoteRepository::for_purchase( $purchase['id'] ), static fn( array $q ): bool => $q['id'] !== $quote['id'] && 'recibida' === $q['status'] && null !== $q['amount_net'] );
				foreach ( $others as $o ) {
					if ( $o['currency'] === $quote['currency'] && null !== $quote['amount_net'] && $o['amount_net'] < $quote['amount_net'] ) {
						$preview['warnings'][] = sprintf( 'La cotización de %s es más barata (%s frente a %s %s).', $o['supplier'], number_format( (float) $o['amount_net'], 2, ',', '.' ), number_format( (float) $quote['amount_net'], 2, ',', '.' ), $quote['currency'] );
					}
				}
				break;

			case 'set_quote_items':
				$quote              = QuoteRepository::find( $payload['quote_id'] );
				$preview['summary'] = sprintf( 'Fijar %d ítems en la cotización %s', count( $payload['items'] ), $quote['quote_number'] ? $quote['quote_number'] : '#' . $quote['id'] );
				$net                = 0.0;
				foreach ( $payload['items'] as $it ) {
					if ( ! isset( $it['selected'] ) || ! empty( $it['selected'] ) ) {
						$net += (float) ( $it['quantity'] ?? 1 ) * (float) ( $it['unit_price'] ?? 0 );
					}
				}
				$preview['changes']['amount_net'] = array( 'before' => $quote['amount_net'], 'after' => round( $net, 4 ) );
				break;

			case 'create_supplier':
				$preview['summary'] = sprintf( 'Crear el proveedor "%s"%s', $payload['data']['name'], 0 === (int) $payload['data']['project_id'] ? ' (global)' : '' );
				$preview['changes']['supplier'] = array( 'before' => null, 'after' => $payload['data'] );
				break;

			case 'update_supplier':
				$supplier           = SupplierRepository::find( $payload['supplier_id'] );
				$preview['summary'] = sprintf( 'Actualizar el proveedor "%s"', $supplier['name'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( (string) ( $supplier[ $field ] ?? '' ) !== (string) $value ) {
						$preview['changes'][ $field ] = array( 'before' => $supplier[ $field ] ?? null, 'after' => $value );
					}
				}
				break;

			case 'delete_supplier':
				$supplier           = SupplierRepository::find( $payload['supplier_id'] );
				$preview['summary'] = sprintf( 'Eliminar el proveedor "%s"', $supplier['name'] );
				$preview['changes']['supplier'] = array( 'before' => $supplier['name'], 'after' => null );
				break;

			case 'set_budget':
				$lines              = BudgetService::summary( $project_id )['lines'];
				$before             = null;
				foreach ( $lines as $l ) {
					if ( $l['code'] === $payload['budget_line'] ) {
						$before = $l['assigned'];
					}
				}
				$preview['summary'] = sprintf( 'Asignar %s a la partida %s', number_format( $payload['assigned'], 0, ',', '.' ), PurchaseRepository::budget_lines( $project_id )[ $payload['budget_line'] ] );
				$preview['changes']['assigned'] = array( 'before' => $before, 'after' => $payload['assigned'] );
				$summary = BudgetService::summary( $project_id );
				if ( null !== $summary['project_budget'] ) {
					$new_total = $summary['totals']['assigned'] - (float) $before + $payload['assigned'];
					if ( $new_total > $summary['project_budget'] ) {
						$preview['warnings'][] = sprintf( 'La suma de partidas (%s) supera el presupuesto del proyecto (%s).', number_format( $new_total, 0, ',', '.' ), number_format( $summary['project_budget'], 0, ',', '.' ) );
					}
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
				$id = PurchaseRepository::create( $project_id, $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'purchase_id' => $id, 'purchase' => PurchaseRepository::find( $id ) ) );

			case 'update':
				$before  = PurchaseRepository::find( $payload['purchase_id'] );
				$updated = PurchaseRepository::update( $payload['purchase_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $updated ) ? $updated : array( 'before' => $before, 'result' => array( 'purchase_id' => $updated['id'], 'purchase' => $updated ) );

			case 'delete':
				$snapshot = PurchaseRepository::snapshot( $payload['purchase_id'] );
				$ok       = PurchaseRepository::delete( $payload['purchase_id'] );
				return is_wp_error( $ok ) ? $ok : array( 'before' => $snapshot, 'result' => array( 'purchase_id' => $payload['purchase_id'] ) );

			case 'set_stage':
				$before = PurchaseRepository::find( $payload['purchase_id'] );
				$extra  = array_intersect_key( $payload, array_flip( array( 'order_number', 'invoice_number' ) ) );
				if ( ! empty( $extra ) ) {
					$r = PurchaseRepository::update( $payload['purchase_id'], $extra, null );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				$updated = PurchaseRepository::set_stage( $payload['purchase_id'], $payload['stage'], $payload['date'], $payload['note'], $payload['document_id'] );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$history = PurchaseRepository::stage_history( $updated['id'] );
				$last    = end( $history );
				return array( 'before' => $before, 'result' => array( 'purchase_id' => $updated['id'], 'stage' => $updated['stage'], 'stage_row_id' => $last ? $last['id'] : 0, 'purchase' => $updated ) );

			case 'approve':
				$before  = PurchaseRepository::find( $payload['purchase_id'] );
				$updated = PurchaseRepository::approve( $payload['purchase_id'] );
				return is_wp_error( $updated ) ? $updated : array( 'before' => $before, 'result' => array( 'purchase_id' => $updated['id'], 'approved_at' => $updated['approved_at'] ) );

			case 'add_quote':
				$purchase = PurchaseRepository::find( $payload['purchase_id'] );
				$id       = QuoteRepository::create( $purchase, $payload['data'], $payload['items'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				if ( 'solicitud_cotizacion' === $purchase['stage'] && 'recibida' === ( $payload['data']['status'] ?? '' ) ) {
					PurchaseRepository::set_stage( $purchase['id'], 'cotizacion_recibida', current_time( 'Y-m-d' ), __( 'Primera cotización recibida', 'gestion-de-proyectos' ) );
				}
				return array( 'before' => null, 'result' => array( 'quote_id' => $id, 'quote' => QuoteRepository::find( $id ) ) );

			case 'update_quote':
				$before = QuoteRepository::find( $payload['quote_id'] );
				$result = ! empty( $payload['data'] ) ? QuoteRepository::update( $payload['quote_id'], $payload['data'] ) : $before;
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( null !== $payload['items'] ) {
					$result = QuoteRepository::set_items( $payload['quote_id'], $payload['items'] );
				}
				$purchase = PurchaseRepository::find( $before['purchase_id'] );
				if ( $purchase && $purchase['chosen_quote_id'] === $before['id'] ) {
					$this->sync_purchase_with_quote( $purchase, QuoteRepository::find( $before['id'] ) );
				}
				return array( 'before' => $before, 'result' => array( 'quote_id' => $payload['quote_id'], 'quote' => QuoteRepository::find( $payload['quote_id'] ) ) );

			case 'delete_quote':
				$snapshot = QuoteRepository::snapshot_for_purchase( QuoteRepository::find( $payload['quote_id'] )['purchase_id'] );
				$row      = null;
				foreach ( $snapshot as $q ) {
					if ( $q['id'] === $payload['quote_id'] ) {
						$row = $q;
					}
				}
				$purchase = $row ? PurchaseRepository::find( $row['purchase_id'] ) : null;
				$ok       = QuoteRepository::delete( $payload['quote_id'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				if ( $purchase && $purchase['chosen_quote_id'] === $payload['quote_id'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( Schema::table( 'purchases' ), array( 'chosen_quote_id' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $purchase['id'] ) );
				}
				return array( 'before' => array( 'quote' => $row, 'chosen_before' => $purchase ? $purchase['chosen_quote_id'] : 0 ), 'result' => array( 'quote_id' => $payload['quote_id'] ) );

			case 'choose_quote':
				$quote    = QuoteRepository::find( $payload['quote_id'] );
				$purchase = PurchaseRepository::find( $quote['purchase_id'] );
				$before   = array( 'purchase' => $purchase, 'quotes' => array_map( static fn( array $q ): array => array( 'id' => $q['id'], 'status' => $q['status'] ), QuoteRepository::for_purchase( $purchase['id'] ) ) );
				foreach ( QuoteRepository::for_purchase( $purchase['id'] ) as $q ) {
					if ( $q['id'] === $quote['id'] ) {
						QuoteRepository::update( $q['id'], array( 'status' => 'elegida' ) );
					} elseif ( 'elegida' === $q['status'] ) {
						QuoteRepository::update( $q['id'], array( 'status' => 'recibida' ) );
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'purchases' ), array( 'chosen_quote_id' => $quote['id'] ), array( 'id' => $purchase['id'] ) );
				$this->sync_purchase_with_quote( PurchaseRepository::find( $purchase['id'] ), QuoteRepository::find( $quote['id'] ) );
				if ( PurchaseRepository::stage_index( $purchase['stage'], $project_id ) < PurchaseRepository::stage_index( 'eleccion', $project_id ) ) {
					/* translators: proveedor. */
					PurchaseRepository::set_stage( $purchase['id'], 'eleccion', current_time( 'Y-m-d' ), sprintf( __( 'Elegida la cotización de %s', 'gestion-de-proyectos' ), $quote['supplier'] ) );
				}
				return array( 'before' => $before, 'result' => array( 'purchase_id' => $purchase['id'], 'quote_id' => $quote['id'], 'purchase' => PurchaseRepository::find( $purchase['id'] ) ) );

			case 'set_quote_items':
				$before = QuoteRepository::find( $payload['quote_id'] );
				$result = QuoteRepository::set_items( $payload['quote_id'], $payload['items'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$purchase = PurchaseRepository::find( $before['purchase_id'] );
				if ( $purchase && $purchase['chosen_quote_id'] === $before['id'] ) {
					$this->sync_purchase_with_quote( $purchase, $result );
				}
				return array( 'before' => $before, 'result' => array( 'quote_id' => $payload['quote_id'], 'amount_net' => $result['amount_net'] ) );

			case 'create_supplier':
				$id = SupplierRepository::create( $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'supplier_id' => $id, 'supplier' => SupplierRepository::find( $id ) ) );

			case 'update_supplier':
				$before  = SupplierRepository::find( $payload['supplier_id'] );
				$updated = SupplierRepository::update( $payload['supplier_id'], $payload['data'] );
				return is_wp_error( $updated ) ? $updated : array( 'before' => $before, 'result' => array( 'supplier_id' => $updated['id'], 'supplier' => $updated ) );

			case 'delete_supplier':
				$before = SupplierRepository::find( $payload['supplier_id'] );
				$ok     = SupplierRepository::delete( $payload['supplier_id'] );
				return is_wp_error( $ok ) ? $ok : array( 'before' => $before, 'result' => array( 'supplier_id' => $payload['supplier_id'] ) );

			case 'set_budget':
				$before = BudgetService::assigned( $project_id )[ $payload['budget_line'] ] ?? null;
				$ok     = BudgetService::set_assigned( $project_id, $payload['budget_line'], $payload['assigned'], $payload['notes'] );
				return is_wp_error( $ok ) ? $ok : array( 'before' => $before ? array( 'budget_line' => $payload['budget_line'], 'assigned' => $before['assigned_clp'], 'notes' => $before['notes'] ) : null, 'result' => array( 'budget_line' => $payload['budget_line'], 'assigned' => $payload['assigned'] ) );
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
				return PurchaseRepository::delete( (int) ( $result['purchase_id'] ?? 0 ) );

			case 'update':
			case 'approve':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$row = array_intersect_key( $before, array_flip( PurchaseRepository::COLUMNS ) );
				unset( $row['id'], $row['project_id'], $row['version'], $row['created_by'], $row['created_at'] );
				$row['updated_at'] = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'purchases' ), $row, array( 'id' => (int) $before['id'] ) );
				return true;

			case 'set_stage':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$row = array_intersect_key( $before, array_flip( array( 'stage', 'order_date', 'invoice_date', 'paid_at', 'amount_total', 'amount_clp', 'uf_rate', 'uf_date', 'order_number', 'invoice_number' ) ) );
				$row['updated_at'] = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'purchases' ), $row, array( 'id' => (int) $before['id'] ) );
				if ( ! empty( $result['stage_row_id'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( Schema::table( 'purchase_stages' ), array( 'id' => (int) $result['stage_row_id'] ) );
				}
				return true;

			case 'delete':
				return $before ? PurchaseRepository::restore( $before ) : new WP_Error( 'nothing_to_revert', __( 'No hay instantánea de la compra.', 'gestion-de-proyectos' ) );

			case 'add_quote':
				return QuoteRepository::delete( (int) ( $result['quote_id'] ?? 0 ) );

			case 'update_quote':
			case 'set_quote_items':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				QuoteRepository::update( (int) $before['id'], array_intersect_key( $before, array_flip( array( 'supplier_id', 'quote_number', 'status', 'requested_at', 'quote_date', 'valid_until', 'currency', 'tax_rate', 'document_id', 'notes' ) ) ) );
				QuoteRepository::set_items( (int) $before['id'], (array) $before['items'] );
				if ( empty( $before['items'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( Schema::table( 'quotes' ), array( 'amount_net' => $before['amount_net'], 'amount_total' => $before['amount_total'] ), array( 'id' => (int) $before['id'] ) );
				}
				return true;

			case 'delete_quote':
				if ( empty( $before['quote'] ) ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay instantánea de la cotización.', 'gestion-de-proyectos' ) );
				}
				QuoteRepository::restore( array( $before['quote'] ) );
				if ( ! empty( $before['chosen_before'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( Schema::table( 'purchases' ), array( 'chosen_quote_id' => (int) $before['chosen_before'] ), array( 'id' => (int) $before['quote']['purchase_id'] ) );
				}
				return true;

			case 'choose_quote':
				if ( empty( $before['purchase'] ) ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$row = array_intersect_key( $before['purchase'], array_flip( array( 'chosen_quote_id', 'supplier_id', 'currency', 'amount_net', 'tax_rate', 'amount_total', 'amount_clp', 'uf_rate', 'uf_date', 'stage' ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( 'purchases' ), $row, array( 'id' => (int) $before['purchase']['id'] ) );
				foreach ( (array) ( $before['quotes'] ?? array() ) as $q ) {
					QuoteRepository::update( (int) $q['id'], array( 'status' => (string) $q['status'] ) );
				}
				return true;

			case 'create_supplier':
				return SupplierRepository::delete( (int) ( $result['supplier_id'] ?? 0 ) );

			case 'update_supplier':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
				}
				$r = SupplierRepository::update( (int) $before['id'], array_intersect_key( $before, array_flip( array( 'name', 'tax_id', 'contact_name', 'email', 'phone', 'address', 'category', 'notes', 'active' ) ) ) );
				return is_wp_error( $r ) ? $r : true;

			case 'delete_supplier':
				return $before ? SupplierRepository::restore( $before ) : new WP_Error( 'nothing_to_revert', __( 'No hay instantánea del proveedor.', 'gestion-de-proyectos' ) );

			case 'set_budget':
				if ( $before ) {
					BudgetService::set_assigned( $project_id, (string) $before['budget_line'], (float) $before['assigned'], (string) $before['notes'] );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( Schema::table( 'budget_lines' ), array( 'project_id' => $project_id, 'line_code' => (string) ( $result['budget_line'] ?? '' ) ) );
				}
				return true;
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Copia moneda, monto e impuesto de la cotización elegida a la compra y recalcula pesos.
	 *
	 * @param array<string,mixed>      $purchase Compra.
	 * @param array<string,mixed>|null $quote    Cotización.
	 * @return void
	 */
	private function sync_purchase_with_quote( array $purchase, ?array $quote ): void {
		if ( ! $quote ) {
			return;
		}
		PurchaseRepository::update(
			(int) $purchase['id'],
			array(
				'supplier_id' => $quote['supplier_id'] > 0 ? $quote['supplier_id'] : $purchase['supplier_id'],
				'currency'    => $quote['currency'],
				'amount_net'  => $quote['amount_net'],
				'tax_rate'    => $quote['tax_rate'],
			),
			null
		);
	}

	/**
	 * Ítems normalizados del payload.
	 *
	 * @param array $payload Datos.
	 * @return array<int,array<string,mixed>>
	 */
	private function items( array $payload ): array {
		$out = array();
		foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$description = sanitize_text_field( (string) ( $item['description'] ?? '' ) );
			if ( '' === $description ) {
				continue;
			}
			$out[] = array(
				'description' => $description,
				'quantity'    => PurchaseRepository::number( $item['quantity'] ?? 1 ) ?? 1.0,
				'unit'        => sanitize_text_field( (string) ( $item['unit'] ?? '' ) ),
				'unit_price'  => PurchaseRepository::number( $item['unit_price'] ?? 0 ) ?? 0.0,
				'selected'    => ! isset( $item['selected'] ) || ! empty( $item['selected'] ),
			);
		}

		return $out;
	}

	/**
	 * Compra del payload dentro del proyecto.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	private function purchase( array $payload, int $project_id ) {
		$purchase = PurchaseRepository::find( (int) ( $payload['purchase_id'] ?? 0 ) );
		if ( ! $purchase || $purchase['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'La compra no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}

		return $purchase;
	}

	/**
	 * Cotización del payload dentro del proyecto.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	private function quote( array $payload, int $project_id ) {
		$quote = QuoteRepository::find( (int) ( $payload['quote_id'] ?? 0 ) );
		if ( ! $quote || $quote['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'La cotización no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}

		return $quote;
	}
}
