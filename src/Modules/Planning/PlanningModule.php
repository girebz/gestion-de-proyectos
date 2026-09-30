<?php
/**
 * Módulo de planificación y tiempo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Modules\ModuleInterface;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Estructura de desglose del trabajo, cronograma con dependencias y ruta
 * crítica, carta Gantt, tablero, calendarios laborales, líneas base,
 * avances, alertas e informe semanal.
 *
 * Componentes:
 * - GDP\Planning\* : motor puro (calendario, feriados, programación).
 * - Repositorios   : actividades, dependencias, calendarios, líneas base,
 *                    asignaciones y avances.
 * - ScheduleService: recálculo y caché de fechas.
 * - ActivityHandler: operaciones en dos tiempos (conector e importación).
 * - PlanningTools  : herramientas del conector.
 * - PlanningCron   : recálculo diario, alertas e informe semanal.
 * - Admin\PlanningPage: pantallas del panel.
 */
final class PlanningModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'planning';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Planificación y tiempo', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Estructura de desglose, cronograma, dependencias, ruta crítica, carta Gantt, línea base, avances, alertas e informe semanal.', 'gestion-de-proyectos' );
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
		add_filter( 'gdp_connector_abilities', array( PlanningTools::class, 'add_abilities' ) );
		add_action( 'gdp_daily_tasks', array( PlanningCron::class, 'daily' ) );
		add_action( 'gdp_weekly_tasks', array( PlanningCron::class, 'weekly' ) );
		add_action( 'gdp_project_deleted', array( $this, 'cleanup_project' ) );

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( \GDP\Admin\Pages\PlanningPage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( \GDP\Admin\Pages\PlanningPage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( \GDP\Admin\Pages\PlanningPage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( \GDP\Admin\Pages\PlanningPage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra el manejador de operaciones.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		OperationManager::register_handler( new ActivityHandler() );
	}

	/**
	 * Elimina los datos del módulo cuando se elimina un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public function cleanup_project( int $project_id ): void {
		global $wpdb;

		DependencyRepository::delete_for_project( $project_id );
		AssignmentRepository::delete_for_project( $project_id );
		ProgressRepository::delete_for_project( $project_id );
		BaselineRepository::delete_for_project( $project_id );
		CalendarRepository::delete_for_project( $project_id );
		SnapshotRepository::delete_for_project( $project_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( \GDP\Core\Schema::table( 'activities' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}
}
