<?php
/**
 * Pruebas de los cálculos y gráficos del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Finance;

use GDP\Modules\Finance\Board\BoardCharts;
use GDP\Modules\Finance\Logic\BoardMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Cifras derivadas del estado de cuentas: uso de cada fuente, avance de las
 * cuotas, plazo transcurrido, ejes, curva de caja, franja de rendiciones,
 * alertas y textos; y el marcado de los gráficos que las dibujan.
 */
final class BoardMetricsTest extends TestCase {

	private const NB = "\u{00A0}";

	public function test_money_percent_and_date_formats(): void {
		$this->assertSame( '$' . self::NB . '1.308.619', BoardMetrics::money( 1308619.4 ) );
		$this->assertSame( '-$' . self::NB . '1.235', BoardMetrics::money( -1234.6 ) );
		$this->assertSame( '$' . self::NB . '0', BoardMetrics::money( 0.0 ) );
		$this->assertSame( 12.3, BoardMetrics::pct( 12.34, 100.0 ) );
		$this->assertNull( BoardMetrics::pct( 5.0, 0.0 ) );
		$this->assertSame( '12,3' . self::NB . '%', BoardMetrics::pct_label( 12.34 ) );
		$this->assertSame( '—', BoardMetrics::pct_label( null ) );
		$this->assertSame( '02/10/2026', BoardMetrics::date( '2026-10-02' ) );
		$this->assertSame( '02/10/2026', BoardMetrics::date( '2026-10-02 13:45:00' ) );
		$this->assertSame( '—', BoardMetrics::date( '' ) );
		$this->assertSame( '—', BoardMetrics::date( null ) );
	}

	public function test_display_text_unifies_amounts_dates_and_months_only(): void {
		$text = BoardMetrics::display_text( 'Faltan $3.336.600 antes del 2026-10-13; atrasadas: 2026-01, 2026-12; pago PG-0004; plan 2026-2027; código 2026-13.' );
		$this->assertSame( 'Faltan $' . self::NB . '3.336.600 antes del 13/10/2026; atrasadas: 01/2026, 12/2026; pago PG-0004; plan 2026-2027; código 2026-13.', $text );
		// Un texto ya unificado no cambia.
		$this->assertSame( $text, BoardMetrics::display_text( $text ) );
	}

	public function test_usage_splits_paid_committed_and_free_with_received_marker(): void {
		$u = BoardMetrics::usage( 100.0, 20.0, 30.0, 40.0 );
		$this->assertSame( 100.0, $u['base'] );
		$this->assertSame( 50.0, $u['free'] );
		$this->assertSame( 0.0, $u['over'] );
		$this->assertSame( 10.0, $u['uncovered'] );
		$this->assertSame( 40.0, $u['received_pct'] );
		$this->assertSame( array( 'paid', 'committed', 'free' ), array_column( $u['segments'], 'key' ) );
		$this->assertSame( array( 20.0, 30.0, 50.0 ), array_column( $u['segments'], 'pct' ) );
	}

	public function test_usage_over_the_total_rescales_and_reports_the_excess(): void {
		$u = BoardMetrics::usage( 100.0, 80.0, 40.0, 150.0 );
		$this->assertSame( 120.0, $u['base'] );
		$this->assertSame( 20.0, $u['over'] );
		$this->assertSame( 0.0, $u['free'] );
		$this->assertSame( 0.0, $u['uncovered'] );
		$this->assertSame( 100.0, $u['received_pct'] );
		$this->assertLessThan( 0.02, abs( 100.0 - array_sum( array_column( $u['segments'], 'pct' ) ) ) );
		$empty = BoardMetrics::usage( 0.0, 0.0, 0.0, 0.0 );
		$this->assertNull( $empty['received_pct'] );
		$this->assertSame( array( 0.0, 0.0, 0.0 ), array_column( $empty['segments'], 'pct' ) );
	}

	public function test_installment_segments_nest_approved_rendered_paid_and_clamp(): void {
		$s = BoardMetrics::installment_segments( 100.0, 60.0, 40.0, 10.0 );
		$this->assertSame( array( 'approved', 'rendered', 'paid', 'pending' ), array_column( $s, 'key' ) );
		$this->assertSame( array( 10.0, 30.0, 20.0, 40.0 ), array_column( $s, 'amount' ) );
		// Aprobado por sobre lo rendido y pagado por sobre la cuota se acotan.
		$c = BoardMetrics::installment_segments( 100.0, 130.0, 20.0, 50.0 );
		$this->assertSame( array( 50.0, 0.0, 50.0, 0.0 ), array_column( $c, 'amount' ) );
		$this->assertSame( 100.0, array_sum( array_column( $c, 'pct' ) ) );
	}

