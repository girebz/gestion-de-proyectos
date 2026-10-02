<?php
/**
 * Identidades de cuadratura de caja.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Saldo de caja S = T + E − G − G_P − D, conciliación Δ = S_cartola − S,
 * caja no negativa por fuente (G ≤ T, G_P ≤ E), disponible por ítem
 * L = M − G − K − X y diferencia de cierre Σ T_k − A − D.
 */
final class Reconciliation {

	/**
	 * Saldo de caja calculado.
	 *
	 * @param float $transferred  Transferido por el otorgante (T).
	 * @param float $contributed  Aporte pecuniario enterado (E).
	 * @param float $paid_fund    Pagado con cargo al Fondo (G).
	 * @param float $paid_cash    Pagado con cargo al aporte (G_P).
	 * @param float $returned     Reintegros al otorgante (D).
	 * @return float
	 */
	public static function balance( float $transferred, float $contributed, float $paid_fund, float $paid_cash, float $returned = 0.0 ): float {
		return round( $transferred + $contributed - $paid_fund - $paid_cash - $returned, 2 );
	}

	/**
	 * Diferencia de conciliación Δ = saldo de la cartola − saldo calculado.
	 *
	 * @param float $ledger_balance Saldo según cartola o mayor contable.
	 * @param float $computed       Saldo calculado.
	 * @return float
	 */
	public static function difference( float $ledger_balance, float $computed ): float {
		return round( $ledger_balance - $computed, 2 );
	}

	/**
	 * Indica si la caja de una fuente se mantiene no negativa.
	 *
	 * @param float $received Recibido (T o E).
	 * @param float $paid     Pagado con cargo a la fuente.
	 * @return bool
	 */
	public static function non_negative( float $received, float $paid ): bool {
		return $paid <= $received + 0.005;
	}

	/**
	 * Disponible de un ítem: asignado − pagado − comprometido no pagado − rechazado.
	 *
	 * @param float $assigned  Asignado vigente (M).
	 * @param float $paid      Pagado (G).
	 * @param float $committed Comprometido no pagado (K).
	 * @param float $rejected  Rechazado (X).
	 * @return float
	 */
	public static function available( float $assigned, float $paid, float $committed, float $rejected = 0.0 ): float {
		return round( $assigned - $paid - $committed - $rejected, 2 );
	}

	/**
	 * Diferencia de cierre: transferido − aprobado − reintegrado. Debe ser cero.
	 *
	 * @param float $transferred Transferido (Σ T_k).
	 * @param float $approved    Aprobado (A).
	 * @param float $returned    Reintegrado (D).
	 * @return float
	 */
	public static function closing_difference( float $transferred, float $approved, float $returned ): float {
		return round( $transferred - $approved - $returned, 2 );
	}
}
