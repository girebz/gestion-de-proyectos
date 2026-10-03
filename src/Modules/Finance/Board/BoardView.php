<?php
/**
 * Pestañas del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

use GDP\Admin\Pages\FinancePage;
use GDP\Core\Workbook;
use GDP\Modules\Finance\Assistant;
use GDP\Modules\Finance\FinanceService;
use GDP\Modules\Finance\GuaranteeRepository;
use GDP\Modules\Finance\InstallmentRepository;
use GDP\Modules\Finance\Logic\BoardMetrics;
use GDP\Modules\Finance\Logic\PaymentValidator;
use GDP\Modules\Finance\ModificationRepository;
use GDP\Modules\Finance\PaymentRepository;
use GDP\Modules\Finance\RenditionRepository;
use GDP\Modules\Procurement\SupplierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Nueve pestañas de solo lectura. Resumen es la vista integral del director
 * (alertas, plazo frente a ejecución, cifras por fuente, cuota siguiente,
 * cuotas, ítems, caja, rendiciones y próximas acciones); Cuotas, Ítems,
 * Pagos, Rendiciones, Caja y Convenio dan el detalle; Paso a paso convierte
 * cada obligación en la hoja de ejecución de SISREC con los valores listos
 * para copiar; Reportes entrega el informe imprimible, el texto para
 * informes y las descargas. Toda escritura sigue en el panel.
 */
final class BoardView {

	public const TABS = array( 'resumen', 'cuotas', 'items', 'pagos', 'rendiciones', 'caja', 'convenio', 'paso', 'reportes' );

	/**
	 * Explicaciones breves de las cifras de la petición en curso.
	 *
	 * @var array<string,string>
	 */
	private static array $tips = array();

