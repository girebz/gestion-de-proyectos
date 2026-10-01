<?php
/**
 * Configuración de los tableros por proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Dashboards;

use GDP\Domain\Projects\ProjectRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Los tableros guardan su configuración en los ajustes del proyecto
 * (settings.dashboards): el público solo muestra lo que aquí se declara
 * publicable, de modo que ningún dato interno llegue a la portada por omisión.
 */
final class DashboardSettings {

	public const BLOCKS = array( 'portada', 'indicadores', 'etapas', 'hitos', 'aliados', 'contacto' );

	public const SOURCES = array( 'manual', 'avance', 'plazo', 'meses', 'hitos_cumplidos', 'actividades_terminadas', 'reuniones', 'documentos', 'acuerdos_cumplidos', 'muestras' );

	/**
	 * Etiquetas de los bloques del tablero público.
	 *
	 * @return array<string,string>
	 */
	public static function block_labels(): array {
		return array(
			'portada'     => __( 'Mensaje central y avance', 'gestion-de-proyectos' ),
			'indicadores' => __( 'Indicadores', 'gestion-de-proyectos' ),
			'etapas'      => __( 'Etapas del proyecto', 'gestion-de-proyectos' ),
			'hitos'       => __( 'Logros y próximos hitos', 'gestion-de-proyectos' ),
			'aliados'     => __( 'Instituciones y financiamiento', 'gestion-de-proyectos' ),
			'contacto'    => __( 'Llamado a la acción', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de las fuentes de los indicadores.
	 *
	 * @return array<string,string>
	 */
	public static function source_labels(): array {
		return array(
			'manual'                 => __( 'Valor escrito a mano', 'gestion-de-proyectos' ),
			'avance'                 => __( 'Avance general (%)', 'gestion-de-proyectos' ),
			'plazo'                  => __( 'Plazo transcurrido (%)', 'gestion-de-proyectos' ),
			'meses'                  => __( 'Meses de ejecución', 'gestion-de-proyectos' ),
			'hitos_cumplidos'        => __( 'Hitos cumplidos', 'gestion-de-proyectos' ),
			'actividades_terminadas' => __( 'Actividades terminadas', 'gestion-de-proyectos' ),
			'reuniones'              => __( 'Reuniones realizadas', 'gestion-de-proyectos' ),
			'documentos'             => __( 'Documentos emitidos', 'gestion-de-proyectos' ),
			'acuerdos_cumplidos'     => __( 'Acuerdos cumplidos', 'gestion-de-proyectos' ),
			'muestras'               => __( 'Muestras analizadas (a mano mientras no exista el módulo de laboratorio)', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Valores por defecto.
	 *
	 * @param array<string,mixed>|null $project Proyecto.
	 * @return array<string,mixed>
	 */
	public static function defaults( ?array $project = null ): array {
		$description = trim( (string) ( $project['description'] ?? '' ) );
		$first       = '' === $description ? '' : trim( (string) preg_split( '/\R\s*\R/', $description )[0] );

		return array(
			'public' => array(
				'enabled'      => false,
				'blocks'       => self::BLOCKS,
				'headline'     => (string) ( $project['name'] ?? '' ),
				'summary'      => $first,
				'stages'       => array(),
				'highlights'   => array(),
				'indicators'   => array(
					array( 'label' => __( 'de avance', 'gestion-de-proyectos' ), 'source' => 'avance', 'value' => '' ),
					array( 'label' => __( 'meses de trabajo', 'gestion-de-proyectos' ), 'source' => 'meses', 'value' => '' ),
					array( 'label' => __( 'hitos cumplidos', 'gestion-de-proyectos' ), 'source' => 'hitos_cumplidos', 'value' => '' ),
				),
				'partners'     => array(),
				'funding'      => (string) ( $project['funder'] ?? '' ),
				'cta_title'    => __( '¿Quiere saber más o colaborar con el proyecto?', 'gestion-de-proyectos' ),
				'cta_text'     => __( 'Escríbanos y le responderemos a la brevedad.', 'gestion-de-proyectos' ),
				'cta_button'   => __( 'Contactar al equipo', 'gestion-de-proyectos' ),
				'cta_email'    => (string) get_option( 'admin_email' ),
				'cta_subject'  => __( 'Consulta desde el sitio del proyecto', 'gestion-de-proyectos' ),
				'show_updated' => true,
			),
			'team'   => array(
				'require_member' => false,
				'horizon_days'   => 14,
			),
		);
	}

	/**
	 * Configuración vigente de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function get( int $project_id ): array {
		$project  = ProjectRepository::find( $project_id );
		$defaults = self::defaults( $project );
		$stored   = is_array( $project['settings']['dashboards'] ?? null ) ? $project['settings']['dashboards'] : array();
		$out      = $defaults;
		foreach ( array( 'public', 'team' ) as $part ) {
			if ( is_array( $stored[ $part ] ?? null ) ) {
				$out[ $part ] = array_merge( $defaults[ $part ], $stored[ $part ] );
			}
		}
		$out['public']['blocks']     = array_values( array_intersect( (array) $out['public']['blocks'], self::BLOCKS ) );
		$out['public']['stages']     = is_array( $out['public']['stages'] ) ? $out['public']['stages'] : array();
		$out['public']['highlights'] = is_array( $out['public']['highlights'] ) ? $out['public']['highlights'] : array();
		$out['public']['indicators'] = is_array( $out['public']['indicators'] ) ? array_values( $out['public']['indicators'] ) : array();
		$out['public']['partners']   = is_array( $out['public']['partners'] ) ? array_values( $out['public']['partners'] ) : array();

		return $out;
	}

	/**
	 * Valida y guarda la configuración.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $values     Valores del formulario (public, team).
	 * @return bool|WP_Error
	 */
	public static function save( int $project_id, array $values ) {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
		}
		$clean    = self::sanitize( $values, $project );
		$settings = is_array( $project['settings'] ) ? $project['settings'] : array();
		$settings['dashboards'] = $clean;
		$result   = ProjectRepository::update( $project_id, array( 'settings' => $settings ), null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		DashboardsModule::flush( $project_id );

		return true;
	}

	/**
	 * Normaliza los valores recibidos.
	 *
	 * @param array<string,mixed> $values  Valores.
	 * @param array<string,mixed> $project Proyecto.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $values, array $project ): array {
		$defaults = self::defaults( $project );
		$p        = is_array( $values['public'] ?? null ) ? $values['public'] : array();
		$t        = is_array( $values['team'] ?? null ) ? $values['team'] : array();

		$stages = array();
		foreach ( (array) ( $p['stages'] ?? array() ) as $id => $s ) {
			$s = (array) $s;
			$stages[ (string) (int) $id ] = array(
				'label'   => sanitize_text_field( (string) ( $s['label'] ?? '' ) ),
				'text'    => sanitize_text_field( (string) ( $s['text'] ?? '' ) ),
				'visible' => ! empty( $s['visible'] ),
			);
		}
		$highlights = array();
		foreach ( (array) ( $p['highlights'] ?? array() ) as $id => $h ) {
			$h = (array) $h;
			if ( empty( $h['public'] ) ) {
				continue;
			}
			$highlights[ (string) (int) $id ] = array( 'label' => sanitize_text_field( (string) ( $h['label'] ?? '' ) ) );
		}
		$indicators = array();
		foreach ( (array) ( $p['indicators'] ?? array() ) as $ind ) {
			$ind   = (array) $ind;
			$label = sanitize_text_field( (string) ( $ind['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$source       = in_array( $ind['source'] ?? '', self::SOURCES, true ) ? (string) $ind['source'] : 'manual';
			$indicators[] = array( 'label' => $label, 'source' => $source, 'value' => sanitize_text_field( (string) ( $ind['value'] ?? '' ) ) );
		}
		$partners = array();
		foreach ( (array) ( $p['partners'] ?? array() ) as $pa ) {
			$pa   = (array) $pa;
			$name = sanitize_text_field( (string) ( $pa['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$partners[] = array(
				'name' => $name,
				'role' => sanitize_text_field( (string) ( $pa['role'] ?? '' ) ),
				'logo' => esc_url_raw( (string) ( $pa['logo'] ?? '' ) ),
				'url'  => esc_url_raw( (string) ( $pa['url'] ?? '' ) ),
			);
		}
		$email = sanitize_email( (string) ( $p['cta_email'] ?? '' ) );

		return array(
			'public' => array(
				'enabled'      => ! empty( $p['enabled'] ),
				'blocks'       => array_values( array_intersect( self::BLOCKS, (array) ( $p['blocks'] ?? array() ) ) ),
				'headline'     => sanitize_text_field( (string) ( $p['headline'] ?? $defaults['public']['headline'] ) ),
				'summary'      => sanitize_textarea_field( (string) ( $p['summary'] ?? '' ) ),
				'stages'       => $stages,
				'highlights'   => $highlights,
				'indicators'   => $indicators,
				'partners'     => $partners,
				'funding'      => sanitize_text_field( (string) ( $p['funding'] ?? '' ) ),
				'cta_title'    => sanitize_text_field( (string) ( $p['cta_title'] ?? '' ) ),
				'cta_text'     => sanitize_text_field( (string) ( $p['cta_text'] ?? '' ) ),
				'cta_button'   => sanitize_text_field( (string) ( $p['cta_button'] ?? $defaults['public']['cta_button'] ) ),
				'cta_email'    => is_email( $email ) ? $email : $defaults['public']['cta_email'],
				'cta_subject'  => sanitize_text_field( (string) ( $p['cta_subject'] ?? '' ) ),
				'show_updated' => ! empty( $p['show_updated'] ),
			),
			'team'   => array(
				'require_member' => ! empty( $t['require_member'] ),
				'horizon_days'   => max( 3, min( 60, (int) ( $t['horizon_days'] ?? 14 ) ) ),
			),
		);
	}
}
