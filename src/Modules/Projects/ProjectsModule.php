<?php
/**
 * Módulo de proyectos (núcleo).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Projects;

use GDP\Modules\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Entidad raíz del sistema: todo registro pertenece a un proyecto. Este módulo
 * aporta la ficha del proyecto, sus miembros y su configuración; las pantallas
 * viven en GDP\Admin y las operaciones en GDP\Operations\Handlers\ProjectHandler.
 */
final class ProjectsModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'projects';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Proyectos', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Ficha del proyecto, equipo con perfiles por proyecto, configuración y módulos activos.', 'gestion-de-proyectos' );
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
		// Las tablas se crean en el instalador del núcleo y las pantallas en Admin.
		// Este método queda para ganchos propios del módulo (por ejemplo, limpieza
		// al eliminar un proyecto, que los demás módulos escuchan en gdp_project_deleted).
	}
}