	/**
	 * Etiquetas de las pestañas.
	 *
	 * @return array<string,string>
	 */
	public static function tab_labels(): array {
		return array(
			'resumen'     => __( 'Resumen', 'gestion-de-proyectos' ),
			'cuotas'      => __( 'Cuotas', 'gestion-de-proyectos' ),
			'items'       => __( 'Ítems', 'gestion-de-proyectos' ),
			'pagos'       => __( 'Pagos', 'gestion-de-proyectos' ),
			'rendiciones' => __( 'Rendiciones', 'gestion-de-proyectos' ),
			'caja'        => __( 'Caja', 'gestion-de-proyectos' ),
			'convenio'    => __( 'Convenio', 'gestion-de-proyectos' ),
			'paso'        => __( 'Paso a paso', 'gestion-de-proyectos' ),
			'reportes'    => __( 'Reportes', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Dibuja el tablero.
	 *
	 * @param array<string,mixed>  $data  Datos (BoardData::build).
	 * @param BoardContext         $ctx   Contexto.
	 * @param string               $tab   Pestaña.
	 * @param array<string,string> $route guide, entity, id, estado.
	 * @return void
	 */
	public static function render( array $data, BoardContext $ctx, string $tab, array $route ): void {
		self::$tips = BoardHelp::tips( $data );
		$project    = $ctx->project;
		$status     = $data['status'];
		echo '<div class="gdp-fin__head">';
		echo '<div class="gdp-fin__title"><h2>' . esc_html__( 'Finanzas y rendición de cuentas', 'gestion-de-proyectos' ) . '</h2>';
		echo '<p class="gdp-fin-muted">' . esc_html(
			sprintf(
				/* translators: 1: código del proyecto, 2: nombre, 3: fecha. */
				__( '%1$s · %2$s · estado al %3$s', 'gestion-de-proyectos' ),
				(string) $project['code'],
				(string) $project['name'],
				BoardMetrics::date( (string) $status['today'] )
			)
		) . '</p></div>';
		echo '<div class="gdp-fin__tools">';
		self::help_menu( $data, $ctx );
		if ( $ctx->export && Workbook::available() ) {
			echo '<a class="button button-small" href="' . esc_url( FinancePage::export_url( (int) $project['id'], 'workbook' ) ) . '" title="' . esc_attr__( 'Libro Excel con todo el estado financiero, una hoja por materia', 'gestion-de-proyectos' ) . '">' . esc_html__( 'Exportar a Excel', 'gestion-de-proyectos' ) . '</a> ';
		}
		echo '<button type="button" class="button button-small" data-gdp-print="page">' . esc_html__( 'Imprimir', 'gestion-de-proyectos' ) . '</button>';
		if ( $ctx->panel ) {
			echo ' <a class="button button-small" href="' . esc_url( FinancePage::url( (int) $project['id'] ) ) . '">' . esc_html__( 'Abrir en el panel', 'gestion-de-proyectos' ) . '</a>';
		}
		echo '</div></div>';

		if ( ! $ctx->single ) {
			echo '<nav class="gdp-fin-tabs" aria-label="' . esc_attr__( 'Secciones del tablero de finanzas', 'gestion-de-proyectos' ) . '">';
			foreach ( self::tab_labels() as $slug => $label ) {
				$active = $slug === $tab;
				echo '<a class="gdp-fin-tab' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $ctx->url( 'resumen' === $slug ? array() : array( 'fin' => $slug ) ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}

		if ( (float) $status['agreement']['fund_amount'] <= 0 ) {
			echo '<p class="gdp-front__notice">' . esc_html__( 'El convenio de este proyecto todavía no está registrado: el tablero se completa cuando se registran en el panel el convenio, las cuotas, los ítems y los pagos.', 'gestion-de-proyectos' ) . '</p>';
		}

		echo '<div class="gdp-fin__body">';
		$method = 'tab_' . $tab;
		self::$method( $data, $ctx, $route );
		echo '</div>';
	}

	// ------------------------------------------------------------------ Resumen

	/**
	 * Resumen: la vista integral del director.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_resumen( array $data, BoardContext $ctx, array $route ): void {
		unset( $route );
		$status = $data['status'];
		$fund   = $status['sources']['fondo'];
		$usage  = $data['usage']['fondo'];

		self::alerts( $data, $ctx );

		// Cifra principal y plazo frente a ejecución.
		$elapsed = $data['elapsed'];
		echo '<section class="gdp-fin-hero">';
		echo '<div class="gdp-fin-hero__figure"><div class="gdp-fin-hero__label">' . esc_html__( 'Saldo de caja del Fondo', 'gestion-de-proyectos' ) . self::tip( 'saldo' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ayuda escapada.
		echo '<span class="gdp-fin-hero__value">' . esc_html( BoardMetrics::money( (float) $fund['balance'] ) ) . '</span>';
		echo '<span class="gdp-fin-muted">' . esc_html(
			sprintf(
				/* translators: 1: transferido, 2: pagado, 3: comprometido. */
				__( 'Transferido %1$s · pagado %2$s · comprometido por pagar %3$s', 'gestion-de-proyectos' ),
				BoardMetrics::money( (float) $fund['received'] ),
				BoardMetrics::money( (float) $fund['paid'] ),
				BoardMetrics::money( (float) $fund['committed'] )
			)
		) . '</span></div>';
		echo '<div class="gdp-fin-hero__pace">';
		if ( $elapsed ) {
			$label = sprintf(
				/* translators: 1: porcentaje, 2: mes en curso, 3: meses totales, 4: fecha de término. */
				__( 'Plazo transcurrido %1$s (mes %2$d de %3$d; término el %4$s)', 'gestion-de-proyectos' ),
				BoardMetrics::pct_label( (float) $elapsed['pct'] ),
				(int) $elapsed['month'],
				(int) $elapsed['months'],
				BoardMetrics::date( (string) $status['agreement']['end_date'] )
			);
			self::pace_row( $label, (float) $elapsed['pct'], 'time', self::tip( 'plazo' ) );
		}
		$total = (float) $fund['total'];
		self::pace_row(
			sprintf( /* translators: porcentaje. */ __( 'Fondo pagado %s', 'gestion-de-proyectos' ), BoardMetrics::pct_label( BoardMetrics::pct( (float) $fund['paid'], $total ) ) ),
			(float) ( BoardMetrics::pct( (float) $fund['paid'], $total ) ?? 0 ),
			'paid',
			''
		);
		self::pace_row(
			sprintf( /* translators: porcentaje. */ __( 'Fondo pagado o comprometido %s', 'gestion-de-proyectos' ), BoardMetrics::pct_label( BoardMetrics::pct( (float) $fund['paid'] + (float) $fund['committed'], $total ) ) ),
			(float) ( BoardMetrics::pct( (float) $fund['paid'] + (float) $fund['committed'], $total ) ?? 0 ),
			'committed',
			''
		);
		echo '</div></section>';

		// Cifras del Fondo.
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html( (string) $fund['label'] ) . '</h3><div class="gdp-fin-kpis">';
		self::kpi( __( 'Convenio', 'gestion-de-proyectos' ), BoardMetrics::money( $total ), sprintf( /* translators: número de cuotas. */ __( '%d cuotas', 'gestion-de-proyectos' ), count( (array) $status['installments'] ) ), 'convenio' );
		self::kpi( __( 'Transferido', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['received'] ), sprintf( /* translators: porcentaje. */ __( '%s del convenio', 'gestion-de-proyectos' ), BoardMetrics::pct_label( BoardMetrics::pct( (float) $fund['received'], $total ) ) ), 'transferido' );
		self::kpi( __( 'Pagado', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['paid'] ), sprintf( /* translators: porcentaje. */ __( '%s de lo transferido', 'gestion-de-proyectos' ), BoardMetrics::pct_label( BoardMetrics::pct( (float) $fund['paid'], (float) $fund['received'] ) ) ), 'pagado' );
		self::kpi( __( 'Comprometido por pagar', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['committed'] ), (float) $fund['accrued'] > 0 ? sprintf( /* translators: monto devengado. */ __( 'incluye %s devengado', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['accrued'] ) ) : '', 'comprometido' );
		self::kpi( __( 'Rendido', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['rendered'] ), sprintf( /* translators: 1: aprobado, 2: observado. */ __( 'aprobado %1$s · observado %2$s', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['approved'] ), BoardMetrics::money( (float) $fund['observed'] ) ), 'rendido' );
		self::kpi( __( 'Por transferir', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $fund['to_receive'] ), sprintf( /* translators: porcentaje. */ __( '%s del convenio', 'gestion-de-proyectos' ), BoardMetrics::pct_label( BoardMetrics::pct( (float) $fund['to_receive'], $total ) ) ), 'convenio' );
		echo '</div>';
		echo self::chart_with_tip( BoardCharts::usage( $usage, __( 'Uso del Fondo', 'gestion-de-proyectos' ), self::usage_labels( __( 'Transferido', 'gestion-de-proyectos' ) ) ), 'uso' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		// Con transferencias recibidas, la misma advertencia ya figura entre las alertas.
		if ( (float) $usage['uncovered'] > 0.5 && (float) $usage['received'] <= 0 ) {
			echo '<p class="gdp-fin-note">' . esc_html( sprintf( /* translators: monto. */ __( 'Lo pagado más lo comprometido supera lo transferido en %s: esa parte de los compromisos se paga con la cuota siguiente.', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $usage['uncovered'] ) ) ) . '</p>';
		}
		echo '</section>';

		echo '<div class="gdp-fin-cols">';
		self::next_installment_card( $data, $ctx, true );
		self::university_card( $data );
		echo '</div>';

		echo '<section class="gdp-fin-section">';
		echo BoardCharts::installments( (array) $status['installments'], self::installment_labels() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		echo '<section class="gdp-fin-section">';
		echo self::chart_with_tip( BoardCharts::items( (array) $status['items'], 'fondo', __( 'Ejecución por ítem (Fondo)', 'gestion-de-proyectos' ) ), 'disponible' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		if ( $ctx->reachable( 'items' ) ) {
			echo '<p class="gdp-fin-more"><a href="' . esc_url( $ctx->url( array( 'fin' => 'items' ) ) ) . '">' . esc_html__( 'Detalle por ítem y fuente', 'gestion-de-proyectos' ) . '</a></p>';
		}
		echo '</section>';

		echo '<section class="gdp-fin-section">';
		echo self::chart_with_tip( BoardCharts::cash_curve( $data['curve'], (string) $data['current'] ), 'caja' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		echo '<section class="gdp-fin-section">';
		echo self::chart_with_tip( BoardCharts::strip( $data['cells'] ), 'rendiciones' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		self::actions_list( $data, $ctx, 5 );
	}

	/**
	 * Alertas del estado.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param BoardContext        $ctx  Contexto.
	 * @return void
	 */
	private static function alerts( array $data, BoardContext $ctx ): void {
		$alerts = (array) $data['alerts'];
		if ( empty( $alerts ) ) {
			echo '<p class="gdp-fin-alert gdp-fin-alert--ok"><span class="gdp-fin-alert__icon" aria-hidden="true">✓</span> ' . esc_html__( 'Sin alertas: rendiciones al día, ítems dentro de su asignado y caja conciliada.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		$icons = array(
			'critical' => '✕',
			'warning'  => '!',
			'info'     => 'i',
		);
		$names = array(
			'critical' => __( 'Atención inmediata', 'gestion-de-proyectos' ),
			'warning'  => __( 'Revisar', 'gestion-de-proyectos' ),
			'info'     => __( 'Para saber', 'gestion-de-proyectos' ),
		);
		echo '<section class="gdp-fin-alerts" aria-label="' . esc_attr__( 'Alertas', 'gestion-de-proyectos' ) . '"><ul>';
		foreach ( $alerts as $a ) {
			$link = ! $ctx->reachable( (string) $a['tab'] ) || $ctx->fixed === $a['tab'] ? '' : ' <a href="' . esc_url( $ctx->url( 'resumen' === $a['tab'] ? array() : array( 'fin' => $a['tab'] ) ) ) . '">' . esc_html__( 'Ver', 'gestion-de-proyectos' ) . '</a>';
			echo '<li class="gdp-fin-alert gdp-fin-alert--' . esc_attr( $a['severity'] ) . '"><span class="gdp-fin-alert__icon" aria-hidden="true">' . esc_html( $icons[ $a['severity'] ] ?? '!' ) . '</span><span class="screen-reader-text">' . esc_html( $names[ $a['severity'] ] ?? '' ) . ': </span><span>' . esc_html( $a['text'] ) . $link . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link escapado.
		}
		echo '</ul></section>';
	}

	/**
	 * Fila de ritmo: texto y medidor.
	 *
	 * @param string $label Texto.
	 * @param float  $pct   Porcentaje.
	 * @param string $kind  time, paid o committed.
	 * @param string $tip   Ayuda ya escapada.
	 * @return void
	 */
	private static function pace_row( string $label, float $pct, string $kind, string $tip ): void {
		echo '<div class="gdp-fin-pace"><div class="gdp-fin-pace__label">' . esc_html( $label ) . $tip . '</div>' . BoardCharts::meter( $pct, $label, $kind ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
	}

	/**
	 * Tarjeta de una cifra.
	 *
	 * @param string $label Etiqueta.
	 * @param string $value Valor.
	 * @param string $sub   Detalle.
	 * @param string $tip   Clave de la ayuda.
	 * @return void
	 */
	private static function kpi( string $label, string $value, string $sub, string $tip = '' ): void {
		echo '<div class="gdp-fin-kpi"><div class="gdp-fin-kpi__label">' . self::with_tip( $label, $tip ) . '</div><span class="gdp-fin-kpi__value">' . esc_html( $value ) . '</span>' . ( '' !== $sub ? '<span class="gdp-fin-kpi__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ayuda escapada.
	}

	/**
	 * Tarjeta de la cuota siguiente.
	 *
	 * @param array<string,mixed> $data    Datos.
	 * @param BoardContext        $ctx     Contexto.
	 * @param bool                $compact En el resumen.
	 * @return void
	 */
	private static function next_installment_card( array $data, BoardContext $ctx, bool $compact ): void {
		$status = $data['status'];
		$next   = $status['next_installment'];
		echo '<section class="gdp-fin-card gdp-fin-next">';
		if ( ! is_array( $next ) ) {
			echo '<h3 class="gdp-fin-h">' . esc_html__( 'Cuota siguiente', 'gestion-de-proyectos' ) . '</h3><p class="gdp-fin-muted">' . esc_html__( 'No quedan cuotas por recibir, o las cuotas no están registradas.', 'gestion-de-proyectos' ) . '</p></section>';
			return;
		}
		$gaps   = $next['gaps'];
		$target = (float) $gaps['target'];
		$fund   = $status['sources']['fondo'];
		/* translators: 1: número de la cuota, 2: monto. */
		echo '<div class="gdp-fin-hrow"><h3 class="gdp-fin-h">' . esc_html( sprintf( __( 'Cuota siguiente: N.º %1$d por %2$s', 'gestion-de-proyectos' ), (int) $next['number'], BoardMetrics::money( (float) $next['amount'] ) ) ) . '</h3>' . self::tip( 'brecha' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ayuda escapada.
		echo '<p class="gdp-fin-muted">' . esc_html(
			sprintf(
				/* translators: 1: inicio de la ventana, 2: fin de la ventana, 3: fecha objetivo. */
				__( 'Ventana del convenio: %1$s a %2$s · fecha objetivo del giro: %3$s', 'gestion-de-proyectos' ),
				BoardMetrics::date( (string) ( $next['window'][0] ?? '' ) ),
				BoardMetrics::date( (string) ( $next['window'][1] ?? '' ) ),
				BoardMetrics::date( (string) $next['target_date'] )
			)
		) . '</p>';
		$rows = array(
			array( __( 'Pagado', 'gestion-de-proyectos' ), (float) $fund['paid'], (float) $gaps['pay_gap'], 'paid' ),
			array( __( 'Rendido', 'gestion-de-proyectos' ), (float) $fund['rendered'], (float) $gaps['render_gap'], 'rendered' ),
			array( __( 'Aprobado', 'gestion-de-proyectos' ), (float) $fund['approved'], (float) $gaps['approve_gap'], 'approved' ),
		);
		echo '<ul class="gdp-fin-gaps">';
		foreach ( $rows as $r ) {
			$pct   = BoardMetrics::pct( min( $r[1], $target ), $target ) ?? 0.0;
			$label = sprintf(
				/* translators: 1: concepto, 2: monto, 3: monto de referencia, 4: monto faltante. */
				__( '%1$s %2$s de %3$s; faltan %4$s', 'gestion-de-proyectos' ),
				$r[0],
				BoardMetrics::money( $r[1] ),
				BoardMetrics::money( $target ),
				BoardMetrics::money( $r[2] )
			);
			echo '<li><span>' . esc_html( $label ) . '</span>' . BoardCharts::meter( (float) $pct, $label, $r[3] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		}
		echo '</ul>';
		echo '<div class="gdp-fin-para">' . esc_html( sprintf( /* translators: monto. */ __( 'Garantía alternativa por la fracción no rendida: %s', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $gaps['guarantee'] ) ) ) . self::tip( 'garantia' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ayuda escapada.
		if ( ! empty( $next['latest_month'] ) ) {
			echo '<div class="gdp-fin-para">' . esc_html(
				sprintf(
					/* translators: 1: mes, 2: fecha para rendir, 3: fecha para recibir facturas. */
					__( 'Último mes de pago útil: %1$s (rendir antes del %2$s; facturas recibidas antes del %3$s)', 'gestion-de-proyectos' ),
					BoardMetrics::month_label( (string) $next['latest_month'] ),
					BoardMetrics::date( (string) $next['latest_render_due'] ),
					BoardMetrics::date( (string) $next['invoice_by'] )
				)
			) . self::tip( 'ultimo_mes' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ayuda escapada.
			if ( ! empty( $next['invoice_by'] ) && (string) $next['invoice_by'] < (string) $status['today'] ) {
				echo '<p class="gdp-fin-flag gdp-fin-flag--warn"><span aria-hidden="true">!</span> ' . esc_html( sprintf( /* translators: días de pago de una factura. */ __( 'El plazo para facturas nuevas ya pasó: con %d días para pagar una factura, solo alcanzan las ya recibidas.', 'gestion-de-proyectos' ), (int) $next['invoice_days'] ) ) . '</p>';
			}
		} else {
			echo '<p class="gdp-fin-flag gdp-fin-flag--bad"><span aria-hidden="true">✕</span> ' . esc_html__( 'Ningún mes de pago alcanza ya la fecha objetivo del giro: la vía es la garantía o una nueva fecha objetivo.', 'gestion-de-proyectos' ) . '</p>';
		}
		echo '<div class="gdp-fin-hrow gdp-fin-hrow--4"><h4 class="gdp-fin-h4">' . esc_html__( 'Condiciones de giro', 'gestion-de-proyectos' ) . '</h4>' . self::tip( 'condiciones' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ayuda escapada.
		self::conditions( (array) $next['conditions'], $compact );
		$links = array();
		if ( $compact && $ctx->reachable( 'cuotas' ) ) {
			$links[] = '<a href="' . esc_url( $ctx->url( array( 'fin' => 'cuotas' ) ) ) . '">' . esc_html__( 'Compromisos que cierran la brecha', 'gestion-de-proyectos' ) . '</a>';
		}
		if ( $ctx->reachable( 'paso' ) ) {
			$links[] = '<a href="' . esc_url(
				$ctx->url(
					array(
						'fin'  => 'paso',
						'guia' => 'solicitar_cuota',
						'ent'  => 'installment',
						'id'   => self::installment_id( $status, (int) $next['number'] ),
					)
				)
			) . '">' . esc_html__( 'Paso a paso para solicitarla', 'gestion-de-proyectos' ) . '</a>';
		}
		if ( $links ) {
			echo '<p class="gdp-fin-more">' . implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- enlaces escapados.
		}
		echo '</section>';
	}

	/**
	 * Lista de condiciones o controles con su estado.
	 *
	 * @param array<int,array<string,mixed>> $conditions Condiciones (key, label, ok, detail).
	 * @param bool                           $compact    Sin el detalle.
	 * @param string                         $none       Palabra para un estado sin evaluar.
	 * @return void
	 */
	private static function conditions( array $conditions, bool $compact, string $none = '' ): void {
		$none = '' !== $none ? $none : __( 'sin registro', 'gestion-de-proyectos' );
		echo '<ul class="gdp-fin-checks">';
		foreach ( $conditions as $c ) {
			$state = null === $c['ok'] ? 'none' : ( $c['ok'] ? 'ok' : 'bad' );
			$icon  = array(
				'ok'   => '✓',
				'bad'  => '✕',
				'none' => '–',
			)[ $state ];
			$word  = array(
				'ok'   => __( 'cumple', 'gestion-de-proyectos' ),
				'bad'  => __( 'no cumple', 'gestion-de-proyectos' ),
				'none' => $none,
			)[ $state ];
			echo '<li class="gdp-fin-check gdp-fin-check--' . esc_attr( $state ) . '"><span class="gdp-fin-check__icon" aria-hidden="true">' . esc_html( $icon ) . '</span><span><strong>' . esc_html( (string) $c['key'] ) . '</strong> ' . esc_html( BoardMetrics::display_text( (string) $c['label'] ) ) . ' <span class="gdp-fin-check__word">(' . esc_html( $word ) . ')</span>' . ( $compact || '' === (string) $c['detail'] ? '' : '<br><span class="gdp-fin-muted">' . esc_html( BoardMetrics::display_text( (string) $c['detail'] ) ) . '</span>' ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * Tarjeta del aporte de la universidad.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return void
	 */
	private static function university_card( array $data ): void {
		$status    = $data['status'];
		$cash      = $status['sources']['pecuniario'];
		$agreement = $status['agreement'];
		$next      = $status['next_installment'];
		echo '<section class="gdp-fin-card"><h3 class="gdp-fin-h">' . esc_html__( 'Aporte de la universidad', 'gestion-de-proyectos' ) . '</h3>';
		echo '<div class="gdp-fin-kpis gdp-fin-kpis--two">';
		self::kpi( __( 'Aporte pecuniario', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $cash['total'] ), sprintf( /* translators: 1: enterado, 2: pagado. */ __( 'enterado %1$s · pagado %2$s', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $cash['received'] ), BoardMetrics::money( (float) $cash['paid'] ) ), 'pecuniario' );
		self::kpi( __( 'Aporte no pecuniario', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $agreement['inkind_amount'] ), __( 'valorización comprometida', 'gestion-de-proyectos' ), 'no_pecuniario' );
		echo '</div>';
		echo BoardCharts::usage( $data['usage']['pecuniario'], __( 'Uso del aporte pecuniario', 'gestion-de-proyectos' ), self::usage_labels( __( 'Enterado', 'gestion-de-proyectos' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		if ( is_array( $next ) && (float) $next['cash_amount'] > 0 ) {
			$received = false;
			foreach ( (array) $status['installments'] as $i ) {
				if ( (int) $i['number'] === (int) $next['number'] && ! empty( $i['cash_received_at'] ) ) {
					$received = true;
				}
			}
			echo '<p class="gdp-fin-note">' . esc_html(
				$received
					/* translators: 1: monto, 2: número de la cuota. */
					? sprintf( __( 'El aporte de %1$s para la cuota %2$d está acreditado.', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['cash_amount'] ), (int) $next['number'] )
					/* translators: 1: monto, 2: número de la cuota. */
					: sprintf( __( 'Antes de pedir la cuota %2$d, la universidad debe enterar y acreditar %1$s con su comprobante de ingreso.', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['cash_amount'] ), (int) $next['number'] )
			) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * Acciones del asistente.
	 *
	 * @param array<string,mixed> $data  Datos.
	 * @param BoardContext        $ctx   Contexto.
	 * @param int                 $limit Máximo (0 = todas).
	 * @return void
	 */
	private static function actions_list( array $data, BoardContext $ctx, int $limit ): void {
		$actions = (array) $data['actions'];
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html( $limit > 0 ? __( 'Próximas acciones', 'gestion-de-proyectos' ) : __( 'Acciones pendientes, por gravedad y vencimiento', 'gestion-de-proyectos' ) ) . '</h3>';
		if ( empty( $actions ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Sin acciones pendientes.', 'gestion-de-proyectos' ) . '</p></section>';
			return;
		}
		$shown = $limit > 0 ? array_slice( $actions, 0, $limit ) : $actions;
		$sev   = array(
			'alta'  => 'critical',
			'media' => 'warning',
			'baja'  => 'info',
		);
		$names = array(
			'alta'  => __( 'alta', 'gestion-de-proyectos' ),
			'media' => __( 'media', 'gestion-de-proyectos' ),
			'baja'  => __( 'baja', 'gestion-de-proyectos' ),
		);
		echo '<ol class="gdp-fin-actions">';
		foreach ( $shown as $a ) {
			$due = '';
			if ( ! empty( $a['due'] ) ) {
				$left = (int) $a['days_left'];
				$due  = $left < 0
					/* translators: 1: fecha, 2: días hábiles. */
					? sprintf( __( 'venció el %1$s (hace %2$d días hábiles)', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $a['due'] ), abs( $left ) )
					/* translators: 1: fecha, 2: días hábiles. */
					: sprintf( __( 'vence el %1$s (%2$d días hábiles)', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $a['due'] ), $left );
			}
			echo '<li class="gdp-fin-action gdp-fin-action--' . esc_attr( $sev[ $a['severity'] ] ?? 'info' ) . '">';
			echo '<span class="gdp-fin-badge gdp-fin-badge--' . esc_attr( $sev[ $a['severity'] ] ?? 'info' ) . '">' . esc_html( sprintf( /* translators: gravedad. */ __( 'Prioridad %s', 'gestion-de-proyectos' ), $names[ $a['severity'] ] ?? (string) $a['severity'] ) ) . '</span> ';
			echo '<strong>' . esc_html( (string) $a['title'] ) . '</strong>';
			if ( '' !== $due ) {
				echo ' <span class="gdp-fin-muted' . ( ! empty( $a['due'] ) && (int) $a['days_left'] < 0 ? ' gdp-fin-text-bad' : '' ) . '">' . esc_html( $due ) . '</span>';
			}
			echo '<p class="gdp-fin-muted">' . esc_html( (string) $a['detail'] ) . '</p>';
			if ( '' !== (string) $a['guide'] && $ctx->reachable( 'paso' ) ) {
				echo '<a class="button button-small" href="' . esc_url(
					$ctx->url(
						array(
							'fin'  => 'paso',
							'guia' => (string) $a['guide'],
							'ent'  => (string) $a['entity_type'],
							'id'   => (int) $a['entity_id'],
						)
					)
				) . '">' . esc_html__( 'Ver el paso a paso', 'gestion-de-proyectos' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol>';
		if ( $limit > 0 && count( $actions ) > $limit && $ctx->reachable( 'paso' ) ) {
			echo '<p class="gdp-fin-more"><a href="' . esc_url( $ctx->url( array( 'fin' => 'paso' ) ) ) . '">' . esc_html( sprintf( /* translators: número de acciones. */ __( 'Ver las %d acciones', 'gestion-de-proyectos' ), count( $actions ) ) ) . '</a></p>';
		}
		echo '</section>';
	}

	// ------------------------------------------------------------------- Cuotas

	/**
	 * Cuotas y giro.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_cuotas( array $data, BoardContext $ctx, array $route ): void {
		unset( $route );
		$status  = $data['status'];
		$profile = $data['profile'];
		$pid     = (int) $data['project_id'];
		echo '<section class="gdp-fin-section">';
		echo BoardCharts::installments( (array) $status['installments'], self::installment_labels() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		echo '<div class="gdp-fin-cols">';
		self::next_installment_card( $data, $ctx, false );
		$next = $status['next_installment'];
		echo '<section class="gdp-fin-card"><h3 class="gdp-fin-h">' . esc_html__( 'Compromisos que cierran la brecha', 'gestion-de-proyectos' ) . '</h3>';
		if ( is_array( $next ) && ! empty( $next['candidates'] ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Pagos comprometidos o devengados con cargo al Fondo, primero los de ítems con disponible y los devengados.', 'gestion-de-proyectos' ) . '</p><ul class="gdp-fin-list">';
			foreach ( (array) $next['candidates'] as $c ) {
				echo '<li><code>' . esc_html( (string) $c['code'] ) . '</code> ' . esc_html( (string) $c['description'] ) . ' · <strong>' . esc_html( BoardMetrics::money( (float) $c['amount'] ) ) . '</strong> · ' . esc_html( $profile->payment_statuses()[ $c['status'] ] ?? (string) $c['status'] ) . ( $c['item_ok'] ? '' : ' <span class="gdp-fin-text-bad">' . esc_html__( '(ítem sin disponible)', 'gestion-de-proyectos' ) . '</span>' ) . '</li>';
			}
			echo '</ul>';
			echo '<p>' . esc_html( sprintf( /* translators: 1: monto cubierto, 2: monto sin cubrir. */ __( 'Cubren %1$s; quedan %2$s sin cubrir.', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['candidates_covered'] ), BoardMetrics::money( (float) $next['candidates_remaining'] ) ) ) . '</p>';
			echo '<p class="gdp-fin-note">' . esc_html__( 'Que un compromiso cubra la brecha no significa que alcance a pagarse a tiempo: los honorarios se pagan mes a mes y cada factura tiene su plazo de pago.', 'gestion-de-proyectos' ) . '</p>';
		} else {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'No hay brecha de pago o no hay compromisos registrados con cargo al Fondo.', 'gestion-de-proyectos' ) . '</p>';
		}
		if ( is_array( $next ) && self::installment_id( $status, (int) $next['number'] ) > 0 ) {
			echo '<p><a class="button button-small" href="' . esc_url( FinancePage::export_url( $pid, 'installment_sheet', self::installment_id( $status, (int) $next['number'] ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ficha de giro (imprimible)', 'gestion-de-proyectos' ) . '</a></p>';
		}
		echo '</section></div>';

		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Detalle de cada cuota', 'gestion-de-proyectos' ) . '</h3><div class="gdp-fin-grid">';
		foreach ( (array) $status['installments'] as $i ) {
			$facts = array(
				__( 'Monto', 'gestion-de-proyectos' )      => BoardMetrics::money( (float) $i['amount'] ) . ( (float) $i['share_pct'] > 0 ? ' (' . BoardMetrics::pct_label( (float) $i['share_pct'] ) . ')' : '' ),
				__( 'Ventana del convenio', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $i['window_from'] ) . ' a ' . BoardMetrics::date( (string) $i['window_to'] ),
				__( 'Informe que la habilita', 'gestion-de-proyectos' ) => (int) $i['report_no'] > 0 ? sprintf( /* translators: número. */ __( 'Informe de avance N.º %d aprobado', 'gestion-de-proyectos' ), (int) $i['report_no'] ) : __( 'ninguno', 'gestion-de-proyectos' ),
				__( 'Aporte pecuniario', 'gestion-de-proyectos' ) => BoardMetrics::money( (float) $i['cash_amount'] ) . ' · ' . ( $i['cash_received_at'] ? sprintf( /* translators: fecha. */ __( 'acreditado el %s', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $i['cash_received_at'] ) ) : __( 'por acreditar', 'gestion-de-proyectos' ) ),
				__( 'Solicitada', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $i['requested_at'] ),
				__( 'Transferida', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $i['transferred_at'] ),
				__( 'Ingresada', 'gestion-de-proyectos' )  => BoardMetrics::date( (string) $i['received_at'] ),
				__( 'Comprobante de ingreso', 'gestion-de-proyectos' ) => trim( (string) $i['receipt_number'] . ' ' . ( $i['receipt_sent_at'] ? sprintf( /* translators: fecha. */ __( 'enviado el %s', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $i['receipt_sent_at'] ) ) : ( $i['is_received'] ? __( 'sin envío registrado', 'gestion-de-proyectos' ) : '' ) ) ),
				__( 'Estado en la plataforma', 'gestion-de-proyectos' ) => $profile->transfer_statuses()[ $i['platform_status'] ] ?? (string) $i['platform_status'],
				__( 'Pagado / rendido / aprobado', 'gestion-de-proyectos' ) => BoardMetrics::money( (float) $i['paid'] ) . ' / ' . BoardMetrics::money( (float) $i['rendered'] ) . ' / ' . BoardMetrics::money( (float) $i['approved'] ),
			);
			/* translators: número de la cuota. */
			echo '<article class="gdp-fin-card"><h4 class="gdp-fin-h4">' . esc_html( sprintf( __( 'Cuota %d', 'gestion-de-proyectos' ), (int) $i['number'] ) ) . ' ' . ( $i['is_received'] ? '<span class="gdp-fin-flag gdp-fin-flag--ok"><span aria-hidden="true">✓</span> ' . esc_html__( 'recibida', 'gestion-de-proyectos' ) . '</span>' : '<span class="gdp-fin-flag gdp-fin-flag--none">' . esc_html__( 'por recibir', 'gestion-de-proyectos' ) . '</span>' ) . '</h4>';
			self::facts( $facts );
			if ( '' !== trim( (string) $i['notes'] ) ) {
				echo '<details class="gdp-fin-notes"><summary>' . esc_html__( 'Notas', 'gestion-de-proyectos' ) . '</summary><p>' . nl2br( esc_html( (string) $i['notes'] ) ) . '</p></details>';
			}
			echo '</article>';
		}
		echo '</div></section>';
	}

	// -------------------------------------------------------------------- Ítems

	/**
	 * Ítems y fuentes.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_items( array $data, BoardContext $ctx, array $route ): void {
		unset( $ctx, $route );
		$status  = $data['status'];
		$profile = $data['profile'];
		$items   = (array) $status['items'];
		echo '<section class="gdp-fin-section">';
		echo self::chart_with_tip( BoardCharts::items( $items, 'fondo', __( 'Ejecución por ítem (Fondo)', 'gestion-de-proyectos' ) ), 'disponible' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo BoardCharts::items( $items, 'pecuniario', __( 'Ejecución por ítem (aporte pecuniario)', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		$rows   = $data['items_rows'];
		$totals = array_fill_keys( array( 'fa', 'fp', 'fc', 'fr', 'fd', 'ca', 'cp', 'cd', 'na' ), 0.0 );
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Asignado, ejecutado y disponible por fuente', 'gestion-de-proyectos' ) . '</h3>';
		echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table gdp-fin-table--num"><thead><tr><th rowspan="2" scope="col">' . esc_html__( 'Ítem', 'gestion-de-proyectos' ) . '</th><th colspan="6" scope="colgroup">' . esc_html__( 'Fondo', 'gestion-de-proyectos' ) . '</th><th colspan="3" scope="colgroup">' . esc_html__( 'Aporte pecuniario', 'gestion-de-proyectos' ) . '</th><th rowspan="2" scope="col">' . esc_html__( 'No pecuniario', 'gestion-de-proyectos' ) . '</th><th rowspan="2" scope="col">' . esc_html__( 'Tope de las bases', 'gestion-de-proyectos' ) . '</th></tr><tr>';
		foreach ( array( __( 'Asignado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Comprometido', 'gestion-de-proyectos' ), __( 'Rechazado', 'gestion-de-proyectos' ), __( 'Disponible', 'gestion-de-proyectos' ), __( 'Usado', 'gestion-de-proyectos' ), __( 'Asignado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Disponible', 'gestion-de-proyectos' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $i ) {
			$f             = $i['sources']['fondo'];
			$c             = $i['sources']['pecuniario'];
			$inkind        = (float) ( $rows[ $i['slug'] ]['assigned_inkind'] ?? 0 );
			$totals['fa'] += (float) $f['assigned'];
			$totals['fp'] += (float) $f['paid'];
			$totals['fc'] += (float) $f['committed'];
			$totals['fr'] += (float) $f['rejected'];
			$totals['fd'] += (float) $f['available'];
			$totals['ca'] += (float) $c['assigned'];
			$totals['cp'] += (float) $c['paid'];
			$totals['cd'] += (float) $c['available'];
			$totals['na'] += $inkind;
			$cap           = is_array( $i['cap'] ) ? ( $i['cap']['ok'] ? '✓ ' : '✕ ' ) . sprintf( '≤ %s (%s)', BoardMetrics::money( (float) $i['cap']['limit'] ), (string) $i['cap']['base'] ) : '—';
			echo '<tr><th scope="row">' . esc_html( (string) $i['label'] ) . '<br><span class="gdp-fin-muted">' . esc_html( ( $profile->platform_types()[ $i['platform_type'] ] ?? (string) $i['platform_type'] ) . ( $i['platform_subclass'] ? ' · ' . $i['platform_subclass'] : '' ) ) . '</span></th>';
			foreach ( array( $f['assigned'], $f['paid'], $f['committed'], $f['rejected'] ) as $v ) {
				echo '<td>' . esc_html( BoardMetrics::money( (float) $v ) ) . '</td>';
			}
			echo '<td class="' . ( (float) $f['available'] < -0.5 ? 'gdp-fin-text-bad' : '' ) . '"><strong>' . esc_html( BoardMetrics::money( (float) $f['available'] ) ) . '</strong></td>';
			echo '<td>' . esc_html( BoardMetrics::pct_label( BoardMetrics::pct( (float) $f['paid'] + (float) $f['committed'], (float) $f['assigned'] ) ) ) . '</td>';
			foreach ( array( $c['assigned'], $c['paid'], $c['available'] ) as $v ) {
				echo '<td>' . esc_html( BoardMetrics::money( (float) $v ) ) . '</td>';
			}
			echo '<td>' . esc_html( BoardMetrics::money( $inkind ) ) . '</td><td>' . esc_html( $cap ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'Total', 'gestion-de-proyectos' ) . '</th>';
		foreach ( array( 'fa', 'fp', 'fc', 'fr', 'fd' ) as $k ) {
			echo '<td>' . esc_html( BoardMetrics::money( $totals[ $k ] ) ) . '</td>';
		}
		echo '<td>' . esc_html( BoardMetrics::pct_label( BoardMetrics::pct( $totals['fp'] + $totals['fc'], $totals['fa'] ) ) ) . '</td>';
		foreach ( array( 'ca', 'cp', 'cd', 'na' ) as $k ) {
			echo '<td>' . esc_html( BoardMetrics::money( $totals[ $k ] ) ) . '</td>';
		}
		echo '<td></td></tr></tfoot></table></div></section>';

		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Detalle de cada ítem', 'gestion-de-proyectos' ) . '</h3>';
		$reqs = $profile->support_requirements();
		foreach ( $items as $i ) {
			$payments = array_filter( (array) $data['payments'], static fn( array $p ): bool => $p['item_slug'] === $i['slug'] );
			echo '<details class="gdp-fin-item"><summary><strong>' . esc_html( (string) $i['label'] ) . '</strong> <span class="gdp-fin-muted">' . esc_html( sprintf( /* translators: 1: número de pagos, 2: disponible. */ __( '%1$d pagos · disponible %2$s', 'gestion-de-proyectos' ), count( $payments ), BoardMetrics::money( (float) $i['sources']['fondo']['available'] ) ) ) . '</span></summary>';
			$note = (string) ( $rows[ $i['slug'] ]['notes'] ?? '' );
			if ( '' !== trim( $note ) ) {
				echo '<p>' . esc_html( $note ) . '</p>';
			}
			if ( ! empty( $reqs[ $i['slug'] ] ) ) {
				echo '<p><strong>' . esc_html__( 'Respaldos que exige:', 'gestion-de-proyectos' ) . '</strong> ' . esc_html( implode( '; ', array_values( $reqs[ $i['slug'] ] ) ) ) . '.</p>';
			}
			if ( $payments ) {
				echo '<ul class="gdp-fin-list">';
				foreach ( $payments as $p ) {
					echo '<li><code>' . esc_html( (string) $p['code'] ) . '</code> ' . esc_html( (string) $p['description'] ) . ' · <strong>' . esc_html( BoardMetrics::money( (float) $p['amount'] ) ) . '</strong> · ' . esc_html( $profile->payment_statuses()[ $p['status'] ] ?? (string) $p['status'] ) . ( 'pecuniario' === $p['source'] ? ' · ' . esc_html__( 'aporte pecuniario', 'gestion-de-proyectos' ) : '' ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</details>';
		}
		echo '</section>';
	}

	// -------------------------------------------------------------------- Pagos

	/**
	 * Pagos con filtros y detalle.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_pagos( array $data, BoardContext $ctx, array $route ): void {
		$profile  = $data['profile'];
		$statuses = $profile->payment_statuses();
		$items    = (array) $data['items_labels'];
		$filter   = array(
			'estado' => '' !== ( $route['estado'] ?? '' ) ? (string) $route['estado'] : sanitize_key( $ctx->get( 'estado' ) ),
			'item'   => sanitize_key( $ctx->get( 'item' ) ),
			'fuente' => sanitize_key( $ctx->get( 'fuente' ) ),
			'q'      => $ctx->get( 'q' ),
		);

		// Resumen por estado.
		$summary = array();
		foreach ( (array) $data['by_status'] as $row ) {
			$summary[ $row['status'] ][ $row['source'] ] = $row;
		}
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Pagos por estado', 'gestion-de-proyectos' ) . '</h3>';
		echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table gdp-fin-table--num gdp-fin-table--compact"><thead><tr><th scope="col">' . esc_html__( 'Estado', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Fondo', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Aporte pecuniario', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
		foreach ( $statuses as $slug => $label ) {
			if ( ! isset( $summary[ $slug ] ) ) {
				continue;
			}
			echo '<tr><th scope="row"><a href="' . esc_url(
				$ctx->url(
					array(
						'fin'    => 'pagos',
						'estado' => $slug,
					)
				)
			) . '">' . esc_html( $label ) . '</a></th>';
			foreach ( array( 'fondo', 'pecuniario' ) as $src ) {
				$r = $summary[ $slug ][ $src ] ?? null;
				echo '<td>' . ( $r ? esc_html( BoardMetrics::money( (float) $r['amount'] ) . ' (' . (int) $r['count'] . ')' ) : '—' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';

		// Filtros.
		$groups = array(
			''           => __( 'Todos los estados', 'gestion-de-proyectos' ),
			'por_pagar'  => __( 'Por pagar (comprometidos y devengados)', 'gestion-de-proyectos' ),
			'pagados'    => __( 'Pagados (en cualquier etapa)', 'gestion-de-proyectos' ),
			'observados' => __( 'Observados o corregidos', 'gestion-de-proyectos' ),
		);
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Detalle de los pagos', 'gestion-de-proyectos' ) . '</h3>';
		echo $ctx->form_start( array( 'fin' => 'pagos' ), 'gdp-fin-form gdp-fin-filters' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Estado', 'gestion-de-proyectos' ) . '</span><select name="gdp_estado">';
		foreach ( $groups as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filter['estado'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		foreach ( $statuses as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filter['estado'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Ítem', 'gestion-de-proyectos' ) . '</span><select name="gdp_item"><option value="">' . esc_html__( 'Todos los ítems', 'gestion-de-proyectos' ) . '</option>';
		foreach ( $items as $k => $l ) {
			echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( $filter['item'], (string) $k, false ) . '>' . esc_html( (string) $l ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Fuente', 'gestion-de-proyectos' ) . '</span><select name="gdp_fuente"><option value="">' . esc_html__( 'Ambas fuentes', 'gestion-de-proyectos' ) . '</option><option value="fondo"' . selected( $filter['fuente'], 'fondo', false ) . '>' . esc_html__( 'Fondo', 'gestion-de-proyectos' ) . '</option><option value="pecuniario"' . selected( $filter['fuente'], 'pecuniario', false ) . '>' . esc_html__( 'Aporte pecuniario', 'gestion-de-proyectos' ) . '</option></select></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Buscar', 'gestion-de-proyectos' ) . '</span><input type="search" name="gdp_q" value="' . esc_attr( $filter['q'] ) . '" placeholder="' . esc_attr__( 'Código, proveedor, descripción o documento', 'gestion-de-proyectos' ) . '"></label>';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Filtrar', 'gestion-de-proyectos' ) . '</button>';
		if ( array_filter( $filter ) ) {
			echo ' <a class="gdp-fin-small" href="' . esc_url( $ctx->url( array( 'fin' => 'pagos' ) ) ) . '">' . esc_html__( 'Quitar filtros', 'gestion-de-proyectos' ) . '</a>';
		}
		echo '</form>';

		$rows = array();
		foreach ( (array) $data['payments'] as $p ) {
			if ( ! self::payment_matches( $p, $filter, $data ) ) {
				continue;
			}
			$rows[] = $p;
		}
		if ( empty( $rows ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Ningún pago coincide con el filtro.', 'gestion-de-proyectos' ) . '</p></section>';
			return;
		}
		$total = 0.0;
		$csv   = array( array( 'codigo', 'fuente', 'item', 'proveedor', 'descripcion', 'compromiso', 'tipo_documento', 'numero_documento', 'fecha_documento', 'ejecutado', 'pagado', 'egreso', 'monto', 'cuota', 'estado', 'observacion' ) );
		echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table gdp-fin-payments"><thead><tr><th scope="col">' . esc_html__( 'Pago', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Proveedor y detalle', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Ítem', 'gestion-de-proyectos' ) . '</th><th scope="col" class="gdp-fin-num">' . esc_html__( 'Monto', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Estado', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
		$status = $data['status'];
		foreach ( $rows as $p ) {
			$total   += (float) $p['amount'];
			$supplier = BoardData::supplier_name( $data, (int) $p['supplier_id'] );
			$doc      = trim( ( $profile->doc_types()[ $p['doc_type'] ] ?? (string) $p['doc_type'] ) . ' ' . (string) $p['doc_number'] );
			$date     = '' !== (string) $p['paid_at'] ? (string) $p['paid_at'] : ( '' !== (string) $p['doc_date'] ? (string) $p['doc_date'] : (string) $p['executed_at'] );
			$inst     = $data['effective'][ $p['id'] ] ?? ( (int) $p['installment_no'] > 0 ? (int) $p['installment_no'] : 0 );
			$issues   = FinanceService::validate_payment( $p, $status );
			$state    = in_array( $p['status'], array( 'observado', 'rechazado' ), true ) ? 'bad' : ( in_array( $p['status'], PaymentRepository::COMMITTED, true ) ? 'pending' : ( 'aprobado' === $p['status'] ? 'ok' : 'neutral' ) );
			echo '<tr class="gdp-fin-row--' . esc_attr( (string) $p['source'] ) . '">';
			echo '<td><code>' . esc_html( (string) $p['code'] ) . '</code><br><span class="gdp-fin-muted">' . esc_html( BoardMetrics::date( $date ) ) . '</span></td>';
			echo '<td><strong>' . esc_html( '' !== $supplier ? $supplier : __( 'Sin proveedor', 'gestion-de-proyectos' ) ) . '</strong><br>' . esc_html( (string) $p['description'] );
			echo '<details class="gdp-fin-detail"><summary>' . esc_html__( 'Detalle', 'gestion-de-proyectos' ) . '</summary>';
			$facts = array(
				__( 'Fuente', 'gestion-de-proyectos' )     => $profile->sources()[ $p['source'] ] ?? (string) $p['source'],
				__( 'Compromiso', 'gestion-de-proyectos' ) => (string) $p['commitment'],
				__( 'Documento', 'gestion-de-proyectos' )  => trim( $doc . ( $p['doc_date'] ? ' · ' . BoardMetrics::date( (string) $p['doc_date'] ) : '' ) ),
				__( 'Ejecutado', 'gestion-de-proyectos' )  => BoardMetrics::date( (string) $p['executed_at'] ),
				__( 'Pagado', 'gestion-de-proyectos' )     => BoardMetrics::date( (string) $p['paid_at'] ),
				__( 'Egreso', 'gestion-de-proyectos' )     => (string) $p['egress_number'],
				__( 'Cuota imputada', 'gestion-de-proyectos' ) => $inst > 0 ? (string) $inst : '—',
				__( 'Compra', 'gestion-de-proyectos' )     => (int) $p['purchase_id'] > 0 && is_array( $data['purchases'][ (int) $p['purchase_id'] ] ?? null ) ? (string) $data['purchases'][ (int) $p['purchase_id'] ]['code'] : '',
				__( 'Observación', 'gestion-de-proyectos' ) => (string) $p['observation'],
				__( 'Notas', 'gestion-de-proyectos' )      => (string) $p['notes'],
			);
			self::facts( $facts );
			if ( ! empty( $p['support'] ) ) {
				echo '<p><strong>' . esc_html__( 'Respaldos:', 'gestion-de-proyectos' ) . '</strong></p><ul class="gdp-fin-list">';
				foreach ( (array) $p['support'] as $s ) {
					$did  = (int) ( $s['document_id'] ?? 0 );
					$kind = $profile->support_kinds()[ $s['kind'] ] ?? (string) $s['kind'];
					$url  = $did > 0 && $ctx->docs ? Assistant::document_url( $did ) : '';
					echo '<li>' . esc_html( $kind );
					if ( $did > 0 && $ctx->docs ) {
						$label = BoardData::document_label( $data, $did );
						echo ': ' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) );
					} elseif ( $did > 0 ) {
						echo ' <span class="gdp-fin-muted">' . esc_html__( '(documento adjunto)', 'gestion-de-proyectos' ) . '</span>';
					}
					echo ! empty( $s['note'] ) ? ' <span class="gdp-fin-muted">(' . esc_html( (string) $s['note'] ) . ')</span>' : '';
					echo '</li>';
				}
				echo '</ul>';
			}
			if ( $issues ) {
				echo '<p><strong>' . esc_html__( 'Hallazgos para la rendición:', 'gestion-de-proyectos' ) . '</strong></p><ul class="gdp-fin-list gdp-fin-issues">';
				foreach ( $issues as $i ) {
					echo '<li class="gdp-fin-issue--' . esc_attr( (string) $i['severity'] ) . '">' . esc_html( BoardMetrics::display_text( (string) $i['message'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</details></td>';
			echo '<td>' . esc_html( $items[ $p['item_slug'] ] ?? (string) $p['item_slug'] ) . '</td>';
			echo '<td class="gdp-fin-num"><strong>' . esc_html( BoardMetrics::money( (float) $p['amount'] ) ) . '</strong>' . ( 'pecuniario' === $p['source'] ? '<br><span class="gdp-fin-muted">' . esc_html__( 'pecuniario', 'gestion-de-proyectos' ) . '</span>' : '' ) . '</td>';
			echo '<td><span class="gdp-fin-pill gdp-fin-pill--' . esc_attr( $state ) . '">' . esc_html( $statuses[ $p['status'] ] ?? (string) $p['status'] ) . '</span>' . ( PaymentValidator::blocks( $issues ) ? '<br><span class="gdp-fin-text-bad gdp-fin-small">' . esc_html__( 'con hallazgos que bloquean', 'gestion-de-proyectos' ) . '</span>' : '' ) . '</td>';
			echo '</tr>';
			$csv[] = array( (string) $p['code'], (string) $p['source'], (string) ( $items[ $p['item_slug'] ] ?? $p['item_slug'] ), $supplier, (string) $p['description'], (string) $p['commitment'], (string) ( $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'] ), (string) $p['doc_number'], (string) $p['doc_date'], (string) $p['executed_at'], (string) $p['paid_at'], (string) $p['egress_number'], (string) (int) round( (float) $p['amount'] ), (string) $inst, (string) ( $statuses[ $p['status'] ] ?? $p['status'] ), (string) $p['observation'] );
		}
		echo '</tbody><tfoot><tr><th scope="row" colspan="3">' . esc_html( sprintf( /* translators: número de pagos. */ __( 'Total de los %d pagos mostrados', 'gestion-de-proyectos' ), count( $rows ) ) ) . '</th><td class="gdp-fin-num"><strong>' . esc_html( BoardMetrics::money( $total ) ) . '</strong></td><td></td></tr></tfoot></table></div>';
		echo '<p>' . self::csv_button( $csv, 'pagos-' . sanitize_file_name( (string) $ctx->project['code'] ) . '.csv', __( 'Descargar estos pagos (CSV)', 'gestion-de-proyectos' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';
	}

	/**
	 * Indica si un pago pasa el filtro.
	 *
	 * @param array<string,mixed>  $p      Pago.
	 * @param array<string,string> $filter Filtro.
	 * @param array<string,mixed>  $data   Datos.
	 * @return bool
	 */
	private static function payment_matches( array $p, array $filter, array $data ): bool {
		$estado = $filter['estado'];
		if ( 'por_pagar' === $estado && ! in_array( $p['status'], PaymentRepository::COMMITTED, true ) ) {
			return false;
		}
		if ( 'pagados' === $estado && ! in_array( $p['status'], PaymentRepository::PAID, true ) ) {
			return false;
		}
		if ( 'observados' === $estado && ! in_array( $p['status'], array( 'observado', 'corregido' ), true ) ) {
			return false;
		}
		if ( '' !== $estado && ! in_array( $estado, array( 'por_pagar', 'pagados', 'observados' ), true ) && $p['status'] !== $estado ) {
			return false;
		}
		if ( '' !== $filter['item'] && $p['item_slug'] !== $filter['item'] ) {
			return false;
		}
		if ( '' !== $filter['fuente'] && $p['source'] !== $filter['fuente'] ) {
			return false;
		}
		if ( '' !== $filter['q'] ) {
			$haystack = mb_strtolower( implode( ' ', array( (string) $p['code'], (string) $p['description'], (string) $p['doc_number'], (string) $p['commitment'], BoardData::supplier_name( $data, (int) $p['supplier_id'] ) ) ) );
			if ( false === strpos( $haystack, mb_strtolower( $filter['q'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	// -------------------------------------------------------------- Rendiciones

	/**
	 * Rendiciones.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_rendiciones( array $data, BoardContext $ctx, array $route ): void {
		unset( $route );
		$status   = $data['status'];
		$profile  = $data['profile'];
		$labels   = $profile->rendition_statuses();
		$pid      = (int) $data['project_id'];
		$timeline = array_reverse( (array) $status['renditions'] );
		echo '<section class="gdp-fin-section">';
		echo self::chart_with_tip( BoardCharts::strip( $data['cells'] ), 'rendiciones' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Mes a mes, del más reciente al más antiguo', 'gestion-de-proyectos' ) . '</h3>';
		echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table"><thead><tr><th scope="col">' . esc_html__( 'Mes', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Pagado en el mes (Fondo)', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Estado', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Respaldos a la universidad', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Plazo en SISREC', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Días hábiles', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
		foreach ( $timeline as $t ) {
			$r     = $t['rendition'];
			$state = $r ? ( $labels[ $r['status'] ]['label'] ?? (string) $r['status'] ) . ' (' . BoardMetrics::rendition_kind_label( (string) $r['kind'] ) . ')' : ( $t['current'] ? __( 'mes en curso', 'gestion-de-proyectos' ) : ( 'mensual' === $t['expected_kind'] ? __( 'sin registrar (con gasto)', 'gestion-de-proyectos' ) : __( 'sin registrar (sin movimiento)', 'gestion-de-proyectos' ) ) );
			echo '<tr class="' . ( $t['overdue'] ? 'gdp-fin-row--bad' : '' ) . '"><th scope="row">' . esc_html( BoardMetrics::month_label( (string) $t['period'] ) ) . '</th><td>' . esc_html( BoardMetrics::money( (float) $t['amount'] ) ) . '</td><td>' . esc_html( $state ) . ( $t['overdue'] ? '<br><span class="gdp-fin-text-bad gdp-fin-small">' . esc_html__( 'vencida sin estado declarado', 'gestion-de-proyectos' ) . '</span>' : '' ) . '</td><td>' . esc_html( BoardMetrics::date( (string) $t['internal_due'] ) ) . '</td><td>' . esc_html( BoardMetrics::date( (string) $t['platform_due'] ) ) . '</td><td class="' . ( ! $t['submitted'] && (int) $t['days_left'] < 0 ? 'gdp-fin-text-bad' : '' ) . '">' . ( $t['submitted'] ? '—' : (int) $t['days_left'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="gdp-fin-note">' . esc_html__( 'El módulo no lee SISREC: una rendición presentada en la plataforma aparece aquí cuando se declara su estado en el panel (Proyectos → Finanzas → Rendiciones).', 'gestion-de-proyectos' ) . '</p></section>';

		$registered = (array) $data['renditions'];
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Rendiciones registradas', 'gestion-de-proyectos' ) . '</h3>';
		if ( empty( $registered ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Todavía no hay rendiciones registradas en el plugin.', 'gestion-de-proyectos' ) . '</p></section>';
			return;
		}
		$by_rendition = array();
		foreach ( (array) $data['payments'] as $p ) {
			if ( (int) $p['rendition_id'] > 0 ) {
				$by_rendition[ (int) $p['rendition_id'] ][] = $p;
			}
		}
		foreach ( array_reverse( $registered ) as $r ) {
			$payments = $by_rendition[ (int) $r['id'] ] ?? array();
			$sum      = array_sum( array_map( static fn( array $p ): float => (float) $p['amount'], $payments ) );
			/* translators: 1: mes, 2: fuente, 3: tipo, 4: estado. */
			echo '<details class="gdp-fin-item"><summary><strong>' . esc_html( sprintf( __( '%1$s · %2$s · %3$s', 'gestion-de-proyectos' ), BoardMetrics::month_label( (string) $r['period'] ), $profile->sources()[ $r['source'] ] ?? (string) $r['source'], BoardMetrics::rendition_kind_label( (string) $r['kind'] ) ) ) . '</strong> <span class="gdp-fin-muted">' . esc_html( ( $labels[ $r['status'] ]['label'] ?? (string) $r['status'] ) . ' · ' . BoardMetrics::money( $sum ) ) . '</span></summary>';
			self::facts(
				array(
					__( 'Estado', 'gestion-de-proyectos' ) => ( $labels[ $r['status'] ]['label'] ?? (string) $r['status'] ) . ' · ' . sprintf( /* translators: actor. */ __( 'a cargo de %s', 'gestion-de-proyectos' ), (string) ( $labels[ $r['status'] ]['actor'] ?? '' ) ),
					__( 'Plazo interno', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['internal_due'] ),
					__( 'Plazo en la plataforma', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['platform_due'] ),
					__( 'Plazo de subsanación', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['fix_due'] ),
					__( 'Enviada a la universidad', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['sent_internal_at'] ),
					__( 'Enviada al otorgante', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['sent_at'] ),
					__( 'Aprobada', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['approved_at'] ),
					__( 'Devuelta', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $r['returned_at'] ),
					__( 'Notas', 'gestion-de-proyectos' )  => (string) $r['notes'],
				)
			);
			if ( $payments ) {
				echo '<ul class="gdp-fin-list">';
				foreach ( $payments as $p ) {
					echo '<li><code>' . esc_html( (string) $p['code'] ) . '</code> ' . esc_html( (string) $p['description'] ) . ' · ' . esc_html( BoardMetrics::money( (float) $p['amount'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			$events = BoardData::rendition_events( (int) $r['id'] );
			if ( $events ) {
				echo '<p><strong>' . esc_html__( 'Estados declarados:', 'gestion-de-proyectos' ) . '</strong></p><ul class="gdp-fin-list">';
				foreach ( $events as $e ) {
					echo '<li>' . esc_html( BoardMetrics::date( (string) $e['event_date'] ) . ' · ' . ( $labels[ $e['event_key'] ]['label'] ?? (string) $e['event_key'] ) . ( $e['note'] ? ' · ' . (string) $e['note'] : '' ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p>';
			echo '<a class="button button-small" href="' . esc_url( FinancePage::export_url( $pid, 'expedient', (int) $r['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Expediente', 'gestion-de-proyectos' ) . '</a> ';
			if ( 'sin_movimiento' === $r['kind'] ) {
				echo '<a class="button button-small" href="' . esc_url( FinancePage::export_url( $pid, 'zero_letter', (int) $r['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Carta y carátula de gasto cero', 'gestion-de-proyectos' ) . '</a> ';
			} elseif ( $ctx->export ) {
				echo '<a class="button button-small" href="' . esc_url( FinancePage::export_url( $pid, 'bulk_sheet', (int) $r['id'] ) ) . '">' . esc_html__( 'Planilla de carga masiva', 'gestion-de-proyectos' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( FinancePage::export_url( $pid, 'bulk_zip', (int) $r['id'] ) ) . '">' . esc_html__( 'ZIP de respaldos', 'gestion-de-proyectos' ) . '</a>';
			}
			echo '</p>';
			echo '</details>';
		}
		echo '</section>';
	}

	// --------------------------------------------------------------------- Caja

	/**
	 * Caja: curva, programación frente a lo real, controles y conciliación.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_caja( array $data, BoardContext $ctx, array $route ): void {
		unset( $route );
		$status = $data['status'];
		$plan   = $status['cash_plan'];
		echo '<section class="gdp-fin-section">';
		echo self::chart_with_tip( BoardCharts::cash_curve( $data['curve'], (string) $data['current'] ), 'caja' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Programación de caja vigente', 'gestion-de-proyectos' ) . '</h3>';
		if ( ! is_array( $plan ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'No hay una programación de caja registrada.', 'gestion-de-proyectos' ) . '</p></section>';
		} else {
			$p = $plan['plan'];
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: nombre, 2: estado, 3: fecha de envío. */
					__( '%1$s · estado: %2$s · enviada el %3$s', 'gestion-de-proyectos' ),
					(string) $p['name'],
					(string) $p['status'],
					BoardMetrics::date( (string) $p['submitted_at'] )
				)
			) . '</p>';
			$paid     = (array) $data['paid_by_month']['fondo'];
			$received = (array) $data['received'];
			$sum      = array_fill_keys( array( 't', 'tr', 's', 'sr', 'c' ), 0.0 );
			echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table gdp-fin-table--num"><thead><tr><th scope="col">' . esc_html__( 'Mes', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Transferencia programada', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Transferido real', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Gasto programado', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Pagado real', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Aporte programado', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Hito', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $plan['rows'] as $r ) {
				$m          = (string) $r['period'];
				$past       = $m <= (string) $data['current'];
				$sum['t']  += (float) $r['transfer'];
				$sum['s']  += (float) $r['spend'];
				$sum['c']  += (float) $r['cash'];
				$sum['tr'] += $past ? (float) ( $received[ $m ] ?? 0 ) : 0;
				$sum['sr'] += $past ? (float) ( $paid[ $m ] ?? 0 ) : 0;
				echo '<tr><th scope="row">' . esc_html( BoardMetrics::month_label( $m ) ) . '</th><td>' . esc_html( BoardMetrics::money( (float) $r['transfer'] ) ) . '</td><td>' . ( $past ? esc_html( BoardMetrics::money( (float) ( $received[ $m ] ?? 0 ) ) ) : '—' ) . '</td><td>' . esc_html( BoardMetrics::money( (float) $r['spend'] ) ) . '</td><td>' . ( $past ? esc_html( BoardMetrics::money( (float) ( $paid[ $m ] ?? 0 ) ) ) : '—' ) . '</td><td>' . esc_html( BoardMetrics::money( (float) $r['cash'] ) ) . '</td><td class="gdp-fin-wrap">' . esc_html( (string) $r['milestone'] ) . '</td></tr>';
			}
			echo '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'Total', 'gestion-de-proyectos' ) . '</th><td>' . esc_html( BoardMetrics::money( $sum['t'] ) ) . '</td><td>' . esc_html( BoardMetrics::money( $sum['tr'] ) ) . '</td><td>' . esc_html( BoardMetrics::money( $sum['s'] ) ) . '</td><td>' . esc_html( BoardMetrics::money( $sum['sr'] ) ) . '</td><td>' . esc_html( BoardMetrics::money( $sum['c'] ) ) . '</td><td></td></tr></tfoot></table></div>';

			echo '<h4 class="gdp-fin-h4">' . esc_html__( 'Controles de la programación', 'gestion-de-proyectos' ) . '</h4>';
			self::conditions( (array) $plan['checks'], false, __( 'no evaluable', 'gestion-de-proyectos' ) );
			if ( '' !== trim( (string) $p['notes'] ) ) {
				echo '<details class="gdp-fin-notes"><summary>' . esc_html__( 'Notas de la programación', 'gestion-de-proyectos' ) . '</summary><p>' . nl2br( esc_html( (string) $p['notes'] ) ) . '</p></details>';
			}
			if ( $ctx->export ) {
				echo '<p><a class="button button-small" href="' . esc_url( FinancePage::export_url( (int) $data['project_id'], 'cash_plan', (int) $p['id'] ) ) . '">' . esc_html__( 'Descargar la programación (XLSX)', 'gestion-de-proyectos' ) . '</a></p>';
			}
			echo '</section>';
		}

		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Conciliación con el centro de costo', 'gestion-de-proyectos' ) . '</h3><div class="gdp-fin-kpis">';
		foreach ( $status['sources'] as $s ) {
			$sub = (int) $s['ledger_count'] > 0
				? sprintf(
					/* translators: 1: saldo de la cartola, 2: diferencia, 3: movimientos por aclarar. */
					__( 'cartola %1$s · diferencia %2$s · %3$d por aclarar', 'gestion-de-proyectos' ),
					BoardMetrics::money( (float) $s['ledger_balance'] ),
					BoardMetrics::money( (float) $s['difference'] ),
					(int) $s['unmatched']
				)
				: __( 'sin movimientos de la cartola importados', 'gestion-de-proyectos' );
			self::kpi( sprintf( /* translators: fuente. */ __( 'Saldo calculado · %s', 'gestion-de-proyectos' ), (string) $s['label'] ), BoardMetrics::money( (float) $s['balance'] ), $sub, 'saldo' );
		}
		echo '</div></section>';
	}

	// ----------------------------------------------------------------- Convenio

	/**
	 * Convenio, montos, modificaciones, garantías y reglas.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_convenio( array $data, BoardContext $ctx, array $route ): void {
		unset( $ctx, $route );
		$status = $data['status'];
		$a      = $status['agreement'];
		$fund   = (float) $a['fund_amount'];
		$cash   = (float) $a['cash_amount'];
		$inkind = (float) $a['inkind_amount'];
		$all    = $fund + $cash + $inkind;
		echo '<div class="gdp-fin-cols"><section class="gdp-fin-card"><h3 class="gdp-fin-h">' . esc_html__( 'Convenio', 'gestion-de-proyectos' ) . '</h3>';
		self::facts(
			array(
				__( 'Otorgante', 'gestion-de-proyectos' ) => (string) $a['funder'],
				__( 'Programa', 'gestion-de-proyectos' )  => (string) $a['program'],
				__( 'Fecha del convenio', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $a['agreement_date'] ),
				__( 'Acto aprobatorio', 'gestion-de-proyectos' ) => trim( (string) $a['approval_act'] . ( $a['approval_date'] ? ' (' . BoardMetrics::date( (string) $a['approval_date'] ) . ')' : '' ) ),
				__( 'Plazo de ejecución', 'gestion-de-proyectos' ) => BoardMetrics::date( (string) $a['start_date'] ) . ' a ' . BoardMetrics::date( (string) $a['end_date'] ) . ( (int) $a['months'] > 0 ? ' (' . sprintf( /* translators: meses. */ __( '%d meses', 'gestion-de-proyectos' ), (int) $a['months'] ) . ')' : '' ),
				__( 'Plataforma de rendición', 'gestion-de-proyectos' ) => trim( (string) $a['platform'] . ' ' . (string) $a['platform_code'] ),
				__( 'Centro de costo', 'gestion-de-proyectos' ) => (string) $a['cost_center'],
				__( 'Garantía exigida', 'gestion-de-proyectos' ) => ! empty( $a['guarantee_required'] ) ? __( 'sí', 'gestion-de-proyectos' ) : __( 'no', 'gestion-de-proyectos' ),
				__( 'Perfil de reglas', 'gestion-de-proyectos' ) => (string) $status['profile_label'],
			)
		);
		if ( '' !== trim( (string) $a['notes'] ) ) {
			echo '<details class="gdp-fin-notes"><summary>' . esc_html__( 'Notas del convenio', 'gestion-de-proyectos' ) . '</summary><p>' . nl2br( esc_html( (string) $a['notes'] ) ) . '</p></details>';
		}
		echo '</section>';
		echo '<section class="gdp-fin-card"><h3 class="gdp-fin-h">' . esc_html__( 'Financiamiento por fuente', 'gestion-de-proyectos' ) . '</h3><div class="gdp-fin-kpis gdp-fin-kpis--two">';
		self::kpi( __( 'Fondo', 'gestion-de-proyectos' ), BoardMetrics::money( $fund ), BoardMetrics::pct_label( BoardMetrics::pct( $fund, $all ) ) . ' ' . __( 'del costo total', 'gestion-de-proyectos' ), 'convenio' );
		self::kpi( __( 'Aporte pecuniario', 'gestion-de-proyectos' ), BoardMetrics::money( $cash ), BoardMetrics::pct_label( BoardMetrics::pct( $cash, $all ) ) . ' ' . __( 'del costo total', 'gestion-de-proyectos' ), 'pecuniario' );
		self::kpi( __( 'Aporte no pecuniario', 'gestion-de-proyectos' ), BoardMetrics::money( $inkind ), BoardMetrics::pct_label( BoardMetrics::pct( $inkind, $all ) ) . ' ' . __( 'del costo total', 'gestion-de-proyectos' ), 'no_pecuniario' );
		self::kpi( __( 'Costo total', 'gestion-de-proyectos' ), BoardMetrics::money( $all ), '', '' );
		echo '</div>';
		echo '<p class="gdp-fin-muted">' . esc_html( sprintf( /* translators: 1: usadas, 2: máximo. */ __( 'Reitemizaciones usadas: %1$d de %2$d.', 'gestion-de-proyectos' ), (int) $status['reitemizations']['used'], (int) $status['reitemizations']['max'] ) ) . '</p>';
		echo '</section></div>';

		$mods   = (array) $data['modifications'];
		$labels = ModificationRepository::labels();
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Modificaciones del convenio', 'gestion-de-proyectos' ) . '</h3>';
		if ( empty( $mods ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Sin modificaciones registradas.', 'gestion-de-proyectos' ) . '</p>';
		} else {
			echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table"><thead><tr><th scope="col">' . esc_html__( 'Tipo', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Estado', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Solicitada', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Aprobada', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Acto', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Detalle', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
			foreach ( $mods as $m ) {
				$state = 'aprobada' === $m['status'] ? 'ok' : ( 'rechazada' === $m['status'] ? 'bad' : 'pending' );
				echo '<tr><th scope="row">' . esc_html( $labels['kinds'][ $m['kind'] ] ?? (string) $m['kind'] ) . '</th><td><span class="gdp-fin-pill gdp-fin-pill--' . esc_attr( $state ) . '">' . esc_html( $labels['statuses'][ $m['status'] ] ?? (string) $m['status'] ) . '</span></td><td>' . esc_html( BoardMetrics::date( (string) $m['requested_at'] ) ) . '</td><td>' . esc_html( BoardMetrics::date( (string) $m['approved_at'] ) ) . '</td><td>' . esc_html( (string) $m['act_number'] ) . '</td><td class="gdp-fin-wrap">' . esc_html( (string) $m['notes'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		$guarantees = (array) $data['guarantees'];
		$glabels    = GuaranteeRepository::labels();
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Garantías', 'gestion-de-proyectos' ) . '</h3>';
		if ( empty( $guarantees ) ) {
			echo '<p class="gdp-fin-muted">' . esc_html( ! empty( $a['guarantee_required'] ) ? __( 'El convenio exige garantía de fiel cumplimiento, pero no hay ninguna registrada.', 'gestion-de-proyectos' ) : __( 'Sin garantías registradas.', 'gestion-de-proyectos' ) ) . '</p>';
		} else {
			echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table"><thead><tr><th scope="col">' . esc_html__( 'Tipo', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Instrumento', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Monto', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Vigencia', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Estado', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
			foreach ( $guarantees as $g ) {
				echo '<tr><th scope="row">' . esc_html( $glabels['kinds'][ $g['kind'] ] ?? (string) $g['kind'] ) . '</th><td>' . esc_html( trim( ( $glabels['instruments'][ $g['instrument'] ] ?? (string) $g['instrument'] ) . ' ' . (string) $g['number'] . ( '' !== (string) $g['issuer'] ? ' · ' . (string) $g['issuer'] : '' ) ) ) . '</td><td>' . esc_html( BoardMetrics::money( (float) $g['amount'] ) ) . '</td><td>' . esc_html( BoardMetrics::date( (string) $g['issued_at'] ) . ' a ' . BoardMetrics::date( (string) $g['valid_until'] ) ) . '</td><td>' . esc_html( $glabels['statuses'][ $g['status'] ] ?? (string) $g['status'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Reglas vigentes', 'gestion-de-proyectos' ) . '</h3>';
		echo '<p class="gdp-fin-muted">' . esc_html__( 'Las reglas del convenio (registradas para este proyecto) prevalecen sobre las del perfil del fondo; cada una indica su fuente.', 'gestion-de-proyectos' ) . '</p>';
		echo '<details class="gdp-fin-notes"><summary>' . esc_html( sprintf( /* translators: número de reglas. */ __( 'Ver las %d reglas', 'gestion-de-proyectos' ), count( (array) $status['rules'] ) ) ) . '</summary>';
		echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table"><thead><tr><th scope="col">' . esc_html__( 'Regla', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Valor', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Fuente', 'gestion-de-proyectos' ) . '</th><th scope="col">' . esc_html__( 'Origen', 'gestion-de-proyectos' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $status['rules'] as $r ) {
			echo '<tr' . ( 'convenio' === ( $r['layer'] ?? '' ) ? ' class="gdp-fin-row--own"' : '' ) . '><th scope="row">' . esc_html( (string) $r['label'] ) . '</th><td>' . esc_html( (string) $r['value'] ) . '</td><td class="gdp-fin-wrap">' . esc_html( (string) $r['source'] ) . '</td><td>' . esc_html( 'convenio' === ( $r['layer'] ?? '' ) ? __( 'proyecto', 'gestion-de-proyectos' ) : __( 'perfil', 'gestion-de-proyectos' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></details></section>';
	}

	// ------------------------------------------------------------- Paso a paso

	/**
	 * Paso a paso: acciones, ciclo mensual, guías y hojas de ejecución.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_paso( array $data, BoardContext $ctx, array $route ): void {
		$guide = (string) ( $route['guide'] ?? '' );
		if ( '' !== $guide ) {
			self::sheet( $data, $ctx, $guide, (string) ( $route['entity'] ?? 'project' ), (int) ( $route['id'] ?? 0 ) );
			return;
		}
		$actions = (array) $data['actions'];
		if ( $actions ) {
			$a = $actions[0];
			echo '<section class="gdp-fin-card gdp-fin-first"><h3 class="gdp-fin-h">' . esc_html__( 'Lo primero que hay que hacer', 'gestion-de-proyectos' ) . '</h3><p><strong>' . esc_html( (string) $a['title'] ) . '</strong></p><p class="gdp-fin-muted">' . esc_html( (string) $a['detail'] ) . '</p>';
			if ( '' !== (string) $a['guide'] ) {
				echo '<p><a class="button" href="' . esc_url(
					$ctx->url(
						array(
							'fin'  => 'paso',
							'guia' => (string) $a['guide'],
							'ent'  => (string) $a['entity_type'],
							'id'   => (int) $a['entity_id'],
						)
					)
				) . '">' . esc_html__( 'Abrir la hoja de ejecución', 'gestion-de-proyectos' ) . '</a></p>';
			}
			echo '</section>';
		}
		self::actions_list( $data, $ctx, 0 );

		$topics = BoardHelp::topics( $data );
		echo '<section class="gdp-fin-section">';
		echo '<h3 class="gdp-fin-h">' . esc_html( $topics[1]['title'] ) . '</h3>' . $topics[1]['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</section>';

		$profile = $data['profile'];
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Todas las guías', 'gestion-de-proyectos' ) . '</h3><p class="gdp-fin-muted">' . esc_html__( 'Cada guía se abre como hoja de ejecución: la pantalla de SISREC o de la universidad, quién actúa, qué hacer y cada valor en el formato que la pantalla pide, con su botón para copiar. Si la guía necesita una rendición, una cuota o un proveedor, la hoja permite elegirlo.', 'gestion-de-proyectos' ) . '</p><ul class="gdp-fin-guides">';
		foreach ( $profile->guides() as $key => $g ) {
			echo '<li><a href="' . esc_url(
				$ctx->url(
					array(
						'fin'  => 'paso',
						'guia' => (string) $key,
						'ent'  => (string) $g['entity'],
					)
				)
			) . '">' . esc_html( (string) $g['label'] ) . '</a> <span class="gdp-fin-muted">' . esc_html( sprintf( /* translators: 1: número de pasos, 2: fuente. */ __( '%1$d pasos · %2$s', 'gestion-de-proyectos' ), count( (array) $g['steps'] ), (string) $g['source'] ) ) . '</span></li>';
		}
		echo '</ul>';
		$links = $profile->links();
		if ( $links ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Enlaces públicos:', 'gestion-de-proyectos' );
			foreach ( $links as $l ) {
				echo ' <a href="' . esc_url( (string) $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $l['label'] ) . '</a>';
			}
			echo '</p>';
		}
		echo '</section>';
	}

	/**
	 * Hoja de ejecución de solo lectura.
	 *
	 * @param array<string,mixed> $data        Datos.
	 * @param BoardContext        $ctx         Contexto.
	 * @param string              $guide       Guía.
	 * @param string              $entity_type Entidad.
	 * @param int                 $entity_id   Identificador.
	 * @return void
	 */
	private static function sheet( array $data, BoardContext $ctx, string $guide, string $entity_type, int $entity_id ): void {
		$pid     = (int) $data['project_id'];
		$profile = $data['profile'];
		$def     = $profile->guides()[ $guide ] ?? null;
		echo '<p><a href="' . esc_url( $ctx->url( array( 'fin' => 'paso' ) ) ) . '">&larr; ' . esc_html__( 'Volver a las acciones y guías', 'gestion-de-proyectos' ) . '</a></p>';
		if ( ! $def ) {
			echo '<p>' . esc_html__( 'La guía no existe en el perfil del fondo.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		$entity_type = in_array( $entity_type, array( 'project', 'rendition', 'installment', 'supplier' ), true ) ? $entity_type : (string) $def['entity'];
		if ( $entity_id > 0 && ! self::entity_belongs( $pid, $entity_type, $entity_id ) ) {
			$entity_id = 0;
		}
		$sheet      = Assistant::sheet( $pid, $guide, $entity_type, $entity_id );
		$has_entity = 'project' === $entity_type || $entity_id > 0;
		echo '<article class="gdp-fin-sheet"><h3 class="gdp-fin-h">' . esc_html( (string) $sheet['label'] ) . '</h3>';
		echo '<p class="gdp-fin-muted">' . esc_html( sprintf( /* translators: 1: fuente, 2: actores. */ __( 'Fuente: %1$s. Actores: %2$s.', 'gestion-de-proyectos' ), (string) $sheet['source'], implode( ', ', (array) $sheet['actors'] ) ) ) . '</p>';
		self::entity_picker( $data, $ctx, $guide, $entity_type, $entity_id );
		if ( $has_entity && ! empty( $sheet['missing'] ) ) {
			echo '<p class="gdp-fin-flag gdp-fin-flag--warn"><span aria-hidden="true">!</span> ' . esc_html( sprintf( /* translators: datos faltantes. */ __( 'Faltan datos para completar la hoja: %s. Se registran en el panel.', 'gestion-de-proyectos' ), implode( ', ', $sheet['missing_labels'] ? $sheet['missing_labels'] : $sheet['missing'] ) ) ) . '</p>';
		}
		echo '<ol class="gdp-fin-steps">';
		foreach ( (array) $sheet['steps'] as $step ) {
			$done = $step['done'];
			echo '<li class="gdp-fin-step' . ( $done ? ' is-done' : '' ) . '"><div class="gdp-fin-step__head"><span class="gdp-fin-step__actor">' . esc_html( (string) $step['actor'] ) . '</span> <span class="gdp-fin-step__screen">' . esc_html( (string) $step['screen'] ) . '</span>' . ( $done ? ' <span class="gdp-fin-flag gdp-fin-flag--ok"><span aria-hidden="true">✓</span> ' . esc_html( sprintf( /* translators: 1: fecha, 2: usuario. */ __( 'hecho el %1$s por %2$s', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $done['event_date'] ), (string) $done['user'] ) ) . '</span>' : '' ) . '</div>';
			echo '<p>' . esc_html( (string) $step['instruction'] ) . '</p>';
			if ( ! empty( $step['fields'] ) ) {
				self::fields( (array) $step['fields'] );
			}
			if ( ! empty( $step['per_payment'] ) && ! empty( $step['payments'] ) ) {
				foreach ( (array) $step['payments'] as $ps ) {
					echo '<details class="gdp-fin-detail"' . ( $ps['blocks'] ? ' open' : '' ) . '><summary><code>' . esc_html( (string) $ps['code'] ) . '</code> ' . esc_html( (string) $ps['description'] ) . ( $ps['blocks'] ? ' <span class="gdp-fin-text-bad">' . esc_html__( '(tiene hallazgos que bloquean)', 'gestion-de-proyectos' ) . '</span>' : '' ) . '</summary>';
					self::fields( (array) $ps['fields'] );
					if ( ! empty( $ps['issues'] ) ) {
						echo '<ul class="gdp-fin-list gdp-fin-issues">';
						foreach ( (array) $ps['issues'] as $i ) {
							echo '<li class="gdp-fin-issue--' . esc_attr( (string) $i['severity'] ) . '">' . esc_html( BoardMetrics::display_text( (string) $i['message'] ) ) . '</li>';
						}
						echo '</ul>';
					}
					echo '</details>';
				}
			}
			if ( '' !== (string) $step['check'] ) {
				echo '<p class="gdp-fin-step__check"><span aria-hidden="true">✓</span> ' . esc_html( (string) $step['check'] ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ol>';
		if ( $ctx->panel && $ctx->edit && $has_entity ) {
			echo '<p><a class="button" href="' . esc_url(
				FinancePage::url(
					$pid,
					array(
						'view'        => 'assistant',
						'guide'       => $guide,
						'entity_type' => $entity_type,
						'entity_id'   => $entity_id,
					)
				)
			) . '">' . esc_html__( 'Marcar los pasos hechos en el panel', 'gestion-de-proyectos' ) . '</a></p>';
		}
		echo '</article>';
	}

	/**
	 * Campos de un paso con su valor y el botón de copiar.
	 *
	 * @param array<int,array<string,mixed>> $fields Campos.
	 * @return void
	 */
	private static function fields( array $fields ): void {
		echo '<dl class="gdp-fin-fields">';
		foreach ( $fields as $f ) {
			$value = (string) $f['value'];
			echo '<dt>' . esc_html( (string) $f['label'] ) . '</dt><dd>';
			if ( ! empty( $f['file'] ) ) {
				echo '' !== $value ? '<a class="button button-small" href="' . esc_url( $value ) . '">' . esc_html__( 'Descargar', 'gestion-de-proyectos' ) . '</a>' : '<span class="gdp-fin-text-bad">' . esc_html__( 'falta', 'gestion-de-proyectos' ) . '</span>';
			} elseif ( '' === $value ) {
				echo '<span class="gdp-fin-text-bad">' . esc_html__( 'falta', 'gestion-de-proyectos' ) . '</span>';
			} else {
				echo '<code class="gdp-fin-copyvalue">' . esc_html( $value ) . '</code> <button type="button" class="button button-small gdp-copy" data-copy="' . esc_attr( $value ) . '">' . esc_html__( 'Copiar', 'gestion-de-proyectos' ) . '</button>';
			}
			if ( ! empty( $f['hint'] ) ) {
				echo ' <span class="gdp-fin-muted">' . esc_html( (string) $f['hint'] ) . '</span>';
			}
			echo '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Selector de la rendición, cuota o proveedor con que se completa una hoja.
	 *
	 * @param array<string,mixed> $data        Datos.
	 * @param BoardContext        $ctx         Contexto.
	 * @param string              $guide       Guía.
	 * @param string              $entity_type Entidad.
	 * @param int                 $entity_id   Elegida.
	 * @return void
	 */
	private static function entity_picker( array $data, BoardContext $ctx, string $guide, string $entity_type, int $entity_id ): void {
		$options = array();
		$label   = '';
		$pid     = (int) $data['project_id'];
		switch ( $entity_type ) {
			case 'rendition':
				$label    = __( 'Rendición', 'gestion-de-proyectos' );
				$statuses = $data['profile']->rendition_statuses();
				foreach ( array_reverse( (array) $data['renditions'] ) as $r ) {
					$options[ (int) $r['id'] ] = sprintf( '%s · %s · %s', BoardMetrics::month_label( (string) $r['period'] ), BoardMetrics::rendition_kind_label( (string) $r['kind'] ), (string) ( $statuses[ $r['status'] ]['label'] ?? $r['status'] ) );
				}
				break;
			case 'installment':
				$label = __( 'Cuota', 'gestion-de-proyectos' );
				foreach ( (array) $data['status']['installments'] as $i ) {
					/* translators: 1: número, 2: monto. */
					$options[ (int) $i['id'] ] = sprintf( __( 'Cuota %1$d · %2$s', 'gestion-de-proyectos' ), (int) $i['number'], BoardMetrics::money( (float) $i['amount'] ) );
				}
				break;
			case 'supplier':
				$label = __( 'Proveedor', 'gestion-de-proyectos' );
				foreach ( SupplierRepository::for_project( $pid, true ) as $s ) {
					$options[ (int) $s['id'] ] = (string) $s['name'];
				}
				break;
			default:
				return;
		}
		$form = $ctx->form_start(
			array(
				'fin'  => 'paso',
				'guia' => $guide,
				'ent'  => $entity_type,
			),
			'gdp-fin-form gdp-fin-picker'
		);
		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		if ( $options ) {
			echo '<label>' . esc_html( $label ) . ' <select name="gdp_id"><option value="0">' . esc_html__( 'Solo las indicaciones', 'gestion-de-proyectos' ) . '</option>';
			foreach ( $options as $id => $text ) {
				echo '<option value="' . (int) $id . '"' . selected( $entity_id, (int) $id, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select></label> <button type="submit" class="button button-small">' . esc_html__( 'Completar la hoja', 'gestion-de-proyectos' ) . '</button>';
		} else {
			echo '<span class="gdp-fin-muted">' . esc_html__( 'Todavía no hay registros de este tipo: la hoja muestra solo las indicaciones.', 'gestion-de-proyectos' ) . '</span>';
		}
		echo '</form>';
	}

	/**
	 * Indica si una entidad pertenece al proyecto.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $entity_type Tipo.
	 * @param int    $entity_id   Identificador.
	 * @return bool
	 */
	private static function entity_belongs( int $project_id, string $entity_type, int $entity_id ): bool {
		switch ( $entity_type ) {
			case 'rendition':
				$r = RenditionRepository::find( $entity_id );
				return is_array( $r ) && (int) $r['project_id'] === $project_id;
			case 'installment':
				$i = InstallmentRepository::find( $entity_id );
				return is_array( $i ) && (int) $i['project_id'] === $project_id;
			case 'supplier':
				$s = SupplierRepository::find( $entity_id );
				return is_array( $s ) && in_array( (int) $s['project_id'], array( 0, $project_id ), true );
		}

		return true;
	}

	// ----------------------------------------------------------------- Reportes

	/**
	 * Reportes: informe imprimible, texto para informes y descargas.
	 *
	 * @param array<string,mixed>  $data  Datos.
	 * @param BoardContext         $ctx   Contexto.
	 * @param array<string,string> $route Ruta.
	 * @return void
	 */
	private static function tab_reportes( array $data, BoardContext $ctx, array $route ): void {
		unset( $route );
		$code = sanitize_file_name( (string) $ctx->project['code'] );
		echo '<section class="gdp-fin-section gdp-fin-reports"><h3 class="gdp-fin-h">' . esc_html__( 'Descargas y textos', 'gestion-de-proyectos' ) . '</h3><div class="gdp-fin-actionsbar">';
		if ( $ctx->export && Workbook::available() ) {
			echo '<a class="button button-primary" href="' . esc_url( FinancePage::export_url( (int) $ctx->project['id'], 'workbook' ) ) . '">' . esc_html__( 'Todo el estado financiero en Excel', 'gestion-de-proyectos' ) . '</a> ';
		}
		echo '<button type="button" class="button" data-gdp-print="report">' . esc_html__( 'Imprimir o guardar en PDF el informe', 'gestion-de-proyectos' ) . '</button> ';
		echo self::csv_button( BoardReport::payments_csv( $data ), 'pagos-' . $code . '.csv', __( 'Pagos (CSV)', 'gestion-de-proyectos' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo self::csv_button( BoardReport::items_csv( $data ), 'items-' . $code . '.csv', __( 'Ítems (CSV)', 'gestion-de-proyectos' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo self::csv_button( BoardReport::installments_csv( $data ), 'cuotas-' . $code . '.csv', __( 'Cuotas (CSV)', 'gestion-de-proyectos' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo self::csv_button( BoardReport::renditions_csv( $data ), 'rendiciones-' . $code . '.csv', __( 'Rendiciones (CSV)', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		echo '</div>';
		$exports = BoardReport::exports( $data, $ctx );
		if ( $exports ) {
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Documentos que genera el módulo:', 'gestion-de-proyectos' ) . '</p><ul class="gdp-fin-list">';
			foreach ( $exports as $e ) {
				echo '<li><a href="' . esc_url( $e['url'] ) . '"' . ( $e['new'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $e['label'] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		$text = BoardReport::summary_text( $data );
		echo '<section class="gdp-fin-section"><h3 class="gdp-fin-h">' . esc_html__( 'Texto para informes y correos', 'gestion-de-proyectos' ) . '</h3>';
		echo '<p class="gdp-fin-muted">' . esc_html__( 'Resumen redactado con las cifras de hoy, para pegar en un informe, un acta o un correo a la contraparte; conviene revisarlo antes de enviarlo.', 'gestion-de-proyectos' ) . '</p>';
		echo '<div class="gdp-fin-text">' . wp_kses_post( wpautop( esc_html( $text ) ) ) . '</div>';
		echo '<p><button type="button" class="button button-small gdp-copy" data-copy="' . esc_attr( $text ) . '">' . esc_html__( 'Copiar el texto', 'gestion-de-proyectos' ) . '</button></p></section>';

		echo '<section class="gdp-fin-section gdp-fin-report" id="' . esc_attr( $ctx->anchor() ) . '-informe">';
		BoardReport::render( $data );
		echo '</section>';
	}

	// ------------------------------------------------------------------ Ayuda

	/**
	 * Menú de ayuda: selector de tareas y temas.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param BoardContext        $ctx  Contexto.
	 * @return void
	 */
	private static function help_menu( array $data, BoardContext $ctx ): void {
		echo '<details class="gdp-fin-help"><summary class="button button-small"><span aria-hidden="true">?</span> ' . esc_html__( 'Ayuda', 'gestion-de-proyectos' ) . '</summary>';
		echo '<div class="gdp-fin-help__panel" role="region" aria-label="' . esc_attr__( 'Ayuda del tablero de finanzas', 'gestion-de-proyectos' ) . '">';
		$tasks = array_filter( BoardHelp::tasks( $data ), static fn( array $t ): bool => $ctx->reachable( (string) $t['tab'] ) );
		if ( $tasks ) {
			echo '<div class="gdp-fin-help__task"><h3 class="gdp-fin-h">' . esc_html__( '¿Qué necesita hacer?', 'gestion-de-proyectos' ) . '</h3>';
			echo $ctx->form_start( array(), 'gdp-fin-form gdp-fin-taskform' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
			echo '<label><span class="screen-reader-text">' . esc_html__( 'Tarea', 'gestion-de-proyectos' ) . '</span><select name="gdp_tarea" data-gdp-autosubmit>';
			echo '<option value="">' . esc_html__( 'Elija una tarea', 'gestion-de-proyectos' ) . '</option>';
			foreach ( $tasks as $key => $t ) {
				echo '<option value="' . esc_attr( (string) $key ) . '">' . esc_html( $t['label'] ) . '</option>';
			}
			echo '</select></label> <button type="submit" class="button button-small">' . esc_html__( 'Ver cómo', 'gestion-de-proyectos' ) . '</button></form>';
			echo '<p class="gdp-fin-muted">' . esc_html__( 'Lleva a la hoja de ejecución con los datos del proyecto ya resueltos o a la pestaña que corresponde.', 'gestion-de-proyectos' ) . '</p></div>';
		}
		echo '<div class="gdp-fin-help__topics">';
		foreach ( BoardHelp::topics( $data ) as $t ) {
			echo '<details class="gdp-fin-topic"><summary>' . esc_html( $t['title'] ) . '</summary><div class="gdp-fin-topic__body">' . $t['html'] . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
		}
		echo '</div></div></details>';
	}

	// ------------------------------------------------------------- Auxiliares

	/**
	 * Ayuda breve de una cifra.
	 *
	 * @param string $key Clave.
	 * @return string HTML.
	 */
	private static function tip( string $key ): string {
		$text = self::$tips[ $key ] ?? '';
		if ( '' === $text ) {
			return '';
		}

		return '<details class="gdp-fin-tip"><summary aria-label="' . esc_attr__( 'Qué significa', 'gestion-de-proyectos' ) . '"><span aria-hidden="true">?</span></summary><span class="gdp-fin-tip__body" role="note">' . esc_html( $text ) . '</span></details>';
	}

	/**
	 * Texto con su ayuda al final, sin que la ayuda quede sola en una línea:
	 * la última palabra y la ayuda no se separan.
	 *
	 * @param string $text Texto sin escapar.
	 * @param string $key  Clave de la ayuda ('' sin ayuda).
	 * @return string HTML.
	 */
	private static function with_tip( string $text, string $key ): string {
		$tip = '' !== $key ? self::tip( $key ) : '';
		if ( '' === $tip ) {
			return esc_html( $text );
		}
		$pos  = strrpos( $text, ' ' );
		$head = false === $pos ? '' : substr( $text, 0, $pos + 1 );
		$last = false === $pos ? $text : substr( $text, $pos + 1 );

		return esc_html( $head ) . '<span class="gdp-fin-keep">' . esc_html( $last ) . $tip . '</span>';
	}

	/**
	 * Inserta la ayuda en el título de un gráfico.
	 *
	 * @param string $html Gráfico.
	 * @param string $key  Clave de la ayuda.
	 * @return string HTML.
	 */
	private static function chart_with_tip( string $html, string $key ): string {
		if ( '' === $html ) {
			return '';
		}
		$pos = strpos( $html, '</figcaption>' );

		return false === $pos ? $html : substr( $html, 0, $pos ) . self::tip( $key ) . substr( $html, $pos );
	}

	/**
	 * Lista de datos (término y valor), omitiendo los vacíos.
	 *
	 * @param array<string,string> $facts Datos.
	 * @return void
	 */
	private static function facts( array $facts ): void {
		echo '<dl class="gdp-fin-facts">';
		foreach ( $facts as $term => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value || '—' === $value || '— a —' === $value ) {
				continue;
			}
			echo '<dt>' . esc_html( (string) $term ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Botón que descarga un CSV generado en el navegador.
	 *
	 * @param array<int,array<int,string>> $rows     Filas (la primera es el encabezado).
	 * @param string                       $filename Nombre del archivo.
	 * @param string                       $label    Texto del botón.
	 * @return string HTML.
	 */
	private static function csv_button( array $rows, string $filename, string $label ): string {
		return '<button type="button" class="button button-small" data-gdp-csv="' . esc_attr(
			(string) wp_json_encode(
				array(
					'name' => $filename,
					'rows' => $rows,
				)
			)
		) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Etiquetas del gráfico de uso.
	 *
	 * @param string $received Rótulo de lo recibido.
	 * @return array<string,string>
	 */
	private static function usage_labels( string $received ): array {
		return array(
			'paid'      => __( 'Pagado', 'gestion-de-proyectos' ),
			'committed' => __( 'Comprometido por pagar', 'gestion-de-proyectos' ),
			'free'      => __( 'Sin comprometer', 'gestion-de-proyectos' ),
			'received'  => $received,
		);
	}

	/**
	 * Etiquetas del gráfico de cuotas.
	 *
	 * @return array<string,string>
	 */
	private static function installment_labels(): array {
		return array(
			'approved' => __( 'Aprobado', 'gestion-de-proyectos' ),
			'rendered' => __( 'Rendido sin aprobar', 'gestion-de-proyectos' ),
			'paid'     => __( 'Pagado sin rendir', 'gestion-de-proyectos' ),
			'pending'  => __( 'Por pagar', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Identificador de una cuota por número.
	 *
	 * @param array<string,mixed> $status Estado.
	 * @param int                 $number Número.
	 * @return int
	 */
	private static function installment_id( array $status, int $number ): int {
		foreach ( (array) $status['installments'] as $i ) {
			if ( (int) $i['number'] === $number ) {
				return (int) $i['id'];
			}
		}

		return 0;
	}
}
