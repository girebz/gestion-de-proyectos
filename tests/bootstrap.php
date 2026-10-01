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

		return preg_match( '#^https?://#i', $url ) ? $url : '';
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
