<?php
/**
 * Ayuda del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

use GDP\Modules\Finance\Assistant;
use GDP\Modules\Finance\Logic\BoardMetrics;

defined( 'ABSPATH' ) || exit;

/**
 * Tres capas de ayuda: la explicación breve de cada cifra (junto a ella),
 * los temas del menú de ayuda (cómo leer el tablero, el ciclo mensual con
 * las fechas de este proyecto, qué falta para la cuota siguiente, estados,
 * respaldos por ítem, qué reporte usar y un glosario) y el selector de
 * tareas, que lleva a la hoja de ejecución de la guía con los datos ya
 * resueltos o a la pestaña que corresponde. Los plazos se leen de las reglas
 * vigentes del convenio, no de valores fijos.
 */
final class BoardHelp {

	/**
	 * Explicaciones breves de las cifras, por clave.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return array<string,string>
	 */
	public static function tips( array $data ): array {
		$rule = self::rule_reader( $data );

		return array(
			'convenio'      => __( 'Monto total que el Gobierno Regional transfiere al proyecto según el convenio vigente, en cuotas. Solo cambia con una modificación aprobada.', 'gestion-de-proyectos' ),
			'transferido'   => __( 'Suma de las cuotas recibidas (con fecha de transferencia o de ingreso). El porcentaje es sobre el monto del convenio.', 'gestion-de-proyectos' ),
			'pagado'        => __( 'Pagos con comprobante de egreso con cargo al Fondo, en cualquier estado posterior al pago: pagado, rendido, aprobado, observado, corregido o rechazado.', 'gestion-de-proyectos' ),
			'comprometido'  => __( 'Obligaciones aún no pagadas: comprometidas (contrato u orden de compra) o devengadas (servicio prestado o documento emitido, sin pago). Son la primera fuente para cerrar la brecha de la cuota siguiente.', 'gestion-de-proyectos' ),
			'saldo'         => __( 'Transferido menos pagado menos reintegrado: el dinero del Fondo que debería estar en el centro de costo. Se contrasta con la cartola en la pestaña Caja.', 'gestion-de-proyectos' ),
			'rendido'       => __( 'Pagos presentados al Gobierno Regional en SISREC (rendidos, aprobados, observados o corregidos). Lo aprobado es lo que el otorgante dio por bueno; lo observado debe aclararse o corregirse.', 'gestion-de-proyectos' ),
			'plazo'         => __( 'Proporción del plazo de ejecución del convenio ya transcurrida. Compararla con la proporción del Fondo usada muestra si el gasto avanza al ritmo del tiempo.', 'gestion-de-proyectos' ),
			'uso'           => __( 'Cada barra suma el total de la fuente: lo pagado, lo comprometido por pagar y lo que queda libre. La marca indica lo recibido hasta hoy; si lo pagado más lo comprometido la supera, esa parte depende de la cuota siguiente.', 'gestion-de-proyectos' ),
			'pecuniario'    => sprintf(
				/* translators: porcentajes por cuota. */
				__( 'Dinero que aporta la universidad, que se entera antes de cada giro según el programa de desembolso (%s por ciento por cuota) y se rinde por separado del Fondo.', 'gestion-de-proyectos' ),
				str_replace( ',', ', ', $rule( 'pecuniario_por_cuota', '20,60,20' ) )
			),
			'no_pecuniario' => __( 'Valorización de recursos propios comprometidos en el convenio (horas del personal de la universidad, infraestructura); no se transfiere ni se paga.', 'gestion-de-proyectos' ),
			'brecha'        => __( 'Lo que falta pagar, rendir y aprobar de las cuotas ya recibidas para habilitar la siguiente: la suma de lo transferido menos lo pagado, rendido o aprobado. Si el criterio es "ambos", se exige lo rendido y lo aprobado.', 'gestion-de-proyectos' ),
			'garantia'      => __( 'Alternativa a rendir el 100 %: un documento de garantía por la fracción no rendida, vigente hasta la fecha proyectada de su rendición total.', 'gestion-de-proyectos' ),
			'ultimo_mes'    => sprintf(
				/* translators: 1: día hábil de la plataforma, 2: días de revisión, 3: días de solicitud. */
				__( 'Último mes cuyos pagos todavía alcanzan a rendirse (al %1$s.º día hábil del mes siguiente), revisarse (unos %2$d días) y dar paso a la solicitud y transferencia (unos %3$d días) antes de la fecha objetivo del giro.', 'gestion-de-proyectos' ),
				$rule( 'plazo_sisrec', '15' ),
				(int) $rule( 'delta_revision', '15' ),
				(int) $rule( 'delta_solicitud', '10' )
			),
			'disponible'    => __( 'Asignado vigente del ítem menos lo pagado, lo comprometido y lo rechazado. Un disponible negativo significa que el ítem ya no admite gastos sin una reitemización.', 'gestion-de-proyectos' ),
			'condiciones'   => __( 'Las siete condiciones que el módulo evalúa antes de solicitar una cuota: rendición o garantía, informe de avance aprobado, aporte pecuniario acreditado, carta de solicitud, rendiciones al día, comprobantes de ingreso enviados y ventana del convenio.', 'gestion-de-proyectos' ),
			'caja'          => __( 'Transferido acumulado (escalonado; en trazo discontinuo, lo que la programación de caja proyecta), gasto programado acumulado y pagado acumulado, mes a mes. La distancia entre lo transferido y lo pagado es el dinero disponible en caja.', 'gestion-de-proyectos' ),
			'rendiciones'   => sprintf(
				/* translators: 1: día hábil interno, 2: día hábil de la plataforma. */
				__( 'Cada mes se rinde en el mes siguiente: respaldos a la universidad hasta el %1$s.º día hábil y carga en SISREC hasta el %2$s.º. Un mes sin gasto también se rinde, sin movimiento. El módulo no lee la plataforma: muestra el estado que se declara.', 'gestion-de-proyectos' ),
				$rule( 'plazo_respaldo_interno', '8' ),
				$rule( 'plazo_sisrec', '15' )
			),
		);
	}