	public function test_elapsed_and_full_months(): void {
		$e = BoardMetrics::elapsed( '2026-01-15', '2028-01-15', '2026-10-02' );
		$this->assertSame( 35.6, $e['pct'] );
		$this->assertSame( 9, $e['month'] );
		$this->assertSame( 24, $e['months'] );
		$this->assertSame( 470, $e['days_left'] );
		$before = BoardMetrics::elapsed( '2026-01-15', '2028-01-15', '2025-12-01' );
		$this->assertSame( 0.0, $before['pct'] );
		$this->assertSame( 0, $before['month'] );
		$after = BoardMetrics::elapsed( '2026-01-15', '2028-01-15', '2029-01-01' );
		$this->assertSame( 100.0, $after['pct'] );
		$this->assertSame( 24, $after['month'] );
		$this->assertNull( BoardMetrics::elapsed( '', '2028-01-15', '2026-10-02' ) );
		$this->assertNull( BoardMetrics::elapsed( '2028-01-15', '2026-01-15', '2026-10-02' ) );
		$this->assertSame( 8, BoardMetrics::full_months( '2026-01-15', '2026-10-02' ) );
		$this->assertSame( 9, BoardMetrics::full_months( '2026-01-15', '2026-10-15' ) );
		$this->assertSame( 0, BoardMetrics::full_months( '2026-10-02', '2026-10-02' ) );
	}

	public function test_axis_ticks_unit_and_numbers(): void {
		$this->assertSame( array( 0.0, 25000000.0, 50000000.0, 75000000.0, 100000000.0 ), BoardMetrics::ticks( 99994000.0 ) );
		$this->assertSame( array( 0.0, 2.0, 4.0, 6.0, 8.0 ), BoardMetrics::ticks( 7.0 ) );
		$this->assertSame( array( 0.0, 1.0 ), BoardMetrics::ticks( 0.0 ) );
		$this->assertSame( 1000000.0, BoardMetrics::axis_unit( 2500000.0 )['div'] );
		$this->assertSame( 1000.0, BoardMetrics::axis_unit( 900000.0 )['div'] );
		$this->assertSame( '2,5', BoardMetrics::axis_number( 2.5 ) );
		$this->assertSame( '100', BoardMetrics::axis_number( 100.0 ) );
		$this->assertSame( '1.250', BoardMetrics::axis_number( 1250.0 ) );
	}

	public function test_months_between_periods(): void {
		$this->assertSame( array( '2025-11', '2025-12', '2026-01', '2026-02' ), BoardMetrics::months( '2025-11', '2026-02' ) );
		$this->assertSame( array( '2026-05' ), BoardMetrics::months( '2026-05', '2026-05' ) );
		$this->assertSame( array(), BoardMetrics::months( '2026-05', '2026-01' ) );
		$this->assertSame( array(), BoardMetrics::months( '2026-5', '2026-06' ) );
	}

	public function test_cash_curve_accumulates_and_projects_the_programmed_transfers(): void {
		$months = BoardMetrics::months( '2026-01', '2026-06' );
		$plan   = array(
			array( 'period' => '2026-04', 'transfer' => 500, 'spend' => 200 ),
			array( 'period' => '2026-05', 'transfer' => 0, 'spend' => 300 ),
			array( 'period' => '2026-06', 'transfer' => 300, 'spend' => 100 ),
		);
		$c      = BoardMetrics::cash_curve( $months, array( '2026-02' => 100.0, '2026-03' => 50.0 ), array( '2026-01' => 1000.0 ), $plan, '2026-04' );
		$this->assertSame( array( 0.0, 100.0, 150.0, 150.0, null, null ), $c['paid'] );
		// El gasto programado parte de lo pagado antes del primer mes del plan.
		$this->assertSame( array( null, null, null, 350.0, 650.0, 750.0 ), $c['planned'] );
		// La transferencia del mes en curso que todavía no llega se proyecta en ese mes.
		$this->assertSame( array( 1000.0, 1000.0, 1000.0, 1500.0, 1500.0, 1800.0 ), $c['transfers'] );
		$this->assertSame( 3, $c['projected_from'] );
		$this->assertSame( 1000.0, $c['received_to_date'] );
		$this->assertSame( 1800.0, $c['max'] );
	}

	public function test_cash_curve_counts_what_was_paid_before_the_axis(): void {
		$c = BoardMetrics::cash_curve( array( '2026-03', '2026-04' ), array( '2026-01' => 70.0, '2026-04' => 30.0 ), array( '2025-12' => 500.0 ), array(), '2026-04' );
		$this->assertSame( array( 70.0, 100.0 ), $c['paid'] );
		$this->assertSame( array( 500.0, 500.0 ), $c['transfers'] );
		$this->assertSame( array( null, null ), $c['planned'] );
		$this->assertSame( 1, $c['projected_from'] );
	}

