<?php
/**
 * Hojas del libro Excel del estado financiero.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

use GDP\Core\Workbook;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte los datos del tablero de finanzas (BoardData::build, más los
 * eventos, la cartola, los hallazgos de cada pago y el registro de los
 * proveedores en la plataforma) en las hojas de un libro: resumen, alertas,
 * acciones, cuota siguiente, cuotas, ítems, pagos, pagos por estado,
 * proveedores, rendiciones mes a mes y registradas, estados y pasos, caja
 * mes a mes, programación de caja y sus controles, cartola, convenio,
 * modificaciones, garantías y reglas.
 *
 * Los montos, fechas y porcentajes se escriben como valores numéricos con su
 * formato; los totales, disponibles, acumulados, porcentajes y resúmenes son
 * fórmulas (con su valor ya calculado), de modo que el libro se recalcula si
 * alguien corrige una cifra y los totales siguen a los filtros. Todo es
 * aritmética sobre arreglos: se prueba sin WordPress.
 */
final class WorkbookSheets {

	/**
	 * Fila (en la numeración de la planilla) donde empieza el preámbulo de una hoja de tabla.
	 */
	public const PREAMBLE_ROW = 4;

	/**
	 * Arma las hojas.
	 *
	 * @param array<string,mixed> $data Datos (ver la descripción de la clase).
	 * @return array<int,array<string,mixed>>
	 */
	public static function build( array $data ): array {
		$ctx = self::context( $data );

		$payments = self::payments( $data, $ctx );
		$months   = self::renditions_by_month( $data, $ctx );
		$alerts   = self::alerts( $data, $ctx );

		return array(
			self::summary( $data, $ctx, $months['meta'], $alerts['meta'] ),
			$alerts['sheet'],
			self::actions( $data, $ctx ),
			self::next_installment( $data, $ctx ),
			self::installments( $data, $ctx ),
			self::items( $data, $ctx ),
			$payments['sheet'],
			self::by_status( $data, $ctx, $payments['meta'] ),
			self::suppliers( $data, $ctx, $payments['meta'] ),
			$months['sheet'],
			self::renditions( $data, $ctx, $payments['meta'] ),
			self::events( $data, $ctx ),
			self::cash( $data, $ctx ),
			self::plan( $data, $ctx ),
			self::plan_checks( $data, $ctx ),
			self::ledger( $data, $ctx ),
			self::agreement( $data, $ctx ),
			self::modifications( $data, $ctx ),
			self::guarantees( $data, $ctx ),
			self::rules( $data, $ctx ),
		);
	}

