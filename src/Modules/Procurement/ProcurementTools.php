<?php
/**
 * Herramientas del conector para adquisiciones y presupuesto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura de compras, cotizaciones, proveedores, presupuesto por partida y
 * valor de la unidad de fomento; propuesta de cambios a través de
 * PurchaseHandler. Los montos se ocultan a quien no tenga
 * procurement.view_amounts.
 */
final class ProcurementTools {

	public const AMOUNT_FIELDS = array( 'amount_net', 'amount_total', 'amount_clp', 'uf_rate', 'tax_rate', 'unit_price', 'line_total' );

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

		$definitions['list-purchases'] = array(
			'label'            => __( 'Listar compras', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve las compras de un proyecto con código, título, partida, proveedor, etapa del ciclo (solicitud_cotizacion, cotizacion_recibida, seguimiento, eleccion, solicitud_interna, orden_compra, factura, pago), estado (abierta, cerrada, anulada), montos (si el usuario puede verlos), fechas de orden, factura y pago, responsable y alertas (cotizaciones recibidas sin decidir, cotizaciones solicitadas sin respuesta). Filtros: stage, status, budget_line, supplier_id, activity_id, open, search.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'stage'       => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string', 'enum' => PurchaseRepository::STATUSES ),
					'budget_line' => array( 'type' => 'string' ),
					'supplier_id' => array( 'type' => 'integer' ),
					'activity_id' => array( 'type' => 'integer' ),
					'open'        => array( 'type' => 'boolean' ),
					'search'      => array( 'type' => 'string' ),
					'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_purchases' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-purchase'] = array(
			'label'            => __( 'Obtener compra', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve una compra con sus cotizaciones (proveedor, estado, montos, ítems con selección), el historial de etapas con fechas, notas y documentos, la cotización elegida, la aprobación, los vínculos y, si procede, las comprobaciones para emitir la orden (antigüedad de la cotización en unidades de fomento, valor implícito frente al oficial, saldo de la partida). Incluye la versión del registro.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => array( 'purchase_id' => array( 'type' => 'integer' ) ),
				'required'             => array( 'purchase_id' ),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_purchase' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['list-suppliers'] = array(
			'label'            => __( 'Listar proveedores', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los proveedores visibles en un proyecto (globales y propios) con contacto, categoría y si están activos; admite búsqueda por texto.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $project_props + array( 'search' => array( 'type' => 'string' ), 'active' => array( 'type' => 'boolean' ) ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_suppliers' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-budget'] = array(
			'label'            => __( 'Obtener presupuesto', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve el presupuesto del proyecto por partida: asignado, comprometido (compras con orden emitida), ejecutado (pagadas), pendiente (abiertas sin orden) y saldo, en pesos, más los totales y el presupuesto total del proyecto. Requiere el permiso procurement.view_amounts.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $project_props, 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_budget' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-uf-rate'] = array(
			'label'            => __( 'Obtener valor de la unidad de fomento', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve el valor oficial de la unidad de fomento aplicable a una fecha (el del día o el último anterior registrado) y, si se indica un monto en unidades de fomento, su equivalente en pesos. Si no hay valor registrado, lo intenta obtener de la fuente pública.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'date' => array( 'type' => 'string', 'description' => __( 'Fecha AAAA-MM-DD; por defecto, hoy.', 'gestion-de-proyectos' ) ), 'amount_uf' => array( 'type' => 'number' ) ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_uf_rate' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['propose-purchase-change'] = array(
			'label'               => __( 'Proponer cambio en compras', 'gestion-de-proyectos' ),
			'description'         => __( 'Propone un cambio en las compras de un proyecto. NO aplica nada: devuelve una vista previa (cambios, advertencias, conflictos) y un operation_id que debe confirmarse con confirm-operation. Acciones: create (data: title, description, budget_line, supplier_id, currency CLP|UF|USD, amount_net, tax_rate, owner_id, activity_id, expected_at, notes), update (purchase_id, data, expected_version), delete (purchase_id; reversible), set_stage (purchase_id, stage, date, note, document_id, order_number, invoice_number; al pasar a orden_compra se comprueban la antigüedad de la cotización en unidades de fomento y el valor implícito frente al oficial: una discrepancia impide confirmar), approve (purchase_id; reservada a quien aprueba compras), add_quote (purchase_id, data: supplier_id, quote_number, status solicitada|recibida, requested_at, quote_date, valid_until, currency, amount_net, tax_rate, document_id, notes; items opcionales), update_quote (quote_id, data, items), delete_quote (quote_id), choose_quote (quote_id: copia proveedor, moneda y monto a la compra y la pasa a elección), set_quote_items (quote_id, items: lista de {description, quantity, unit, unit_price, selected}; el neto se recalcula con los seleccionados), create_supplier (data: name, tax_id, contact_name, email, phone, address, category, notes; global solo administradores), update_supplier (supplier_id, data), delete_supplier (supplier_id), set_budget (budget_line, assigned en pesos, notes).', 'gestion-de-proyectos' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'action'           => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'delete', 'set_stage', 'approve', 'add_quote', 'update_quote', 'delete_quote', 'choose_quote', 'set_quote_items', 'create_supplier', 'update_supplier', 'delete_supplier', 'set_budget' ) ),
					'project_id'       => array( 'type' => 'integer' ),
					'purchase_id'      => array( 'type' => 'integer' ),
					'quote_id'         => array( 'type' => 'integer' ),
					'supplier_id'      => array( 'type' => 'integer' ),
					'data'             => array( 'type' => 'object', 'additionalProperties' => true ),
					'items'            => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
					'expected_version' => array( 'type' => 'integer' ),
					'stage'            => array( 'type' => 'string' ),
					'date'             => array( 'type' => 'string' ),
					'note'             => array( 'type' => 'string' ),
					'document_id'      => array( 'type' => 'integer' ),
					'order_number'     => array( 'type' => 'string' ),
					'invoice_number'   => array( 'type' => 'string' ),
					'global'           => array( 'type' => 'boolean', 'description' => __( 'Proveedor global, visible en todos los proyectos (create_supplier).', 'gestion-de-proyectos' ) ),
					'budget_line'      => array( 'type' => 'string' ),
					'assigned'         => array( 'type' => 'number' ),
					'notes'            => array( 'type' => 'string' ),
				),
				'required'             => array( 'action', 'project_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'propose_purchase_change' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => $write ),
		);

		return $definitions;
	}

	/**
	 * Compras con filtros.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_purchases( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$project_id = (int) $project['id'];
		$amounts    = Access::can( 'procurement.view_amounts', $project_id );
		$filters    = array_intersect_key( $input, array_flip( array( 'stage', 'status', 'budget_line', 'supplier_id', 'activity_id', 'open', 'search', 'limit' ) ) );
		$stages     = PurchaseRepository::stages( $project_id );
		$rows       = array();
		foreach ( PurchaseRepository::for_project( $project_id, $filters ) as $p ) {
			$rows[] = self::brief( $p, $stages, $amounts );
		}
		$pending = array_map( static fn( array $q ): array => array( 'quote_id' => $q['id'], 'purchase_id' => $q['purchase_id'], 'supplier' => $q['supplier'], 'requested_at' => $q['requested_at'] ), QuoteRepository::pending_requests( $project_id ) );

		return array(
			'project_id'           => $project_id,
			'project_code'         => $project['code'],
			'count'                => count( $rows ),
			'stats'                => PurchaseRepository::stats( $project_id ),
			'stages'               => $stages,
			'budget_lines'         => PurchaseRepository::budget_lines( $project_id ),
			'quotes_without_reply' => $pending,
			'amounts_visible'      => $amounts,
			'purchases'            => $rows,
		);
	}

	/**
	 * Compra completa.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_purchase( array $input = array() ) {
		$purchase = PurchaseRepository::find( (int) ( $input['purchase_id'] ?? 0 ) );
		if ( ! $purchase ) {
			return new WP_Error( 'not_found', __( 'La compra no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		$project_id = $purchase['project_id'];
		if ( ! Access::can( 'procurement.view', $project_id ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver las compras de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$amounts = Access::can( 'procurement.view_amounts', $project_id );
		$stages  = PurchaseRepository::stages( $project_id );
		$out     = self::brief( $purchase, $stages, $amounts );
		$out    += array_intersect_key( $purchase, array_flip( array( 'description', 'notes', 'expected_at', 'received_at', 'approved_by', 'approved_at', 'chosen_quote_id', 'uf_date', 'version' ) ) );
		$out['approved_by_name'] = ScheduleService::user_name( $purchase['approved_by'] );
		$out['quotes']           = array_map( static fn( array $q ): array => self::quote_brief( $q, $amounts ), QuoteRepository::for_purchase( $purchase['id'] ) );
		$out['stage_history']    = array_map( static fn( array $s ): array => array( 'stage' => $s['stage'], 'label' => $stages[ $s['stage'] ] ?? $s['stage'], 'date' => $s['stage_date'], 'user' => $s['user'], 'note' => $s['note'], 'document_id' => $s['document_id'] ), PurchaseRepository::stage_history( $purchase['id'] ) );
		$out['links']            = \GDP\Modules\Documents\LinkRepository::for_entity( 'purchase', $purchase['id'] );
		$out['external_refs']    = \GDP\Modules\Documents\ExternalRefRepository::for_entity( 'purchase', $purchase['id'] );
		if ( $amounts && 'abierta' === $purchase['status'] && ! $purchase['committed'] ) {
			$out['order_check'] = ProcurementChecks::order( $purchase, current_time( 'Y-m-d' ) );
		}

		return $out;
	}

	/**
	 * Proveedores.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_suppliers( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$rows = SupplierRepository::for_project( (int) $project['id'], ! empty( $input['active'] ), sanitize_text_field( (string) ( $input['search'] ?? '' ) ) );

		return array( 'project_id' => (int) $project['id'], 'count' => count( $rows ), 'suppliers' => $rows );
	}

	/**
	 * Presupuesto por partida.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_budget( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		if ( ! Access::can( 'procurement.view_amounts', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver montos en este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$summary                 = BudgetService::summary( (int) $project['id'] );
		$summary['project_id']   = (int) $project['id'];
		$summary['currency']     = 'CLP';
		$summary['uf_rate']      = UfService::latest();
		$summary['conventions']  = __( 'Montos en pesos; las compras en unidades de fomento o dólares se convierten con el valor vigente en la fecha de la orden (o de referencia). Comprometido: compras con orden emitida, facturadas o pagadas. Ejecutado: compras pagadas. Pendiente: compras abiertas sin orden.', 'gestion-de-proyectos' );

		return $summary;
	}

	/**
	 * Valor de la unidad de fomento.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_uf_rate( array $input = array() ) {
		$date = \GDP\Modules\Planning\ActivityRepository::normalize_date( $input['date'] ?? current_time( 'Y-m-d' ) );
		if ( false === $date ) {
			return new WP_Error( 'date', __( 'Fecha no válida (use AAAA-MM-DD).', 'gestion-de-proyectos' ) );
		}
		$date = $date ? $date : current_time( 'Y-m-d' );
		$rate = UfService::rate_for( $date );
		if ( ! $rate || ! $rate['exact'] ) {
			$fetched = UfService::fetch( $date );
			if ( ! is_wp_error( $fetched ) ) {
				$rate = UfService::rate_for( $date );
			}
		}
		if ( ! $rate ) {
			return new WP_Error( 'no_rate', __( 'No hay valor de la unidad de fomento registrado ni se pudo obtener; cárguelo en Presupuesto.', 'gestion-de-proyectos' ) );
		}
		$out = array( 'requested_date' => $date, 'rate_date' => $rate['date'], 'value_clp' => $rate['value'], 'exact' => $rate['exact'], 'source' => $rate['source'] );
		if ( isset( $input['amount_uf'] ) ) {
			$out['amount_uf']  = (float) $input['amount_uf'];
			$out['amount_clp'] = UfMath::to_clp( (float) $input['amount_uf'], $rate['value'] );
		}

		return $out;
	}

	/**
	 * Propone una operación de compras.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_purchase_change( array $input = array() ) {
		$action     = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$project_id = (int) ( $input['project_id'] ?? 0 );
		if ( $project_id <= 0 || ! ProjectRepository::find( $project_id ) ) {
			return new WP_Error( 'missing_project', __( 'Indique un project_id válido.', 'gestion-de-proyectos' ) );
		}
		$payload = array_intersect_key( $input, array_flip( array( 'purchase_id', 'quote_id', 'supplier_id', 'data', 'items', 'expected_version', 'stage', 'date', 'note', 'document_id', 'order_number', 'invoice_number', 'global', 'budget_line', 'assigned', 'notes' ) ) );

		return OperationManager::propose( 'purchase', $action, $payload, $project_id, 'connector' );
	}

	/**
	 * Resumen de una compra.
	 *
	 * @param array<string,mixed>  $p       Compra.
	 * @param array<string,string> $stages  Etapas.
	 * @param bool                 $amounts Si se muestran montos.
	 * @return array<string,mixed>
	 */
	public static function brief( array $p, array $stages, bool $amounts ): array {
		$supplier = $p['supplier_id'] > 0 ? SupplierRepository::find( $p['supplier_id'] ) : null;
		$out      = array(
			'id'                => $p['id'],
			'code'              => $p['code'],
			'title'             => $p['title'],
			'budget_line'       => $p['budget_line'],
			'supplier_id'       => $p['supplier_id'],
			'supplier'          => $supplier ? $supplier['name'] : '',
			'stage'             => $p['stage'],
			'stage_label'       => $stages[ $p['stage'] ] ?? $p['stage'],
			'status'            => $p['status'],
			'owner_id'          => $p['owner_id'],
			'owner'             => ScheduleService::user_name( $p['owner_id'] ),
			'activity_id'       => $p['activity_id'],
			'currency'          => $p['currency'],
			'order_number'      => $p['order_number'],
			'order_date'        => $p['order_date'],
			'invoice_number'    => $p['invoice_number'],
			'invoice_date'      => $p['invoice_date'],
			'paid_at'           => $p['paid_at'],
			'approved'          => ! empty( $p['approved_at'] ),
			'committed'         => $p['committed'],
			'executed'          => $p['executed'],
			'awaiting_decision' => PurchaseRepository::awaiting_decision( $p ),
			'version'           => $p['version'],
		);
		if ( $amounts ) {
			$out += array_intersect_key( $p, array_flip( array( 'amount_net', 'tax_rate', 'amount_total', 'amount_clp', 'uf_rate' ) ) );
		}

		return $out;
	}

	/**
	 * Resumen de una cotización.
	 *
	 * @param array<string,mixed> $q       Cotización.
	 * @param bool                $amounts Si se muestran montos.
	 * @return array<string,mixed>
	 */
	public static function quote_brief( array $q, bool $amounts ): array {
		$out = array_intersect_key( $q, array_flip( array( 'id', 'supplier_id', 'supplier', 'quote_number', 'status', 'requested_at', 'quote_date', 'valid_until', 'currency', 'document_id', 'notes' ) ) );
		if ( $amounts ) {
			$out += array_intersect_key( $q, array_flip( array( 'amount_net', 'tax_rate', 'amount_total' ) ) );
			$out['items'] = $q['items'];
		} else {
			$out['items'] = array_map( static fn( array $i ): array => array_diff_key( $i, array_flip( array( 'unit_price', 'line_total' ) ) ), $q['items'] );
		}

		return $out;
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
		if ( ! Access::can( 'procurement.view', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver las compras de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return $project;
	}
}
