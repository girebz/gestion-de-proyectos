<?php
/**
 * Módulo de exportación, importación y respaldo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Admin\Pages\DataPage;
use GDP\Modules\ModuleInterface;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Exportación por módulo o completa en JSON, CSV, XLSX y ZIP con adjuntos
 * (con diccionario de datos y variante anonimizada); importación de JSON o
 * ZIP mediante la capa de operaciones; respaldos completos manuales y
 * semanales con restauración reversible.
 */
final class DataModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'data';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Exportación, importación y respaldo', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Datos en formatos abiertos con diccionario, importación con vista previa y reversión, respaldos completos.', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'gdp_register_operation_handlers', array( $this, 'register_handlers' ) );
		add_filter( 'gdp_connector_abilities', array( DataTools::class, 'add_abilities' ) );
		add_action( 'gdp_weekly_tasks', array( BackupService::class, 'scheduled' ) );
		add_action( 'gdp_daily_tasks', array( $this, 'daily' ) );

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( DataPage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( DataPage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( DataPage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( DataPage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra el manejador de operaciones.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		OperationManager::register_handler( new DataHandler() );
	}

	/**
	 * Limpieza diaria de archivos de importación antiguos.
	 *
	 * @return void
	 */
	public function daily(): void {
		DataHandler::cleanup( 7 );
	}
}
