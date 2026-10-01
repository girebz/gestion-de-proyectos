<?php
/**
 * Comprobaciones al avanzar una compra.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

defined( 'ABSPATH' ) || exit;

/**
 * Antes de emitir una orden de compra: la cotización elegida en unidades de
 * fomento no debe ser demasiado antigua (reajuste), el valor implícito de la
 * orden (pesos / unidades) debe coincidir con el valor oficial del día dentro
 * de la tolerancia, y la compra debe estar aprobada y con partida y saldo.
 */
final class ProcurementChecks {

	/**
	 * Evalúa la emisión de la orden de una compra en una fecha.
	 *
	 * @param array<string,mixed> $purchase Compra.
	 * @param string              $date     Fecha de la orden.
	 * @return array{warnings:string[],conflicts:string[],facts:array<string,mixed>}
	 */
	public static function order( array $purchase, string $date ): array {
		$project_id = (int) $purchase['project_id'];
		$settings   = ProcurementSettings::all( $project_id );
		$warnings   = array();
		$conflicts  = array();
		$facts      = array();

		if ( empty( $purchase['approved_at'] ) ) {
			$warnings[] = __( 'La compra no tiene aprobación formal registrada (acción aprobar, reservada al director).', 'gestion-de-proyectos' );
		}
		if ( '' === (string) $purchase['budget_line'] ) {
			$warnings[] = __( 'La compra no tiene partida presupuestaria.', 'gestion-de-proyectos' );
		} else {
			foreach ( BudgetService::summary( $project_id )['lines'] as $line ) {
				if ( $line['code'] === $purchase['budget_line'] ) {
					$facts['budget_balance'] = $line['balance'];
					if ( null !== $purchase['amount_clp'] && $line['assigned'] > 0 && $line['balance'] - (float) $purchase['amount_clp'] < 0 && ! $purchase['committed'] ) {
						/* translators: 1: partida, 2: saldo. */
						$warnings[] = sprintf( __( 'La orden supera el saldo de la partida %1$s (saldo %2$s).', 'gestion-de-proyectos' ), $line['label'], number_format( $line['balance'], 0, ',', '.' ) );
					}
				}
			}
		}
		if ( null === $purchase['amount_net'] ) {
			$warnings[] = __( 'La compra no tiene monto; indique el neto o elija una cotización.', 'gestion-de-proyectos' );
		}

		$quote = $purchase['chosen_quote_id'] > 0 ? QuoteRepository::find( (int) $purchase['chosen_quote_id'] ) : null;
		if ( $quote ) {
			$facts['quote'] = array( 'id' => $quote['id'], 'number' => $quote['quote_number'], 'date' => $quote['quote_date'], 'currency' => $quote['currency'], 'amount_net' => $quote['amount_net'] );
			if ( $quote['quote_date'] ) {
				$age               = UfMath::age_days( $quote['quote_date'], $date );
				$facts['quote_age'] = $age;
				if ( 'UF' === $quote['currency'] && $age > (int) $settings['quote_age_days'] ) {
					/* translators: 1: días, 2: días máximos. */
					$warnings[] = sprintf( __( 'La cotización elegida está en unidades de fomento y tiene %1$d días (más de %2$d): compruebe el reajuste antes de emitir la orden.', 'gestion-de-proyectos' ), $age, (int) $settings['quote_age_days'] );
				}
			}
			if ( $quote['valid_until'] && $quote['valid_until'] < $date ) {
				/* translators: fecha de validez. */
				$warnings[] = sprintf( __( 'La cotización elegida venció el %s.', 'gestion-de-proyectos' ), $quote['valid_until'] );
			}
			if ( 'UF' === $quote['currency'] && null !== $quote['amount_net'] && $quote['amount_net'] > 0 && 'CLP' === $purchase['currency'] && null !== $purchase['amount_net'] ) {
				$implied  = UfMath::implied_rate( (float) $purchase['amount_net'], (float) $quote['amount_net'] );
				$official = UfService::rate_for( $date );
				$facts['implied_rate']  = $implied;
				$facts['official_rate'] = $official ? $official['value'] : null;
				if ( $implied && $official ) {
					$deviation = UfMath::deviation_percent( $implied, $official['value'] );
					$facts['deviation_percent'] = $deviation;
					if ( UfMath::within_tolerance( $implied, $official['value'], (float) $settings['uf_tolerance'] ) ) {
						/* translators: 1: valor implícito, 2: valor oficial, 3: fecha, 4: desviación. */
						$warnings[] = sprintf( __( 'Valor implícito de la unidad de fomento %1$s frente al oficial %2$s del %3$s (desviación %4$s %%): la diferencia con la cotización se explica por reajuste, no por ítems omitidos.', 'gestion-de-proyectos' ), number_format( $implied, 2, ',', '.' ), number_format( $official['value'], 2, ',', '.' ), $official['date'], number_format( $deviation, 2, ',', '.' ) );
					} else {
						/* translators: 1: valor implícito, 2: valor oficial, 3: desviación, 4: tolerancia. */
						$conflicts[] = sprintf( __( 'Discrepancia: el valor implícito de la unidad de fomento (%1$s) se aleja del oficial (%2$s) en %3$s %%, más que la tolerancia de %4$s %%. Revise los ítems de la orden antes de emitirla.', 'gestion-de-proyectos' ), number_format( $implied, 2, ',', '.' ), number_format( $official['value'], 2, ',', '.' ), number_format( $deviation, 2, ',', '.' ), number_format( (float) $settings['uf_tolerance'], 2, ',', '.' ) );
					}
				} elseif ( $implied && ! $official ) {
					$warnings[] = __( 'No hay valor oficial de la unidad de fomento para contrastar; cárguelo en Presupuesto.', 'gestion-de-proyectos' );
				}
			}
		} elseif ( 'UF' === $purchase['currency'] ) {
			$rate = UfService::rate_for( $date );
			if ( ! $rate ) {
				$warnings[] = __( 'La compra está en unidades de fomento y no hay valor oficial registrado para convertirla.', 'gestion-de-proyectos' );
			}
		}

		return array( 'warnings' => $warnings, 'conflicts' => $conflicts, 'facts' => $facts );
	}
}
