<?php
/**
 * Punto de arranque del plugin.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP;

use GDP\Admin\Admin;
use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Core\Cron;
use GDP\Core\Installer;
use GDP\Core\Storage;
use GDP\Modules\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Orquesta la carga de los componentes del plugin.
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Registro de módulos.
	 *
	 * @var Registry
	 */
	private Registry $modules;

	/**
	 * Devuelve (y crea si hace falta) la instancia única.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado: la carga se hace en boot().
	 */
	private function __construct() {
		$this->modules = new Registry();
	}

	/**
	 * Registra los ganchos de los componentes.
	 *
	 * @return void
	 */
	private function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		// Migraciones de esquema cuando cambia la versión del plugin.
		Installer::maybe_upgrade();

		// Acceso al panel para miembros de proyectos.
		Access::register();

		// Protección del directorio privado de adjuntos y su entrega controlada.
		Storage::register();

		// Tareas programadas.
		Cron::register();

		// Módulos funcionales (proyectos, documentos, adquisiciones, etc.).
		$this->modules->register_all();

		// Conector para asistentes de inteligencia artificial (Abilities API + MCP).
		Connector::register();

		// Interfaz de administración.
		if ( is_admin() ) {
			Admin::register();
		}

		/**
		 * Permite a extensiones engancharse una vez cargado el plugin.
		 *
		 * @param Plugin $plugin Instancia del plugin.
		 */
		do_action( 'gdp_loaded', $this );
	}

	/**
	 * Carga las traducciones del plugin.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'gestion-de-proyectos', false, dirname( GDP_BASENAME ) . '/languages' );
	}

	/**
	 * Acceso al registro de módulos.
	 *
	 * @return Registry
	 */
	public function modules(): Registry {
		return $this->modules;
	}
}