	/**
	 * Nombres de las hojas, en orden.
	 *
	 * @return array<string,string> Clave => nombre.
	 */
	public static function names(): array {
		return array(
			'summary'      => __( 'Resumen', 'gestion-de-proyectos' ),
			'alerts'       => __( 'Alertas', 'gestion-de-proyectos' ),
			'actions'      => __( 'Acciones', 'gestion-de-proyectos' ),
			'next'         => __( 'Cuota siguiente', 'gestion-de-proyectos' ),
			'installments' => __( 'Cuotas', 'gestion-de-proyectos' ),
			'items'        => __( 'Ítems', 'gestion-de-proyectos' ),
			'payments'     => __( 'Pagos', 'gestion-de-proyectos' ),
			'by_status'    => __( 'Pagos por estado', 'gestion-de-proyectos' ),
			'suppliers'    => __( 'Proveedores', 'gestion-de-proyectos' ),
			'months'       => __( 'Rendiciones mes a mes', 'gestion-de-proyectos' ),
			'renditions'   => __( 'Rendiciones registradas', 'gestion-de-proyectos' ),
			'events'       => __( 'Estados y pasos', 'gestion-de-proyectos' ),
			'cash'         => __( 'Caja mes a mes', 'gestion-de-proyectos' ),
			'plan'         => __( 'Programación de caja', 'gestion-de-proyectos' ),
			'checks'       => __( 'Controles de caja', 'gestion-de-proyectos' ),
			'ledger'       => __( 'Cartola', 'gestion-de-proyectos' ),
			'agreement'    => __( 'Convenio', 'gestion-de-proyectos' ),
			'mods'         => __( 'Modificaciones', 'gestion-de-proyectos' ),
			'guarantees'   => __( 'Garantías', 'gestion-de-proyectos' ),
			'rules'        => __( 'Reglas', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Qué contiene cada hoja (para el índice del resumen).
	 *
	 * @return array<string,string>
	 */
	public static function descriptions(): array {
		return array(
			'alerts'       => __( 'Situaciones que exigen atención, de la más grave a la más leve.', 'gestion-de-proyectos' ),
			'actions'      => __( 'Tareas pendientes del asistente, por prioridad y vencimiento.', 'gestion-de-proyectos' ),
			'next'         => __( 'Brechas de pago, rendición y aprobación, condiciones de giro y compromisos que cierran la brecha.', 'gestion-de-proyectos' ),
			'installments' => __( 'Programa de desembolso: montos, ventanas, fechas, comprobantes y avance de cada cuota.', 'gestion-de-proyectos' ),
			'items'        => __( 'Asignado, pagado, comprometido, rechazado y disponible por ítem y fuente; topes y respaldos.', 'gestion-de-proyectos' ),
			'payments'     => __( 'Cada pago con su documento, egreso, cuota imputada, estado, rendición, respaldos y hallazgos.', 'gestion-de-proyectos' ),
			'by_status'    => __( 'Montos y cantidad de pagos por estado y fuente.', 'gestion-de-proyectos' ),
			'suppliers'    => __( 'Proveedores con pagos, su registro en SISREC y lo pagado y por pagar.', 'gestion-de-proyectos' ),
			'months'       => __( 'Cada mes del plazo con su gasto, su rendición, sus plazos y su situación.', 'gestion-de-proyectos' ),
			'renditions'   => __( 'Rendiciones registradas con sus fechas, pagos y montos.', 'gestion-de-proyectos' ),
			'events'       => __( 'Estados declarados y pasos cumplidos, con fecha, nota y autor.', 'gestion-de-proyectos' ),
			'cash'         => __( 'Transferido, programado y pagado, mes a mes y acumulado, con la caja real al cierre de cada mes.', 'gestion-de-proyectos' ),
			'plan'         => __( 'Programación de caja vigente frente a lo real.', 'gestion-de-proyectos' ),
			'checks'       => __( 'Los seis controles de la programación de caja.', 'gestion-de-proyectos' ),
			'ledger'       => __( 'Movimientos de la cartola del centro de costo y su conciliación.', 'gestion-de-proyectos' ),
			'agreement'    => __( 'Datos del convenio y financiamiento por fuente.', 'gestion-de-proyectos' ),
			'mods'         => __( 'Modificaciones del convenio y su estado.', 'gestion-de-proyectos' ),
			'guarantees'   => __( 'Garantías con su monto y vigencia.', 'gestion-de-proyectos' ),
			'rules'        => __( 'Reglas vigentes con su valor y su fuente.', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas y datos comunes.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>
	 */
	private static function context( array $data ): array {
		$profile = $data['profile'];
		$project = (array) $data['project'];
		$line    = trim( (string) ( $project['code'] ?? '' ) . ' · ' . (string) ( $project['name'] ?? '' ), ' ·' );
		$rstatus = array();
		foreach ( $profile->rendition_statuses() as $slug => $s ) {
			$rstatus[ $slug ] = is_array( $s ) ? $s : array( 'label' => (string) $s );
		}

		return array(
			'line'      => $line,
			'subtitle'  => sprintf( /* translators: 1: proyecto, 2: fecha. */ __( '%1$s · estado al %2$s · montos en pesos', 'gestion-de-proyectos' ), $line, BoardMetrics::date( (string) $data['today'] ) ),
			'today'     => (string) $data['today'],
			'current'   => substr( (string) $data['today'], 0, 7 ),
			'names'     => self::names(),
			'sources'   => $profile->sources(),
			'pstatus'   => $profile->payment_statuses(),
			'rstatus'   => $rstatus,
			'doc_types' => $profile->doc_types(),
			'supports'  => $profile->support_kinds(),
			'ptypes'    => $profile->platform_types(),
			'tstatus'   => $profile->transfer_statuses(),
			'guides'    => $profile->guides(),
			'required'  => $profile->support_requirements(),
			'items'     => (array) ( $data['items_labels'] ?? array() ),
			'labels'    => (array) ( $data['labels'] ?? array() ),
		);
	}

	// ------------------------------------------------------------------ Resumen

	/**
	 * Hoja de resumen: fuentes, plazo, cuota siguiente, rendiciones, alertas, índice y notas.
	 *
	 * @param array<string,mixed> $data   Datos.
	 * @param array<string,mixed> $ctx    Contexto.
	 * @param array<string,mixed> $months Ubicación de la columna de vencidas en Rendiciones mes a mes.
	 * @param array<string,mixed> $alerts Ubicación de la columna de gravedad en Alertas.
	 * @return array<string,mixed>
	 */
	private static function summary( array $data, array $ctx, array $months, array $alerts ): array {
		$status = (array) $data['status'];
		$a      = (array) $status['agreement'];
		$names  = $ctx['names'];
		$rows   = array();
		$links  = array();

		$rows[] = array(
			array(
				'v' => __( 'Estado financiero del proyecto', 'gestion-de-proyectos' ),
				's' => 'title',
			),
		);
		$rows[] = array(
			array(
				'v' => $ctx['line'],
				's' => 'bold',
			),
		);
		$rows[] = array(
			array(
				'v' => sprintf( /* translators: fecha. */ __( 'Estado al %s. Montos en pesos chilenos; fechas en día, mes y año.', 'gestion-de-proyectos' ), BoardMetrics::date( $ctx['today'] ) ),
				's' => 'subtitle',
			),
		);
		$rows[] = array();

		// Fuentes de financiamiento.
		$rows[]       = array(
			array(
				'v' => __( 'Fuentes de financiamiento', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$rows[]       = array_map(
			static fn( string $h ): array => array(
				'v' => $h,
				's' => 'header',
			),
			array( __( 'Fuente', 'gestion-de-proyectos' ), __( 'Convenio', 'gestion-de-proyectos' ), __( 'Recibido o enterado', 'gestion-de-proyectos' ), __( 'Pagado', 'gestion-de-proyectos' ), __( 'Comprometido por pagar', 'gestion-de-proyectos' ), __( 'Rendido', 'gestion-de-proyectos' ), __( 'Aprobado', 'gestion-de-proyectos' ), __( 'Observado', 'gestion-de-proyectos' ), __( 'Saldo de caja', 'gestion-de-proyectos' ), __( 'Por recibir', 'gestion-de-proyectos' ), __( 'Pagado sobre el convenio', 'gestion-de-proyectos' ), __( 'Recibido sobre el convenio', 'gestion-de-proyectos' ) )
		);
		$first_source = count( $rows ) + 1;
		foreach ( array( 'fondo', 'pecuniario' ) as $src ) {
			$s      = (array) ( $status['sources'][ $src ] ?? array() );
			$r      = count( $rows ) + 1;
			$total  = (float) ( $s['total'] ?? 0 );
			$rows[] = array(
				array(
					'v' => (string) ( $s['label'] ?? $src ),
					's' => 'bold',
				),
				self::money( $total ),
				self::money( (float) ( $s['received'] ?? 0 ) ),
				self::money( (float) ( $s['paid'] ?? 0 ) ),
				self::money( (float) ( $s['committed'] ?? 0 ) ),
				self::money( (float) ( $s['rendered'] ?? 0 ) ),
				self::money( (float) ( $s['approved'] ?? 0 ) ),
				self::money( (float) ( $s['observed'] ?? 0 ) ),
				self::money( (float) ( $s['balance'] ?? 0 ) ),
				self::money( (float) ( $s['to_receive'] ?? 0 ) ),
				array(
					'v' => self::ratio( (float) ( $s['paid'] ?? 0 ), $total ),
					'f' => "IF(B{$r}>0,D{$r}/B{$r},0)",
					's' => 'pct',
				),
				array(
					'v' => self::ratio( (float) ( $s['received'] ?? 0 ), $total ),
					'f' => "IF(B{$r}>0,C{$r}/B{$r},0)",
					's' => 'pct',
				),
			);
		}
		$rows[]      = array(
			array(
				'v' => __( 'Aporte no pecuniario', 'gestion-de-proyectos' ),
				's' => 'bold',
			),
			self::money( (float) ( $a['inkind_amount'] ?? 0 ) ),
		);
		$last_source = count( $rows );
		$fund_row    = $first_source;
		$rows[]      = array(
			array(
				'v' => __( 'Costo total del proyecto', 'gestion-de-proyectos' ),
				's' => 'total',
			),
			array(
				'v' => (float) ( $a['fund_amount'] ?? 0 ) + (float) ( $a['cash_amount'] ?? 0 ) + (float) ( $a['inkind_amount'] ?? 0 ),
				'f' => "SUM(B{$first_source}:B{$last_source})",
				's' => 'total_money',
			),
		);
		$rows[]      = array();

		// Plazo y ejecución.
		$elapsed = $data['elapsed'] ?? null;
		$fund    = (array) ( $status['sources']['fondo'] ?? array() );
		$total   = (float) ( $fund['total'] ?? 0 );
		$rows[]  = array(
			array(
				'v' => __( 'Plazo y ejecución del Fondo', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$rows[]  = self::pair( __( 'Inicio del plazo de ejecución', 'gestion-de-proyectos' ), self::date( (string) ( $a['start_date'] ?? '' ) ) );
		$rows[]  = self::pair( __( 'Término del plazo de ejecución', 'gestion-de-proyectos' ), self::date( (string) ( $a['end_date'] ?? '' ) ) );
		$rows[]  = self::pair(
			__( 'Plazo transcurrido', 'gestion-de-proyectos' ),
			is_array( $elapsed ) ? array(
				'v' => (float) $elapsed['pct'] / 100,
				's' => 'pct',
			) : ''
		);
		$rows[]  = self::pair( __( 'Mes del convenio', 'gestion-de-proyectos' ), is_array( $elapsed ) ? sprintf( /* translators: 1: mes, 2: meses. */ __( '%1$d de %2$d', 'gestion-de-proyectos' ), (int) $elapsed['month'], (int) $elapsed['months'] ) : '' );
		$rows[]  = self::pair(
			__( 'Fondo pagado sobre el convenio', 'gestion-de-proyectos' ),
			array(
				'v' => self::ratio( (float) ( $fund['paid'] ?? 0 ), $total ),
				'f' => "IF(B{$fund_row}>0,D{$fund_row}/B{$fund_row},0)",
				's' => 'pct',
			)
		);
		$rows[]  = self::pair(
			__( 'Fondo pagado o comprometido sobre el convenio', 'gestion-de-proyectos' ),
			array(
				'v' => self::ratio( (float) ( $fund['paid'] ?? 0 ) + (float) ( $fund['committed'] ?? 0 ), $total ),
				'f' => "IF(B{$fund_row}>0,(D{$fund_row}+E{$fund_row})/B{$fund_row},0)",
				's' => 'pct',
			)
		);
		$rows[]  = self::pair(
			__( 'Fondo recibido sobre el convenio', 'gestion-de-proyectos' ),
			array(
				'v' => self::ratio( (float) ( $fund['received'] ?? 0 ), $total ),
				'f' => "IF(B{$fund_row}>0,C{$fund_row}/B{$fund_row},0)",
				's' => 'pct',
			)
		);
		$rows[]  = array();

		// Cuota siguiente.
		$next   = $status['next_installment'] ?? null;
		$rows[] = array(
			array(
				'v' => __( 'Cuota siguiente', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		if ( is_array( $next ) ) {
			$gaps   = (array) $next['gaps'];
			$rows[] = self::pair( __( 'Cuota', 'gestion-de-proyectos' ), sprintf( /* translators: número. */ __( 'N.º %d', 'gestion-de-proyectos' ), (int) $next['number'] ) );
			$rows[] = self::pair( __( 'Monto', 'gestion-de-proyectos' ), self::money( (float) $next['amount'] ) );
			$rows[] = self::pair( __( 'Fecha objetivo del giro', 'gestion-de-proyectos' ), self::date( (string) $next['target_date'] ) );
			$rows[] = self::pair( __( 'Falta por pagar', 'gestion-de-proyectos' ), self::money( (float) $gaps['pay_gap'] ) );
			$rows[] = self::pair( __( 'Falta por rendir', 'gestion-de-proyectos' ), self::money( (float) $gaps['render_gap'] ) );
			$rows[] = self::pair( __( 'Falta por aprobar', 'gestion-de-proyectos' ), self::money( (float) $gaps['approve_gap'] ) );
			$rows[] = self::pair( __( 'Último mes de pago útil', 'gestion-de-proyectos' ), ! empty( $next['latest_month'] ) ? BoardMetrics::month_label( (string) $next['latest_month'] ) : __( 'ninguno alcanza la fecha objetivo', 'gestion-de-proyectos' ) );
		} else {
			$rows[] = self::pair( __( 'Cuota', 'gestion-de-proyectos' ), __( 'No quedan cuotas por recibir o no están registradas.', 'gestion-de-proyectos' ) );
		}
		$r       = count( $rows ) + 1;
		$rows[]  = array(
			array(
				'v' => sprintf( /* translators: nombre de la hoja. */ __( 'Detalle en la hoja %s', 'gestion-de-proyectos' ), $names['next'] ),
				's' => 'link',
			),
		);
		$links[] = self::link( $r, $names['next'] );
		$rows[]  = array();

		// Rendiciones y alertas.
		$rows[] = array(
			array(
				'v' => __( 'Rendiciones y alertas', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$rows[] = self::pair(
			__( 'Rendiciones vencidas sin estado declarado', 'gestion-de-proyectos' ),
			array(
				'v' => (int) $months['overdue'],
				'f' => sprintf( 'COUNTIF(%s!$%s$%d:$%s$%d,"%s")', Workbook::quote_sheet( $names['months'] ), $months['col'], $months['first'], $months['col'], $months['last'], self::text_criterion( __( 'Sí', 'gestion-de-proyectos' ) ) ),
				's' => 'code',
			)
		);
		$rows[] = self::pair(
			__( 'Rendiciones registradas', 'gestion-de-proyectos' ),
			array(
				'v' => count( (array) $data['renditions'] ),
				's' => 'code',
			)
		);
		foreach ( (array) $alerts['levels'] as $label => $count ) {
			$rows[] = self::pair(
				/* translators: gravedad de la alerta. */
				sprintf( __( 'Alertas: %s', 'gestion-de-proyectos' ), mb_strtolower( (string) $label ) ),
				array(
					'v' => (int) $count,
					'f' => sprintf( 'COUNTIF(%s!$A$%d:$A$%d,"%s")', Workbook::quote_sheet( $names['alerts'] ), $alerts['first'], $alerts['last'], self::text_criterion( (string) $label ) ),
					's' => 'code',
				)
			);
		}
		$rows[] = array();

		// Índice.
		$rows[] = array(
			array(
				'v' => __( 'Contenido del libro', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		foreach ( self::descriptions() as $key => $text ) {
			$r       = count( $rows ) + 1;
			$rows[]  = array(
				array(
					'v' => $names[ $key ],
					's' => 'link',
				),
				array(
					'v' => $text,
					's' => 'note',
				),
			);
			$links[] = self::link( $r, $names[ $key ] );
		}
		$rows[] = array();

		// Notas.
		$rows[] = array(
			array(
				'v' => __( 'Notas', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$rows[] = array(
			array(
				'v' => __( 'Cifras del módulo de finanzas a la fecha indicada. Los estados de las rendiciones son los declarados en el módulo, que no lee SISREC:', 'gestion-de-proyectos' ),
				's' => 'note',
			),
		);
		$rows[] = array(
			array(
				'v' => __( 'una rendición presentada en la plataforma y no declarada en el módulo figura como vencida sin estado declarado.', 'gestion-de-proyectos' ),
				's' => 'note',
			),
		);
		$rows[] = array(
			array(
				'v' => __( 'Los totales, disponibles, acumulados y porcentajes son fórmulas: se recalculan si se corrige una cifra, y los totales de las tablas siguen a los filtros.', 'gestion-de-proyectos' ),
				's' => 'note',
			),
		);

		return array(
			'name'      => $names['summary'],
			'columns'   => array_merge( array( array( 'width' => 46 ) ), array_fill( 0, 9, array( 'width' => 17 ) ), array( array( 'width' => 14 ), array( 'width' => 14 ) ) ),
			'rows'      => $rows,
			'links'     => $links,
			'gridlines' => false,
		);
	}

	// ------------------------------------------------------------------ Alertas y acciones

	/**
	 * Alertas, con vínculo a la hoja del detalle.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array{sheet:array<string,mixed>,meta:array<string,mixed>}
	 */
	private static function alerts( array $data, array $ctx ): array {
		$names  = $ctx['names'];
		$levels = array(
			'critical' => array( __( 'Atención inmediata', 'gestion-de-proyectos' ), 'bad' ),
			'warning'  => array( __( 'Revisar', 'gestion-de-proyectos' ), 'warn' ),
			'info'     => array( __( 'Para saber', 'gestion-de-proyectos' ), '' ),
		);
		$tabs   = array(
			'rendiciones' => 'months',
			'cuotas'      => 'next',
			'items'       => 'items',
			'pagos'       => 'payments',
			'caja'        => 'cash',
			'convenio'    => 'agreement',
			'resumen'     => 'summary',
		);
		$rows   = array();
		$count  = array();
		$links  = array();
		$header = self::header_index( 0 );
		foreach ( (array) $data['alerts'] as $al ) {
			$level              = $levels[ $al['severity'] ] ?? $levels['info'];
			$sheet              = $names[ $tabs[ $al['tab'] ] ?? 'summary' ];
			$links[]            = self::link( $header + 2 + count( $rows ), $sheet, 'C' );
			$rows[]             = array(
				array(
					'v' => $level[0],
					's' => $level[1],
				),
				array(
					'v' => (string) $al['text'],
					's' => 'wrap',
				),
				array(
					'v' => $sheet,
					's' => 'link',
				),
			);
			$count[ $level[0] ] = ( $count[ $level[0] ] ?? 0 ) + 1;
		}
		$levels_count = array();
		foreach ( $levels as $l ) {
			$levels_count[ $l[0] ] = $count[ $l[0] ] ?? 0;
		}
		$sheet          = self::table(
			$ctx,
			$names['alerts'],
			__( 'Alertas', 'gestion-de-proyectos' ),
			array(
				array( __( 'Gravedad', 'gestion-de-proyectos' ), 18, '' ),
				array( __( 'Alerta', 'gestion-de-proyectos' ), 110, 'wrap' ),
				array( __( 'Hoja con el detalle', 'gestion-de-proyectos' ), 24, '' ),
			),
			$rows,
			array( 'empty' => __( 'Sin alertas.', 'gestion-de-proyectos' ) )
		);
		$sheet['links'] = $links;

		return array(
			'sheet' => $sheet,
			'meta'  => array(
				'levels' => $levels_count,
				'first'  => $header + 2,
				'last'   => $header + 1 + max( 1, count( $rows ) ),
			),
		);
	}

	/**
	 * Acciones del asistente.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function actions( array $data, array $ctx ): array {
		$priority = array(
			'alta'  => array( __( 'Alta', 'gestion-de-proyectos' ), 'bad' ),
			'media' => array( __( 'Media', 'gestion-de-proyectos' ), 'warn' ),
			'baja'  => array( __( 'Baja', 'gestion-de-proyectos' ), '' ),
		);
		$rows     = array();
		foreach ( (array) $data['actions'] as $a ) {
			$p      = $priority[ $a['severity'] ] ?? array( (string) $a['severity'], '' );
			$guide  = (string) ( $a['guide'] ?? '' );
			$rows[] = array(
				array(
					'v' => $p[0],
					's' => $p[1],
				),
				BoardMetrics::display_text( (string) $a['title'] ),
				self::date( (string) ( $a['due'] ?? '' ) ),
				! empty( $a['due'] ) ? (int) $a['days_left'] : '',
				BoardMetrics::display_text( (string) $a['detail'] ),
				'' !== $guide ? (string) ( $ctx['guides'][ $guide ]['label'] ?? $guide ) : '',
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['actions'],
			__( 'Acciones pendientes', 'gestion-de-proyectos' ),
			array(
				array( __( 'Prioridad', 'gestion-de-proyectos' ), 11, '' ),
				array( __( 'Acción', 'gestion-de-proyectos' ), 50, 'wrap' ),
				array( __( 'Vence', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Días hábiles (negativo: vencida)', 'gestion-de-proyectos' ), 13, 'num' ),
				array( __( 'Detalle', 'gestion-de-proyectos' ), 80, 'wrap' ),
				array( __( 'Guía del paso a paso', 'gestion-de-proyectos' ), 40, 'wrap' ),
			),
			$rows,
			array( 'empty' => __( 'Sin acciones pendientes.', 'gestion-de-proyectos' ) )
		);
	}

	// ------------------------------------------------------------------ Cuotas

	/**
	 * Cuota siguiente: cifras, condiciones de giro y compromisos que cierran la brecha.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function next_installment( array $data, array $ctx ): array {
		$status = (array) $data['status'];
		$next   = $status['next_installment'] ?? null;
		$rows   = array(
			array(
				array(
					'v' => __( 'Cuota siguiente', 'gestion-de-proyectos' ),
					's' => 'title',
				),
			),
			array(
				array(
					'v' => $ctx['subtitle'],
					's' => 'subtitle',
				),
			),
			array(),
		);
		if ( ! is_array( $next ) ) {
			$rows[] = array(
				array(
					'v' => __( 'No quedan cuotas por recibir o no están registradas.', 'gestion-de-proyectos' ),
					's' => 'note',
				),
			);

			return array(
				'name'      => $ctx['names']['next'],
				'columns'   => self::widths( array( 8, 60, 18, 90 ) ),
				'rows'      => $rows,
				'gridlines' => false,
			);
		}
		$gaps   = (array) $next['gaps'];
		$window = (array) ( $next['window'] ?? array() );
		$pairs  = array(
			array(
				__( 'Cuota', 'gestion-de-proyectos' ),
				array(
					'v' => (int) $next['number'],
					's' => 'code',
				),
			),
			array( __( 'Monto de la cuota', 'gestion-de-proyectos' ), self::money( (float) $next['amount'] ) ),
			array( __( 'Aporte pecuniario asociado', 'gestion-de-proyectos' ), self::money( (float) ( $next['cash_amount'] ?? 0 ) ) ),
			array( __( 'Ventana del convenio: desde', 'gestion-de-proyectos' ), self::date( (string) ( $window[0] ?? '' ) ) ),
			array( __( 'Ventana del convenio: hasta', 'gestion-de-proyectos' ), self::date( (string) ( $window[1] ?? '' ) ) ),
			array( __( 'Fecha objetivo del giro', 'gestion-de-proyectos' ), self::date( (string) $next['target_date'] ) ),
			array( __( 'Referencia de la brecha (lo transferido de las cuotas recibidas)', 'gestion-de-proyectos' ), self::money( (float) $gaps['target'] ) ),
			array( __( 'Falta por pagar', 'gestion-de-proyectos' ), self::money( (float) $gaps['pay_gap'] ) ),
			array( __( 'Falta por rendir', 'gestion-de-proyectos' ), self::money( (float) $gaps['render_gap'] ) ),
			array( __( 'Falta por aprobar', 'gestion-de-proyectos' ), self::money( (float) $gaps['approve_gap'] ) ),
			array( __( 'Garantía alternativa por la fracción no rendida', 'gestion-de-proyectos' ), self::money( (float) $gaps['guarantee'] ) ),
			array( __( 'Criterio de "rendir el 100 %"', 'gestion-de-proyectos' ), self::criterion( (string) ( $next['criterion'] ?? '' ) ) ),
			array( __( 'Último mes de pago útil', 'gestion-de-proyectos' ), ! empty( $next['latest_month'] ) ? BoardMetrics::month_label( (string) $next['latest_month'] ) : __( 'ninguno alcanza la fecha objetivo', 'gestion-de-proyectos' ) ),
			array( __( 'Rendir ese mes a más tardar el', 'gestion-de-proyectos' ), self::date( (string) ( $next['latest_render_due'] ?? '' ) ) ),
			array( __( 'Facturas recibidas a más tardar el', 'gestion-de-proyectos' ), self::date( (string) ( $next['invoice_by'] ?? '' ) ) ),
		);
		foreach ( $pairs as $p ) {
			$rows[] = array(
				'',
				array(
					'v' => $p[0],
					's' => 'bold',
				),
				$p[1],
			);
		}
		$rows[] = array();

		// Condiciones de giro.
		$rows[] = array(
			array(
				'v' => __( 'Condiciones de giro', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$rows[] = self::header_row( array( __( 'Clave', 'gestion-de-proyectos' ), __( 'Condición', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Detalle', 'gestion-de-proyectos' ) ) );
		foreach ( (array) $next['conditions'] as $c ) {
			$rows[] = array(
				(string) $c['key'],
				array(
					'v' => BoardMetrics::display_text( (string) $c['label'] ),
					's' => 'wrap',
				),
				self::state( $c['ok'] ?? null, __( 'sin registro', 'gestion-de-proyectos' ) ),
				array(
					'v' => BoardMetrics::display_text( (string) $c['detail'] ),
					's' => 'wrap',
				),
			);
		}
		$rows[] = array();

		// Compromisos que cierran la brecha.
		$profile_status = $ctx['pstatus'];
		$rows[]         = array(
			array(
				'v' => __( 'Compromisos que cierran la brecha de pago', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$rows[]         = self::header_row( array( __( 'Pago', 'gestion-de-proyectos' ), __( 'Descripción', 'gestion-de-proyectos' ), __( 'Monto', 'gestion-de-proyectos' ), __( 'Estado e ítem', 'gestion-de-proyectos' ) ) );
		$first          = count( $rows ) + 1;
		$candidates     = (array) ( $next['candidates'] ?? array() );
		foreach ( $candidates as $c ) {
			$rows[] = array(
				(string) $c['code'],
				array(
					'v' => (string) $c['description'],
					's' => 'wrap',
				),
				self::money( (float) $c['amount'] ),
				trim( ( $profile_status[ $c['status'] ] ?? (string) $c['status'] ) . ( empty( $c['item_ok'] ) ? ' · ' . __( 'ítem sin disponible', 'gestion-de-proyectos' ) : '' ) ),
			);
		}
		if ( $candidates ) {
			$last   = count( $rows );
			$rows[] = array(
				array(
					'v' => '',
					's' => 'total',
				),
				array(
					'v' => __( 'Total de los compromisos', 'gestion-de-proyectos' ),
					's' => 'total',
				),
				array(
					'v' => array_sum( array_map( static fn( array $c ): float => (float) $c['amount'], $candidates ) ),
					'f' => "SUM(C{$first}:C{$last})",
					's' => 'total_money',
				),
				array(
					'v' => '',
					's' => 'total',
				),
			);
			$rows[] = array(
				'',
				array(
					'v' => __( 'Cubren de la brecha de pago', 'gestion-de-proyectos' ),
					's' => 'bold',
				),
				self::money( (float) ( $next['candidates_covered'] ?? 0 ) ),
			);
			$rows[] = array(
				'',
				array(
					'v' => __( 'Quedan sin cubrir', 'gestion-de-proyectos' ),
					's' => 'bold',
				),
				self::money( (float) ( $next['candidates_remaining'] ?? 0 ) ),
			);
		} else {
			$rows[] = array(
				'',
				array(
					'v' => __( 'No hay brecha de pago o no hay compromisos registrados con cargo al Fondo.', 'gestion-de-proyectos' ),
					's' => 'note',
				),
			);
		}

		return array(
			'name'      => $ctx['names']['next'],
			'columns'   => self::widths( array( 9, 62, 18, 90 ) ),
			'rows'      => $rows,
			'gridlines' => false,
		);
	}

	/**
	 * Cuotas del programa de desembolso.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function installments( array $data, array $ctx ): array {
		$rows = array();
		foreach ( (array) $data['status']['installments'] as $i ) {
			$report = self::capital( __( 'ninguno', 'gestion-de-proyectos' ) );
			if ( (int) ( $i['report_no'] ?? 0 ) > 0 ) {
				/* translators: número del informe. */
				$report = sprintf( __( 'Informe N.º %d', 'gestion-de-proyectos' ), (int) $i['report_no'] );
			}
			$rows[] = array(
				(int) $i['number'],
				self::money( (float) $i['amount'] ),
				(float) ( $i['share_pct'] ?? 0 ) > 0 ? (float) $i['share_pct'] / 100 : '',
				self::date( (string) ( $i['window_from'] ?? '' ) ),
				self::date( (string) ( $i['window_to'] ?? '' ) ),
				$report,
				self::money( (float) ( $i['cash_amount'] ?? 0 ) ),
				self::date( (string) ( $i['cash_received_at'] ?? '' ) ),
				self::date( (string) ( $i['requested_at'] ?? '' ) ),
				self::date( (string) ( $i['transferred_at'] ?? '' ) ),
				self::date( (string) ( $i['received_on'] ?? '' ) ),
				! empty( $i['is_received'] ) ? array(
					'v' => __( 'Recibida', 'gestion-de-proyectos' ),
					's' => 'ok',
				) : __( 'Por recibir', 'gestion-de-proyectos' ),
				self::code( (string) ( $i['receipt_number'] ?? '' ) ),
				self::date( (string) ( $i['receipt_sent_at'] ?? '' ) ),
				self::date( (string) ( $i['receipt_due'] ?? '' ) ),
				(string) ( $ctx['tstatus'][ $i['platform_status'] ?? '' ] ?? ( $i['platform_status'] ?? '' ) ),
				self::money( (float) ( $i['paid'] ?? 0 ) ),
				self::money( (float) ( $i['rendered'] ?? 0 ) ),
				self::money( (float) ( $i['approved'] ?? 0 ) ),
				(string) ( $i['notes'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['installments'],
			__( 'Cuotas del programa de desembolso', 'gestion-de-proyectos' ),
			array(
				array( __( 'Cuota', 'gestion-de-proyectos' ), 7, 'num' ),
				array( __( 'Monto', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Parte del convenio', 'gestion-de-proyectos' ), 10, 'pct' ),
				array( __( 'Ventana desde', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Ventana hasta', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Informe que la habilita', 'gestion-de-proyectos' ), 15, '' ),
				array( __( 'Aporte pecuniario asociado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Aporte acreditado el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Solicitada el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Transferida el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Recibida el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Situación', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Comprobante de ingreso', 'gestion-de-proyectos' ), 14, '' ),
				array( __( 'Comprobante enviado el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Plazo del comprobante', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Estado en la plataforma', 'gestion-de-proyectos' ), 16, '' ),
				array( __( 'Pagado imputado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Rendido imputado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Aprobado imputado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Notas', 'gestion-de-proyectos' ), 60, 'wrap' ),
			),
			$rows,
			array(
				'totals'      => array(
					0  => __( 'Total', 'gestion-de-proyectos' ),
					1  => 'sum',
					6  => 'sum',
					16 => 'sum',
					17 => 'sum',
					18 => 'sum',
				),
				'freeze_cols' => 1,
				'empty'       => __( 'Sin cuotas registradas.', 'gestion-de-proyectos' ),
			)
		);
	}

	// ------------------------------------------------------------------ Ítems

	/**
	 * Ítems: asignado, ejecutado y disponible por fuente.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function items( array $data, array $ctx ): array {
		$rows      = array();
		$items     = (array) $data['status']['items'];
		$item_rows = (array) ( $data['items_rows'] ?? array() );
		foreach ( $items as $i ) {
			$f      = (array) $i['sources']['fondo'];
			$c      = (array) $i['sources']['pecuniario'];
			$real   = 'sin_item' !== $i['slug'];
			$cap    = is_array( $i['cap'] ?? null ) ? $i['cap'] : null;
			$type   = (string) ( $ctx['ptypes'][ $i['platform_type'] ] ?? $i['platform_type'] );
			$sub    = trim( (string) $i['platform_subclass'] );
			$type  .= '' !== $sub && self::fold( $sub ) !== self::fold( $type ) ? ' · ' . $sub : '';
			$used   = self::ratio( (float) $f['paid'] + (float) $f['committed'], (float) $f['assigned'] );
			$rows[] = array(
				(string) $i['label'],
				trim( $type, ' ·' ),
				self::money( (float) $f['assigned'] ),
				self::money( (float) $f['paid'] ),
				self::money( (float) $f['committed'] ),
				self::money( (float) $f['rejected'] ),
				self::money( (float) $f['rendered'] ),
				$real ? array(
					'v' => (float) $f['available'],
					'f' => 'C{r}-D{r}-E{r}-F{r}',
				) : self::money( (float) $f['available'] ),
				$real ? array(
					'v' => $used,
					'f' => 'IF(C{r}>0,(D{r}+E{r})/C{r},0)',
				) : '',
				self::money( (float) $c['assigned'] ),
				self::money( (float) $c['paid'] ),
				self::money( (float) $c['committed'] ),
				self::money( (float) $c['rejected'] ),
				$real ? array(
					'v' => (float) $c['available'],
					'f' => 'J{r}-K{r}-L{r}-M{r}',
				) : self::money( (float) $c['available'] ),
				self::money( (float) ( $item_rows[ $i['slug'] ]['assigned_inkind'] ?? 0 ) ),
				$cap ? self::money( (float) $cap['limit'] ) : '',
				$cap ? (string) $cap['base'] : '',
				$cap ? ( $cap['ok'] ? array(
					'v' => __( 'Sí', 'gestion-de-proyectos' ),
					's' => 'ok',
				) : array(
					'v' => __( 'No', 'gestion-de-proyectos' ),
					's' => 'bad',
				) ) : '',
				implode( '; ', array_values( (array) ( $ctx['required'][ $i['slug'] ] ?? array() ) ) ),
				(string) ( $item_rows[ $i['slug'] ]['notes'] ?? '' ),
			);
		}
		$sheet = self::table(
			$ctx,
			$ctx['names']['items'],
			__( 'Ítems: asignado, ejecutado y disponible por fuente', 'gestion-de-proyectos' ),
			array(
				array( __( 'Ítem', 'gestion-de-proyectos' ), 26, '' ),
				array( __( 'Clasificación en la plataforma', 'gestion-de-proyectos' ), 24, 'wrap' ),
				array( __( 'Fondo: asignado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Fondo: pagado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Fondo: comprometido', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Fondo: rechazado', 'gestion-de-proyectos' ), 14, 'money' ),
				array( __( 'Fondo: rendido', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Fondo: disponible', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Fondo: usado (pagado y comprometido)', 'gestion-de-proyectos' ), 12, 'pct' ),
				array( __( 'Aporte pecuniario: asignado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Aporte pecuniario: pagado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Aporte pecuniario: comprometido', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Aporte pecuniario: rechazado', 'gestion-de-proyectos' ), 14, 'money' ),
				array( __( 'Aporte pecuniario: disponible', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Aporte no pecuniario', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Tope de las bases', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Base del tope', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Dentro del tope', 'gestion-de-proyectos' ), 10, '' ),
				array( __( 'Respaldos que exige', 'gestion-de-proyectos' ), 50, 'wrap' ),
				array( __( 'Notas', 'gestion-de-proyectos' ), 50, 'wrap' ),
			),
			$rows,
			array(
				'totals'      => array(
					0  => __( 'Total', 'gestion-de-proyectos' ),
					2  => 'sum',
					3  => 'sum',
					4  => 'sum',
					5  => 'sum',
					6  => 'sum',
					7  => 'sum',
					9  => 'sum',
					10 => 'sum',
					11 => 'sum',
					12 => 'sum',
					13 => 'sum',
					14 => 'sum',
				),
				'freeze_cols' => 1,
				'empty'       => __( 'Sin ítems registrados.', 'gestion-de-proyectos' ),
			)
		);
		// Porcentaje usado del total: sobre los totales de la misma fila.
		if ( $items ) {
			$t     = count( $sheet['rows'] ) - 1;
			$r     = $t + 1;
			$tot_c = array_sum( array_map( static fn( array $i ): float => (float) $i['sources']['fondo']['assigned'], $items ) );
			$tot_u = array_sum( array_map( static fn( array $i ): float => (float) $i['sources']['fondo']['paid'] + (float) $i['sources']['fondo']['committed'], $items ) );

			$sheet['rows'][ $t ][8] = array(
				'v' => self::ratio( $tot_u, $tot_c ),
				'f' => "IF(C{$r}>0,(D{$r}+E{$r})/C{$r},0)",
				's' => 'total_pct',
			);
		}

		return $sheet;
	}

	// ------------------------------------------------------------------ Pagos

	/**
	 * Pagos, con la ubicación de sus columnas para las fórmulas de otras hojas.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array{sheet:array<string,mixed>,meta:array<string,mixed>}
	 */
	private static function payments( array $data, array $ctx ): array {
		$rows     = array();
		$payments = array_values( (array) $data['payments'] );
		usort( $payments, static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['code'], (string) $b['code'] ) );
		$effective = (array) ( $data['effective'] ?? array() );
		$issues    = (array) ( $data['issues'] ?? array() );
		$docs      = ! empty( $data['docs'] );
		$keys      = self::rendition_keys( $data, $ctx );
		foreach ( $payments as $p ) {
			$supplier = $data['suppliers'][ (int) $p['supplier_id'] ] ?? null;
			$purchase = $data['purchases'][ (int) $p['purchase_id'] ] ?? null;
			$found    = (array) ( $issues[ (int) $p['id'] ] ?? array() );
			$blocks   = PaymentValidator::blocks( $found );
			$inst     = $effective[ $p['id'] ] ?? ( (int) $p['installment_no'] > 0 ? (int) $p['installment_no'] : 0 );
			$status   = (string) $p['status'];
			$style    = in_array( $status, array( 'observado', 'rechazado' ), true ) ? 'bad' : ( 'aprobado' === $status ? 'ok' : '' );
			$support  = array();
			foreach ( (array) ( $p['support'] ?? array() ) as $s ) {
				$did       = (int) ( $s['document_id'] ?? 0 );
				$label     = (string) ( $ctx['supports'][ $s['kind'] ?? '' ] ?? ( $s['kind'] ?? '' ) );
				$doc       = $data['documents'][ $did ] ?? null;
				$label    .= $did > 0 ? ( $docs && is_array( $doc ) ? ': ' . trim( (string) ( '' !== trim( (string) $doc['number'] ) ? $doc['number'] : $doc['subject'] ) ) : ' ' . __( '(adjunto)', 'gestion-de-proyectos' ) ) : '';
				$label    .= ! empty( $s['note'] ) ? ' (' . (string) $s['note'] . ')' : '';
				$support[] = $label;
			}
			$rows[] = array(
				(string) $p['code'],
				(string) ( $ctx['sources'][ $p['source'] ] ?? $p['source'] ),
				(string) ( $ctx['items'][ $p['item_slug'] ] ?? $p['item_slug'] ),
				is_array( $supplier ) ? (string) $supplier['name'] : __( 'Sin proveedor', 'gestion-de-proyectos' ),
				is_array( $supplier ) ? (string) ( $supplier['tax_id'] ?? '' ) : '',
				(string) $p['description'],
				(string) $p['commitment'],
				(string) ( $ctx['doc_types'][ $p['doc_type'] ] ?? $p['doc_type'] ),
				self::code( (string) $p['doc_number'] ),
				self::date( (string) $p['doc_date'] ),
				self::date( (string) $p['executed_at'] ),
				self::date( (string) $p['paid_at'] ),
				self::code( (string) $p['egress_number'] ),
				self::money( (float) $p['amount'] ),
				$inst > 0 ? (int) $inst : '',
				array(
					'v' => (string) ( $ctx['pstatus'][ $status ] ?? $status ),
					's' => $style,
				),
				self::stage( $status ),
				(string) ( $keys[ (int) $p['rendition_id'] ] ?? '' ),
				(int) $p['folio'] > 0 ? (int) $p['folio'] : '',
				is_array( $purchase ) ? (string) $purchase['code'] : '',
				(string) $p['observation'],
				self::date( (string) ( $p['observed_at'] ?? '' ) ),
				implode( '; ', $support ),
				implode( '; ', array_map( static fn( array $i ): string => BoardMetrics::display_text( (string) $i['message'] ), $found ) ),
				$blocks ? array(
					'v' => __( 'Sí', 'gestion-de-proyectos' ),
					's' => 'bad',
				) : __( 'No', 'gestion-de-proyectos' ),
				(string) $p['notes'],
			);
		}
		$sheet  = self::table(
			$ctx,
			$ctx['names']['payments'],
			__( 'Pagos', 'gestion-de-proyectos' ),
			array(
				array( __( 'Código', 'gestion-de-proyectos' ), 10, '' ),
				array( __( 'Fuente', 'gestion-de-proyectos' ), 18, '' ),
				array( __( 'Ítem', 'gestion-de-proyectos' ), 20, '' ),
				array( __( 'Proveedor', 'gestion-de-proyectos' ), 30, 'wrap' ),
				array( __( 'RUT', 'gestion-de-proyectos' ), 13, '' ),
				array( __( 'Descripción', 'gestion-de-proyectos' ), 50, 'wrap' ),
				array( __( 'Compromiso', 'gestion-de-proyectos' ), 18, 'wrap' ),
				array( __( 'Tipo de documento', 'gestion-de-proyectos' ), 18, '' ),
				array( __( 'N.º de documento', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Fecha del documento', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Fecha de ejecución', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Fecha de pago', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'N.º de egreso', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Monto', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Cuota imputada', 'gestion-de-proyectos' ), 9, 'num' ),
				array( __( 'Estado', 'gestion-de-proyectos' ), 13, '' ),
				array( __( 'Etapa', 'gestion-de-proyectos' ), 11, '' ),
				array( __( 'Rendición', 'gestion-de-proyectos' ), 30, 'wrap' ),
				array( __( 'Folio', 'gestion-de-proyectos' ), 7, 'num' ),
				array( __( 'Compra', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Observación', 'gestion-de-proyectos' ), 40, 'wrap' ),
				array( __( 'Observado el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Respaldos', 'gestion-de-proyectos' ), 40, 'wrap' ),
				array( __( 'Hallazgos para la rendición', 'gestion-de-proyectos' ), 60, 'wrap' ),
				array( __( 'Bloquea la rendición', 'gestion-de-proyectos' ), 11, '' ),
				array( __( 'Notas', 'gestion-de-proyectos' ), 40, 'wrap' ),
			),
			$rows,
			array(
				'totals'      => array(
					0  => __( 'Total', 'gestion-de-proyectos' ),
					13 => 'sum',
				),
				'freeze_cols' => 1,
				'empty'       => __( 'Sin pagos registrados.', 'gestion-de-proyectos' ),
			)
		);
		$header = self::header_index( 0 );

		return array(
			'sheet' => $sheet,
			'meta'  => array(
				'first'     => $header + 2,
				'last'      => $header + 1 + max( 1, count( $rows ) ),
				'source'    => Workbook::column( 1 ),
				'supplier'  => Workbook::column( 3 ),
				'amount'    => Workbook::column( 13 ),
				'status'    => Workbook::column( 15 ),
				'stage'     => Workbook::column( 16 ),
				'rendition' => Workbook::column( 17 ),
				'count'     => count( $rows ),
				'keys'      => $keys,
			),
		);
	}

	/**
	 * Pagos por estado y fuente, con fórmulas sobre la hoja de pagos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @param array<string,mixed> $meta Ubicación de las columnas de Pagos.
	 * @return array<string,mixed>
	 */
	private static function by_status( array $data, array $ctx, array $meta ): array {
		$sheet = Workbook::quote_sheet( $ctx['names']['payments'] );
		$range = static fn( string $col ): string => sprintf( '%s!$%s$%d:$%s$%d', $sheet, $col, $meta['first'], $col, $meta['last'] );
		$sum   = array();
		$count = array();
		foreach ( (array) $data['payments'] as $p ) {
			$k           = (string) $p['status'] . '|' . (string) $p['source'];
			$sum[ $k ]   = ( $sum[ $k ] ?? 0.0 ) + (float) $p['amount'];
			$count[ $k ] = ( $count[ $k ] ?? 0 ) + 1;
		}
		$rows    = array();
		$sources = array( 'fondo', 'pecuniario' );
		foreach ( $ctx['pstatus'] as $slug => $label ) {
			$cells = array( (string) $label );
			foreach ( $sources as $src ) {
				$crit    = self::text_criterion( (string) ( $ctx['sources'][ $src ] ?? $src ) );
				$k       = $slug . '|' . $src;
				$cells[] = array(
					'v' => (float) ( $sum[ $k ] ?? 0 ),
					'f' => sprintf( 'SUMIFS(%s,%s,$A{r},%s,"%s")', $range( $meta['amount'] ), $range( $meta['status'] ), $range( $meta['source'] ), $crit ),
				);
				$cells[] = array(
					'v' => (int) ( $count[ $k ] ?? 0 ),
					'f' => sprintf( 'COUNTIFS(%s,$A{r},%s,"%s")', $range( $meta['status'] ), $range( $meta['source'] ), $crit ),
				);
			}
			$cells[] = array(
				'v' => (float) ( $sum[ $slug . '|fondo' ] ?? 0 ) + (float) ( $sum[ $slug . '|pecuniario' ] ?? 0 ),
				'f' => 'B{r}+D{r}',
			);
			$cells[] = array(
				'v' => (int) ( $count[ $slug . '|fondo' ] ?? 0 ) + (int) ( $count[ $slug . '|pecuniario' ] ?? 0 ),
				'f' => 'C{r}+E{r}',
			);
			$rows[]  = $cells;
		}

		return self::table(
			$ctx,
			$ctx['names']['by_status'],
			__( 'Pagos por estado y fuente', 'gestion-de-proyectos' ),
			array(
				array( __( 'Estado', 'gestion-de-proyectos' ), 22, '' ),
				array( sprintf( /* translators: fuente. */ __( '%s: monto', 'gestion-de-proyectos' ), (string) ( $ctx['sources']['fondo'] ?? 'Fondo' ) ), 17, 'money' ),
				array( sprintf( /* translators: fuente. */ __( '%s: pagos', 'gestion-de-proyectos' ), (string) ( $ctx['sources']['fondo'] ?? 'Fondo' ) ), 10, 'int' ),
				array( sprintf( /* translators: fuente. */ __( '%s: monto', 'gestion-de-proyectos' ), (string) ( $ctx['sources']['pecuniario'] ?? 'Aporte pecuniario' ) ), 17, 'money' ),
				array( sprintf( /* translators: fuente. */ __( '%s: pagos', 'gestion-de-proyectos' ), (string) ( $ctx['sources']['pecuniario'] ?? 'Aporte pecuniario' ) ), 10, 'int' ),
				array( __( 'Total: monto', 'gestion-de-proyectos' ), 17, 'money' ),
				array( __( 'Total: pagos', 'gestion-de-proyectos' ), 10, 'int' ),
			),
			$rows,
			array(
				'totals' => array(
					0 => __( 'Total', 'gestion-de-proyectos' ),
					1 => 'sum',
					2 => 'sum',
					3 => 'sum',
					4 => 'sum',
					5 => 'sum',
					6 => 'sum',
				),
				'filter' => false,
			)
		);
	}

	/**
	 * Proveedores con pagos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @param array<string,mixed> $meta Ubicación de las columnas de Pagos.
	 * @return array<string,mixed>
	 */
	private static function suppliers( array $data, array $ctx, array $meta ): array {
		$sheet  = Workbook::quote_sheet( $ctx['names']['payments'] );
		$range  = static fn( string $col ): string => sprintf( '%s!$%s$%d:$%s$%d', $sheet, $col, $meta['first'], $col, $meta['last'] );
		$groups = array();
		foreach ( (array) $data['payments'] as $p ) {
			$sid  = (int) $p['supplier_id'];
			$s    = $data['suppliers'][ $sid ] ?? null;
			$name = is_array( $s ) ? (string) $s['name'] : __( 'Sin proveedor', 'gestion-de-proyectos' );
			if ( ! isset( $groups[ $name ] ) ) {
				$groups[ $name ] = array(
					'tax'        => is_array( $s ) ? (string) ( $s['tax_id'] ?? '' ) : '',
					'registered' => is_array( $s ) ? ! empty( $data['registered'][ $sid ] ) : null,
					'n'          => 0,
					'total'      => 0.0,
					'paid'       => 0.0,
					'due'        => 0.0,
				);
			}
			$stage = self::stage( (string) $p['status'] );
			++$groups[ $name ]['n'];
			$groups[ $name ]['total'] += (float) $p['amount'];
			if ( __( 'Pagado', 'gestion-de-proyectos' ) === $stage ) {
				$groups[ $name ]['paid'] += (float) $p['amount'];
			} else {
				$groups[ $name ]['due'] += (float) $p['amount'];
			}
		}
		ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );
		$paid = self::text_criterion( __( 'Pagado', 'gestion-de-proyectos' ) );
		$due  = self::text_criterion( __( 'Por pagar', 'gestion-de-proyectos' ) );
		$rows = array();
		foreach ( $groups as $name => $g ) {
			$rows[] = array(
				(string) $name,
				$g['tax'],
				null === $g['registered'] ? '' : ( $g['registered'] ? array(
					'v' => __( 'Sí', 'gestion-de-proyectos' ),
					's' => 'ok',
				) : array(
					'v' => __( 'No', 'gestion-de-proyectos' ),
					's' => 'warn',
				) ),
				array(
					'v' => (int) $g['n'],
					'f' => sprintf( 'COUNTIFS(%s,$A{r})', $range( $meta['supplier'] ) ),
				),
				array(
					'v' => (float) $g['total'],
					'f' => sprintf( 'SUMIFS(%s,%s,$A{r})', $range( $meta['amount'] ), $range( $meta['supplier'] ) ),
				),
				array(
					'v' => (float) $g['paid'],
					'f' => sprintf( 'SUMIFS(%s,%s,$A{r},%s,"%s")', $range( $meta['amount'] ), $range( $meta['supplier'] ), $range( $meta['stage'] ), $paid ),
				),
				array(
					'v' => (float) $g['due'],
					'f' => sprintf( 'SUMIFS(%s,%s,$A{r},%s,"%s")', $range( $meta['amount'] ), $range( $meta['supplier'] ), $range( $meta['stage'] ), $due ),
				),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['suppliers'],
			__( 'Proveedores con pagos', 'gestion-de-proyectos' ),
			array(
				array( __( 'Proveedor', 'gestion-de-proyectos' ), 36, 'wrap' ),
				array( __( 'RUT', 'gestion-de-proyectos' ), 13, '' ),
				array( __( 'Registrado en SISREC', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Pagos', 'gestion-de-proyectos' ), 8, 'int' ),
				array( __( 'Monto total', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Pagado', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Por pagar', 'gestion-de-proyectos' ), 16, 'money' ),
			),
			$rows,
			array(
				'totals' => array(
					0 => __( 'Total', 'gestion-de-proyectos' ),
					3 => 'sum',
					4 => 'sum',
					5 => 'sum',
					6 => 'sum',
				),
				'empty'  => __( 'Sin pagos registrados.', 'gestion-de-proyectos' ),
			)
		);
	}

	// ------------------------------------------------------------------ Rendiciones

	/**
	 * Rendiciones mes a mes (línea de tiempo del estado de cuentas).
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array{sheet:array<string,mixed>,meta:array<string,mixed>}
	 */
	private static function renditions_by_month( array $data, array $ctx ): array {
		$timeline = array_values( (array) $data['status']['renditions'] );
		$labels   = array();
		foreach ( $ctx['rstatus'] as $slug => $s ) {
			$labels[ $slug ] = (string) ( $s['label'] ?? $slug );
		}
		$cells   = BoardMetrics::rendition_cells( $timeline, $labels );
		$styles  = array(
			'ok'      => 'ok',
			'warn'    => 'warn',
			'bad'     => 'bad',
			'current' => 'muted',
		);
		$rows    = array();
		$overdue = 0;
		foreach ( $timeline as $k => $t ) {
			$r    = $t['rendition'] ?? null;
			$cell = $cells[ $k ];
			if ( ! empty( $t['overdue'] ) ) {
				++$overdue;
			}
			$rows[] = array(
				BoardMetrics::month_label( (string) $t['period'] ),
				(string) $t['period'],
				self::money( (float) ( $t['amount'] ?? 0 ) ),
				self::capital( BoardMetrics::rendition_kind_label( is_array( $r ) ? (string) $r['kind'] : (string) ( $t['expected_kind'] ?? '' ) ) ),
				is_array( $r ) ? __( 'Sí', 'gestion-de-proyectos' ) : __( 'No', 'gestion-de-proyectos' ),
				is_array( $r ) ? (string) ( $labels[ $r['status'] ] ?? $r['status'] ) : '',
				array(
					'v' => (string) $cell['label'],
					's' => $styles[ $cell['state'] ] ?? '',
				),
				self::date( (string) ( $t['internal_due'] ?? '' ) ),
				self::date( (string) ( $t['platform_due'] ?? '' ) ),
				self::date( (string) ( $cell['fix_due'] ?? '' ) ),
				! empty( $t['submitted'] ) ? '' : (int) ( $t['days_left'] ?? 0 ),
				! empty( $t['overdue'] ) ? array(
					'v' => __( 'Sí', 'gestion-de-proyectos' ),
					's' => 'bad',
				) : __( 'No', 'gestion-de-proyectos' ),
			);
		}
		$sheet  = self::table(
			$ctx,
			$ctx['names']['months'],
			__( 'Rendiciones mes a mes del Fondo', 'gestion-de-proyectos' ),
			array(
				array( __( 'Mes', 'gestion-de-proyectos' ), 20, '' ),
				array( __( 'Período', 'gestion-de-proyectos' ), 9, '' ),
				array( __( 'Pagado en el mes', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Tipo', 'gestion-de-proyectos' ), 14, '' ),
				array( __( 'Registrada en el módulo', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Estado declarado', 'gestion-de-proyectos' ), 26, 'wrap' ),
				array( __( 'Situación', 'gestion-de-proyectos' ), 22, 'wrap' ),
				array( __( 'Respaldos a la universidad hasta', 'gestion-de-proyectos' ), 13, 'date' ),
				array( __( 'Plazo en SISREC', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Plazo de subsanación', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Días hábiles restantes', 'gestion-de-proyectos' ), 11, 'num' ),
				array( __( 'Vencida sin estado declarado', 'gestion-de-proyectos' ), 12, '' ),
			),
			$rows,
			array(
				'totals' => array(
					0 => __( 'Total', 'gestion-de-proyectos' ),
					2 => 'sum',
				),
				'empty'  => __( 'El convenio no tiene meses de rendición registrados.', 'gestion-de-proyectos' ),
			)
		);
		$header = self::header_index( 0 );

		return array(
			'sheet' => $sheet,
			'meta'  => array(
				'col'     => Workbook::column( 11 ),
				'first'   => $header + 2,
				'last'    => $header + 1 + max( 1, count( $rows ) ),
				'overdue' => $overdue,
			),
		);
	}

	/**
	 * Rendiciones registradas, con sus pagos por fórmula.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @param array<string,mixed> $meta Ubicación de las columnas de Pagos.
	 * @return array<string,mixed>
	 */
	private static function renditions( array $data, array $ctx, array $meta ): array {
		$sheet  = Workbook::quote_sheet( $ctx['names']['payments'] );
		$range  = static fn( string $col ): string => sprintf( '%s!$%s$%d:$%s$%d', $sheet, $col, $meta['first'], $col, $meta['last'] );
		$sums   = array();
		$counts = array();
		foreach ( (array) $data['payments'] as $p ) {
			$rid = (int) $p['rendition_id'];
			if ( $rid > 0 ) {
				$sums[ $rid ]   = ( $sums[ $rid ] ?? 0.0 ) + (float) $p['amount'];
				$counts[ $rid ] = ( $counts[ $rid ] ?? 0 ) + 1;
			}
		}
		$rows = array();
		foreach ( (array) $data['renditions'] as $r ) {
			$id     = (int) $r['id'];
			$status = (string) $r['status'];
			$style  = 'aprobada' === $status ? 'ok' : ( 'devuelta' === $status ? 'bad' : ( 'aprobada_parcial' === $status ? 'warn' : '' ) );
			$rows[] = array(
				(string) $r['period'],
				BoardMetrics::month_label( (string) $r['period'] ),
				(string) ( $meta['keys'][ $id ] ?? '' ),
				(string) ( $ctx['sources'][ $r['source'] ] ?? $r['source'] ),
				self::capital( BoardMetrics::rendition_kind_label( (string) $r['kind'] ) ),
				array(
					'v' => (string) ( $ctx['rstatus'][ $status ]['label'] ?? $status ),
					's' => $style,
				),
				(string) ( $ctx['rstatus'][ $status ]['actor'] ?? '' ),
				self::date( (string) ( $r['internal_due'] ?? '' ) ),
				self::date( (string) ( $r['platform_due'] ?? '' ) ),
				self::date( (string) ( $r['fix_due'] ?? '' ) ),
				self::date( (string) ( $r['sent_internal_at'] ?? '' ) ),
				self::date( (string) ( $r['loaded_at'] ?? '' ) ),
				self::date( (string) ( $r['sent_at'] ?? '' ) ),
				self::date( (string) ( $r['approved_at'] ?? '' ) ),
				self::date( (string) ( $r['returned_at'] ?? '' ) ),
				array(
					'v' => (int) ( $counts[ $id ] ?? 0 ),
					'f' => sprintf( 'COUNTIFS(%s,$C{r})', $range( $meta['rendition'] ) ),
				),
				array(
					'v' => (float) ( $sums[ $id ] ?? 0 ),
					'f' => sprintf( 'SUMIFS(%s,%s,$C{r})', $range( $meta['amount'] ), $range( $meta['rendition'] ) ),
				),
				(string) ( $r['notes'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['renditions'],
			__( 'Rendiciones registradas', 'gestion-de-proyectos' ),
			array(
				array( __( 'Período', 'gestion-de-proyectos' ), 9, '' ),
				array( __( 'Mes', 'gestion-de-proyectos' ), 20, '' ),
				array( __( 'Rendición (como figura en Pagos)', 'gestion-de-proyectos' ), 34, 'wrap' ),
				array( __( 'Fuente', 'gestion-de-proyectos' ), 18, '' ),
				array( __( 'Tipo', 'gestion-de-proyectos' ), 14, '' ),
				array( __( 'Estado', 'gestion-de-proyectos' ), 26, 'wrap' ),
				array( __( 'A cargo de', 'gestion-de-proyectos' ), 30, 'wrap' ),
				array( __( 'Plazo interno', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Plazo en la plataforma', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Plazo de subsanación', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Enviada a la universidad', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Cargada en la plataforma', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Enviada al otorgante', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Aprobada', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Devuelta', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Pagos', 'gestion-de-proyectos' ), 8, 'int' ),
				array( __( 'Monto de los pagos', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Notas', 'gestion-de-proyectos' ), 50, 'wrap' ),
			),
			$rows,
			array(
				'totals' => array(
					0  => __( 'Total', 'gestion-de-proyectos' ),
					15 => 'sum',
					16 => 'sum',
				),
				'empty'  => __( 'Todavía no hay rendiciones registradas en el módulo.', 'gestion-de-proyectos' ),
			)
		);
	}

	/**
	 * Estados declarados y pasos cumplidos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function events( array $data, array $ctx ): array {
		$entities = array(
			'rendition'   => __( 'Rendición', 'gestion-de-proyectos' ),
			'installment' => __( 'Cuota', 'gestion-de-proyectos' ),
			'payment'     => __( 'Pago', 'gestion-de-proyectos' ),
			'supplier'    => __( 'Proveedor', 'gestion-de-proyectos' ),
			'guarantee'   => __( 'Garantía', 'gestion-de-proyectos' ),
			'project'     => __( 'Proyecto', 'gestion-de-proyectos' ),
		);
		$facts    = array(
			'informe_aprobado'       => __( 'Informe de avance aprobado', 'gestion-de-proyectos' ),
			'carta_solicitud'        => __( 'Carta de solicitud enviada', 'gestion-de-proyectos' ),
			'transferencia_aceptada' => __( 'Transferencia aceptada', 'gestion-de-proyectos' ),
			'comprobante_enviado'    => __( 'Comprobante de ingreso enviado', 'gestion-de-proyectos' ),
			'aporte_acreditado'      => __( 'Aporte pecuniario acreditado', 'gestion-de-proyectos' ),
			'otro'                   => __( 'Otro hecho', 'gestion-de-proyectos' ),
		);
		$refs     = self::entity_refs( $data, $ctx );
		$rows     = array();
		$events   = (array) ( $data['events'] ?? array() );
		usort( $events, static fn( array $a, array $b ): int => array( (string) $a['event_date'], (int) ( $a['id'] ?? 0 ) ) <=> array( (string) $b['event_date'], (int) ( $b['id'] ?? 0 ) ) );
		foreach ( $events as $e ) {
			$type  = (string) $e['entity_type'];
			$key   = (string) $e['event_key'];
			$guide = (string) ( $e['guide'] ?? '' );
			if ( 'paso' === $e['kind'] ) {
				$label = $key;
				foreach ( (array) ( $ctx['guides'][ $guide ]['steps'] ?? array() ) as $step ) {
					if ( ( $step['key'] ?? '' ) === $key ) {
						$label = (string) ( $step['screen'] ?? $key );
					}
				}
			} elseif ( 'rendition' === $type ) {
				$label = (string) ( $ctx['rstatus'][ $key ]['label'] ?? $key );
			} elseif ( 'payment' === $type ) {
				$label = (string) ( $ctx['pstatus'][ $key ] ?? $key );
			} elseif ( 'installment' === $type ) {
				$label = (string) ( $facts[ $key ] ?? $key );
			} else {
				$label = ucfirst( str_replace( '_', ' ', $key ) );
			}
			$rows[] = array(
				self::date( (string) $e['event_date'] ),
				(string) ( $entities[ $type ] ?? $type ),
				(string) ( $refs[ $type ][ (int) $e['entity_id'] ] ?? ( 'project' === $type ? $ctx['line'] : '#' . (int) $e['entity_id'] ) ),
				'paso' === $e['kind'] ? __( 'Paso cumplido', 'gestion-de-proyectos' ) : __( 'Estado declarado', 'gestion-de-proyectos' ),
				$label,
				'' !== $guide ? (string) ( $ctx['guides'][ $guide ]['label'] ?? $guide ) : '',
				(string) ( $e['note'] ?? '' ),
				(int) ( $e['document_id'] ?? 0 ) > 0 ? __( 'Sí', 'gestion-de-proyectos' ) : '',
				(string) ( $e['user'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['events'],
			__( 'Estados declarados y pasos cumplidos', 'gestion-de-proyectos' ),
			array(
				array( __( 'Fecha', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Entidad', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Referencia', 'gestion-de-proyectos' ), 32, 'wrap' ),
				array( __( 'Tipo', 'gestion-de-proyectos' ), 16, '' ),
				array( __( 'Estado o paso', 'gestion-de-proyectos' ), 40, 'wrap' ),
				array( __( 'Guía', 'gestion-de-proyectos' ), 40, 'wrap' ),
				array( __( 'Nota', 'gestion-de-proyectos' ), 50, 'wrap' ),
				array( __( 'Documento adjunto', 'gestion-de-proyectos' ), 11, '' ),
				array( __( 'Registrado por', 'gestion-de-proyectos' ), 22, '' ),
			),
			$rows,
			array( 'empty' => __( 'Todavía no hay estados declarados ni pasos marcados.', 'gestion-de-proyectos' ) )
		);
	}

	// ------------------------------------------------------------------ Caja

	/**
	 * Caja mes a mes: transferido, programado y pagado, con acumulados por fórmula.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function cash( array $data, array $ctx ): array {
		$curve    = (array) $data['curve'];
		$months   = array_values( (array) ( $curve['months'] ?? array() ) );
		$received = (array) ( $data['received'] ?? array() );
		$paid     = (array) ( $data['paid_by_month']['fondo'] ?? array() );
		$plan     = is_array( $data['status']['cash_plan'] ?? null ) ? (array) $data['status']['cash_plan']['rows'] : array();
		$transfer = array();
		$spend    = array();
		foreach ( $plan as $r ) {
			$transfer[ (string) $r['period'] ] = (float) ( $transfer[ (string) $r['period'] ] ?? 0 ) + (float) $r['transfer'];
			$spend[ (string) $r['period'] ]    = (float) ( $spend[ (string) $r['period'] ] ?? 0 ) + (float) $r['spend'];
		}
		$first      = (string) ( $months[0] ?? '' );
		$before_rec = 0.0;
		$before_pay = 0.0;
		foreach ( $received as $m => $v ) {
			$before_rec += (string) $m < $first ? (float) $v : 0.0;
		}
		foreach ( $paid as $m => $v ) {
			$before_pay += (string) $m < $first ? (float) $v : 0.0;
		}
		$plan_first = $spend ? (string) min( array_keys( $spend ) ) : '';
		$plan_base  = 0.0;
		foreach ( $paid as $m => $v ) {
			$plan_base += '' !== $plan_first && (string) $m < $plan_first ? (float) $v : 0.0;
		}
		// Los valores del preámbulo van en la columna F, bajo el transferido
		// acumulado, para que el rótulo se lea completo en las celdas vacías.
		$pre      = self::PREAMBLE_ROW;
		$preamble = array(
			self::preamble( __( 'Transferido antes del primer mes de la tabla', 'gestion-de-proyectos' ), self::money( $before_rec ), 5 ),
			self::preamble( __( 'Pagado antes del primer mes de la tabla', 'gestion-de-proyectos' ), self::money( $before_pay ), 5 ),
			self::preamble( __( 'Pagado antes del primer mes de la programación (su línea base)', 'gestion-de-proyectos' ), self::money( $plan_base ), 5 ),
		);
		$current  = $ctx['current'];
		$from     = (int) ( $curve['projected_from'] ?? count( $months ) );
		$rows     = array();
		$prev_tr  = $before_rec;
		$real_cum = $before_rec;
		$started  = false;
		foreach ( $months as $i => $m ) {
			$tr_cum    = (float) ( $curve['transfers'][ $i ] ?? 0 );
			$monthly   = round( $tr_cum - $prev_tr, 2 );
			$prev_tr   = $tr_cum;
			$past      = $m <= $current;
			$real_cum += $past ? (float) ( $received[ $m ] ?? 0 ) : 0.0;
			$plan_cum  = $curve['planned'][ $i ] ?? null;
			$pay_cum   = $curve['paid'][ $i ] ?? null;
			$cells     = array(
				BoardMetrics::month_label( (string) $m ),
				(string) $m,
				$past ? self::money( (float) ( $received[ $m ] ?? 0 ) ) : '',
				self::money( (float) ( $transfer[ $m ] ?? 0 ) ),
				self::money( $monthly ),
				array(
					'v' => $tr_cum,
					'f' => 0 === $i ? '$F$' . $pre . '+E{r}' : 'F{p}+E{r}',
				),
				array(
					'v' => $i >= $from ? __( 'Sí', 'gestion-de-proyectos' ) : __( 'No', 'gestion-de-proyectos' ),
					's' => 'center',
				),
				self::money( (float) ( $spend[ $m ] ?? 0 ) ),
			);
			if ( null !== $plan_cum ) {
				$cells[] = array(
					'v' => (float) $plan_cum,
					'f' => $started ? 'I{p}+H{r}' : '$F$' . ( $pre + 2 ) . '+H{r}',
				);
				$started = true;
			} else {
				$cells[] = '';
			}
			$cells[] = $past ? self::money( (float) ( $paid[ $m ] ?? 0 ) ) : '';
			$cells[] = null !== $pay_cum ? array(
				'v' => (float) $pay_cum,
				'f' => 0 === $i ? '$F$' . ( $pre + 1 ) . '+J{r}' : 'K{p}+J{r}',
			) : '';
			$cells[] = null !== $pay_cum ? array(
				'v' => round( $real_cum - (float) $pay_cum, 2 ),
				'f' => '$F$' . $pre . '+SUM(C${first}:C{r})-K{r}',
			) : '';
			$rows[]  = $cells;
		}

		return self::table(
			$ctx,
			$ctx['names']['cash'],
			__( 'Caja del Fondo mes a mes', 'gestion-de-proyectos' ),
			array(
				array( __( 'Mes', 'gestion-de-proyectos' ), 20, '' ),
				array( __( 'Período', 'gestion-de-proyectos' ), 9, '' ),
				array( __( 'Transferido en el mes', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Transferencia programada', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Transferencia considerada (real hasta hoy, programada después)', 'gestion-de-proyectos' ), 17, 'money' ),
				array( __( 'Transferido acumulado', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Proyectado', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Gasto programado', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Gasto programado acumulado', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Pagado en el mes', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Pagado acumulado', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Caja real al cierre (transferido real acumulado menos pagado acumulado)', 'gestion-de-proyectos' ), 18, 'money' ),
			),
			$rows,
			array(
				'preamble' => $preamble,
				'filter'   => false,
				'empty'    => __( 'Sin meses que mostrar: falta el plazo del convenio.', 'gestion-de-proyectos' ),
			)
		);
	}

	/**
	 * Programación de caja vigente frente a lo real.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function plan( array $data, array $ctx ): array {
		$cash = $data['status']['cash_plan'] ?? null;
		if ( ! is_array( $cash ) ) {
			return self::table( $ctx, $ctx['names']['plan'], __( 'Programación de caja', 'gestion-de-proyectos' ), array( array( __( 'Mes', 'gestion-de-proyectos' ), 20, '' ) ), array(), array( 'empty' => __( 'No hay una programación de caja registrada.', 'gestion-de-proyectos' ) ) );
		}
		$p        = (array) $cash['plan'];
		$received = (array) ( $data['received'] ?? array() );
		$paid     = (array) ( $data['paid_by_month']['fondo'] ?? array() );
		$preamble = array(
			self::preamble( __( 'Programación', 'gestion-de-proyectos' ), (string) $p['name'], 2 ),
			self::preamble( __( 'Estado', 'gestion-de-proyectos' ), self::plan_status( (string) $p['status'] ), 2 ),
			self::preamble( __( 'Enviada el', 'gestion-de-proyectos' ), self::date( (string) ( $p['submitted_at'] ?? '' ) ), 2 ),
		);
		$rows     = array();
		foreach ( (array) $cash['rows'] as $r ) {
			$m      = (string) $r['period'];
			$past   = $m <= $ctx['current'];
			$rows[] = array(
				BoardMetrics::month_label( $m ),
				$m,
				self::money( (float) $r['transfer'] ),
				$past ? self::money( (float) ( $received[ $m ] ?? 0 ) ) : '',
				self::money( (float) $r['spend'] ),
				$past ? self::money( (float) ( $paid[ $m ] ?? 0 ) ) : '',
				self::money( (float) $r['cash'] ),
				(string) $r['milestone'],
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['plan'],
			__( 'Programación de caja vigente frente a lo real', 'gestion-de-proyectos' ),
			array(
				array( __( 'Mes', 'gestion-de-proyectos' ), 20, '' ),
				array( __( 'Período', 'gestion-de-proyectos' ), 9, '' ),
				array( __( 'Transferencia programada', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Transferido real', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Gasto programado', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Pagado real (Fondo)', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Aporte pecuniario programado', 'gestion-de-proyectos' ), 16, 'money' ),
				array( __( 'Hito que lo respalda', 'gestion-de-proyectos' ), 80, 'wrap' ),
			),
			$rows,
			array(
				'preamble' => $preamble,
				'totals'   => array(
					0 => __( 'Total', 'gestion-de-proyectos' ),
					2 => 'sum',
					3 => 'sum',
					4 => 'sum',
					5 => 'sum',
					6 => 'sum',
				),
				'empty'    => __( 'La programación no tiene meses.', 'gestion-de-proyectos' ),
			)
		);
	}

	/**
	 * Controles de la programación de caja.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function plan_checks( array $data, array $ctx ): array {
		$cash = $data['status']['cash_plan'] ?? null;
		$rows = array();
		foreach ( is_array( $cash ) ? (array) $cash['checks'] : array() as $c ) {
			$rows[] = array( (string) $c['key'], BoardMetrics::display_text( (string) $c['label'] ), self::state( $c['ok'] ?? null, __( 'no evaluable', 'gestion-de-proyectos' ) ), BoardMetrics::display_text( (string) $c['detail'] ) );
		}

		return self::table(
			$ctx,
			$ctx['names']['checks'],
			__( 'Controles de la programación de caja', 'gestion-de-proyectos' ),
			array(
				array( __( 'Control', 'gestion-de-proyectos' ), 9, '' ),
				array( __( 'Qué verifica', 'gestion-de-proyectos' ), 60, 'wrap' ),
				array( __( 'Resultado', 'gestion-de-proyectos' ), 14, '' ),
				array( __( 'Detalle', 'gestion-de-proyectos' ), 90, 'wrap' ),
			),
			$rows,
			array( 'empty' => __( 'No hay una programación de caja registrada.', 'gestion-de-proyectos' ) )
		);
	}

	/**
	 * Cartola del centro de costo.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function ledger( array $data, array $ctx ): array {
		$labels   = (array) ( $ctx['labels']['ledger'] ?? array() );
		$codes    = array();
		$styles   = array(
			'conciliado'  => 'ok',
			'por_aclarar' => 'warn',
			'excluido'    => 'muted',
		);
		$preamble = array();
		$pre      = self::PREAMBLE_ROW;
		foreach ( (array) $data['payments'] as $p ) {
			$codes[ (int) $p['id'] ] = (string) $p['code'];
		}
		foreach ( array( 'fondo', 'pecuniario' ) as $src ) {
			$s     = (array) ( $data['status']['sources'][ $src ] ?? array() );
			$label = (string) ( $s['label'] ?? $src );
			$row   = $pre + count( $preamble );
			// Los valores van en la columna E (cargos), para que el rótulo se lea completo.
			$preamble[] = self::preamble(
				/* translators: fuente. */
				sprintf( __( '%s: saldo calculado', 'gestion-de-proyectos' ), $label ),
				self::money( (float) ( $s['balance'] ?? 0 ) ),
				4
			);
			if ( (int) ( $s['ledger_count'] ?? 0 ) > 0 ) {
				$preamble[] = self::preamble(
					/* translators: fuente. */
					sprintf( __( '%s: saldo de la cartola', 'gestion-de-proyectos' ), $label ),
					self::money( (float) ( $s['ledger_balance'] ?? 0 ) ),
					4
				);
				$preamble[] = self::preamble(
					/* translators: fuente. */
					sprintf( __( '%s: diferencia (cartola menos calculado)', 'gestion-de-proyectos' ), $label ),
					array(
						'v' => round( (float) ( $s['ledger_balance'] ?? 0 ) - (float) ( $s['balance'] ?? 0 ), 2 ),
						'f' => 'E' . ( $row + 1 ) . '-E' . $row,
						's' => 'money',
					),
					4
				);
				$preamble[] = self::preamble(
					/* translators: fuente. */
					sprintf( __( '%s: movimientos por aclarar', 'gestion-de-proyectos' ), $label ),
					array(
						'v' => (int) ( $s['unmatched'] ?? 0 ),
						's' => 'code',
					),
					4
				);
			} else {
				$preamble[] = array(
					array(
						'v' => sprintf( /* translators: fuente. */ __( '%s: sin movimientos de la cartola importados', 'gestion-de-proyectos' ), $label ),
						's' => 'note',
					),
				);
			}
		}
		$rows = array();
		foreach ( (array) ( $data['ledger'] ?? array() ) as $l ) {
			$status = (string) ( $l['status'] ?? '' );
			$rows[] = array(
				self::date( (string) $l['entry_date'] ),
				(string) ( $ctx['sources'][ $l['source'] ] ?? $l['source'] ),
				self::code( (string) ( $l['reference'] ?? '' ) ),
				(string) ( $l['description'] ?? '' ),
				self::money( (float) ( $l['debit'] ?? 0 ) ),
				self::money( (float) ( $l['credit'] ?? 0 ) ),
				(string) ( $labels['kinds'][ $l['kind'] ?? '' ] ?? ( $l['kind'] ?? '' ) ),
				(string) ( $codes[ (int) ( $l['payment_id'] ?? 0 ) ] ?? '' ),
				(int) ( $l['installment_no'] ?? 0 ) > 0 ? (int) $l['installment_no'] : '',
				array(
					'v' => (string) ( $labels['statuses'][ $status ] ?? $status ),
					's' => $styles[ $status ] ?? '',
				),
				(string) ( $l['batch'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['ledger'],
			__( 'Cartola del centro de costo y conciliación', 'gestion-de-proyectos' ),
			array(
				array( __( 'Fecha', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Fuente', 'gestion-de-proyectos' ), 18, '' ),
				array( __( 'Referencia', 'gestion-de-proyectos' ), 14, '' ),
				array( __( 'Descripción', 'gestion-de-proyectos' ), 50, 'wrap' ),
				array( __( 'Cargo', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Abono', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Tipo', 'gestion-de-proyectos' ), 24, 'wrap' ),
				array( __( 'Pago emparejado', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Cuota', 'gestion-de-proyectos' ), 7, 'num' ),
				array( __( 'Estado', 'gestion-de-proyectos' ), 13, '' ),
				array( __( 'Lote de importación', 'gestion-de-proyectos' ), 18, '' ),
			),
			$rows,
			array(
				'preamble' => $preamble,
				'totals'   => array(
					0 => __( 'Total', 'gestion-de-proyectos' ),
					4 => 'sum',
					5 => 'sum',
				),
				'empty'    => __( 'Sin movimientos de la cartola importados.', 'gestion-de-proyectos' ),
			)
		);
	}

	// ------------------------------------------------------------------ Convenio

	/**
	 * Convenio y financiamiento.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function agreement( array $data, array $ctx ): array {
		$status = (array) $data['status'];
		$a      = (array) $status['agreement'];
		$rows   = array(
			array(
				array(
					'v' => __( 'Convenio', 'gestion-de-proyectos' ),
					's' => 'title',
				),
			),
			array(
				array(
					'v' => $ctx['subtitle'],
					's' => 'subtitle',
				),
			),
			array(),
		);
		$pairs  = array(
			array( __( 'Otorgante', 'gestion-de-proyectos' ), (string) ( $a['funder'] ?? '' ) ),
			array( __( 'Programa', 'gestion-de-proyectos' ), (string) ( $a['program'] ?? '' ) ),
			array( __( 'Fecha del convenio', 'gestion-de-proyectos' ), self::date( (string) ( $a['agreement_date'] ?? '' ) ) ),
			array( __( 'Acto aprobatorio', 'gestion-de-proyectos' ), (string) ( $a['approval_act'] ?? '' ) ),
			array( __( 'Fecha del acto aprobatorio', 'gestion-de-proyectos' ), self::date( (string) ( $a['approval_date'] ?? '' ) ) ),
			array( __( 'Inicio del plazo de ejecución', 'gestion-de-proyectos' ), self::date( (string) ( $a['start_date'] ?? '' ) ) ),
			array( __( 'Término del plazo de ejecución', 'gestion-de-proyectos' ), self::date( (string) ( $a['end_date'] ?? '' ) ) ),
			array(
				__( 'Meses de ejecución', 'gestion-de-proyectos' ),
				(int) ( $a['months'] ?? 0 ) > 0 ? array(
					'v' => (int) $a['months'],
					's' => 'code',
				) : '',
			),
			array( __( 'Plataforma de rendición', 'gestion-de-proyectos' ), (string) ( $a['platform'] ?? '' ) ),
			array( __( 'Código del proyecto en la plataforma', 'gestion-de-proyectos' ), (string) ( $a['platform_code'] ?? '' ) ),
			array( __( 'Término en la plataforma', 'gestion-de-proyectos' ), self::date( (string) ( $a['platform_end_date'] ?? '' ) ) ),
			array( __( 'Rendición en la plataforma hasta', 'gestion-de-proyectos' ), self::date( (string) ( $a['platform_render_until'] ?? '' ) ) ),
			array( __( 'Centro de costo', 'gestion-de-proyectos' ), (string) ( $a['cost_center'] ?? '' ) ),
			array( __( 'Garantía de fiel cumplimiento exigida', 'gestion-de-proyectos' ), ! empty( $a['guarantee_required'] ) ? __( 'Sí', 'gestion-de-proyectos' ) : __( 'No', 'gestion-de-proyectos' ) ),
			array( __( 'Perfil de reglas', 'gestion-de-proyectos' ), (string) ( $status['profile_label'] ?? '' ) ),
		);
		foreach ( $pairs as $p ) {
			$rows[] = self::pair( $p[0], $p[1] );
		}
		$rows[]  = array();
		$rows[]  = array(
			array(
				'v' => __( 'Financiamiento por fuente', 'gestion-de-proyectos' ),
				's' => 'section',
			),
		);
		$first   = count( $rows ) + 1;
		$fund    = (float) ( $a['fund_amount'] ?? 0 );
		$cash    = (float) ( $a['cash_amount'] ?? 0 );
		$inkind  = (float) ( $a['inkind_amount'] ?? 0 );
		$rows[]  = self::pair( __( 'Fondo', 'gestion-de-proyectos' ), self::money( $fund ) );
		$rows[]  = self::pair( __( 'Aporte pecuniario de la universidad', 'gestion-de-proyectos' ), self::money( $cash ) );
		$rows[]  = self::pair( __( 'Aporte no pecuniario de la universidad', 'gestion-de-proyectos' ), self::money( $inkind ) );
		$last    = count( $rows );
		$total_r = $last + 1;
		$rows[]  = array(
			array(
				'v' => __( 'Costo total del proyecto', 'gestion-de-proyectos' ),
				's' => 'total',
			),
			array(
				'v' => $fund + $cash + $inkind,
				'f' => "SUM(B{$first}:B{$last})",
				's' => 'total_money',
			),
		);
		$rows[]  = self::pair(
			__( 'Participación del Fondo en el costo total', 'gestion-de-proyectos' ),
			array(
				'v' => self::ratio( $fund, $fund + $cash + $inkind ),
				'f' => "IF(B{$total_r}>0,B{$first}/B{$total_r},0)",
				's' => 'pct',
			)
		);
		$rows[]  = array();
		$rows[]  = self::pair(
			__( 'Reitemizaciones usadas', 'gestion-de-proyectos' ),
			array(
				'v' => (int) ( $status['reitemizations']['used'] ?? 0 ),
				's' => 'code',
			)
		);
		$rows[]  = self::pair(
			__( 'Reitemizaciones permitidas', 'gestion-de-proyectos' ),
			array(
				'v' => (int) ( $status['reitemizations']['max'] ?? 0 ),
				's' => 'code',
			)
		);
		if ( '' !== trim( (string) ( $a['notes'] ?? '' ) ) ) {
			$rows[] = array();
			$rows[] = self::pair(
				__( 'Notas del convenio', 'gestion-de-proyectos' ),
				array(
					'v' => (string) $a['notes'],
					's' => 'wrap',
				)
			);
		}

		return array(
			'name'      => $ctx['names']['agreement'],
			'columns'   => self::widths( array( 44, 90 ) ),
			'rows'      => $rows,
			'gridlines' => false,
		);
	}

	/**
	 * Modificaciones del convenio.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function modifications( array $data, array $ctx ): array {
		$labels = (array) ( $ctx['labels']['modifications'] ?? array() );
		$rows   = array();
		foreach ( (array) $data['modifications'] as $m ) {
			$status = (string) $m['status'];
			$rows[] = array(
				(string) ( $labels['kinds'][ $m['kind'] ] ?? $m['kind'] ),
				array(
					'v' => (string) ( $labels['statuses'][ $status ] ?? $status ),
					's' => 'aprobada' === $status ? 'ok' : ( 'rechazada' === $status ? 'bad' : '' ),
				),
				self::date( (string) ( $m['requested_at'] ?? '' ) ),
				self::date( (string) ( $m['approved_at'] ?? '' ) ),
				(string) ( $m['act_number'] ?? '' ),
				(string) ( $m['notes'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['mods'],
			__( 'Modificaciones del convenio', 'gestion-de-proyectos' ),
			array(
				array( __( 'Tipo', 'gestion-de-proyectos' ), 30, 'wrap' ),
				array( __( 'Estado', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Solicitada el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Aprobada el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Acto', 'gestion-de-proyectos' ), 26, 'wrap' ),
				array( __( 'Detalle', 'gestion-de-proyectos' ), 100, 'wrap' ),
			),
			$rows,
			array( 'empty' => __( 'Sin modificaciones registradas.', 'gestion-de-proyectos' ) )
		);
	}

	/**
	 * Garantías.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function guarantees( array $data, array $ctx ): array {
		$labels = (array) ( $ctx['labels']['guarantees'] ?? array() );
		$rows   = array();
		foreach ( (array) $data['guarantees'] as $g ) {
			$status = (string) $g['status'];
			$rows[] = array(
				(string) ( $labels['kinds'][ $g['kind'] ] ?? $g['kind'] ),
				(string) ( $labels['instruments'][ $g['instrument'] ] ?? $g['instrument'] ),
				self::code( (string) ( $g['number'] ?? '' ) ),
				(string) ( $g['issuer'] ?? '' ),
				self::money( (float) ( $g['amount'] ?? 0 ) ),
				self::date( (string) ( $g['issued_at'] ?? '' ) ),
				self::date( (string) ( $g['valid_until'] ?? '' ) ),
				(int) ( $g['installment_no'] ?? 0 ) > 0 ? (int) $g['installment_no'] : '',
				array(
					'v' => (string) ( $labels['statuses'][ $status ] ?? $status ),
					's' => 'vencida' === $status ? 'bad' : '',
				),
				(string) ( $g['notes'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['guarantees'],
			__( 'Garantías', 'gestion-de-proyectos' ),
			array(
				array( __( 'Tipo', 'gestion-de-proyectos' ), 28, 'wrap' ),
				array( __( 'Instrumento', 'gestion-de-proyectos' ), 18, '' ),
				array( __( 'Número', 'gestion-de-proyectos' ), 14, '' ),
				array( __( 'Emisor', 'gestion-de-proyectos' ), 26, 'wrap' ),
				array( __( 'Monto', 'gestion-de-proyectos' ), 15, 'money' ),
				array( __( 'Emitida el', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Vigente hasta', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Cuota', 'gestion-de-proyectos' ), 7, 'num' ),
				array( __( 'Estado', 'gestion-de-proyectos' ), 12, '' ),
				array( __( 'Notas', 'gestion-de-proyectos' ), 60, 'wrap' ),
			),
			$rows,
			array(
				'totals' => array(
					0 => __( 'Total', 'gestion-de-proyectos' ),
					4 => 'sum',
				),
				'empty'  => ! empty( $data['status']['agreement']['guarantee_required'] ) ? __( 'El convenio exige garantía de fiel cumplimiento, pero no hay ninguna registrada.', 'gestion-de-proyectos' ) : __( 'Sin garantías registradas.', 'gestion-de-proyectos' ),
			)
		);
	}

	/**
	 * Reglas vigentes.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,mixed>
	 */
	private static function rules( array $data, array $ctx ): array {
		$rows = array();
		foreach ( (array) $data['status']['rules'] as $key => $r ) {
			$own    = 'convenio' === ( $r['layer'] ?? '' );
			$rows[] = array(
				(string) $key,
				(string) ( $r['label'] ?? $key ),
				self::rule_value( (string) ( $r['value'] ?? '' ) ),
				(string) ( $r['source'] ?? '' ),
				$own ? array(
					'v' => __( 'Proyecto', 'gestion-de-proyectos' ),
					's' => 'ok',
				) : __( 'Perfil', 'gestion-de-proyectos' ),
				self::date( (string) ( $r['valid_from'] ?? '' ) ),
				self::date( (string) ( $r['valid_to'] ?? '' ) ),
				(string) ( $r['note'] ?? '' ),
			);
		}

		return self::table(
			$ctx,
			$ctx['names']['rules'],
			__( 'Reglas vigentes', 'gestion-de-proyectos' ),
			array(
				array( __( 'Clave', 'gestion-de-proyectos' ), 26, '' ),
				array( __( 'Regla', 'gestion-de-proyectos' ), 46, 'wrap' ),
				array( __( 'Valor', 'gestion-de-proyectos' ), 18, 'wrap' ),
				array( __( 'Fuente', 'gestion-de-proyectos' ), 60, 'wrap' ),
				array( __( 'Origen', 'gestion-de-proyectos' ), 11, '' ),
				array( __( 'Vigente desde', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Vigente hasta', 'gestion-de-proyectos' ), 12, 'date' ),
				array( __( 'Nota', 'gestion-de-proyectos' ), 50, 'wrap' ),
			),
			$rows,
			array( 'empty' => __( 'Sin reglas.', 'gestion-de-proyectos' ) )
		);
	}

	// ------------------------------------------------------------------ Auxiliares

	/**
	 * Hoja de tabla: título, subtítulo, preámbulo opcional, encabezado, datos y fila de totales.
	 *
	 * En las fórmulas de los datos, {r} es la fila de la planilla, {p} la
	 * anterior, {first} y {last} la primera y la última de los datos.
	 *
	 * @param array<string,mixed>                             $ctx     Contexto.
	 * @param string                                          $name    Nombre de la hoja.
	 * @param string                                          $title   Título.
	 * @param array<int,array{0:string,1:int|float,2:string}> $columns Encabezado, ancho y formato.
	 * @param array<int,array<int,mixed>>                     $rows    Filas de datos.
	 * @param array<string,mixed>                             $opts    preamble, totals, freeze_cols, filter, empty.
	 * @return array<string,mixed>
	 */
	private static function table( array $ctx, string $name, string $title, array $columns, array $rows, array $opts = array() ): array {
		$preamble = array_values( (array) ( $opts['preamble'] ?? array() ) );
		$header   = self::header_index( count( $preamble ) );
		$out      = array(
			array(
				array(
					'v' => $title,
					's' => 'title',
				),
			),
			array(
				array(
					'v' => $ctx['subtitle'],
					's' => 'subtitle',
				),
			),
			array(),
		);
		if ( $preamble ) {
			foreach ( $preamble as $p ) {
				$out[] = $p;
			}
			$out[] = array();
		}
		$out[] = array_map( static fn( array $c ): string => (string) $c[0], $columns );
		$first = $header + 2;
		$empty = ! $rows;
		if ( $empty ) {
			$rows = array(
				array(
					array(
						'v' => (string) ( $opts['empty'] ?? __( 'Sin registros.', 'gestion-de-proyectos' ) ),
						's' => 'note',
					),
				),
			);
		}
		$last = $first + count( $rows ) - 1;
		foreach ( array_values( $rows ) as $i => $row ) {
			$r     = $first + $i;
			$out[] = array_map(
				static function ( $cell ) use ( $r, $first, $last ) {
					if ( is_array( $cell ) && isset( $cell['f'] ) ) {
						$cell['f'] = strtr(
							(string) $cell['f'],
							array(
								'{r}'     => (string) $r,
								'{p}'     => (string) ( $r - 1 ),
								'{first}' => (string) $first,
								'{last}'  => (string) $last,
							)
						);
					}
					return $cell;
				},
				array_values( (array) $row )
			);
		}
		$totals = (array) ( $opts['totals'] ?? array() );
		if ( $totals && ! $empty ) {
			$line = array();
			foreach ( array_keys( $columns ) as $c ) {
				$spec   = $totals[ $c ] ?? null;
				$format = (string) $columns[ $c ][2];
				$style  = array(
					'money' => 'total_money',
					'int'   => 'total_int',
					'num'   => 'total_int',
					'pct'   => 'total_pct',
				)[ $format ] ?? 'total';
				if ( 'sum' === $spec ) {
					$sum = 0.0;
					foreach ( $rows as $row ) {
						$cell = array_values( (array) $row )[ $c ] ?? null;
						$val  = is_array( $cell ) ? ( $cell['v'] ?? null ) : $cell;
						$sum += is_int( $val ) || is_float( $val ) ? (float) $val : 0.0;
					}
					$col    = Workbook::column( $c );
					$line[] = array(
						'v' => in_array( $format, array( 'int', 'num' ), true ) ? (int) round( $sum ) : round( $sum, 2 ),
						'f' => "SUBTOTAL(109,{$col}{$first}:{$col}{$last})",
						's' => $style,
					);
				} elseif ( is_string( $spec ) ) {
					$line[] = array(
						'v' => $spec,
						's' => 'total',
					);
				} else {
					$line[] = array(
						'v' => '',
						's' => $style,
					);
				}
			}
			$out[] = $line;
		}

		return array(
			'name'        => $name,
			'columns'     => array_map(
				static fn( array $c ): array => array(
					'width'  => $c[1],
					'format' => $c[2],
				),
				$columns
			),
			'rows'        => $out,
			'header'      => $header,
			'freeze'      => true,
			'freeze_cols' => (int) ( $opts['freeze_cols'] ?? 0 ),
			'filter'      => ! $empty && ( ! isset( $opts['filter'] ) || $opts['filter'] ),
			'filter_last' => $last - 1,
		);
	}

	/**
	 * Índice (desde 0) de la fila de encabezado de una hoja de tabla.
	 *
	 * @param int $preamble Filas del preámbulo.
	 * @return int
	 */
	private static function header_index( int $preamble ): int {
		return 3 + ( $preamble > 0 ? $preamble + 1 : 0 );
	}

	/**
	 * Clave de cada rendición, como figura en la hoja de pagos ("junio de 2026 · mensual").
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<int,string>
	 */
	private static function rendition_keys( array $data, array $ctx ): array {
		$out = array();
		foreach ( (array) $data['renditions'] as $r ) {
			$key = BoardMetrics::month_label( (string) $r['period'] ) . ' · ' . BoardMetrics::rendition_kind_label( (string) $r['kind'] );
			if ( 'fondo' !== $r['source'] ) {
				$key .= ' · ' . (string) ( $ctx['sources'][ $r['source'] ] ?? $r['source'] );
			}
			$out[ (int) $r['id'] ] = $key;
		}

		return $out;
	}

	/**
	 * Referencias legibles de las entidades de los eventos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @param array<string,mixed> $ctx  Contexto.
	 * @return array<string,array<int,string>>
	 */
	private static function entity_refs( array $data, array $ctx ): array {
		$refs = array(
			'rendition'   => self::rendition_keys( $data, $ctx ),
			'installment' => array(),
			'payment'     => array(),
			'supplier'    => array(),
			'guarantee'   => array(),
		);
		foreach ( (array) $data['status']['installments'] as $i ) {
			$refs['installment'][ (int) $i['id'] ] = sprintf( /* translators: número. */ __( 'Cuota %d', 'gestion-de-proyectos' ), (int) $i['number'] );
		}
		foreach ( (array) $data['payments'] as $p ) {
			$refs['payment'][ (int) $p['id'] ] = (string) $p['code'] . ' · ' . (string) $p['description'];
		}
		foreach ( (array) $data['suppliers'] as $id => $s ) {
			if ( is_array( $s ) ) {
				$refs['supplier'][ (int) $id ] = (string) $s['name'];
			}
		}
		$glabels = (array) ( $ctx['labels']['guarantees'] ?? array() );
		foreach ( (array) $data['guarantees'] as $g ) {
			$refs['guarantee'][ (int) $g['id'] ] = trim( (string) ( $glabels['kinds'][ $g['kind'] ] ?? $g['kind'] ) . ' ' . (string) ( $g['number'] ?? '' ) );
		}

		return $refs;
	}

	/**
	 * Estado de una programación de caja.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	private static function plan_status( string $status ): string {
		$labels = array(
			'borrador'    => __( 'Borrador', 'gestion-de-proyectos' ),
			'enviada'     => __( 'Enviada', 'gestion-de-proyectos' ),
			'vigente'     => __( 'Vigente', 'gestion-de-proyectos' ),
			'reemplazada' => __( 'Reemplazada', 'gestion-de-proyectos' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Lectura de "rendir el 100 %" que aplica la condición de giro.
	 *
	 * @param string $criterion presentado, aprobado o ambos.
	 * @return string
	 */
	private static function criterion( string $criterion ): string {
		$labels = array(
			'presentado' => __( 'Basta con lo rendido (presentado)', 'gestion-de-proyectos' ),
			'aprobado'   => __( 'Se exige lo rendido aprobado', 'gestion-de-proyectos' ),
			'ambos'      => __( 'Se muestran las dos lecturas, rendido y aprobado, hasta que la contraparte la precise', 'gestion-de-proyectos' ),
		);

		return $labels[ $criterion ] ?? $criterion;
	}

	/**
	 * Valor de una regla: número entero o fecha cuando lo es; si no, texto.
	 *
	 * @param string $value Valor.
	 * @return mixed
	 */
	private static function rule_value( string $value ) {
		$value = trim( $value );
		if ( preg_match( '/^-?(0|[1-9]\d{0,14})$/', $value ) ) {
			return array(
				'v' => (int) $value,
				's' => 'thousands',
			);
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && null !== Workbook::date_serial( $value ) ) {
			return array(
				'v' => $value,
				's' => 'date',
			);
		}

		return $value;
	}

	/**
	 * Número de documento, egreso o referencia: como número si son solo
	 * cifras sin ceros a la izquierda (así la planilla no lo marca como
	 * número guardado como texto); si no, como texto.
	 *
	 * @param string $code Código.
	 * @return mixed
	 */
	private static function code( string $code ) {
		$code = trim( $code );

		return preg_match( '/^[1-9]\d{0,14}$/', $code ) ? array(
			'v' => (int) $code,
			's' => 'code',
		) : $code;
	}

	/**
	 * Texto en minúsculas y sin tildes, para comparar rótulos.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function fold( string $text ): string {
		return strtr(
			mb_strtolower( trim( $text ) ),
			array(
				'á' => 'a',
				'é' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
			)
		);
	}

	/**
	 * Texto con la primera letra en mayúscula.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function capital( string $text ): string {
		return '' === $text ? '' : mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * Etapa de un pago para agrupar: por pagar o pagado.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	private static function stage( string $status ): string {
		return in_array( $status, array( 'comprometido', 'devengado' ), true ) ? __( 'Por pagar', 'gestion-de-proyectos' ) : __( 'Pagado', 'gestion-de-proyectos' );
	}

	/**
	 * Celda de estado de una condición o control.
	 *
	 * @param bool|null $ok   Resultado.
	 * @param string    $none Texto sin evaluación.
	 * @return array<string,string>
	 */
	private static function state( ?bool $ok, string $none ): array {
		if ( null === $ok ) {
			return array(
				'v' => $none,
				's' => 'muted',
			);
		}

		return $ok ? array(
			'v' => __( 'Cumple', 'gestion-de-proyectos' ),
			's' => 'ok',
		) : array(
			'v' => __( 'No cumple', 'gestion-de-proyectos' ),
			's' => 'bad',
		);
	}

	/**
	 * Fila de rótulo y valor.
	 *
	 * @param string $label Rótulo.
	 * @param mixed  $value Valor o celda.
	 * @return array<int,mixed>
	 */
	private static function pair( string $label, $value ): array {
		return array(
			array(
				'v' => $label,
				's' => 'bold',
			),
			$value,
		);
	}

	/**
	 * Fila del preámbulo de una hoja de tabla: el rótulo en la columna A y el
	 * valor en la columna indicada, con celdas vacías entre ambos para que el
	 * rótulo se lea completo.
	 *
	 * @param string $label Rótulo.
	 * @param mixed  $value Valor o celda.
	 * @param int    $col   Columna del valor (desde 0).
	 * @return array<int,mixed>
	 */
	private static function preamble( string $label, $value, int $col ): array {
		return array_merge(
			array(
				array(
					'v' => $label,
					's' => 'bold',
				),
			),
			array_fill( 0, max( 0, $col - 1 ), '' ),
			array( $value )
		);
	}

	/**
	 * Fila de encabezado dentro de una hoja de bloques.
	 *
	 * @param string[] $labels Rótulos.
	 * @return array<int,array<string,string>>
	 */
	private static function header_row( array $labels ): array {
		return array_map(
			static fn( string $l ): array => array(
				'v' => $l,
				's' => 'header',
			),
			$labels
		);
	}

	/**
	 * Celda de monto.
	 *
	 * @param float $amount Monto.
	 * @return array<string,mixed>
	 */
	private static function money( float $amount ): array {
		return array(
			'v' => round( $amount, 2 ),
			's' => 'money',
		);
	}

	/**
	 * Celda de fecha ('' si no hay fecha válida).
	 *
	 * @param string $date Fecha AAAA-MM-DD.
	 * @return array<string,mixed>|string
	 */
	private static function date( string $date ) {
		return null !== Workbook::date_serial( $date ) ? array(
			'v' => substr( $date, 0, 10 ),
			's' => 'date',
		) : '';
	}

	/**
	 * Cociente acotado (0 si el divisor no es positivo).
	 *
	 * @param float $part  Parte.
	 * @param float $whole Total.
	 * @return float
	 */
	private static function ratio( float $part, float $whole ): float {
		return $whole > 0 ? round( $part / $whole, 6 ) : 0.0;
	}

	/**
	 * Vínculo a otra hoja desde la celda indicada.
	 *
	 * @param int    $row   Fila de la planilla.
	 * @param string $sheet Hoja de destino.
	 * @param string $col   Columna de la celda.
	 * @return array<string,string>
	 */
	private static function link( int $row, string $sheet, string $col = 'A' ): array {
		return array(
			'ref'      => $col . $row,
			'location' => Workbook::quote_sheet( $sheet ) . '!A1',
			'display'  => $sheet,
		);
	}

	/**
	 * Texto como criterio literal de una fórmula (comillas dobladas; los
	 * comodines y operadores iniciales se anulan con una tilde).
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function text_criterion( string $text ): string {
		$text = (string) preg_replace( '/([*?~])/', '~$1', $text );
		if ( preg_match( '/^[<>=]/', $text ) ) {
			$text = '=' . $text;
		}

		return str_replace( '"', '""', $text );
	}

	/**
	 * Anchos de columna.
	 *
	 * @param array<int,int|float> $widths Anchos.
	 * @return array<int,array<string,int|float>>
	 */
	private static function widths( array $widths ): array {
		return array_map( static fn( $w ): array => array( 'width' => $w ), $widths );
	}
}
