<?php
/**
 * Reportes del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

use GDP\Admin\Pages\FinancePage;
use GDP\Modules\Finance\Logic\BoardMetrics;
use GDP\Modules\Finance\PaymentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que el director lleva a una reunión o a un informe: el informe del
 * estado financiero (imprimible o guardable en PDF desde el navegador), un
 * texto redactado con las cifras del día para pegar en un acta o un correo,
 * las planillas CSV de pagos, ítems, cuotas y rendiciones, y los documentos
 * que el módulo genera en el servidor (expedientes, planillas de carga,
 * ficha de giro, programación de caja) según los permisos de quien mira.
 */
final class BoardReport {

	/**
	 * Texto para informes y correos.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return string Párrafos separados por una línea en blanco.
	 */
	public static function summary_text( array $data ): string {
		$status  = $data['status'];
		$project = $data['project'];
		$a       = $status['agreement'];
		$fund    = $status['sources']['fondo'];
		$cash    = $status['sources']['pecuniario'];
		$out     = array();

		$received_count = count( array_filter( (array) $status['installments'], static fn( array $i ): bool => ! empty( $i['is_received'] ) ) );
		$elapsed        = $data['elapsed'];
		$out[]          = sprintf(
			/* translators: 1: fecha, 2: proyecto, 3: código, 4: otorgante, 5: monto del Fondo, 6: aporte pecuniario, 7: aporte no pecuniario, 8: inicio, 9: término, 10: plazo transcurrido. */
			__( 'Al %1$s, el proyecto %2$s (código %3$s) cuenta con un convenio de %5$s del Fondo (otorgante: %4$s), más %6$s de aporte pecuniario y %7$s de aporte no pecuniario de la universidad, con plazo del %8$s al %9$s (%10$s transcurrido).', 'gestion-de-proyectos' ),
			BoardMetrics::date( (string) $status['today'] ),
			(string) $project['name'],
			(string) $project['code'],
			'' !== (string) $a['funder'] ? (string) $a['funder'] : __( 'sin registrar', 'gestion-de-proyectos' ),
			BoardMetrics::money( (float) $a['fund_amount'] ),
			BoardMetrics::money( (float) $a['cash_amount'] ),
			BoardMetrics::money( (float) $a['inkind_amount'] ),
			BoardMetrics::date( (string) $a['start_date'] ),
			BoardMetrics::date( (string) $a['end_date'] ),
			$elapsed ? BoardMetrics::pct_label( (float) $elapsed['pct'] ) : '—'
		);

		$out[] = sprintf(
			/* translators: 1: transferido, 2: porcentaje, 3: cuotas recibidas, 4: cuotas totales, 5: pagado, 6: comprometido, 7: devengado, 8: saldo, 9: rendido, 10: aprobado, 11: observado. */
			__( 'Se han recibido %1$s del Fondo (%2$s del convenio; %3$d de %4$d cuotas). Con cargo a ese monto se han pagado %5$s y hay compromisos por pagar de %6$s (incluidos %7$s devengados), de modo que el saldo de caja es %8$s. Se han rendido %9$s, de los cuales %10$s están aprobados y %11$s observados.', 'gestion-de-proyectos' ),
			BoardMetrics::money( (float) $fund['received'] ),
			BoardMetrics::pct_label( BoardMetrics::pct( (float) $fund['received'], (float) $fund['total'] ) ),
			$received_count,
			count( (array) $status['installments'] ),
			BoardMetrics::money( (float) $fund['paid'] ),
			BoardMetrics::money( (float) $fund['committed'] ),
			BoardMetrics::money( (float) $fund['accrued'] ),
			BoardMetrics::money( (float) $fund['balance'] ),
			BoardMetrics::money( (float) $fund['rendered'] ),
			BoardMetrics::money( (float) $fund['approved'] ),
			BoardMetrics::money( (float) $fund['observed'] )
		);

		$next = $status['next_installment'];
		if ( is_array( $next ) ) {
			$text = sprintf(
				/* translators: 1: número de la cuota, 2: monto, 3: monto por pagar, 4: monto por rendir, 5: monto de la garantía. */
				__( 'Para solicitar la cuota %1$d (%2$s) faltan %3$s por pagar y %4$s por rendir de lo recibido; la alternativa a la rendición total es una garantía por %5$s.', 'gestion-de-proyectos' ),
				(int) $next['number'],
				BoardMetrics::money( (float) $next['amount'] ),
				BoardMetrics::money( (float) $next['gaps']['pay_gap'] ),
				BoardMetrics::money( (float) $next['gaps']['render_gap'] ),
				BoardMetrics::money( (float) $next['gaps']['guarantee'] )
			);
			if ( ! empty( $next['latest_month'] ) ) {
				/* translators: 1: fecha objetivo, 2: mes, 3: fecha para rendir. */
				$text .= ' ' . sprintf( __( 'Con la fecha objetivo del %1$s, el último mes de pago útil es %2$s, con rendición a más tardar el %3$s.', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $next['target_date'] ), BoardMetrics::month_label( (string) $next['latest_month'] ), BoardMetrics::date( (string) $next['latest_render_due'] ) );
			}
			$pending = array();
			foreach ( (array) $next['conditions'] as $c ) {
				if ( true !== $c['ok'] ) {
					$pending[] = mb_strtolower( (string) $c['label'] );
				}
			}
			if ( $pending ) {
				/* translators: lista de condiciones. */
				$text .= ' ' . sprintf( __( 'Condiciones pendientes o sin registro: %s.', 'gestion-de-proyectos' ), implode( '; ', $pending ) );
			}
			$out[] = $text;
		}

		$text = sprintf(
			/* translators: 1: enterado, 2: total, 3: pagado. */
			__( 'Del aporte pecuniario se han enterado %1$s de %2$s y se han pagado %3$s.', 'gestion-de-proyectos' ),
			BoardMetrics::money( (float) $cash['received'] ),
			BoardMetrics::money( (float) $cash['total'] ),
			BoardMetrics::money( (float) $cash['paid'] )
		);
		if ( is_array( $next ) && (float) $next['cash_amount'] > 0 ) {
			/* translators: 1: número de la cuota, 2: monto. */
			$text .= ' ' . sprintf( __( 'Antes de la cuota %1$d corresponde enterar %2$s.', 'gestion-de-proyectos' ), (int) $next['number'], BoardMetrics::money( (float) $next['cash_amount'] ) );
		}
		$out[] = $text;

		$parts = array();
		foreach ( (array) $status['items'] as $i ) {
			$f = $i['sources']['fondo'];
			if ( (float) $f['assigned'] <= 0 ) {
				continue;
			}
			$parts[] = sprintf(
				/* translators: 1: ítem, 2: pagado, 3: comprometido, 4: disponible, 5: asignado. */
				__( '%1$s, pagado %2$s y comprometido %3$s, con %4$s disponibles de %5$s', 'gestion-de-proyectos' ),
				(string) $i['label'],
				BoardMetrics::money( (float) $f['paid'] ),
				BoardMetrics::money( (float) $f['committed'] ),
				BoardMetrics::money( (float) $f['available'] ),
				BoardMetrics::money( (float) $f['assigned'] )
			);
		}
		if ( $parts ) {
			/* translators: lista de ítems. */
			$out[] = sprintf( __( 'Por ítem, con cargo al Fondo: %s.', 'gestion-de-proyectos' ), implode( '; ', $parts ) );
		}

		$alerts = array();
		foreach ( (array) $data['alerts'] as $al ) {
			if ( 'info' !== $al['severity'] ) {
				$alerts[] = (string) $al['text'];
			}
		}
		if ( $alerts ) {
			/* translators: lista de alertas. */
			$out[] = sprintf( __( 'Asuntos que requieren atención: %s', 'gestion-de-proyectos' ), implode( ' ', $alerts ) );
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Informe del estado financiero, para imprimir o guardar en PDF.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return void
	 */
	public static function render( array $data ): void {
		$status  = $data['status'];
		$profile = $data['profile'];
		$project = $data['project'];
		$items   = (array) $data['items_labels'];
		echo '<header class="gdp-fin-report__head"><h3 class="gdp-fin-h">' . esc_html__( 'Informe del estado financiero', 'gestion-de-proyectos' ) . '</h3><p>' . esc_html( sprintf( /* translators: 1: código, 2: proyecto, 3: fecha. */ __( '%1$s · %2$s · al %3$s', 'gestion-de-proyectos' ), (string) $project['code'], (string) $project['name'], BoardMetrics::date( (string) $status['today'] ) ) ) . '</p></header>';

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '1. Fuentes de financiamiento', 'gestion-de-proyectos' ) . '</h4>';
		$rows = array();
		foreach ( $status['sources'] as $s ) {
			$rows[] = array( (string) $s['label'], BoardMetrics::money( (float) $s['total'] ), BoardMetrics::money( (float) $s['received'] ), BoardMetrics::money( (float) $s['paid'] ), BoardMetrics::money( (float) $s['committed'] ), BoardMetrics::money( (float) $s['rendered'] ), BoardMetrics::money( (float) $s['approved'] ), BoardMetrics::money( (float) $s['balance'] ) );
		}
		$rows[] = array( __( 'Aporte no pecuniario', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $status['agreement']['inkind_amount'] ), '—', '—', '—', '—', '—', '—' );
		self::table( array( __( 'Fuente', 'gestion-de-proyectos' ), __( 'Convenio', 'gestion-de-proyectos' ), __( 'Recibido', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Comprometido', 'gestion-de-proyectos' ), __( 'Rendido', 'gestion-de-proyectos' ), __( 'Aprobado', 'gestion-de-proyectos' ), __( 'Saldo de caja', 'gestion-de-proyectos' ) ), $rows );

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '2. Cuotas del Fondo', 'gestion-de-proyectos' ) . '</h4>';
		$rows = array();
		foreach ( (array) $status['installments'] as $i ) {
			$rows[] = array(
				sprintf( /* translators: número. */ __( 'Cuota %d', 'gestion-de-proyectos' ), (int) $i['number'] ),
				BoardMetrics::money( (float) $i['amount'] ),
				BoardMetrics::date( (string) $i['window_from'] ) . ' a ' . BoardMetrics::date( (string) $i['window_to'] ),
				$i['is_received'] ? sprintf( /* translators: fecha. */ __( 'recibida el %s', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $i['received_on'] ) ) : __( 'por recibir', 'gestion-de-proyectos' ),
				BoardMetrics::money( (float) $i['paid'] ),
				BoardMetrics::money( (float) $i['rendered'] ),
				BoardMetrics::money( (float) $i['approved'] ),
				BoardMetrics::money( (float) $i['cash_amount'] ) . ' · ' . ( $i['cash_received_at'] ? __( 'acreditado', 'gestion-de-proyectos' ) : __( 'por acreditar', 'gestion-de-proyectos' ) ),
			);
		}
		self::table( array( __( 'Cuota', 'gestion-de-proyectos' ), __( 'Monto', 'gestion-de-proyectos' ), __( 'Ventana', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Rendido', 'gestion-de-proyectos' ), __( 'Aprobado', 'gestion-de-proyectos' ), __( 'Aporte pecuniario', 'gestion-de-proyectos' ) ), $rows );

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '3. Ejecución por ítem con cargo al Fondo', 'gestion-de-proyectos' ) . '</h4>';
		$rows = array();
		foreach ( (array) $status['items'] as $i ) {
			$f      = $i['sources']['fondo'];
			$rows[] = array( (string) $i['label'], BoardMetrics::money( (float) $f['assigned'] ), BoardMetrics::money( (float) $f['paid'] ), BoardMetrics::money( (float) $f['committed'] ), BoardMetrics::money( (float) $f['available'] ), BoardMetrics::pct_label( BoardMetrics::pct( (float) $f['paid'] + (float) $f['committed'], (float) $f['assigned'] ) ) );
		}
		self::table( array( __( 'Ítem', 'gestion-de-proyectos' ), __( 'Asignado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Comprometido', 'gestion-de-proyectos' ), __( 'Disponible', 'gestion-de-proyectos' ), __( 'Usado', 'gestion-de-proyectos' ) ), $rows );

		$next = $status['next_installment'];
		if ( is_array( $next ) ) {
			/* translators: número de la cuota. */
			echo '<h4 class="gdp-fin-h4">' . esc_html( sprintf( __( '4. Cuota %d: brechas y condiciones', 'gestion-de-proyectos' ), (int) $next['number'] ) ) . '</h4>';
			self::table(
				array( __( 'Concepto', 'gestion-de-proyectos' ), __( 'Monto o fecha', 'gestion-de-proyectos' ) ),
				array(
					array( __( 'Falta pagar', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['gaps']['pay_gap'] ) ),
					array( __( 'Falta rendir', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['gaps']['render_gap'] ) ),
					array( __( 'Falta aprobar', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['gaps']['approve_gap'] ) ),
					array( __( 'Garantía alternativa', 'gestion-de-proyectos' ), BoardMetrics::money( (float) $next['gaps']['guarantee'] ) ),
					array( __( 'Fecha objetivo del giro', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $next['target_date'] ) ),
					array( __( 'Último mes de pago útil', 'gestion-de-proyectos' ), ! empty( $next['latest_month'] ) ? BoardMetrics::month_label( (string) $next['latest_month'] ) : __( 'ninguno', 'gestion-de-proyectos' ) ),
				)
			);
			$rows = array();
			foreach ( (array) $next['conditions'] as $c ) {
				$rows[] = array( (string) $c['key'] . ' ' . BoardMetrics::display_text( (string) $c['label'] ), null === $c['ok'] ? __( 'sin registro', 'gestion-de-proyectos' ) : ( $c['ok'] ? __( 'cumple', 'gestion-de-proyectos' ) : __( 'no cumple', 'gestion-de-proyectos' ) ), BoardMetrics::display_text( (string) $c['detail'] ) );
			}
			self::table( array( __( 'Condición', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Detalle', 'gestion-de-proyectos' ) ), $rows );
		}

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '5. Compromisos por pagar', 'gestion-de-proyectos' ) . '</h4>';
		$rows  = array();
		$total = 0.0;
		foreach ( (array) $data['payments'] as $p ) {
			if ( in_array( $p['status'], PaymentRepository::COMMITTED, true ) ) {
				$total += (float) $p['amount'];
				$rows[] = array( (string) $p['code'], BoardData::supplier_name( $data, (int) $p['supplier_id'] ), (string) $p['description'], (string) ( $items[ $p['item_slug'] ] ?? $p['item_slug'] ), BoardMetrics::money( (float) $p['amount'] ), (string) ( $profile->payment_statuses()[ $p['status'] ] ?? $p['status'] ) );
			}
		}
		if ( $rows ) {
			$rows[] = array( __( 'Total', 'gestion-de-proyectos' ), '', '', '', BoardMetrics::money( $total ), '' );
			self::table( array( __( 'Pago', 'gestion-de-proyectos' ), __( 'Proveedor', 'gestion-de-proyectos' ), __( 'Descripción', 'gestion-de-proyectos' ), __( 'Ítem', 'gestion-de-proyectos' ), __( 'Monto', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ) ), $rows );
		} else {
			echo '<p>' . esc_html__( 'Sin compromisos por pagar.', 'gestion-de-proyectos' ) . '</p>';
		}

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '6. Pagos observados', 'gestion-de-proyectos' ) . '</h4>';
		$rows = array();
		foreach ( (array) $data['payments'] as $p ) {
			if ( in_array( $p['status'], array( 'observado', 'corregido', 'rechazado' ), true ) ) {
				$rows[] = array( (string) $p['code'], (string) $p['description'], BoardMetrics::money( (float) $p['amount'] ), (string) ( $profile->payment_statuses()[ $p['status'] ] ?? $p['status'] ), (string) $p['observation'] );
			}
		}
		if ( $rows ) {
			self::table( array( __( 'Pago', 'gestion-de-proyectos' ), __( 'Descripción', 'gestion-de-proyectos' ), __( 'Monto', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Observación', 'gestion-de-proyectos' ) ), $rows );
		} else {
			echo '<p>' . esc_html__( 'Sin pagos observados.', 'gestion-de-proyectos' ) . '</p>';
		}

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '7. Rendiciones mensuales', 'gestion-de-proyectos' ) . '</h4>';
		$rows = array();
		foreach ( array_reverse( (array) $data['cells'] ) as $c ) {
			$rows[] = array( BoardMetrics::month_label( (string) $c['period'] ), (string) $c['label'], BoardMetrics::money( (float) $c['amount'] ), BoardMetrics::date( (string) $c['platform_due'] ) );
		}
		self::table( array( __( 'Mes', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Pagado en el mes', 'gestion-de-proyectos' ), __( 'Plazo en SISREC', 'gestion-de-proyectos' ) ), $rows );

		echo '<h4 class="gdp-fin-h4">' . esc_html__( '8. Alertas y próximas acciones', 'gestion-de-proyectos' ) . '</h4><ul class="gdp-fin-list">';
		foreach ( (array) $data['alerts'] as $al ) {
			echo '<li>' . esc_html( (string) $al['text'] ) . '</li>';
		}
		foreach ( array_slice( (array) $data['actions'], 0, 8 ) as $ac ) {
			echo '<li>' . esc_html( (string) $ac['title'] . ( ! empty( $ac['due'] ) ? ' (' . BoardMetrics::date( (string) $ac['due'] ) . ')' : '' ) ) . '</li>';
		}
		echo '</ul>';
		echo '<p class="gdp-fin-muted gdp-fin-report__foot">' . esc_html( sprintf( /* translators: fecha. */ __( 'Cifras calculadas el %s con los registros del módulo de finanzas. El módulo no lee SISREC: el estado de cada rendición es el que se declaró en el plugin.', 'gestion-de-proyectos' ), BoardMetrics::date( (string) $status['today'] ) ) ) . '</p>';
	}

	/**
	 * Pagos para CSV (montos sin formato, fechas AAAA-MM-DD).
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<int,array<int,string>>
	 */
	public static function payments_csv( array $data ): array {
		$profile = $data['profile'];
		$items   = (array) $data['items_labels'];
		$rows    = array( array( 'codigo', 'fuente', 'item', 'proveedor', 'descripcion', 'compromiso', 'tipo_documento', 'numero_documento', 'fecha_documento', 'ejecutado', 'pagado', 'egreso', 'monto', 'cuota', 'estado', 'observacion' ) );
		foreach ( (array) $data['payments'] as $p ) {
			$rows[] = array( (string) $p['code'], (string) $p['source'], (string) ( $items[ $p['item_slug'] ] ?? $p['item_slug'] ), BoardData::supplier_name( $data, (int) $p['supplier_id'] ), (string) $p['description'], (string) $p['commitment'], (string) ( $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'] ), (string) $p['doc_number'], (string) $p['doc_date'], (string) $p['executed_at'], (string) $p['paid_at'], (string) $p['egress_number'], (string) (int) round( (float) $p['amount'] ), (string) ( $data['effective'][ $p['id'] ] ?? $p['installment_no'] ), (string) ( $profile->payment_statuses()[ $p['status'] ] ?? $p['status'] ), (string) $p['observation'] );
		}

		return $rows;
	}

	/**
	 * Ítems para CSV.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<int,array<int,string>>
	 */
	public static function items_csv( array $data ): array {
		$rows = array( array( 'item', 'fondo_asignado', 'fondo_pagado', 'fondo_comprometido', 'fondo_rechazado', 'fondo_disponible', 'pecuniario_asignado', 'pecuniario_pagado', 'pecuniario_disponible', 'no_pecuniario_asignado' ) );
		foreach ( (array) $data['status']['items'] as $i ) {
			$f      = $i['sources']['fondo'];
			$c      = $i['sources']['pecuniario'];
			$rows[] = array( (string) $i['label'], self::int( $f['assigned'] ), self::int( $f['paid'] ), self::int( $f['committed'] ), self::int( $f['rejected'] ), self::int( $f['available'] ), self::int( $c['assigned'] ), self::int( $c['paid'] ), self::int( $c['available'] ), self::int( $data['items_rows'][ $i['slug'] ]['assigned_inkind'] ?? 0 ) );
		}

		return $rows;
	}

	/**
	 * Cuotas para CSV.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<int,array<int,string>>
	 */
	public static function installments_csv( array $data ): array {
		$rows = array( array( 'cuota', 'monto', 'ventana_desde', 'ventana_hasta', 'informe', 'aporte_pecuniario', 'aporte_acreditado', 'solicitada', 'transferida', 'ingresada', 'comprobante', 'comprobante_enviado', 'estado_plataforma', 'pagado', 'rendido', 'aprobado' ) );
		foreach ( (array) $data['status']['installments'] as $i ) {
			$rows[] = array( (string) $i['number'], self::int( $i['amount'] ), (string) $i['window_from'], (string) $i['window_to'], (string) $i['report_no'], self::int( $i['cash_amount'] ), (string) $i['cash_received_at'], (string) $i['requested_at'], (string) $i['transferred_at'], (string) $i['received_at'], (string) $i['receipt_number'], (string) $i['receipt_sent_at'], (string) $i['platform_status'], self::int( $i['paid'] ), self::int( $i['rendered'] ), self::int( $i['approved'] ) );
		}

		return $rows;
	}

	/**
	 * Rendiciones (línea de tiempo) para CSV.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<int,array<int,string>>
	 */
	public static function renditions_csv( array $data ): array {
		$rows = array( array( 'mes', 'estado', 'tipo', 'pagado_en_el_mes', 'plazo_interno', 'plazo_plataforma', 'plazo_subsanacion' ) );
		foreach ( (array) $data['cells'] as $c ) {
			$rows[] = array( (string) $c['period'], (string) $c['label'], (string) $c['kind'], self::int( $c['amount'] ), (string) $c['internal_due'], (string) $c['platform_due'], (string) $c['fix_due'] );
		}

		return $rows;
	}

	/**
	 * Documentos que el módulo genera en el servidor, según los permisos: la
	 * ficha de giro, el expediente y la carta de gasto cero piden ver las
	 * finanzas (lo mismo que el tablero); la planilla, el ZIP y la programación
	 * piden además el permiso de exportar.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param BoardContext        $ctx  Contexto.
	 * @return array<int,array{label:string,url:string,new:bool}>
	 */
	public static function exports( array $data, BoardContext $ctx ): array {
		$pid    = (int) $data['project_id'];
		$status = $data['status'];
		$out    = array();
		$next   = $status['next_installment'];
		if ( is_array( $next ) ) {
			foreach ( (array) $status['installments'] as $i ) {
				if ( (int) $i['number'] === (int) $next['number'] ) {
					$out[] = array(
						/* translators: número de la cuota. */
						'label' => sprintf( __( 'Ficha de giro de la cuota %d (imprimible)', 'gestion-de-proyectos' ), (int) $next['number'] ),
						'url'   => FinancePage::export_url( $pid, 'installment_sheet', (int) $i['id'] ),
						'new'   => true,
					);
				}
			}
		}
		if ( $ctx->export && is_array( $status['cash_plan'] ) ) {
			$out[] = array(
				'label' => __( 'Programación de caja en el formato de la Dirección de Investigación (XLSX)', 'gestion-de-proyectos' ),
				'url'   => FinancePage::export_url( $pid, 'cash_plan', (int) $status['cash_plan']['plan']['id'] ),
				'new'   => false,
			);
		}
		foreach ( array_reverse( (array) $data['renditions'] ) as $r ) {
			$month = BoardMetrics::month_label( (string) $r['period'] );
			$out[] = array(
				/* translators: mes. */
				'label' => sprintf( __( 'Expediente de la rendición de %s', 'gestion-de-proyectos' ), $month ),
				'url'   => FinancePage::export_url( $pid, 'expedient', (int) $r['id'] ),
				'new'   => true,
			);
			if ( 'sin_movimiento' === $r['kind'] ) {
				$out[] = array(
					/* translators: mes. */
					'label' => sprintf( __( 'Carta y carátula de gasto cero de %s', 'gestion-de-proyectos' ), $month ),
					'url'   => FinancePage::export_url( $pid, 'zero_letter', (int) $r['id'] ),
					'new'   => true,
				);
			} elseif ( $ctx->export ) {
				$out[] = array(
					/* translators: mes. */
					'label' => sprintf( __( 'Planilla de carga masiva de %s', 'gestion-de-proyectos' ), $month ),
					'url'   => FinancePage::export_url( $pid, 'bulk_sheet', (int) $r['id'] ),
					'new'   => false,
				);
				$out[] = array(
					/* translators: mes. */
					'label' => sprintf( __( 'ZIP de respaldos de %s', 'gestion-de-proyectos' ), $month ),
					'url'   => FinancePage::export_url( $pid, 'bulk_zip', (int) $r['id'] ),
					'new'   => false,
				);
			}
		}

		return $out;
	}

	/**
	 * Tabla simple con escape.
	 *
	 * @param string[]                 $head Encabezados.
	 * @param array<int,array<string>> $rows Filas.
	 * @return void
	 */
	private static function table( array $head, array $rows ): void {
		echo '<div class="gdp-fin-scroll"><table class="gdp-table gdp-fin-table"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			foreach ( array_values( $r ) as $k => $cell ) {
				echo 0 === $k ? '<th scope="row">' . esc_html( (string) $cell ) . '</th>' : '<td>' . esc_html( (string) $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Monto entero como texto.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private static function int( $value ): string {
		return (string) (int) round( (float) $value );
	}
}
