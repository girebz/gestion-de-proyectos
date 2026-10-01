<?php
/**
 * Módulo de control documental.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Admin\Pages\DocumentsPage;
use GDP\Admin\Pages\PlanningPage;
use GDP\Modules\ModuleInterface;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Cartas, oficios, contratos, órdenes y demás documentos del proyecto:
 * numeración correlativa por tipo, versiones de archivo en el directorio
 * privado, vínculos con actividades y otros documentos, plazos de respuesta
 * con alerta, referencias en sistemas externos y borradores de carta en LaTeX
 * y Word.
 *
 * Componentes:
 * - DocumentRepository, VersionRepository, LinkRepository, ExternalRefRepository.
 * - DocumentHandler: operaciones en dos tiempos (panel, conector).
 * - DocumentsTools: herramientas del conector.
 * - DocumentsCron: avisos de vencimiento y eventos del calendario.
 * - LetterTemplate: borradores en LaTeX y Word.
 * - Admin\DocumentsPage: pantallas del panel.
 */
final class DocumentsModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'documents';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Control documental', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Cartas, oficios, contratos y órdenes con numeración correlativa, versiones, vínculos cruzados, plazos de respuesta y referencias externas.', 'gestion-de-proyectos' );
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
		add_filter( 'gdp_connector_abilities', array( DocumentsTools::class, 'add_abilities' ) );
		add_filter( 'gdp_link_entities', array( $this, 'link_entities' ) );
		add_filter( 'gdp_calendar_events', array( DocumentsCron::class, 'calendar_events' ), 10, 4 );
		add_action( 'gdp_daily_tasks', array( DocumentsCron::class, 'daily' ) );
		add_action( 'gdp_project_deleted', array( $this, 'cleanup_project' ) );
		VersionRepository::register();

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( DocumentsPage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( DocumentsPage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( DocumentsPage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( DocumentsPage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra el manejador de operaciones.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		OperationManager::register_handler( new DocumentHandler() );
	}

	/**
	 * Entidades enlazables: documentos y actividades.
	 *
	 * @param array<string,array<string,mixed>> $entities Entidades.
	 * @return array<string,array<string,mixed>>
	 */
	public function link_entities( array $entities ): array {
		$entities['document'] = array(
			'label'   => __( 'documento', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$d = DocumentRepository::find( $id );
				if ( ! $d ) {
					return null;
				}
				return array(
					'title'      => $d['subject'],
					'code'       => $d['number'],
					'url'        => DocumentsPage::url( $d['project_id'], array( 'view' => 'show', 'id' => $d['id'] ) ),
					'project_id' => $d['project_id'],
				);
			},
			'search'  => static function ( int $project_id, string $text ): array {
				$out = array();
				foreach ( DocumentRepository::for_project( $project_id, array( 'search' => $text, 'limit' => 20 ) ) as $d ) {
					$out[] = array( 'id' => $d['id'], 'code' => $d['number'], 'title' => $d['subject'] );
				}
				return $out;
			},
		);
		$entities['activity'] = array(
			'label'   => __( 'actividad', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$a = ActivityRepository::find( $id );
				if ( ! $a ) {
					return null;
				}
				return array(
					'title'      => $a['name'],
					'code'       => $a['code'],
					'url'        => PlanningPage::url( $a['project_id'], 'edit', array( 'id' => $a['id'] ) ),
					'project_id' => $a['project_id'],
				);
			},
			'search'  => static function ( int $project_id, string $text ): array {
				$out = array();
				foreach ( ActivityRepository::for_project( $project_id, array( 'search' => $text ) ) as $a ) {
					$out[] = array( 'id' => $a['id'], 'code' => $a['code'], 'title' => $a['name'] );
				}
				return array_slice( $out, 0, 20 );
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

		DocumentRepository::delete_for_project( $project_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( \GDP\Core\Schema::table( 'links' ), array( 'project_id' => $project_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( \GDP\Core\Schema::table( 'external_refs' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}
}
