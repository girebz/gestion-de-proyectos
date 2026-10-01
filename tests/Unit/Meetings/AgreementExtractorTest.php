<?php
/**
 * Pruebas de la propuesta automática de acuerdos a partir de un texto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Meetings;

use GDP\Modules\Meetings\AgreementExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Frases con marcas de acuerdo, viñetas bajo un encabezado, responsables
 * (asistentes o nombres en la frase) y plazos absolutos y relativos.
 */
final class AgreementExtractorTest extends TestCase {

	private const ATTENDEES = array( 'Inés Valdés', 'Mercedes López', 'Cristian Sánchez' );

	public function test_bullets_under_heading_are_high_confidence(): void {
		$text = "Resumen de la reunión.\nSe revisó el avance.\n\nAcuerdos:\n- Inés Valdés enviará el protocolo de muestreo antes del 30 de septiembre.\n- Mercedes deberá confirmar la fecha de recepción en la próxima reunión.\n- Revisar el convenio con la universidad.\n\nOtros temas:\nNada más que informar.";
		$out  = AgreementExtractor::extract( $text, '2026-09-21', self::ATTENDEES );

		$this->assertCount( 3, $out );
		$this->assertSame( 'Inés Valdés enviará el protocolo de muestreo antes del 30 de septiembre', $out[0]['description'] );
		$this->assertSame( 'Inés Valdés', $out[0]['owner_name'] );
		$this->assertSame( '2026-09-30', $out[0]['due_date'] );
		$this->assertSame( 'alta', $out[0]['confidence'] );
		// Primer nombre de un asistente y plazo "próxima reunión" (una semana).
		$this->assertSame( 'Mercedes López', $out[1]['owner_name'] );
		$this->assertSame( '2026-09-28', $out[1]['due_date'] );
		// Viñeta sin marca: confianza media, sin responsable ni plazo.
		$this->assertSame( 'Revisar el convenio con la universidad', $out[2]['description'] );
		$this->assertSame( '', $out[2]['owner_name'] );
		$this->assertNull( $out[2]['due_date'] );
		$this->assertSame( 'media', $out[2]['confidence'] );
	}

	public function test_sentences_with_cues_in_prose(): void {
		$text = 'Se presentó el avance del proyecto. Se acuerda que Cristian Sánchez coordinará el transporte de las muestras en dos semanas. El informe quedó a cargo de Pedro Rojas para el 15/10/2026. La reunión terminó a las once.';
		$out  = AgreementExtractor::extract( $text, '2026-09-21', self::ATTENDEES );

		$this->assertCount( 2, $out );
		$this->assertSame( 'Cristian Sánchez coordinará el transporte de las muestras en dos semanas', $out[0]['description'] );
		$this->assertSame( 'Cristian Sánchez', $out[0]['owner_name'] );
		$this->assertSame( '2026-10-05', $out[0]['due_date'] );
		$this->assertSame( 'media', $out[0]['confidence'] );
		// Nombre que no es asistente, tomado de "a cargo de", y fecha con barras.
		$this->assertSame( 'Pedro Rojas', $out[1]['owner_name'] );
		$this->assertSame( '2026-10-15', $out[1]['due_date'] );
	}

	public function test_dates_and_relative_deadlines(): void {
		$cases = array(
			'Se acuerda entregar el informe el 2026-11-03.'      => '2026-11-03',
			'Juan enviará la carta el 5 de enero.'              => '2027-01-05',
			'Se acuerda revisar el borrador en 10 días.'        => '2026-10-01',
			'La minuta se enviará dentro de un mes.'            => '2026-10-21',
			'Pedro presentará el resumen la próxima semana.'    => '2026-09-28',
			'Se acuerda continuar con el plan sin fecha.'       => null,
		);
		foreach ( $cases as $sentence => $expected ) {
			$out = AgreementExtractor::extract( $sentence, '2026-09-21', array() );
			$this->assertCount( 1, $out, $sentence );
			$this->assertSame( $expected, $out[0]['due_date'], $sentence );
		}
	}

	public function test_transcript_markers_and_duplicates_are_cleaned(): void {
		$text = "[00:12:03] Cristian: Se acuerda que Inés enviará la propuesta mañana mismo.\n00:15 - Inés: se acuerda que Inés enviará la propuesta mañana mismo.\n1) Mercedes revisará los resultados de laboratorio.";
		$out  = AgreementExtractor::extract( $text, '2026-09-21', self::ATTENDEES );

		$this->assertCount( 2, $out );
		$this->assertSame( 'Inés enviará la propuesta mañana mismo', $out[0]['description'] );
		$this->assertSame( 'Inés Valdés', $out[0]['owner_name'] );
		$this->assertSame( 'Mercedes revisará los resultados de laboratorio', $out[1]['description'] );
		$this->assertSame( 'Mercedes López', $out[1]['owner_name'] );
	}

	public function test_text_without_cues_yields_nothing(): void {
		$this->assertSame( array(), AgreementExtractor::extract( "Se conversó sobre el clima.\nLa sesión fue breve.", '2026-09-21', self::ATTENDEES ) );
	}
}
