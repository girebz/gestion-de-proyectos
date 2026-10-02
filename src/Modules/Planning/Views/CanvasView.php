<?php
/**
 * Carta Gantt y tablero: contenedores, barra de herramientas y datos para el script.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

use GDP\Core\Catalogs;
use GDP\Domain\Projects\MemberRepository;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\BaselineRepository;
use GDP\Modules\Planning\CalendarRepository;
use GDP\Modules\Planning\DependencyRepository;
use GDP\Modules\Planning\ScheduleService;

defined( 'ABSPATH' ) || exit;

/**
 * La carta Gantt y el tablero los dibuja planning.js a partir de los datos que
 * entrega data(); aquí se imprime el marco (barra de herramientas y
 * contenedor) y se prepara la configuración del script, igual en el panel y en
 * el sitio.
 */
final class CanvasView {

	/**
	 * Imprime la barra de herramientas y el contenedor de la carta Gantt o del tablero.
	 *
	 * @param int         $project_id Proyecto.
	 * @param ViewContext $ctx        Contexto.
	 * @param string      $kind       gantt|board.
	 * @return void
	 */
	public static function render( int $project_id, ViewContext $ctx, string $kind ): void {
		if ( 0 === ActivityRepository::count( $project_id ) ) {
			echo '<p class="gdp-muted">' . esc_html__( 'El proyecto aún no tiene actividades.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}

		if ( 'gantt' === $kind ) {
			?>
			<div class="gdp-gantt-toolbar">
				<span class="gdp-gantt-zoom" role="group" aria-label="<?php esc_attr_e( 'Escala', 'gestion-de-proyectos' ); ?>"></span>
				<label><input type="checkbox" id="gdp-gantt-baseline" checked> <?php esc_html_e( 'Línea base', 'gestion-de-proyectos' ); ?></label>
				<label><input type="checkbox" id="gdp-gantt-critical" checked> <?php esc_html_e( 'Ruta crítica', 'gestion-de-proyectos' ); ?></label>
				<label><input type="checkbox" id="gdp-gantt-links" checked> <?php esc_html_e( 'Dependencias', 'gestion-de-proyectos' ); ?></label>
				<label><input type="checkbox" id="gdp-gantt-only-critical"> <?php esc_html_e( 'Solo críticas', 'gestion-de-proyectos' ); ?></label>
				<select id="gdp-gantt-front" aria-label="<?php esc_attr_e( 'Frente', 'gestion-de-proyectos' ); ?>"></select>
				<select id="gdp-gantt-owner" aria-label="<?php esc_attr_e( 'Responsable', 'gestion-de-proyectos' ); ?>"></select>
				<button type="button" class="button button-small" id="gdp-gantt-print"><?php esc_html_e( 'Imprimir o guardar en PDF', 'gestion-de-proyectos' ); ?></button>
				<span class="gdp-gantt-status" aria-live="polite"></span>
			</div>
			<?php if ( $ctx->editable() ) : ?>
				<p class="gdp-muted gdp-small gdp-no-print"><?php esc_html_e( 'Arrastre una barra para fijar su inicio (restricción "no empezar antes de") o su borde derecho para cambiar la duración. Arrastre desde el círculo del extremo de una barra hasta otra para crear una dependencia fin a inicio; pulse sobre una flecha para quitarla; doble clic sobre una barra quita su restricción.', 'gestion-de-proyectos' ); ?></p>
			<?php endif; ?>
			<div id="gdp-gantt" class="gdp-gantt" data-project="<?php echo (int) $project_id; ?>"></div>
			<?php
			return;
		}
		?>
		<div class="gdp-planning-toolbar">
			<label for="gdp-board-group"><?php esc_html_e( 'Agrupar por', 'gestion-de-proyectos' ); ?></label>
			<select id="gdp-board-group">
				<option value="status"><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></option>
				<option value="front"><?php esc_html_e( 'Frente de trabajo', 'gestion-de-proyectos' ); ?></option>
				<option value="owner"><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></option>
			</select>
			<label><input type="checkbox" id="gdp-board-hide-done"> <?php esc_html_e( 'Ocultar terminadas y canceladas', 'gestion-de-proyectos' ); ?></label>
			<?php if ( $ctx->editable() ) : ?>
				<span class="gdp-muted gdp-small"><?php esc_html_e( 'Arrastre las tarjetas entre columnas: cambia el estado, el frente o el responsable según la agrupación. Marcar como terminada fija el avance en 100 % y la fecha real de término en hoy.', 'gestion-de-proyectos' ); ?></span>
			<?php endif; ?>
		</div>
		<div id="gdp-board" class="gdp-board" data-project="<?php echo (int) $project_id; ?>"></div>
		<?php
	}

	/**
	 * Configuración del script planning.js (sin los datos del proyecto).
	 *
	 * @param int    $project_id Proyecto (0 si no hay).
	 * @param string $view       Vista activa.
	 * @param bool   $can_edit   Si se permite arrastrar, enlazar y guardar.
	 * @param string $edit_url   Enlace de edición con id=0 (se sustituye en el script).
	 * @return array<string,mixed>
	 */
	public static function config( int $project_id, string $view, bool $can_edit, string $edit_url = '' ): array {
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'gdp_planning_ajax' ),
			'projectId' => $project_id,
			'view'      => $view,
			'canEdit'   => $can_edit,
			'editUrl'   => $can_edit ? $edit_url : '',
			'strings'   => array(
				'saving'      => __( 'Guardando…', 'gestion-de-proyectos' ),
				'error'       => __( 'No se pudo guardar el cambio.', 'gestion-de-proyectos' ),
				'today'       => __( 'Hoy', 'gestion-de-proyectos' ),
				'baseline'    => __( 'Línea base', 'gestion-de-proyectos' ),
				'days'        => __( 'días hábiles', 'gestion-de-proyectos' ),
				'fixed'       => __( 'Con fechas reales: no se puede arrastrar.', 'gestion-de-proyectos' ),
				'zoomDay'     => __( 'Día', 'gestion-de-proyectos' ),
				'zoomWeek'    => __( 'Semana', 'gestion-de-proyectos' ),
				'zoomMonth'   => __( 'Mes', 'gestion-de-proyectos' ),
				'zoomQuarter' => __( 'Trimestre', 'gestion-de-proyectos' ),
				'allFronts'   => __( 'Todos los frentes', 'gestion-de-proyectos' ),
				'allOwners'   => __( 'Todos los responsables', 'gestion-de-proyectos' ),
				'noFront'     => __( 'Sin frente', 'gestion-de-proyectos' ),
				'noOwner'     => __( 'Sin responsable', 'gestion-de-proyectos' ),
				'linkTo'      => __( 'Suelte sobre la actividad sucesora', 'gestion-de-proyectos' ),
				/* translators: notación de la dependencia. */
				'unlink'      => __( '¿Quitar la dependencia %s?', 'gestion-de-proyectos' ),
				'collapse'    => __( 'Contraer', 'gestion-de-proyectos' ),
				'expand'      => __( 'Expandir', 'gestion-de-proyectos' ),
				'noDates'     => __( 'Sin fechas programadas.', 'gestion-de-proyectos' ),
				'activity'    => __( 'Actividad', 'gestion-de-proyectos' ),
				'start'       => __( 'Inicio', 'gestion-de-proyectos' ),
				'end'         => __( 'Término', 'gestion-de-proyectos' ),
				'float'       => __( 'Holgura', 'gestion-de-proyectos' ),
				'critical'    => __( 'crítica', 'gestion-de-proyectos' ),
				/* translators: nombre de la actividad. */
				'clear'       => __( '¿Quitar la restricción de fecha de %s?', 'gestion-de-proyectos' ),
				'statuses'    => ActivityRepository::status_labels(),
			),
		);
	}