	public function test_rendition_cells_states(): void {
		$timeline = array(
			array( 'period' => '2026-06', 'rendition' => array( 'status' => 'aprobada', 'kind' => 'mensual' ), 'current' => false, 'overdue' => false, 'amount' => 300 ),
			array( 'period' => '2026-07', 'rendition' => array( 'status' => 'en_revision', 'kind' => 'mensual' ), 'current' => false, 'overdue' => false ),
			array( 'period' => '2026-08', 'rendition' => array( 'status' => 'devuelta', 'kind' => 'mensual', 'fix_due' => '2026-10-20' ), 'current' => false, 'overdue' => false ),
			array( 'period' => '2026-09', 'rendition' => null, 'current' => false, 'overdue' => true, 'expected_kind' => 'sin_movimiento' ),
			array( 'period' => '2026-10', 'rendition' => null, 'current' => true, 'overdue' => false ),
			array( 'period' => '2026-11', 'rendition' => null, 'current' => false, 'overdue' => false ),
			array( 'period' => '2026-12', 'rendition' => array( 'status' => 'en_preparacion', 'kind' => 'mensual' ), 'current' => false, 'overdue' => false ),
		);
		$cells    = BoardMetrics::rendition_cells( $timeline, array( 'aprobada' => 'Aprobada' ) );
		$this->assertSame( array( 'ok', 'sent', 'bad', 'bad', 'current', 'todo', 'progress' ), array_column( $cells, 'state' ) );
		$this->assertSame( 'Aprobada', $cells[0]['label'] );
		$this->assertSame( 'Vencida sin declarar', $cells[3]['label'] );
		$this->assertSame( 'sin_movimiento', $cells[3]['kind'] );
		$this->assertSame( '2026-10-20', $cells[2]['fix_due'] );
		$this->assertSame( 300.0, $cells[0]['amount'] );
	}

	public function test_alerts_cover_the_situations_and_sort_by_severity(): void {
		$status = array(
			'today'              => '2026-10-02',
			'agreement'          => array( 'fund_amount' => 100, 'start_date' => '2026-01-15' ),
			'overdue_renditions' => array( '2026-02', '2026-01' ),
			'renditions'         => array(),
			'sources'            => array(
				'fondo' => array( 'label' => 'Fondo', 'received' => 20, 'paid' => 10, 'committed' => 15, 'observed' => 5, 'non_negative' => true, 'difference' => null ),
			),
			'items'              => array(
				array( 'label' => 'Personal', 'sources' => array( 'fondo' => array( 'available' => -3 ) ), 'cap' => null ),
			),
			'next_installment'   => array(
				'number'            => 2,
				'gaps'              => array( 'pay_gap' => 10, 'render_gap' => 12, 'guarantee' => 12 ),
				'latest_month'      => '2026-12',
				'latest_render_due' => '2027-01-22',
				'conditions'        => array( array( 'key' => 'G3', 'ok' => false, 'detail' => 'Falta enterar $3.000 antes del 2026-10-13.' ) ),
			),
			'installments'       => array( array( 'number' => 1, 'is_received' => true, 'receipt_sent_at' => null ) ),
			'cash_plan'          => array( 'checks' => array( array( 'key' => 'C2', 'ok' => false ), array( 'key' => 'C6', 'ok' => null ) ) ),
		);
		$guarantees = array( array( 'status' => 'vigente', 'valid_until' => '2026-10-20', 'amount' => 50 ) );
		$alerts     = BoardMetrics::alerts( $status, $guarantees );
		$severities = array_column( $alerts, 'severity' );
		$this->assertSame( array( 'critical', 'critical', 'warning', 'warning', 'warning', 'warning', 'warning', 'warning', 'info' ), $severities );
		$texts = implode( "\n", array_column( $alerts, 'text' ) );
		$this->assertStringContainsString( '2 rendiciones mensuales vencidas sin estado declarado (enero a febrero de 2026)', $texts );
		$this->assertStringContainsString( 'El ítem Personal supera su asignado en $' . self::NB . '3', $texts );
		$this->assertStringContainsString( 'Falta enterar $' . self::NB . '3.000 antes del 13/10/2026.', $texts );
		$this->assertStringContainsString( 'controles C2.', $texts );
		$this->assertStringContainsString( 'Una garantía vence el 20/10/2026', $texts );
		$this->assertStringContainsString( 'supera lo transferido en $' . self::NB . '5', $texts );
		// La brecha de la cuota con meses útiles por delante es advertencia, no crítica.
		$gap = array_values( array_filter( $alerts, static fn( array $a ): bool => str_starts_with( $a['text'], 'Para la cuota 2' ) ) );
		$this->assertSame( 'warning', $gap[0]['severity'] );
		$this->assertSame( 'cuotas', $gap[0]['tab'] );
	}

