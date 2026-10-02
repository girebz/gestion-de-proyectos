<?php
/**
 * Contrato de los perfiles de fondo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * Un perfil de fondo instancia los conceptos del núcleo con los datos de un
 * financiador concreto: reglas por omisión con su fuente, catálogo de ítems
 * y su proyección a la clasificación de la plataforma, respaldos exigidos
 * por ítem, estados y guías paso a paso. El núcleo no conoce ninguna de
 * estas particularidades; la precedencia es convenio, perfil, núcleo.
 */
abstract class Profile {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Nombre visible.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Reglas por omisión: clave => [value, source, label, type].
	 *
	 * @return array<string,array{value:string,source:string,label:string,type:string}>
	 */
	abstract public function rules(): array;

	/**
	 * Ítems del fondo: slug => [label, group, platform_type, platform_subclass, cap_rule, budget_line].
	 *
	 * @return array<string,array<string,string>>
	 */
	abstract public function items(): array;

	/**
	 * Respaldos exigidos por ítem: slug => [kind => etiqueta].
	 *
	 * @return array<string,array<string,string>>
	 */
	abstract public function support_requirements(): array;

	/**
	 * Guías paso a paso (hojas de ejecución).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	abstract public function guides(): array;

	/**
	 * Tipos de respaldo conocidos: kind => etiqueta.
	 *
	 * @return array<string,string>
	 */
	public function support_kinds(): array {
		return array(
			'contrato'         => __( 'Contrato', 'gestion-de-proyectos' ),
			'orden_compra'     => __( 'Orden de compra', 'gestion-de-proyectos' ),
			'cotizaciones'     => __( 'Cotizaciones evaluadas', 'gestion-de-proyectos' ),
			'justificacion'    => __( 'Memorándum de justificación', 'gestion-de-proyectos' ),
			'licitacion'       => __( 'Antecedentes de la licitación', 'gestion-de-proyectos' ),
			'fondo_por_rendir' => __( 'Rendición del fondo por rendir', 'gestion-de-proyectos' ),
			'factura'          => __( 'Factura o boleta', 'gestion-de-proyectos' ),
			'boleta'           => __( 'Boleta de honorarios', 'gestion-de-proyectos' ),
			'informe'          => __( 'Informe de actividades o de cumplimiento', 'gestion-de-proyectos' ),
			'carga_horaria'    => __( 'Certificado de carga horaria', 'gestion-de-proyectos' ),
			'autorizacion'     => __( 'Autorización previa del otorgante', 'gestion-de-proyectos' ),
			'visado'           => __( 'Visado de comunicaciones', 'gestion-de-proyectos' ),
			'inscripcion'      => __( 'Inscripción o programa', 'gestion-de-proyectos' ),
			'verificacion'     => __( 'Medios de verificación', 'gestion-de-proyectos' ),
			'inventario'       => __( 'Alta en el inventario', 'gestion-de-proyectos' ),
			'recepcion'        => __( 'Conformidad o recepción', 'gestion-de-proyectos' ),
			'pasajes'          => __( 'Pasajes, viáticos o peajes', 'gestion-de-proyectos' ),
			'otro'             => __( 'Otro respaldo', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Fuentes de financiamiento: slug => etiqueta.
	 *
	 * @return array<string,string>
	 */
	public function sources(): array {
		return array(
			'fondo'         => __( 'Fondo (otorgante)', 'gestion-de-proyectos' ),
			'pecuniario'    => __( 'Aporte pecuniario', 'gestion-de-proyectos' ),
			'no_pecuniario' => __( 'Aporte no pecuniario', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Tipos de gasto de la plataforma: slug => etiqueta.
	 *
	 * @return array<string,string>
	 */
	public function platform_types(): array {
		return array(
			'personal'  => __( 'Personal', 'gestion-de-proyectos' ),
			'operacion' => __( 'Operación', 'gestion-de-proyectos' ),
			'inversion' => __( 'Inversión', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Tipos de documento de gasto: slug => etiqueta.
	 *
	 * @return array<string,string>
	 */
	public function doc_types(): array {
		return array(
			'factura'              => __( 'Factura', 'gestion-de-proyectos' ),
			'factura_exenta'       => __( 'Factura exenta', 'gestion-de-proyectos' ),
			'boleta'               => __( 'Boleta', 'gestion-de-proyectos' ),
			'boleta_honorarios'    => __( 'Boleta de honorarios', 'gestion-de-proyectos' ),
			'documento_extranjero' => __( 'Documento extranjero', 'gestion-de-proyectos' ),
			'otro'                 => __( 'Otro', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Estados de una rendición, en orden, con etiqueta y actor.
	 *
	 * @return array<string,array{label:string,actor:string,stage:string}>
	 */
	public function rendition_statuses(): array {
		return array(
			'preparada'          => array( 'label' => __( 'Preparada', 'gestion-de-proyectos' ), 'actor' => __( 'ingeniero de proyectos', 'gestion-de-proyectos' ), 'stage' => 'interna' ),
			'enviada_universidad' => array( 'label' => __( 'Enviada a la universidad', 'gestion-de-proyectos' ), 'actor' => __( 'Dirección de Investigación', 'gestion-de-proyectos' ), 'stage' => 'interna' ),
			'en_carga'           => array( 'label' => __( 'En carga (borrador en la plataforma)', 'gestion-de-proyectos' ), 'actor' => __( 'analista ejecutor', 'gestion-de-proyectos' ), 'stage' => 'ejecutor' ),
			'en_autenticacion'   => array( 'label' => __( 'En autenticación (ministro de fe)', 'gestion-de-proyectos' ), 'actor' => __( 'ministro de fe', 'gestion-de-proyectos' ), 'stage' => 'ejecutor' ),
			'en_firma_interna'   => array( 'label' => __( 'En firma interna', 'gestion-de-proyectos' ), 'actor' => __( 'encargado ejecutor', 'gestion-de-proyectos' ), 'stage' => 'ejecutor' ),
			'rendida'            => array( 'label' => __( 'Rendida (enviada al otorgante)', 'gestion-de-proyectos' ), 'actor' => __( 'Gobierno Regional', 'gestion-de-proyectos' ), 'stage' => 'otorgante' ),
			'en_revision'        => array( 'label' => __( 'En revisión del otorgante', 'gestion-de-proyectos' ), 'actor' => __( 'Gobierno Regional', 'gestion-de-proyectos' ), 'stage' => 'otorgante' ),
			'aprobada'           => array( 'label' => __( 'Aprobada', 'gestion-de-proyectos' ), 'actor' => __( 'Gobierno Regional', 'gestion-de-proyectos' ), 'stage' => 'cerrada' ),
			'aprobada_parcial'   => array( 'label' => __( 'Aprobada parcialmente', 'gestion-de-proyectos' ), 'actor' => __( 'ejecutor, mediante regularización', 'gestion-de-proyectos' ), 'stage' => 'ejecutor' ),
			'devuelta'           => array( 'label' => __( 'Devuelta (observada)', 'gestion-de-proyectos' ), 'actor' => __( 'ejecutor, dentro del plazo de subsanación', 'gestion-de-proyectos' ), 'stage' => 'ejecutor' ),
		);
	}

	/**
	 * Estados de un pago, en orden.
	 *
	 * @return array<string,string>
	 */
	public function payment_statuses(): array {
		return array(
			'comprometido' => __( 'Comprometido', 'gestion-de-proyectos' ),
			'devengado'    => __( 'Devengado', 'gestion-de-proyectos' ),
			'pagado'       => __( 'Pagado', 'gestion-de-proyectos' ),
			'rendido'      => __( 'Rendido', 'gestion-de-proyectos' ),
			'aprobado'     => __( 'Aprobado', 'gestion-de-proyectos' ),
			'observado'    => __( 'Observado', 'gestion-de-proyectos' ),
			'corregido'    => __( 'Corregido', 'gestion-de-proyectos' ),
			'rechazado'    => __( 'Rechazado', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Estados de una transferencia en la plataforma.
	 *
	 * @return array<string,string>
	 */
	public function transfer_statuses(): array {
		return array(
			'pendiente' => __( 'Pendiente', 'gestion-de-proyectos' ),
			'enviada'   => __( 'Enviada por el otorgante', 'gestion-de-proyectos' ),
			'aceptada'  => __( 'Aceptada', 'gestion-de-proyectos' ),
			'rechazada' => __( 'Rechazada', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Enlaces públicos del perfil (etiqueta => URL), verificados en la fecha indicada.
	 *
	 * @return array<int,array{label:string,url:string,checked:string}>
	 */
	public function links(): array {
		return array();
	}
}
