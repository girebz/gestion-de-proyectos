<?php
/**
 * Gancho de activación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Acciones al activar el plugin.
 */
final class Activator {

	/**
	 * Instala el esquema, los roles y las tareas programadas.
	 *
	 * @param bool $network_wide Activación en toda la red (multisitio).
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			// Multisitio: se instala en cada sitio de la red.
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				Installer::install();
				restore_current_blog();
			}
			return;
		}

		Installer::install();

		// Se marca para que la primera visita al panel muestre la bienvenida.
		set_transient( 'gdp_activation_redirect', 1, 60 );
	}
}
