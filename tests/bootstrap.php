<?php
/**
 * Arranque de la suite de pruebas unitarias.
 *
 * Las pruebas unitarias cubren las clases que no dependen de WordPress
 * (motor de programación, calendarios, utilidades). Las constantes mínimas
 * del plugin se definen aquí para que el autocargador funcione sin WordPress.
 *
 * @package GDP
 */

declare( strict_types=1 );

$gdp_root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $gdp_root . '/tests/' );
}
if ( ! defined( 'GDP_PATH' ) ) {
	define( 'GDP_PATH', $gdp_root . '/' );
}

if ( is_file( $gdp_root . '/vendor/autoload.php' ) ) {
	require_once $gdp_root . '/vendor/autoload.php';
} else {
	require_once $gdp_root . '/src/Autoloader.php';
	\GDP\Autoloader::register();
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress: devuelve el valor sin filtrar.
	 *
	 * @param string $hook  Gancho.
	 * @param mixed  $value Valor.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param mixed $data  Datos.
	 * @param int   $flags Opciones.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress: devuelve el texto sin traducir.
	 *
	 * @param string $text   Texto.
	 * @param string $domain Dominio.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, Universal.Files.SeparateFunctionsFromOO
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress: quita etiquetas y espacios sobrantes.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	function sanitize_text_field( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress: conserva los saltos de línea.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	function sanitize_textarea_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	function sanitize_email( string $email ): string {
		return (string) filter_var( trim( $email ), FILTER_SANITIZE_EMAIL );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $email Correo.
	 * @return string|false
	 */
	function is_email( string $email ) {
		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress: solo direcciones http(s).
	 *
	 * @param string $url Dirección.
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		$url = trim( $url );

		return preg_match( '#^(https?://|/)#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $url Dirección.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return esc_url_raw( $url );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $class Clase.
	 * @return string
	 */
	function sanitize_html_class( string $class ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $class );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
	}
}
if ( ! function_exists( 'wp_parse_str' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string $text   Cadena de consulta.
	 * @param array  $result Resultado.
	 * @return void
	 */
	function wp_parse_str( string $text, &$result ): void {
		parse_str( $text, $result );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress, con el mismo comportamiento esencial:
	 * los parámetros existentes se vuelven a codificar y los nuevos se añaden tal cual.
	 *
	 * @param mixed ...$args Parámetros (array y URL, o clave, valor y URL).
	 * @return string
	 */
	function add_query_arg( ...$args ): string {
		if ( is_array( $args[0] ) ) {
			$new = $args[0];
			$url = (string) ( $args[1] ?? '' );
		} else {
			$new = array( (string) $args[0] => $args[1] );
			$url = (string) ( $args[2] ?? '' );
		}
		$frag = '';
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$frag = substr( $url, $hash );
			$url  = substr( $url, 0, $hash );
		}
		$query = '';
		$pos   = strpos( $url, '?' );
		if ( false !== $pos ) {
			$query = substr( $url, $pos + 1 );
			$url   = substr( $url, 0, $pos );
		}
		parse_str( $query, $qs );
		$qs = array_map( 'urlencode', array_filter( $qs, 'is_string' ) );
		foreach ( $new as $k => $v ) {
			if ( false === $v ) {
				unset( $qs[ $k ] );
			} else {
				$qs[ $k ] = (string) $v;
			}
		}
		$pairs = array();
		foreach ( $qs as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}

		return $url . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $frag;
	}
}
if ( ! function_exists( 'remove_query_arg' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress.
	 *
	 * @param string|array $keys Parámetros a quitar.
	 * @param string       $url  Dirección.
	 * @return string
	 */
	function remove_query_arg( $keys, string $url ): string {
		$remove = array();
		foreach ( (array) $keys as $key ) {
			$remove[ $key ] = false;
		}

		return add_query_arg( $remove, $url );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Sustituto mínimo fuera de WordPress: devuelve el valor por defecto.
	 *
	 * @param string $name    Opción.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return 'admin_email' === $name ? 'admin@example.test' : $default;
	}
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

unset( $gdp_root );
