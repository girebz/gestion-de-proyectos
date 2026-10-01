<?php
/**
 * Propuesta automática de acuerdos a partir de un texto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

/**
 * Lee un resumen o una transcripción de reunión en español y propone
 * acuerdos para revisión: toma las frases con marcas de compromiso ("se
 * acuerda", "a cargo de", "deberá", "enviará"...) y las viñetas bajo
 * encabezados como "Acuerdos" o "Compromisos", y de cada una intenta extraer
 * el responsable (contrastado con los asistentes) y el plazo (fechas
 * numéricas, "15 de octubre", "en dos semanas"). Es determinista y no depende
 * de WordPress; el asistente conectado puede proponer acuerdos mejores por la
 * misma vía de operaciones.
 */
final class AgreementExtractor {

	private const CUES = '/\b(se acuerda|se acordó|acordamos|se compromete|compromiso|queda a cargo|a cargo de|responsable|deberá|deberán|debe |deben |enviará|entregará|presentará|revisará|coordinará|gestionará|preparará|solicitará|elaborará|redactará|convocará|hará llegar|quedó de|se encargará|pendiente de|tarea)\b/iu';

	private const HEADINGS = '/^\s*(acuerdos?|compromisos?|tareas?|pendientes?|próximos pasos|proximos pasos|acciones)\s*[:.]?\s*$/iu';