	/**
	 * Datos del proyecto para el script (carta Gantt y tablero).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function data( int $project_id ): array {
		$result   = ScheduleService::recalculate( $project_id );
		$baseline = BaselineRepository::current( $project_id );
		$base     = $baseline ? BaselineRepository::activities( $baseline['id'] ) : array();
		$calendar = CalendarRepository::build( $project_id );
		$record   = CalendarRepository::effective( $project_id );
		$fronts   = array();
		foreach ( Catalogs::items( Catalogs::WORK_FRONT, $project_id ) as $item ) {
			$fronts[ $item['slug'] ] = $item['label'];
		}

		$activities = array();
		foreach ( $result['activities'] as $a ) {
			$activities[] = array(
				'id'         => $a['id'],
				'code'       => $a['code'],
				'name'       => $a['name'],
				'kind'       => $a['kind'],
				'level'      => $a['level'],
				'parent'     => $a['parent_id'],
				'front'      => $fronts[ $a['work_front'] ] ?? $a['work_front'],
				'frontSlug'  => $a['work_front'],
				'ownerId'    => $a['owner_id'],
				'status'     => $a['status'],
				'priority'   => $a['priority'],
				'duration'   => $a['duration'],
				'percent'    => $a['percent'],
				'start'      => $a['start_date'],
				'end'        => $a['end_date'],
				'lateStart'  => $a['late_start'],
				'lateFinish' => $a['late_finish'],
				'float'      => $a['total_float'],
				'critical'   => $a['is_critical'],
				'fixed'      => ! empty( $a['actual_start'] ) || ! empty( $a['actual_finish'] ),
				'constraint' => $a['constraint_type'],
				'owner'      => ScheduleService::user_name( $a['owner_id'] ),
				'conflicts'  => $a['schedule_conflicts'],
				'baseStart'  => $base[ $a['id'] ]['start_date'] ?? null,
				'baseEnd'    => $base[ $a['id'] ]['end_date'] ?? null,
				'version'    => $a['version'],
			);
		}

		$deps = array();
		foreach ( DependencyRepository::for_project( $project_id ) as $d ) {
			$deps[] = array( 'from' => $d['predecessor_id'], 'to' => $d['successor_id'], 'type' => $d['type'], 'lag' => $d['lag'] );
		}

		$exceptions = array();
		foreach ( $calendar->exceptions() as $date => $working ) {
			$exceptions[ $date ] = $working;
		}

		$owners = array();
		foreach ( $activities as $a ) {
			if ( '' !== $a['owner'] ) {
				$owners[ $a['owner'] ] = $a['owner'];
			}
		}
		ksort( $owners );

		// Opciones para agrupar el tablero: frentes del catálogo y miembros del proyecto.
		$owner_options = array();
		foreach ( MemberRepository::for_project( $project_id ) as $m ) {
			$owner_options[ (string) $m['user_id'] ] = $m['display_name'];
		}
		foreach ( $activities as $a ) {
			if ( $a['ownerId'] > 0 && ! isset( $owner_options[ (string) $a['ownerId'] ] ) ) {
				$owner_options[ (string) $a['ownerId'] ] = $a['owner'];
			}
		}
		asort( $owner_options );

		return array(
			'activities'   => $activities,
			'dependencies' => $deps,
			'calendar'     => array( 'weekdays' => $calendar->weekdays(), 'exceptions' => $exceptions, 'name' => $record ? $record['name'] : '' ),
			'project'      => $result['project'],
			'today'        => current_time( 'Y-m-d' ),
			'baseline'     => $baseline ? $baseline['name'] : null,
			'statuses'     => ActivityRepository::status_labels(),
			'fronts'       => array_values( $fronts ),
			'owners'       => array_values( $owners ),
			'frontOptions' => $fronts,
			'ownerOptions' => $owner_options,
		);
	}
}
