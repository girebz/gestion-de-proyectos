<?php
/**
 * Pruebas de las hojas del libro Excel del estado financiero.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Finance;

use GDP\Core\Workbook;
use GDP\Modules\Finance\Logic\BoardMetrics;
use GDP\Modules\Finance\Logic\WorkbookSheets;
use GDP\Modules\Finance\Profiles\Profiles;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZipArchive;

/**
 * El libro reúne todo el estado financiero en hojas fijas; los totales,
 * acumulados y resúmenes son fórmulas que citan bien sus rangos y traen el
 * mismo valor que calcula el módulo.
 */
final class WorkbookSheetsTest extends TestCase {

	public function test_sheets_follow_the_fixed_order(): void {
		$sheets = WorkbookSheets::build( self::data() );
		$this->assertSame( array_values( WorkbookSheets::names() ), array_column( $sheets, 'name' ) );
		$this->assertCount( 20, $sheets );
		$this->assertSame( array_keys( WorkbookSheets::descriptions() ), array_slice( array_keys( WorkbookSheets::names() ), 1 ) );
	}

	public function test_every_formula_has_its_tokens_resolved_and_a_cached_number(): void {
		$count = 0;
		foreach ( WorkbookSheets::build( self::data() ) as $sheet ) {
			foreach ( $sheet['rows'] as $row ) {
				foreach ( $row as $cell ) {
					if ( is_array( $cell ) && isset( $cell['f'] ) ) {
						++$count;
						$this->assertSame( 0, preg_match( '/\{(r|p|first|last)\}/', (string) $cell['f'] ), $sheet['name'] . ': ' . $cell['f'] );
						$this->assertTrue( is_int( $cell['v'] ) || is_float( $cell['v'] ), $sheet['name'] . ': ' . $cell['f'] );
					}
				}
			}
		}
		$this->assertGreaterThan( 40, $count );
	}

	public function test_payments_are_sorted_by_code_and_totalled_with_subtotal(): void {
		$sheet = self::sheet( 'payments' );
		$this->assertSame( 3, $sheet['header'] );
		$codes = array_map( static fn( array $r ) => $r[0], array_slice( $sheet['rows'], 4, 3 ) );
		$this->assertSame( array( 'PG-0001', 'PG-0002', 'PG-0010' ), $codes );
		$this->assertSame( 'Monto', $sheet['rows'][3][13] );
		$total = end( $sheet['rows'] );
		$this->assertSame( 'SUBTOTAL(109,N5:N7)', $total[13]['f'] );
		$this->assertSame( 1850000.0, $total[13]['v'] );
		$this->assertSame( 6, $sheet['filter_last'] );
		// Etapa para agrupar, clave de rendición, número de documento como número.
		$first = $sheet['rows'][4];
		$this->assertSame( 'Pagado', $first[16] );
		$this->assertSame( 'junio de 2026 · mensual', $first[17] );
		$this->assertSame( array( 'v' => 136, 's' => 'code' ), $first[8] );
		$this->assertSame( array( 'v' => '2026-06-10', 's' => 'date' ), $first[11] );
		$this->assertSame( 'Por pagar', $sheet['rows'][5][16] );
	}

	public function test_status_and_supplier_summaries_point_at_the_payments_sheet(): void {
		$by_status = self::sheet( 'by_status' );
		$paid      = $by_status['rows'][6];
		$this->assertSame( 'Pagado', $paid[0] );
		$this->assertSame( "SUMIFS('Pagos'!\$N\$5:\$N\$7,'Pagos'!\$P\$5:\$P\$7,\$A7,'Pagos'!\$B\$5:\$B\$7,\"Fondo (otorgante)\")", $paid[1]['f'] );
		$this->assertSame( 600000.0, $paid[1]['v'] );
		$this->assertSame( 1, $paid[2]['v'] );
		$this->assertSame( 'B7+D7', $paid[5]['f'] );

		$suppliers = self::sheet( 'suppliers' );
		$row       = $suppliers['rows'][4];
		$this->assertSame( 'Laboratorio Uno SpA', $row[0] );
		$this->assertSame( array( 'v' => 'Sí', 's' => 'ok' ), $row[2] );
		$this->assertSame( "SUMIFS('Pagos'!\$N\$5:\$N\$7,'Pagos'!\$D\$5:\$D\$7,\$A5,'Pagos'!\$Q\$5:\$Q\$7,\"Por pagar\")", $row[6]['f'] );
		$this->assertSame( 1250000.0, $row[6]['v'] );
	}

