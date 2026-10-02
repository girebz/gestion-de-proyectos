<?php
/**
 * Cálculos del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * El tablero responde las preguntas del director con cifras derivadas del
 * estado de cuentas: qué parte de cada fuente está pagada, comprometida o
 * libre; qué parte del plazo transcurrió; cómo se acumulan, mes a mes, lo
 * transferido, lo programado y lo pagado; en qué estado está cada rendición;
 * y qué situaciones exigen atención. Todo es aritmética sobre arreglos, de
 * modo que se prueba sin WordPress ni base de datos.
 */
final class BoardMetrics {

	/**
	 * Espacio que no se corta (entre el signo y la cifra).
	 */
	public const NBSP = "\u{00A0}";

	/**
	 * Pesos con separador de miles.
	 *
	 * @param float $amount Monto.
	 * @return string
	 */
	public static function money( float $amount ): string {
		$rounded = round( $amount );

		return ( $rounded < 0 ? '-' : '' ) . '$' . self::NBSP . number_format( abs( $rounded ), 0, ',', '.' );
	}

	/**
	 * Unifica un texto armado por las reglas con el formato del tablero: los
	 * montos ("$3.336.600" pasa a "$ 3.336.600", con espacio que no se
	 * corta), las fechas ("2026-10-13" pasa a "13/10/2026") y los meses
	 * ("2026-01" pasa a "01/2026"). Solo cambia la presentación: los valores
	 * para copiar en la plataforma conservan su formato.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function display_text( string $text ): string {
		$text = (string) preg_replace( '/\$\s?(?=-?\d)/u', '$' . self::NBSP, $text );
		$text = (string) preg_replace( '/\b(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b/', '$3/$2/$1', $text );

		return (string) preg_replace( '/\b(\d{4})-(0[1-9]|1[0-2])\b(?!-\d)/', '$2/$1', $text );
	}

	/**
	 * Porcentaje con un decimal; null si el total no es positivo.
	 *
	 * @param float $part  Parte.
	 * @param float $whole Total.
	 * @return float|null
	 */
	public static function pct( float $part, float $whole ): ?float {
		return $whole > 0 ? round( 100 * $part / $whole, 1 ) : null;
	}

	/**
	 * Porcentaje escrito con coma decimal.
	 *
	 * @param float|null $pct Porcentaje.
	 * @return string
	 */
	public static function pct_label( ?float $pct ): string {
		return null === $pct ? '—' : number_format( $pct, 1, ',', '.' ) . self::NBSP . '%';
	}

