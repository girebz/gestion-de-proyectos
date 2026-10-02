<?php
/**
 * Pruebas de los plazos en días hábiles y de los controles de la programación de caja.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Finance;

use GDP\Modules\Finance\Logic\CashPlanChecks;
use GDP\Modules\Finance\Logic\Deadlines;
use GDP\Modules\Finance\Logic\InstallmentConditions;
use GDP\Planning\ChileHolidays;
use GDP\Planning\WorkCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Las fechas se contrastan con el calendario de 2026 y 2027 (feriados de Chile).
 */
final class DeadlinesTest extends TestCase {

	private function calendar(): WorkCalendar {
		$exceptions = array();
		foreach ( ChileHolidays::exceptions_for_years( 2025, 2027 ) as $date => $e ) {
			$exceptions[ $date ] = $e['working'];
		}

		return new WorkCalendar( array( 1, 2, 3, 4, 5 ), $exceptions );
	}

	public function test_nth_working_day_skips_weekends_and_holidays(): void {
		$calendar = $this->calendar();
		// Octubre de 2026: el lunes 12 es feriado.
		$this->assertSame( '2026-10-01', Deadlines::nth_working_day( '2026-10', 1, $calendar ) );
		$this->assertSame( '2026-10-13', Deadlines::nth_working_day( '2026-10', 8, $calendar ) );
		$this->assertSame( '2026-10-22', Deadlines::nth_working_day( '2026-10', 15, $calendar ) );
		// Noviembre y diciembre de 2026, enero de 2027 (cifras del documento de lineamientos).
		$this->assertSame( '2026-11-11', Deadlines::nth_working_day( '2026-11', 8, $calendar ) );
		$this->assertSame( '2026-11-20', Deadlines::nth_working_day( '2026-11', 15, $calendar ) );
		$this->assertSame( '2026-12-11', Deadlines::nth_working_day( '2026-12', 8, $calendar ) );
		$this->assertSame( '2026-12-22', Deadlines::nth_working_day( '2026-12', 15, $calendar ) );
		$this->assertSame( '2027-01-13', Deadlines::nth_working_day( '2027-01', 8, $calendar ) );
		$this->assertSame( '2027-01-22', Deadlines::nth_working_day( '2027-01', 15, $calendar ) );
	}

	public function test_rendition_due_is_in_the_following_month(): void {
		$due = Deadlines::rendition_due( '2026-09', 8, 15, $this->calendar() );
		$this->assertSame( array( 'internal' => '2026-10-13', 'platform' => '2026-10-22' ), $due );
		$this->assertSame( '2026-11-20', Deadlines::rho( '2026-10', 15, $this->calendar() ) );
	}

	public function test_months_arithmetic(): void {
		$this->assertSame( '2027-01', Deadlines::add_months( '2026-12', 1 ) );
		$this->assertSame( '2026-11', Deadlines::add_months( '2026-12', -1 ) );
		$this->assertSame( '2026-10-31', Deadlines::month_end( '2026-10' ) );
		$this->assertSame( array( '2026-11', '2026-12', '2027-01' ), Deadlines::months_between( '2026-11', '2027-01' ) );
		$this->assertSame( array(), Deadlines::months_between( '2027-02', '2027-01' ) );
	}

	public function test_latest_payment_month_counts_backwards_from_target(): void {
		$calendar = $this->calendar();
		// Giro deseado el 30/12/2026, 15 días de revisión y 10 de solicitud: rho(oct)=20/11 + 25 = 15/12 cabe; rho(nov)=22/12 + 25 no cabe.
		$this->assertSame( '2026-10', Deadlines::latest_payment_month( '2026-12-30', 15, 10, 15, $calendar ) );
		// Sin desfases, noviembre cabe (22/12).
		$this->assertSame( '2026-11', Deadlines::latest_payment_month( '2026-12-30', 0, 0, 15, $calendar ) );
		// Un objetivo imposible dentro del piso devuelve null.
		$this->assertNull( Deadlines::latest_payment_month( '2026-01-05', 30, 30, 15, $calendar, '2026-01' ) );
	}

	public function test_working_days_left_is_signed(): void {
		$calendar = $this->calendar();
		// Del 2 al 13 de octubre de 2026 median seis días hábiles (el 12 es feriado).
		$this->assertSame( 6, Deadlines::working_days_left( '2026-10-02', '2026-10-13', $calendar ) );
		$this->assertSame( -6, Deadlines::working_days_left( '2026-10-13', '2026-10-02', $calendar ) );
	}

