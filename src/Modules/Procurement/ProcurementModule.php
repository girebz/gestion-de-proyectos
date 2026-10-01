<?php
/**
 * Módulo de adquisiciones y presupuesto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Admin\Pages\ProcurementPage;
use GDP\Modules\ModuleInterface;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Ciclo de compra por etapas con historial, proveedores, cotizaciones con
 * ítems y comparación, elección, aprobación, orden con comprobación de
 * reajuste en unidades de fomento, presupuesto por partida (asignado,
 * comprometido, ejecutado, saldo), valores diarios de la unidad de fomento y
 * exportación para la rendición.
 *
 * Componentes:
 * - SupplierRepository, PurchaseRepository, QuoteRepository, BudgetService.
 * - UfMath (puro) y UfService (valores, fuente pública, conversión).
 * - ProcurementChecks: comprobaciones al emitir la orden.
 * - PurchaseHandler: operaciones en dos tiempos.
 * - ProcurementTools: herramientas del conector.
 * - ProcurementCron: valor diario, avisos y eventos del calendario.
 * - ProcurementExport: rendición en CSV y XLSX.
 * - Admin\ProcurementPage: pantallas del panel.
 */
final class ProcurementModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'procurement';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Adquisiciones y presupuesto', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Ciclo de compra, proveedores, cotizaciones, partidas, unidades de fomento y rendición.', 'gestion-de-proyectos' );
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
		add_filter( 'gdp_connector_abilities', array( ProcurementTools::class, 'add_abilities' ) );
		add_filter( 'gdp_link_entities', array( $this, 'link_entities' ) );
		add_filter( 'gdp_calendar_events', array( ProcurementCron::class, 'calendar_events' ), 10, 4 );
		add_action( 'gdp_daily_tasks', array( ProcurementCron::class, 'daily' ) );
		add_action( 'gdp_project_deleted', array( $this, 'cleanup_project' ) );

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( ProcurementPage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( ProcurementPage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( ProcurementPage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( ProcurementPage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra el manejador de operaciones.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		OperationManager::register_handler( new PurchaseHandler() );
	}

	/**
	 * Entidad enlazable: compra.
	 *
	 * @param array<string,array<string,mixed>> $entities Entidades.
	 * @return array<string,array<string,mixed>>
	 */
	public function link_entities( array $entities ): array {
		$entities['purchase'] = array(
			'label'   => __( 'compra', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$p = PurchaseRepository::find( $id );
				if ( ! $p ) {
					return null;
				}
				return array(
					'title'      => $p['title'],
					'code'       => $p['code'],
					'url'        => ProcurementPage::url( $p['project_id'], array( 'view' => 'show', 'id' => $p['id'] ) ),
					'project_id' => $p['project_id'],
				);
			},
			'search'  => static function ( int $project_id, string $text ): array {
				$out = array();
				foreach ( PurchaseRepository::for_project( $project_id, array( 'search' => $text, 'limit' => 200 ) ) as $p ) {
					$out[] = array( 'id' => $p['id'], 'code' => $p['code'], 'title' => $p['title'] );
				}
				return $out;
			},
		);

		return $entities;
	}

	/**
	 * Elimina los datos del módulo cuando se elimina un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public function cleanup_project( int $project_id ): void {
		global $wpdb;

		PurchaseRepository::delete_for_project( $project_id );
		BudgetService::delete_for_project( $project_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( \GDP\Core\Schema::table( 'suppliers' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}
}
