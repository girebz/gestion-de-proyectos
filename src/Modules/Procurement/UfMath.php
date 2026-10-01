<?php
/**
 * Aritmética de la unidad de fomento.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

/**
 * Conversiones y comprobaciones puras (sin WordPress) sobre montos en
 * unidades de fomento y pesos: conversión con un valor dado, valor implícito
 * de una orden, tolerancia de reajuste y antigüedad de una cotización.
 */
final class UfMath {

	/**
	 * Pesos equivalentes a un monto en unidades de fomento.
	 *
	 * @param float $uf   Monto en unidades de fomento.
	 * @param float $rate Valor de la unidad de fomento en pesos.
	 * @return float Pesos, redondeados a la unidad.
	 */
	public static function to_clp( float $uf, float $rate ): float {
		return round( $uf * $rate, 0 );
	}

	/**
	 * Unidades de fomento equivalentes a un monto en pesos.
	 *
	 * @param float $clp  Pesos.
	 * @param float $rate Valor de la unidad de fomento.
	 * @return float Unidades de fomento con cuatro decimales.
	 */
	public static function to_uf( float $clp, float $rate ): float {
		return $rate > 0 ? round( $clp / $rate, 4 ) : 0.0;
	}

	/**
	 * Valor implícito de la unidad de fomento en una operación (pesos / unidades).
	 *
	 * @param float $clp Pesos.
	 * @param float $uf  Unidades de fomento.
	 * @return float|null Null si no hay unidades.
	 */
	public static function implied_rate( float $clp, float $uf ): ?float {
		return $uf > 0 ? round( $clp / $uf, 2 ) : null;
	}

	/**
	 * Desviación porcentual del valor implícito respecto del oficial.
	 *
	 * @param float $implied  Valor implícito.
	 * @param float $official Valor oficial.
	 * @return float Porcentaje con signo (positivo si el implícito es mayor).
	 */
	public static function deviation_percent( float $implied, float $official ): float {
		return $official > 0 ? round( ( $implied - $official ) / $official * 100, 2 ) : 0.0;
	}

	/**
	 * Indica si el valor implícito coincide con el oficial dentro de una tolerancia.
	 *
	 * @param float $implied   Valor implícito.
	 * @param float $official  Valor oficial.
	 * @param float $tolerance Tolerancia en porcentaje.
	 * @return bool
	 */
	public static function within_tolerance( float $implied, float $official, float $tolerance ): bool {
		return abs( self::deviation_percent( $implied, $official ) ) <= $tolerance;
	}

	/**
	 * Días corridos entre dos fechas (Y-m-d).
	 *
	 * @param string $from Desde.
	 * @param string $to   Hasta.
	 * @return int Negativo si "hasta" es anterior.
	 */
	public static function age_days( string $from, string $to ): int {
		$a = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		$b = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
		if ( ! $a || ! $b ) {
			return 0;
		}
		$diff = $a->diff( $b );

		return (int) $diff->days * ( $diff->invert ? -1 : 1 );
	}

	/**
	 * Total con impuesto a partir del neto.
	 *
	 * @param float $net      Neto.
	 * @param float $tax_rate Impuesto en porcentaje.
	 * @return float
	 */
	public static function with_tax( float $net, float $tax_rate ): float {
		return round( $net * ( 1 + $tax_rate / 100 ), 4 );
	}
}
