<?php
/**
 * Imputación de pagos a cuotas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * El dinero de la cuenta es fungible; para saber cuánto de cada cuota está
 * gastado, rendido o aprobado, el módulo imputa por orden cronológico
 * (primero entrada, primero gastada):
 *
 *   g_k = min( max( g − Σ_{j<k} T_j, 0 ), T_k ).
 *
 * SISREC exige además declarar en cada transacción la transferencia con que
 * se pagó y rechaza rendir por una transferencia más de lo que aportó; por eso
 * la clase también comprueba una imputación declarada contra los montos.
 */
final class Imputation {

	/**
	 * Reparte un acumulado entre las cuotas, en su orden.
	 *
	 * @param float              $cumulative Acumulado (pagado, rendido o aprobado).
	 * @param array<int,float>   $amounts    Montos por número de cuota, en orden.
	 * @return array<int,float> Monto imputado por número de cuota.
	 */
	public static function fifo( float $cumulative, array $amounts ): array {
		$out      = array();
		$previous = 0.0;
		foreach ( $amounts as $number => $amount ) {
			$amount         = (float) $amount;
			$out[ $number ] = round( min( max( $cumulative - $previous, 0.0 ), $amount ), 2 );
			$previous      += $amount;
		}

		return $out;
	}

	/**
	 * Número de cuota que corresponde a un pago según la cronología: la
	 * primera cuota cuyo acumulado de montos supera el acumulado pagado
	 * antes de este pago.
	 *
	 * @param float            $paid_before Pagado acumulado antes del pago.
	 * @param array<int,float> $amounts     Montos por número de cuota, en orden.
	 * @return int Número de cuota (la última si todo está agotado; 0 sin cuotas).
	 */
	public static function installment_for( float $paid_before, array $amounts ): int {
		$sum  = 0.0;
		$last = 0;
		foreach ( $amounts as $number => $amount ) {
			$sum += (float) $amount;
			$last = (int) $number;
			if ( $paid_before < $sum - 0.005 ) {
				return (int) $number;
			}
		}

		return $last;
	}

	/**
	 * Comprueba una imputación declarada: r_k ≤ T_k para cada cuota.
	 *
	 * @param array<int,float> $declared Monto declarado por número de cuota.
	 * @param array<int,float> $amounts  Montos de las cuotas.
	 * @return array<int,float> Exceso por cuota (solo las que lo tienen).
	 */
	public static function excess( array $declared, array $amounts ): array {
		$out = array();
		foreach ( $declared as $number => $value ) {
			$limit = (float) ( $amounts[ $number ] ?? 0.0 );
			if ( (float) $value > $limit + 0.005 ) {
				$out[ (int) $number ] = round( (float) $value - $limit, 2 );
			}
		}

		return $out;
	}

	/**
	 * Suma acumulada hasta la cuota k inclusive.
	 *
	 * @param array<int,float> $amounts Montos por número de cuota.
	 * @param int              $k       Cuota.
	 * @return float
	 */
	public static function cumulative_to( array $amounts, int $k ): float {
		$sum = 0.0;
		foreach ( $amounts as $number => $amount ) {
			if ( (int) $number <= $k ) {
				$sum += (float) $amount;
			}
		}

		return round( $sum, 2 );
	}
}
