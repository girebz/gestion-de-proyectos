<?php
/**
 * Herramientas del conector para finanzas y rendición de cuentas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Operations\OperationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura del estado de cuentas, de la cuota siguiente (brechas y
 * condiciones), de las rendiciones y los pagos, del asistente (acciones y
 * hojas de ejecución) y de las reglas; propuesta de cambios a través de
 * FinanceHandler. Todo exige finance.view.
 */
final class FinanceTools {

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
		$props = array(
			'project_id' => array( 'type' => 'integer', 'description' => __( 'Identificador numérico del proyecto.', 'gestion-de-proyectos' ) ),
			'code'       => array( 'type' => 'string', 'description' => __( 'Código del proyecto (alternativa al identificador).', 'gestion-de-proyectos' ) ),
		);

		$definitions['get-finance-status'] = array(
			'label'            => __( 'Obtener estado de cuentas', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve el estado de cuentas del proyecto a la fecha: por fuente (Fondo y aporte pecuniario) lo transferido o enterado, pagado, rendido, aprobado, observado, rechazado y comprometido, el saldo de caja calculado, el saldo de la cartola y la diferencia de conciliación; el avance por cuota (pagado, rendido y aprobado con imputación cronológica); el disponible por ítem y los topes; la línea de tiempo de las rendiciones con plazos, vencidas y días hábiles restantes; la programación de caja vigente con sus seis controles; la diferencia de cierre; y las reglas efectivas con su fuente. Admite date (AAAA-MM-DD) para evaluar a otra fecha.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $props + array( 'date' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_status' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-next-installment'] = array(
			'label'            => __( 'Obtener brecha y condiciones de la cuota siguiente', 'gestion-de-proyectos' ),
			'description'      => __( 'Responde cuánto falta pagar y rendir para acceder a la cuota siguiente, en qué ítems y antes de qué fecha: brechas de pago, rendición y aprobación, monto de la garantía alternativa, último mes de pago útil (calculado hacia atrás desde la fecha objetivo del giro con los plazos de rendición en días hábiles y los desfases de revisión y solicitud), fecha límite de facturación, compromisos candidatos a cerrar la brecha y las siete condiciones de giro (G1 a G7) con su cumplimiento y detalle.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $props, 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_next_installment' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['list-finance-renditions'] = array(
			'label'            => __( 'Listar rendiciones', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve las rendiciones del proyecto (período, fuente, tipo, estado, plazos interno y de la plataforma, plazo de subsanación, fechas declaradas) y, si se indica rendition_id, el detalle de una con sus pagos, hallazgos de validación por pago, problemas para la carga masiva y eventos declarados.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $props + array( 'rendition_id' => array( 'type' => 'integer' ), 'source' => array( 'type' => 'string', 'enum' => PaymentRepository::SOURCES ) ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_renditions' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['list-finance-payments'] = array(
			'label'            => __( 'Listar pagos', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los pagos (unidad de rendición) con código, compra, proveedor, fuente, ítem, fechas de ejecución y egreso, comprobante de egreso, documento, monto, cuota imputada (declarada o cronológica), estado, rendición, folio, observación y respaldos; cada uno con sus hallazgos de validación (bloquea, advierte, falta). Filtros: source, status, statuses, item_slug, period (AAAA-MM del egreso), rendition_id, purchase_id, supplier_id, search, limit.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $props + array(
					'source'       => array( 'type' => 'string' ),
					'status'       => array( 'type' => 'string', 'enum' => PaymentRepository::STATUSES ),
					'statuses'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'item_slug'    => array( 'type' => 'string' ),
					'period'       => array( 'type' => 'string' ),
					'rendition_id' => array( 'type' => 'integer' ),
					'purchase_id'  => array( 'type' => 'integer' ),
					'supplier_id'  => array( 'type' => 'integer' ),
					'search'       => array( 'type' => 'string' ),
					'limit'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_payments' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-finance-assistant'] = array(
			'label'            => __( 'Obtener acciones del asistente de rendición', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve las acciones pendientes derivadas del estado (aceptar transferencias, crear y presentar rendiciones, reunir respaldos, registrar proveedores, pagar y rendir la brecha, cumplir condiciones, renovar garantías, revisar la programación, aclarar la conciliación), cada una con responsable, vencimiento, días hábiles restantes, severidad y la guía que la resuelve. Con guide, entity_type y entity_id devuelve la hoja de ejecución: los pasos de la guía con la pantalla de la plataforma, la indicación, cada campo con su valor ya formateado para copiar, los archivos por subir, los pasos cumplidos y los datos que faltan.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $props + array( 'guide' => array( 'type' => 'string' ), 'entity_type' => array( 'type' => 'string' ), 'entity_id' => array( 'type' => 'integer' ) ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_assistant' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['propose-finance-change'] = array(
			'label'               => __( 'Proponer cambio en finanzas', 'gestion-de-proyectos' ),
			'description'         => __( 'Propone un cambio en finanzas y rendición. NO aplica nada: devuelve una vista previa (cambios, advertencias, conflictos) y un operation_id que debe confirmarse con confirm-operation. Acciones: save_agreement (data: profile, funder, program, agreement_date, approval_act, approval_date, start_date, end_date, months, fund_amount, cash_amount, inkind_amount, guarantee_required, platform, platform_code, platform_end_date, platform_render_until, bank_account, cost_center, notes), create_installment / update_installment (installment_id) / delete_installment (data: number, label, amount, share_pct, window_from, window_to, report_no, cash_amount, cash_received_at, cash_receipt, requested_at, transferred_at, received_at, receipt_number, receipt_sent_at, platform_status pendiente|enviada|aceptada|rechazada, document_id, notes), save_item (data: slug, label, item_group, assigned_fund, assigned_cash, assigned_inkind, platform_type, platform_subclass, budget_line, cap_rule, sort_order, notes) / delete_item (item_id) / seed_items, set_rule (data: rule_key, value, source, valid_from, valid_to, note) / reset_rule (rule_key), create_payment / update_payment (payment_id, expected_version) / delete_payment (data: purchase_id, supplier_id, source fondo|pecuniario, item_slug, description, commitment, executed_at, paid_at, egress_number, egress_document_id, doc_type factura|factura_exenta|boleta|boleta_honorarios|documento_extranjero|otro, doc_number, doc_date, amount, installment_no (0 = cronológica), status, support: lista de {kind, document_id, note}, notes), set_payment_status (payment_id, status, date, note, document_id), create_rendition (data: period AAAA-MM, source, kind mensual|sin_movimiento|regularizacion|final, notes; asigna los pagos pagados del período) / update_rendition / delete_rendition / collect_payments (rendition_id), declare_rendition (rendition_id, status, date, note, document_id; al declarar enviada_universidad o rendida, los hallazgos que bloquean son conflictos), create_guarantee / update_guarantee / delete_guarantee (data: kind, instrument, number, issuer, amount, issued_at, valid_until, installment_no, document_id, status, notes), save_cash_plan (plan_id opcional, data: name, status, submitted_at, notes; rows: lista de {period, transfer, spend, cash, milestone}) / delete_cash_plan, import_ledger (source, batch, rows: lista de {entry_date, reference, description, debit, credit}; empareja por monto y fecha) / update_ledger (entry_id, data) / delete_ledger / delete_ledger_batch (batch), create_modification / update_modification / delete_modification (data: kind, status, requested_at, approved_at, act_number, document_id, details: lista de {item, source, delta, note}, notes), mark_step / undo_step (entity_type, entity_id, guide, step, date, note, document_id), set_supplier_registered (supplier_id, registered), installment_event (installment_id, event_key informe_aprobado|carta_solicitud|transferencia_aceptada|comprobante_enviado|aporte_acreditado|otro, date, note, document_id).', 'gestion-de-proyectos' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'action'           => array( 'type' => 'string' ),
					'project_id'       => array( 'type' => 'integer' ),
					'data'             => array( 'type' => 'object', 'additionalProperties' => true ),
					'rows'             => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
					'expected_version' => array( 'type' => 'integer' ),
					'installment_id'   => array( 'type' => 'integer' ),
					'item_id'          => array( 'type' => 'integer' ),
					'payment_id'       => array( 'type' => 'integer' ),
					'rendition_id'     => array( 'type' => 'integer' ),
					'guarantee_id'     => array( 'type' => 'integer' ),
					'plan_id'          => array( 'type' => 'integer' ),
					'entry_id'         => array( 'type' => 'integer' ),
					'modification_id'  => array( 'type' => 'integer' ),
					'supplier_id'      => array( 'type' => 'integer' ),
					'entity_type'      => array( 'type' => 'string' ),
					'entity_id'        => array( 'type' => 'integer' ),
					'guide'            => array( 'type' => 'string' ),
					'step'             => array( 'type' => 'string' ),
					'status'           => array( 'type' => 'string' ),
					'event_key'        => array( 'type' => 'string' ),
					'rule_key'         => array( 'type' => 'string' ),
					'source'           => array( 'type' => 'string' ),
					'batch'            => array( 'type' => 'string' ),
					'date'             => array( 'type' => 'string' ),
					'note'             => array( 'type' => 'string' ),
					'document_id'      => array( 'type' => 'integer' ),
					'registered'       => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'action', 'project_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'propose_change' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => $write ),
		);

		return $definitions;
	}

	/**
	 * Estado de cuentas.
	 *
	 * @param array<string,mixed> $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_status( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$date   = Repository::date( $input['date'] ?? '' );
		$status = FinanceService::status( (int) $project['id'], $date ? $date : '' );
		$status['project_id']   = (int) $project['id'];
		$status['project_code'] = $project['code'];
		$status['conventions']  = __( 'Montos en pesos. Pagado: pagos con comprobante de egreso (incluye rendidos, aprobados, observados y rechazados). Rendido: presentados en la plataforma. Aprobado: aprobados por el otorgante. Saldo de caja = transferido + aporte − pagado − reintegrado; la diferencia de conciliación compara ese saldo con la cartola del centro de costo. El avance por cuota imputa los pagos cronológicamente salvo cuota declarada.', 'gestion-de-proyectos' );

		return $status;
	}

	/**
	 * Cuota siguiente.
	 *
	 * @param array<string,mixed> $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_next_installment( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$status = FinanceService::status( (int) $project['id'] );
		if ( ! $status['next_installment'] ) {
			return array( 'project_id' => (int) $project['id'], 'next_installment' => null, 'message' => __( 'No hay una cuota siguiente registrada; todas las cuotas fueron recibidas o no se han registrado cuotas.', 'gestion-de-proyectos' ), 'last_received' => $status['last_received'] );
		}

		return array( 'project_id' => (int) $project['id'], 'today' => $status['today'], 'last_received' => $status['last_received'], 'sources' => array( 'fondo' => $status['sources']['fondo'] ), 'overdue_renditions' => $status['overdue_renditions'] ) + $status['next_installment'];
	}

	/**
	 * Rendiciones.
	 *
	 * @param array<string,mixed> $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_renditions( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$project_id = (int) $project['id'];
		if ( ! empty( $input['rendition_id'] ) ) {
			$r = RenditionRepository::find( (int) $input['rendition_id'] );
			if ( ! $r || $r['project_id'] !== $project_id ) {
				return new WP_Error( 'not_found', __( 'La rendición no existe en este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
			}
			$payments = array();
			foreach ( PaymentRepository::list( $project_id, array( 'rendition_id' => $r['id'] ) ) as $p ) {
				$row           = PaymentRepository::brief( $p );
				$row['issues'] = FinanceService::validate_payment( $p, null );
				$payments[]    = $row;
			}
			$bulk = FinanceExport::bulk_payments( $r );

			return array(
				'rendition'     => $r,
				'payments'      => $payments,
				'bulk_rows'     => \GDP\Modules\Finance\Logic\BulkLoad::rows( $bulk ),
				'bulk_problems' => \GDP\Modules\Finance\Logic\BulkLoad::problems( $bulk, InstallmentRepository::amounts( $project_id ) ),
				'events'        => EventRepository::for_entity( 'rendition', $r['id'] ),
			);
		}
		$rows = RenditionRepository::all( $project_id, sanitize_key( (string) ( $input['source'] ?? '' ) ) );

		return array( 'project_id' => $project_id, 'count' => count( $rows ), 'statuses' => Profiles::get( (string) AgreementRepository::get( $project_id )['profile'] )->rendition_statuses(), 'renditions' => $rows, 'timeline' => FinanceService::status( $project_id )['renditions'] );
	}

	/**
	 * Pagos.
	 *
	 * @param array<string,mixed> $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_payments( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$project_id = (int) $project['id'];
		$filters    = array_intersect_key( $input, array_flip( array( 'source', 'status', 'statuses', 'item_slug', 'period', 'rendition_id', 'purchase_id', 'supplier_id', 'search', 'limit' ) ) );
		$status     = FinanceService::status( $project_id );
		$effective  = PaymentRepository::effective_installments( $project_id );
		$rows       = array();
		foreach ( PaymentRepository::list( $project_id, $filters ) as $p ) {
			$row                          = PaymentRepository::brief( $p );
			$row['effective_installment'] = $effective[ $p['id'] ] ?? ( $p['installment_no'] > 0 ? $p['installment_no'] : null );
			$row['issues']                = FinanceService::validate_payment( $p, $status );
			$rows[]                       = $row;
		}

		return array( 'project_id' => $project_id, 'count' => count( $rows ), 'totals' => PaymentRepository::totals( $project_id ), 'items' => ItemRepository::labels( $project_id ), 'payments' => $rows );
	}

	/**
	 * Asistente: acciones u hoja de ejecución.
	 *
	 * @param array<string,mixed> $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_assistant( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$project_id = (int) $project['id'];
		if ( ! empty( $input['guide'] ) ) {
			$sheet = Assistant::sheet( $project_id, sanitize_key( (string) $input['guide'] ), sanitize_key( (string) ( $input['entity_type'] ?? 'project' ) ), (int) ( $input['entity_id'] ?? 0 ) );
			if ( ! $sheet ) {
				return new WP_Error( 'not_found', __( 'La guía no existe en el perfil del proyecto.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
			}

			return $sheet;
		}
		$profile = Profiles::get( (string) AgreementRepository::get( $project_id )['profile'] );
		$guides  = array();
		foreach ( $profile->guides() as $key => $g ) {
			$guides[ $key ] = array( 'label' => $g['label'], 'entity' => $g['entity'], 'source' => $g['source'], 'steps' => count( $g['steps'] ) );
		}

		return array( 'project_id' => $project_id, 'actions' => Assistant::actions( $project_id ), 'guides' => $guides, 'links' => $profile->links() );
	}

	/**
	 * Propuesta de cambio.
	 *
	 * @param array<string,mixed> $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_change( array $input = array() ) {
		$project_id = (int) ( $input['project_id'] ?? 0 );
		if ( ! ProjectRepository::find( $project_id ) ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		$action  = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$payload = $input;
		unset( $payload['action'], $payload['project_id'] );

		return OperationManager::propose( 'finance', $action, $payload, $project_id );
	}

	/**
	 * Resuelve el proyecto y comprueba el permiso de lectura.
	 *
	 * @param array<string,mixed> $input Entrada.
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
		if ( ! Access::can( 'finance.view', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver las finanzas de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return $project;
	}
}
