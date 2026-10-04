<?php
/**
 * Avisos automáticos por correo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Los módulos avisan por correo de lo que exige atención (plazos,
 * documentos, compras, rendición de cuentas, acuerdos) y envían el informe
 * semanal. Ningún aviso sale sin que alguien lo active: hay un interruptor
 * general y uno por tipo, ambos apagados por omisión, y un filtro para
 * ajustar la decisión por proyecto. Las alertas se calculan y se muestran en
 * el panel y en los tableros se envíen o no por correo.
 */
final class Notifications {

	/**
	 * Tipos de aviso, en el orden en que se presentan.
	 */
	public const TYPES = array( 'deadlines', 'weekly_report', 'documents', 'procurement', 'finance', 'agreements' );

	/**
	 * Descripción de cada tipo: rótulo (igual al asunto del correo), frecuencia, contenido y destinatarios.
	 *
	 * @return array<string,array{label:string,when:string,what:string,who:string}>
	 */
	public static function types(): array {
		return array(
			'deadlines'     => array(
				'label' => __( 'Alertas de plazo', 'gestion-de-proyectos' ),
				'when'  => __( 'Diario', 'gestion-de-proyectos' ),
				'what'  => __( 'Actividades del cronograma con alertas de severidad alta.', 'gestion-de-proyectos' ),
				'who'   => __( 'Director e ingeniero de proyectos de cada proyecto.', 'gestion-de-proyectos' ),
			),
			'weekly_report' => array(
				'label' => __( 'Informe semanal', 'gestion-de-proyectos' ),
				'when'  => __( 'Semanal', 'gestion-de-proyectos' ),
				'what'  => __( 'Avance del cronograma por frente: lo terminado, lo que viene y lo vencido.', 'gestion-de-proyectos' ),
				'who'   => __( 'Director e ingeniero de proyectos de cada proyecto.', 'gestion-de-proyectos' ),
			),
			'documents'     => array(
				'label' => __( 'Respuestas documentales pendientes', 'gestion-de-proyectos' ),
				'when'  => __( 'Diario', 'gestion-de-proyectos' ),
				'what'  => __( 'Documentos con respuesta vencida o que vence en los próximos tres días.', 'gestion-de-proyectos' ),
				'who'   => __( 'Director e ingeniero de proyectos de cada proyecto.', 'gestion-de-proyectos' ),
			),
			'procurement'   => array(
				'label' => __( 'Compras pendientes de decisión', 'gestion-de-proyectos' ),
				'when'  => __( 'Diario', 'gestion-de-proyectos' ),
				'what'  => __( 'Compras con cotizaciones que esperan decisión y cotizaciones sin respuesta del proveedor.', 'gestion-de-proyectos' ),
				'who'   => __( 'Director e ingeniero de proyectos de cada proyecto.', 'gestion-de-proyectos' ),
			),
			'finance'       => array(
				'label' => __( 'Rendición de cuentas: acciones pendientes', 'gestion-de-proyectos' ),
				'when'  => __( 'Diario', 'gestion-de-proyectos' ),
				'what'  => __( 'Acciones del asistente de rendición con severidad alta.', 'gestion-de-proyectos' ),
				'who'   => __( 'Integrantes que pueden ver las finanzas del proyecto.', 'gestion-de-proyectos' ),
			),
			'agreements'    => array(
				'label' => __( 'Acuerdos vencidos', 'gestion-de-proyectos' ),
				'when'  => __( 'Diario', 'gestion-de-proyectos' ),
				'what'  => __( 'Acuerdos de reunión vencidos que siguen abiertos.', 'gestion-de-proyectos' ),
				'who'   => __( 'Director, ingeniero de proyectos y responsable de cada acuerdo.', 'gestion-de-proyectos' ),
			),
		);
	}

	/**
	 * Decide, con unos ajustes dados, si un tipo de aviso se envía: hace
	 * falta el interruptor general y el del tipo.
	 *
	 * @param array<string,mixed> $settings Ajustes (Options::all()).
	 * @param string              $type     Tipo de aviso.
	 * @return bool
	 */
	public static function allowed( array $settings, string $type ): bool {
		if ( empty( $settings['notifications_enabled'] ) || ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		$types = $settings['notification_types'] ?? array();

		return is_array( $types ) && ! empty( $types[ $type ] );
	}

	/**
	 * Indica si un aviso se envía para un proyecto.
	 *
	 * @param string $type       Tipo de aviso.
	 * @param int    $project_id Proyecto (0 si no aplica).
	 * @return bool
	 */
	public static function enabled( string $type, int $project_id = 0 ): bool {
		/**
		 * Permite ajustar, por proyecto, si un aviso por correo se envía.
		 *
		 * @param bool   $enabled    Decisión según los ajustes.
		 * @param string $type       Tipo de aviso (ver Notifications::TYPES).
		 * @param int    $project_id Proyecto.
		 */
		return (bool) apply_filters( 'gdp_notification_enabled', self::allowed( Options::all(), $type ), $type, $project_id );
	}

	/**
	 * Tipos marcados en el formulario, como clave => activo.
	 *
	 * @param mixed $input Valor recibido.
	 * @return array<string,bool>
	 */
	public static function sanitize_types( $input ): array {
		$out = array();
		foreach ( self::TYPES as $type ) {
			$out[ $type ] = is_array( $input ) && ! empty( $input[ $type ] );
		}

		return $out;
	}
}
