<?php
/**
 * Plugin Name:       Gestión de Proyectos
 * Plugin URI:        https://github.com/girebz/gestion-de-proyectos
 * Description:       Gestión integral de proyectos de investigación y desarrollo con financiamiento externo: planificación y control del tiempo, control documental, adquisiciones y presupuesto, laboratorio, evidencias, exportación de datos y conector para asistentes de inteligencia artificial.
 * Version:           0.7.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Giorgio Reveco Barraza
 * Author URI:        https://giorgioreveco.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gestion-de-proyectos
 * Domain Path:       /languages
 *
 * @package GDP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Identidad técnica del plugin. El prefijo "gdp_" se usa en tablas, opciones,
// ganchos y capacidades para no colisionar con otros plugins.
define( 'GDP_VERSION', '0.7.0' );
define( 'GDP_DB_VERSION', '6' );
define( 'GDP_FILE', __FILE__ );
define( 'GDP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GDP_URL', plugin_dir_url( __FILE__ ) );
define( 'GDP_BASENAME', plugin_basename( __FILE__ ) );
define( 'GDP_MIN_PHP', '8.1' );
define( 'GDP_MIN_WP', '6.9' );

/**
 * Comprueba los requisitos mínimos antes de cargar el resto del plugin.
 *
 * @return bool
 */
function gdp_requirements_met(): bool {
	global $wp_version;

	if ( version_compare( PHP_VERSION, GDP_MIN_PHP, '<' ) ) {
		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, GDP_MIN_WP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Aviso administrativo cuando los requisitos no se cumplen.
 *
 * @return void
 */
function gdp_requirements_notice(): void {
	global $wp_version;

	$message = sprintf(
		/* translators: 1: versión mínima de PHP, 2: versión mínima de WordPress, 3: versión actual de PHP, 4: versión actual de WordPress */
		__( 'Gestión de Proyectos requiere PHP %1$s o superior y WordPress %2$s o superior. Este sitio tiene PHP %3$s y WordPress %4$s. El plugin permanece inactivo hasta que se cumplan los requisitos.', 'gestion-de-proyectos' ),
		GDP_MIN_PHP,
		GDP_MIN_WP,
		PHP_VERSION,
		isset( $wp_version ) ? $wp_version : '?'
	);

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
}

if ( ! gdp_requirements_met() ) {
	add_action( 'admin_notices', 'gdp_requirements_notice' );
	return;
}

// Autocarga: se prefiere Composer si el paquete se instaló con dependencias;
// en su defecto se usa un autocargador PSR-4 propio, para que el ZIP
// instalable no dependa de ejecutar Composer en el sitio.
if ( is_readable( GDP_PATH . 'vendor/autoload.php' ) ) {
	require_once GDP_PATH . 'vendor/autoload.php';
} else {
	require_once GDP_PATH . 'src/Autoloader.php';
	GDP\Autoloader::register();
}

register_activation_hook( __FILE__, array( GDP\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( GDP\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( GDP\Plugin::class, 'instance' ), 5 );
