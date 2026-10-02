<?php
/**
 * Pruebas del emparejamiento de cargos de la cartola con pagos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Finance;

use GDP\Modules\Finance\LedgerRepository;
use PHPUnit\Framework\TestCase;

/**
 * Septiembre del proyecto de relaves: trece boletas de 300.000 pagadas con
 * un solo egreso (4512) y una factura pagada con su propio egreso.
 */
final class LedgerMatchTest extends TestCase {

	/**
	 * Pagos de prueba.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function payments(): array {
		$out = array( array( 'id' => 1, 'amount' => 408619.0, 'egress_number' => '4400', 'paid_at' => '2026-07-31' ) );
		for ( $k = 0; $k < 13; $k++ ) {
			$out[] = array( 'id' => 10 + $k, 'amount' => 300000.0, 'egress_number' => '4512', 'paid_at' => '2026-09-30' );
		}

		return $out;
	}

	public function test_single_payment_by_egress_and_amount(): void {
		$this->assertSame( array( 1 ), LedgerRepository::match_debit( $this->payments(), array(), 408619.0, '4400', '2026-07-31' ) );
	}

	public function test_one_egress_paying_several_documents_matches_the_group(): void {
		$match = LedgerRepository::match_debit( $this->payments(), array(), 3900000.0, '4512', '2026-09-30' );
		$this->assertCount( 13, $match );
		$this->assertSame( 10, $match[0] );
	}

	public function test_partial_sum_of_an_egress_does_not_match_the_group(): void {
		$this->assertSame( array(), LedgerRepository::match_debit( $this->payments(), array(), 3600000.0, '4512', '2026-09-30' ) );
	}

	public function test_without_reference_falls_back_to_amount_and_date(): void {
		$this->assertSame( array( 10 ), LedgerRepository::match_debit( $this->payments(), array(), 300000.0, '', '2026-10-05' ) );
		$this->assertSame( array(), LedgerRepository::match_debit( $this->payments(), array(), 300000.0, '', '2026-11-30' ), 'Más de quince días entre el pago y el cargo.' );
		$this->assertSame( array( 11 ), LedgerRepository::match_debit( $this->payments(), array( 10 => true ), 300000.0, '', '2026-09-30' ), 'Un pago ya emparejado no se vuelve a usar.' );
	}
}
