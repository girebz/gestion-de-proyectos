<?php
/**
 * Funciones para temas y extensiones.
 *
 * Son el punto de apoyo estable para integrar los tableros en un tema sin
 * depender de las clases internas del plugin: un tema comprueba
 * function_exists( 'gdp_dashboard_data' ) y, si existe, obtiene los datos
 * del tablero público para presentarlos con su propio diseño, o el HTML del
 * tablero tal como lo produce el código corto.
 *
 * @package GDP
 */

declare( strict_types=1 );

use GDP\Core\Access;
use GDP\Modules\Dashboards\DashboardData;
use GDP\Modules\Dashboards\DashboardRenderer;
use GDP\Modules\Dashboards\DashboardSettings;
use GDP\Modules\Dashboards\DashboardsModule;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'gdp_dashboard_project' ) ) {
	/**
	 * Resuelve el proyecto de un tablero por código (en cualquier combinación
	 * de mayúsculas) o identificador; sin referencia, el único proyecto del sitio.
	 *
	 * @param string $project Código o identificador.
	 * @return array<string,mixed>|null Ficha del proyecto o null si no existe.
	 */
	function gdp_dashboard_project( string $project = '' ): ?array {
		return DashboardsModule::resolve_project( $project );
	}
}

if ( ! function_exists( 'gdp_dashboard_data' ) ) {
	/**
	 * Datos del tablero público de un proyecto (desde la caché del plugin).
	 *
	 * El tablero público solo contiene lo declarado publicable en Proyectos →
	 * Tableros: título y resumen, avance y plazo, indicadores, etapas (cada
	 * una con sus hitos destacados), logros, próximos hitos, instituciones y
	 * contacto. La clave "enabled" indica si la publicación está activada; un
	 * tema debe respetarla y no mostrar nada a los visitantes cuando es false.
	 *
	 * Para el tablero del equipo (kind = "team") se exige un usuario con
	 * sesión y acceso; los montos solo se incluyen si tiene permiso para verlos.
	 *
	 * @param string $project Código o identificador del proyecto ('' = el único).
	 * @param string $kind    "public" o "team".
	 * @return array<string,mixed>|null Null si el proyecto no existe o el usuario no puede ver el tablero del equipo.
	 */
	function gdp_dashboard_data( string $project = '', string $kind = 'public' ): ?array {
		$p = DashboardsModule::resolve_project( $project );
		if ( ! $p ) {
			return null;
		}
		$project_id = (int) $p['id'];
		if ( 'team' === $kind ) {
			$user_id = get_current_user_id();
			if ( ! DashboardData::team_allowed( $project_id, $user_id ) ) {
				return null;
			}

			return DashboardsModule::team_data( $project_id, Access::can( 'procurement.view_amounts', $project_id, $user_id ) );
		}

		return DashboardsModule::public_data( $project_id );
	}
}

if ( ! function_exists( 'gdp_dashboard' ) ) {
	/**
	 * HTML del tablero público, igual al del código corto [gdp_avance].
	 *
	 * Devuelve cadena vacía si el proyecto no existe o la publicación está
	 * desactivada (en ese caso, los administradores reciben un aviso).
	 * Encola la hoja de estilos del plugin; un tema puede sobrescribir las
	 * variables --gdp-dash-* dentro de su propio contenedor.
	 *
	 * @param string   $project Código o identificador del proyecto ('' = el único).
	 * @param string[] $blocks  Bloques a mostrar, en orden (vacío = los configurados): portada, indicadores, etapas, hitos, aliados, contacto.
	 * @return string
	 */
	function gdp_dashboard( string $project = '', array $blocks = array() ): string {
		return DashboardsModule::shortcode_public( array( 'proyecto' => $project, 'bloques' => implode( ',', array_map( 'strval', $blocks ) ) ) );
	}
}

if ( ! function_exists( 'gdp_dashboard_html' ) ) {
	/**
	 * HTML del tablero público a partir de datos ya obtenidos (sin caché ni
	 * comprobación de publicación): para temas que combinan datos propios.
	 *
	 * @param array<string,mixed> $data   Datos de gdp_dashboard_data().
	 * @param string[]            $blocks Bloques a mostrar, en orden (vacío = los configurados).
	 * @return string
	 */
	function gdp_dashboard_html( array $data, array $blocks = array() ): string {
		wp_enqueue_style( 'gdp-dashboards' );

		return DashboardRenderer::public_html( $data, $blocks );
	}
}

if ( ! function_exists( 'gdp_dashboard_blocks' ) ) {
	/**
	 * Bloques disponibles del tablero público con su etiqueta.
	 *
	 * @return array<string,string>
	 */
	function gdp_dashboard_blocks(): array {
		return DashboardSettings::block_labels();
	}
}

if ( ! function_exists( 'gdp_parse_dashboard_shortcode' ) ) {
	/**
	 * Interpreta un código corto del plugin pegado por el usuario (por ejemplo
	 * en el Personalizador del tema) y devuelve sus partes.
	 *
	 * @param string $shortcode Texto como [gdp_avance proyecto="CODIGO" bloques="portada,etapas"].
	 * @return array{tag:string,project:string,blocks:string[]}|null Null si no es un código corto del plugin.
	 */
	function gdp_parse_dashboard_shortcode( string $shortcode ): ?array {
		if ( ! preg_match( '/^\s*\[(gdp_avance|gdp_tablero_equipo)(\s[^\]]*)?\]\s*$/u', $shortcode, $m ) ) {
			return null;
		}
		$atts   = shortcode_parse_atts( trim( (string) ( $m[2] ?? '' ) ) );
		$atts   = is_array( $atts ) ? $atts : array();
		$blocks = array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $atts['bloques'] ?? '' ) ) ) ) ) );

		return array(
			'tag'     => $m[1],
			'project' => trim( (string) ( $atts['proyecto'] ?? '' ) ),
			'blocks'  => array_values( array_intersect( $blocks, DashboardSettings::BLOCKS ) ),
		);
	}
}
