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

unset( $gdp_root );