	public function test_alerts_without_agreement(): void {
		$alerts = BoardMetrics::alerts( array( 'today' => '2026-10-02', 'agreement' => array() ) );
		$this->assertSame( 'critical', $alerts[0]['severity'] );
		$this->assertSame( 'convenio', $alerts[0]['tab'] );
	}

	public function test_period_and_month_labels(): void {
		$this->assertSame( 'enero a agosto de 2026', BoardMetrics::periods_label( array( '2026-03', '2026-01', '2026-02', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08' ) ) );
		$this->assertSame( 'noviembre de 2025 a enero de 2026', BoardMetrics::periods_label( array( '2025-11', '2025-12', '2026-01' ) ) );
		$this->assertSame( 'enero y marzo de 2026', BoardMetrics::periods_label( array( '2026-03', '2026-01' ) ) );
		$this->assertSame( 'febrero a mayo y agosto de 2026', BoardMetrics::periods_label( array( '2026-02', '2026-03', '2026-04', '2026-05', '2026-08' ) ) );
		$this->assertSame( 'enero, marzo y diciembre de 2026; febrero de 2027', BoardMetrics::periods_label( array( '2026-01', '2026-03', '2026-12', '2027-02' ) ) );
		$this->assertSame( 'sin movimiento', BoardMetrics::rendition_kind_label( 'sin_movimiento' ) );
		$this->assertSame( 'otro', BoardMetrics::rendition_kind_label( 'otro' ) );
		$this->assertSame( 'agosto de 2026', BoardMetrics::periods_label( array( '2026-08' ) ) );
		$this->assertSame( '', BoardMetrics::periods_label( array() ) );
		$this->assertSame( 'oct 26', BoardMetrics::month_short( '2026-10' ) );
		$this->assertSame( 'septiembre', BoardMetrics::month_name( '2026-09' ) );
	}

	public function test_charts_markup_uses_the_styled_classes_and_carries_a_table(): void {
		$usage = BoardCharts::usage(
			BoardMetrics::usage( 100.0, 20.0, 30.0, 40.0 ),
			'Uso del Fondo',
			array( 'paid' => 'Pagado', 'committed' => 'Comprometido', 'free' => 'Libre', 'received' => 'Transferido' )
		);
		$this->assertStringContainsString( 'gdp-fin-seg--paid', $usage );
		$this->assertStringContainsString( 'gdp-fin-seg--committed', $usage );
		$this->assertStringContainsString( 'gdp-fin-marker', $usage );
		$this->assertStringContainsString( 'role="img"', $usage );
		$this->assertSame( 4, substr_count( $usage, '<th scope="row">' ) );

		$installments = BoardCharts::installments(
			array(
				array( 'number' => 1, 'amount' => 100, 'is_received' => true, 'received_on' => '2026-01-15', 'paid' => 60, 'rendered' => 40, 'approved' => 10, 'window_from' => '', 'window_to' => '', 'report_no' => 0 ),
				array( 'number' => 2, 'amount' => 300, 'is_received' => false, 'received_on' => '', 'paid' => 0, 'rendered' => 0, 'approved' => 0, 'window_from' => '2026-07-01', 'window_to' => '2026-12-31', 'report_no' => 1 ),
			),
			array( 'approved' => 'Aprobado', 'rendered' => 'Rendido', 'paid' => 'Pagado', 'pending' => 'Por pagar' )
		);
		foreach ( array( 'approved', 'rendered', 'paid', 'pending' ) as $key ) {
			$this->assertStringContainsString( 'gdp-fin-seg--inst-' . $key, $installments );
			$this->assertStringContainsString( 'gdp-fin-swatch--inst-' . $key, $installments );
		}
		$this->assertStringContainsString( 'gdp-fin-stack--future', $installments );

		$curve = BoardCharts::cash_curve(
			BoardMetrics::cash_curve(
				BoardMetrics::months( '2026-01', '2026-06' ),
				array( '2026-02' => 100.0 ),
				array( '2026-01' => 1000.0 ),
				array( array( 'period' => '2026-05', 'transfer' => 500, 'spend' => 200 ) ),
				'2026-04'
			),
			'2026-04'
		);
		$this->assertStringContainsString( 'gdp-fin-line__s--projected', $curve );
		$this->assertStringContainsString( 'gdp-fin-line__today', $curve );
		$this->assertStringContainsString( 'data-gdp-curve=', $curve );
		$this->assertSame( 6, substr_count( $curve, '<th scope="row">' ) );
		$this->assertStringContainsString( 'text-anchor="end">jun 26</text>', $curve );
		$this->assertSame( '', BoardCharts::cash_curve( BoardMetrics::cash_curve( array( '2026-01' ), array(), array(), array(), '2026-01' ), '2026-01' ) );
	}
}