	public function test_items_compute_available_and_used_by_formula(): void {
		$sheet = self::sheet( 'items' );
		$row   = $sheet['rows'][4];
		$this->assertSame( 'Personal', $row[1] );
		$this->assertSame( 'C5-D5-E5-F5', $row[7]['f'] );
		$this->assertSame( 400000.0, $row[7]['v'] );
		$this->assertSame( 'IF(C5>0,(D5+E5)/C5,0)', $row[8]['f'] );
		$this->assertSame( 0.6, $row[8]['v'] );
		$this->assertSame( 'Operación · Subcontratos', $sheet['rows'][5][1] );
		$total = end( $sheet['rows'] );
		$this->assertSame( 'IF(C7>0,(D7+E7)/C7,0)', $total[8]['f'] );
		$this->assertSame( 'total_pct', $total[8]['s'] );
	}

	public function test_cash_sheet_rebuilds_the_curve_and_the_real_cash(): void {
		$data  = self::data();
		$curve = $data['curve'];
		$sheet = self::sheet( 'cash', $data );
		$this->assertSame( 7, $sheet['header'] );
		// El preámbulo deja su valor en la columna F, bajo el transferido acumulado.
		$this->assertSame( 0.0, $sheet['rows'][3][5]['v'] );
		$this->assertSame( '', $sheet['rows'][3][1] );
		$real = 0.0;
		foreach ( $curve['months'] as $i => $m ) {
			$row = $sheet['rows'][8 + $i];
			$r   = 9 + $i;
			$this->assertSame( $m, $row[1] );
			$this->assertSame( (float) $curve['transfers'][ $i ], $row[5]['v'] );
			$this->assertSame( 0 === $i ? '$F$4+E9' : 'F' . ( $r - 1 ) . '+E' . $r, $row[5]['f'] );
			if ( null === $curve['paid'][ $i ] ) {
				$this->assertSame( '', $row[10] );
				$this->assertSame( '', $row[11] );
				continue;
			}
			$real += (float) ( $data['received'][ $m ] ?? 0 );
			$this->assertSame( (float) $curve['paid'][ $i ], $row[10]['v'] );
			$this->assertSame( '$F$4+SUM(C$9:C' . $r . ')-K' . $r, $row[11]['f'] );
			$this->assertSame( round( $real - (float) $curve['paid'][ $i ], 2 ), $row[11]['v'] );
		}
		// Lo programado y no recibido en el mes en curso se proyecta en ese mes.
		$october = $sheet['rows'][8 + array_search( '2026-10', $curve['months'], true )];
		$this->assertSame( 'Sí', $october[6]['v'] );
		$this->assertSame( 300000.0, $october[4]['v'] );
		$this->assertSame( 750000.0, $october[5]['v'] );
		// La caja real no cuenta lo proyectado: 450.000 recibidos menos 600.000 pagados.
		$this->assertSame( -150000.0, $october[11]['v'] );
		// El gasto programado se acumula sobre lo pagado antes del plan.
		$this->assertSame( '$F$6+H18', $october[8]['f'] );
		$this->assertSame( 720000.0, $october[8]['v'] );
	}

	public function test_summary_counts_overdue_months_and_alerts_by_formula(): void {
		$sheet = self::sheet( 'summary' );
		$found = array();
		foreach ( $sheet['rows'] as $row ) {
			if ( isset( $row[1]['f'] ) && str_starts_with( (string) $row[1]['f'], 'COUNTIF(' ) ) {
				$found[ $row[0]['v'] ] = $row[1];
			}
		}
		$overdue = $found['Rendiciones vencidas sin estado declarado'];
		$this->assertSame( "COUNTIF('Rendiciones mes a mes'!\$L\$5:\$L\$7,\"Sí\")", $overdue['f'] );
		$this->assertSame( 1, $overdue['v'] );
		$this->assertSame( "COUNTIF('Alertas'!\$A\$5:\$A\$6,\"Atención inmediata\")", $found['Alertas: atención inmediata']['f'] );
		$this->assertSame( 1, $found['Alertas: atención inmediata']['v'] );
		// Vínculos del índice a cada hoja.
		$this->assertContains( "'Pagos'!A1", array_column( $sheet['links'], 'location' ) );
		$this->assertFalse( $sheet['gridlines'] );
	}

	public function test_agreement_sheet_leaves_out_the_bank_account(): void {
		$sheet = self::sheet( 'agreement' );
		$flat  = wp_json_encode( $sheet['rows'] );
		$this->assertFalse( str_contains( (string) $flat, '00-123-45678' ) );
		$this->assertTrue( str_contains( (string) $flat, 'Programa de prueba' ) );
	}

