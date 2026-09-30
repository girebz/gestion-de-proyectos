<?php
/**
 * Curva S: avance planificado frente a real.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * El avance planificado acumulado en una fecha es la suma, ponderada por
 * duración, de la fracción de cada actividad que debía estar hecha según la
 * línea base vigente (o el cronograma actual si no la hay), distribuida
 * linealmente sobre sus días hábiles. El avance real acumulado se reconstruye
 * con el historial de avances: para cada actividad, el último porcentaje
 * registrado a la fecha.
 */
final class ProgressCurve {

	/**
	 * Serie semanal.
	 *
	 * @param int         $project_id Proyecto.
	 * @param string|null $until      Última fecha de la serie real (por defecto, hoy).
	 * @return array<string,mixed>
	 */
	public static function build( int $project_id, ?string $until = null ): array {
		$today      = $until ?? current_time( 'Y-m-d' );
		$calendar   = CalendarRepository::build( $project_id );
		$result     = ScheduleService::recalculate( $project_id );
		$activities = array_values( array_filter( $result['activities'], static fn( array $a ): bool => 'summary' !== $a['kind'] && 'cancelada' !== $a['status'] ) );
		$baseline   = BaselineRepository::current( $project_id );
		$base       = $baseline ? BaselineRepository::activities( $baseline['id'] ) : array();

		// Plan por actividad: fechas de la línea base si existe, si no las actuales.
		$plan   = array();
		$weight = 0;
		foreach ( $activities as $a ) {
			$b     = $base[ $a['id'] ] ?? null;
			$start = $b && $b['start_date'] ? $b['start_date'] : $a['start_date'];
			$end   = $b && $b['end_date'] ? $b['end_date'] : $a['end_date'];
			$w     = max( 0, $b ? (int) $b['duration'] : (int) $a['duration'] );
			if ( 'milestone' === $a['kind'] ) {
				$w = 0;
			}
			if ( ! $start || ! $end || 0 === $w ) {
				continue;
			}
			$plan[ $a['id'] ] = array( 'start' => $start, 'end' => $end, 'weight' => $w, 'days' => max( 1, $calendar->count_working_days( $start, $end ) ) );
			$weight          += $w;
		}
		if ( 0 === $weight ) {
			return array( 'points' => array(), 'weight' => 0, 'baseline' => $baseline ? $baseline['name'] : null );
		}

		// Historial de avances por actividad, ordenado por fecha (hora del sitio).
		$history = array();
		foreach ( ProgressRepository::all_for_project( $project_id ) as $h ) {
			$history[ $h['activity_id'] ][] = array( 'date' => get_date_from_gmt( $h['reported_at'], 'Y-m-d' ), 'percent' => $h['percent'] );
		}
		$current = array();
		foreach ( $activities as $a ) {
			$current[ $a['id'] ] = array( 'percent' => $a['percent'], 'updated' => get_date_from_gmt( (string) $a['updated_at'], 'Y-m-d' ) );
		}

		// Rango: del inicio del plan al término del plan, por semanas.
		$from = null;
		$to   = null;
		foreach ( $plan as $p ) {
			$from = null === $from ? $p['start'] : min( $from, $p['start'] );
			$to   = null === $to ? $p['end'] : max( $to, $p['end'] );
		}
		$to     = max( $to, $today );
		$cursor = WeeklyReport::monday( $from )->modify( '+6 days' ); // Domingos.
		$last   = new DateTimeImmutable( $to );
		$points = array();
		$guard  = 0;
		while ( $cursor <= $last->modify( '+6 days' ) && $guard++ < 400 ) {
			$date = $cursor->format( 'Y-m-d' );
			$points[] = array(
				'date'    => $date,
				'planned' => self::planned_at( $date, $plan, $weight, $calendar ),
				'actual'  => $date <= $today ? self::actual_at( $date, $plan, $weight, $history, $current ) : null,
			);
			$cursor = $cursor->modify( '+7 days' );
		}

		$planned_today = self::planned_at( $today, $plan, $weight, $calendar );
		$actual_today  = self::actual_at( $today, $plan, $weight, $history, $current );

		return array(
			'baseline'      => $baseline ? $baseline['name'] : null,
			'weight'        => $weight,
			'today'         => $today,
			'planned_today' => $planned_today,
			'actual_today'  => $actual_today,
			'variance'      => round( $actual_today - $planned_today, 1 ),
			'points'        => $points,
		);
	}

	/**
	 * Avance planificado acumulado a una fecha (0 a 100).
	 *
	 * @param string                                   $date     Fecha.
	 * @param array<int,array<string,mixed>>           $plan     Plan por actividad.
	 * @param int                                      $weight   Peso total.
	 * @param \GDP\Planning\WorkCalendar               $calendar Calendario.
	 * @return float
	 */
	private static function planned_at( string $date, array $plan, int $weight, $calendar ): float {
		$sum = 0.0;
		foreach ( $plan as $p ) {
			if ( $date < $p['start'] ) {
				continue;
			}
			if ( $date >= $p['end'] ) {
				$sum += $p['weight'];
				continue;
			}
			$done = $calendar->count_working_days( $p['start'], $date );
			$sum += $p['weight'] * min( 1, $done / $p['days'] );
		}

		return round( 100 * $sum / $weight, 1 );
	}

