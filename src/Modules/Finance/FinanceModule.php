<?php
/**
 * Módulo de finanzas y rendición de cuentas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Admin\Pages\FinancePage;
use GDP\Modules\ModuleInterface;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Convenio, cuotas, ítems con asignado por fuente, registro de reglas,
 * pagos como unidad de rendición, rendiciones por período con estados
 * declarados, garantías, programación de caja con seis controles,
 * conciliación con el centro de costo, modificaciones, asistente con hojas
 * de ejecución y exportaciones para la plataforma de rendición.
 *
 * Componentes:
 * - Logic\*: identidades puras (imputación, conciliación, plazos, brechas, controles, condiciones, validaciones, carga masiva).
 * - Profiles\*: perfiles de fondo (reglas, ítems, respaldos, guías).
 * - Repositorios: Agreement, Installment, Item, Payment, Rendition, Event, Guarantee, CashPlan, Ledger, Rules, Modification.
 * - FinanceService (estado de cuentas), Assistant (acciones y hojas), FinanceHandler (operaciones), FinanceTools (conector), FinanceExport, FinanceCron.
 * - Admin\FinancePage y Admin\FinanceViews.
 */
final class FinanceModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'finance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Finanzas y rendición de cuentas', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Convenio, cuotas, pagos, rendiciones, estado de cuentas, cuadratura de caja y asistente de rendición con hojas de ejecución para la plataforma del otorgante.', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'gdp_register_operation_handlers', array( $this, 'register_handlers' ) );
		add_filter( 'gdp_connector_abilities', array( FinanceTools::class, 'add_abilities' ) );
		add_filter( 'gdp_link_entities', array( $this, 'link_entities' ) );
		add_filter( 'gdp_calendar_events', array( FinanceCron::class, 'calendar_events' ), 10, 4 );
		add_action( 'gdp_daily_tasks', array( FinanceCron::class, 'daily' ) );
		add_action( 'gdp_project_deleted', array( $this, 'cleanup_project' ) );
		add_filter( 'gdp_data_modules', array( $this, 'data_modules' ) );
		add_filter( 'gdp_data_refs', array( $this, 'data_refs' ) );
		add_filter( 'gdp_data_keys', array( $this, 'data_keys' ) );
		add_filter( 'gdp_data_parents', array( $this, 'data_parents' ) );
		add_filter( 'gdp_data_json_columns', array( $this, 'data_json_columns' ) );
		add_filter( 'gdp_data_amount_columns', array( $this, 'data_amount_columns' ) );
		add_filter( 'gdp_data_entity_tables', array( $this, 'data_entity_tables' ) );
		add_filter( 'gdp_data_polymorphic', array( $this, 'data_polymorphic' ) );
		add_filter( 'gdp_data_personal_columns', array( $this, 'data_personal_columns' ) );
		add_filter( 'gdp_data_import_row', array( $this, 'import_row' ), 10, 3 );
		add_filter( 'gdp_data_dictionary', array( $this, 'data_dictionary' ) );

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( FinancePage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( FinancePage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( FinancePage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( FinancePage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra el manejador de operaciones.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		OperationManager::register_handler( new FinanceHandler() );
	}

	/**
	 * Entidades enlazables: pago y rendición.
	 *
	 * @param array<string,array<string,mixed>> $entities Entidades.
	 * @return array<string,array<string,mixed>>
	 */
	public function link_entities( array $entities ): array {
		$entities['payment']   = array(
			'label'   => __( 'pago', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$p = PaymentRepository::find( $id );
				if ( ! $p ) {
					return null;
				}
				return array( 'title' => $p['description'], 'code' => $p['code'], 'url' => FinancePage::url( $p['project_id'], array( 'view' => 'payments', 'id' => $p['id'] ) ), 'project_id' => $p['project_id'] );
			},
			'search'  => static function ( int $project_id, string $text ): array {
				$out = array();
				foreach ( PaymentRepository::list( $project_id, array( 'search' => $text, 'limit' => 200 ) ) as $p ) {
					$out[] = array( 'id' => $p['id'], 'code' => $p['code'], 'title' => $p['description'] );
				}
				return $out;
			},
		);
		$entities['rendition'] = array(
			'label'   => __( 'rendición', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$r = RenditionRepository::find( $id );
				if ( ! $r ) {
					return null;
				}
				return array( 'title' => sprintf( 'Rendición %s (%s)', $r['period'], $r['kind'] ), 'code' => $r['period'], 'url' => FinancePage::url( $r['project_id'], array( 'view' => 'renditions', 'id' => $r['id'] ) ), 'project_id' => $r['project_id'] );
			},
			'search'  => static function ( int $project_id, string $text ): array {
				$out = array();
				foreach ( RenditionRepository::all( $project_id ) as $r ) {
					if ( '' === $text || false !== strpos( $r['period'], $text ) ) {
						$out[] = array( 'id' => $r['id'], 'code' => $r['period'], 'title' => sprintf( 'Rendición %s (%s)', $r['period'], $r['kind'] ) );
					}
				}
				return $out;
			},
		);

		return $entities;
	}

	/**
	 * Tablas del módulo para exportación e importación, en orden de carga:
	 * las rendiciones antes que los pagos que las referencian, los pagos antes
	 * que los movimientos emparejados y los eventos al final, porque apuntan a
	 * cualquiera de las entidades anteriores.
	 *
	 * @param array<string,string[]> $modules Módulos.
	 * @return array<string,string[]>
	 */
	public function data_modules( array $modules ): array {
		$modules['finance'] = array( 'finance_agreements', 'finance_installments', 'finance_items', 'finance_rules', 'finance_renditions', 'finance_payments', 'finance_guarantees', 'finance_cash_plans', 'finance_cash_plan_rows', 'finance_ledger', 'finance_modifications', 'finance_events' );

		return $modules;
	}

	/**
	 * Referencias entre tablas.
	 *
	 * @param array<string,array<string,string>> $refs Referencias.
	 * @return array<string,array<string,string>>
	 */
	public function data_refs( array $refs ): array {
		$refs['finance_agreements']     = array( 'project_id' => 'projects' );
		$refs['finance_installments']   = array( 'project_id' => 'projects', 'document_id' => 'documents' );
		$refs['finance_items']          = array( 'project_id' => 'projects' );
		$refs['finance_rules']          = array( 'project_id' => 'projects' );
		$refs['finance_payments']       = array( 'project_id' => 'projects', 'purchase_id' => 'purchases', 'supplier_id' => 'suppliers', 'egress_document_id' => 'documents', 'rendition_id' => 'finance_renditions' );
		$refs['finance_renditions']     = array( 'project_id' => 'projects', 'report_document_id' => 'documents', 'letter_document_id' => 'documents' );
		$refs['finance_events']         = array( 'project_id' => 'projects', 'document_id' => 'documents' );
		$refs['finance_guarantees']     = array( 'project_id' => 'projects', 'document_id' => 'documents' );
		$refs['finance_cash_plans']     = array( 'project_id' => 'projects' );
		$refs['finance_cash_plan_rows'] = array( 'plan_id' => 'finance_cash_plans' );
		$refs['finance_ledger']         = array( 'project_id' => 'projects', 'payment_id' => 'finance_payments' );
		$refs['finance_modifications']  = array( 'project_id' => 'projects', 'document_id' => 'documents' );

		return $refs;
	}

	/**
	 * Claves naturales para la importación.
	 *
	 * @param array<string,string[]> $keys Claves.
	 * @return array<string,string[]>
	 */
	public function data_keys( array $keys ): array {
		$keys['finance_agreements']     = array( 'project_id' );
		$keys['finance_installments']   = array( 'project_id', 'number' );
		$keys['finance_items']          = array( 'project_id', 'slug' );
		$keys['finance_rules']          = array( 'project_id', 'rule_key' );
		$keys['finance_payments']       = array( 'project_id', 'code' );
		$keys['finance_renditions']     = array( 'project_id', 'source', 'period', 'kind' );
		$keys['finance_events']         = array( 'project_id', 'entity_type', 'entity_id', 'kind', 'event_key', 'guide', 'event_date' );
		$keys['finance_guarantees']     = array( 'project_id', 'kind', 'number', 'amount' );
		$keys['finance_cash_plans']     = array( 'project_id', 'name' );
		$keys['finance_cash_plan_rows'] = array( 'plan_id', 'period' );
		$keys['finance_ledger']         = array( 'project_id', 'source', 'entry_date', 'reference', 'debit', 'credit' );
		$keys['finance_modifications']  = array( 'project_id', 'kind', 'requested_at', 'act_number' );

		return $keys;
	}

	/**
	 * Tablas hijas.
	 *
	 * @param array<string,array{0:string,1:string}> $parents Tablas hijas.
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_parents( array $parents ): array {
		$parents['finance_cash_plan_rows'] = array( 'plan_id', 'finance_cash_plans' );

		return $parents;
	}

	/**
	 * Columnas JSON.
	 *
	 * @param array<string,string[]> $columns Columnas.
	 * @return array<string,string[]>
	 */
	public function data_json_columns( array $columns ): array {
		$columns['finance_payments']      = array( 'support' );
		$columns['finance_modifications'] = array( 'details' );

		return $columns;
	}

	/**
	 * Columnas de montos.
	 *
	 * @param array<string,string[]> $columns Columnas.
	 * @return array<string,string[]>
	 */
	public function data_amount_columns( array $columns ): array {
		$columns['finance_agreements']     = array( 'fund_amount', 'cash_amount', 'inkind_amount' );
		$columns['finance_installments']   = array( 'amount', 'cash_amount' );
		$columns['finance_items']          = array( 'assigned_fund', 'assigned_cash', 'assigned_inkind' );
		$columns['finance_payments']       = array( 'amount' );
		$columns['finance_guarantees']     = array( 'amount' );
		$columns['finance_cash_plan_rows'] = array( 'transfer_planned', 'spend_planned', 'cash_planned' );
		$columns['finance_ledger']         = array( 'debit', 'credit' );

		return $columns;
	}

	/**
	 * Entidades enlazables para la importación.
	 *
	 * @param array<string,string> $tables Entidades.
	 * @return array<string,string>
	 */
	public function data_entity_tables( array $tables ): array {
		$tables['payment']     = 'finance_payments';
		$tables['rendition']   = 'finance_renditions';
		$tables['installment'] = 'finance_installments';
		$tables['guarantee']   = 'finance_guarantees';

		return $tables;
	}

	/**
	 * Referencias polimórficas: los eventos apuntan a un pago, una rendición,
	 * una cuota, una garantía, un proveedor o al proyecto (identificador 0).
	 *
	 * @param array<string,array<int,array{0:string,1:string}>> $pairs Referencias.
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	public function data_polymorphic( array $pairs ): array {
		$pairs['finance_events'] = array( array( 'entity_type', 'entity_id' ) );

		return $pairs;
	}

	/**
	 * Columnas que pueden contener nombres de personas (glosas y descripciones
	 * de honorarios), vaciadas en una exportación anonimizada.
	 *
	 * @param array<string,string[]> $columns Columnas.
	 * @return array<string,string[]>
	 */
	public function data_personal_columns( array $columns ): array {
		$columns['finance_payments'] = array( 'description', 'observation', 'notes' );
		$columns['finance_ledger']   = array( 'description' );
		$columns['finance_events']   = array( 'note' );

		return $columns;
	}

	/**
	 * Reasigna los documentos de respaldo guardados en el JSON de cada pago.
	 * Si el documento de origen no viene en el archivo, se conserva el
	 * identificador (importación sobre el mismo sitio); si viene pero no se
	 * importó, el respaldo queda sin documento y el validador lo marcará.
	 *
	 * @param array<string,mixed>                $row   Fila preparada.
	 * @param string                             $table Tabla.
	 * @param array<string,array<int,int|null>> $map   Identificadores reasignados.
	 * @return array<string,mixed>
	 */
	public function import_row( array $row, string $table, array $map ): array {
		if ( 'finance_payments' !== $table || ! is_array( $row['support'] ?? null ) || ! isset( $map['documents'] ) ) {
			return $row;
		}
		foreach ( $row['support'] as &$entry ) {
			if ( is_array( $entry ) && ! empty( $entry['document_id'] ) ) {
				$old                  = (int) $entry['document_id'];
				$entry['document_id'] = array_key_exists( $old, $map['documents'] ) ? (int) $map['documents'][ $old ] : 0;
			}
		}
		unset( $entry );

		return $row;
	}

	/**
	 * Etiquetas y descripciones de las tablas en el diccionario de datos.
	 *
	 * @param array<string,array<string,mixed>> $dictionary Diccionario.
	 * @return array<string,array<string,mixed>>
	 */
	public function data_dictionary( array $dictionary ): array {
		$meta = array(
			'finance_agreements'     => array( __( 'Convenio', 'gestion-de-proyectos' ), __( 'Perfil de fondo, otorgante, fechas, montos por fuente y datos del proyecto en la plataforma de rendición.', 'gestion-de-proyectos' ) ),
			'finance_installments'   => array( __( 'Cuotas', 'gestion-de-proyectos' ), __( 'Programa de desembolso: monto, ventana, informe habilitante, aporte pecuniario y fechas de solicitud, transferencia, ingreso y aceptación.', 'gestion-de-proyectos' ) ),
			'finance_items'          => array( __( 'Ítems del convenio', 'gestion-de-proyectos' ), __( 'Asignado vigente por fuente y proyección a la clasificación de la plataforma.', 'gestion-de-proyectos' ) ),
			'finance_rules'          => array( __( 'Reglas del proyecto', 'gestion-de-proyectos' ), __( 'Valores que sustituyen a los del perfil de fondo, con fuente y vigencia.', 'gestion-de-proyectos' ) ),
			'finance_payments'       => array( __( 'Pagos', 'gestion-de-proyectos' ), __( 'Unidad de rendición: documento pagado con fuente, ítem, egreso, cuota imputada, estado y respaldos.', 'gestion-de-proyectos' ) ),
			'finance_renditions'     => array( __( 'Rendiciones', 'gestion-de-proyectos' ), __( 'Rendiciones por período y fuente con estado declarado y plazos.', 'gestion-de-proyectos' ) ),
			'finance_events'         => array( __( 'Eventos de rendición', 'gestion-de-proyectos' ), __( 'Estados declarados y pasos cumplidos, con fecha, autor, nota y documento.', 'gestion-de-proyectos' ) ),
			'finance_guarantees'     => array( __( 'Garantías', 'gestion-de-proyectos' ), __( 'Instrumentos de garantía con monto, vigencia y estado.', 'gestion-de-proyectos' ) ),
			'finance_cash_plans'     => array( __( 'Programaciones de caja', 'gestion-de-proyectos' ), __( 'Planes de transferencias, gasto y aporte por mes.', 'gestion-de-proyectos' ) ),
			'finance_cash_plan_rows' => array( __( 'Filas de programación', 'gestion-de-proyectos' ), __( 'Un mes de una programación de caja.', 'gestion-de-proyectos' ) ),
			'finance_ledger'         => array( __( 'Movimientos del centro de costo', 'gestion-de-proyectos' ), __( 'Cartola importada y emparejada con pagos y transferencias para la conciliación.', 'gestion-de-proyectos' ) ),
			'finance_modifications'  => array( __( 'Modificaciones del convenio', 'gestion-de-proyectos' ), __( 'Reitemizaciones, prórrogas y otras modificaciones con su estado y acto.', 'gestion-de-proyectos' ) ),
		);
		$fields = self::field_descriptions();
		foreach ( $meta as $table => $pair ) {
			if ( ! isset( $dictionary[ $table ] ) ) {
				continue;
			}
			$dictionary[ $table ]['label']       = $pair[0];
			$dictionary[ $table ]['description'] = $pair[1];
			foreach ( $dictionary[ $table ]['fields'] as &$field ) {
				if ( isset( $fields[ $table ][ $field['name'] ] ) ) {
					$field['description'] = $fields[ $table ][ $field['name'] ][0];
					$field['unit']        = $fields[ $table ][ $field['name'] ][1] ?? $field['unit'];
				}
			}
			unset( $field );
		}

		return $dictionary;
	}

	/**
	 * Significado y unidad de las columnas propias del módulo, para el
	 * diccionario de datos y para preparar importaciones a mano.
	 *
	 * @return array<string,array<string,array{0:string,1?:string}>>
	 */
	public static function field_descriptions(): array {
		$money = 'CLP';
		return array(
			'finance_agreements'     => array(
				'profile'               => array( __( 'Perfil de fondo con las reglas, ítems, respaldos y guías (por ejemplo frpd_coquimbo_sisrec).', 'gestion-de-proyectos' ) ),
				'funder'                => array( __( 'Institución otorgante.', 'gestion-de-proyectos' ) ),
				'program'               => array( __( 'Fondo o programa del concurso.', 'gestion-de-proyectos' ) ),
				'agreement_date'        => array( __( 'Fecha de firma del convenio.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'approval_act'          => array( __( 'Acto que aprueba el convenio (por ejemplo, número de resolución exenta).', 'gestion-de-proyectos' ) ),
				'approval_date'         => array( __( 'Fecha del acto aprobatorio; desde ella se pueden rendir gastos.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'start_date'            => array( __( 'Inicio de la ejecución; primer mes de la línea de tiempo de rendiciones.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'end_date'              => array( __( 'Término de la ejecución vigente (con prórrogas).', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'months'                => array( __( 'Plazo de ejecución.', 'gestion-de-proyectos' ), 'meses' ),
				'fund_amount'           => array( __( 'Monto aportado por el otorgante (Fondo).', 'gestion-de-proyectos' ), $money ),
				'cash_amount'           => array( __( 'Aporte pecuniario de la entidad ejecutora.', 'gestion-de-proyectos' ), $money ),
				'inkind_amount'         => array( __( 'Aporte no pecuniario (valorizado, no se rinde con egresos).', 'gestion-de-proyectos' ), $money ),
				'guarantee_required'    => array( __( 'Indica si el convenio exige garantía de fiel cumplimiento (1 sí, 0 no).', 'gestion-de-proyectos' ) ),
				'platform'              => array( __( 'Plataforma de rendición.', 'gestion-de-proyectos' ) ),
				'platform_code'         => array( __( 'Código del proyecto en la plataforma de rendición.', 'gestion-de-proyectos' ) ),
				'platform_end_date'     => array( __( 'Fecha de término registrada en la plataforma.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'platform_render_until' => array( __( 'Último día en que la plataforma admite rendir.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'bank_account'          => array( __( 'Cuenta donde ingresan las transferencias.', 'gestion-de-proyectos' ) ),
				'cost_center'           => array( __( 'Centro de costo de la universidad que administra los fondos.', 'gestion-de-proyectos' ) ),
			),
			'finance_installments'   => array(
				'number'           => array( __( 'Número de cuota en el programa de desembolso.', 'gestion-de-proyectos' ) ),
				'amount'           => array( __( 'Monto de la cuota con cargo al Fondo.', 'gestion-de-proyectos' ), $money ),
				'share_pct'        => array( __( 'Porcentaje del Fondo que representa la cuota.', 'gestion-de-proyectos' ), '%' ),
				'window_from'      => array( __( 'Inicio de la ventana del programa de desembolso.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'window_to'        => array( __( 'Fin de la ventana del programa de desembolso.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'report_no'        => array( __( 'Informe de avance cuya aprobación habilita la cuota (0 si no aplica).', 'gestion-de-proyectos' ) ),
				'cash_amount'      => array( __( 'Aporte pecuniario que debe enterarse junto con la cuota.', 'gestion-de-proyectos' ), $money ),
				'cash_received_at' => array( __( 'Fecha en que el aporte pecuniario quedó acreditado.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'cash_receipt'     => array( __( 'Comprobante del aporte pecuniario.', 'gestion-de-proyectos' ) ),
				'requested_at'     => array( __( 'Fecha de la carta de solicitud de la transferencia.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'transferred_at'   => array( __( 'Fecha de la transferencia del otorgante.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'received_at'      => array( __( 'Fecha de ingreso a caja (y aceptación en la plataforma).', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'receipt_number'   => array( __( 'Número del comprobante de ingreso.', 'gestion-de-proyectos' ) ),
				'receipt_sent_at'  => array( __( 'Fecha de envío del comprobante de ingreso al otorgante.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'platform_status'  => array( __( 'Estado de la transferencia en la plataforma: pendiente, enviada, aceptada, rechazada.', 'gestion-de-proyectos' ) ),
			),
			'finance_items'          => array(
				'slug'              => array( __( 'Identificador del ítem (personal, subcontratos, capacitacion, difusion, generales, inversion, administracion).', 'gestion-de-proyectos' ) ),
				'item_group'        => array( __( 'Grupo del ítem en el presupuesto.', 'gestion-de-proyectos' ) ),
				'assigned_fund'     => array( __( 'Asignado vigente con cargo al Fondo.', 'gestion-de-proyectos' ), $money ),
				'assigned_cash'     => array( __( 'Asignado vigente con cargo al aporte pecuniario.', 'gestion-de-proyectos' ), $money ),
				'assigned_inkind'   => array( __( 'Asignado vigente del aporte no pecuniario.', 'gestion-de-proyectos' ), $money ),
				'platform_type'     => array( __( 'Tipo de gasto en la plataforma: personal, operacion o inversion.', 'gestion-de-proyectos' ) ),
				'platform_subclass' => array( __( 'Subclasificación del gasto en la plataforma.', 'gestion-de-proyectos' ) ),
				'budget_line'       => array( __( 'Partida presupuestaria del módulo de compras con la que se corresponde.', 'gestion-de-proyectos' ) ),
				'cap_rule'          => array( __( 'Regla de tope aplicable (personal_max, admin_max).', 'gestion-de-proyectos' ) ),
			),
			'finance_payments'       => array(
				'code'               => array( __( 'Código correlativo del pago (PG-0001).', 'gestion-de-proyectos' ) ),
				'purchase_id'        => array( __( 'Compra que origina el pago (0 si no hay).', 'gestion-de-proyectos' ) ),
				'supplier_id'        => array( __( 'Proveedor o persona a quien se paga.', 'gestion-de-proyectos' ) ),
				'source'             => array( __( 'Fuente: fondo (otorgante) o pecuniario (aporte de la universidad).', 'gestion-de-proyectos' ) ),
				'item_slug'          => array( __( 'Ítem del convenio al que se imputa.', 'gestion-de-proyectos' ) ),
				'commitment'         => array( __( 'Documento que compromete el gasto (orden de compra, contrato).', 'gestion-de-proyectos' ) ),
				'executed_at'        => array( __( 'Fecha de ejecución del gasto; controla el plazo de rendición.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'paid_at'            => array( __( 'Fecha de pago (egreso); define el mes de rendición.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'egress_number'      => array( __( 'Número del comprobante de egreso; puede repetirse si un egreso paga varios documentos.', 'gestion-de-proyectos' ) ),
				'egress_document_id' => array( __( 'Documento digitalizado del comprobante de egreso.', 'gestion-de-proyectos' ) ),
				'doc_type'           => array( __( 'Tipo de documento: factura, factura_exenta, boleta, boleta_honorarios, documento_extranjero, otro.', 'gestion-de-proyectos' ) ),
				'doc_number'         => array( __( 'Número del documento pagado.', 'gestion-de-proyectos' ) ),
				'doc_date'           => array( __( 'Fecha del documento pagado.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'amount'             => array( __( 'Monto pagado.', 'gestion-de-proyectos' ), $money ),
				'installment_no'     => array( __( 'Cuota a la que se imputa (0: imputación cronológica automática).', 'gestion-de-proyectos' ) ),
				'status'             => array( __( 'Estado: comprometido, devengado, pagado, rendido, observado, corregido, aprobado, rechazado.', 'gestion-de-proyectos' ) ),
				'rendition_id'       => array( __( 'Rendición en la que se presenta.', 'gestion-de-proyectos' ) ),
				'folio'              => array( __( 'Folio dentro de la carga masiva.', 'gestion-de-proyectos' ) ),
				'observation'        => array( __( 'Observación del otorgante.', 'gestion-de-proyectos' ) ),
				'observed_at'        => array( __( 'Fecha de la observación.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'support'            => array( __( 'Respaldos: lista de objetos {kind, document_id, note}.', 'gestion-de-proyectos' ) ),
			),
			'finance_renditions'     => array(
				'source'             => array( __( 'Fuente rendida: fondo o pecuniario.', 'gestion-de-proyectos' ) ),
				'period'             => array( __( 'Mes rendido.', 'gestion-de-proyectos' ), 'AAAA-MM' ),
				'kind'               => array( __( 'Tipo: mensual, sin_movimiento, regularizacion, final.', 'gestion-de-proyectos' ) ),
				'status'             => array( __( 'Estado declarado: preparada, enviada_universidad, en_carga, en_autenticacion, en_firma_interna, rendida, en_revision, aprobada, aprobada_parcial, devuelta.', 'gestion-de-proyectos' ) ),
				'internal_due'       => array( __( 'Plazo para entregar los respaldos a la universidad.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'platform_due'       => array( __( 'Plazo para enviar la rendición en la plataforma.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'fix_due'            => array( __( 'Plazo de subsanación de una rendición devuelta.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'sent_internal_at'   => array( __( 'Fecha de envío de los respaldos a la universidad.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'loaded_at'          => array( __( 'Fecha de carga en la plataforma.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'sent_at'            => array( __( 'Fecha de envío al otorgante (rendida).', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'approved_at'        => array( __( 'Fecha de aprobación.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'returned_at'        => array( __( 'Fecha de devolución con observaciones.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'report_document_id' => array( __( 'Informe o comprobante de la rendición.', 'gestion-de-proyectos' ) ),
				'letter_document_id' => array( __( 'Carta conductora (por ejemplo, de rendición sin movimiento).', 'gestion-de-proyectos' ) ),
			),
			'finance_events'         => array(
				'entity_type' => array( __( 'Entidad: project, installment, payment, rendition, guarantee o supplier.', 'gestion-de-proyectos' ) ),
				'entity_id'   => array( __( 'Identificador de la entidad (0 para el proyecto).', 'gestion-de-proyectos' ) ),
				'kind'        => array( __( 'estado (estado declarado) o paso (paso de una guía cumplido).', 'gestion-de-proyectos' ) ),
				'event_key'   => array( __( 'Estado o paso.', 'gestion-de-proyectos' ) ),
				'guide'       => array( __( 'Guía a la que pertenece el paso.', 'gestion-de-proyectos' ) ),
				'event_date'  => array( __( 'Fecha del hecho.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'document_id' => array( __( 'Documento que respalda el hecho.', 'gestion-de-proyectos' ) ),
			),
			'finance_guarantees'     => array(
				'kind'           => array( __( 'Tipo: fiel_cumplimiento, fraccion_no_rendida o prorroga.', 'gestion-de-proyectos' ) ),
				'instrument'     => array( __( 'Instrumento: boleta_garantia, poliza, vale_vista u otro.', 'gestion-de-proyectos' ) ),
				'number'         => array( __( 'Número del instrumento.', 'gestion-de-proyectos' ) ),
				'issuer'         => array( __( 'Emisor del instrumento.', 'gestion-de-proyectos' ) ),
				'amount'         => array( __( 'Monto garantizado.', 'gestion-de-proyectos' ), $money ),
				'issued_at'      => array( __( 'Fecha de emisión.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'valid_until'    => array( __( 'Vigencia.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'installment_no' => array( __( 'Cuota que habilita (garantía por fracción no rendida).', 'gestion-de-proyectos' ) ),
				'status'         => array( __( 'Estado: vigente, devuelta, cobrada o vencida.', 'gestion-de-proyectos' ) ),
			),
			'finance_cash_plans'     => array(
				'status'       => array( __( 'Estado: borrador, enviada, vigente, reemplazada.', 'gestion-de-proyectos' ) ),
				'submitted_at' => array( __( 'Fecha de envío a la Dirección de Investigación.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
			),
			'finance_cash_plan_rows' => array(
				'period'           => array( __( 'Mes programado.', 'gestion-de-proyectos' ), 'AAAA-MM' ),
				'transfer_planned' => array( __( 'Transferencia que se solicitará al otorgante en el mes.', 'gestion-de-proyectos' ), $money ),
				'spend_planned'    => array( __( 'Gasto programado con cargo al Fondo en el mes.', 'gestion-de-proyectos' ), $money ),
				'cash_planned'     => array( __( 'Aporte pecuniario a enterar en el mes.', 'gestion-de-proyectos' ), $money ),
				'milestone'        => array( __( 'Hito que respalda la programación del mes.', 'gestion-de-proyectos' ) ),
			),
			'finance_ledger'         => array(
				'source'         => array( __( 'Fuente: fondo o pecuniario.', 'gestion-de-proyectos' ) ),
				'entry_date'     => array( __( 'Fecha del movimiento en el centro de costo.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'reference'      => array( __( 'Referencia del movimiento (número de egreso, folio contable).', 'gestion-de-proyectos' ) ),
				'description'    => array( __( 'Glosa del movimiento.', 'gestion-de-proyectos' ) ),
				'debit'          => array( __( 'Cargo (salida de caja).', 'gestion-de-proyectos' ), $money ),
				'credit'         => array( __( 'Abono (ingreso de caja).', 'gestion-de-proyectos' ), $money ),
				'kind'           => array( __( 'Tipo: pago, transferencia, aporte, reintegro, cargo_bancario u otro.', 'gestion-de-proyectos' ) ),
				'payment_id'     => array( __( 'Pago emparejado.', 'gestion-de-proyectos' ) ),
				'installment_no' => array( __( 'Cuota emparejada (abonos).', 'gestion-de-proyectos' ) ),
				'status'         => array( __( 'Estado: conciliado, por_aclarar o excluido.', 'gestion-de-proyectos' ) ),
				'batch'          => array( __( 'Lote de importación.', 'gestion-de-proyectos' ) ),
			),
			'finance_rules'          => array(
				'rule_key'   => array( __( 'Clave de la regla del perfil de fondo.', 'gestion-de-proyectos' ) ),
				'value'      => array( __( 'Valor vigente para el proyecto.', 'gestion-de-proyectos' ) ),
				'source'     => array( __( 'Fuente del valor (convenio, correo, oficio).', 'gestion-de-proyectos' ) ),
				'valid_from' => array( __( 'Vigente desde.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'valid_to'   => array( __( 'Vigente hasta.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
			),
			'finance_modifications'  => array(
				'kind'         => array( __( 'Tipo: reitemizacion, redistribucion, personal, prorroga, reprogramacion u otra.', 'gestion-de-proyectos' ) ),
				'status'       => array( __( 'Estado: solicitada, aprobada o rechazada.', 'gestion-de-proyectos' ) ),
				'requested_at' => array( __( 'Fecha de solicitud.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'approved_at'  => array( __( 'Fecha del acto que la aprueba.', 'gestion-de-proyectos' ), 'AAAA-MM-DD' ),
				'act_number'   => array( __( 'Acto que la aprueba.', 'gestion-de-proyectos' ) ),
				'details'      => array( __( 'Detalle: lista de {item, source, delta, note}; en una reitemización la suma de los delta es cero.', 'gestion-de-proyectos' ) ),
			),
		);
	}

	/**
	 * Elimina los datos del módulo cuando se elimina un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public function cleanup_project( int $project_id ): void {
		global $wpdb;

		foreach ( CashPlanRepository::for_project( $project_id ) as $plan ) {
			CashPlanRepository::delete( (int) $plan['id'] );
		}
		foreach ( array( AgreementRepository::class, InstallmentRepository::class, ItemRepository::class, RulesRepository::class, PaymentRepository::class, RenditionRepository::class, EventRepository::class, GuaranteeRepository::class, LedgerRepository::class, ModificationRepository::class ) as $class ) {
			$class::delete_for_project( $project_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( \GDP\Core\Schema::table( 'finance_cash_plans' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}
}
