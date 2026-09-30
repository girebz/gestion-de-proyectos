<?php
/**
 * Pantalla de planificación y tiempo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Catalogs;
use GDP\Core\Roles;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\AssignmentRepository;
use GDP\Modules\Planning\BaselineRepository;
use GDP\Modules\Planning\CalendarRepository;
use GDP\Modules\Planning\DependencyRepository;
use GDP\Modules\Planning\ProgressRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Estructura de desglose, formulario de actividad, carta Gantt, tablero y
 * enrutamiento hacia calendarios, líneas base, alertas e informe semanal.
 */
final class PlanningPage extends Page {

	public const SLUG  = 'planning';
	public const VIEWS = array( 'list', 'gantt', 'board', 'edit', 'calendars', 'baselines', 'alerts', 'report' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Slug del menú principal.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		add_submenu_page( $parent, __( 'Planificación', 'gestion-de-proyectos' ), __( 'Planificación', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores de formularios y peticiones.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_activity', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_gdp_delete_activity', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_gdp_activity_progress', array( self::class, 'handle_progress' ) );
		add_action( 'admin_post_gdp_move_activity', array( self::class, 'handle_move' ) );
		add_action( 'admin_post_gdp_set_assignment', array( self::class, 'handle_set_assignment' ) );
		add_action( 'admin_post_gdp_remove_assignment', array( self::class, 'handle_remove_assignment' ) );
		add_action( 'wp_ajax_gdp_planning', array( self::class, 'handle_ajax' ) );

		PlanningCalendarsPage::register_handlers();
		PlanningBaselinesPage::register_handlers();
		PlanningReportPage::register_handlers();
	}

	/**
	 * Estilos y scripts de la pantalla.
	 *
	 * @param string $hook Pantalla.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, Admin::SLUG . '-' . self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'gdp-planning', GDP_URL . 'assets/css/planning.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_script( 'gdp-planning', GDP_URL . 'assets/js/planning.js', array( 'gdp-admin' ), GDP_VERSION, true );

		$project = self::current_project();
		$view    = self::current_view();
		$data    = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'gdp_planning_ajax' ),
			'projectId' => $project ? (int) $project['id'] : 0,
			'view'      => $view,
			'canEdit'   => $project ? Access::can( 'planning.edit', (int) $project['id'] ) : false,
			'editUrl'   => $project ? self::url( (int) $project['id'], 'edit', array( 'id' => 0 ) ) : '',
			'strings'   => array(
				'saving'     => __( 'Guardando…', 'gestion-de-proyectos' ),
				'error'      => __( 'No se pudo guardar el cambio.', 'gestion-de-proyectos' ),
				'today'      => __( 'Hoy', 'gestion-de-proyectos' ),
				'baseline'   => __( 'Línea base', 'gestion-de-proyectos' ),
				'days'       => __( 'días hábiles', 'gestion-de-proyectos' ),
				'fixed'      => __( 'Con fechas reales: no se puede arrastrar.', 'gestion-de-proyectos' ),
				'zoomDay'    => __( 'Día', 'gestion-de-proyectos' ),
				'zoomWeek'   => __( 'Semana', 'gestion-de-proyectos' ),
				'zoomMonth'  => __( 'Mes', 'gestion-de-proyectos' ),
				'collapse'   => __( 'Contraer', 'gestion-de-proyectos' ),
				'expand'     => __( 'Expandir', 'gestion-de-proyectos' ),
				'noDates'    => __( 'Sin fechas programadas.', 'gestion-de-proyectos' ),
				'activity'   => __( 'Actividad', 'gestion-de-proyectos' ),
				'start'      => __( 'Inicio', 'gestion-de-proyectos' ),
				'end'        => __( 'Término', 'gestion-de-proyectos' ),
				'float'      => __( 'Holgura', 'gestion-de-proyectos' ),
				'critical'   => __( 'crítica', 'gestion-de-proyectos' ),
				'clear'      => __( '¿Quitar la restricción de fecha de %s?', 'gestion-de-proyectos' ),
				'statuses'   => ActivityRepository::status_labels(),
			),
		);
		if ( $project && in_array( $view, array( 'gantt', 'board' ), true ) ) {
			$data['data'] = self::client_data( (int) $project['id'] );
		}
		wp_localize_script( 'gdp-planning', 'gdpPlanning', $data );
	}

	/**
	 * URL de una vista.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param string              $view       Vista.
	 * @param array<string,mixed> $args       Parámetros.
	 * @return string
	 */
	public static function url( int $project_id, string $view = 'list', array $args = array() ): string {
		return Admin::url( self::SLUG, array_merge( array( 'project_id' => $project_id, 'view' => $view ), $args ) );
	}

	/**
	 * Proyecto de la petición (o el último usado, o el primero visible).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function current_project(): ?array {
		$id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id <= 0 ) {
			$id = (int) get_user_meta( get_current_user_id(), 'gdp_planning_project', true );
		}
		$visible = Access::visible_project_ids();
		if ( $id > 0 && ( null === $visible || in_array( $id, $visible, true ) ) ) {
			$project = ProjectRepository::find( $id );
			if ( $project ) {
				return $project;
			}
		}
		$projects = ProjectRepository::all( $visible );

		return $projects[0] ?? null;
	}

	/**
	 * Vista de la petición.
	 *
	 * @return string
	 */
	public static function current_view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $view, self::VIEWS, true ) ? $view : 'list';
	}

