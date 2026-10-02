<?php
/**
 * Manejador de operaciones del módulo de finanzas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Core\Access;
use GDP\Core\Schema;
use GDP\Modules\Finance\Logic\PaymentValidator;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Escrituras en dos tiempos sobre convenio, cuotas, ítems, reglas, pagos,
 * rendiciones, garantías, programación de caja, movimientos del centro de
 * costo, modificaciones, pasos de las hojas de ejecución y estados
 * declarados. Al declarar una rendición como enviada, los hallazgos que
 * bloquean en sus pagos son conflictos que impiden confirmar.
 */
final class FinanceHandler implements HandlerInterface {

	private const RULE_ACTIONS      = array( 'save_agreement', 'create_installment', 'update_installment', 'delete_installment', 'save_item', 'delete_item', 'seed_items', 'set_rule', 'reset_rule', 'create_modification', 'update_modification', 'delete_modification' );
	private const RECONCILE_ACTIONS = array( 'import_ledger', 'update_ledger', 'delete_ledger', 'delete_ledger_batch' );

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'finance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array_merge(
			self::RULE_ACTIONS,
			self::RECONCILE_ACTIONS,
			array( 'create_payment', 'update_payment', 'delete_payment', 'set_payment_status', 'create_rendition', 'update_rendition', 'delete_rendition', 'declare_rendition', 'collect_payments', 'create_guarantee', 'update_guarantee', 'delete_guarantee', 'save_cash_plan', 'delete_cash_plan', 'mark_step', 'undo_step', 'set_supplier_registered', 'installment_event' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( $project_id <= 0 ) {
			return false;
		}
		if ( in_array( $action, self::RULE_ACTIONS, true ) ) {
			return Access::can( 'finance.rules', $project_id, $user_id );
		}
		if ( in_array( $action, self::RECONCILE_ACTIONS, true ) ) {
			return Access::can( 'finance.reconcile', $project_id, $user_id );
		}

		return Access::can( 'finance.edit', $project_id, $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$ev   = isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null;
		switch ( $action ) {
			case 'save_agreement':
				$clean = AgreementRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'data' => $clean, 'expected_version' => $ev );

			case 'create_installment':
				$clean = InstallmentRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'data' => $clean );

			case 'update_installment':
			case 'delete_installment':
				$row = $this->owned( InstallmentRepository::class, (int) ( $payload['installment_id'] ?? 0 ), $project_id, __( 'La cuota no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				if ( 'delete_installment' === $action ) {
					return array( 'installment_id' => $row['id'] );
				}
				$clean = InstallmentRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'installment_id' => $row['id'], 'data' => $clean, 'expected_version' => $ev );

			case 'save_item':
				$clean = ItemRepository::validate( $data );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['slug'] ) ) {
					return new WP_Error( 'slug', __( 'Indique el identificador del ítem.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'delete_item':
				$row = $this->owned( ItemRepository::class, (int) ( $payload['item_id'] ?? 0 ), $project_id, __( 'El ítem no existe en este proyecto.', 'gestion-de-proyectos' ) );
				return is_wp_error( $row ) ? $row : array( 'item_id' => $row['id'] );

			case 'seed_items':
				return array();

			case 'set_rule':
				if ( empty( $data['rule_key'] ) ) {
					return new WP_Error( 'rule_key', __( 'Indique la clave de la regla.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $data );

			case 'reset_rule':
				$key = sanitize_key( (string) ( $payload['rule_key'] ?? '' ) );
				return '' === $key ? new WP_Error( 'rule_key', __( 'Indique la clave de la regla.', 'gestion-de-proyectos' ) ) : array( 'rule_key' => $key );

			case 'create_payment':
				$clean = PaymentRepository::validate( array_merge( array( 'source' => 'fondo', 'status' => 'comprometido', 'doc_type' => 'factura', 'support' => array() ), $data ), $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['description'] ) ) {
					return new WP_Error( 'required', __( 'Indique la descripción del pago.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'update_payment':
			case 'delete_payment':
				$row = $this->owned( PaymentRepository::class, (int) ( $payload['payment_id'] ?? 0 ), $project_id, __( 'El pago no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				if ( 'delete_payment' === $action ) {
					return array( 'payment_id' => $row['id'] );
				}
				$clean = PaymentRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				return array( 'payment_id' => $row['id'], 'data' => $clean, 'expected_version' => $ev );

			case 'set_payment_status':
				$row = $this->owned( PaymentRepository::class, (int) ( $payload['payment_id'] ?? 0 ), $project_id, __( 'El pago no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
				if ( ! in_array( $status, PaymentRepository::STATUSES, true ) ) {
					return new WP_Error( 'status', __( 'Estado del pago no válido.', 'gestion-de-proyectos' ) );
				}
				return array( 'payment_id' => $row['id'], 'status' => $status ) + $this->event_fields( $payload );

			case 'create_rendition':
				$clean = RenditionRepository::validate( array_merge( array( 'source' => 'fondo', 'kind' => 'mensual' ), $data ) );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['period'] ) ) {
					return new WP_Error( 'period', __( 'Indique el período (AAAA-MM).', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'update_rendition':
			case 'delete_rendition':
			case 'collect_payments':
				$row = $this->owned( RenditionRepository::class, (int) ( $payload['rendition_id'] ?? 0 ), $project_id, __( 'La rendición no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				if ( 'update_rendition' !== $action ) {
					return array( 'rendition_id' => $row['id'] );
				}
				$clean = RenditionRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'rendition_id' => $row['id'], 'data' => $clean, 'expected_version' => $ev );

			case 'declare_rendition':
				$row = $this->owned( RenditionRepository::class, (int) ( $payload['rendition_id'] ?? 0 ), $project_id, __( 'La rendición no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
				if ( ! in_array( $status, RenditionRepository::statuses(), true ) ) {
					return new WP_Error( 'status', __( 'Estado de rendición no válido.', 'gestion-de-proyectos' ) );
				}
				return array( 'rendition_id' => $row['id'], 'status' => $status ) + $this->event_fields( $payload );

			case 'create_guarantee':
				$clean = GuaranteeRepository::validate( array_merge( array( 'kind' => 'fiel_cumplimiento', 'instrument' => 'boleta_garantia', 'status' => 'vigente' ), $data ) );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['amount'] ) ) {
					return new WP_Error( 'amount', __( 'Indique el monto de la garantía.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'update_guarantee':
			case 'delete_guarantee':
				$row = $this->owned( GuaranteeRepository::class, (int) ( $payload['guarantee_id'] ?? 0 ), $project_id, __( 'La garantía no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				if ( 'delete_guarantee' === $action ) {
					return array( 'guarantee_id' => $row['id'] );
				}
				$clean = GuaranteeRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'guarantee_id' => $row['id'], 'data' => $clean, 'expected_version' => $ev );

			case 'save_cash_plan':
				$plan_id = (int) ( $payload['plan_id'] ?? 0 );
				if ( $plan_id > 0 ) {
					$row = $this->owned( CashPlanRepository::class, $plan_id, $project_id, __( 'La programación no existe en este proyecto.', 'gestion-de-proyectos' ) );
					if ( is_wp_error( $row ) ) {
						return $row;
					}
				}
				$rows = isset( $payload['rows'] ) && is_array( $payload['rows'] ) ? $payload['rows'] : null;
				if ( 0 === $plan_id && empty( $rows ) ) {
					return new WP_Error( 'rows', __( 'Indique las filas de la programación (período, transferencia, gasto, aporte).', 'gestion-de-proyectos' ) );
				}
				return array( 'plan_id' => $plan_id, 'data' => $data, 'rows' => $rows, 'expected_version' => $ev );

			case 'delete_cash_plan':
				$row = $this->owned( CashPlanRepository::class, (int) ( $payload['plan_id'] ?? 0 ), $project_id, __( 'La programación no existe en este proyecto.', 'gestion-de-proyectos' ) );
				return is_wp_error( $row ) ? $row : array( 'plan_id' => $row['id'] );

			case 'import_ledger':
				$rows = isset( $payload['rows'] ) && is_array( $payload['rows'] ) ? $payload['rows'] : array();
				if ( empty( $rows ) ) {
					return new WP_Error( 'rows', __( 'Indique los movimientos (fecha, referencia, glosa, cargo, abono).', 'gestion-de-proyectos' ) );
				}
				$source = sanitize_key( (string) ( $payload['source'] ?? 'fondo' ) );
				if ( ! in_array( $source, PaymentRepository::SOURCES, true ) ) {
					return new WP_Error( 'source', __( 'La fuente debe ser fondo o pecuniario.', 'gestion-de-proyectos' ) );
				}
				return array( 'source' => $source, 'rows' => array_values( $rows ), 'batch' => sanitize_text_field( (string) ( $payload['batch'] ?? gmdate( 'Ymd-His' ) ) ) );

			case 'update_ledger':
			case 'delete_ledger':
				$row = $this->owned( LedgerRepository::class, (int) ( $payload['entry_id'] ?? 0 ), $project_id, __( 'El movimiento no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				if ( 'delete_ledger' === $action ) {
					return array( 'entry_id' => $row['id'] );
				}
				$clean = LedgerRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'entry_id' => $row['id'], 'data' => $clean );

			case 'delete_ledger_batch':
				$batch = sanitize_text_field( (string) ( $payload['batch'] ?? '' ) );
				return '' === $batch ? new WP_Error( 'batch', __( 'Indique el lote.', 'gestion-de-proyectos' ) ) : array( 'batch' => $batch );

			case 'create_modification':
				$clean = ModificationRepository::validate( array_merge( array( 'kind' => 'reitemizacion', 'status' => 'solicitada', 'details' => array() ), $data ) );
				return is_wp_error( $clean ) ? $clean : array( 'data' => $clean );

			case 'update_modification':
			case 'delete_modification':
				$row = $this->owned( ModificationRepository::class, (int) ( $payload['modification_id'] ?? 0 ), $project_id, __( 'La modificación no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				if ( 'delete_modification' === $action ) {
					return array( 'modification_id' => $row['id'] );
				}
				$clean = ModificationRepository::validate( $data );
				return is_wp_error( $clean ) ? $clean : array( 'modification_id' => $row['id'], 'data' => $clean, 'expected_version' => $ev );

			case 'mark_step':
			case 'undo_step':
				$entity_type = sanitize_key( (string) ( $payload['entity_type'] ?? '' ) );
				$guide       = sanitize_key( (string) ( $payload['guide'] ?? '' ) );
				$step        = sanitize_key( (string) ( $payload['step'] ?? '' ) );
				if ( ! in_array( $entity_type, array( 'project', 'rendition', 'installment', 'payment', 'supplier', 'guarantee' ), true ) || '' === $guide || '' === $step ) {
					return new WP_Error( 'invalid_payload', __( 'Indique entidad, guía y paso.', 'gestion-de-proyectos' ) );
				}
				$profile = Profiles::get( (string) AgreementRepository::get( $project_id )['profile'] );
				$guides  = $profile->guides();
				if ( ! isset( $guides[ $guide ] ) ) {
					return new WP_Error( 'guide', __( 'La guía no existe en el perfil.', 'gestion-de-proyectos' ) );
				}
				$found = false;
				foreach ( $guides[ $guide ]['steps'] as $s ) {
					if ( $s['key'] === $step ) {
						$found = true;
					}
				}
				if ( ! $found ) {
					return new WP_Error( 'step', __( 'El paso no existe en la guía.', 'gestion-de-proyectos' ) );
				}
				return array( 'entity_type' => $entity_type, 'entity_id' => (int) ( $payload['entity_id'] ?? 0 ), 'guide' => $guide, 'step' => $step ) + $this->event_fields( $payload );

			case 'set_supplier_registered':
				$supplier = \GDP\Modules\Procurement\SupplierRepository::find( (int) ( $payload['supplier_id'] ?? 0 ) );
				if ( ! $supplier || ( $supplier['project_id'] > 0 && $supplier['project_id'] !== $project_id ) ) {
					return new WP_Error( 'not_found', __( 'El proveedor no existe o pertenece a otro proyecto.', 'gestion-de-proyectos' ) );
				}
				return array( 'supplier_id' => $supplier['id'], 'registered' => ! empty( $payload['registered'] ) );

			case 'installment_event':
				$row = $this->owned( InstallmentRepository::class, (int) ( $payload['installment_id'] ?? 0 ), $project_id, __( 'La cuota no existe en este proyecto.', 'gestion-de-proyectos' ) );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				$key = sanitize_key( (string) ( $payload['event_key'] ?? '' ) );
				if ( ! in_array( $key, array( 'informe_aprobado', 'carta_solicitud', 'transferencia_aceptada', 'comprobante_enviado', 'aporte_acreditado', 'otro' ), true ) ) {
					return new WP_Error( 'event_key', __( 'Evento no válido (informe_aprobado, carta_solicitud, transferencia_aceptada, comprobante_enviado, aporte_acreditado, otro).', 'gestion-de-proyectos' ) );
				}
				return array( 'installment_id' => $row['id'], 'event_key' => $key ) + $this->event_fields( $payload );
		}

		return new WP_Error( 'unknown_action', __( 'Acción desconocida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$out = array( 'summary' => '', 'changes' => array(), 'warnings' => array(), 'conflicts' => array() );
		switch ( $action ) {
			case 'save_agreement':
				$current        = AgreementRepository::get( $project_id );
				$out['summary'] = __( 'Guardar el convenio del proyecto', 'gestion-de-proyectos' );
				$out['changes'] = $this->diff( $current, $payload['data'] );
				break;
			case 'create_installment':
				$out['summary'] = sprintf( 'Registrar la cuota %s por %s', (string) ( $payload['data']['number'] ?? 'siguiente' ), $this->money( (float) ( $payload['data']['amount'] ?? 0 ) ) );
				break;
			case 'update_installment':
				$current        = InstallmentRepository::find( $payload['installment_id'] );
				$out['summary'] = sprintf( 'Actualizar la cuota %d', $current['number'] ?? 0 );
				$out['changes'] = $this->diff( $current ?? array(), $payload['data'] );
				break;
			case 'delete_installment':
				$out['summary'] = __( 'Eliminar una cuota', 'gestion-de-proyectos' );
				break;
			case 'save_item':
				$current        = ItemRepository::by_slug( $project_id )[ $payload['data']['slug'] ] ?? array();
				$out['summary'] = sprintf( 'Guardar el ítem %s', $payload['data']['slug'] );
				$out['changes'] = $this->diff( $current, $payload['data'] );
				break;
			case 'delete_item':
				$out['summary'] = __( 'Eliminar un ítem', 'gestion-de-proyectos' );
				break;
			case 'seed_items':
				$out['summary'] = __( 'Crear los ítems del perfil que falten', 'gestion-de-proyectos' );
				break;
			case 'set_rule':
				$out['summary'] = sprintf( 'Fijar la regla %s en "%s"', $payload['data']['rule_key'], (string) ( $payload['data']['value'] ?? '' ) );
				break;
			case 'reset_rule':
				$out['summary'] = sprintf( 'Devolver la regla %s al valor del perfil', $payload['rule_key'] );
				break;
			case 'create_payment':
				$out['summary'] = sprintf( 'Registrar el pago "%s" por %s', $payload['data']['description'], $this->money( (float) ( $payload['data']['amount'] ?? 0 ) ) );
				$issues         = FinanceService::validate_payment( $payload['data'] + array( 'project_id' => $project_id, 'supplier_id' => 0, 'item_slug' => '', 'doc_type' => 'factura', 'support' => array() ), null );
				$out['warnings'] = array_map( static fn( array $i ): string => $i['code'] . ' (' . $i['severity'] . '): ' . $i['message'], $issues );
				break;
			case 'update_payment':
				$current        = PaymentRepository::find( $payload['payment_id'] );
				$out['summary'] = sprintf( 'Actualizar el pago %s', $current['code'] ?? '' );
				$out['changes'] = $this->diff( $current ?? array(), $payload['data'] );
				if ( $current && in_array( $current['status'], PaymentRepository::RENDERED, true ) && isset( $payload['data']['amount'] ) && (float) $payload['data']['amount'] > (float) $current['amount'] ) {
					$out['conflicts'][] = __( 'El pago ya está rendido: la plataforma solo admite corregir el monto a un valor menor o igual.', 'gestion-de-proyectos' );
				}
				if ( $current ) {
					$issues          = FinanceService::validate_payment( array_merge( $current, $payload['data'] ), null );
					$out['warnings'] = array_map( static fn( array $i ): string => $i['code'] . ' (' . $i['severity'] . '): ' . $i['message'], $issues );
				}
				break;
			case 'delete_payment':
				$current        = PaymentRepository::find( $payload['payment_id'] );
				$out['summary'] = sprintf( 'Eliminar el pago %s', $current['code'] ?? '' );
				if ( $current && in_array( $current['status'], PaymentRepository::RENDERED, true ) ) {
					$out['conflicts'][] = __( 'Un pago rendido no se elimina.', 'gestion-de-proyectos' );
				}
				break;
			case 'set_payment_status':
				$current        = PaymentRepository::find( $payload['payment_id'] );
				$out['summary'] = sprintf( 'Pago %s: %s (%s)', $current['code'] ?? '', $payload['status'], $payload['date'] );
				$out['changes'] = array( 'status' => array( $current['status'] ?? '', $payload['status'] ) );
				break;
			case 'create_rendition':
				$out['summary'] = sprintf( 'Crear la rendición %s (%s, %s)', $payload['data']['period'], $payload['data']['kind'], $payload['data']['source'] );
				break;
			case 'update_rendition':
				$current        = RenditionRepository::find( $payload['rendition_id'] );
				$out['summary'] = sprintf( 'Actualizar la rendición %s', $current['period'] ?? '' );
				$out['changes'] = $this->diff( $current ?? array(), $payload['data'] );
				break;
			case 'delete_rendition':
				$current        = RenditionRepository::find( $payload['rendition_id'] );
				$out['summary'] = sprintf( 'Eliminar la rendición %s', $current['period'] ?? '' );
				if ( $current && in_array( $current['status'], RenditionRepository::SUBMITTED, true ) ) {
					$out['conflicts'][] = __( 'Una rendición presentada no se elimina.', 'gestion-de-proyectos' );
				}
				break;
			case 'collect_payments':
				$current        = RenditionRepository::find( $payload['rendition_id'] );
				$out['summary'] = sprintf( 'Asignar a la rendición %s los pagos del período sin rendición', $current['period'] ?? '' );
				break;
			case 'declare_rendition':
				$current        = RenditionRepository::find( $payload['rendition_id'] );
				$out['summary'] = sprintf( 'Rendición %s: declarar %s (%s)', $current['period'] ?? '', $payload['status'], $payload['date'] );
				$out['changes'] = array( 'status' => array( $current['status'] ?? '', $payload['status'] ) );
				$cascade = $current ? RenditionRepository::cascade_targets( (int) $current['id'], $payload['status'] ) : array();
				if ( $cascade ) {
					$counts = array_count_values( $cascade );
					foreach ( $counts as $target => $n ) {
						/* translators: 1: cantidad de pagos, 2: estado. */
						$out['summary'] .= sprintf( _n( '; %1$d pago pasa a %2$s', '; %1$d pagos pasan a %2$s', $n, 'gestion-de-proyectos' ), $n, $target );
					}
				}
				if ( $current && in_array( $payload['status'], array( 'enviada_universidad', 'en_carga', 'rendida' ), true ) ) {
					$payments = PaymentRepository::list( $project_id, array( 'rendition_id' => $current['id'] ) );
					if ( 'mensual' === $current['kind'] && empty( $payments ) ) {
						$out['conflicts'][] = __( 'La rendición mensual no tiene pagos asignados; asígnelos o cámbiela a sin movimiento.', 'gestion-de-proyectos' );
					}
					foreach ( $payments as $p ) {
						$issues = FinanceService::validate_payment( $p, null );
						foreach ( $issues as $i ) {
							if ( PaymentValidator::BLOCK === $i['severity'] ) {
								$out['conflicts'][] = sprintf( '%s: %s', $p['code'], $i['message'] );
							} elseif ( PaymentValidator::MISS === $i['severity'] ) {
								$out['warnings'][] = sprintf( '%s: %s', $p['code'], $i['message'] );
							}
						}
					}
				}
				break;
			case 'create_guarantee':
				$out['summary'] = sprintf( 'Registrar una garantía (%s) por %s', $payload['data']['kind'], $this->money( (float) $payload['data']['amount'] ) );
				break;
			case 'update_guarantee':
				$out['summary'] = __( 'Actualizar una garantía', 'gestion-de-proyectos' );
				$out['changes'] = $this->diff( GuaranteeRepository::find( $payload['guarantee_id'] ) ?? array(), $payload['data'] );
				break;
			case 'delete_guarantee':
				$out['summary'] = __( 'Eliminar una garantía', 'gestion-de-proyectos' );
				break;
			case 'save_cash_plan':
				$out['summary'] = $payload['plan_id'] > 0 ? __( 'Actualizar la programación de caja', 'gestion-de-proyectos' ) : sprintf( 'Registrar una programación de caja con %d meses', count( (array) $payload['rows'] ) );
				if ( ! empty( $payload['rows'] ) ) {
					$status = FinanceService::status( $project_id );
					$rows   = array();
					foreach ( $payload['rows'] as $r ) {
						$rows[] = array( 'period' => (string) ( $r['period'] ?? '' ), 'transfer' => (float) ( Repository::number( $r['transfer'] ?? 0 ) ?? 0 ), 'spend' => (float) ( Repository::number( $r['spend'] ?? 0 ) ?? 0 ), 'cash' => (float) ( Repository::number( $r['cash'] ?? 0 ) ?? 0 ) );
					}
					$rule = static fn( string $key, string $fallback = '' ): string => (string) ( $status['rules'][ $key ]['value'] ?? $fallback );
					foreach ( FinanceService::plan_checks( $project_id, $rows, $status['agreement'], Calendar::for_project( $project_id ), $rule ) as $c ) {
						if ( false === $c['ok'] ) {
							$out['warnings'][] = $c['key'] . ': ' . $c['detail'];
						}
					}
				}
				break;
			case 'delete_cash_plan':
				$out['summary'] = __( 'Eliminar una programación de caja', 'gestion-de-proyectos' );
				break;
			case 'import_ledger':
				$out['summary'] = sprintf( 'Importar %d movimientos del centro de costo (%s)', count( $payload['rows'] ), $payload['source'] );
				break;
			case 'update_ledger':
				$out['summary'] = __( 'Actualizar un movimiento', 'gestion-de-proyectos' );
				$out['changes'] = $this->diff( LedgerRepository::find( $payload['entry_id'] ) ?? array(), $payload['data'] );
				break;
			case 'delete_ledger':
				$out['summary'] = __( 'Eliminar un movimiento', 'gestion-de-proyectos' );
				break;
			case 'delete_ledger_batch':
				$out['summary'] = sprintf( 'Eliminar el lote de movimientos %s', $payload['batch'] );
				break;
			case 'create_modification':
				$out['summary'] = sprintf( 'Registrar una modificación (%s, %s)', $payload['data']['kind'], $payload['data']['status'] );
				if ( 'reitemizacion' === $payload['data']['kind'] ) {
					$status = FinanceService::status( $project_id );
					if ( $status['reitemizations']['used'] >= $status['reitemizations']['max'] ) {
						$out['warnings'][] = sprintf( /* translators: 1: reitemizaciones usadas, 2: máximo permitido. */ __( 'El proyecto ya usó %1$d de %2$d reitemizaciones.', 'gestion-de-proyectos' ), $status['reitemizations']['used'], $status['reitemizations']['max'] );
					}
				}
				break;
			case 'update_modification':
				$out['summary'] = __( 'Actualizar una modificación', 'gestion-de-proyectos' );
				$out['changes'] = $this->diff( ModificationRepository::find( $payload['modification_id'] ) ?? array(), $payload['data'] );
				break;
			case 'delete_modification':
				$out['summary'] = __( 'Eliminar una modificación', 'gestion-de-proyectos' );
				break;
			case 'mark_step':
				$out['summary'] = sprintf( 'Marcar el paso %s de la guía %s como hecho', $payload['step'], $payload['guide'] );
				break;
			case 'undo_step':
				$out['summary'] = sprintf( 'Dejar pendiente el paso %s de la guía %s', $payload['step'], $payload['guide'] );
				break;
			case 'set_supplier_registered':
				$out['summary'] = $payload['registered'] ? __( 'Marcar el proveedor como registrado en la plataforma', 'gestion-de-proyectos' ) : __( 'Marcar el proveedor como no registrado en la plataforma', 'gestion-de-proyectos' );
				break;
			case 'installment_event':
				$current        = InstallmentRepository::find( $payload['installment_id'] );
				$out['summary'] = sprintf( 'Cuota %d: registrar %s (%s)', $current['number'] ?? 0, $payload['event_key'], $payload['date'] );
				break;
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'save_agreement':
				$before = AgreementRepository::for_project_single( $project_id );
				$result = AgreementRepository::save( $project_id, $payload['data'], $payload['expected_version'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'agreement_id' => $result['id'] ) );

			case 'create_installment':
				$id = InstallmentRepository::create( $project_id, $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'installment_id' => $id ) );

			case 'update_installment':
				$before = InstallmentRepository::find( $payload['installment_id'] );
				$result = InstallmentRepository::update( $payload['installment_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'installment_id' => $payload['installment_id'] ) );

			case 'delete_installment':
				$before = InstallmentRepository::find( $payload['installment_id'] );
				InstallmentRepository::delete( $payload['installment_id'] );
				return array( 'before' => $before, 'result' => array( 'installment_id' => $payload['installment_id'], 'deleted' => true ) );

			case 'save_item':
				$before = ItemRepository::by_slug( $project_id )[ $payload['data']['slug'] ] ?? null;
				$result = ItemRepository::save( $project_id, $payload['data'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'item_id' => $result['id'], 'slug' => $result['slug'] ) );

			case 'delete_item':
				$before = ItemRepository::find( $payload['item_id'] );
				ItemRepository::delete( $payload['item_id'] );
				return array( 'before' => $before, 'result' => array( 'item_id' => $payload['item_id'], 'deleted' => true ) );

			case 'seed_items':
				$created = ItemRepository::seed( $project_id, (string) AgreementRepository::get( $project_id )['profile'] );
				return array( 'before' => null, 'result' => array( 'created' => $created ) );

			case 'set_rule':
				$before = null;
				foreach ( RulesRepository::for_project( $project_id ) as $r ) {
					if ( $r['rule_key'] === sanitize_key( (string) $payload['data']['rule_key'] ) ) {
						$before = $r;
					}
				}
				$id = RulesRepository::set( $project_id, $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => $before, 'result' => array( 'rule_id' => $id, 'rule_key' => sanitize_key( (string) $payload['data']['rule_key'] ) ) );

			case 'reset_rule':
				$before = null;
				foreach ( RulesRepository::for_project( $project_id ) as $r ) {
					if ( $r['rule_key'] === $payload['rule_key'] ) {
						$before = $r;
					}
				}
				RulesRepository::reset( $project_id, $payload['rule_key'] );
				return array( 'before' => $before, 'result' => array( 'rule_key' => $payload['rule_key'], 'reset' => true ) );

			case 'create_payment':
				$id = PaymentRepository::create( $project_id, $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'payment_id' => $id ) );

			case 'update_payment':
				$before = PaymentRepository::find( $payload['payment_id'] );
				$result = PaymentRepository::update( $payload['payment_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'payment_id' => $payload['payment_id'] ) );

			case 'delete_payment':
				$before = PaymentRepository::find( $payload['payment_id'] );
				$result = PaymentRepository::delete( $payload['payment_id'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'payment_id' => $payload['payment_id'], 'deleted' => true ) );

			case 'set_payment_status':
				$before = PaymentRepository::find( $payload['payment_id'] );
				EventRepository::capture_start();
				$result = PaymentRepository::set_status( $payload['payment_id'], $payload['status'], $payload['date'], $payload['note'], $payload['document_id'] );
				$events = EventRepository::capture_stop();
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'payment_id' => $payload['payment_id'], 'status' => $payload['status'], 'events' => $events ) );

			case 'create_rendition':
				$id = RenditionRepository::create( $project_id, $payload['data'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$collected = RenditionRepository::collect_payments( $id );
				return array( 'before' => null, 'result' => array( 'rendition_id' => $id, 'collected' => $collected ) );

			case 'update_rendition':
				$before = RenditionRepository::find( $payload['rendition_id'] );
				$result = RenditionRepository::update( $payload['rendition_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'rendition_id' => $payload['rendition_id'] ) );

			case 'delete_rendition':
				$before = RenditionRepository::find( $payload['rendition_id'] );
				$result = RenditionRepository::delete( $payload['rendition_id'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'rendition_id' => $payload['rendition_id'], 'deleted' => true ) );

			case 'collect_payments':
				$collected = RenditionRepository::collect_payments( $payload['rendition_id'] );
				return array( 'before' => null, 'result' => array( 'rendition_id' => $payload['rendition_id'], 'collected' => $collected ) );

			case 'declare_rendition':
				$before = RenditionRepository::find( $payload['rendition_id'] );
				if ( $before ) {
					// Instantánea de los pagos que la cascada puede cambiar, para revertir de forma exacta.
					$before['payments'] = array();
					foreach ( array_keys( RenditionRepository::cascade_targets( $payload['rendition_id'], $payload['status'] ) ) as $payment_id ) {
						$before['payments'][] = PaymentRepository::find( $payment_id );
					}
				}
				EventRepository::capture_start();
				$result = RenditionRepository::declare( $payload['rendition_id'], $payload['status'], $payload['date'], $payload['note'], $payload['document_id'] );
				$events = EventRepository::capture_stop();
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'rendition_id' => $payload['rendition_id'], 'status' => $payload['status'], 'payments' => count( $before['payments'] ?? array() ), 'events' => $events ) );

			case 'create_guarantee':
				$id = GuaranteeRepository::create( $project_id, $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'guarantee_id' => $id ) );

			case 'update_guarantee':
				$before = GuaranteeRepository::find( $payload['guarantee_id'] );
				$result = GuaranteeRepository::update( $payload['guarantee_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'guarantee_id' => $payload['guarantee_id'] ) );

			case 'delete_guarantee':
				$before = GuaranteeRepository::find( $payload['guarantee_id'] );
				GuaranteeRepository::delete( $payload['guarantee_id'] );
				return array( 'before' => $before, 'result' => array( 'guarantee_id' => $payload['guarantee_id'], 'deleted' => true ) );

			case 'save_cash_plan':
				if ( $payload['plan_id'] > 0 ) {
					$before = CashPlanRepository::find( $payload['plan_id'] );
					$before['rows'] = CashPlanRepository::rows( $payload['plan_id'] );
					$result = CashPlanRepository::update( $payload['plan_id'], $payload['data'], $payload['rows'], $payload['expected_version'] );
					return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'plan_id' => $payload['plan_id'] ) );
				}
				$id = CashPlanRepository::create( $project_id, $payload['data'], (array) $payload['rows'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'plan_id' => $id ) );

			case 'delete_cash_plan':
				$before         = CashPlanRepository::find( $payload['plan_id'] );
				$before['rows'] = CashPlanRepository::rows( $payload['plan_id'] );
				CashPlanRepository::delete( $payload['plan_id'] );
				return array( 'before' => $before, 'result' => array( 'plan_id' => $payload['plan_id'], 'deleted' => true ) );

			case 'import_ledger':
				$result = LedgerRepository::import( $project_id, $payload['source'], $payload['rows'], $payload['batch'] );
				return array( 'before' => null, 'result' => $result + array( 'batch' => $payload['batch'] ) );

			case 'update_ledger':
				$before = LedgerRepository::find( $payload['entry_id'] );
				$result = LedgerRepository::update( $payload['entry_id'], $payload['data'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'entry_id' => $payload['entry_id'] ) );

			case 'delete_ledger':
				$before = LedgerRepository::find( $payload['entry_id'] );
				LedgerRepository::delete( $payload['entry_id'] );
				return array( 'before' => $before, 'result' => array( 'entry_id' => $payload['entry_id'], 'deleted' => true ) );

			case 'delete_ledger_batch':
				$before = array( 'entries' => array_values( array_filter( LedgerRepository::all( $project_id ), static fn( array $e ): bool => $e['batch'] === $payload['batch'] ) ) );
				$count  = LedgerRepository::delete_batch( $project_id, $payload['batch'] );
				return array( 'before' => $before, 'result' => array( 'batch' => $payload['batch'], 'deleted' => $count ) );

			case 'create_modification':
				$id = ModificationRepository::create( $project_id, $payload['data'] );
				return is_wp_error( $id ) ? $id : array( 'before' => null, 'result' => array( 'modification_id' => $id ) );

			case 'update_modification':
				$before = ModificationRepository::find( $payload['modification_id'] );
				$result = ModificationRepository::update( $payload['modification_id'], $payload['data'], $payload['expected_version'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'modification_id' => $payload['modification_id'] ) );

			case 'delete_modification':
				$before = ModificationRepository::find( $payload['modification_id'] );
				$result = ModificationRepository::delete( $payload['modification_id'] );
				return is_wp_error( $result ) ? $result : array( 'before' => $before, 'result' => array( 'modification_id' => $payload['modification_id'], 'deleted' => true ) );

			case 'mark_step':
				$id = EventRepository::log( $project_id, $payload['entity_type'], $payload['entity_id'], 'paso', $payload['step'], $payload['date'], $payload['note'], $payload['document_id'], $payload['guide'] );
				return array( 'before' => null, 'result' => array( 'event_id' => $id, 'step' => $payload['step'] ) );

			case 'undo_step':
				EventRepository::undo_step( $payload['entity_type'], $payload['entity_id'], $payload['guide'], $payload['step'] );
				return array( 'before' => $payload, 'result' => array( 'step' => $payload['step'], 'undone' => true ) );

			case 'set_supplier_registered':
				FinanceService::set_supplier_registered( $project_id, $payload['supplier_id'], $payload['registered'] );
				return array( 'before' => array( 'registered' => ! $payload['registered'] ), 'result' => array( 'supplier_id' => $payload['supplier_id'], 'registered' => $payload['registered'] ) );

			case 'installment_event':
				$before = InstallmentRepository::find( $payload['installment_id'] );
				$id     = EventRepository::log( $project_id, 'installment', $payload['installment_id'], 'estado', $payload['event_key'], $payload['date'], $payload['note'], $payload['document_id'] );
				$fields = array( 'carta_solicitud' => array( 'requested_at' => $payload['date'] ), 'transferencia_aceptada' => array( 'platform_status' => 'aceptada', 'received_at' => $payload['date'] ), 'comprobante_enviado' => array( 'receipt_sent_at' => $payload['date'] ), 'aporte_acreditado' => array( 'cash_received_at' => $payload['date'] ) );
				if ( isset( $fields[ $payload['event_key'] ] ) ) {
					$data = $fields[ $payload['event_key'] ];
					if ( $payload['document_id'] > 0 && 'transferencia_aceptada' === $payload['event_key'] ) {
						$data['document_id'] = $payload['document_id'];
					}
					if ( '' !== $payload['note'] && in_array( $payload['event_key'], array( 'transferencia_aceptada', 'aporte_acreditado' ), true ) ) {
						$data[ 'aporte_acreditado' === $payload['event_key'] ? 'cash_receipt' : 'receipt_number' ] = $payload['note'];
					}
					InstallmentRepository::update( $payload['installment_id'], $data );
				}
				return array( 'before' => $before, 'result' => array( 'event_id' => $id, 'installment_id' => $payload['installment_id'] ) );
		}

		return new WP_Error( 'unknown_action', __( 'Acción desconocida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id ) {
		global $wpdb;

		$tables = array(
			'installment'  => array( InstallmentRepository::class, 'installment_id' ),
			'item'         => array( ItemRepository::class, 'item_id' ),
			'payment'      => array( PaymentRepository::class, 'payment_id' ),
			'rendition'    => array( RenditionRepository::class, 'rendition_id' ),
			'guarantee'    => array( GuaranteeRepository::class, 'guarantee_id' ),
			'cash_plan'    => array( CashPlanRepository::class, 'plan_id' ),
			'ledger'       => array( LedgerRepository::class, 'entry_id' ),
			'modification' => array( ModificationRepository::class, 'modification_id' ),
		);
		if ( ( 'declare_rendition' === $action || 'set_payment_status' === $action ) && $before ) {
			// Estados declarados: se restauran la fila, los pagos alcanzados por la cascada y se borran los eventos creados.
			EventRepository::delete_ids( (array) ( $result['events'] ?? array() ) );
			foreach ( (array) ( $before['payments'] ?? array() ) as $payment ) {
				if ( is_array( $payment ) ) {
					$this->restore( PaymentRepository::class, $payment, false );
				}
			}
			unset( $before['payments'] );

			return $this->restore( 'declare_rendition' === $action ? RenditionRepository::class : PaymentRepository::class, $before, false );
		}
		foreach ( $tables as $entity => $pair ) {
			list( $class, $id_key ) = $pair;
			if ( ! in_array( $action, array( 'create_' . $entity, 'update_' . $entity, 'delete_' . $entity, 'save_' . $entity ), true ) || ! isset( $result[ $id_key ] ) ) {
				continue;
			}
			$id = (int) $result[ $id_key ];
			if ( null === $before ) {
				// Creación: se elimina.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $class::table(), array( 'id' => $id ), array( '%d' ) );
				if ( 'cash_plan' === $entity ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( Schema::table( 'finance_cash_plan_rows' ), array( 'plan_id' => $id ), array( '%d' ) );
				}
				return true;
			}
			return $this->restore( $class, $before, ! empty( $result['deleted'] ) );
		}
		if ( 'save_agreement' === $action ) {
			if ( null === $before ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( AgreementRepository::table(), array( 'project_id' => $project_id ), array( '%d' ) );
				return true;
			}
			return $this->restore( AgreementRepository::class, $before, false );
		}
		if ( 'set_rule' === $action || 'reset_rule' === $action ) {
			if ( null === $before ) {
				RulesRepository::reset( $project_id, (string) $result['rule_key'] );
				return true;
			}
			RulesRepository::set( $project_id, array_intersect_key( $before, array_flip( array( 'rule_key', 'value', 'source', 'valid_from', 'valid_to', 'note' ) ) ) );
			return true;
		}
		if ( 'mark_step' === $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( EventRepository::table(), array( 'id' => (int) $result['event_id'] ), array( '%d' ) );
			return true;
		}
		if ( 'undo_step' === $action && $before ) {
			EventRepository::log( $project_id, $before['entity_type'], $before['entity_id'], 'paso', $before['step'], $before['date'], $before['note'], $before['document_id'], $before['guide'] );
			return true;
		}
		if ( 'set_supplier_registered' === $action ) {
			FinanceService::set_supplier_registered( $project_id, (int) $result['supplier_id'], ! empty( $before['registered'] ) );
			return true;
		}
		if ( 'installment_event' === $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( EventRepository::table(), array( 'id' => (int) $result['event_id'] ), array( '%d' ) );
			return $before ? $this->restore( InstallmentRepository::class, $before, false ) : true;
		}
		if ( 'import_ledger' === $action ) {
			LedgerRepository::delete_batch( $project_id, (string) $result['batch'] );
			return true;
		}
		if ( 'delete_ledger_batch' === $action && $before ) {
			foreach ( $before['entries'] as $e ) {
				$this->restore( LedgerRepository::class, $e, true );
			}
			return true;
		}
		if ( 'collect_payments' === $action || 'seed_items' === $action ) {
			return new WP_Error( 'irreversible', __( 'Esta operación no se revierte automáticamente.', 'gestion-de-proyectos' ) );
		}

		return new WP_Error( 'irreversible', __( 'Operación no reversible.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Restaura una fila a partir de su instantánea.
	 *
	 * @param string              $class    Repositorio.
	 * @param array<string,mixed> $before   Instantánea.
	 * @param bool                $reinsert Si la fila fue eliminada.
	 * @return bool
	 */
	private function restore( string $class, array $before, bool $reinsert ): bool {
		global $wpdb;

		$rows = $before['rows'] ?? null;
		unset( $before['rows'], $before['user'] );
		$columns = $class::columns();
		$data    = array();
		foreach ( $before as $k => $v ) {
			if ( in_array( $k, $columns, true ) ) {
				$data[ $k ] = is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : ( is_bool( $v ) ? (int) $v : $v );
			}
		}
		if ( $reinsert ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $class::table(), $data );
		} else {
			$id = (int) $data['id'];
			unset( $data['id'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $class::table(), $data, array( 'id' => $id ) );
		}
		if ( null !== $rows && isset( $before['id'] ) ) {
			$table = Schema::table( 'finance_cash_plan_rows' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table, array( 'plan_id' => (int) $before['id'] ), array( '%d' ) );
			foreach ( $rows as $r ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert( $table, array( 'plan_id' => (int) $before['id'], 'period' => $r['period'], 'transfer_planned' => $r['transfer'], 'spend_planned' => $r['spend'], 'cash_planned' => $r['cash'], 'milestone' => $r['milestone'] ) );
			}
		}

		return true;
	}

	/**
	 * Comprueba que un registro existe y pertenece al proyecto.
	 *
	 * @param string $class      Repositorio.
	 * @param int    $id         Identificador.
	 * @param int    $project_id Proyecto.
	 * @param string $message    Mensaje de error.
	 * @return array<string,mixed>|WP_Error
	 */
	private function owned( string $class, int $id, int $project_id, string $message ) {
		$row = $id > 0 ? $class::find( $id ) : null;
		if ( ! $row || (int) $row['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', $message );
		}

		return $row;
	}

	/**
	 * Campos comunes de un evento declarado.
	 *
	 * @param array<string,mixed> $payload Carga.
	 * @return array{date:string,note:string,document_id:int}
	 */
	private function event_fields( array $payload ): array {
		$date = Repository::date( $payload['date'] ?? current_time( 'Y-m-d' ) );

		return array(
			'date'        => $date ? $date : current_time( 'Y-m-d' ),
			'note'        => sanitize_textarea_field( (string) ( $payload['note'] ?? '' ) ),
			'document_id' => (int) ( $payload['document_id'] ?? 0 ),
		);
	}

	/**
	 * Diferencias campo a campo.
	 *
	 * @param array<string,mixed> $current Actual.
	 * @param array<string,mixed> $data    Nuevo.
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	private function diff( array $current, array $data ): array {
		$out = array();
		foreach ( $data as $k => $v ) {
			$old = $current[ $k ] ?? null;
			if ( is_array( $v ) || is_array( $old ) ) {
				if ( wp_json_encode( $v ) !== wp_json_encode( $old ) ) {
					$out[ $k ] = array( $old, $v );
				}
			} elseif ( (string) $old !== (string) $v ) {
				$out[ $k ] = array( $old, $v );
			}
		}

		return $out;
	}

	/**
	 * Formato de pesos.
	 *
	 * @param float $amount Monto.
	 * @return string
	 */
	private function money( float $amount ): string {
		return '$' . number_format( $amount, 0, ',', '.' );
	}
}
