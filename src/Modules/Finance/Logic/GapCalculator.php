<?php
/**
 * Brechas para acceder a la cuota siguiente.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Para habilitar la cuota k+1 hay dos brechas monetarias:
 *
 *   B_pago = max( Σ_{j≤k} T_j − G, 0 )   lo que falta pagar;
 *   B_rend = max( Σ_{j≤k} T_j − R, 0 )   lo que falta rendir (y el monto de la garantía alternativa).
 *
 * Un plan mensual es factible sin garantía si existe un mes m en que el
 * gasto acumulado alcanza exactamente Σ T_j con tiempo para rendir.
 */
final class GapCalculator {

	/**
	 * Brechas de la cuota k (la que debe quedar rendida para girar la k+1).
	 *
	 * @param array<int,float> $amounts  Montos por número de cuota.
	 * @param int              $k        Última cuota recibida.
	 * @param float            $paid     Pagado acumulado (G).
	 * @param float            $rendered Rendido acumulado (R).
	 * @param float            $approved Aprobado acumulado (A).
	 * @return array{target:float,pay_gap:float,render_gap:float,approve_gap:float,guarantee:float}
	 */
	public static function gaps( array $amounts, int $k, float $paid, float $rendered, float $approved = 0.0 ): array {
		$target = Imputation::cumulative_to( $amounts, $k );

		return array(
			'target'      => $target,
			'pay_gap'     => round( max( $target - $paid, 0.0 ), 2 ),
			'render_gap'  => round( max( $target - $rendered, 0.0 ), 2 ),
			'approve_gap' => round( max( $target - $approved, 0.0 ), 2 ),
			'guarantee'   => round( max( $target - $rendered, 0.0 ), 2 ),
		);
	}

	/**
	 * Primer mes en que el gasto acumulado programado alcanza el objetivo.
	 *
	 * @param array<string,float> $cumulative Gasto acumulado por mes AAAA-MM, en orden.
	 * @param float               $target     Objetivo (Σ T_j).
	 * @return string|null
	 */
	public static function month_reaching( array $cumulative, float $target ): ?string {
		foreach ( $cumulative as $period => $value ) {
			if ( (float) $value >= $target - 0.005 ) {
				return (string) $period;
			}
		}

		return null;
	}

	/**
	 * Acumula una serie mensual a partir de un saldo inicial.
	 *
	 * @param array<string,float> $monthly Montos por mes AAAA-MM, en orden.
	 * @param float               $initial Acumulado inicial.
	 * @return array<string,float>
	 */
	public static function cumulate( array $monthly, float $initial = 0.0 ): array {
		$out = array();
		$sum = $initial;
		foreach ( $monthly as $period => $value ) {
			$sum             += (float) $value;
			$out[ $period ]   = round( $sum, 2 );
		}

		return $out;
	}

	/**
	 * Selecciona los compromisos que cierran la brecha de pago, en el orden
	 * dado (el llamador los ordena por disponibilidad del ítem, preparación
	 * y fecha probable de pago). Devuelve los elegidos y lo que queda por cubrir.
	 *
	 * @param array<int,array<string,mixed>> $candidates Candidatos con clave amount.
	 * @param float                          $gap        Brecha de pago.
	 * @return array{selected:array<int,array<string,mixed>>,covered:float,remaining:float}
	 */
	public static function cover( array $candidates, float $gap ): array {
		$selected = array();
		$covered  = 0.0;
		foreach ( $candidates as $c ) {
			if ( $covered >= $gap - 0.005 ) {
				break;
			}
			$selected[] = $c;
			$covered   += (float) ( $c['amount'] ?? 0 );
		}

		return array(
			'selected'  => $selected,
			'covered'   => round( $covered, 2 ),
			'remaining' => round( max( $gap - $covered, 0.0 ), 2 ),
		);
	}
}