	private const MONTHS = array( 'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12 );

	/**
	 * Propone acuerdos.
	 *
	 * @param string   $text         Resumen o transcripción.
	 * @param string   $meeting_date Fecha de la reunión (Y-m-d) para los plazos relativos.
	 * @param string[] $attendees    Nombres de los asistentes.
	 * @return array<int,array{description:string,owner_name:string,due_date:string|null,confidence:string,source:string}>
	 */
	public static function extract( string $text, string $meeting_date, array $attendees = array() ): array {
		$out   = array();
		$seen  = array();
		$lines = preg_split( '/\r\n|\r|\n/', $text ) ?: array();
		$in_block = false;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				$in_block = false;
				continue;
			}
			if ( preg_match( self::HEADINGS, $line ) ) {
				$in_block = true;
				continue;
			}
			$is_bullet = (bool) preg_match( '/^([-*•]|\d+[.)])\s+/u', $line );
			$body      = preg_replace( '/^([-*•]|\d+[.)])\s+/u', '', $line );
			$body      = preg_replace( '/^\[?\d{1,2}:\d{2}(:\d{2})?\]?\s*[-–]?\s*/u', '', (string) $body );
			$body      = preg_replace( '/^[A-ZÁÉÍÓÚÑ][\wÁÉÍÓÚÑáéíóúñ .]{1,40}:\s+(?=[a-záéíóúñA-ZÁÉÍÓÚÑ])/u', '', (string) $body );
			$sentences = $in_block && $is_bullet ? array( $body ) : ( preg_split( '/(?<=[.;!?])\s+(?=[A-ZÁÉÍÓÚÑ¿¡"])/u', (string) $body ) ?: array( $body ) );
			foreach ( $sentences as $sentence ) {
				$sentence = trim( (string) $sentence, " \t\"'" );
				if ( mb_strlen( $sentence ) < 12 ) {
					continue;
				}
				$cue = (bool) preg_match( self::CUES, $sentence );
				if ( ! $cue && ! ( $in_block && $is_bullet ) ) {
					continue;
				}
				$key = mb_strtolower( preg_replace( '/\s+/u', ' ', $sentence ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array(
					'description' => self::clean_description( $sentence ),
					'owner_name'  => self::owner( $sentence, $attendees ),
					'due_date'    => self::due_date( $sentence, $meeting_date ),
					'confidence'  => $cue && $in_block ? 'alta' : ( $cue || $in_block ? 'media' : 'baja' ),
					'source'      => $sentence,
				);
			}
		}

		return $out;
	}

	/**
	 * Limpia la frase para usarla como descripción.
	 *
	 * @param string $sentence Frase.
	 * @return string
	 */
	private static function clean_description( string $sentence ): string {
		$s = preg_replace( '/^(se acuerda que|se acordó que|se acuerda|se acordó|acordamos que|acordamos|acuerdo:?)\s*/iu', '', $sentence );
		$s = trim( (string) $s, " .;" );

		return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
	}

	/**
	 * Responsable a partir de la frase, preferentemente un asistente.
	 *
	 * @param string   $sentence  Frase.
	 * @param string[] $attendees Asistentes.
	 * @return string
	 */
	private static function owner( string $sentence, array $attendees ): string {
		// Primero, un asistente nombrado en la frase (nombre completo o primer nombre).
		foreach ( $attendees as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			if ( false !== mb_stripos( $sentence, $name ) ) {
				return $name;
			}
			$first = explode( ' ', $name )[0];
			if ( mb_strlen( $first ) >= 3 && preg_match( '/\b' . preg_quote( $first, '/' ) . '\b/iu', $sentence ) ) {
				return $name;
			}
		}
		$patterns = array(
			'/(?:a cargo de|responsable:?|queda a cargo de|se encarga|encargad[oa]:?)\s+(?:de\s+)?([A-ZÁÉÍÓÚÑ][\wáéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][\wáéíóúñ]+){0,2})/u',
			'/\b([A-ZÁÉÍÓÚÑ][\wáéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][\wáéíóúñ]+){0,2})\s+(?:se compromete|enviará|entregará|presentará|revisará|coordinará|gestionará|preparará|solicitará|elaborará|redactará|convocará|deberá|quedó de|hará)/u',
		);
		foreach ( $patterns as $p ) {
			if ( preg_match( $p, $sentence, $m ) ) {
				return trim( $m[1] );
			}
		}

		return '';
	}

	/**
	 * Plazo a partir de la frase.
	 *
	 * @param string $sentence     Frase.
	 * @param string $meeting_date Fecha de la reunión.
	 * @return string|null
	 */
	private static function due_date( string $sentence, string $meeting_date ): ?string {
		$base = \DateTimeImmutable::createFromFormat( '!Y-m-d', $meeting_date ) ?: new \DateTimeImmutable( 'today' );
		$year = (int) $base->format( 'Y' );
		if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $sentence, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '#\b(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?\b#', $sentence, $m ) ) {
			$y = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : $year;
			$y = $y < 100 ? 2000 + $y : $y;
			if ( checkdate( (int) $m[2], (int) $m[1], $y ) ) {
				return sprintf( '%04d-%02d-%02d', $y, $m[2], $m[1] );
			}
		}
		if ( preg_match( '/\b(\d{1,2})\s+de\s+(' . implode( '|', array_keys( self::MONTHS ) ) . ')(?:\s+(?:de\s+)?(\d{4}))?/iu', $sentence, $m ) ) {
			$month = self::MONTHS[ mb_strtolower( $m[2] ) ];
			$y     = ! empty( $m[3] ) ? (int) $m[3] : $year;
			if ( empty( $m[3] ) && sprintf( '%04d-%02d-%02d', $y, $month, $m[1] ) < $meeting_date ) {
				++$y;
			}
			if ( checkdate( $month, (int) $m[1], $y ) ) {
				return sprintf( '%04d-%02d-%02d', $y, $month, $m[1] );
			}
		}
		if ( preg_match( '/\b(?:en|dentro de|plazo de)\s+(\d+|un|una|dos|tres|cuatro|cinco|seis|diez|quince)\s+(d[ií]as?|semanas?|mes(?:es)?)\b/iu', $sentence, $m ) ) {
			$words = array( 'un' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'diez' => 10, 'quince' => 15 );
			$n     = is_numeric( $m[1] ) ? (int) $m[1] : ( $words[ mb_strtolower( $m[1] ) ] ?? 1 );
			$unit  = mb_strtolower( $m[2] );
			$days  = 0 === strpos( $unit, 'sem' ) ? $n * 7 : ( 0 === strpos( $unit, 'mes' ) ? $n * 30 : $n );

			return $base->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		}
		if ( preg_match( '/\b(próxima|proxima) (reunión|reunion|semana)\b/iu', $sentence, $m ) ) {
			return $base->modify( '+7 days' )->format( 'Y-m-d' );
		}

		return null;
	}
}