	public function test_cash_plan_of_the_project_passes_sums_but_fails_feasibility(): void {
		$calendar = $this->calendar();
		$rows     = array(
			array( 'period' => '2026-09', 'transfer' => 0.0, 'spend' => 2000000.0 ),
			array( 'period' => '2026-10', 'transfer' => 59997000.0, 'spend' => 8333645.0 ),
			array( 'period' => '2026-11', 'transfer' => 0.0, 'spend' => 40583204.0 ),
			array( 'period' => '2026-12', 'transfer' => 0.0, 'spend' => 10000000.0 ),
			array( 'period' => '2027-03', 'transfer' => 19999000.0, 'spend' => 20000000.0 ),
			array( 'period' => '2027-04', 'transfer' => 0.0, 'spend' => 17168532.0 ),
		);
		$due = array();
		foreach ( array( '2026-09', '2026-10', '2026-11', '2026-12', '2027-03', '2027-04' ) as $m ) {
			$due[ $m ] = Deadlines::rho( $m, 15, $calendar );
		}
		$state = array(
			'fund_total'  => 99994000.0,
			'transferred' => 19998000.0,
			'spent'       => 1908619.0,
			'amounts'     => array( 1 => 19998000.0, 2 => 59997000.0, 3 => 19999000.0 ),
			'received'    => array( 1 ),
			'windows'     => array( 2 => array( '2026-07-01', '2026-12-31' ), 3 => array( '2027-01-01', '2027-06-30' ) ),
			'due'         => $due,
			'lag_days'    => 25,
		);
		$checks = array();
		foreach ( CashPlanChecks::evaluate( $rows, $state ) as $c ) {
			$checks[ $c['key'] ] = $c;
		}
		$this->assertTrue( $checks['C1']['ok'], $checks['C1']['detail'] );
		$this->assertTrue( $checks['C2']['ok'], $checks['C2']['detail'] );
		$this->assertTrue( $checks['C3']['ok'], $checks['C3']['detail'] );
		$this->assertFalse( $checks['C4']['ok'], $checks['C4']['detail'] );
		$this->assertStringContainsString( 'Cuota 2 programada en 2026-10', $checks['C4']['detail'] );
		$this->assertStringContainsString( '2026-11', $checks['C4']['detail'] );
		$this->assertStringContainsString( 'Cuota 3 programada en 2027-03', $checks['C4']['detail'] );
		$this->assertTrue( $checks['C5']['ok'], $checks['C5']['detail'] );
		$this->assertNull( $checks['C6']['ok'] );

		// Postergar la cuota 2 a diciembre vuelve la caja negativa en noviembre (52.825.468 > 19.998.000).
		$rows[1]['transfer'] = 0.0;
		$rows[3]['transfer'] = 59997000.0;
		$checks              = array();
		foreach ( CashPlanChecks::evaluate( $rows, $state ) as $c ) {
			$checks[ $c['key'] ] = $c;
		}
		$this->assertFalse( $checks['C3']['ok'] );
		$this->assertStringContainsString( '2026-11', $checks['C3']['detail'] );

		// Con garantía declarada por la cuota 2 y la cuota 3 movida a junio de 2027, C4 y C5 se cumplen.
		$rows[1]['transfer'] = 59997000.0;
		$rows[3]['transfer'] = 0.0;
		$rows[4]['transfer'] = 0.0;
		$rows[]              = array( 'period' => '2027-06', 'transfer' => 19999000.0, 'spend' => 0.0 );
		$state['due']['2027-06'] = Deadlines::rho( '2027-06', 15, $calendar );
		$state['guarantees'] = array( 2 => 17189381.0 );
		$checks              = array();
		foreach ( CashPlanChecks::evaluate( $rows, $state ) as $c ) {
			$checks[ $c['key'] ] = $c;
		}
		$this->assertTrue( $checks['C4']['ok'], $checks['C4']['detail'] );
		$this->assertTrue( $checks['C5']['ok'], $checks['C5']['detail'] );
	}

	public function test_installment_conditions_with_the_project_state(): void {
		$ctx = array(
			'next'               => 2,
			'target'             => 19998000.0,
			'rendered'           => 2808619.0,
			'approved'           => 0.0,
			'criterion'          => 'ambos',
			'guarantee'          => 0.0,
			'report_approved'    => null,
			'report_no'          => 1,
			'cash_amount'        => 3336600.0,
			'cash_received'      => false,
			'request_letter'     => null,
			'overdue_renditions' => array( '2026-08' ),
			'receipts_pending'   => array(),
			'window'             => array( '2026-07-01', '2026-12-31' ),
			'today'              => '2026-10-02',
		);
		$conditions = array();
		foreach ( InstallmentConditions::evaluate( $ctx ) as $c ) {
			$conditions[ $c['key'] ] = $c;
		}
		$this->assertFalse( $conditions['G1']['ok'] );
		$this->assertStringContainsString( '17.189.381', $conditions['G1']['detail'] );
		$this->assertNull( $conditions['G2']['ok'] );
		$this->assertFalse( $conditions['G3']['ok'] );
		$this->assertFalse( $conditions['G5']['ok'] );
		$this->assertTrue( $conditions['G6']['ok'] );
		$this->assertTrue( $conditions['G7']['ok'] );
		$this->assertFalse( InstallmentConditions::all_met( InstallmentConditions::evaluate( $ctx ) ) );

		$ctx['guarantee']          = 19998000.0;
		$ctx['cash_received']      = true;
		$ctx['overdue_renditions'] = array();
		$ctx['report_approved']    = true;
		$ctx['request_letter']     = true;
		$this->assertTrue( InstallmentConditions::all_met( InstallmentConditions::evaluate( $ctx ) ) );

		// La condición con criterio "presentado" se cumple con lo rendido aunque nada esté aprobado.
		$ctx['guarantee'] = 0.0;
		$ctx['rendered']  = 19998000.0;
		$ctx['criterion'] = 'presentado';
		$this->assertTrue( InstallmentConditions::all_met( InstallmentConditions::evaluate( $ctx ) ) );
		$ctx['criterion'] = 'aprobado';
		$this->assertFalse( InstallmentConditions::all_met( InstallmentConditions::evaluate( $ctx ) ) );
	}
}
