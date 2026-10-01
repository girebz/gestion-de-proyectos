<?php
/**
 * Pruebas del escapado LaTeX de las plantillas de carta.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Documents;

use GDP\Modules\Documents\LetterTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Los caracteres especiales de LaTeX se escapan y el resto se conserva.
 */
final class LetterTemplateTest extends TestCase {

	public function test_escape_special_characters(): void {
		$this->assertSame( '50\% \& más \$ \# \_ \{ \}', LetterTemplate::escape( '50% & más $ # _ { }' ) );
		$this->assertSame( '\textbackslash{}a\textasciitilde{}b\textasciicircum{}', LetterTemplate::escape( '\a~b^' ) );
	}

	public function test_escape_keeps_accents_and_newlines(): void {
		$this->assertSame( "Señor Ñandú\nlínea", LetterTemplate::escape( "Señor Ñandú\nlínea" ) );
		$this->assertSame( '', LetterTemplate::escape( '' ) );
	}
}
