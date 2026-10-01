<?php
/**
 * Registro de módulos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules;

use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\DocumentsModule;
use GDP\Modules\Planning\PlanningModule;
use GDP\Modules\Projects\ProjectsModule;

defined( 'ABSPATH' ) || exit;

/**
 * Mantiene la lista de módulos disponibles y planificados, y resuelve cuáles
 * están activos en cada proyecto.
 */
final class Registry {

	/**
	 * Módulos instanciados, por slug.
	 *
	 * @var array<string,ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Módulos previstos en la hoja de ruta que aún no tienen implementación.
	 * Se muestran en los ajustes y en el diagnóstico como "planificado".
	 *
	 * @return array<string,array{label:string,description:string,stage:int,core:bool}>
	 */
	public static function roadmap(): array {
		return array(
			'planning'     => array(
				'label'       => __( 'Planificación y tiempo', 'gestion-de-proyectos' ),
				'description' => __( 'Estructura de desglose, cronograma, dependencias, ruta crítica, carta Gantt, línea base, valor ganado.', 'gestion-de-proyectos' ),
				'stage'       => 1,
				'core'        => true,
			),
			'documents'    => array(
				'label'       => __( 'Control documental', 'gestion-de-proyectos' ),
				'description' => __( 'Cartas, oficios, contratos y órdenes con numeración, versiones, vínculos cruzados y plazos de respuesta.', 'gestion-de-proyectos' ),
				'stage'       => 1,
				'core'        => true,
			),
			'procurement'  => array(
				'label'       => __( 'Adquisiciones y presupuesto', 'gestion-de-proyectos' ),
				'description' => __( 'Ciclo de compra, proveedores, cotizaciones, partidas, unidades de fomento y rendición.', 'gestion-de-proyectos' ),
				'stage'       => 1,
				'core'        => true,
			),
			'meetings'     => array(
				'label'       => __( 'Reuniones y acuerdos', 'gestion-de-proyectos' ),
				'description' => __( 'Actas, acuerdos convertidos en tareas y seguimiento de cumplimiento.', 'gestion-de-proyectos' ),
				'stage'       => 1,
				'core'        => true,
			),
			'data'         => array(
				'label'       => __( 'Exportación, importación y respaldo', 'gestion-de-proyectos' ),
				'description' => __( 'Respaldos, exportación en CSV, XLSX y JSON, diccionario de datos e importación con salvaguardas.', 'gestion-de-proyectos' ),
				'stage'       => 1,
				'core'        => true,
			),
			'lab'          => array(
				'label'       => __( 'Muestras, ensayos, mezclas y probetas', 'gestion-de-proyectos' ),
				'description' => __( 'Cadena de custodia, resultados como datos, umbrales, dosificaciones y resistencia.', 'gestion-de-proyectos' ),
				'stage'       => 2,
				'core'        => false,
			),
			'evidence'     => array(
				'label'       => __( 'Evidencias para acreditación y rendición', 'gestion-de-proyectos' ),
				'description' => __( 'Criterios, medios de verificación, carpetas de evidencia e informes por criterio.', 'gestion-de-proyectos' ),
				'stage'       => 3,
				'core'        => false,
			),
			'publications' => array(
				'label'       => __( 'Publicaciones y difusión', 'gestion-de-proyectos' ),
				'description' => __( 'Artículos, ponencias, difusión y borradores automáticos para el blog.', 'gestion-de-proyectos' ),
				'stage'       => 3,
				'core'        => false,
			),
		);
	}

	/**
	 * Instancia y registra los módulos implementados.
	 *
	 * @return void
	 */
	public function register_all(): void {
		$this->add( new ProjectsModule() );
		$this->add( new PlanningModule() );
		$this->add( new DocumentsModule() );

		/**
		 * Permite registrar módulos adicionales (propios o de extensiones).
		 *
		 * @param Registry $registry Registro.
		 */
		do_action( 'gdp_register_modules', $this );

		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}

	/**
	 * Añade un módulo.
	 *
	 * @param ModuleInterface $module Módulo.
	 * @return void
	 */
	public function add( ModuleInterface $module ): void {
		$this->modules[ $module->slug() ] = $module;
	}

	/**
	 * Módulos implementados.
	 *
	 * @return array<string,ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Un módulo por slug.
	 *
	 * @param string $slug Identificador.
	 * @return ModuleInterface|null
	 */
	public function get( string $slug ): ?ModuleInterface {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * Indica si un módulo está activo en un proyecto.
	 *
	 * Los módulos del núcleo siempre lo están; los demás dependen de la
	 * configuración del proyecto (settings.modules).
	 *
	 * @param string $slug       Módulo.
	 * @param int    $project_id Proyecto.
	 * @return bool
	 */
	public function is_enabled( string $slug, int $project_id ): bool {
		$module = $this->get( $slug );
		if ( $module && $module->is_core() ) {
			return true;
		}

		$roadmap = self::roadmap();
		if ( isset( $roadmap[ $slug ] ) && $roadmap[ $slug ]['core'] ) {
			return true;
		}

		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return false;
		}

		$enabled = $project['settings']['modules'] ?? array();

		return is_array( $enabled ) && in_array( $slug, $enabled, true );
	}

	/**
	 * Estado de cada módulo para el diagnóstico y el conector.
	 *
	 * @return array<int,array{slug:string,label:string,stage:int,core:bool,status:string}>
	 */
	public function status(): array {
		$rows = array();

		foreach ( $this->modules as $slug => $module ) {
			$rows[] = array(
				'slug'   => $slug,
				'label'  => $module->label(),
				'stage'  => 1,
				'core'   => $module->is_core(),
				'status' => 'disponible',
			);
		}

		foreach ( self::roadmap() as $slug => $info ) {
			if ( isset( $this->modules[ $slug ] ) ) {
				continue;
			}
			$rows[] = array(
				'slug'   => $slug,
				'label'  => $info['label'],
				'stage'  => $info['stage'],
				'core'   => $info['core'],
				'status' => 'planificado',
			);
		}

		return $rows;
	}
}
