<?php
/**
 * Pruebas de la imputación de pagos a cuotas y de las identidades de cuadratura.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Finance;

use GDP\Modules\Finance\Logic\GapCalculator;
use GDP\Modules\Finance\Logic\Imputation;
use GDP\Modules\Finance\Logic\Reconciliation;
use PHPUnit\Framework\TestCase;

/**
 * Cifras del proyecto de relaves: tres cuotas de 19.998.000, 59.997.000 y
 * 19.999.000; pagado al 30/09/2026, 5.808.619.
 */
final class ImputationTest extends TestCase {

	private const AMOUNTS = array( 1 => 19998000.0, 2 => 59997000.0, 3 => 19999000.0 );

	public function test_fifo_splits_cumulative_in_order(): void {
		$split = Imputation::fifo( 5808619.0, self::AMOUNTS );
		$this->assertSame( array( 1 => 5808619.0, 2 => 0.0, 3 => 0.0 ), $split );

		$split = Imputation::fifo( 25000000.0, self::AMOUNTS );
		$this->assertSame( 19998000.0, $split[1] );
		$this->assertSame( 5002000.0, $split[2] );
		$this->assertSame( 0.0, $split[3] );

		$split = Imputation::fifo( 120000000.0, self::AMOUNTS );
		$this->assertSame( self::AMOUNTS, $split );
	}

	public function test_installment_for_a_payment_follows_chronology(): void {
		$this->assertSame( 1, Imputation::installment_for( 0.0, self::AMOUNTS ) );
		$this->assertSame( 1, Imputation::installment_for( 19997999.0, self::AMOUNTS ) );
		$this->assertSame( 2, Imputation::installment_for( 19998000.0, self::AMOUNTS ) );
		$this->assertSame( 3, Imputation::installment_for( 79995000.0, self::AMOUNTS ) );
		$this->assertSame( 3, Imputation::installment_for( 999999999.0, self::AMOUNTS ) );
		$this->assertSame( 0, Imputation::installment_for( 10.0, array() ) );
	}

	public function test_declared_imputation_cannot_exceed_installment(): void {
		$this->assertSame( array(), Imputation::excess( array( 1 => 19998000.0 ), self::AMOUNTS ) );
		$this->assertSame( array( 1 => 2000.0 ), Imputation::excess( array( 1 => 20000000.0, 2 => 100.0 ), self::AMOUNTS ) );
	}

	public function test_cumulative_to_installment(): void {
		$this->assertSame( 19998000.0, Imputation::cumulative_to( self::AMOUNTS, 1 ) );
		$this->assertSame( 79995000.0, Imputation::cumulative_to( self::AMOUNTS, 2 ) );
		$this->assertSame( 99994000.0, Imputation::cumulative_to( self::AMOUNTS, 3 ) );
	}

	public function test_gaps_of_the_project_example(): void {
		$gaps = GapCalculator::gaps( self::AMOUNTS, 1, 5808619.0, 2808619.0 );
		$this->assertSame( 19998000.0, $gaps['target'] );
		$this->assertSame( 14189381.0, $gaps['pay_gap'] );
		$this->assertSame( 17189381.0, $gaps['render_gap'] );
		$this->assertSame( 17189381.0, $gaps['guarantee'] );

		$gaps = GapCalculator::gaps( self::AMOUNTS, 1, 25000000.0, 19998000.0 );
		$this->assertSame( 0.0, $gaps['pay_gap'] );
		$this->assertSame( 0.0, $gaps['render_gap'] );
	}

	public function test_cumulative_series_and_month_reaching(): void {
		$series = GapCalculator::cumulate( array( '2026-09' => 2000000.0, '2026-10' => 8333645.0, '2026-11' => 40583204.0 ), 1908619.0 );
		$this->assertSame( array( '2026-09' => 3908619.0, '2026-10' => 12242264.0, '2026-11' => 52825468.0 ), $series );
		$this->assertSame( '2026-11', GapCalculator::month_reaching( $series, 19998000.0 ) );
		$this->assertNull( GapCalculator::month_reaching( $series, 79995000.0 ) );
	}

	public function test_cover_selects_candidates_until_gap_is_closed(): void {
		$candidates = array( array( 'id' => 1, 'amount' => 9000000.0 ), array( 'id' => 2, 'amount' => 4000000.0 ), array( 'id' => 3, 'amount' => 3000000.0 ) );
		$result     = GapCalculator::cover( $candidates, 14189381.0 );
		$this->assertCount( 3, $result['selected'] );
		$this->assertSame( 16000000.0, $result['covered'] );
		$this->assertSame( 0.0, $result['remaining'] );

		$result = GapCalculator::cover( array_slice( $candidates, 0, 2 ), 14189381.0 );
		$this->assertSame( 1189381.0, $result['remaining'] );
	}

	public function test_reconciliation_identities(): void {
		$this->assertSame( 15301581.0, Reconciliation::balance( 19998000.0, 1112200.0, 5808619.0, 0.0, 0.0 ) );
		$this->assertSame( -1000.0, Reconciliation::difference( 15300581.0, 15301581.0 ) );
		$this->assertTrue( Reconciliation::non_negative( 19998000.0, 19998000.0 ) );
		$this->assertFalse( Reconciliation::non_negative( 19998000.0, 52825468.0 ) );
		$this->assertSame( 1500000.0, Reconciliation::available( 10500000.0, 6000000.0, 2500000.0, 500000.0 ) );
		$this->assertSame( 494000.0, Reconciliation::closing_difference( 99994000.0, 99500000.0, 0.0 ) );
		$this->assertSame( 0.0, Reconciliation::closing_difference( 99994000.0, 99500000.0, 494000.0 ) );
	}
}