	public function test_empty_project_builds_every_sheet_with_a_notice(): void {
		$data                                 = self::data();
		$data['payments']                     = array();
		$data['renditions']                   = array();
		$data['events']                       = array();
		$data['alerts']                       = array();
		$data['actions']                      = array();
		$data['status']['cash_plan']          = null;
		$data['status']['next_installment']   = null;
		$data['status']['items']              = array();
		$data['status']['renditions']         = array();
		$data['status']['installments']       = array();
		$data['curve']                        = BoardMetrics::cash_curve( array(), array(), array(), array(), '2026-10' );
		$sheets                               = WorkbookSheets::build( $data );
		$this->assertCount( 20, $sheets );
		$payments = $sheets[6];
		$this->assertSame( array( 'v' => 'Sin pagos registrados.', 's' => 'note' ), $payments['rows'][4][0] );
		$this->assertFalse( $payments['filter'] );
		$this->assertCount( 5, $payments['rows'] );
	}

	public function test_text_criteria_neutralize_wildcards_operators_and_quotes(): void {
		$method = new ReflectionMethod( WorkbookSheets::class, 'text_criterion' );
		$this->assertSame( 'a~*b~?c~~', $method->invoke( null, 'a*b?c~' ) );
		$this->assertSame( '=>5', $method->invoke( null, '>5' ) );
		$this->assertSame( 'dice ""sí""', $method->invoke( null, 'dice "sí"' ) );
	}