	/**
	 * Enrutador.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$project = self::current_project();
		$view    = self::current_view();

		if ( ! $project ) {
			self::open( __( 'Planificación', 'gestion-de-proyectos' ) );
			echo '<p class="gdp-muted">' . esc_html__( 'No hay proyectos visibles para su usuario. Cree uno en la pantalla de proyectos.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}

		$project_id = (int) $project['id'];
		if ( ! Access::can( 'planning.view', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para ver la planificación de este proyecto.', 'gestion-de-proyectos' ), 403 );
		}
		update_user_meta( get_current_user_id(), 'gdp_planning_project', $project_id );

		switch ( $view ) {
			case 'edit':
				self::render_form( $project );
				break;
			case 'gantt':
				self::render_canvas( $project, 'gantt' );
				break;
			case 'board':
				self::render_canvas( $project, 'board' );
				break;
			case 'calendars':
				PlanningCalendarsPage::render( $project );
				break;
			case 'baselines':
				PlanningBaselinesPage::render( $project );
				break;
			case 'alerts':
				PlanningReportPage::render_alerts( $project );
				break;
			case 'report':
				PlanningReportPage::render_report( $project );
				break;
			default:
				self::render_list( $project );
		}
	}

	/**
	 * Cabecera común: selector de proyecto y pestañas.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $view    Vista activa.
	 * @param string              $title   Título.
	 * @return void
	 */
	public static function header( array $project, string $view, string $title ): void {
		$project_id = (int) $project['id'];
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		$tabs       = array(
			'list'      => __( 'Actividades', 'gestion-de-proyectos' ),
			'gantt'     => __( 'Carta Gantt', 'gestion-de-proyectos' ),
			'board'     => __( 'Tablero', 'gestion-de-proyectos' ),
			'alerts'    => __( 'Alertas', 'gestion-de-proyectos' ),
			'report'    => __( 'Informe semanal', 'gestion-de-proyectos' ),
			'baselines' => __( 'Líneas base', 'gestion-de-proyectos' ),
			'calendars' => __( 'Calendarios', 'gestion-de-proyectos' ),
		);

		self::open( $title, sprintf( '%s · %s', $project['code'], $project['name'] ) );
		?>
		<div class="gdp-planning-bar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( 'edit' === $view ? 'list' : $view ); ?>">
				<label for="gdp-planning-project" class="screen-reader-text"><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></label>
				<select name="project_id" id="gdp-planning-project" onchange="this.form.submit()">
					<?php foreach ( $projects as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $view || ( 'edit' === $view && 'list' === $slug ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $project_id, $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Tarjeta en la ficha del proyecto.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	public static function project_card( array $p ): void {
		$project_id = (int) $p['id'];
		if ( ! Access::can( 'planning.view', $project_id ) ) {
			return;
		}
		$result = ScheduleService::recalculate( $project_id );
		$stats  = ActivityRepository::stats( $project_id );
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Planificación', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Actividades', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['leaves']; ?> (<?php echo (int) $stats['milestones']; ?> <?php esc_html_e( 'hitos', 'gestion-de-proyectos' ); ?>)</td></tr>
				<tr><th><?php esc_html_e( 'Avance ponderado', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['percent']; ?> %</td></tr>
				<tr><th><?php esc_html_e( 'Término programado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $stats['leaves'] > 0 && $result['project'] ? $result['project']['finish_date'] : '—' ); ?></td></tr>
				<?php if ( $result['project'] && null !== $result['project']['deadline_slack'] && $stats['leaves'] > 0 ) : ?>
					<tr><th><?php esc_html_e( 'Holgura contractual', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $result['project']['deadline_slack'] < 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><?php echo (int) $result['project']['deadline_slack']; ?> <?php esc_html_e( 'días hábiles', 'gestion-de-proyectos' ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Vencidas', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $stats['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $stats['overdue']; ?></td></tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, 'list' ) ); ?>"><?php esc_html_e( 'Actividades', 'gestion-de-proyectos' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, 'gantt' ) ); ?>"><?php esc_html_e( 'Carta Gantt', 'gestion-de-proyectos' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, 'report' ) ); ?>"><?php esc_html_e( 'Informe semanal', 'gestion-de-proyectos' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Lista de actividades (estructura de desglose).
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_list( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'planning.edit', $project_id );
		$result     = ScheduleService::recalculate( $project_id );
		$filters    = array(
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'work_front' => isset( $_GET['work_front'] ) ? sanitize_key( wp_unslash( (string) $_GET['work_front'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'critical'   => ! empty( $_GET['critical'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$all        = $result['activities'];
		$rows       = ActivityRepository::for_project( $project_id, array_filter( $filters ) );
		$index      = array();
		foreach ( $all as $a ) {
			$index[ $a['id'] ] = $a;
		}
		$deps   = DependencyRepository::for_project( $project_id );
		$stats  = ActivityRepository::stats( $project_id );
		$fronts = Catalogs::items( Catalogs::WORK_FRONT, $project_id );
		$labels = ActivityRepository::status_labels();
		$today  = current_time( 'Y-m-d' );

		self::header( $project, 'list', __( 'Planificación', 'gestion-de-proyectos' ) );

		if ( ! empty( $result['errors'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', $result['errors'] ) ) . '</p></div>';
		}
		?>
		<div class="gdp-planning-summary">
			<span><strong><?php echo (int) $stats['leaves']; ?></strong> <?php esc_html_e( 'actividades e hitos', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $stats['percent']; ?> %</strong> <?php esc_html_e( 'avance ponderado', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo esc_html( $stats['leaves'] > 0 && $result['project'] ? $result['project']['finish_date'] : '—' ); ?></strong> <?php esc_html_e( 'término programado', 'gestion-de-proyectos' ); ?></span>
			<?php if ( $result['project'] && null !== $result['project']['deadline_slack'] && $stats['leaves'] > 0 ) : ?>
				<span class="<?php echo $result['project']['deadline_slack'] < 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><strong><?php echo (int) $result['project']['deadline_slack']; ?></strong> <?php esc_html_e( 'días hábiles de holgura contractual', 'gestion-de-proyectos' ); ?></span>
			<?php endif; ?>
			<span class="<?php echo $stats['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo (int) $stats['overdue']; ?></strong> <?php esc_html_e( 'vencidas', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $stats['critical']; ?></strong> <?php esc_html_e( 'críticas', 'gestion-de-proyectos' ); ?></span>
		</div>

		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, 'edit', array( 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nueva actividad', 'gestion-de-proyectos' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, 'edit', array( 'id' => 0, 'kind' => 'summary' ) ) ); ?>"><?php esc_html_e( 'Nueva fase', 'gestion-de-proyectos' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, 'edit', array( 'id' => 0, 'kind' => 'milestone' ) ) ); ?>"><?php esc_html_e( 'Nuevo hito', 'gestion-de-proyectos' ); ?></a>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<input type="hidden" name="view" value="list">
				<select name="status">
					<option value=""><?php esc_html_e( 'Todos los estados', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $labels as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="work_front">
					<option value=""><?php esc_html_e( 'Todos los frentes', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $fronts as $item ) : ?>
						<option value="<?php echo esc_attr( $item['slug'] ); ?>" <?php selected( $filters['work_front'], $item['slug'] ); ?>><?php echo esc_html( $item['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="critical" value="1" <?php checked( $filters['critical'] ); ?>> <?php esc_html_e( 'Solo críticas', 'gestion-de-proyectos' ); ?></label>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
			</form>
		</div>

		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php echo empty( $all ) ? esc_html__( 'El proyecto aún no tiene actividades. Empiece creando las fases (resúmenes) y dentro de ellas las actividades e hitos.', 'gestion-de-proyectos' ) : esc_html__( 'Ninguna actividad coincide con el filtro.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<table class="widefat striped gdp-table gdp-wbs">
			<thead>
				<tr>
					<th class="gdp-wbs__code"><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Frente', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th>
					<th class="gdp-num"><?php esc_html_e( 'Dur.', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Inicio', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></th>
					<th class="gdp-num"><?php esc_html_e( 'Holgura', 'gestion-de-proyectos' ); ?></th>
					<th class="gdp-num"><?php esc_html_e( 'Avance', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Predecesoras', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th>
					<?php if ( $can_edit ) : ?><th class="gdp-wbs__actions"></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $a ) : ?>
				<?php
				$is_summary = 'summary' === $a['kind'];
				$overdue    = $a['end_date'] && $a['end_date'] < $today && ! in_array( $a['status'], array( 'terminada', 'cancelada' ), true ) && ! $is_summary;
				$classes    = array( 'gdp-wbs__row', 'gdp-wbs__row--' . $a['kind'], 'gdp-wbs__row--level-' . min( 6, (int) $a['level'] ) );
				if ( $a['is_critical'] ) {
					$classes[] = 'gdp-wbs__row--critical';
				}
				?>
				<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
					<td class="gdp-wbs__code"><code><?php echo esc_html( $a['code'] ); ?></code></td>
					<td>
						<span class="gdp-wbs__indent" style="--gdp-level: <?php echo (int) $a['level']; ?>"></span>
						<span class="gdp-wbs__icon gdp-wbs__icon--<?php echo esc_attr( $a['kind'] ); ?>" aria-hidden="true"></span>
						<?php if ( $can_edit ) : ?>
							<a href="<?php echo esc_url( self::url( $project_id, 'edit', array( 'id' => $a['id'] ) ) ); ?>"><?php echo esc_html( $a['name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $a['name'] ); ?>
						<?php endif; ?>
						<?php if ( $a['is_critical'] && ! $is_summary ) : ?><span class="gdp-badge gdp-badge--critical"><?php esc_html_e( 'crítica', 'gestion-de-proyectos' ); ?></span><?php endif; ?>
						<?php if ( ! empty( $a['schedule_conflicts'] ) ) : ?><span class="gdp-badge gdp-badge--warn" title="<?php echo esc_attr( implode( ' ', $a['schedule_conflicts'] ) ); ?>"><?php esc_html_e( 'conflicto', 'gestion-de-proyectos' ); ?></span><?php endif; ?>
						<?php if ( 'asap' !== $a['constraint_type'] && $a['constraint_date'] ) : ?><span class="gdp-muted gdp-small"><?php echo esc_html( ActivityRepository::constraint_labels()[ $a['constraint_type'] ] . ' ' . $a['constraint_date'] ); ?></span><?php endif; ?>
					</td>
					<td><?php echo esc_html( $a['work_front'] ? Catalogs::label( Catalogs::WORK_FRONT, $a['work_front'], $project_id ) : '' ); ?></td>
					<td><?php echo $is_summary ? '' : self::badge( $a['status'], $labels[ $a['status'] ] ?? $a['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="gdp-num"><?php echo 'milestone' === $a['kind'] ? '◆' : (int) $a['duration']; ?></td>
					<td><?php echo esc_html( 'milestone' === $a['kind'] ? '' : (string) $a['start_date'] ); ?></td>
					<td class="<?php echo $overdue ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( (string) $a['end_date'] ); ?></td>
					<td class="gdp-num <?php echo null !== $a['total_float'] && $a['total_float'] < 0 ? 'gdp-text-danger' : ''; ?>"><?php echo $is_summary || null === $a['total_float'] ? '' : (int) $a['total_float']; ?></td>
					<td class="gdp-num">
						<span class="gdp-progress" title="<?php echo (int) $a['percent']; ?> %"><span class="gdp-progress__bar" style="width: <?php echo (int) $a['percent']; ?>%"></span></span>
						<?php echo (int) $a['percent']; ?> %
					</td>
					<td class="gdp-small"><?php echo esc_html( DependencyRepository::notation( $a['id'], $index, $deps ) ); ?></td>
					<td><?php echo esc_html( ScheduleService::user_name( $a['owner_id'] ) ); ?></td>
					<?php if ( $can_edit ) : ?>
					<td class="gdp-wbs__actions">
						<?php if ( ! $is_summary ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form gdp-progress-form">
							<?php wp_nonce_field( 'gdp_activity_progress_' . $a['id'] ); ?>
							<input type="hidden" name="action" value="gdp_activity_progress">
							<input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
							<input type="number" name="percent" min="0" max="100" step="5" value="<?php echo (int) $a['percent']; ?>" aria-label="<?php esc_attr_e( 'Avance', 'gestion-de-proyectos' ); ?>">
							<button type="submit" class="button button-small" title="<?php esc_attr_e( 'Guardar avance', 'gestion-de-proyectos' ); ?>">✓</button>
						</form>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form gdp-move-form">
							<?php wp_nonce_field( 'gdp_move_activity_' . $a['id'] ); ?>
							<input type="hidden" name="action" value="gdp_move_activity">
							<input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
							<button type="submit" name="direction" value="up" class="button-link" title="<?php esc_attr_e( 'Subir', 'gestion-de-proyectos' ); ?>">↑</button>
							<button type="submit" name="direction" value="down" class="button-link" title="<?php esc_attr_e( 'Bajar', 'gestion-de-proyectos' ); ?>">↓</button>
							<button type="submit" name="direction" value="indent" class="button-link" title="<?php esc_attr_e( 'Anidar en la fase anterior', 'gestion-de-proyectos' ); ?>">→</button>
							<button type="submit" name="direction" value="outdent" class="button-link" title="<?php esc_attr_e( 'Sacar de la fase', 'gestion-de-proyectos' ); ?>">←</button>
						</form>
					</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="gdp-muted gdp-small"><?php esc_html_e( 'Duraciones y holguras en días hábiles del calendario del proyecto. Un hito se fecha el día en que se alcanza. Las fechas se recalculan automáticamente a partir de las dependencias, restricciones, fechas reales y calendario.', 'gestion-de-proyectos' ); ?></p>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Contenedor de la carta Gantt o del tablero (los dibuja el script).
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $kind    gantt|board.
	 * @return void
	 */
	private static function render_canvas( array $project, string $kind ): void {
		$project_id = (int) $project['id'];
		self::header( $project, $kind, 'gantt' === $kind ? __( 'Carta Gantt', 'gestion-de-proyectos' ) : __( 'Tablero', 'gestion-de-proyectos' ) );

		if ( 0 === ActivityRepository::count( $project_id ) ) {
			echo '<p class="gdp-muted">' . esc_html__( 'El proyecto aún no tiene actividades.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}

		if ( 'gantt' === $kind ) {
			?>
			<div class="gdp-gantt-toolbar">
				<span class="gdp-gantt-zoom" role="group" aria-label="<?php esc_attr_e( 'Escala', 'gestion-de-proyectos' ); ?>"></span>
				<label><input type="checkbox" id="gdp-gantt-baseline" checked> <?php esc_html_e( 'Línea base', 'gestion-de-proyectos' ); ?></label>
				<label><input type="checkbox" id="gdp-gantt-critical" checked> <?php esc_html_e( 'Ruta crítica', 'gestion-de-proyectos' ); ?></label>
				<label><input type="checkbox" id="gdp-gantt-links" checked> <?php esc_html_e( 'Dependencias', 'gestion-de-proyectos' ); ?></label>
				<span class="gdp-gantt-status" aria-live="polite"></span>
				<?php if ( Access::can( 'planning.edit', $project_id ) ) : ?>
					<span class="gdp-muted gdp-small"><?php esc_html_e( 'Arrastre una barra para fijar su inicio (restricción "no empezar antes de") o su borde derecho para cambiar la duración.', 'gestion-de-proyectos' ); ?></span>
				<?php endif; ?>
			</div>
			<div id="gdp-gantt" class="gdp-gantt" data-project="<?php echo (int) $project_id; ?>"></div>
			<?php
		} else {
			?>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'Arrastre las tarjetas entre columnas para cambiar su estado. Marcar como terminada fija el avance en 100 % y la fecha real de término en hoy.', 'gestion-de-proyectos' ); ?></p>
			<div id="gdp-board" class="gdp-board" data-project="<?php echo (int) $project_id; ?>"></div>
			<?php
		}
		self::close();
	}

	/**
	 * Datos para el script (Gantt y tablero).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function client_data( int $project_id ): array {
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
				'id'          => $a['id'],
				'code'        => $a['code'],
				'name'        => $a['name'],
				'kind'        => $a['kind'],
				'level'       => $a['level'],
				'parent'      => $a['parent_id'],
				'front'       => $fronts[ $a['work_front'] ] ?? $a['work_front'],
				'status'      => $a['status'],
				'priority'    => $a['priority'],
				'duration'    => $a['duration'],
				'percent'     => $a['percent'],
				'start'       => $a['start_date'],
				'end'         => $a['end_date'],
				'lateStart'   => $a['late_start'],
				'lateFinish'  => $a['late_finish'],
				'float'       => $a['total_float'],
				'critical'    => $a['is_critical'],
				'fixed'       => ! empty( $a['actual_start'] ) || ! empty( $a['actual_finish'] ),
				'constraint'  => $a['constraint_type'],
				'owner'       => ScheduleService::user_name( $a['owner_id'] ),
				'conflicts'   => $a['schedule_conflicts'],
				'baseStart'   => $base[ $a['id'] ]['start_date'] ?? null,
				'baseEnd'     => $base[ $a['id'] ]['end_date'] ?? null,
				'version'     => $a['version'],
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

		return array(
			'activities'   => $activities,
			'dependencies' => $deps,
			'calendar'     => array( 'weekdays' => $calendar->weekdays(), 'exceptions' => $exceptions, 'name' => $record ? $record['name'] : '' ),
			'project'      => $result['project'],
			'today'        => current_time( 'Y-m-d' ),
			'baseline'     => $baseline ? $baseline['name'] : null,
			'statuses'     => ActivityRepository::status_labels(),
		);
	}

	/**
	 * Formulario de actividad.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_form( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para editar la planificación.', 'gestion-de-proyectos' ), 403 );
		}

		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$activity = $id > 0 ? ActivityRepository::find( $id ) : null;
		if ( $id > 0 && ( ! $activity || $activity['project_id'] !== $project_id ) ) {
			wp_die( esc_html__( 'La actividad no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$is_new = null === $activity;
		$kind   = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : 'activity'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$a      = $activity ?? array(
			'id'              => 0,
			'parent_id'       => isset( $_GET['parent_id'] ) ? (int) $_GET['parent_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'name'            => '',
			'description'     => '',
			'kind'            => in_array( $kind, ActivityRepository::KINDS, true ) ? $kind : 'activity',
			'work_front'      => '',
			'status'          => 'pendiente',
			'priority'        => 2,
			'duration'        => 'milestone' === $kind ? 0 : 5,
			'constraint_type' => 'asap',
			'constraint_date' => '',
			'actual_start'    => '',
			'actual_finish'   => '',
			'percent'         => 0,
			'owner_id'        => 0,
			'deliverable'     => '',
			'budget_line'     => '',
			'cost_planned'    => null,
			'notes'           => '',
			'version'         => 0,
			'code'            => '',
			'schedule_conflicts' => array(),
		);

		$all        = ActivityRepository::for_project( $project_id );
		$summaries  = array_values( array_filter( $all, static fn( array $x ): bool => 'summary' === $x['kind'] && $x['id'] !== $id ) );
		$candidates = array_values( array_filter( $all, static fn( array $x ): bool => 'summary' !== $x['kind'] && $x['id'] !== $id ) );
		$preds      = $id > 0 ? DependencyRepository::predecessors( $id ) : array();
		$assigns    = $id > 0 ? AssignmentRepository::for_activity( $id ) : array();
		$history    = $id > 0 ? ProgressRepository::for_activity( $id, 15 ) : array();
		$fronts     = Catalogs::items( Catalogs::WORK_FRONT, $project_id );
		$lines      = Catalogs::items( Catalogs::BUDGET_LINE, $project_id );

		self::header( $project, 'edit', $is_new ? __( 'Nueva actividad', 'gestion-de-proyectos' ) : sprintf( '%s %s', $a['code'], $a['name'] ) );
		?>
		<div class="gdp-grid gdp-grid--form">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form">
			<?php wp_nonce_field( 'gdp_save_activity_' . $id ); ?>
			<input type="hidden" name="action" value="gdp_save_activity">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="expected_version" value="<?php echo (int) $a['version']; ?>">

			<?php if ( ! empty( $a['schedule_conflicts'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( implode( ' ', $a['schedule_conflicts'] ) ); ?></p></div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gdp-act-name"><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?> *</label></th>
					<td><input type="text" id="gdp-act-name" name="name" class="large-text" required value="<?php echo esc_attr( $a['name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-kind"><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select id="gdp-act-kind" name="kind">
							<?php foreach ( ActivityRepository::kind_labels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $a['kind'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Los resúmenes agrupan actividades y toman sus fechas de ellas; los hitos tienen duración cero.', 'gestion-de-proyectos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-parent"><?php esc_html_e( 'Dentro de', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select id="gdp-act-parent" name="parent_id">
							<option value="0"><?php esc_html_e( 'Primer nivel', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $summaries as $s ) : ?>
								<option value="<?php echo (int) $s['id']; ?>" <?php selected( (int) $a['parent_id'], $s['id'] ); ?>><?php echo esc_html( str_repeat( '· ', (int) $s['level'] ) . $s['code'] . ' ' . $s['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-front"><?php esc_html_e( 'Frente de trabajo', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select id="gdp-act-front" name="work_front">
							<option value=""><?php esc_html_e( 'Sin frente', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $fronts as $item ) : ?>
								<option value="<?php echo esc_attr( $item['slug'] ); ?>" <?php selected( $a['work_front'], $item['slug'] ); ?>><?php echo esc_html( $item['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="priority" aria-label="<?php esc_attr_e( 'Prioridad', 'gestion-de-proyectos' ); ?>">
							<?php foreach ( ActivityRepository::priority_labels() as $value => $label ) : ?>
								<option value="<?php echo (int) $value; ?>" <?php selected( (int) $a['priority'], $value ); ?>><?php echo esc_html( sprintf( __( 'Prioridad %s', 'gestion-de-proyectos' ), mb_strtolower( $label ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="gdp-act-leaf">
					<th scope="row"><label for="gdp-act-duration"><?php esc_html_e( 'Duración (días hábiles)', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="number" id="gdp-act-duration" name="duration" min="0" max="3650" value="<?php echo (int) $a['duration']; ?>" class="small-text"></td>
				</tr>
				<tr class="gdp-act-leaf">
					<th scope="row"><label for="gdp-act-constraint"><?php esc_html_e( 'Restricción de fecha', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select id="gdp-act-constraint" name="constraint_type">
							<?php foreach ( ActivityRepository::constraint_labels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $a['constraint_type'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="date" name="constraint_date" value="<?php echo esc_attr( (string) $a['constraint_date'] ); ?>" aria-label="<?php esc_attr_e( 'Fecha de la restricción', 'gestion-de-proyectos' ); ?>">
						<p class="description"><?php esc_html_e( 'Sin restricción, la actividad se programa lo antes posible según sus predecesoras.', 'gestion-de-proyectos' ); ?></p>
					</td>
				</tr>
				<tr class="gdp-act-leaf">
					<th scope="row"><?php esc_html_e( 'Predecesoras', 'gestion-de-proyectos' ); ?></th>
					<td>
						<table class="gdp-deps" id="gdp-deps">
							<thead><tr><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Retraso', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
							<tbody>
							<?php foreach ( array_merge( $preds, array( null ) ) as $i => $d ) : ?>
								<tr class="gdp-deps__row <?php echo null === $d ? 'gdp-deps__row--template' : ''; ?>">
									<td>
										<select name="pred_id[]">
											<option value="0"><?php esc_html_e( '(ninguna)', 'gestion-de-proyectos' ); ?></option>
											<?php foreach ( $candidates as $c ) : ?>
												<option value="<?php echo (int) $c['id']; ?>" <?php selected( $d ? $d['predecessor_id'] : 0, $c['id'] ); ?>><?php echo esc_html( $c['code'] . ' ' . $c['name'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="pred_type[]">
											<?php foreach ( DependencyRepository::type_labels() as $t => $label ) : ?>
												<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $d ? $d['type'] : 'FS', $t ); ?>><?php echo esc_html( $t . ' · ' . $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="number" name="pred_lag[]" min="-365" max="365" value="<?php echo $d ? (int) $d['lag'] : 0; ?>" class="small-text"></td>
									<td><button type="button" class="button-link gdp-deps__remove" title="<?php esc_attr_e( 'Quitar', 'gestion-de-proyectos' ); ?>">✕</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<button type="button" class="button button-small" id="gdp-deps-add"><?php esc_html_e( 'Añadir predecesora', 'gestion-de-proyectos' ); ?></button>
						<p class="description"><?php esc_html_e( 'FS: empieza cuando la otra termina. SS: empiezan juntas. FF: terminan juntas. SF: termina cuando la otra empieza. El retraso se expresa en días hábiles; negativo es adelanto.', 'gestion-de-proyectos' ); ?></p>
					</td>
				</tr>
				<tr class="gdp-act-leaf">
					<th scope="row"><?php esc_html_e( 'Estado y avance', 'gestion-de-proyectos' ); ?></th>
					<td>
						<select name="status" aria-label="<?php esc_attr_e( 'Estado', 'gestion-de-proyectos' ); ?>">
							<?php foreach ( ActivityRepository::status_labels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $a['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" name="percent" min="0" max="100" value="<?php echo (int) $a['percent']; ?>" class="small-text" aria-label="<?php esc_attr_e( 'Avance', 'gestion-de-proyectos' ); ?>"> %
					</td>
				</tr>
				<tr class="gdp-act-leaf">
					<th scope="row"><?php esc_html_e( 'Fechas reales', 'gestion-de-proyectos' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Inicio', 'gestion-de-proyectos' ); ?> <input type="date" name="actual_start" value="<?php echo esc_attr( (string) $a['actual_start'] ); ?>"></label>
						<label><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?> <input type="date" name="actual_finish" value="<?php echo esc_attr( (string) $a['actual_finish'] ); ?>"></label>
						<p class="description"><?php esc_html_e( 'Las fechas reales fijan la programación: una actividad terminada ya no se mueve.', 'gestion-de-proyectos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-owner"><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'             => 'owner_id',
								'id'               => 'gdp-act-owner',
								'show'             => 'display_name_with_login',
								'orderby'          => 'display_name',
								'selected'         => (int) $a['owner_id'],
								'show_option_none' => __( 'Sin responsable', 'gestion-de-proyectos' ),
								'option_none_value' => 0,
								'include_selected' => true,
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-deliverable"><?php esc_html_e( 'Entregable', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-act-deliverable" name="deliverable" class="large-text" value="<?php echo esc_attr( $a['deliverable'] ); ?>" placeholder="<?php esc_attr_e( 'Informe, muestra, prototipo, acta…', 'gestion-de-proyectos' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-budget"><?php esc_html_e( 'Partida y costo planificado', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select id="gdp-act-budget" name="budget_line">
							<option value=""><?php esc_html_e( 'Sin partida', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $lines as $item ) : ?>
								<option value="<?php echo esc_attr( $item['slug'] ); ?>" <?php selected( $a['budget_line'], $item['slug'] ); ?>><?php echo esc_html( $item['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" step="0.01" min="0" name="cost_planned" value="<?php echo esc_attr( null === $a['cost_planned'] ? '' : (string) $a['cost_planned'] ); ?>" aria-label="<?php esc_attr_e( 'Costo planificado', 'gestion-de-proyectos' ); ?>"> <?php echo esc_html( (string) $project['currency'] ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-description"><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></label></th>
					<td><textarea id="gdp-act-description" name="description" rows="4" class="large-text"><?php echo esc_textarea( (string) $a['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-act-notes"><?php esc_html_e( 'Notas de seguimiento', 'gestion-de-proyectos' ); ?></label></th>
					<td><textarea id="gdp-act-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $a['notes'] ); ?></textarea></td>
				</tr>
				<?php if ( ! $is_new ) : ?>
				<tr class="gdp-act-leaf">
					<th scope="row"><label for="gdp-act-note"><?php esc_html_e( 'Nota de este avance', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-act-note" name="progress_note" class="large-text" placeholder="<?php esc_attr_e( 'Se anota en el historial si cambian el avance, el estado o las fechas reales.', 'gestion-de-proyectos' ); ?>"></td>
				</tr>
				<?php endif; ?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $is_new ? esc_html__( 'Crear', 'gestion-de-proyectos' ) : esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ); ?></button>
				<?php if ( $is_new ) : ?>
					<button type="submit" name="another" value="1" class="button"><?php esc_html_e( 'Crear y añadir otra', 'gestion-de-proyectos' ); ?></button>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, 'list' ) ); ?>"><?php esc_html_e( 'Volver a la lista', 'gestion-de-proyectos' ); ?></a>
			</p>
		</form>

		<?php if ( ! $is_new ) : ?>
		<div>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Programación', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( $a['code'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Inicio programado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) ( $a['start_date'] ?? '—' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Término programado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) ( $a['end_date'] ?? '—' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Inicio y término tardíos', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( ( $a['late_start'] ?? '—' ) . ' / ' . ( $a['late_finish'] ?? '—' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Holgura total / libre', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( ( null === $a['total_float'] ? '—' : $a['total_float'] ) . ' / ' . ( null === $a['free_float'] ? '—' : $a['free_float'] ) ); ?> <?php esc_html_e( 'días hábiles', 'gestion-de-proyectos' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Ruta crítica', 'gestion-de-proyectos' ); ?></th><td><?php echo $a['is_critical'] ? esc_html__( 'Sí', 'gestion-de-proyectos' ) : esc_html__( 'No', 'gestion-de-proyectos' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Versión', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $a['version']; ?></td></tr>
				</table>
				<?php $succs = DependencyRepository::successors( $id ); ?>
				<?php if ( ! empty( $succs ) ) : ?>
					<h3><?php esc_html_e( 'Sucesoras', 'gestion-de-proyectos' ); ?></h3>
					<ul class="gdp-list">
						<?php foreach ( $succs as $d ) : ?>
							<?php $s = ActivityRepository::find( $d['successor_id'] ); ?>
							<li><a href="<?php echo esc_url( self::url( $project_id, 'edit', array( 'id' => $d['successor_id'] ) ) ); ?>"><?php echo esc_html( $s ? $s['code'] . ' ' . $s['name'] : (string) $d['successor_id'] ); ?></a> <span class="gdp-muted"><?php echo esc_html( $d['type'] . ( $d['lag'] ? sprintf( '%+d', $d['lag'] ) : '' ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Personas asignadas', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $assigns ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin asignaciones.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<tbody>
						<?php foreach ( $assigns as $s ) : ?>
							<tr>
								<td><?php echo esc_html( $s['display_name'] ); ?></td>
								<td><?php echo esc_html( AssignmentRepository::role_labels()[ $s['role'] ] ?? $s['role'] ); ?></td>
								<td><?php echo (int) $s['allocation']; ?> %</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
										<?php wp_nonce_field( 'gdp_remove_assignment_' . $id ); ?>
										<input type="hidden" name="action" value="gdp_remove_assignment">
										<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
										<input type="hidden" name="user_id" value="<?php echo (int) $s['user_id']; ?>">
										<button type="submit" class="button-link gdp-link-danger"><?php esc_html_e( 'Retirar', 'gestion-de-proyectos' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
					<?php wp_nonce_field( 'gdp_set_assignment_' . $id ); ?>
					<input type="hidden" name="action" value="gdp_set_assignment">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<?php wp_dropdown_users( array( 'name' => 'user_id', 'show' => 'display_name_with_login', 'orderby' => 'display_name' ) ); ?>
					<select name="role">
						<?php foreach ( AssignmentRepository::role_labels() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="number" name="allocation" min="1" max="100" value="100" class="small-text" aria-label="<?php esc_attr_e( 'Dedicación', 'gestion-de-proyectos' ); ?>"> %
					<button type="submit" class="button"><?php esc_html_e( 'Asignar', 'gestion-de-proyectos' ); ?></button>
				</form>
			</div>

			<?php if ( ! empty( $history ) ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Historial de avance', 'gestion-de-proyectos' ); ?></h2>
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Avance', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Quién', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $history as $h ) : ?>
						<tr>
							<td><?php echo esc_html( self::date( $h['reported_at'] ) ); ?></td>
							<td><?php echo (int) $h['previous_percent']; ?> % → <?php echo (int) $h['percent']; ?> %</td>
							<td><?php echo esc_html( ActivityRepository::status_labels()[ $h['status'] ] ?? $h['status'] ); ?></td>
							<td><?php echo esc_html( $h['note'] ); ?></td>
							<td><?php echo esc_html( $h['display_name'] ); ?> <span class="gdp-badge gdp-badge--channel"><?php echo esc_html( $h['source'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-danger-zone" data-confirm="1">
				<?php wp_nonce_field( 'gdp_delete_activity_' . $id ); ?>
				<input type="hidden" name="action" value="gdp_delete_activity">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar actividad', 'gestion-de-proyectos' ); ?></button>
				<span class="gdp-muted"><?php esc_html_e( 'Se eliminan también las actividades contenidas, sus dependencias, asignaciones e historial.', 'gestion-de-proyectos' ); ?></span>
			</form>
		</div>
		<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Lee los campos de actividad del formulario.
	 *
	 * @return array<string,mixed>
	 */
	private static function posted_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- se verifica en el manejador.
		$data = array();
		foreach ( array( 'name', 'kind', 'parent_id', 'work_front', 'priority', 'duration', 'constraint_type', 'constraint_date', 'status', 'percent', 'actual_start', 'actual_finish', 'owner_id', 'deliverable', 'budget_line', 'cost_planned' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		foreach ( array( 'description', 'notes' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_kses_post( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		if ( isset( $_POST['progress_note'] ) ) {
			$data['progress_note'] = sanitize_text_field( wp_unslash( (string) $_POST['progress_note'] ) );
		}
		// phpcs:enable

		return $data;
	}

	/**
	 * Lee la lista de predecesoras del formulario.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function posted_predecessors(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$ids   = isset( $_POST['pred_id'] ) && is_array( $_POST['pred_id'] ) ? array_map( 'intval', wp_unslash( $_POST['pred_id'] ) ) : array();
		$types = isset( $_POST['pred_type'] ) && is_array( $_POST['pred_type'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['pred_type'] ) ) : array();
		$lags  = isset( $_POST['pred_lag'] ) && is_array( $_POST['pred_lag'] ) ? array_map( 'intval', wp_unslash( $_POST['pred_lag'] ) ) : array();
		// phpcs:enable
		$list = array();
		foreach ( $ids as $i => $pid ) {
			if ( $pid > 0 ) {
				$list[] = array( 'predecessor_id' => $pid, 'type' => $types[ $i ] ?? 'FS', 'lag' => $lags[ $i ] ?? 0 );
			}
		}

		return $list;
	}

	/**
	 * Guarda una actividad.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_save_activity_' . $id );

		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$data  = self::posted_fields();
		$preds = self::posted_predecessors();
		$back  = self::url( $project_id, 'edit', array( 'id' => $id ) );

		if ( 0 === $id ) {
			$result = ActivityRepository::create( $project_id, $data );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( self::url( $project_id, 'edit', array( 'id' => 0, 'kind' => $data['kind'] ?? 'activity' ) ), implode( ' ', $result->get_error_messages() ), 'error' );
			}
			$id = $result;
		} else {
			$current = ActivityRepository::find( $id );
			if ( ! $current || $current['project_id'] !== $project_id ) {
				wp_die( esc_html__( 'La actividad no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
			}
			$expected = isset( $_POST['expected_version'] ) ? (int) $_POST['expected_version'] : null;
			$result   = ActivityRepository::update( $id, $data, $expected );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( $back, implode( ' ', $result->get_error_messages() ), 'error' );
			}
		}

		$activity = ActivityRepository::find( $id );
		if ( 'summary' !== $activity['kind'] ) {
			$list = DependencyRepository::validate_list( $project_id, $id, $preds );
			if ( is_wp_error( $list ) ) {
				ScheduleService::recalculate( $project_id );
				Admin::redirect_with_notice( self::url( $project_id, 'edit', array( 'id' => $id ) ), __( 'Actividad guardada, pero las predecesoras no se aplicaron:', 'gestion-de-proyectos' ) . ' ' . $list->get_error_message(), 'warning' );
			}
			DependencyRepository::replace_predecessors( $project_id, $id, $list );
		}
		ScheduleService::recalculate( $project_id );

		if ( ! empty( $_POST['another'] ) ) {
			Admin::redirect_with_notice( self::url( $project_id, 'edit', array( 'id' => 0, 'kind' => $activity['kind'], 'parent_id' => $activity['parent_id'] ) ), __( 'Actividad creada.', 'gestion-de-proyectos' ) );
		}
		Admin::redirect_with_notice( self::url( $project_id, 'list' ), __( 'Actividad guardada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una actividad.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_activity_' . $id );

		$activity = ActivityRepository::find( $id );
		if ( ! $activity || ! Access::can( 'planning.edit', $activity['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		// Por la capa de operaciones: queda la instantánea y puede restaurarse desde la papelera.
		$result = OperationManager::execute( 'activity', 'delete', array( 'activity_id' => $id ), $activity['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $activity['project_id'], 'edit', array( 'id' => $id ) ), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice(
			self::url( $activity['project_id'], 'list' ),
			sprintf(
				/* translators: 1: registros eliminados, 2: enlace a la papelera */
				__( 'Actividad eliminada (%1$d registros). Puede restaurarla desde la <a href="%2$s">papelera</a>.', 'gestion-de-proyectos' ),
				(int) ( $result['result']['deleted'] ?? 1 ),
				esc_url( Admin::url( 'trash', array( 'project_id' => $activity['project_id'] ) ) )
			)
		);
	}

	/**
	 * Avance rápido desde la lista.
	 *
	 * @return void
	 */
	public static function handle_progress(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_activity_progress_' . $id );

		$activity = ActivityRepository::find( $id );
		if ( ! $activity || ! Access::can( 'planning.edit', $activity['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$percent = isset( $_POST['percent'] ) ? (int) $_POST['percent'] : $activity['percent'];
		$result  = ActivityRepository::update( $id, array( 'percent' => $percent ), null );
		ScheduleService::recalculate( $activity['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $activity['project_id'], 'list' ), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( self::url( $activity['project_id'], 'list' ), sprintf( __( 'Avance de "%s" fijado en %d %%.', 'gestion-de-proyectos' ), $activity['name'], $result['percent'] ) );
	}

	/**
	 * Mueve una actividad (subir, bajar, anidar, desanidar).
	 *
	 * @return void
	 */
	public static function handle_move(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_move_activity_' . $id );

		$activity = ActivityRepository::find( $id );
		if ( ! $activity || ! Access::can( 'planning.edit', $activity['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$project_id = $activity['project_id'];
		$direction  = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( (string) $_POST['direction'] ) ) : '';
		$siblings   = ActivityRepository::children( $project_id, $activity['parent_id'] );
		$position   = 0;
		foreach ( $siblings as $i => $s ) {
			if ( $s['id'] === $id ) {
				$position = $i;
			}
		}
		$result = null;

		switch ( $direction ) {
			case 'up':
				if ( $position > 0 ) {
					$result = ActivityRepository::move( $id, null, $siblings[ $position - 1 ]['sort_order'] );
				}
				break;
			case 'down':
				if ( $position < count( $siblings ) - 1 ) {
					$result = ActivityRepository::move( $id, null, $siblings[ $position + 1 ]['sort_order'] + 1 );
				}
				break;
			case 'indent':
				// Se anida en el resumen hermano anterior más cercano.
				for ( $i = $position - 1; $i >= 0; $i-- ) {
					if ( 'summary' === $siblings[ $i ]['kind'] ) {
						$result = ActivityRepository::move( $id, $siblings[ $i ]['id'], null );
						break;
					}
				}
				if ( null === $result ) {
					Admin::redirect_with_notice( self::url( $project_id, 'list' ), __( 'No hay una fase anterior en la que anidar la actividad.', 'gestion-de-proyectos' ), 'warning' );
				}
				break;
			case 'outdent':
				if ( $activity['parent_id'] > 0 ) {
					$parent = ActivityRepository::find( $activity['parent_id'] );
					$result = ActivityRepository::move( $id, $parent ? $parent['parent_id'] : 0, $parent ? $parent['sort_order'] + 1 : null );
				}
				break;
		}

		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $project_id, 'list' ), $result->get_error_message(), 'error' );
		}
		ScheduleService::recalculate( $project_id );
		wp_safe_redirect( self::url( $project_id, 'list' ) );
		exit;
	}

	/**
	 * Asigna una persona.
	 *
	 * @return void
	 */
	public static function handle_set_assignment(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_set_assignment_' . $id );

		$activity = ActivityRepository::find( $id );
		if ( ! $activity || ! Access::can( 'planning.edit', $activity['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$result = AssignmentRepository::set(
			$activity['project_id'],
			$id,
			isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0,
			isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : 'participante',
			isset( $_POST['allocation'] ) ? (int) $_POST['allocation'] : 100
		);
		$back = self::url( $activity['project_id'], 'edit', array( 'id' => $id ) );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Asignación guardada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Retira una asignación.
	 *
	 * @return void
	 */
	public static function handle_remove_assignment(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_remove_assignment_' . $id );

		$activity = ActivityRepository::find( $id );
		if ( ! $activity || ! Access::can( 'planning.edit', $activity['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		AssignmentRepository::remove( $id, isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0 );
		Admin::redirect_with_notice( self::url( $activity['project_id'], 'edit', array( 'id' => $id ) ), __( 'Asignación retirada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Peticiones del script (Gantt y tablero).
	 *
	 * @return void
	 */
	public static function handle_ajax(): void {
		check_ajax_referer( 'gdp_planning_ajax', 'nonce' );

		$op         = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;

		if ( ! Access::can( 'planning.view', $project_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permiso.', 'gestion-de-proyectos' ) ), 403 );
		}

		if ( 'data' === $op ) {
			wp_send_json_success( self::client_data( $project_id ) );
		}

		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permiso de edición.', 'gestion-de-proyectos' ) ), 403 );
		}

		$id       = isset( $_POST['activity_id'] ) ? (int) $_POST['activity_id'] : 0;
		$activity = ActivityRepository::find( $id );
		if ( ! $activity || $activity['project_id'] !== $project_id ) {
			wp_send_json_error( array( 'message' => __( 'La actividad no existe.', 'gestion-de-proyectos' ) ), 404 );
		}
		$expected = isset( $_POST['version'] ) ? (int) $_POST['version'] : null;
		$data     = array();

		switch ( $op ) {
			case 'move':
				$start = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start'] ) ) : '';
				if ( $activity['actual_start'] || $activity['actual_finish'] ) {
					wp_send_json_error( array( 'message' => __( 'La actividad tiene fechas reales y no se puede mover.', 'gestion-de-proyectos' ) ) );
				}
				$calendar = CalendarRepository::build( $project_id );
				try {
					$start = $calendar->next_working( $start );
				} catch ( \InvalidArgumentException $e ) {
					wp_send_json_error( array( 'message' => __( 'Fecha no válida.', 'gestion-de-proyectos' ) ) );
				}
				$data = array( 'constraint_type' => 'snet', 'constraint_date' => $start );
				break;
			case 'resize':
				$duration = isset( $_POST['duration'] ) ? (int) $_POST['duration'] : 0;
				if ( 'activity' !== $activity['kind'] ) {
					wp_send_json_error( array( 'message' => __( 'Solo las actividades cambian de duración.', 'gestion-de-proyectos' ) ) );
				}
				$data = array( 'duration' => max( 1, $duration ) );
				break;
			case 'status':
				$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
				if ( 'summary' === $activity['kind'] ) {
					wp_send_json_error( array( 'message' => __( 'El estado de un resumen se deriva de sus actividades.', 'gestion-de-proyectos' ) ) );
				}
				// Las reglas de coherencia del repositorio ajustan avance y fechas reales según el estado.
				$data = array( 'status' => $status );
				break;
			case 'clear_constraint':
				$data = array( 'constraint_type' => 'asap', 'constraint_date' => null );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Operación desconocida.', 'gestion-de-proyectos' ) ), 400 );
		}

		$result = ActivityRepository::update( $id, $data, $expected );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		ScheduleService::recalculate( $project_id );

		wp_send_json_success( self::client_data( $project_id ) );
	}
}
