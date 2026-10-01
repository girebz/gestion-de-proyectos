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

unset( $gdp_root );