	/**
	 * Avance real acumulado a una fecha (0 a 100).
	 *
	 * @param string                                          $date    Fecha.
	 * @param array<int,array<string,mixed>>                  $plan    Plan por actividad.
	 * @param int                                             $weight  Peso total.
	 * @param array<int,array<int,array{date:string,percent:int}>> $history Historial.
	 * @param array<int,array{percent:int,updated:string}>    $current Estado actual.
	 * @return float
	 */
	private static function actual_at( string $date, array $plan, int $weight, array $history, array $current ): float {
		$sum = 0.0;
		foreach ( $plan as $id => $p ) {
			$percent = 0;
			if ( ! empty( $history[ $id ] ) ) {
				foreach ( $history[ $id ] as $h ) {
					if ( $h['date'] <= $date ) {
						$percent = $h['percent'];
					}
				}
			} elseif ( isset( $current[ $id ] ) && $current[ $id ]['updated'] <= $date ) {
				// Sin historial: el avance actual se atribuye a su última modificación.
				$percent = $current[ $id ]['percent'];
			}
			$sum += $p['weight'] * $percent / 100;
		}

		return round( 100 * $sum / $weight, 1 );
	}

	/**
	 * Gráfico SVG de la curva.
	 *
	 * @param array<string,mixed> $curve  Serie de build().
	 * @param int                 $width  Ancho.
	 * @param int                 $height Alto.
	 * @return string HTML del SVG.
	 */
	public static function svg( array $curve, int $width = 720, int $height = 280 ): string {
		$points = $curve['points'] ?? array();
		if ( count( $points ) < 2 ) {
			return '<p class="gdp-muted">' . esc_html__( 'Sin datos suficientes para la curva.', 'gestion-de-proyectos' ) . '</p>';
		}
		$pad_l = 44;
		$pad_r = 16;
		$pad_t = 16;
		$pad_b = 34;
		$w     = $width - $pad_l - $pad_r;
		$h     = $height - $pad_t - $pad_b;
		$n     = count( $points );
		$x     = static fn( int $i ): float => $pad_l + $w * $i / ( $n - 1 );
		$y     = static fn( float $v ): float => $pad_t + $h * ( 1 - $v / 100 );

		$planned = array();
		$actual  = array();
		$today_i = null;
		foreach ( $points as $i => $p ) {
			$planned[] = sprintf( '%.1f,%.1f', $x( $i ), $y( (float) $p['planned'] ) );
			if ( null !== $p['actual'] ) {
				$actual[] = sprintf( '%.1f,%.1f', $x( $i ), $y( (float) $p['actual'] ) );
				$today_i  = $i;
			}
		}

		$out   = array();
		$out[] = sprintf( '<svg class="gdp-curve" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s">', $width, $height, esc_attr__( 'Curva S de avance planificado y real', 'gestion-de-proyectos' ) );
		foreach ( array( 0, 25, 50, 75, 100 ) as $g ) {
			$out[] = sprintf( '<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#e5e5e5"/>', $pad_l, $width - $pad_r, $y( $g ), $y( $g ) );
			$out[] = sprintf( '<text x="%d" y="%.1f" font-size="10" fill="#646970" text-anchor="end">%d %%</text>', $pad_l - 6, $y( $g ) + 3, $g );
		}
		// Rótulos de fecha: primer punto, último y algunos intermedios.
		$labels = max( 2, min( 8, (int) floor( $w / 90 ) ) );
		for ( $k = 0; $k < $labels; $k++ ) {
			$i = (int) round( ( $n - 1 ) * $k / ( $labels - 1 ) );
			$out[] = sprintf( '<text x="%.1f" y="%d" font-size="10" fill="#646970" text-anchor="middle">%s</text>', $x( $i ), $height - 14, esc_html( WeeklyReport::human_date( $points[ $i ]['date'] ) ) );
		}
		if ( null !== $today_i ) {
			$out[] = sprintf( '<line x1="%.1f" x2="%.1f" y1="%d" y2="%d" stroke="var(--gdp-accent, #d63638)" stroke-dasharray="4 3"/>', $x( $today_i ), $x( $today_i ), $pad_t, $pad_t + $h );
		}
		$out[] = sprintf( '<polyline points="%s" fill="none" stroke="#646970" stroke-width="2" stroke-dasharray="6 4"/>', implode( ' ', $planned ) );
		if ( ! empty( $actual ) ) {
			$out[] = sprintf( '<polyline points="%s" fill="none" stroke="var(--gdp-primary, #2271b1)" stroke-width="2.5"/>', implode( ' ', $actual ) );
		}
		$out[] = sprintf( '<text x="%d" y="%d" font-size="11" fill="#646970">%s</text>', $pad_l + 6, $pad_t + 12, esc_html__( 'Planificado (línea discontinua) y real (línea continua)', 'gestion-de-proyectos' ) );
		$out[] = '</svg>';

		return implode( "\n", $out );
	}
}
