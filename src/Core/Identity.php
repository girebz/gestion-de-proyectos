<?php
/**
 * Identidad visual adoptada del sitio anfitrión.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * El plugin no trae marca propia: toma nombre, logotipo, ícono, paleta y
 * tipografía del sitio que lo aloja (ajustes de WordPress y theme.json del
 * tema activo) y permite corregirlos manualmente desde los ajustes cuando el
 * tema no los declara.
 */
final class Identity {

	/**
	 * Identidad resuelta: valores del sitio con las correcciones manuales encima.
	 *
	 * @return array<string,string>
	 */
	public static function get(): array {
		$identity = self::from_site();

		if ( Options::get( 'identity_use_site', true ) ) {
			$overrides = Options::get( 'identity_overrides', array() );
			if ( is_array( $overrides ) ) {
				foreach ( $overrides as $key => $value ) {
					if ( is_string( $value ) && '' !== trim( $value ) ) {
						$identity[ $key ] = $value;
					}
				}
			}
		}

		/**
		 * Permite ajustar la identidad visual resuelta.
		 *
		 * @param array<string,string> $identity Identidad.
		 */
		return (array) apply_filters( 'gdp_identity', $identity );
	}

	/**
	 * Lee la identidad declarada por el sitio y el tema activo.
	 *
	 * @return array<string,string>
	 */
	public static function from_site(): array {
		$logo_url = '';
		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$logo_url = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
		}

		$palette = self::palette();

		$identity = array(
			'name'       => (string) get_bloginfo( 'name' ),
			'tagline'    => (string) get_bloginfo( 'description' ),
			'logo_url'   => $logo_url,
			'icon_url'   => (string) get_site_icon_url( 64 ),
			'primary'    => $palette['primary'],
			'secondary'  => $palette['secondary'],
			'accent'     => $palette['accent'],
			'background' => $palette['background'],
			'text'       => $palette['text'],
			'font'       => self::font_family(),
		);

		/**
		 * Permite al tema declarar su identidad (colores y tipografía) cuando
		 * su paleta no usa los nombres habituales de theme.json. A diferencia
		 * de gdp_identity, se aplica antes de las correcciones manuales de los
		 * ajustes del plugin, que siguen teniendo la última palabra.
		 *
		 * @param array<string,string> $identity Identidad detectada.
		 */
		return (array) apply_filters( 'gdp_site_identity', $identity );
	}

	/**
	 * Deduce una paleta funcional a partir de theme.json.
	 *
	 * Busca por nombres habituales (primary, secondary, accent, contrast, base)
	 * y, si el tema no declara paleta, recurre a los colores del panel de
	 * WordPress para que la interfaz siga siendo coherente.
	 *
	 * @return array<string,string>
	 */
	private static function palette(): array {
		$defaults = array(
			'primary'    => '#2271b1',
			'secondary'  => '#1d2327',
			'accent'     => '#d63638',
			'background' => '#ffffff',
			'text'       => '#1d2327',
		);

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $defaults;
		}

		$settings = wp_get_global_settings( array( 'color', 'palette' ) );
		$entries  = array();

		if ( is_array( $settings ) ) {
			// theme.json puede devolver la paleta agrupada por origen (default/theme/custom).
			foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
				if ( isset( $settings[ $origin ] ) && is_array( $settings[ $origin ] ) ) {
					foreach ( $settings[ $origin ] as $entry ) {
						if ( isset( $entry['slug'], $entry['color'] ) ) {
							$entries[ strtolower( (string) $entry['slug'] ) ] = (string) $entry['color'];
						}
					}
				}
			}
			// O como lista plana.
			foreach ( $settings as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) ) {
					$entries[ strtolower( (string) $entry['slug'] ) ] = (string) $entry['color'];
				}
			}
		}

		if ( empty( $entries ) ) {
			return $defaults;
		}

		$pick = static function ( array $candidates, string $fallback ) use ( $entries ): string {
			foreach ( $candidates as $slug ) {
				if ( isset( $entries[ $slug ] ) ) {
					return $entries[ $slug ];
				}
			}
			return $fallback;
		};

		return array(
			'primary'    => $pick( array( 'primary', 'contrast', 'accent', 'accent-1', 'secondary' ), $defaults['primary'] ),
			'secondary'  => $pick( array( 'secondary', 'contrast-2', 'accent-2', 'tertiary' ), $defaults['secondary'] ),
			'accent'     => $pick( array( 'accent', 'accent-1', 'accent-3', 'primary' ), $defaults['accent'] ),
			'background' => $pick( array( 'base', 'background', 'white', 'base-2' ), $defaults['background'] ),
			'text'       => $pick( array( 'contrast', 'foreground', 'text', 'black' ), $defaults['text'] ),
		);
	}

	/**
	 * Primera familia tipográfica declarada por el tema.
	 *
	 * @return string
	 */
	private static function font_family(): string {
		$fallback = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $fallback;
		}

		$fonts = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		if ( ! is_array( $fonts ) ) {
			return $fallback;
		}

		foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
			if ( isset( $fonts[ $origin ][0]['fontFamily'] ) ) {
				return (string) $fonts[ $origin ][0]['fontFamily'];
			}
		}

		if ( isset( $fonts[0]['fontFamily'] ) ) {
			return (string) $fonts[0]['fontFamily'];
		}

		return $fallback;
	}

	/**
	 * Variables CSS con la identidad resuelta, para inyectar en las pantallas del plugin.
	 *
	 * @param string $selector Selector que recibe las variables (:root en el panel; en el sitio, el contenedor del plugin).
	 * @return string
	 */
	public static function css_variables( string $selector = ':root' ): string {
		$i = self::get();

		$css = sprintf(
			'%7$s{--gdp-primary:%1$s;--gdp-secondary:%2$s;--gdp-accent:%3$s;--gdp-background:%4$s;--gdp-text:%5$s;--gdp-font:%6$s;}',
			self::sanitize_color( $i['primary'] ),
			self::sanitize_color( $i['secondary'] ),
			self::sanitize_color( $i['accent'] ),
			self::sanitize_color( $i['background'] ),
			self::sanitize_color( $i['text'] ),
			self::sanitize_font( $i['font'] ),
			$selector
		);

		return $css;
	}

	/**
	 * Acepta colores hexadecimales, rgb(a) y hsl(a); en otro caso devuelve "inherit".
	 *
	 * @param string $color Color.
	 * @return string
	 */
	public static function sanitize_color( string $color ): string {
		$color = trim( $color );

		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color ) ) {
			return $color;
		}

		if ( preg_match( '/^(rgb|hsl)a?\([\d\s.,%\/]+\)$/i', $color ) ) {
			return $color;
		}

		return 'inherit';
	}

	/**
	 * Limpia una declaración de familia tipográfica.
	 *
	 * @param string $font Familia.
	 * @return string
	 */
	public static function sanitize_font( string $font ): string {
		$font = preg_replace( '/[^a-zA-Z0-9\s,\'"\-]/', '', $font );

		return '' === trim( (string) $font ) ? 'inherit' : trim( (string) $font );
	}
}
