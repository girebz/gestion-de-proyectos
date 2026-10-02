<?php
/**
 * Gráficos del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

use GDP\Modules\Finance\Logic\BoardMetrics;

defined( 'ABSPATH' ) || exit;

/**
 * Gráficos sin bibliotecas externas: barras apiladas, medidores y franjas en
 * HTML con anchos porcentuales (nítidos y adaptables a cualquier ancho) y la
 * curva de caja en SVG. Cada gráfico lleva su leyenda con los valores, un
 * nombre accesible y su tabla equivalente, de modo que ninguna cifra queda
 * disponible solo al pasar el puntero; los recuadros emergentes solo
 * repiten lo que ya está escrito.
 *
 * Los tonos siguen una escala ordinal del color principal del sitio: más
 * oscuro cuanto más avanzado el dinero (pagado sobre comprometido, aprobado
 * sobre rendido); los colores de estado (cumple, advertencia, problema) se
 * reservan para estados y siempre van con símbolo y texto.
 */
final class BoardCharts {

	/**
	 * Barra apilada del uso de una fuente: pagado, comprometido y libre, con la
	 * marca de lo recibido.
	 *
	 * @param array<string,mixed>  $u       Resultado de BoardMetrics::usage().
	 * @param string               $title   Título.
	 * @param array<string,string> $labels Etiquetas de paid, committed, free y received.
	 * @return string HTML.
	 */
	public static function usage( array $u, string $title, array $labels ): string {
		$total = (float) $u['total'];
		$rows  = array();
		$segs  = '';
		$aria  = array();
		foreach ( $u['segments'] as $s ) {
			$pct    = BoardMetrics::pct( (float) $s['amount'], $total );
			$text   = sprintf( '%s: %s (%s)', $labels[ $s['key'] ], BoardMetrics::money( (float) $s['amount'] ), BoardMetrics::pct_label( $pct ) );
			$aria[] = $text;
			$rows[] = array( $labels[ $s['key'] ], BoardMetrics::money( (float) $s['amount'] ), BoardMetrics::pct_label( $pct ) );
			if ( (float) $s['amount'] > 0 ) {
				$segs .= sprintf( '<span class="gdp-fin-seg gdp-fin-seg--%1$s" style="width:%2$s%%" tabindex="0" data-gdp-tip="%3$s" aria-label="%3$s"></span>', esc_attr( $s['key'] ), esc_attr( self::num( (float) $s['pct'] ) ), esc_attr( $text ) );
			}
		}
		$marker = '';
		if ( null !== $u['received_pct'] && (float) $u['received'] > 0 ) {
			$pct    = BoardMetrics::pct( (float) $u['received'], $total );
			$text   = sprintf( '%s: %s (%s)', $labels['received'], BoardMetrics::money( (float) $u['received'] ), BoardMetrics::pct_label( $pct ) );
			$aria[] = $text;
			$rows[] = array( $labels['received'], BoardMetrics::money( (float) $u['received'] ), BoardMetrics::pct_label( $pct ) );
			$side   = (float) $u['received_pct'] > 70 ? ' gdp-fin-marker--left' : '';
			$marker = sprintf( '<span class="gdp-fin-marker%1$s" style="left:%2$s%%" tabindex="0" data-gdp-tip="%3$s" aria-label="%3$s"><span class="gdp-fin-marker__label">%4$s</span></span>', esc_attr( $side ), esc_attr( self::num( (float) $u['received_pct'] ) ), esc_attr( $text ), esc_html( $labels['received'] . ' ' . BoardMetrics::pct_label( $pct ) ) );
		}

		$html  = '<figure class="gdp-fin-chart gdp-fin-usage">';
		$html .= '<figcaption class="gdp-fin-chart__title">' . esc_html( $title ) . ' <span class="gdp-fin-muted">' . esc_html( sprintf( /* translators: monto total. */ __( 'sobre %s', 'gestion-de-proyectos' ), BoardMetrics::money( $total ) ) ) . '</span></figcaption>';
		$html .= self::legend(
			array(
				array( 'paid', $labels['paid'], BoardMetrics::money( (float) $u['paid'] ) ),
				array( 'committed', $labels['committed'], BoardMetrics::money( (float) $u['committed'] ) ),
				array( 'free', $labels['free'], BoardMetrics::money( (float) $u['free'] ) ),
				array( 'received', $labels['received'], BoardMetrics::money( (float) $u['received'] ) ),
			)
		);
		$html .= '<div class="gdp-fin-stack" role="img" aria-label="' . esc_attr( $title . '. ' . implode( '; ', $aria ) ) . '">' . $segs . $marker . '</div>';
		$html .= '<div class="gdp-fin-scale" aria-hidden="true"><span>0 %</span><span>50 %</span><span>100 %</span></div>';
		$html .= self::table_view( array( __( 'Concepto', 'gestion-de-proyectos' ), __( 'Monto', 'gestion-de-proyectos' ), __( 'Porcentaje del total', 'gestion-de-proyectos' ) ), $rows );
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Medidores del avance de cada cuota: aprobado, rendido, pagado y por pagar.
	 *
	 * @param array<int,array<string,mixed>> $installments Cuotas del estado de cuentas.
	 * @param array<string,string>           $labels       Etiquetas de approved, rendered, paid y pending.
	 * @return string HTML.
	 */
	public static function installments( array $installments, array $labels ): string {
		if ( empty( $installments ) ) {
			return '';
		}
		$rows  = array();
		$html  = '<figure class="gdp-fin-chart gdp-fin-meters gdp-fin-installments">';
		$html .= '<figcaption class="gdp-fin-chart__title">' . esc_html__( 'Avance de cada cuota', 'gestion-de-proyectos' ) . ' <span class="gdp-fin-muted">' . esc_html__( 'sobre el monto de la cuota', 'gestion-de-proyectos' ) . '</span></figcaption>';
		$html .= self::legend(
			array(
				array( 'inst-approved', $labels['approved'], '' ),
				array( 'inst-rendered', $labels['rendered'], '' ),
				array( 'inst-paid', $labels['paid'], '' ),
				array( 'inst-pending', $labels['pending'], '' ),
			)
		);
		$html .= '<ul class="gdp-fin-meterlist">';
		foreach ( $installments as $i ) {
			$amount = (float) $i['amount'];
			/* translators: 1: número de la cuota, 2: monto. */
			$name = sprintf( __( 'Cuota %1$d · %2$s', 'gestion-de-proyectos' ), (int) $i['number'], BoardMetrics::money( $amount ) );
			if ( ! empty( $i['is_received'] ) ) {
				/* translators: fecha. */
				$state = sprintf( __( 'recibida el %s', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $i['received_on'] ) );
				$segs  = '';
				$aria  = array();
				foreach ( BoardMetrics::installment_segments( $amount, (float) $i['paid'], (float) $i['rendered'], (float) $i['approved'] ) as $s ) {
					$text   = sprintf( '%s: %s (%s)', $labels[ $s['key'] ], BoardMetrics::money( (float) $s['amount'] ), BoardMetrics::pct_label( (float) $s['pct'] ) );
					$aria[] = $text;
					if ( (float) $s['amount'] > 0 ) {
						$segs .= sprintf( '<span class="gdp-fin-seg gdp-fin-seg--inst-%1$s" style="width:%2$s%%" tabindex="0" data-gdp-tip="%3$s" aria-label="%3$s"></span>', esc_attr( $s['key'] ), esc_attr( self::num( (float) $s['pct'] ) ), esc_attr( $text ) );
					}
				}
				$bar    = '<div class="gdp-fin-stack gdp-fin-stack--thin" role="img" aria-label="' . esc_attr( $name . '. ' . implode( '; ', $aria ) ) . '">' . $segs . '</div>';
				$detail = sprintf(
					/* translators: 1: pagado, 2: rendido, 3: aprobado. */
					__( 'Pagado %1$s · rendido %2$s · aprobado %3$s', 'gestion-de-proyectos' ),
					BoardMetrics::money( (float) $i['paid'] ),
					BoardMetrics::money( (float) $i['rendered'] ),
					BoardMetrics::money( (float) $i['approved'] )
				);
			} else {
				/* translators: ventana del programa de desembolso. */
				$state  = sprintf( __( 'por recibir · ventana %s', 'gestion-de-proyectos' ), trim( BoardMetrics::date( (string) $i['window_from'] ) . ' a ' . BoardMetrics::date( (string) $i['window_to'] ) ) );
				$bar    = '<div class="gdp-fin-stack gdp-fin-stack--thin gdp-fin-stack--future" role="img" aria-label="' . esc_attr( $name . ': ' . $state ) . '"></div>';
				$detail = (int) $i['report_no'] > 0 ? sprintf( /* translators: número del informe. */ __( 'Habilita el informe de avance N.º %d aprobado', 'gestion-de-proyectos' ), (int) $i['report_no'] ) : '';
			}
			$rows[] = array( $name, $state, BoardMetrics::money( (float) $i['paid'] ), BoardMetrics::money( (float) $i['rendered'] ), BoardMetrics::money( (float) $i['approved'] ) );
			$html  .= '<li class="gdp-fin-meterrow"><div class="gdp-fin-meterrow__name"><strong>' . esc_html( $name ) . '</strong><span class="gdp-fin-muted">' . esc_html( $state ) . '</span></div>' . $bar . '<div class="gdp-fin-meterrow__nums gdp-fin-muted">' . esc_html( $detail ) . '</div></li>';
		}
		$html .= '</ul>';
		$html .= self::table_view( array( __( 'Cuota', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Rendido', 'gestion-de-proyectos' ), __( 'Aprobado', 'gestion-de-proyectos' ) ), $rows );
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Medidores de ejecución por ítem con cargo a una fuente: pagado y
	 * comprometido sobre el asignado de cada ítem (cada barra mide su propio
	 * asignado, de modo que se comparan porcentajes y no montos).
	 *
	 * @param array<int,array<string,mixed>> $items  Ítems del estado de cuentas.
	 * @param string                         $source fondo o pecuniario.
	 * @param string                         $title  Título.
	 * @return string HTML.
	 */
	public static function items( array $items, string $source, string $title ): string {
		$rows = array();
		$list = '';
		foreach ( $items as $i ) {
			$s        = (array) ( $i['sources'][ $source ] ?? array() );
			$assigned = (float) ( $s['assigned'] ?? 0 );
			$paid     = (float) ( $s['paid'] ?? 0 );
			$commit   = (float) ( $s['committed'] ?? 0 );
			$avail    = (float) ( $s['available'] ?? 0 );
			if ( $assigned <= 0 && $paid <= 0 && $commit <= 0 ) {
				continue;
			}
			$base      = max( $assigned, $paid + $commit, 1.0 );
			$p_paid    = 100 * $paid / $base;
			$p_commit  = 100 * $commit / $base;
			$used_pct  = BoardMetrics::pct( $paid + $commit, $assigned );
			$text_paid = sprintf( '%s · %s: %s', (string) $i['label'], __( 'pagado', 'gestion-de-proyectos' ), BoardMetrics::money( $paid ) );
			$text_com  = sprintf( '%s · %s: %s', (string) $i['label'], __( 'comprometido', 'gestion-de-proyectos' ), BoardMetrics::money( $commit ) );
			$segs      = '';
			if ( $paid > 0 ) {
				$segs .= sprintf( '<span class="gdp-fin-seg gdp-fin-seg--paid" style="width:%1$s%%" tabindex="0" data-gdp-tip="%2$s" aria-label="%2$s"></span>', esc_attr( self::num( $p_paid ) ), esc_attr( $text_paid ) );
			}
			if ( $commit > 0 ) {
				$segs .= sprintf( '<span class="gdp-fin-seg gdp-fin-seg--committed" style="width:%1$s%%" tabindex="0" data-gdp-tip="%2$s" aria-label="%2$s"></span>', esc_attr( self::num( $p_commit ) ), esc_attr( $text_com ) );
			}
			$over = $avail < -0.5;
			$nums = sprintf(
				/* translators: 1: asignado, 2: disponible, 3: porcentaje usado. */
				__( 'Asignado %1$s · disponible %2$s · usado %3$s', 'gestion-de-proyectos' ),
				BoardMetrics::money( $assigned ),
				BoardMetrics::money( $avail ),
				BoardMetrics::pct_label( $used_pct )
			);
			$list  .= '<li class="gdp-fin-meterrow' . ( $over ? ' gdp-fin-meterrow--over' : '' ) . '"><div class="gdp-fin-meterrow__name"><strong>' . esc_html( (string) $i['label'] ) . '</strong>' . ( $over ? '<span class="gdp-fin-flag gdp-fin-flag--bad"><span aria-hidden="true">✕</span> ' . esc_html__( 'excede el asignado', 'gestion-de-proyectos' ) . '</span>' : '' ) . '</div><div class="gdp-fin-stack gdp-fin-stack--thin" role="img" aria-label="' . esc_attr( (string) $i['label'] . '. ' . $nums ) . '">' . $segs . '</div><div class="gdp-fin-meterrow__nums gdp-fin-muted">' . esc_html( $nums ) . '</div></li>';
			$rows[] = array( (string) $i['label'], BoardMetrics::money( $assigned ), BoardMetrics::money( $paid ), BoardMetrics::money( $commit ), BoardMetrics::money( $avail ), BoardMetrics::pct_label( $used_pct ) );
		}
		if ( '' === $list ) {
			return '';
		}
		$html  = '<figure class="gdp-fin-chart gdp-fin-meters">';
		$html .= '<figcaption class="gdp-fin-chart__title">' . esc_html( $title ) . ' <span class="gdp-fin-muted">' . esc_html__( 'cada barra sobre el asignado de su ítem', 'gestion-de-proyectos' ) . '</span></figcaption>';
		$html .= self::legend(
			array(
				array( 'paid', __( 'Pagado', 'gestion-de-proyectos' ), '' ),
				array( 'committed', __( 'Comprometido por pagar', 'gestion-de-proyectos' ), '' ),
				array( 'free', __( 'Disponible', 'gestion-de-proyectos' ), '' ),
			)
		);
		$html .= '<ul class="gdp-fin-meterlist">' . $list . '</ul>';
		$html .= self::table_view( array( __( 'Ítem', 'gestion-de-proyectos' ), __( 'Asignado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Comprometido', 'gestion-de-proyectos' ), __( 'Disponible', 'gestion-de-proyectos' ), __( 'Usado', 'gestion-de-proyectos' ) ), $rows );
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Franja de los meses con el estado de su rendición.
	 *
	 * @param array<int,array<string,mixed>> $cells Celdas (BoardMetrics::rendition_cells).
	 * @return string HTML.
	 */
	public static function strip( array $cells ): string {
		if ( empty( $cells ) ) {
			return '';
		}
		$icons = self::state_icons();
		$names = self::state_names();
		$seen  = array();
		$rows  = array();
		$html  = '<figure class="gdp-fin-chart gdp-fin-stripwrap">';
		$html .= '<figcaption class="gdp-fin-chart__title">' . esc_html__( 'Rendiciones mensuales del Fondo', 'gestion-de-proyectos' ) . ' <span class="gdp-fin-muted">' . esc_html__( 'estado declarado de cada mes', 'gestion-de-proyectos' ) . '</span></figcaption>';
		$list  = '<ol class="gdp-fin-strip">';
		foreach ( $cells as $c ) {
			$seen[ $c['state'] ] = true;
			$due                 = '' !== $c['platform_due'] ? sprintf( /* translators: fecha. */ __( 'plazo en la plataforma: %s', 'gestion-de-proyectos' ), BoardMetrics::date( $c['platform_due'] ) ) : '';
			$tip                 = trim( sprintf( '%s: %s. %s %s', BoardMetrics::month_label( $c['period'] ), $c['label'], sprintf( /* translators: monto. */ __( 'Pagado en el mes: %s.', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $c['amount'] ) ), $due ) );
			$list               .= '<li class="gdp-fin-cell gdp-fin-cell--' . esc_attr( $c['state'] ) . '" tabindex="0" data-gdp-tip="' . esc_attr( $tip ) . '" aria-label="' . esc_attr( $tip ) . '"><span class="gdp-fin-cell__icon" aria-hidden="true">' . esc_html( $icons[ $c['state'] ] ?? '' ) . '</span><span class="gdp-fin-cell__month">' . esc_html( BoardMetrics::month_short( $c['period'] ) ) . '</span><span class="gdp-fin-cell__label">' . esc_html( $c['label'] ) . '</span></li>';
			$rows[]              = array( BoardMetrics::month_label( $c['period'] ), $c['label'], BoardMetrics::money( (float) $c['amount'] ), BoardMetrics::date( $c['internal_due'] ), BoardMetrics::date( $c['platform_due'] ) );
		}
		$list .= '</ol>';
		$html .= $list;
		$html .= '<ul class="gdp-fin-legend gdp-fin-legend--states">';
		foreach ( $names as $state => $name ) {
			if ( isset( $seen[ $state ] ) ) {
				$html .= '<li><span class="gdp-fin-cellkey gdp-fin-cell--' . esc_attr( $state ) . '" aria-hidden="true">' . esc_html( $icons[ $state ] ) . '</span> ' . esc_html( $name ) . '</li>';
			}
		}
		$html .= '</ul>';
		$html .= self::table_view( array( __( 'Mes', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Pagado en el mes', 'gestion-de-proyectos' ), __( 'Respaldos a la universidad', 'gestion-de-proyectos' ), __( 'Plazo en la plataforma', 'gestion-de-proyectos' ) ), $rows );
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Curva de caja del Fondo en SVG: transferido acumulado (escalonado, con
	 * la parte proyectada en trazo discontinuo), gasto programado acumulado y
	 * pagado acumulado. Un solo eje en pesos.
	 *
	 * @param array<string,mixed> $curve Resultado de BoardMetrics::cash_curve().
	 * @param string              $current Mes en curso (AAAA-MM).
	 * @return string HTML.
	 */
	public static function cash_curve( array $curve, string $current ): string {
		$months = (array) $curve['months'];
		$n      = count( $months );
		if ( $n < 2 ) {
			return '';
		}
		$w      = 760;
		$h      = 300;
		$left   = 44;
		$right  = 14;
		$top    = 14;
		$bottom = 36;
		$pw     = $w - $left - $right;
		$ph     = $h - $top - $bottom;
		$ticks  = BoardMetrics::ticks( (float) $curve['max'], 4 );
		$ymax   = (float) end( $ticks );
		$unit   = BoardMetrics::axis_unit( $ymax );
		$x      = static fn( int $i ): float => $left + ( $n > 1 ? $i * $pw / ( $n - 1 ) : $pw / 2 );
		$y      = static fn( float $v ): float => $top + $ph - ( $ymax > 0 ? $v / $ymax * $ph : 0 );
		$fmt    = static fn( float $v ): string => number_format( $v, 1, '.', '' );

		$svg = '<svg class="gdp-fin-line" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">';
		foreach ( $ticks as $t ) {
			$ty   = $y( (float) $t );
			$svg .= '<line class="gdp-fin-line__grid" x1="' . $left . '" x2="' . ( $w - $right ) . '" y1="' . $fmt( $ty ) . '" y2="' . $fmt( $ty ) . '"/>';
			$svg .= '<text class="gdp-fin-line__tick" x="' . ( $left - 6 ) . '" y="' . $fmt( $ty + 4 ) . '" text-anchor="end">' . esc_html( BoardMetrics::axis_number( (float) $t / $unit['div'] ) ) . '</text>';
		}
		$step = max( 1, (int) ceil( $n / 9 ) );
		foreach ( $months as $i => $m ) {
			// El último rótulo se alinea a la derecha para no salirse del gráfico, y
			// el rótulo que le quedaría pegado se omite.
			$last = $i === $n - 1;
			if ( $last || ( 0 === $i % $step && $n - 1 - $i >= max( 1, (int) ceil( $step / 2 ) ) ) ) {
				$svg .= '<text class="gdp-fin-line__tick" x="' . $fmt( $x( $i ) ) . '" y="' . ( $h - $bottom + 18 ) . '" text-anchor="' . ( $last ? 'end' : ( 0 === $i ? 'start' : 'middle' ) ) . '">' . esc_html( BoardMetrics::month_short( (string) $m ) ) . '</text>';
			}
		}

		$pf = (int) $curve['projected_from'];
		// Transferido: escalonado, con un lavado de área y la parte proyectada en trazo discontinuo.
		$tr     = array_map( 'floatval', (array) $curve['transfers'] );
		$area   = 'M' . $fmt( $x( 0 ) ) . ' ' . $fmt( $y( 0 ) ) . ' V' . $fmt( $y( $tr[0] ) );
		$solid  = 'M' . $fmt( $x( 0 ) ) . ' ' . $fmt( $y( $tr[0] ) );
		$dashed = '';
		for ( $i = 1; $i < $n; $i++ ) {
			$area .= ' H' . $fmt( $x( $i ) ) . ' V' . $fmt( $y( $tr[ $i ] ) );
			if ( $i < $pf ) {
				$solid .= ' H' . $fmt( $x( $i ) ) . ' V' . $fmt( $y( $tr[ $i ] ) );
			} elseif ( $i === $pf ) {
				$solid  .= ' H' . $fmt( $x( $i ) );
				$dashed .= 'M' . $fmt( $x( $i ) ) . ' ' . $fmt( $y( $tr[ $i - 1 ] ) ) . ' V' . $fmt( $y( $tr[ $i ] ) );
			} else {
				$dashed .= ' H' . $fmt( $x( $i ) ) . ' V' . $fmt( $y( $tr[ $i ] ) );
			}
		}
		$area .= ' V' . $fmt( $y( 0 ) ) . ' Z';
		if ( 0 === $pf ) {
			$solid  = '';
			$dashed = 'M' . $fmt( $x( 0 ) ) . ' ' . $fmt( $y( $tr[0] ) );
			for ( $i = 1; $i < $n; $i++ ) {
				$dashed .= ' H' . $fmt( $x( $i ) ) . ' V' . $fmt( $y( $tr[ $i ] ) );
			}
		}
		$svg .= '<path class="gdp-fin-line__area" d="' . esc_attr( $area ) . '"/>';
		if ( '' !== $solid ) {
			$svg .= '<path class="gdp-fin-line__s gdp-fin-line__s--transfers" d="' . esc_attr( $solid ) . '"/>';
		}
		if ( '' !== $dashed ) {
			$svg .= '<path class="gdp-fin-line__s gdp-fin-line__s--transfers gdp-fin-line__s--projected" d="' . esc_attr( $dashed ) . '"/>';
		}

		// Gasto programado acumulado.
		$planned = self::polyline( (array) $curve['planned'], $x, $y, $fmt );
		if ( '' !== $planned ) {
			$svg .= '<path class="gdp-fin-line__s gdp-fin-line__s--planned" d="' . esc_attr( $planned ) . '"/>';
		}

		// Pagado acumulado, con el punto final.
		$paid = self::polyline( (array) $curve['paid'], $x, $y, $fmt );
		if ( '' !== $paid ) {
			$svg .= '<path class="gdp-fin-line__s gdp-fin-line__s--paid" d="' . esc_attr( $paid ) . '"/>';
			$last = null;
			foreach ( (array) $curve['paid'] as $i => $v ) {
				if ( null !== $v ) {
					$last = array( (int) $i, (float) $v );
				}
			}
			if ( $last ) {
				$svg .= '<circle class="gdp-fin-line__dot" cx="' . $fmt( $x( $last[0] ) ) . '" cy="' . $fmt( $y( $last[1] ) ) . '" r="4"/>';
			}
		}

		// Mes en curso.
		$ci = array_search( $current, $months, true );
		if ( false !== $ci ) {
			$cx   = $fmt( $x( (int) $ci ) );
			$svg .= '<line class="gdp-fin-line__today" x1="' . $cx . '" x2="' . $cx . '" y1="' . $top . '" y2="' . ( $h - $bottom ) . '"/>';
			$svg .= '<text class="gdp-fin-line__todaylabel" x="' . $cx . '" y="' . ( $top + 10 ) . '" text-anchor="' . ( (int) $ci > $n * 0.8 ? 'end' : 'start' ) . '" dx="' . ( (int) $ci > $n * 0.8 ? '-4' : '4' ) . '">' . esc_html__( 'hoy', 'gestion-de-proyectos' ) . '</text>';
		}
		$svg .= '<line class="gdp-fin-line__cross" x1="0" x2="0" y1="' . $top . '" y2="' . ( $h - $bottom ) . '" visibility="hidden"/>';
		$svg .= '<rect class="gdp-fin-line__hit" x="' . $left . '" y="' . $top . '" width="' . $pw . '" height="' . $ph . '"/>';
		$svg .= '</svg>';

		$labels = array(
			'transfers' => __( 'Transferido acumulado (proyectado en trazo discontinuo)', 'gestion-de-proyectos' ),
			'planned'   => __( 'Gasto programado acumulado', 'gestion-de-proyectos' ),
			'paid'      => __( 'Pagado acumulado', 'gestion-de-proyectos' ),
		);
		$names  = array_map( static fn( string $m ): string => BoardMetrics::month_label( $m ), $months );
		$series = array(
			array(
				'key'    => 'transfers',
				'label'  => __( 'Transferido acumulado', 'gestion-de-proyectos' ),
				'values' => $curve['transfers'],
				'from'   => $pf,
			),
			array(
				'key'    => 'planned',
				'label'  => $labels['planned'],
				'values' => $curve['planned'],
			),
			array(
				'key'    => 'paid',
				'label'  => $labels['paid'],
				'values' => $curve['paid'],
			),
		);
		$rows   = array();
		foreach ( $months as $i => $m ) {
			$rows[] = array(
				$names[ $i ],
				BoardMetrics::money( (float) $curve['transfers'][ $i ] ) . ( $i >= $pf ? ' ' . __( '(proyectado)', 'gestion-de-proyectos' ) : '' ),
				null === $curve['planned'][ $i ] ? '—' : BoardMetrics::money( (float) $curve['planned'][ $i ] ),
				null === $curve['paid'][ $i ] ? '—' : BoardMetrics::money( (float) $curve['paid'][ $i ] ),
			);
		}
		$last_paid   = 0.0;
		$planned_end = 0.0;
		foreach ( (array) $curve['paid'] as $v ) {
			if ( null !== $v ) {
				$last_paid = (float) $v;
			}
		}
		foreach ( (array) $curve['planned'] as $v ) {
			if ( null !== $v ) {
				$planned_end = max( $planned_end, (float) $v );
			}
		}
		$summary = sprintf(
			/* translators: 1: pagado acumulado, 2: transferido acumulado a la fecha, 3: gasto programado al término. */
			__( 'Curva de caja del Fondo: pagado acumulado %1$s; transferido acumulado a la fecha %2$s; gasto programado al término %3$s.', 'gestion-de-proyectos' ),
			BoardMetrics::money( $last_paid ),
			BoardMetrics::money( (float) ( $curve['received_to_date'] ?? 0 ) ),
			BoardMetrics::money( $planned_end )
		);

		$html  = '<figure class="gdp-fin-chart gdp-fin-curve">';
		$html .= '<figcaption class="gdp-fin-chart__title">' . esc_html__( 'Caja del Fondo: transferido, programado y pagado', 'gestion-de-proyectos' ) . ' <span class="gdp-fin-muted">' . esc_html( $unit['label'] ) . '</span></figcaption>';
		$html .= '<ul class="gdp-fin-legend gdp-fin-legend--lines">';
		foreach ( $labels as $key => $label ) {
			$html .= '<li><span class="gdp-fin-linekey gdp-fin-linekey--' . esc_attr( $key ) . '" aria-hidden="true"></span>' . esc_html( $label ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '<div class="gdp-fin-linewrap" role="img" tabindex="0" aria-label="' . esc_attr( $summary ) . '" data-gdp-curve="' . esc_attr(
			(string) wp_json_encode(
				array(
					'months' => $names,
					'series' => $series,
					'n'      => $n,
					'left'   => $left,
					'width'  => $pw,
					'w'      => $w,
				)
			)
		) . '">' . $svg . '</div>';
		$html .= self::table_view( array( __( 'Mes', 'gestion-de-proyectos' ), __( 'Transferido acumulado', 'gestion-de-proyectos' ), __( 'Gasto programado acumulado', 'gestion-de-proyectos' ), __( 'Pagado acumulado', 'gestion-de-proyectos' ) ), $rows );
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Medidor simple (una proporción frente a su total).
	 *
	 * @param float  $pct     Porcentaje.
	 * @param string $label   Texto accesible.
	 * @param string $variant Variante (time, paid, committed, rendered o approved).
	 * @return string HTML.
	 */
	public static function meter( float $pct, string $label, string $variant = 'paid' ): string {
		$pct = max( 0.0, min( 100.0, $pct ) );

		return '<div class="gdp-fin-meter gdp-fin-meter--' . esc_attr( $variant ) . '" role="img" aria-label="' . esc_attr( $label ) . '"><span style="width:' . esc_attr( self::num( $pct ) ) . '%"></span></div>';
	}

	/**
	 * Leyenda de barras: muestra del color, etiqueta y, si se indica, monto.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $entries Clave, etiqueta, monto.
	 * @return string HTML.
	 */
	public static function legend( array $entries ): string {
		$html = '<ul class="gdp-fin-legend">';
		foreach ( $entries as $e ) {
			$html .= '<li><span class="gdp-fin-swatch gdp-fin-swatch--' . esc_attr( $e[0] ) . '" aria-hidden="true"></span>' . esc_html( $e[1] ) . ( '' !== $e[2] ? ' <strong>' . esc_html( $e[2] ) . '</strong>' : '' ) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Tabla equivalente de un gráfico, plegada.
	 *
	 * @param string[]                 $head Encabezados.
	 * @param array<int,array<string>> $rows Filas ya formateadas.
	 * @return string HTML.
	 */
	public static function table_view( array $head, array $rows ): string {
		$html = '<details class="gdp-fin-tableview"><summary>' . esc_html__( 'Ver como tabla', 'gestion-de-proyectos' ) . '</summary><div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table"><thead><tr>';
		foreach ( $head as $h ) {
			$html .= '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$html .= '<tr>';
			foreach ( array_values( $r ) as $k => $cell ) {
				$html .= 0 === $k ? '<th scope="row">' . esc_html( (string) $cell ) . '</th>' : '<td>' . esc_html( (string) $cell ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table></div></details>';
	}

	/**
	 * Símbolos de los estados (acompañan siempre al color).
	 *
	 * @return array<string,string>
	 */
	public static function state_icons(): array {
		return array(
			'ok'       => '✓',
			'sent'     => '↗',
			'progress' => '…',
			'warn'     => '!',
			'bad'      => '✕',
			'current'  => '◷',
			'todo'     => '○',
		);
	}

	/**
	 * Nombres de los estados para la leyenda.
	 *
	 * @return array<string,string>
	 */
	public static function state_names(): array {
		return array(
			'ok'       => __( 'Aprobada', 'gestion-de-proyectos' ),
			'sent'     => __( 'Rendida, en revisión', 'gestion-de-proyectos' ),
			'progress' => __( 'En preparación o firma', 'gestion-de-proyectos' ),
			'warn'     => __( 'Aprobada parcialmente', 'gestion-de-proyectos' ),
			'bad'      => __( 'Devuelta o vencida sin declarar', 'gestion-de-proyectos' ),
			'current'  => __( 'Mes en curso', 'gestion-de-proyectos' ),
			'todo'     => __( 'Por presentar', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Trazo por los puntos no nulos de una serie (se interrumpe en los nulos).
	 *
	 * @param array<int,float|null> $values Valores.
	 * @param callable              $x      Escala horizontal.
	 * @param callable              $y      Escala vertical.
	 * @param callable              $fmt    Formato numérico.
	 * @return string Atributo d.
	 */
	private static function polyline( array $values, callable $x, callable $y, callable $fmt ): string {
		$d    = '';
		$open = false;
		foreach ( $values as $i => $v ) {
			if ( null === $v ) {
				$open = false;
				continue;
			}
			$d   .= ( $open ? ' L' : ( '' === $d ? 'M' : ' M' ) ) . $fmt( $x( (int) $i ) ) . ' ' . $fmt( $y( (float) $v ) );
			$open = true;
		}

		return $d;
	}

	/**
	 * Número para estilos en línea (punto decimal, dos decimales).
	 *
	 * @param float $value Valor.
	 * @return string
	 */
	private static function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
