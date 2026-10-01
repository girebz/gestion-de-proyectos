<?php
/**
 * Pruebas de la aritmética de la unidad de fomento.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Procurement;

use GDP\Modules\Procurement\UfMath;
use PHPUnit\Framework\TestCase;

/**
 * Conversión, valor implícito, tolerancia, antigüedad e impuesto, incluida la
 * instancia verificable de la especificación (73,99 unidades de fomento).
 */
final class UfMathTest extends TestCase {

	public function test_conversion(): void {
		$this->assertSame( 3026191.0, UfMath::to_clp( 73.99, 40900.0 ) );
		$this->assertSame( 73.99, UfMath::to_uf( 3026191.0, 40900.0 ) );
		$this->assertSame( 0.0, UfMath::to_uf( 1000.0, 0.0 ) );
	}

	public function test_implied_rate_and_tolerance(): void {
		$implied = UfMath::implied_rate( 3026212.0, 73.99 );
		$this->assertSame( 40900.28, $implied );
		$this->assertSame( 0.0, UfMath::deviation_percent( 40900.28, 40900.0 ) );
		$this->assertTrue( UfMath::within_tolerance( 40900.28, 40900.0, 1.0 ) );
		$this->assertFalse( UfMath::within_tolerance( 43000.0, 40900.0, 1.0 ) );
		$this->assertSame( 5.13, UfMath::deviation_percent( 43000.0, 40900.0 ) );
		$this->assertNull( UfMath::implied_rate( 1000.0, 0.0 ) );
	}

	public function test_age_and_tax(): void {
		$this->assertSame( 102, UfMath::age_days( '2026-06-20', '2026-09-30' ) );
		$this->assertSame( -1, UfMath::age_days( '2026-06-21', '2026-06-20' ) );
		$this->assertSame( 0, UfMath::age_days( 'x', '2026-06-20' ) );
		$this->assertSame( 119.0, UfMath::with_tax( 100.0, 19.0 ) );
		$this->assertSame( 94.7121, UfMath::with_tax( 79.59, 19.0 ) );
	}
}