	public function test_written_workbook_opens_as_a_package_with_twenty_sheets(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'Sin la extensión zip.' );
		}
		$binary = Workbook::write( WorkbookSheets::build( self::data() ) );
		$this->assertIsString( $binary );
		$path = (string) tempnam( sys_get_temp_dir(), 'gdp-prueba' );
		file_put_contents( $path, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path ) );
		$this->assertNotSame( false, $zip->getFromName( 'xl/worksheets/sheet20.xml' ) );
		$this->assertFalse( $zip->getFromName( 'xl/worksheets/sheet21.xml' ) );
		$workbook = (string) $zip->getFromName( 'xl/workbook.xml' );
		$zip->close();
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertStringContainsString( '<sheet name="Rendiciones mes a mes" sheetId="10" r:id="rId10"/>', $workbook );
	}

	// ------------------------------------------------------------------ Datos de prueba

	/**
	 * Hoja por su clave.
	 *
	 * @param string                   $key  Clave (ver WorkbookSheets::names()).
	 * @param array<string,mixed>|null $data Datos.
	 * @return array<string,mixed>
	 */
	private static function sheet( string $key, ?array $data = null ): array {
		$index = array_search( $key, array_keys( WorkbookSheets::names() ), true );

		return WorkbookSheets::build( $data ?? self::data() )[ $index ];
	}

	/**
	 * Proyecto pequeño y completo: dos cuotas, dos ítems, tres pagos, una
	 * rendición registrada, tres meses en la línea de tiempo y un plan de caja.
	 *
	 * @return array<string,mixed>
	 */
	private static function data(): array {
		$profile  = Profiles::get( 'frpd_coquimbo_sisrec' );
		$months   = BoardMetrics::months( '2026-01', '2026-12' );
		$paid     = array(
			'fondo'      => array(
				'2026-03' => 200000.0,
				'2026-06' => 400000.0,
			),
			'pecuniario' => array(),
		);
		$received = array( '2026-01' => 450000.0 );
		$plan     = array(
			array( 'period' => '2026-10', 'transfer' => 300000.0, 'spend' => 120000.0, 'cash' => 0.0, 'milestone' => 'Informe 1' ),
			array( 'period' => '2026-11', 'transfer' => 0.0, 'spend' => 80000.0, 'cash' => 50000.0, 'milestone' => 'Equipos' ),
		);
		$fund     = array( 'assigned' => 1000000.0, 'paid' => 600000.0, 'committed' => 0.0, 'rejected' => 0.0, 'rendered' => 600000.0, 'available' => 400000.0 );
		$none     = array( 'assigned' => 0.0, 'paid' => 0.0, 'committed' => 0.0, 'rejected' => 0.0, 'rendered' => 0.0, 'available' => 0.0 );
		$sub      = array( 'assigned' => 500000.0, 'paid' => 0.0, 'committed' => 1250000.0, 'rejected' => 0.0, 'rendered' => 0.0, 'available' => -750000.0 );
		$payment  = static fn( int $id, string $code, string $status, float $amount, int $supplier, int $rendition, string $doc, string $paid_at ): array => array(
			'id'             => $id,
			'code'           => $code,
			'source'         => 'fondo',
			'item_slug'      => 'personal',
			'supplier_id'    => $supplier,
			'purchase_id'    => 0,
			'description'    => 'Pago ' . $code,
			'commitment'     => 'Contrato',
			'doc_type'       => 'factura',
			'doc_number'     => $doc,
			'doc_date'       => '',
			'executed_at'    => '',
			'paid_at'        => $paid_at,
			'egress_number'  => '',
			'amount'         => $amount,
			'installment_no' => 0,
			'status'         => $status,
			'rendition_id'   => $rendition,
			'folio'          => 0,
			'observation'    => '',
			'observed_at'    => '',
			'support'        => array(),
			'notes'          => '',
		);

		return array(
			'project'       => array( 'id' => 1, 'code' => '01', 'name' => 'Proyecto de prueba' ),
			'today'         => '2026-10-02',
			'profile'       => $profile,
			'status'        => array(
				'agreement'        => array(
					'funder'             => 'Gobierno Regional',
					'program'            => 'Programa de prueba',
					'fund_amount'        => 1500000.0,
					'cash_amount'        => 100000.0,
					'inkind_amount'      => 50000.0,
					'start_date'         => '2026-01-15',
					'end_date'           => '2027-01-15',
					'months'             => 12,
					'guarantee_required' => true,
					'bank_account'       => '00-123-45678',
					'notes'              => '',
				),
				'profile_label'    => $profile->label(),
				'sources'          => array(
					'fondo'      => array( 'label' => 'Fondo (otorgante)', 'total' => 1500000.0, 'received' => 450000.0, 'paid' => 600000.0, 'committed' => 1250000.0, 'rendered' => 600000.0, 'approved' => 600000.0, 'observed' => 0.0, 'balance' => -150000.0, 'to_receive' => 1050000.0, 'ledger_count' => 0 ),
					'pecuniario' => array( 'label' => 'Aporte pecuniario', 'total' => 100000.0, 'received' => 0.0, 'paid' => 0.0, 'committed' => 0.0, 'rendered' => 0.0, 'approved' => 0.0, 'observed' => 0.0, 'balance' => 0.0, 'to_receive' => 100000.0, 'ledger_count' => 0 ),
				),
				'next_installment' => null,
				'installments'     => array(
					array( 'id' => 7, 'number' => 1, 'amount' => 450000.0, 'share_pct' => 30.0, 'window_from' => '2026-01-01', 'window_to' => '2026-06-30', 'report_no' => 0, 'cash_amount' => 0.0, 'is_received' => true, 'received_on' => '2026-01-20', 'receipt_number' => '0045', 'platform_status' => 'aceptada', 'paid' => 450000.0, 'rendered' => 450000.0, 'approved' => 450000.0, 'notes' => '' ),
					array( 'id' => 8, 'number' => 2, 'amount' => 1050000.0, 'share_pct' => 70.0, 'window_from' => '2026-07-01', 'window_to' => '2026-12-31', 'report_no' => 1, 'cash_amount' => 0.0, 'is_received' => false, 'received_on' => '', 'receipt_number' => '', 'platform_status' => 'pendiente', 'paid' => 150000.0, 'rendered' => 150000.0, 'approved' => 150000.0, 'notes' => '' ),
				),
				'items'            => array(
					array( 'slug' => 'personal', 'label' => 'Personal', 'platform_type' => 'personal', 'platform_subclass' => 'Personal', 'cap' => null, 'sources' => array( 'fondo' => $fund, 'pecuniario' => $none ) ),
					array( 'slug' => 'subcontratos', 'label' => 'Subcontratos', 'platform_type' => 'operacion', 'platform_subclass' => 'Subcontratos', 'cap' => null, 'sources' => array( 'fondo' => $sub, 'pecuniario' => $none ) ),
				),
				'renditions'       => array(
					array( 'period' => '2026-06', 'amount' => 400000.0, 'expected_kind' => 'mensual', 'rendition' => array( 'id' => 5, 'kind' => 'mensual', 'status' => 'aprobada', 'fix_due' => '' ), 'internal_due' => '2026-07-10', 'platform_due' => '2026-07-22', 'submitted' => true, 'days_left' => 0, 'overdue' => false, 'current' => false ),
					array( 'period' => '2026-07', 'amount' => 0.0, 'expected_kind' => 'sin_movimiento', 'rendition' => null, 'internal_due' => '2026-08-12', 'platform_due' => '2026-08-21', 'submitted' => false, 'days_left' => -30, 'overdue' => true, 'current' => false ),
					array( 'period' => '2026-10', 'amount' => 0.0, 'expected_kind' => 'sin_movimiento', 'rendition' => null, 'internal_due' => '2026-11-11', 'platform_due' => '2026-11-20', 'submitted' => false, 'days_left' => 33, 'overdue' => false, 'current' => true ),
				),
				'cash_plan'        => array(
					'plan'   => array( 'id' => 3, 'name' => 'Plan 2026', 'status' => 'enviada', 'submitted_at' => '2026-09-14' ),
					'rows'   => $plan,
					'checks' => array( array( 'key' => 'C1', 'label' => 'Suma de transferencias', 'ok' => true, 'detail' => 'Programado $300.000.' ) ),
				),
				'rules'            => array(
					'plazo_sisrec'     => array( 'label' => 'Carga en la plataforma', 'value' => '15', 'source' => 'Bases 21.1', 'layer' => 'perfil' ),
					'fecha_limite_giro' => array( 'label' => 'Fecha objetivo', 'value' => '2026-12-31', 'source' => 'Resolución', 'layer' => 'convenio', 'valid_from' => '2026-10-02' ),
				),
				'reitemizations'   => array( 'used' => 1, 'max' => 3 ),
			),
			'payments'      => array(
				$payment( 3, 'PG-0010', 'comprometido', 1250000.0, 11, 0, '', '' ),
				$payment( 1, 'PG-0001', 'pagado', 600000.0, 10, 5, '136', '2026-06-10' ),
				$payment( 2, 'PG-0002', 'devengado', 0.0, 0, 0, 'F-0007', '' ),
			),
			'effective'     => array( 1 => 1 ),
			'suppliers'     => array(
				10 => array( 'id' => 10, 'name' => 'Persona Uno', 'tax_id' => '11.111.111-1' ),
				11 => array( 'id' => 11, 'name' => 'Laboratorio Uno SpA', 'tax_id' => '76.000.000-0' ),
			),
			'registered'    => array(
				10 => false,
				11 => true,
			),
			'purchases'     => array(),
			'documents'     => array(),
			'docs'          => true,
			'issues'        => array( 1 => array( array( 'severity' => 'bloquea', 'message' => 'Falta la boleta.' ) ) ),
			'items_labels'  => array(
				'personal'     => 'Personal',
				'subcontratos' => 'Subcontratos',
			),
			'items_rows'    => array(),
			'renditions'    => array(
				array( 'id' => 5, 'period' => '2026-06', 'kind' => 'mensual', 'source' => 'fondo', 'status' => 'aprobada', 'approved_at' => '2026-08-01', 'notes' => '' ),
			),
			'events'        => array(
				array( 'id' => 1, 'event_date' => '2026-08-01', 'entity_type' => 'rendition', 'entity_id' => 5, 'kind' => 'estado', 'event_key' => 'aprobada', 'guide' => '', 'note' => 'Oficio', 'document_id' => 0, 'user' => 'Ana' ),
			),
			'ledger'        => array(),
			'received'      => $received,
			'paid_by_month' => $paid,
			'curve'         => BoardMetrics::cash_curve( $months, $paid['fondo'], $received, $plan, '2026-10' ),
			'modifications' => array(),
			'guarantees'    => array(),
			'actions'       => array(
				array( 'severity' => 'alta', 'title' => 'Presentar la rendición de julio', 'due' => '2026-08-21', 'days_left' => -30, 'detail' => 'Atrasada.', 'guide' => '' ),
			),
			'alerts'        => array(
				array( 'severity' => 'critical', 'text' => 'Una rendición vencida.', 'tab' => 'rendiciones' ),
				array( 'severity' => 'warning', 'text' => 'Compromisos sobre lo transferido.', 'tab' => 'pagos' ),
			),
			'elapsed'       => array( 'pct' => 71.2, 'month' => 9, 'months' => 12 ),
			'labels'        => array(
				'ledger'        => array( 'kinds' => array(), 'statuses' => array() ),
				'guarantees'    => array( 'kinds' => array(), 'instruments' => array(), 'statuses' => array() ),
				'modifications' => array( 'kinds' => array(), 'statuses' => array() ),
			),
		);
	}
}
