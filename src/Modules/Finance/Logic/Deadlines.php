<?php
/**
 * Plazos de rendición en días hábiles.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

use DateTimeImmutable;
use GDP\Planning\WorkCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Un pago del mes m se respalda internamente el 8.º día hábil de m+1 y se
 * carga en la plataforma el 15.º (parámetros del registro de reglas). Dado
 * un día objetivo de giro D y los desfases de revisión y solicitud, el
 * último mes en que un pago todavía habilita el giro es
 *
 *   m* = max{ m : ρ(m) + δ_rev + δ_sol ≤ D }.
 */
final class Deadlines {

	/**
	 * Enésimo día hábil de un mes.
	 *
	 * @param string       $period   Mes AAAA-MM.
	 * @param int          $n        Ordinal (1 = primer día hábil).
	 * @param WorkCalendar $calendar Calendario laboral.
	 * @return string Fecha AAAA-MM-DD.
	 */
	public static function nth_working_day( string $period, int $n, WorkCalendar $calendar ): string {
		$n     = max( 1, $n );
		$date  = new DateTimeImmutable( $period . '-01' );
		$count = 0;
		while ( true ) {
			if ( $calendar->is_working( $date->format( 'Y-m-d' ) ) ) {
				++$count;
				if ( $count === $n ) {
					return $date->format( 'Y-m-d' );
				}
			}
			$date = $date->modify( '+1 day' );
		}
	}

	/**
	 * Mes siguiente a un período.
	 *
	 * @param string $period Mes AAAA-MM.
	 * @param int    $months Meses a sumar (puede ser negativo).
	 * @return string
	 */
	public static function add_months( string $period, int $months ): string {
		return ( new DateTimeImmutable( $period . '-01' ) )->modify( ( $months >= 0 ? '+' : '' ) . $months . ' months' )->format( 'Y-m' );
	}

	/**
	 * Plazos de la rendición de un período: respaldos internos y carga en la plataforma.
	 *
	 * @param string       $period       Mes rendido AAAA-MM.
	 * @param int          $internal_day Ordinal del día hábil interno (8).
	 * @param int          $platform_day Ordinal del día hábil de la plataforma (15).
	 * @param WorkCalendar $calendar     Calendario laboral.
	 * @return array{internal:string,platform:string}
	 */
	public static function rendition_due( string $period, int $internal_day, int $platform_day, WorkCalendar $calendar ): array {
		$next = self::add_months( $period, 1 );

		return array(
			'internal' => self::nth_working_day( $next, $internal_day, $calendar ),
			'platform' => self::nth_working_day( $next, $platform_day, $calendar ),
		);
	}

	/**
	 * Fecha límite para rendir un pago hecho en un mes: ρ(m).
	 *
	 * @param string       $period       Mes del pago AAAA-MM.
	 * @param int          $platform_day Ordinal del día hábil de la plataforma.
	 * @param WorkCalendar $calendar     Calendario laboral.
	 * @return string
	 */
	public static function rho( string $period, int $platform_day, WorkCalendar $calendar ): string {
		return self::nth_working_day( self::add_months( $period, 1 ), $platform_day, $calendar );
	}

	/**
	 * Último mes en que un pago todavía habilita el giro antes de la fecha objetivo.
	 *
	 * @param string       $target        Fecha objetivo del giro AAAA-MM-DD.
	 * @param int          $review_days   Desfase de revisión del otorgante (días corridos).
	 * @param int          $request_days  Desfase de solicitud y transferencia (días corridos).
	 * @param int          $platform_day  Ordinal del día hábil de la plataforma.
	 * @param WorkCalendar $calendar      Calendario laboral.
	 * @param string       $from          Primer mes candidato AAAA-MM (por omisión, 24 meses antes del objetivo).
	 * @return string|null Mes AAAA-MM, o null si ningún mes cumple.
	 */
	public static function latest_payment_month( string $target, int $review_days, int $request_days, int $platform_day, WorkCalendar $calendar, string $from = '' ): ?string {
		$target_dt = new DateTimeImmutable( $target );
		$month     = $target_dt->format( 'Y-m' );
		$floor     = '' !== $from ? $from : $target_dt->modify( '-24 months' )->format( 'Y-m' );
		$lag       = max( 0, $review_days ) + max( 0, $request_days );
		while ( $month >= $floor ) {
			$ready = ( new DateTimeImmutable( self::rho( $month, $platform_day, $calendar ) ) )->modify( '+' . $lag . ' days' );
			if ( $ready <= $target_dt ) {
				return $month;
			}
			$month = self::add_months( $month, -1 );
		}

		return null;
	}

	/**
	 * Último día de un mes.
	 *
	 * @param string $period Mes AAAA-MM.
	 * @return string
	 */
	public static function month_end( string $period ): string {
		return ( new DateTimeImmutable( $period . '-01' ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
	}

	/**
	 * Meses de un rango, inclusive.
	 *
	 * @param string $from Mes inicial AAAA-MM.
	 * @param string $to   Mes final AAAA-MM.
	 * @return string[]
	 */
	public static function months_between( string $from, string $to ): array {
		$out = array();
		if ( $from > $to ) {
			return $out;
		}
		$month = $from;
		while ( $month <= $to ) {
			$out[] = $month;
			$month = self::add_months( $month, 1 );
		}

		return $out;
	}

	/**
	 * Días hábiles entre hoy y una fecha (negativo si ya pasó).
	 *
	 * @param string       $today    Hoy AAAA-MM-DD.
	 * @param string       $due      Vencimiento AAAA-MM-DD.
	 * @param WorkCalendar $calendar Calendario laboral.
	 * @return int
	 */
	public static function working_days_left( string $today, string $due, WorkCalendar $calendar ): int {
		return $calendar->working_days_between( $today, $due );
	}
}
