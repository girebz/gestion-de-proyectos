<?php
/**
 * Gancho de desactivación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Acciones al desactivar el plugin. No borra datos: eso corresponde a uninstall.php.
 */
final class Deactivator {

	/**
	 * Retira las tareas programadas y limpia transitorios.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Cron::unschedule();
		delete_transient( 'gdp_activation_redirect' );
	}
}