	/**
	 * Tareas del selector "¿Qué necesita hacer?": etiqueta y destino.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return array<string,array{label:string,tab:string,guide:string,entity:string,id:int}>
	 */
	public static function tasks( array $data ): array {
		$status     = (array) $data['status'];
		$renditions = (array) $data['renditions'];
		$find       = static function ( callable $test ) use ( $renditions ): int {
			foreach ( array_reverse( $renditions ) as $r ) {
				if ( $test( $r ) ) {
					return (int) $r['id'];
				}
			}

			return 0;
		};
		$pending    = '';
		foreach ( (array) $status['renditions'] as $t ) {
			if ( empty( $t['submitted'] ) && empty( $t['current'] ) ) {
				$pending = (string) $t['period'];
				break;
			}
		}
		$pending_id = 0;
		foreach ( $renditions as $r ) {
			if ( $pending === (string) $r['period'] && in_array( $r['kind'], array( 'mensual', 'sin_movimiento' ), true ) ) {
				$pending_id = (int) $r['id'];
			}
		}
		$next_id = 0;
		$receipt = 0;
		$next    = $status['next_installment'] ?? null;
		foreach ( (array) $status['installments'] as $i ) {
			if ( is_array( $next ) && (int) $i['number'] === (int) $next['number'] ) {
				$next_id = (int) $i['id'];
			}
			if ( ! empty( $i['is_received'] ) && ( empty( $i['receipt_sent_at'] ) || 'aceptada' !== $i['platform_status'] ) ) {
				$receipt = (int) $i['id'];
			}
		}
		$unregistered = Assistant::unregistered_suppliers( (int) $data['project_id'] );

		$tasks = array();
		if ( '' !== $pending ) {
			$tasks['rendir'] = array(
				/* translators: mes. */
				'label'  => sprintf( __( 'Rendir el mes de %s (el más antiguo pendiente)', 'gestion-de-proyectos' ), BoardMetrics::month_label( $pending ) ),
				'tab'    => 'paso',
				'guide'  => 'respaldos_mensuales',
				'entity' => 'rendition',
				'id'     => $pending_id,
			);
		}
		$tasks['mensual']        = array(
			'label'  => __( 'Ingresar una rendición mensual en SISREC, transacción por transacción', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'rendicion_mensual',
			'entity' => 'rendition',
			'id'     => $pending_id,
		);
		$tasks['masiva']         = array(
			'label'  => __( 'Cargar una rendición con la planilla y el ZIP de carga masiva', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'rendicion_carga_masiva',
			'entity' => 'rendition',
			'id'     => $pending_id,
		);
		$tasks['sin_movimiento'] = array(
			'label'  => __( 'Presentar una rendición sin movimiento (mes sin gasto)', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'rendicion_sin_movimiento',
			'entity' => 'rendition',
			'id'     => $find( static fn( array $r ): bool => 'sin_movimiento' === $r['kind'] && ! in_array( $r['status'], array( 'rendida', 'en_revision', 'aprobada' ), true ) ),
		);
		$tasks['devuelta']       = array(
			'label'  => __( 'Corregir una rendición devuelta u observada', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'corregir_devuelta',
			'entity' => 'rendition',
			'id'     => $find( static fn( array $r ): bool => 'devuelta' === $r['status'] ),
		);
		$tasks['regularizar']    = array(
			'label'  => __( 'Regularizar transacciones de una rendición aprobada parcialmente', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'regularizacion',
			'entity' => 'rendition',
			'id'     => $find( static fn( array $r ): bool => 'aprobada_parcial' === $r['status'] ),
		);
		$tasks['cuota']          = array(
			'label'  => __( 'Solicitar la cuota siguiente', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'solicitar_cuota',
			'entity' => 'installment',
			'id'     => $next_id,
		);
		$tasks['comprobante']    = array(
			'label'  => __( 'Aceptar una transferencia y enviar el comprobante de ingreso', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'aceptar_transferencia',
			'entity' => 'installment',
			'id'     => $receipt,
		);
		$tasks['proveedor']      = array(
			'label'  => __( 'Registrar un proveedor en SISREC', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'registrar_proveedor',
			'entity' => 'supplier',
			'id'     => $unregistered ? (int) $unregistered[0]['id'] : 0,
		);
		$tasks['programacion']   = array(
			'label'  => __( 'Preparar o revisar la programación de caja', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'programacion_caja',
			'entity' => 'project',
			'id'     => 0,
		);
		$tasks['cierre']         = array(
			'label'  => __( 'Rendición final y cierre del proyecto', 'gestion-de-proyectos' ),
			'tab'    => 'paso',
			'guide'  => 'cierre_proyecto',
			'entity' => 'project',
			'id'     => 0,
		);
		$tasks['informe']        = array(
			'label'  => __( 'Preparar un informe del estado financiero (director, reunión o correo)', 'gestion-de-proyectos' ),
			'tab'    => 'reportes',
			'guide'  => '',
			'entity' => '',
			'id'     => 0,
		);
		$tasks['compromisos']    = array(
			'label'  => __( 'Revisar los compromisos por pagar', 'gestion-de-proyectos' ),
			'tab'    => 'pagos',
			'guide'  => '',
			'entity' => 'comprometidos',
			'id'     => 0,
		);
		$tasks['observados']     = array(
			'label'  => __( 'Ver los pagos observados por el Gobierno Regional', 'gestion-de-proyectos' ),
			'tab'    => 'pagos',
			'guide'  => '',
			'entity' => 'observado',
			'id'     => 0,
		);

		return $tasks;
	}

	/**
	 * Temas del menú de ayuda: título y contenido HTML.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return array<int,array{title:string,html:string}>
	 */
	public static function topics( array $data ): array {
		$status  = (array) $data['status'];
		$profile = $data['profile'];
		$rule    = self::rule_reader( $data );
		$topics  = array();

		$topics[] = array(
			'title' => __( 'Cómo leer este tablero', 'gestion-de-proyectos' ),
			'html'  => self::paragraphs(
				array(
					__( 'Resumen reúne lo que el director necesita mirar primero: alertas, plazo transcurrido frente al uso del Fondo, cifras por fuente, cuota siguiente, avance de cuotas e ítems, caja y rendiciones. Las demás pestañas muestran el detalle de cada tema.', 'gestion-de-proyectos' ),
					__( 'El Fondo del Gobierno Regional y el aporte pecuniario de la universidad se muestran siempre por separado, porque se rinden por separado.', 'gestion-de-proyectos' ),
					__( 'En las barras, el tono más oscuro es el dinero más avanzado (pagado antes que comprometido; aprobado antes que rendido). Los colores verde, ámbar y rojo se reservan para estados y siempre van con un símbolo y un texto. Cada gráfico tiene su tabla en "Ver como tabla", y el signo de interrogación junto a una cifra explica cómo se calcula.', 'gestion-de-proyectos' ),
					__( 'El tablero es de solo lectura: los registros (pagos, rendiciones, estados, programación) se hacen en Proyectos → Finanzas del panel. Las cifras se calculan al abrir la página.', 'gestion-de-proyectos' ),
				)
			),
		);

		$cycle    = array(
			sprintf(
				/* translators: 1: día hábil interno, 2: día hábil de la plataforma, 3: días hábiles de subsanación. */
				__( 'Todo pago de un mes se rinde en el mes siguiente: los respaldos completos llegan a la Dirección de Investigación a más tardar el %1$s.º día hábil y la rendición se carga, autentica, firma y envía en SISREC a más tardar el %2$s.º. Si el Gobierno Regional observa, hay %3$s días hábiles para subsanar.', 'gestion-de-proyectos' ),
				$rule( 'plazo_respaldo_interno', '8' ),
				$rule( 'plazo_sisrec', '15' ),
				$rule( 'plazo_subsanacion', '15' )
			),
			__( 'Un mes sin gasto también se rinde: rendición sin movimiento, con carta conductora y carátula de gasto cero. Una sola rendición atrasada bloquea todo giro.', 'gestion-de-proyectos' ),
		);
		$timeline = (array) $status['renditions'];
		$recent   = array_slice( $timeline, -2 );
		$list     = '';
		foreach ( $recent as $t ) {
			/* translators: 1: mes, 2: fecha interna, 3: fecha de la plataforma. */
			$list .= '<li>' . esc_html( sprintf( __( '%1$s: respaldos hasta el %2$s; SISREC hasta el %3$s.', 'gestion-de-proyectos' ), BoardMetrics::month_label( (string) $t['period'] ), BoardMetrics::date( (string) $t['internal_due'] ), BoardMetrics::date( (string) $t['platform_due'] ) ) ) . '</li>';
		}
		$topics[] = array(
			'title' => __( 'El ciclo mensual de la rendición', 'gestion-de-proyectos' ),
			'html'  => self::paragraphs( $cycle ) . ( '' !== $list ? '<p><strong>' . esc_html__( 'Plazos de este proyecto:', 'gestion-de-proyectos' ) . '</strong></p><ul class="gdp-fin-list">' . $list . '</ul>' : '' ),
		);

		$next = $status['next_installment'] ?? null;
		if ( is_array( $next ) ) {
			$criterion = array(
				'presentado' => __( 'basta con haberlo rendido (presentado)', 'gestion-de-proyectos' ),
				'aprobado'   => __( 'se exige que lo rendido esté aprobado', 'gestion-de-proyectos' ),
				'ambos'      => __( 'se muestran las dos lecturas, rendido y aprobado, hasta que la contraparte la precise', 'gestion-de-proyectos' ),
			);
			$topics[]  = array(
				'title' => sprintf( /* translators: número de la cuota. */ __( 'Qué falta para la cuota %d', 'gestion-de-proyectos' ), (int) $next['number'] ),
				'html'  => self::paragraphs(
					array(
						sprintf(
							/* translators: 1: monto transferido, 2: monto pagado, 3: monto rendido, 4: monto por pagar, 5: monto por rendir. */
							__( 'Las cuotas recibidas suman %1$s. Se han pagado %2$s y rendido %3$s, de modo que faltan %4$s por pagar y %5$s por rendir.', 'gestion-de-proyectos' ),
							BoardMetrics::money( (float) $next['gaps']['target'] ),
							BoardMetrics::money( (float) $status['sources']['fondo']['paid'] ),
							BoardMetrics::money( (float) $status['sources']['fondo']['rendered'] ),
							BoardMetrics::money( (float) $next['gaps']['pay_gap'] ),
							BoardMetrics::money( (float) $next['gaps']['render_gap'] )
						),
						sprintf(
							/* translators: criterio de la regla condicion_giro. */
							__( 'Criterio de "rendir el 100 %%": %s. La alternativa es una garantía por la fracción no rendida.', 'gestion-de-proyectos' ),
							$criterion[ (string) $next['criterion'] ] ?? (string) $next['criterion']
						),
						empty( $next['latest_month'] ) ? __( 'Con la fecha objetivo registrada, ningún mes de pago alcanza ya a rendirse a tiempo: la vía es la garantía o una nueva fecha objetivo.', 'gestion-de-proyectos' ) : sprintf(
							/* translators: 1: fecha objetivo, 2: mes, 3: fecha límite para rendir, 4: fecha límite para facturar. */
							__( 'Con la fecha objetivo del %1$s, el último mes de pago útil es %2$s: lo que se pague hasta entonces debe rendirse a más tardar el %3$s, y una factura nueva debe recibirse antes del %4$s para alcanzar a pagarse.', 'gestion-de-proyectos' ),
							BoardMetrics::date( (string) $next['target_date'] ),
							BoardMetrics::month_label( (string) $next['latest_month'] ),
							BoardMetrics::date( (string) $next['latest_render_due'] ),
							BoardMetrics::date( (string) $next['invoice_by'] )
						),
					)
				),
			);
		}

		$flow  = array(
			'comprometido' => __( 'contrato u orden de compra firmados; aún sin documento de cobro', 'gestion-de-proyectos' ),
			'devengado'    => __( 'servicio prestado o documento emitido; falta pagarlo', 'gestion-de-proyectos' ),
			'pagado'       => __( 'con comprobante de egreso; listo para rendirse', 'gestion-de-proyectos' ),
			'rendido'      => __( 'presentado en SISREC', 'gestion-de-proyectos' ),
			'aprobado'     => __( 'aceptado por el Gobierno Regional', 'gestion-de-proyectos' ),
			'observado'    => __( 'el Gobierno Regional pidió aclarar o corregir', 'gestion-de-proyectos' ),
			'corregido'    => __( 'corrección presentada; vuelve a revisión', 'gestion-de-proyectos' ),
			'rechazado'    => __( 'no aceptado: se rebaja del ítem y se reintegra', 'gestion-de-proyectos' ),
		);
		$items = '';
		foreach ( $profile->payment_statuses() as $slug => $label ) {
			$items .= '<li><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $flow[ $slug ] ?? '' ) . '</li>';
		}
		$topics[] = array(
			'title' => __( 'Estados de un pago', 'gestion-de-proyectos' ),
			'html'  => '<ul class="gdp-fin-list">' . $items . '</ul>',
		);

		$items = '';
		foreach ( $profile->rendition_statuses() as $s ) {
			$items .= '<li><strong>' . esc_html( (string) $s['label'] ) . '</strong>: ' . esc_html( sprintf( /* translators: actor responsable. */ __( 'a cargo de %s', 'gestion-de-proyectos' ), (string) $s['actor'] ) ) . '</li>';
		}
		$topics[] = array(
			'title' => __( 'Estados de una rendición y quién actúa', 'gestion-de-proyectos' ),
			'html'  => '<ul class="gdp-fin-list">' . $items . '</ul>',
		);

		$items  = '';
		$labels = (array) $data['items_labels'];
		foreach ( $profile->support_requirements() as $slug => $reqs ) {
			$items .= '<li><strong>' . esc_html( $labels[ $slug ] ?? $profile->items()[ $slug ]['label'] ?? $slug ) . '</strong>: ' . esc_html( implode( '; ', array_values( $reqs ) ) ) . '.</li>';
		}
		$topics[] = array(
			'title' => __( 'Respaldos que exige cada ítem', 'gestion-de-proyectos' ),
			'html'  => '<ul class="gdp-fin-list">' . $items . '</ul>',
		);

		$topics[] = array(
			'title' => __( 'Qué reporte usar en cada caso', 'gestion-de-proyectos' ),
			'html'  => '<ul class="gdp-fin-list">'
				. '<li>' . esc_html__( 'Rendición del mes: expediente imprimible para la Dirección de Investigación; planilla y ZIP de carga masiva si son cinco o más transacciones; carta y carátula de gasto cero si el mes no tuvo gasto (pestaña Rendiciones, cuando la rendición está registrada).', 'gestion-de-proyectos' ) . '</li>'
				. '<li>' . esc_html__( 'Solicitud de una cuota: ficha de giro con las siete condiciones y el documento que acredita cada una (pestaña Cuotas).', 'gestion-de-proyectos' ) . '</li>'
				. '<li>' . esc_html__( 'Oficio que pide la programación de caja: planilla en el formato de la Dirección de Investigación con los seis controles (pestaña Caja).', 'gestion-de-proyectos' ) . '</li>'
				. '<li>' . esc_html__( 'Reunión, informe al director o correo a la contraparte: informe del estado financiero imprimible y texto para informes, ambos en la pestaña Reportes.', 'gestion-de-proyectos' ) . '</li>'
				. '<li>' . esc_html__( 'Cuando piden el estado financiero en Excel (la Dirección de Investigación, la contraparte o una auditoría): libro con todo el estado financiero, una hoja por materia, con montos y fechas como números y totales por fórmula. Se descarga con el botón Exportar a Excel o desde la pestaña Reportes, con el permiso de exportar.', 'gestion-de-proyectos' ) . '</li>'
				. '<li>' . esc_html__( 'Análisis propio: el mismo libro Excel, o la descarga en CSV de los pagos, ítems y cuotas (pestaña Reportes) para abrir en una planilla de cálculo.', 'gestion-de-proyectos' ) . '</li>'
				. '</ul>',
		);

		$terms = '';
		foreach ( self::glossary( $data ) as $g ) {
			$terms .= '<dt>' . esc_html( $g['term'] ) . '</dt><dd>' . esc_html( $g['text'] ) . '</dd>';
		}
		$topics[] = array(
			'title' => __( 'Glosario', 'gestion-de-proyectos' ),
			'html'  => '<dl class="gdp-fin-glossary">' . $terms . '</dl>',
		);

		return $topics;
	}

	/**
	 * Glosario con los valores de las reglas vigentes.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return array<int,array{term:string,text:string}>
	 */
	public static function glossary( array $data ): array {
		$rule = self::rule_reader( $data );

		return array(
			array(
				'term' => __( 'Aporte pecuniario', 'gestion-de-proyectos' ),
				'text' => __( 'Dinero de la universidad comprometido en el convenio; se entera antes de cada giro y se rinde por separado.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Aporte no pecuniario', 'gestion-de-proyectos' ),
				'text' => __( 'Valorización de recursos propios (horas, infraestructura) comprometida en el convenio.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Brecha', 'gestion-de-proyectos' ),
				'text' => __( 'Lo que falta pagar, rendir o aprobar de las cuotas recibidas para habilitar la siguiente.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Comprobante de ingreso', 'gestion-de-proyectos' ),
				'text' => sprintf( /* translators: días hábiles. */ __( 'Documento de la Dirección de Finanzas que acredita la recepción de una transferencia; se adjunta al aceptarla en SISREC dentro de %s días hábiles.', 'gestion-de-proyectos' ), $rule( 'plazo_comprobante_ingreso', '5' ) ),
			),
			array(
				'term' => __( 'Comprometido', 'gestion-de-proyectos' ),
				'text' => __( 'Obligación contraída por contrato u orden de compra, aún sin documento de cobro.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Condiciones de giro', 'gestion-de-proyectos' ),
				'text' => __( 'G1 rendición o garantía; G2 informe de avance aprobado; G3 aporte pecuniario acreditado; G4 carta de solicitud; G5 rendiciones al día; G6 comprobantes de ingreso enviados; G7 ventana del convenio.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Devengado', 'gestion-de-proyectos' ),
				'text' => __( 'Servicio prestado o documento de cobro emitido, pendiente de pago.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Disponible', 'gestion-de-proyectos' ),
				'text' => __( 'Asignado vigente del ítem menos pagado, comprometido y rechazado.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Garantía por la fracción no rendida', 'gestion-de-proyectos' ),
				'text' => __( 'Documento garante que reemplaza la rendición del 100 % para girar la cuota siguiente.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Ministro de fe', 'gestion-de-proyectos' ),
				'text' => __( 'Funcionario de la universidad que coteja en SISREC cada respaldo con su original antes de la firma.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Programación de caja', 'gestion-de-proyectos' ),
				'text' => __( 'Plan mensual de transferencias, gasto y aporte que pide el Gobierno Regional; el módulo le aplica seis controles.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Reitemización', 'gestion-de-proyectos' ),
				'text' => sprintf( /* translators: número máximo. */ __( 'Traspaso de montos entre ítems aprobado por el Gobierno Regional; máximo %s por proyecto.', 'gestion-de-proyectos' ), $rule( 'reitemizaciones_max', '3' ) ),
			),
			array(
				'term' => __( 'Rendición sin movimiento', 'gestion-de-proyectos' ),
				'text' => __( 'Rendición de un mes sin gasto, con carta conductora y carátula de gasto cero.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Saldo de caja', 'gestion-de-proyectos' ),
				'text' => __( 'Transferido menos pagado menos reintegrado.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => 'SISREC',
				'text' => __( 'Sistema de Rendición Electrónica de Cuentas de la Contraloría General de la República, donde se cargan, autentican, firman y envían las rendiciones.', 'gestion-de-proyectos' ),
			),
			array(
				'term' => __( 'Último mes de pago útil', 'gestion-de-proyectos' ),
				'text' => __( 'Último mes cuyos pagos alcanzan a rendirse y revisarse antes de la fecha objetivo del giro.', 'gestion-de-proyectos' ),
			),
		);
	}

	/**
	 * Lector del valor vigente de una regla.
	 *
	 * @param array<string,mixed> $data Datos del tablero.
	 * @return callable(string,string):string
	 */
	private static function rule_reader( array $data ): callable {
		$rules = (array) ( $data['status']['rules'] ?? array() );

		return static function ( string $key, string $fallback ) use ( $rules ): string {
			$value = (string) ( $rules[ $key ]['value'] ?? '' );

			return '' !== $value ? $value : $fallback;
		};
	}

	/**
	 * Párrafos escapados.
	 *
	 * @param string[] $texts Textos.
	 * @return string HTML.
	 */
	private static function paragraphs( array $texts ): string {
		$html = '';
		foreach ( $texts as $t ) {
			$html .= '<p>' . esc_html( $t ) . '</p>';
		}

		return $html;
	}
}
