<?php
/**
 * Autocargador PSR-4 de respaldo para el espacio de nombres GDP.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve clases del espacio de nombres GDP\ hacia el directorio src/.
 */
final class Autoloader {

	/**
	 * Registra el autocargador en la pila de PHP.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Carga el archivo correspondiente a una clase.
	 *
	 * @param string $class Nombre completo de la clase.
	 * @return void
	 */
	public static function load( string $class ): void {
		$prefix = 'GDP\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = GDP_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