	/**
	 * Fecha AAAA-MM-DD como DD/MM/AAAA.
	 *
	 * @param string|null $date Fecha.
	 * @return string
	 */
	public static function date( ?string $date ): string {
		$date = (string) $date;
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $date, $m ) ) {
			return '—';
		}

		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}

	/**
	 * Uso de una fuente sobre su total: lo pagado, lo comprometido por pagar
	 * y lo libre, con la marca de lo recibido. Si lo pagado más lo
	 * comprometido supera el total, la base del gráfico es esa suma (los
	 * segmentos siguen sumando 100 %) y el exceso se informa aparte; si
	 * supera lo recibido, la diferencia es lo que depende de transferencias
	 * futuras.
	 *
	 * @param float $total     Total de la fuente (convenio).
	 * @param float $paid      Pagado.
	 * @param float $committed Comprometido o devengado por pagar.
	 * @param float $received  Recibido o enterado a la fecha.
	 * @return array<string,mixed>
	 */
	public static function usage( float $total, float $paid, float $committed, float $received ): array {
		$total     = max( 0.0, $total );
		$paid      = max( 0.0, $paid );
		$committed = max( 0.0, $committed );
		$used      = $paid + $committed;
		$base      = max( $total, $used );
		$free      = max( 0.0, $total - $used );
		$segments  = array();
		foreach ( array(
			'paid'      => $paid,
			'committed' => $committed,
			'free'      => $free,
		) as $key => $amount ) {
			$segments[] = array(
				'key'    => $key,
				'amount' => round( $amount, 2 ),
				'pct'    => $base > 0 ? round( 100 * $amount / $base, 2 ) : 0.0,
			);
		}

		return array(
			'total'        => round( $total, 2 ),
			'base'         => round( $base, 2 ),
			'paid'         => round( $paid, 2 ),
			'committed'    => round( $committed, 2 ),
			'free'         => round( $free, 2 ),
			'over'         => round( max( 0.0, $used - $total ), 2 ),
			'uncovered'    => round( max( 0.0, $used - max( 0.0, $received ) ), 2 ),
			'received'     => round( max( 0.0, $received ), 2 ),
			'received_pct' => $base > 0 ? round( min( 100.0, 100 * max( 0.0, $received ) / $base ), 2 ) : null,
			'segments'     => $segments,
		);
	}

	/**
	 * Avance de una cuota: aprobado, rendido sin aprobar, pagado sin rendir y
	 * saldo por pagar, sobre el monto de la cuota (las porciones se acotan
	 * para que el exceso de una no deforme la barra).
	 *
	 * @param float $amount   Monto de la cuota.
	 * @param float $paid     Pagado imputado a la cuota.
	 * @param float $rendered Rendido imputado.
	 * @param float $approved Aprobado imputado.
	 * @return array<int,array{key:string,amount:float,pct:float}>
	 */
	public static function installment_segments( float $amount, float $paid, float $rendered, float $approved ): array {
		$amount   = max( 0.0, $amount );
		$approved = min( max( 0.0, $approved ), $amount );
		$rendered = min( max( $approved, $rendered ), $amount );
		$paid     = min( max( $rendered, $paid ), $amount );
		$parts    = array(
			'approved' => $approved,
			'rendered' => $rendered - $approved,
			'paid'     => $paid - $rendered,
			'pending'  => $amount - $paid,
		);
		$out      = array();
		foreach ( $parts as $key => $value ) {
			$out[] = array(
				'key'    => $key,
				'amount' => round( $value, 2 ),
				'pct'    => $amount > 0 ? round( 100 * $value / $amount, 2 ) : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Plazo transcurrido del convenio.
	 *
	 * @param string $start Inicio (AAAA-MM-DD).
	 * @param string $end   Término (AAAA-MM-DD).
	 * @param string $today Hoy (AAAA-MM-DD).
	 * @return array{pct:float,month:int,months:int,days_left:int}|null
	 */
	public static function elapsed( string $start, string $end, string $today ): ?array {
		if ( ! self::is_date( $start ) || ! self::is_date( $end ) || ! self::is_date( $today ) || $end <= $start ) {
			return null;
		}
		$s      = (int) strtotime( $start . ' 00:00:00 UTC' );
		$e      = (int) strtotime( $end . ' 00:00:00 UTC' );
		$t      = (int) strtotime( $today . ' 00:00:00 UTC' );
		$total  = max( 1, $e - $s );
		$done   = min( max( 0, $t - $s ), $total );
		$months = self::full_months( $start, $end );
		$month  = $today < $start ? 0 : min( $months, self::full_months( $start, $today ) + 1 );

		return array(
			'pct'       => round( 100 * $done / $total, 1 ),
			'month'     => $month,
			'months'    => max( 1, $months ),
			'days_left' => (int) max( 0, round( ( $e - $t ) / 86400 ) ),
		);
	}

	/**
	 * Meses completos entre dos fechas.
	 *
	 * @param string $from Desde (AAAA-MM-DD).
	 * @param string $to   Hasta (AAAA-MM-DD).
	 * @return int
	 */
	public static function full_months( string $from, string $to ): int {
		if ( $to <= $from ) {
			return 0;
		}
		$months = ( (int) substr( $to, 0, 4 ) - (int) substr( $from, 0, 4 ) ) * 12 + (int) substr( $to, 5, 2 ) - (int) substr( $from, 5, 2 );
		if ( (int) substr( $to, 8, 2 ) < (int) substr( $from, 8, 2 ) ) {
			--$months;
		}

		return max( 0, $months );
	}

	/**
	 * Marcas redondas de un eje de cero a un máximo (1, 2, 2,5 o 5 por potencia de diez).
	 *
	 * @param float $max    Máximo de los datos.
	 * @param int   $target Número aproximado de intervalos.
	 * @return float[]
	 */
	public static function ticks( float $max, int $target = 4 ): array {
		if ( $max <= 0 ) {
			return array( 0.0, 1.0 );
		}
		$raw  = $max / max( 1, $target );
		$mag  = 10 ** floor( log10( $raw ) );
		$norm = $raw / $mag;
		if ( $norm <= 1 ) {
			$step = 1;
		} elseif ( $norm <= 2 ) {
			$step = 2;
		} elseif ( $norm <= 2.5 ) {
			$step = 2.5;
		} elseif ( $norm <= 5 ) {
			$step = 5;
		} else {
			$step = 10;
		}
		$step  = $step * $mag;
		$count = (int) ceil( $max / $step - 1e-9 );
		$out   = array();
		for ( $i = 0; $i <= $count; $i++ ) {
			$out[] = round( $i * $step, 6 );
		}

		return $out;
	}

	/**
	 * Unidad del eje de montos: millones de pesos desde un millón, miles bajo esa cifra.
	 *
	 * @param float $max Máximo del eje.
	 * @return array{div:float,label:string}
	 */
	public static function axis_unit( float $max ): array {
		return $max >= 1000000 ? array(
			'div'   => 1000000.0,
			'label' => __( 'Millones de pesos', 'gestion-de-proyectos' ),
		) : array(
			'div'   => 1000.0,
			'label' => __( 'Miles de pesos', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Número de un eje con coma decimal y sin ceros sobrantes.
	 *
	 * @param float $value Valor ya dividido por la unidad.
	 * @return string
	 */
	public static function axis_number( float $value ): string {
		$text = number_format( $value, 1, ',', '.' );

		return str_ends_with( $text, ',0' ) ? substr( $text, 0, -2 ) : $text;
	}

	/**
	 * Series acumuladas de la caja del Fondo, mes a mes: lo transferido (real
	 * hasta el mes en curso y, desde él, más lo que el plan programa), el
	 * gasto programado (desde el primer mes del plan, sobre lo pagado antes
	 * de él) y lo pagado (real, hasta el mes en curso).
	 *
	 * @param string[]                       $months   Meses del eje (AAAA-MM), en orden.
	 * @param array<string,float>            $paid     Pagado por mes.
	 * @param array<string,float>            $received Recibido por mes.
	 * @param array<int,array<string,mixed>> $plan     Filas del plan (period, transfer, spend).
	 * @param string                         $current  Mes en curso (AAAA-MM).
	 * @return array<string,mixed>
	 */
	public static function cash_curve( array $months, array $paid, array $received, array $plan, string $current ): array {
		$spend    = array();
		$transfer = array();
		foreach ( $plan as $row ) {
			$period              = (string) ( $row['period'] ?? '' );
			$spend[ $period ]    = (float) ( $spend[ $period ] ?? 0 ) + (float) ( $row['spend'] ?? 0 );
			$transfer[ $period ] = (float) ( $transfer[ $period ] ?? 0 ) + (float) ( $row['transfer'] ?? 0 );
		}
		ksort( $spend );
		$first_plan = $spend ? (string) array_key_first( $spend ) : '';
		$first_axis = $months ? (string) $months[0] : '';

		$paid_cum     = 0.0;
		$received_cum = 0.0;
		$plan_base    = 0.0;
		foreach ( $paid as $period => $amount ) {
			if ( '' !== $first_axis && $period < $first_axis ) {
				$paid_cum += (float) $amount;
			}
			if ( '' !== $first_plan && $period < $first_plan ) {
				$plan_base += (float) $amount;
			}
		}
		foreach ( $received as $period => $amount ) {
			if ( '' !== $first_axis && $period < $first_axis ) {
				$received_cum += (float) $amount;
			}
		}

		// Lo que el plan programa para el mes en curso y todavía no llegó se proyecta en ese mismo mes.
		$pending_now = max( 0.0, (float) ( $transfer[ $current ] ?? 0 ) - (float) ( $received[ $current ] ?? 0 ) );
		$projected   = 0.0;
		$planned_cum = null;
		$out         = array(
			'paid'      => array(),
			'planned'   => array(),
			'transfers' => array(),
		);
		$max         = 0.0;
		$from        = count( $months );
		foreach ( $months as $i => $m ) {
			$paid_cum += (float) ( $paid[ $m ] ?? 0 );
			if ( $m <= $current ) {
				$received_cum += (float) ( $received[ $m ] ?? 0 );
			}
			$out['paid'][ $i ] = $m <= $current ? round( $paid_cum, 2 ) : null;

			if ( '' !== $first_plan && $m >= $first_plan ) {
				$planned_cum          = ( $planned_cum ?? $plan_base ) + (float) ( $spend[ $m ] ?? 0 );
				$out['planned'][ $i ] = round( $planned_cum, 2 );
			} else {
				$out['planned'][ $i ] = null;
			}

			if ( $m === $current ) {
				$projected = $pending_now;
				$from      = min( $from, $i );
			} elseif ( $m > $current ) {
				$projected += (float) ( $transfer[ $m ] ?? 0 );
				$from       = min( $from, $i );
			}
			$out['transfers'][ $i ] = round( $received_cum + ( $m >= $current ? $projected : 0.0 ), 2 );

			foreach ( array( $out['paid'][ $i ], $out['planned'][ $i ], $out['transfers'][ $i ] ) as $v ) {
				if ( null !== $v && $v > $max ) {
					$max = $v;
				}
			}
		}

		$to_date = 0.0;
		foreach ( $received as $period => $amount ) {
			if ( (string) $period <= $current ) {
				$to_date += (float) $amount;
			}
		}

		return array(
			'months'           => array_values( $months ),
			'paid'             => $out['paid'],
			'planned'          => $out['planned'],
			'transfers'        => $out['transfers'],
			'projected_from'   => $from,
			'received_to_date' => round( $to_date, 2 ),
			'max'              => max( 1.0, $max ),
		);
	}

	/**
	 * Meses entre dos períodos AAAA-MM, ambos incluidos.
	 *
	 * @param string $from Desde.
	 * @param string $to   Hasta.
	 * @return string[]
	 */
	public static function months( string $from, string $to ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}$/', $to ) || $to < $from ) {
			return array();
		}
		$out = array();
		$y   = (int) substr( $from, 0, 4 );
		$m   = (int) substr( $from, 5, 2 );
		for ( $guard = 0; $guard < 240; $guard++ ) {
			$period = sprintf( '%04d-%02d', $y, $m );
			$out[]  = $period;
			if ( $period >= $to ) {
				break;
			}
			++$m;
			if ( $m > 12 ) {
				$m = 1;
				++$y;
			}
		}

		return $out;
	}

	/**
	 * Estado visual de cada mes de la línea de tiempo de rendiciones. Un mes
	 * vencido sin rendición registrada se informa como "vencida sin
	 * declarar": el módulo no lee la plataforma, de modo que ignora si se
	 * presentó en ella.
	 *
	 * @param array<int,array<string,mixed>> $timeline Línea de tiempo del estado de cuentas.
	 * @param array<string,string>           $labels   Estado de la rendición => etiqueta.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rendition_cells( array $timeline, array $labels ): array {
		$out = array();
		foreach ( $timeline as $t ) {
			$r      = $t['rendition'] ?? null;
			$status = is_array( $r ) ? (string) ( $r['status'] ?? '' ) : '';
			if ( '' !== $status ) {
				switch ( $status ) {
					case 'aprobada':
						$state = 'ok';
						break;
					case 'rendida':
					case 'en_revision':
						$state = 'sent';
						break;
					case 'aprobada_parcial':
						$state = 'warn';
						break;
					case 'devuelta':
						$state = 'bad';
						break;
					default:
						$state = 'progress';
				}
				$label = $labels[ $status ] ?? $status;
			} elseif ( ! empty( $t['current'] ) ) {
				$state = 'current';
				$label = __( 'Mes en curso', 'gestion-de-proyectos' );
			} elseif ( ! empty( $t['overdue'] ) ) {
				$state = 'bad';
				$label = __( 'Vencida sin declarar', 'gestion-de-proyectos' );
			} else {
				$state = 'todo';
				$label = __( 'Por presentar', 'gestion-de-proyectos' );
			}
			$out[] = array(
				'period'       => (string) $t['period'],
				'state'        => $state,
				'label'        => $label,
				'kind'         => is_array( $r ) ? (string) ( $r['kind'] ?? '' ) : (string) ( $t['expected_kind'] ?? '' ),
				'amount'       => (float) ( $t['amount'] ?? 0 ),
				'internal_due' => (string) ( $t['internal_due'] ?? '' ),
				'platform_due' => (string) ( $t['platform_due'] ?? '' ),
				'fix_due'      => is_array( $r ) ? (string) ( $r['fix_due'] ?? '' ) : '',
			);
		}

		return $out;
	}

	/**
	 * Alertas del estado de cuentas, de la más grave a la más leve. Cada una
	 * dice qué ocurre, cuánto y dónde mirarlo (pestaña del tablero).
	 *
	 * @param array<string,mixed>            $status     Estado de cuentas (FinanceService::status).
	 * @param array<int,array<string,mixed>> $guarantees Garantías del proyecto.
	 * @return array<int,array{severity:string,text:string,tab:string}>
	 */
	public static function alerts( array $status, array $guarantees = array() ): array {
		$out       = array();
		$today     = (string) ( $status['today'] ?? '' );
		$agreement = (array) ( $status['agreement'] ?? array() );
		$add       = static function ( string $severity, string $text, string $tab ) use ( &$out ): void {
			$out[] = array(
				'severity' => $severity,
				'text'     => self::display_text( $text ),
				'tab'      => $tab,
			);
		};

		if ( (float) ( $agreement['fund_amount'] ?? 0 ) <= 0 || empty( $agreement['start_date'] ) ) {
			$add( 'critical', __( 'El convenio no está registrado (monto del Fondo y fecha de inicio): sin esos datos no se calculan plazos, brechas ni disponibles.', 'gestion-de-proyectos' ), 'convenio' );
		}

		$overdue = array_values( array_map( 'strval', (array) ( $status['overdue_renditions'] ?? array() ) ) );
		if ( $overdue ) {
			/* translators: 1: número de rendiciones, 2: lista de meses. */
			$add( 'critical', sprintf( __( '%1$d rendiciones mensuales vencidas sin estado declarado (%2$s). Si ya se presentaron en SISREC, declare su estado; si no, están atrasadas, bloquean todo giro y su omisión es causal de término anticipado.', 'gestion-de-proyectos' ), count( $overdue ), self::periods_label( $overdue ) ), 'rendiciones' );
		}

		foreach ( (array) ( $status['renditions'] ?? array() ) as $t ) {
			$r = $t['rendition'] ?? null;
			if ( is_array( $r ) && 'devuelta' === ( $r['status'] ?? '' ) ) {
				/* translators: 1: mes, 2: fecha límite. */
				$add( 'critical', sprintf( __( 'La rendición de %1$s fue devuelta: subsanar a más tardar el %2$s.', 'gestion-de-proyectos' ), self::month_label( (string) $t['period'] ), self::date( (string) ( $r['fix_due'] ?? '' ) ) ), 'rendiciones' );
			}
		}

		$fund = (array) ( $status['sources']['fondo'] ?? array() );
		foreach ( (array) ( $status['items'] ?? array() ) as $item ) {
			$f = (array) ( $item['sources']['fondo'] ?? array() );
			if ( (float) ( $f['available'] ?? 0 ) < -0.5 ) {
				/* translators: 1: ítem, 2: monto. */
				$add( 'critical', sprintf( __( 'El ítem %1$s supera su asignado en %2$s entre lo pagado y lo comprometido.', 'gestion-de-proyectos' ), (string) $item['label'], self::money( - (float) $f['available'] ) ), 'items' );
			}
			if ( is_array( $item['cap'] ?? null ) && false === $item['cap']['ok'] ) {
				/* translators: 1: ítem, 2: monto del tope. */
				$add( 'critical', sprintf( __( 'El ítem %1$s excede el tope de las bases (%2$s).', 'gestion-de-proyectos' ), (string) $item['label'], self::money( (float) $item['cap']['limit'] ) ), 'items' );
			}
		}

		$next = $status['next_installment'] ?? null;
		if ( is_array( $next ) ) {
			$gaps = (array) $next['gaps'];
			if ( (float) $gaps['pay_gap'] > 0.5 || (float) $gaps['render_gap'] > 0.5 ) {
				/* translators: 1: número de la cuota, 2: monto por pagar, 3: monto por rendir, 4: monto de la garantía. */
				$text = sprintf( __( 'Para la cuota %1$d faltan %2$s por pagar y %3$s por rendir; la alternativa es una garantía por %4$s.', 'gestion-de-proyectos' ), (int) $next['number'], self::money( (float) $gaps['pay_gap'] ), self::money( (float) $gaps['render_gap'] ), self::money( (float) $gaps['guarantee'] ) );
				if ( ! empty( $next['latest_month'] ) ) {
					/* translators: 1: mes, 2: fecha. */
					$text .= ' ' . sprintf( __( 'Último mes de pago útil: %1$s (rendición hasta el %2$s).', 'gestion-de-proyectos' ), self::month_label( (string) $next['latest_month'] ), self::date( (string) $next['latest_render_due'] ) );
				} else {
					$text .= ' ' . __( 'Ningún mes de pago alcanza ya la fecha objetivo del giro.', 'gestion-de-proyectos' );
				}
				$soon = empty( $next['latest_month'] ) || (string) $next['latest_month'] <= substr( $today, 0, 7 );
				$add( $soon ? 'critical' : 'warning', $text, 'cuotas' );
			}
			foreach ( (array) $next['conditions'] as $c ) {
				if ( 'G3' === ( $c['key'] ?? '' ) && false === $c['ok'] ) {
					$add( 'warning', (string) $c['detail'], 'cuotas' );
				}
			}
		}

		foreach ( (array) ( $status['installments'] ?? array() ) as $i ) {
			if ( ! empty( $i['is_received'] ) && empty( $i['receipt_sent_at'] ) ) {
				/* translators: número de la cuota. */
				$add( 'warning', sprintf( __( 'Sin registro del envío del comprobante de ingreso de la cuota %d (condición de giro G6).', 'gestion-de-proyectos' ), (int) $i['number'] ), 'cuotas' );
			}
		}

		if ( (float) ( $fund['observed'] ?? 0 ) > 0.5 ) {
			/* translators: monto. */
			$add( 'warning', sprintf( __( 'Pagos observados por el otorgante por %s: aclarar o corregir dentro del plazo de subsanación.', 'gestion-de-proyectos' ), self::money( (float) $fund['observed'] ) ), 'pagos' );
		}

		$used = (float) ( $fund['paid'] ?? 0 ) + (float) ( $fund['committed'] ?? 0 );
		if ( (float) ( $fund['received'] ?? 0 ) > 0 && $used > (float) $fund['received'] + 0.5 ) {
			/* translators: monto. */
			$add( 'info', sprintf( __( 'Lo pagado más lo comprometido supera lo transferido en %s: esa parte de los compromisos depende de la cuota siguiente.', 'gestion-de-proyectos' ), self::money( $used - (float) $fund['received'] ) ), 'resumen' );
		}

		foreach ( (array) ( $status['sources'] ?? array() ) as $s ) {
			if ( empty( $s['non_negative'] ) && isset( $s['non_negative'] ) ) {
				/* translators: fuente. */
				$add( 'critical', sprintf( __( 'Caja negativa en %s: lo pagado supera lo recibido.', 'gestion-de-proyectos' ), (string) $s['label'] ), 'caja' );
			}
			if ( null !== ( $s['difference'] ?? null ) && abs( (float) $s['difference'] ) > 0.5 ) {
				/* translators: 1: fuente, 2: monto. */
				$add( 'warning', sprintf( __( 'La cartola de %1$s difiere del saldo calculado en %2$s.', 'gestion-de-proyectos' ), (string) $s['label'], self::money( (float) $s['difference'] ) ), 'caja' );
			}
		}

		$plan = $status['cash_plan'] ?? null;
		if ( is_array( $plan ) ) {
			$failing = array();
			foreach ( (array) $plan['checks'] as $c ) {
				if ( false === ( $c['ok'] ?? null ) ) {
					$failing[] = (string) $c['key'];
				}
			}
			if ( $failing ) {
				/* translators: lista de controles. */
				$add( 'warning', sprintf( __( 'La programación de caja no cumple los controles %s.', 'gestion-de-proyectos' ), implode( ', ', $failing ) ), 'caja' );
			}
		}

		foreach ( $guarantees as $g ) {
			if ( 'vigente' === ( $g['status'] ?? '' ) && ! empty( $g['valid_until'] ) && '' !== $today && (string) $g['valid_until'] <= gmdate( 'Y-m-d', (int) strtotime( $today . ' +30 days' ) ) ) {
				/* translators: 1: fecha, 2: monto. */
				$add( 'warning', sprintf( __( 'Una garantía vence el %1$s (%2$s): renovarla o reemplazarla.', 'gestion-de-proyectos' ), self::date( (string) $g['valid_until'] ), self::money( (float) ( $g['amount'] ?? 0 ) ) ), 'convenio' );
			}
		}

		$order = array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
		);
		usort(
			$out,
			static fn( array $a, array $b ): int => ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 )
		);

		return $out;
	}

	/**
	 * Lista breve de meses: "enero a agosto de 2026" si son consecutivos del
	 * mismo año; si no, los meses separados por comas.
	 *
	 * @param string[] $periods Meses AAAA-MM.
	 * @return string
	 */
	public static function periods_label( array $periods ): string {
		$periods = array_values( array_unique( $periods ) );
		sort( $periods );
		if ( ! $periods ) {
			return '';
		}
		if ( 1 === count( $periods ) ) {
			return self::month_label( $periods[0] );
		}
		$first = $periods[0];
		$last  = $periods[ count( $periods ) - 1 ];
		if ( self::months( $first, $last ) === $periods ) {
			if ( substr( $first, 0, 4 ) === substr( $last, 0, 4 ) ) {
				/* translators: 1: mes inicial, 2: mes final, 3: año. */
				return sprintf( __( '%1$s a %2$s de %3$s', 'gestion-de-proyectos' ), self::month_name( $first ), self::month_name( $last ), substr( $last, 0, 4 ) );
			}
			/* translators: 1: mes inicial (con su año si los años difieren), 2: mes final (ídem). */
			return sprintf( __( '%1$s a %2$s', 'gestion-de-proyectos' ), self::month_label( $first ), self::month_label( $last ) );
		}

		// Meses salteados: tramos consecutivos agrupados por año
		// ("febrero a mayo y agosto de 2026").
		$years = array();
		foreach ( $periods as $p ) {
			$years[ substr( $p, 0, 4 ) ][] = $p;
		}
		$parts = array();
		foreach ( $years as $year => $list ) {
			$runs  = array();
			$start = $list[0];
			$prev  = $list[0];
			foreach ( array_slice( $list, 1 ) as $p ) {
				if ( self::months( $prev, $p ) !== array( $prev, $p ) ) {
					$runs[] = array( $start, $prev );
					$start  = $p;
				}
				$prev = $p;
			}
			$runs[] = array( $start, $prev );
			$names  = array_map(
				/* translators: 1: mes inicial (con su año si los años difieren), 2: mes final (ídem). */
				static fn( array $r ): string => $r[0] === $r[1] ? self::month_name( $r[0] ) : sprintf( __( '%1$s a %2$s', 'gestion-de-proyectos' ), self::month_name( $r[0] ), self::month_name( $r[1] ) ),
				$runs
			);
			$last   = array_pop( $names );
			$joined = $names ? sprintf( /* translators: 1: lista, 2: último elemento. */ __( '%1$s y %2$s', 'gestion-de-proyectos' ), implode( ', ', $names ), $last ) : $last;
			/* translators: 1: mes o lista de meses, 2: año. */
			$parts[] = sprintf( __( '%1$s de %2$s', 'gestion-de-proyectos' ), $joined, (string) $year );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Nombre del tipo de rendición.
	 *
	 * @param string $kind mensual, sin_movimiento, regularizacion o final.
	 * @return string
	 */
	public static function rendition_kind_label( string $kind ): string {
		$labels = array(
			'mensual'        => __( 'mensual', 'gestion-de-proyectos' ),
			'sin_movimiento' => __( 'sin movimiento', 'gestion-de-proyectos' ),
			'regularizacion' => __( 'regularización', 'gestion-de-proyectos' ),
			'final'          => __( 'final', 'gestion-de-proyectos' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Nombre del mes con año ("agosto de 2026").
	 *
	 * @param string $period AAAA-MM.
	 * @return string
	 */
	public static function month_label( string $period ): string {
		/* translators: 1: mes o lista de meses, 2: año. */
		return sprintf( __( '%1$s de %2$s', 'gestion-de-proyectos' ), self::month_name( $period ), substr( $period, 0, 4 ) );
	}

	/**
	 * Nombre del mes.
	 *
	 * @param string $period AAAA-MM.
	 * @return string
	 */
	public static function month_name( string $period ): string {
		$names = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );

		return $names[ (int) substr( $period, 5, 2 ) - 1 ] ?? $period;
	}

	/**
	 * Abreviatura del mes con el año en dos cifras ("oct 26").
	 *
	 * @param string $period AAAA-MM.
	 * @return string
	 */
	public static function month_short( string $period ): string {
		$names = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );

		return ( $names[ (int) substr( $period, 5, 2 ) - 1 ] ?? $period ) . ' ' . substr( $period, 2, 2 );
	}

	/**
	 * Indica si un texto es una fecha AAAA-MM-DD.
	 *
	 * @param string $value Texto.
	 * @return bool
	 */
	private static function is_date( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}
}
