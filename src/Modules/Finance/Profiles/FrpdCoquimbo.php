<?php
/**
 * Perfil del Fondo Regional para la Productividad y el Desarrollo (Gobierno
 * Regional de Coquimbo) con rendición en SISREC.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * Reglas, ítems, respaldos y guías tomados de las bases del concurso 2025,
 * la Resolución 30 de 2015 de la Contraloría, los oficios del Gobierno
 * Regional, los criterios escritos de la contraparte y los manuales del
 * ejecutor de SISREC. Cada regla lleva su fuente; el proyecto puede
 * sustituirla en el registro de reglas.
 *
 * En las guías, los valores entre llaves se resuelven con los datos del
 * módulo: {cuota.amount|money}, {pago.doc_date|fecha}, {proveedor.tax_id|rut}.
 */
final class FrpdCoquimbo extends Profile {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'frpd_coquimbo_sisrec';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Fondo Regional para la Productividad y el Desarrollo, Gobierno Regional de Coquimbo, rendición en SISREC', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rules(): array {
		return array(
			'cuota1_max'                 => array( 'value' => '20', 'source' => 'Bases 17', 'label' => __( 'Cuota 1, máximo (% del Fondo)', 'gestion-de-proyectos' ), 'type' => 'percent' ),
			'personal_max'               => array( 'value' => '40', 'source' => 'Bases 7', 'label' => __( 'Personal, máximo (% del costo total)', 'gestion-de-proyectos' ), 'type' => 'percent' ),
			'admin_max'                  => array( 'value' => '5', 'source' => 'Bases 7', 'label' => __( 'Administración, máximo (% del Fondo)', 'gestion-de-proyectos' ), 'type' => 'percent' ),
			'arriendo_vehiculos_max'     => array( 'value' => '10', 'source' => 'Bases 7', 'label' => __( 'Arriendo de vehículos, máximo (% del Fondo)', 'gestion-de-proyectos' ), 'type' => 'percent' ),
			'factura_umbral'             => array( 'value' => '2', 'source' => 'Bases 21.3', 'label' => __( 'Factura obligatoria desde (unidades de fomento)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'horas_semana_max'           => array( 'value' => '57', 'source' => 'Bases 7', 'label' => __( 'Horas semanales máximas por persona', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_respaldo_interno'     => array( 'value' => '8', 'source' => 'Dirección de Investigación', 'label' => __( 'Respaldos internos: día hábil del mes siguiente', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_sisrec'               => array( 'value' => '15', 'source' => 'Bases 21.1', 'label' => __( 'Carga en la plataforma: día hábil del mes siguiente', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_subsanacion'          => array( 'value' => '15', 'source' => 'Bases 21.1', 'label' => __( 'Subsanación de observaciones (días hábiles)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_comprobante_ingreso'  => array( 'value' => '5', 'source' => 'Bases 20', 'label' => __( 'Comprobante de ingreso de cada transferencia (días hábiles)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_rendicion_final'      => array( 'value' => '15', 'source' => 'Bases 21.2', 'label' => __( 'Rendición final tras la última mensual aprobada (días hábiles)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_correccion_final'     => array( 'value' => '10', 'source' => 'Bases 21.2', 'label' => __( 'Corrección de la rendición final (días hábiles)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_informe_trimestral'   => array( 'value' => '20', 'source' => 'Bases 19', 'label' => __( 'Informe técnico trimestral (días hábiles tras el trimestre)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'multa_informe_dia'          => array( 'value' => '1', 'source' => 'Bases 19', 'label' => __( 'Multa por día de atraso del informe (unidades de fomento)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'reitemizaciones_max'        => array( 'value' => '3', 'source' => 'Bases 22', 'label' => __( 'Reitemizaciones máximas por proyecto', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'prorroga_max'               => array( 'value' => '40', 'source' => 'Bases 8 y 22', 'label' => __( 'Prórroga máxima (% del plazo)', 'gestion-de-proyectos' ), 'type' => 'percent' ),
			'garantia_pct'               => array( 'value' => '10', 'source' => 'Bases 16', 'label' => __( 'Garantía de fiel cumplimiento (% del Fondo)', 'gestion-de-proyectos' ), 'type' => 'percent' ),
			'garantia_vigencia_extra'    => array( 'value' => '120', 'source' => 'Bases 16', 'label' => __( 'Vigencia de la garantía tras el término (días corridos)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'cotizaciones_umbral'        => array( 'value' => '3000000', 'source' => 'Procedimiento interno de la universidad', 'label' => __( 'Cotizaciones y contrato desde (pesos)', 'gestion-de-proyectos' ), 'type' => 'money' ),
			'licitacion_umbral'          => array( 'value' => '3000', 'source' => 'Procedimiento interno de la universidad', 'label' => __( 'Licitación desde (unidades de fomento)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'plazo_pago_factura'         => array( 'value' => '30', 'source' => 'Procedimiento interno de la universidad', 'label' => __( 'Pago de una factura desde su recepción (días corridos)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'boleta_sin_codigo'          => array( 'value' => 'advertir', 'source' => 'Correo de la contraparte del 08/06/2026', 'label' => __( 'Boleta sin código del proyecto (advertir o bloquear)', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'imputacion_cuotas'          => array( 'value' => 'cronologica', 'source' => 'Convención del módulo', 'label' => __( 'Imputación de pagos a cuotas', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'condicion_giro'             => array( 'value' => 'ambos', 'source' => 'Correo de la contraparte del 02/10/2026 (por precisar)', 'label' => __( 'Rendir el 100 % significa: presentado, aprobado o ambos', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'pecuniario_por_cuota'       => array( 'value' => '20,60,20', 'source' => 'Programa de desembolso', 'label' => __( 'Aporte pecuniario por cuota (% separados por coma)', 'gestion-de-proyectos' ), 'type' => 'text' ),
			'periodicidad_rendicion'     => array( 'value' => 'mensual', 'source' => 'Bases 21.1', 'label' => __( 'Periodicidad de la rendición (mensual, hito o cuota)', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'plataforma'                 => array( 'value' => 'SISREC', 'source' => 'Bases 21; convenio', 'label' => __( 'Plataforma de rendición', 'gestion-de-proyectos' ), 'type' => 'text' ),
			'carga_masiva_zip_max'       => array( 'value' => '100', 'source' => 'Manuales de carga masiva', 'label' => __( 'Tamaño máximo del ZIP de carga masiva (MB)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'carga_masiva_texto'         => array( 'value' => 'ascii', 'source' => 'Manual del ejecutor 9', 'label' => __( 'Texto de la planilla de carga (sin tildes ni eñe)', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'correccion_monto'           => array( 'value' => 'menor_o_igual', 'source' => 'Manual del ejecutor 7', 'label' => __( 'Corrección de monto observado', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'eliminar_solo_ultima'       => array( 'value' => 'si', 'source' => 'Manual del ejecutor 7', 'label' => __( 'Eliminar o agregar transacciones solo en la última rendición', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'cierre_diferencia'          => array( 'value' => '0', 'source' => 'Manual del ejecutor 11', 'label' => __( 'Diferencia exigida al cierre', 'gestion-de-proyectos' ), 'type' => 'money' ),
			'plazo_por_fecha_ejecucion'  => array( 'value' => 'si', 'source' => 'Manual del otorgante 7', 'label' => __( 'El plazo se controla por la fecha de ejecución, no del egreso', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'delta_revision'             => array( 'value' => '15', 'source' => 'Calibrado con la experiencia (por precisar)', 'label' => __( 'Desfase de revisión del otorgante (días corridos)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'delta_solicitud'            => array( 'value' => '10', 'source' => 'Calibrado con la experiencia (por precisar)', 'label' => __( 'Desfase de solicitud y transferencia (días corridos)', 'gestion-de-proyectos' ), 'type' => 'number' ),
			'fecha_limite_giro'          => array( 'value' => '', 'source' => 'Presupuesto anual del otorgante (por precisar)', 'label' => __( 'Fecha objetivo del próximo giro (AAAA-MM-DD)', 'gestion-de-proyectos' ), 'type' => 'date' ),
			'ministro_de_fe'             => array( 'value' => 'si', 'source' => 'Manual del ejecutor 8', 'label' => __( 'La entidad ejecutora usa ministro de fe', 'gestion-de-proyectos' ), 'type' => 'choice' ),
			'ciudad_cartas'              => array( 'value' => 'La Serena', 'source' => 'Domicilio de la entidad ejecutora', 'label' => __( 'Ciudad que encabeza las cartas', 'gestion-de-proyectos' ), 'type' => 'text' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function items(): array {
		return array(
			'personal'       => array( 'label' => __( 'Personal', 'gestion-de-proyectos' ), 'group' => 'programa', 'platform_type' => 'personal', 'platform_subclass' => 'Personal', 'cap_rule' => 'personal_max', 'budget_line' => 'personal' ),
			'subcontratos'   => array( 'label' => __( 'Subcontratos', 'gestion-de-proyectos' ), 'group' => 'programa', 'platform_type' => 'operacion', 'platform_subclass' => 'Subcontratos', 'cap_rule' => '', 'budget_line' => 'subcontratos' ),
			'capacitacion'   => array( 'label' => __( 'Capacitación', 'gestion-de-proyectos' ), 'group' => 'programa', 'platform_type' => 'operacion', 'platform_subclass' => 'Capacitacion', 'cap_rule' => '', 'budget_line' => 'capacitacion' ),
			'difusion'       => array( 'label' => __( 'Difusión', 'gestion-de-proyectos' ), 'group' => 'programa', 'platform_type' => 'operacion', 'platform_subclass' => 'Difusion', 'cap_rule' => '', 'budget_line' => 'difusion' ),
			'generales'      => array( 'label' => __( 'Gastos generales', 'gestion-de-proyectos' ), 'group' => 'programa', 'platform_type' => 'operacion', 'platform_subclass' => 'Gastos generales', 'cap_rule' => '', 'budget_line' => 'generales' ),
			'inversion'      => array( 'label' => __( 'Inversión', 'gestion-de-proyectos' ), 'group' => 'programa', 'platform_type' => 'inversion', 'platform_subclass' => 'Inversion', 'cap_rule' => '', 'budget_line' => 'inversion' ),
			'administracion' => array( 'label' => __( 'Gastos de administración', 'gestion-de-proyectos' ), 'group' => 'administracion', 'platform_type' => 'operacion', 'platform_subclass' => 'Administracion', 'cap_rule' => 'admin_max', 'budget_line' => 'administracion' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function support_requirements(): array {
		return array(
			'personal'       => array( 'contrato' => __( 'Contrato con fecha de inicio, vigencia, cargo, horas, monto mensual y funciones', 'gestion-de-proyectos' ), 'boleta' => __( 'Boleta de honorarios del mes', 'gestion-de-proyectos' ), 'informe' => __( 'Informe de actividades firmado y visado por el director', 'gestion-de-proyectos' ), 'carga_horaria' => __( 'Certificado de carga horaria (máximo 57 horas semanales)', 'gestion-de-proyectos' ) ),
			'subcontratos'   => array( 'autorizacion' => __( 'Aprobación previa de la División de Fomento e Industria', 'gestion-de-proyectos' ), 'cotizaciones' => __( 'Procedimiento de selección y cotizaciones evaluadas', 'gestion-de-proyectos' ), 'contrato' => __( 'Contrato', 'gestion-de-proyectos' ), 'recepcion' => __( 'Informe de cumplimiento y conformidad del producto', 'gestion-de-proyectos' ), 'factura' => __( 'Factura', 'gestion-de-proyectos' ) ),
			'capacitacion'   => array( 'autorizacion' => __( 'Carta de solicitud y aprobación del Gobierno Regional', 'gestion-de-proyectos' ), 'cotizaciones' => __( 'Cotizaciones evaluadas', 'gestion-de-proyectos' ), 'contrato' => __( 'Contrato', 'gestion-de-proyectos' ), 'inscripcion' => __( 'Inscripción y programa', 'gestion-de-proyectos' ), 'orden_compra' => __( 'Orden de compra', 'gestion-de-proyectos' ), 'factura' => __( 'Factura con código del proyecto', 'gestion-de-proyectos' ), 'verificacion' => __( 'Medios de verificación (registro fotográfico, certificados, material)', 'gestion-de-proyectos' ) ),
			'difusion'       => array( 'visado' => __( 'Visado del Departamento de Comunicaciones del Gobierno Regional', 'gestion-de-proyectos' ), 'factura' => __( 'Factura', 'gestion-de-proyectos' ), 'verificacion' => __( 'Medios de verificación con leyenda de financiamiento', 'gestion-de-proyectos' ) ),
			'generales'      => array( 'factura' => __( 'Factura o boleta con detalle', 'gestion-de-proyectos' ) ),
			'inversion'      => array( 'cotizaciones' => __( 'Cotizaciones y contrato según monto', 'gestion-de-proyectos' ), 'factura' => __( 'Factura', 'gestion-de-proyectos' ), 'inventario' => __( 'Alta en el inventario de la universidad con código', 'gestion-de-proyectos' ) ),
			'administracion' => array( 'factura' => __( 'Factura, boleta o documento del gasto', 'gestion-de-proyectos' ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function links(): array {
		return array(
			array( 'label' => __( 'Portal de SISREC (ingreso con clave única y manuales)', 'gestion-de-proyectos' ), 'url' => 'https://www.rendicioncuentas.cl/portal/sitiosisrec/', 'checked' => '2026-10-02' ),
			array( 'label' => __( 'Preguntas frecuentes de SISREC', 'gestion-de-proyectos' ), 'url' => 'https://www.rendicioncuentas.cl/preguntas-frecuentes', 'checked' => '2026-10-02' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function guides(): array {
		$menu = static fn( string $path ): string => sprintf( /* translators: nombre de la pantalla. */ __( 'SISREC: %s', 'gestion-de-proyectos' ), $path );

		return array(
			'respaldos_mensuales'      => array(
				'label'    => __( 'Reunir los respaldos del mes y enviarlos a la universidad', 'gestion-de-proyectos' ),
				'entity'   => 'rendition',
				'source'   => __( 'Procedimiento interno de la universidad; bases 21.1', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'ingeniero de proyectos', 'gestion-de-proyectos' ), __( 'Dirección de Investigación', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'pagos', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: pestaña Pagos', 'gestion-de-proyectos' ), 'instruction' => __( 'Registre cada egreso del mes con su documento y su ítem; cada uno debe quedar en estado pagado.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Período', 'gestion-de-proyectos' ), 'value' => '{rendicion.period}' ), array( 'label' => __( 'Pagos del período', 'gestion-de-proyectos' ), 'value' => '{rendicion.payments_count}' ), array( 'label' => __( 'Total pagado', 'gestion-de-proyectos' ), 'value' => '{rendicion.total|money_pretty}' ) ), 'check' => __( 'La suma de los pagos coincide con los egresos del centro de costo.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'respaldos', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: ficha de cada pago', 'gestion-de-proyectos' ), 'instruction' => __( 'Adjunte a cada pago el comprobante de egreso y los respaldos que exige su ítem; el pago pasa a "respaldos completos" cuando no falta ninguno.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Pagos con respaldos incompletos', 'gestion-de-proyectos' ), 'value' => '{rendicion.incomplete_count}' ) ), 'check' => __( 'Ningún pago tiene hallazgos que bloqueen.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'caratula', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: expediente de la rendición', 'gestion-de-proyectos' ), 'instruction' => __( 'Descargue el expediente (carátula, detalle y saldo por ítem) y entréguelo a la Dirección de Investigación antes del plazo interno.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Plazo interno', 'gestion-de-proyectos' ), 'value' => '{rendicion.internal_due|fecha}' ), array( 'label' => __( 'Plazo de la plataforma', 'gestion-de-proyectos' ), 'value' => '{rendicion.platform_due|fecha}' ) ), 'produces' => 'expediente', 'check' => __( 'Constancia de recepción de la Dirección de Investigación.', 'gestion-de-proyectos' ) ),
				),
				'result'   => array( 'status' => 'enviada_universidad' ),
			),
			'aceptar_transferencia'    => array(
				'label'    => __( 'Aceptar una transferencia y enviar el comprobante de ingreso', 'gestion-de-proyectos' ),
				'entity'   => 'installment',
				'source'   => __( 'Manual del ejecutor 3; bases 20', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'comprobante', 'actor' => 'ingeniero', 'screen' => __( 'Dirección de Finanzas', 'gestion-de-proyectos' ), 'instruction' => __( 'Pida el comprobante de ingreso el mismo día en que se anuncia la transferencia: el plazo de 5 días hábiles corre desde ella.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Cuota', 'gestion-de-proyectos' ), 'value' => '{cuota.number}' ), array( 'label' => __( 'Monto', 'gestion-de-proyectos' ), 'value' => '{cuota.amount|money_pretty}' ), array( 'label' => __( 'Fecha de la transferencia', 'gestion-de-proyectos' ), 'value' => '{cuota.transferred_at|fecha}' ), array( 'label' => __( 'Plazo del comprobante', 'gestion-de-proyectos' ), 'value' => '{cuota.receipt_due|fecha}' ) ), 'produces' => 'comprobante_ingreso' ),
					array( 'key' => 'ingresos', 'actor' => 'analista', 'screen' => $menu( __( 'Transferencia, Mis ingresos', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Ubique la transferencia enviada por el otorgante y abra la lupa.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Proyecto', 'gestion-de-proyectos' ), 'value' => '{proyecto.name}' ), array( 'label' => __( 'Monto esperado', 'gestion-de-proyectos' ), 'value' => '{cuota.amount|money}' ) ) ),
					array( 'key' => 'documentacion', 'actor' => 'analista', 'screen' => $menu( __( 'Ver ingreso, Documentación complementaria', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Revise el comprobante de egreso y la cartola del otorgante, la resolución del convenio y su modificación si existe; descárguelos al expediente.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Acto aprobatorio del convenio', 'gestion-de-proyectos' ), 'value' => '{convenio.approval_act}' ) ), 'check' => __( 'El monto y la cuenta coinciden con el convenio.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'aceptar', 'actor' => 'analista', 'screen' => $menu( __( 'Revisión, Aceptar, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Seleccione Aceptar, pulse Nuevo e ingrese el comprobante de ingreso con su archivo; Guardar y luego Aceptar.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Nombre del documento', 'gestion-de-proyectos' ), 'value' => 'Comprobante de ingreso cuota {cuota.number}' ), array( 'label' => __( 'Número', 'gestion-de-proyectos' ), 'value' => '{cuota.receipt_number}' ), array( 'label' => __( 'Fecha de la transferencia', 'gestion-de-proyectos' ), 'value' => '{cuota.transferred_at|fecha}' ), array( 'label' => __( 'Archivo', 'gestion-de-proyectos' ), 'value' => '{cuota.document}', 'file' => true ) ), 'check' => __( 'Para rechazar: seleccione Rechazada y escriba el motivo; la transferencia vuelve al otorgante.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'verificar', 'actor' => 'analista', 'screen' => $menu( __( 'Consulta por ingresos, búsqueda avanzada', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Compruebe que el estado sea aceptada y declare el estado en el plugin con la fecha.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Buscar por', 'gestion-de-proyectos' ), 'value' => '{proyecto.name}' ) ), 'produces' => 'constancia' ),
				),
				'result'   => array( 'platform_status' => 'aceptada' ),
			),
			'registrar_proveedor'      => array(
				'label'    => __( 'Registrar un proveedor en la plataforma', 'gestion-de-proyectos' ),
				'entity'   => 'supplier',
				'source'   => __( 'Manual del ejecutor 2', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'nuevo', 'actor' => 'analista', 'screen' => $menu( __( 'Proveedores, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Elija persona natural o jurídica; el rol de una persona natural se valida contra el Registro Civil y completa el nombre.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Tipo', 'gestion-de-proyectos' ), 'value' => '{proveedor.kind}' ), array( 'label' => __( 'Rol', 'gestion-de-proyectos' ), 'value' => '{proveedor.tax_id|rut}' ), array( 'label' => __( 'Razón social o nombre', 'gestion-de-proyectos' ), 'value' => '{proveedor.name}' ), array( 'label' => __( 'Giro', 'gestion-de-proyectos' ), 'value' => '{proveedor.category}' ) ), 'check' => __( 'Obligatorio antes de una carga masiva; en el ingreso manual también puede crearse desde la pantalla Documento con el botón +.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'marcar', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: proveedor', 'gestion-de-proyectos' ), 'instruction' => __( 'Marque el proveedor como registrado en la plataforma.', 'gestion-de-proyectos' ), 'fields' => array() ),
				),
				'result'   => array( 'registered' => true ),
			),
			'rendicion_mensual'        => array(
				'label'    => __( 'Rendición mensual con ingreso manual de transacciones', 'gestion-de-proyectos' ),
				'entity'   => 'rendition',
				'source'   => __( 'Manual del ejecutor 5', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'ministro de fe', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'crear', 'actor' => 'analista', 'screen' => $menu( __( 'Rendiciones, Mis rendiciones, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Cree la rendición de tipo mensual con el programa, el proyecto, el mes y el año.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Tipo de rendición', 'gestion-de-proyectos' ), 'value' => 'Mensual' ), array( 'label' => __( 'Proyecto', 'gestion-de-proyectos' ), 'value' => '{proyecto.name}' ), array( 'label' => __( 'Mes y año', 'gestion-de-proyectos' ), 'value' => '{rendicion.period|mes}' ) ) ),
					array( 'key' => 'transacciones', 'actor' => 'analista', 'screen' => $menu( __( 'Expediente de rendición, Listado de transacciones, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Ingrese cada transacción en la pantalla Documento: primero el comprobante de egreso y su archivo; luego el gasto (proveedor por rol sin puntos ni dígito, documento, monto, tipo de gasto, transferencia, respaldos). Guardar y continuar para otra transacción del mismo egreso.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Transacciones por ingresar', 'gestion-de-proyectos' ), 'value' => '{rendicion.payments_count}' ), array( 'label' => __( 'Total', 'gestion-de-proyectos' ), 'value' => '{rendicion.total|money_pretty}' ) ), 'per_payment' => true, 'check' => __( 'La suma de las transacciones coincide con el total pagado del mes y cada cuota declarada tiene saldo.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'enviar_mf', 'actor' => 'analista', 'screen' => $menu( __( 'Mis rendiciones, enviar', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Cierre el expediente y envíe la rendición al ministro de fe.', 'gestion-de-proyectos' ), 'fields' => array() ),
					array( 'key' => 'ministro', 'actor' => 'ministro', 'screen' => $menu( __( 'Mis rendiciones, Expediente, Listado de transacciones', 'gestion-de-proyectos' ) ), 'instruction' => __( 'El ministro de fe coteja cada comprobante y respaldo con su original y envía al encargado; si un respaldo es ilegible, rechaza y la rendición vuelve al analista.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Originales que debe tener a la vista', 'gestion-de-proyectos' ), 'value' => '{rendicion.originals}' ) ), 'review' => true ),
					array( 'key' => 'encargado', 'actor' => 'encargado', 'screen' => $menu( __( 'Mis rendiciones, Expediente, Datos de rendición', 'gestion-de-proyectos' ) ), 'instruction' => __( 'El encargado revisa el borrador del informe y las transacciones, envía a firma (para firma) y firma con el token en el firmador de escritorio; Buscar actualiza el estado a firmada.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Lo que va a firmar', 'gestion-de-proyectos' ), 'value' => '{rendicion.summary}' ) ), 'review' => true ),
					array( 'key' => 'enviar', 'actor' => 'encargado', 'screen' => $menu( __( 'Mis rendiciones, avión', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Envíe la rendición firmada al otorgante y descargue el informe firmado para el expediente; declare el estado rendida en el plugin.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Plazo de la plataforma', 'gestion-de-proyectos' ), 'value' => '{rendicion.platform_due|fecha}' ) ), 'produces' => 'informe_firmado' ),
				),
				'result'   => array( 'status' => 'rendida' ),
			),
			'rendicion_carga_masiva'   => array(
				'label'    => __( 'Rendición mensual por carga masiva', 'gestion-de-proyectos' ),
				'entity'   => 'rendition',
				'source'   => __( 'Manual del ejecutor 9', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'ministro de fe', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'proveedores', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: pestaña Pagos', 'gestion-de-proyectos' ), 'instruction' => __( 'Compruebe que todos los proveedores del período están registrados en la plataforma; la carga masiva lo exige.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Proveedores sin registrar', 'gestion-de-proyectos' ), 'value' => '{rendicion.unregistered_suppliers}' ) ) ),
					array( 'key' => 'descargar', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: expediente de la rendición', 'gestion-de-proyectos' ), 'instruction' => __( 'Descargue la planilla de carga y el ZIP con una carpeta por folio (CE con el egreso, T con los respaldos); el texto va sin tildes ni eñe.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Planilla', 'gestion-de-proyectos' ), 'value' => '{rendicion.bulk_sheet}', 'file' => true ), array( 'label' => __( 'ZIP de respaldos', 'gestion-de-proyectos' ), 'value' => '{rendicion.bulk_zip}', 'file' => true ), array( 'label' => __( 'Folios', 'gestion-de-proyectos' ), 'value' => '{rendicion.payments_count}' ) ), 'check' => __( 'Problemas detectados antes de la carga: {rendicion.bulk_problems}', 'gestion-de-proyectos' ) ),
					array( 'key' => 'crear', 'actor' => 'analista', 'screen' => $menu( __( 'Rendiciones, Mis rendiciones, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Cree la rendición mensual y ciérrela sin ingresar transacciones: una sola transacción manual desactiva la carga masiva.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Tipo de rendición', 'gestion-de-proyectos' ), 'value' => 'Mensual' ), array( 'label' => __( 'Mes y año', 'gestion-de-proyectos' ), 'value' => '{rendicion.period|mes}' ) ) ),
					array( 'key' => 'cargar', 'actor' => 'analista', 'screen' => $menu( __( 'Mis rendiciones, ícono de carga masiva', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Suba la planilla con el botón +, luego el ZIP; en Detalle de carga cada fila debe mostrar dos marcas en la columna CE/T. Pulse Enviar consolidación.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Nombre del ZIP', 'gestion-de-proyectos' ), 'value' => '{rendicion.bulk_zip_name}' ) ), 'check' => __( 'La rendición queda en borrador y admite transacciones manuales adicionales.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'flujo', 'actor' => 'analista', 'screen' => $menu( __( 'Mis rendiciones, enviar', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Continúe el flujo normal: ministro de fe, encargado, firma y envío al otorgante; declare el estado rendida en el plugin.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Plazo de la plataforma', 'gestion-de-proyectos' ), 'value' => '{rendicion.platform_due|fecha}' ) ), 'produces' => 'informe_firmado' ),
				),
				'result'   => array( 'status' => 'rendida' ),
			),
			'rendicion_sin_movimiento' => array(
				'label'    => __( 'Rendición sin movimiento (mes sin gasto)', 'gestion-de-proyectos' ),
				'entity'   => 'rendition',
				'source'   => __( 'Manual del ejecutor 4; bases 21.1', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'carta', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: expediente de la rendición', 'gestion-de-proyectos' ), 'instruction' => __( 'Genere la carta conductora con la justificación técnica y la carátula de gasto cero, y registre por qué vía se remiten (la plataforma no las adjunta).', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Período', 'gestion-de-proyectos' ), 'value' => '{rendicion.period|mes}' ), array( 'label' => __( 'Carta y carátula', 'gestion-de-proyectos' ), 'value' => '{rendicion.zero_letter}', 'file' => true ) ), 'produces' => 'carta_gasto_cero' ),
					array( 'key' => 'crear', 'actor' => 'analista', 'screen' => $menu( __( 'Rendiciones, Mis rendiciones, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Cree la rendición de tipo sin movimiento con el mes y el año; Guardar. Descargue el formulario en borrador desde la lupa.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Tipo de rendición', 'gestion-de-proyectos' ), 'value' => 'Sin movimiento' ), array( 'label' => __( 'Mes y año', 'gestion-de-proyectos' ), 'value' => '{rendicion.period|mes}' ) ) ),
					array( 'key' => 'enviar_encargado', 'actor' => 'analista', 'screen' => $menu( __( 'Mis rendiciones, enviar', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Envíe directamente al encargado: sin documentos que autenticar, el ministro de fe no interviene.', 'gestion-de-proyectos' ), 'fields' => array() ),
					array( 'key' => 'firmar', 'actor' => 'encargado', 'screen' => $menu( __( 'Mis rendiciones, Expediente, Datos rendición', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Revise el informe, envíe a firma, firme con el token y envíe al otorgante con el avión; declare el estado rendida en el plugin.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Plazo de la plataforma', 'gestion-de-proyectos' ), 'value' => '{rendicion.platform_due|fecha}' ) ), 'produces' => 'informe_firmado', 'review' => true ),
				),
				'result'   => array( 'status' => 'rendida' ),
			),
			'corregir_devuelta'        => array(
				'label'    => __( 'Corregir una rendición devuelta por el otorgante', 'gestion-de-proyectos' ),
				'entity'   => 'rendition',
				'source'   => __( 'Manual del ejecutor 7; bases 21.1', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'ministro de fe', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'leer', 'actor' => 'analista', 'screen' => $menu( __( 'Mis rendiciones (estado observada), Expediente, Listado de transacciones', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Lea el comentario de cada transacción rendida observada y cópielo al pago correspondiente en el plugin.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Transacciones observadas', 'gestion-de-proyectos' ), 'value' => '{rendicion.observed_count}' ), array( 'label' => __( 'Plazo de subsanación', 'gestion-de-proyectos' ), 'value' => '{rendicion.fix_due|fecha}' ) ) ),
					array( 'key' => 'corregir', 'actor' => 'analista', 'screen' => $menu( __( 'Listado de transacciones, lápiz', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Corrija datos o respaldos y Guardar: la transacción pasa a rendida corregida. Un monto solo puede bajar; eliminar o agregar solo si es la última rendición del proyecto; en otro caso, solicítelo por Consulte aquí.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Pagos por corregir', 'gestion-de-proyectos' ), 'value' => '{rendicion.observed_list}' ) ), 'per_payment' => true ),
					array( 'key' => 'flujo', 'actor' => 'analista', 'screen' => $menu( __( 'Mis rendiciones, enviar', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Reenvíe al ministro de fe; sigue el flujo normal hasta el otorgante. El plazo de subsanación debe cubrir todo el recorrido interno.', 'gestion-de-proyectos' ), 'fields' => array() ),
				),
				'result'   => array( 'status' => 'rendida' ),
			),
			'regularizacion'           => array(
				'label'    => __( 'Rendición de regularización tras una aprobación parcial', 'gestion-de-proyectos' ),
				'entity'   => 'rendition',
				'source'   => __( 'Manual del otorgante 13 (por completar con el manual del ejecutor 6)', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'ministro de fe', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'identificar', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: pestaña Rendiciones', 'gestion-de-proyectos' ), 'instruction' => __( 'Identifique las transacciones observadas de la rendición aprobada parcialmente; se presentan de nuevo en una rendición de tipo regularización.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Pendientes de regularizar', 'gestion-de-proyectos' ), 'value' => '{rendicion.observed_list}' ) ) ),
					array( 'key' => 'crear', 'actor' => 'analista', 'screen' => $menu( __( 'Rendiciones, Mis rendiciones, Nuevo', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Cree la rendición de tipo regularización e ingrese las transacciones corregidas; luego el flujo normal.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Tipo de rendición', 'gestion-de-proyectos' ), 'value' => 'Regularización' ) ), 'per_payment' => true ),
				),
				'result'   => array( 'status' => 'rendida' ),
			),
			'solicitar_cuota'          => array(
				'label'    => __( 'Solicitar la cuota siguiente', 'gestion-de-proyectos' ),
				'entity'   => 'installment',
				'source'   => __( 'Bases 17 y 20; correo de la contraparte del 02/10/2026', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'ingeniero de proyectos', 'gestion-de-proyectos' ), __( 'director', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'condiciones', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: ficha de giro', 'gestion-de-proyectos' ), 'instruction' => __( 'Compruebe las siete condiciones de giro; la ficha muestra el documento que acredita cada una.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Cuota', 'gestion-de-proyectos' ), 'value' => '{cuota.number}' ), array( 'label' => __( 'Monto', 'gestion-de-proyectos' ), 'value' => '{cuota.amount|money_pretty}' ), array( 'label' => __( 'Condiciones cumplidas', 'gestion-de-proyectos' ), 'value' => '{cuota.conditions_met}' ) ), 'check' => __( 'Condiciones pendientes: {cuota.conditions_pending}', 'gestion-de-proyectos' ) ),
					array( 'key' => 'pecuniario', 'actor' => 'ingeniero', 'screen' => __( 'Dirección de Finanzas', 'gestion-de-proyectos' ), 'instruction' => __( 'Entere el aporte pecuniario de la cuota y obtenga su comprobante de ingreso.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Aporte pecuniario', 'gestion-de-proyectos' ), 'value' => '{cuota.cash_amount|money_pretty}' ) ), 'produces' => 'comprobante_pecuniario' ),
					array( 'key' => 'carta', 'actor' => 'director', 'screen' => __( 'Oficina de partes del Gobierno Regional', 'gestion-de-proyectos' ), 'instruction' => __( 'Remita la carta de solicitud con el comprobante del aporte y el informe de avance aprobado; registre la fecha de solicitud en el plugin.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Ventana del convenio', 'gestion-de-proyectos' ), 'value' => '{cuota.window}' ) ), 'produces' => 'carta_solicitud' ),
				),
				'result'   => array( 'requested' => true ),
			),
			'programacion_caja'        => array(
				'label'    => __( 'Programación de caja en el formato de la Dirección de Investigación', 'gestion-de-proyectos' ),
				'entity'   => 'project',
				'source'   => __( 'Oficio 3705/2026; formato de la Dirección de Investigación', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'ingeniero de proyectos', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'plan', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: pestaña Caja', 'gestion-de-proyectos' ), 'instruction' => __( 'Complete, por mes, la transferencia solicitada, el gasto programado y el aporte; los seis controles deben quedar en verde.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Controles', 'gestion-de-proyectos' ), 'value' => '{plan.checks}' ) ) ),
					array( 'key' => 'exportar', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: pestaña Caja', 'gestion-de-proyectos' ), 'instruction' => __( 'Descargue la planilla en el formato de la Dirección de Investigación y envíela; marque el plan como enviado.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Planilla', 'gestion-de-proyectos' ), 'value' => '{plan.sheet}', 'file' => true ) ), 'produces' => 'programacion' ),
				),
				'result'   => array( 'status' => 'enviada' ),
			),
			'cierre_proyecto'          => array(
				'label'    => __( 'Rendición final y cierre del proyecto', 'gestion-de-proyectos' ),
				'entity'   => 'project',
				'source'   => __( 'Manual del ejecutor 11; bases 21.2', 'gestion-de-proyectos' ),
				'actors'   => array( __( 'analista ejecutor', 'gestion-de-proyectos' ), __( 'ministro de fe', 'gestion-de-proyectos' ), __( 'encargado ejecutor', 'gestion-de-proyectos' ) ),
				'steps'    => array(
					array( 'key' => 'diferencia', 'actor' => 'ingeniero', 'screen' => __( 'Plugin: estado de cuentas', 'gestion-de-proyectos' ), 'instruction' => __( 'Lleve a cero la diferencia de cierre: transferido menos aprobado menos reintegrado. Lo no gastado y lo rechazado se restituyen antes de solicitar el cierre.', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Diferencia de cierre', 'gestion-de-proyectos' ), 'value' => '{estado.closing_difference|money_pretty}' ) ), 'check' => __( 'No puede solicitarse el cierre con rendiciones en curso.', 'gestion-de-proyectos' ) ),
					array( 'key' => 'solicitar', 'actor' => 'analista', 'screen' => $menu( __( 'Proyectos, Solicitud cierre de proyectos', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Busque el proyecto, pulse solicitar cierre y, en Mis cierres proyecto, edite la solicitud para cargar el informe de término firmado por el jefe de administración y finanzas y los respaldos del reintegro (egreso y cartola).', 'gestion-de-proyectos' ), 'fields' => array( array( 'label' => __( 'Buscar por', 'gestion-de-proyectos' ), 'value' => '{proyecto.name}' ) ), 'produces' => 'informe_termino' ),
					array( 'key' => 'flujo', 'actor' => 'analista', 'screen' => $menu( __( 'Mis cierres proyecto, enviar', 'gestion-de-proyectos' ) ), 'instruction' => __( 'Envíe al ministro de fe con un comentario; luego el encargado firma el informe consolidado de cierre y lo envía al otorgante, que registra el reintegro y aprueba el cierre solo si la diferencia es cero.', 'gestion-de-proyectos' ), 'fields' => array() ),
				),
				'result'   => array( 'status' => 'cerrado' ),
			),
		);
	}
}
