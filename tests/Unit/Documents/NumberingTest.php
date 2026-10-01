<?php
/**
 * Pruebas de la numeración correlativa de documentos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Documents;

use GDP\Modules\Documents\Numbering;
use PHPUnit\Framework\TestCase;

/**
 * Patrones, ceros a la izquierda, año y prefijos por defecto.
 */
final class NumberingTest extends TestCase {

	public function test_default_pattern(): void {
		$this->assertSame( 'CARTA-007/2026', Numbering::format( '', 'CARTA', 7, 2026 ) );
		$this->assertSame( 'CARTA-007/2026', Numbering::format( Numbering::DEFAULT_PATTERN, 'CARTA', 7, 2026 ) );
	}

	public function test_markers(): void {
		$this->assertSame( 'OC 12 (26) relaves', Numbering::format( '{PREFIJO} {N} ({AA}) {PROYECTO}', 'OC', 12, 2026, 'relaves' ) );
		$this->assertSame( '0005', Numbering::format( '{NNNN}', 'X', 5, 2026 ) );
		$this->assertSame( '1234', Numbering::format( '{NN}', 'X', 1234, 2026 ) );
		$this->assertSame( 'sin marcadores', Numbering::format( 'sin marcadores', 'X', 1, 2026 ) );
	}

	public function test_yearly_detection(): void {
		$this->assertTrue( Numbering::yearly( '{PREFIJO}-{NNN}/{AAAA}' ) );
		$this->assertTrue( Numbering::yearly( '{N}-{AA}' ) );
		$this->assertFalse( Numbering::yearly( '{PREFIJO}-{NNNN}' ) );
		$this->assertTrue( Numbering::yearly( '' ) );
	}

	public function test_default_prefix(): void {
		$this->assertSame( 'CARTA', Numbering::default_prefix( 'carta' ) );
		$this->assertSame( 'OC', Numbering::default_prefix( 'orden_compra' ) );
		$this->assertSame( 'SDC', Numbering::default_prefix( 'solicitud-de-cotizacion' ) );
		$this->assertSame( 'INFORM', Numbering::default_prefix( 'informe' ) );
	}
}
