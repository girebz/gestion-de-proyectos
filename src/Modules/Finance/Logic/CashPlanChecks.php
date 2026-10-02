<?php
/**
 * Controles de la programación de caja.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * El formato de la Dirección de Investigación aplica dos controles de suma
 * (C1, C2); el módulo agrega cuatro que aseguran que el plan pueda ocurrir:
 * caja no negativa (C3), condición de giro (C4), ventana del convenio (C5)
 * e ítems (C6). Un plan puede cumplir los dos primeros y fallar los
 * siguientes; esa es exactamente la situación que el módulo debe revelar.
 */
final class CashPlanChecks {

	/**
	 * Evalúa los seis controles.
	 *
	 * @param array<int,array{period:string,transfer:float,spend:float,cash?:float}> $rows  Filas del plan, en orden.
	 * @param array<string,mixed>                                                      $state Estado: fund_total, transferred, spent, amounts [k => T_k],
	 *                                                                                        received [k...], windows [k => [from, to]], due [period => fecha ρ],
	 *                                                                                        lag_days, guarantees [k => monto], items [slug => [assigned, spent, planned]].
	 * @return array<int,array{key:string,label:string,ok:bool|null,detail:string}>
	 */
	public static function evaluate( array $rows, array $state ): array {
		$fund        = (float) ( $state['fund_total'] ?? 0 );
		$transferred = (float) ( $state['transferred'] ?? 0 );
		$spent       = (float) ( $state['spent'] ?? 0 );
		$amounts     = isset( $state['amounts'] ) && is_array( $state['amounts'] ) ? $state['amounts'] : array();
		$received    = isset( $state['received'] ) && is_array( $state['received'] ) ? array_map( 'intval', $state['received'] ) : array();
		$windows     = isset( $state['windows'] ) && is_array( $state['windows'] ) ? $state['windows'] : array();
		$due         = isset( $state['due'] ) && is_array( $state['due'] ) ? $state['due'] : array();
		$lag         = (int) ( $state['lag_days'] ?? 0 );
		$guarantees  = isset( $state['guarantees'] ) && is_array( $state['guarantees'] ) ? $state['guarantees'] : array();

		$sum_transfer = 0.0;
		$sum_spend    = 0.0;
		foreach ( $rows as $r ) {
			$sum_transfer += (float) ( $r['transfer'] ?? 0 );
			$sum_spend    += (float) ( $r['spend'] ?? 0 );
		}
		$to_transfer = round( $fund - $transferred, 2 );
		$to_spend    = round( $fund - $spent, 2 );
		$out         = array();

		$out[] = array(
			'key'    => 'C1',
			'label'  => 'Suma de transferencias programadas igual al saldo por transferir',
			'ok'     => abs( $sum_transfer - $to_transfer ) < 0.5,
			'detail' => sprintf( 'Programado %s; saldo por transferir %s.', self::money( $sum_transfer ), self::money( $to_transfer ) ),
		);
		$out[] = array(
			'key'    => 'C2',
			'label'  => 'Suma del gasto programado igual al saldo no ejecutado más el saldo por transferir',
			'ok'     => abs( $sum_spend - $to_spend ) < 0.5,
			'detail' => sprintf( 'Programado %s; saldo no ejecutado más saldo por transferir %s.', self::money( $sum_spend ), self::money( $to_spend ) ),
		);

		// C3: caja no negativa mes a mes, con la transferencia disponible en el mes en que se programa.
		$cash        = $transferred - $spent;
		$negative    = array();
		$cumulative  = array();
		$sum         = $spent;
		$next_number = empty( $received ) ? 1 : max( $received ) + 1;
		$transfers   = array();
		foreach ( $rows as $r ) {
			$period = (string) $r['period'];
			$cash  += (float) ( $r['transfer'] ?? 0 ) - (float) ( $r['spend'] ?? 0 );
			$sum   += (float) ( $r['spend'] ?? 0 );
			$cumulative[ $period ] = round( $sum, 2 );
			if ( $cash < -0.5 ) {
				$negative[] = sprintf( '%s (%s)', $period, self::money( $cash ) );
			}
			if ( (float) ( $r['transfer'] ?? 0 ) > 0 ) {
				$transfers[ $next_number ] = $period;
				++$next_number;
			}
		}
		$out[] = array(
			'key'    => 'C3',
			'label'  => 'Caja no negativa mes a mes',
			'ok'     => empty( $negative ),
			'detail' => empty( $negative ) ? 'La caja del Fondo nunca es negativa.' : 'Caja negativa en: ' . implode( ', ', $negative ) . '.',
		);

		// C4: cada transferencia k+1 cae después de que el gasto acumulado agota Σ_{j≤k} T_j, más el desfase, o hay garantía.
		$failures = array();
		$notes    = array();
		foreach ( $transfers as $number => $period ) {
			$k      = $number - 1;
			$target = Imputation::cumulative_to( $amounts, $k );
			if ( $target <= 0 ) {
				continue;
			}
			$reached = GapCalculator::month_reaching( $cumulative, $target );
			if ( $spent >= $target - 0.005 ) {
				$reached = 'ya';
			}
			$guarantee = (float) ( $guarantees[ $number ] ?? 0 );
			if ( null === $reached ) {
				if ( $guarantee > 0 ) {
					$notes[] = sprintf( 'Cuota %d: el plan no agota las anteriores, pero declara una garantía de %s.', $number, self::money( $guarantee ) );
					continue;
				}
				$failures[] = sprintf( 'Cuota %d en %s: el gasto programado nunca alcanza %s.', $number, $period, self::money( $target ) );
				continue;
			}
			if ( 'ya' === $reached ) {
				continue;
			}
			$ready = isset( $due[ $reached ] ) ? ( new DateTimeImmutable( (string) $due[ $reached ] ) )->modify( '+' . max( 0, $lag ) . ' days' )->format( 'Y-m-d' ) : null;
			$limit = Deadlines::month_end( $period );
			if ( $reached > $period || ( null !== $ready && $ready > $limit ) ) {
				if ( $guarantee > 0 ) {
					$notes[] = sprintf( 'Cuota %d en %s: las anteriores se agotan en %s; la garantía de %s cubre la diferencia.', $number, $period, $reached, self::money( $guarantee ) );
					continue;
				}
				$failures[] = sprintf( 'Cuota %d programada en %s, pero el gasto acumulado agota las anteriores recién en %s%s.', $number, $period, $reached, null !== $ready ? ' (rendición y revisión listas el ' . $ready . ')' : '' );
			}
		}
		$out[] = array(
			'key'    => 'C4',
			'label'  => 'Condición de giro: cada transferencia después de agotar y rendir las anteriores, o con garantía',
			'ok'     => empty( $failures ),
			'detail' => trim( ( empty( $failures ) ? 'Las transferencias programadas respetan la condición de giro.' : implode( ' ', $failures ) ) . ' ' . implode( ' ', $notes ) ),
		);

		// C5: ventana del programa de desembolso.
		$outside = array();
		foreach ( $transfers as $number => $period ) {
			if ( empty( $windows[ $number ] ) ) {
				continue;
			}
			list( $from, $to ) = $windows[ $number ];
			$from_m            = substr( (string) $from, 0, 7 );
			$to_m              = substr( (string) $to, 0, 7 );
			if ( ( '' !== $from_m && $period < $from_m ) || ( '' !== $to_m && $period > $to_m ) ) {
				$outside[] = sprintf( 'Cuota %d en %s, fuera de la ventana %s a %s.', $number, $period, $from_m, $to_m );
			}
		}
		$out[] = array(
			'key'    => 'C5',
			'label'  => 'Ventana del programa de desembolso del convenio',
			'ok'     => empty( $outside ),
			'detail' => empty( $outside ) ? 'Cada transferencia cae en el semestre que fija el convenio vigente.' : implode( ' ', $outside ),
		);

		// C6: ítems (solo si el plan trae detalle por ítem).
		$items   = isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : array();
		$over    = array();
		$checked = 0;
		foreach ( $items as $slug => $i ) {
			if ( ! isset( $i['planned'] ) ) {
				continue;
			}
			++$checked;
			$total = (float) ( $i['spent'] ?? 0 ) + (float) $i['planned'];
			if ( $total > (float) ( $i['assigned'] ?? 0 ) + 0.5 ) {
				$over[] = sprintf( '%s: %s frente a %s asignados.', (string) $slug, self::money( $total ), self::money( (float) ( $i['assigned'] ?? 0 ) ) );
			}
		}
		$out[] = array(
			'key'    => 'C6',
			'label'  => 'Gasto acumulado por ítem dentro del asignado vigente',
			'ok'     => 0 === $checked ? null : empty( $over ),
			'detail' => 0 === $checked ? 'El plan no trae detalle por ítem; no se evalúa.' : ( empty( $over ) ? 'Ningún ítem supera su asignado.' : implode( ' ', $over ) ),
		);

		return $out;
	}

	/**
	 * Formato de pesos sin decimales.
	 *
	 * @param float $amount Monto.
	 * @return string
	 */
	private static function money( float $amount ): string {
		return '$' . number_format( $amount, 0, ',', '.' );
	}
}
