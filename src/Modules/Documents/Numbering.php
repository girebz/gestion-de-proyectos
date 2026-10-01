<?php
/**
 * Numeración correlativa de documentos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

/**
 * Da formato al número de un documento a partir de un patrón con marcadores:
 * {PREFIJO} (prefijo del tipo), {NNN} (correlativo con ceros a la izquierda,
 * tantos como letras N), {N} (correlativo sin ceros), {AAAA} (año de cuatro
 * cifras), {AA} (año de dos cifras) y {PROYECTO} (código del proyecto).
 * Sin dependencia de WordPress para poder probarse en aislamiento.
 */
final class Numbering {

	public const DEFAULT_PATTERN = '{PREFIJO}-{NNN}/{AAAA}';

	/**
	 * Número con formato.
	 *
	 * @param string $pattern  Patrón.
	 * @param string $prefix   Prefijo del tipo.
	 * @param int    $sequence Correlativo.
	 * @param int    $year     Año.
	 * @param string $project  Código del proyecto.
	 * @return string
	 */
	public static function format( string $pattern, string $prefix, int $sequence, int $year, string $project = '' ): string {
		$pattern = '' === trim( $pattern ) ? self::DEFAULT_PATTERN : $pattern;
		$out     = preg_replace_callback(
			'/\{(N+)\}/',
			static fn( array $m ): string => str_pad( (string) $sequence, strlen( $m[1] ), '0', STR_PAD_LEFT ),
			$pattern
		);

		return strtr(
			(string) $out,
			array(
				'{PREFIJO}'  => $prefix,
				'{AAAA}'     => (string) $year,
				'{AA}'       => substr( (string) $year, -2 ),
				'{PROYECTO}' => $project,
			)
		);
	}

	/**
	 * Indica si el patrón reinicia el correlativo cada año.
	 *
	 * @param string $pattern Patrón.
	 * @return bool
	 */
	public static function yearly( string $pattern ): bool {
		$pattern = '' === trim( $pattern ) ? self::DEFAULT_PATTERN : $pattern;

		return false !== strpos( $pattern, '{AAAA}' ) || false !== strpos( $pattern, '{AA}' );
	}

	/**
	 * Prefijo por defecto de un tipo: iniciales en mayúsculas (carta → CARTA,
	 * orden_compra → OC, solicitud_de_cotizacion → SDC).
	 *
	 * @param string $slug Tipo.
	 * @return string
	 */
	public static function default_prefix( string $slug ): string {
		$parts = array_values( array_filter( explode( '_', str_replace( '-', '_', $slug ) ) ) );
		if ( count( $parts ) <= 1 ) {
			return strtoupper( substr( (string) ( $parts[0] ?? 'DOC' ), 0, 6 ) );
		}
		$initials = '';
		foreach ( $parts as $p ) {
			$initials .= strtoupper( substr( $p, 0, 1 ) );
		}

		return $initials;
	}
}
