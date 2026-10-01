<?php
/**
 * Pantallas de reuniones y acuerdos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Roles;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Meetings\AgreementRepository;
use GDP\Modules\Meetings\MeetingRepository;
use GDP\Modules\Meetings\MinutesTemplate;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lista de reuniones, formulario con asistentes, acta con acuerdos y
 * seguimiento de acuerdos anteriores, propuesta de acuerdos desde un texto,
 * exportación del acta y vista de todos los acuerdos del proyecto.
 */
final class MeetingsPage extends Page {

	public const SLUG  = 'meetings';
	public const VIEWS = array( 'list', 'edit', 'show', 'agreements', 'proposal' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Slug del menú principal.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		add_submenu_page( $parent, __( 'Reuniones', 'gestion-de-proyectos' ), __( 'Reuniones', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		foreach ( array( 'save_meeting', 'delete_meeting', 'meeting_status', 'save_agreement', 'delete_agreement', 'review_agreement', 'agreement_activity', 'propose_agreements', 'confirm_agreements', 'minutes' ) as $action ) {
			add_action( 'admin_post_gdp_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	/**
	 * Estilos.
	 *
	 * @param string $hook Pantalla.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, Admin::SLUG . '-' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'gdp-planning', GDP_URL . 'assets/css/planning.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_style( 'gdp-documents', GDP_URL . 'assets/css/documents.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_style( 'gdp-meetings', GDP_URL . 'assets/css/meetings.css', array( 'gdp-admin', 'gdp-documents' ), GDP_VERSION );
		add_filter( 'admin_body_class', static fn( string $classes ): string => $classes . ' gdp-meetings' );
	}

	/**
	 * URL de una vista.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $args       Parámetros.
	 * @return string
	 */
	public static function url( int $project_id, array $args = array() ): string {
		return Admin::url( self::SLUG, array_merge( array( 'project_id' => $project_id ), $args ) );
	}

	/**
	 * Enrutador.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$project = PlanningPage::current_project();
		if ( ! $project ) {
			self::open( __( 'Reuniones', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'No hay proyectos visibles.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'meetings.view', $project_id ) ) {
			self::open( __( 'Reuniones', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'Sin permiso para ver las reuniones de este proyecto.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		update_user_meta( get_current_user_id(), 'gdp_planning_project', $project_id );

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		switch ( in_array( $view, self::VIEWS, true ) ? $view : 'list' ) {
			case 'edit':
				self::render_form( $project );
				break;
			case 'show':
				self::render_show( $project );
				break;
			case 'agreements':
				self::render_agreements( $project );
				break;
			case 'proposal':
				self::render_proposal( $project );
				break;
			default:
				self::render_list( $project );
		}
	}

	/**
	 * Tarjeta en la ficha del proyecto.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	public static function project_card( array $p ): void {
		$project_id = (int) $p['id'];
		if ( ! Access::can( 'meetings.view', $project_id ) ) {
			return;
		}
		$stats = AgreementRepository::stats( $project_id );
		$next  = MeetingRepository::for_project( $project_id, array( 'status' => 'programada', 'from' => current_time( 'Y-m-d' ), 'limit' => 50 ) );
		$next  = empty( $next ) ? null : end( $next );
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Reuniones y acuerdos', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Próxima reunión', 'gestion-de-proyectos' ); ?></th><td><?php echo $next ? '<a href="' . esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $next['id'] ) ) ) . '">' . esc_html( $next['meeting_date'] . ' · ' . $next['title'] ) . '</a>' : '—'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Acuerdos abiertos', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['open']; ?></td></tr>
				<tr><th><?php esc_html_e( 'Acuerdos vencidos', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $stats['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $stats['overdue']; ?></td></tr>
				<tr><th><?php esc_html_e( 'Cumplidos', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['fulfilled']; ?> <?php esc_html_e( 'de', 'gestion-de-proyectos' ); ?> <?php echo (int) $stats['total']; ?></td></tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Reuniones', 'gestion-de-proyectos' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'agreements' ) ) ); ?>"><?php esc_html_e( 'Acuerdos', 'gestion-de-proyectos' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Cabecera.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $view    Vista.
	 * @param string              $title   Título.
	 * @return void
	 */
	private static function header( array $project, string $view, string $title ): void {
		$project_id = (int) $project['id'];
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		self::open( $title, sprintf( '%s · %s', $project['code'], $project['name'] ) );
		?>
		<div class="gdp-planning-bar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( 'agreements' === $view ? 'agreements' : 'list' ); ?>">
				<select name="project_id" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Proyecto', 'gestion-de-proyectos' ); ?>">
					<?php foreach ( $projects as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<a class="nav-tab <?php echo 'agreements' !== $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Reuniones', 'gestion-de-proyectos' ); ?></a>
				<a class="nav-tab <?php echo 'agreements' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'agreements' ) ) ); ?>"><?php esc_html_e( 'Acuerdos', 'gestion-de-proyectos' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar' ) ); ?>"><?php esc_html_e( 'Calendario', 'gestion-de-proyectos' ); ?></a>
			</nav>
		</div>
		<?php
	}

	/**
	 * Lista de reuniones.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_list( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'meetings.edit', $project_id );
		$filters    = array(
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'kind'   => isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$rows       = MeetingRepository::for_project( $project_id, array_filter( $filters ) );
		$stats      = AgreementRepository::stats( $project_id );
		$kinds      = MeetingRepository::kind_labels();
		$labels     = MeetingRepository::status_labels();

		self::header( $project, 'list', __( 'Reuniones', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-summary">
			<span><strong><?php echo count( $rows ); ?></strong> <?php esc_html_e( 'reuniones', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $stats['open']; ?></strong> <?php esc_html_e( 'acuerdos abiertos', 'gestion-de-proyectos' ); ?></span>
			<span class="<?php echo $stats['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo (int) $stats['overdue']; ?></strong> <?php esc_html_e( 'vencidos', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $stats['fulfilled']; ?></strong> <?php esc_html_e( 'cumplidos', 'gestion-de-proyectos' ); ?></span>
		</div>
		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nueva reunión', 'gestion-de-proyectos' ); ?></a>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<select name="status"><option value=""><?php esc_html_e( 'Todos los estados', 'gestion-de-proyectos' ); ?></option><?php foreach ( $labels as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<select name="kind"><option value=""><?php esc_html_e( 'Todos los tipos', 'gestion-de-proyectos' ); ?></option><?php foreach ( $kinds as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['kind'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
			</form>
		</div>
		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'Sin reuniones registradas.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<table class="widefat striped gdp-table">
			<thead><tr><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Reunión', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Asistentes', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Acuerdos', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $m ) : ?>
				<?php $ags = AgreementRepository::for_meeting( $m['id'] ); ?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $m['id'] ) ) ); ?>"><code><?php echo esc_html( $m['code'] ); ?></code></a></td>
					<td><?php echo esc_html( $m['meeting_date'] . ( $m['start_time'] ? ' ' . $m['start_time'] : '' ) ); ?></td>
					<td><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $m['id'] ) ) ); ?>"><?php echo esc_html( $m['title'] ); ?></a></td>
					<td><?php echo esc_html( $kinds[ $m['kind'] ] ?? $m['kind'] ); ?></td>
					<td><?php echo self::badge( 'realizada' === $m['status'] ? 'ok' : ( 'cancelada' === $m['status'] ? 'cerrado' : 'planned' ), $labels[ $m['status'] ] ?? $m['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="gdp-num"><?php echo count( $m['attendees'] ); ?></td>
					<td class="gdp-num"><?php echo count( $ags ); ?> <span class="gdp-muted">(<?php echo count( array_filter( $ags, static fn( array $a ): bool => in_array( AgreementRepository::effective_status( $a ), AgreementRepository::OPEN, true ) ) ); ?> <?php esc_html_e( 'abiertos', 'gestion-de-proyectos' ); ?>)</span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Formulario de reunión con asistentes.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_form( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'meetings.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para editar reuniones.', 'gestion-de-proyectos' ), 403 );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$m  = $id > 0 ? MeetingRepository::find( $id ) : null;
		if ( $id > 0 && ( ! $m || $m['project_id'] !== $project_id ) ) {
			wp_die( esc_html__( 'La reunión no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$m          = $m ?? array( 'id' => 0, 'title' => '', 'kind' => 'equipo', 'status' => 'programada', 'meeting_date' => current_time( 'Y-m-d' ), 'start_time' => null, 'end_time' => null, 'location' => '', 'agenda' => '', 'summary' => '', 'notes' => '', 'organizer_id' => get_current_user_id(), 'activity_id' => 0, 'attendees' => array(), 'version' => 0 );
		$members    = MemberRepository::for_project( $project_id );
		$activities = ActivityRepository::for_project( $project_id );
		$attending  = array();
		$external   = array();
		foreach ( $m['attendees'] as $a ) {
			if ( $a['user_id'] > 0 ) {
				$attending[ $a['user_id'] ] = $a['attended'];
			} else {
				$external[] = $a['name'] . ( '' !== $a['organization'] ? ' (' . $a['organization'] . ')' : '' ) . ( $a['attended'] ? '' : ' [ausente]' );
			}
		}

		self::header( $project, 'edit', $id > 0 ? __( 'Editar reunión', 'gestion-de-proyectos' ) : __( 'Nueva reunión', 'gestion-de-proyectos' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form">
			<?php wp_nonce_field( 'gdp_save_meeting_' . $id ); ?>
			<input type="hidden" name="action" value="gdp_save_meeting">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="expected_version" value="<?php echo (int) $m['version']; ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gdp-mtg-title"><?php esc_html_e( 'Título', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" id="gdp-mtg-title" name="title" class="large-text" required value="<?php echo esc_attr( (string) $m['title'] ); ?>"></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Tipo y estado', 'gestion-de-proyectos' ); ?></th><td>
					<select name="kind"><?php foreach ( MeetingRepository::kind_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['kind'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<select name="status"><?php foreach ( MeetingRepository::status_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				</td></tr>
				<tr><th scope="row"><label for="gdp-mtg-date"><?php esc_html_e( 'Fecha y hora', 'gestion-de-proyectos' ); ?></label></th><td><input type="date" id="gdp-mtg-date" name="meeting_date" required value="<?php echo esc_attr( (string) $m['meeting_date'] ); ?>"> <input type="time" name="start_time" value="<?php echo esc_attr( (string) $m['start_time'] ); ?>"> <input type="time" name="end_time" value="<?php echo esc_attr( (string) $m['end_time'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="gdp-mtg-location"><?php esc_html_e( 'Lugar o enlace', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" id="gdp-mtg-location" name="location" class="large-text" value="<?php echo esc_attr( (string) $m['location'] ); ?>"></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Asistentes del proyecto', 'gestion-de-proyectos' ); ?></th><td>
					<ul class="gdp-attendees">
					<?php foreach ( $members as $member ) : ?>
						<li><label class="gdp-check"><input type="checkbox" name="attendees[]" value="<?php echo (int) $member['user_id']; ?>" <?php checked( 0 === $id || isset( $attending[ $member['user_id'] ] ) ); ?>> <?php echo esc_html( $member['display_name'] . ' (' . $member['role_label'] . ')' ); ?></label></li>
					<?php endforeach; ?>
					</ul>
				</td></tr>
				<tr><th scope="row"><label for="gdp-mtg-external"><?php esc_html_e( 'Otros asistentes', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-mtg-external" name="external" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Uno por línea: Nombre (organización)', 'gestion-de-proyectos' ); ?>"><?php echo esc_textarea( implode( "\n", $external ) ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="gdp-mtg-agenda"><?php esc_html_e( 'Temas', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-mtg-agenda" name="agenda" rows="4" class="large-text"><?php echo esc_textarea( (string) $m['agenda'] ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="gdp-mtg-summary"><?php esc_html_e( 'Resumen o acta', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-mtg-summary" name="summary" rows="8" class="large-text"><?php echo esc_textarea( (string) $m['summary'] ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="gdp-mtg-organizer"><?php esc_html_e( 'Organiza', 'gestion-de-proyectos' ); ?></label></th><td>
					<select name="organizer_id" id="gdp-mtg-organizer">
						<option value="0">—</option>
						<?php foreach ( $members as $member ) : ?><option value="<?php echo (int) $member['user_id']; ?>" <?php selected( (int) $m['organizer_id'], (int) $member['user_id'] ); ?>><?php echo esc_html( $member['display_name'] ); ?></option><?php endforeach; ?>
					</select>
					<select name="activity_id" aria-label="<?php esc_attr_e( 'Actividad relacionada', 'gestion-de-proyectos' ); ?>">
						<option value="0"><?php esc_html_e( 'Sin actividad relacionada', 'gestion-de-proyectos' ); ?></option>
						<?php foreach ( $activities as $a ) : ?><option value="<?php echo (int) $a['id']; ?>" <?php selected( (int) $m['activity_id'], (int) $a['id'] ); ?>><?php echo esc_html( $a['code'] . ' ' . $a['name'] ); ?></option><?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th scope="row"><label for="gdp-mtg-notes"><?php esc_html_e( 'Notas internas', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-mtg-notes" name="notes" rows="2" class="large-text"><?php echo esc_textarea( (string) $m['notes'] ); ?></textarea></td></tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $id > 0 ? esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ) : esc_html__( 'Crear reunión', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( $id > 0 ? self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ) : self::url( $project_id ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a>
			</p>
		</form>
		<?php
		self::close();
	}

	/**
	 * Acta de la reunión.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_show( array $project ): void {
		$project_id = (int) $project['id'];
		$id         = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$m          = MeetingRepository::find( $id );
		if ( ! $m || $m['project_id'] !== $project_id ) {
			wp_die( esc_html__( 'La reunión no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$can_edit   = Access::can( 'meetings.edit', $project_id );
		$can_plan   = Access::can( 'planning.edit', $project_id );
		$agreements = AgreementRepository::for_meeting( $id );
		$carried    = AgreementRepository::carried_over( $project_id, $id );
		$reviewed   = MinutesTemplate::reviewed( $id );
		$labels     = AgreementRepository::status_labels();
		$members    = MemberRepository::for_project( $project_id );
		$summaries  = array_values( array_filter( ActivityRepository::for_project( $project_id ), static fn( array $a ): bool => 'summary' === $a['kind'] ) );
		$editing    = isset( $_GET['agreement'] ) ? (int) $_GET['agreement'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ed         = $editing > 0 ? AgreementRepository::find( $editing ) : null;
		$ed         = $ed && $ed['meeting_id'] === $id ? $ed : null;

		self::header( $project, 'show', $m['code'] . ' ' . $m['title'] );
		?>
		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Editar', 'gestion-de-proyectos' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
					<?php wp_nonce_field( 'gdp_meeting_status_' . $id ); ?>
					<input type="hidden" name="action" value="gdp_meeting_status">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<select name="status"><?php foreach ( MeetingRepository::status_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<button type="submit" class="button"><?php esc_html_e( 'Cambiar estado', 'gestion-de-proyectos' ); ?></button>
				</form>
			<?php endif; ?>
			<span class="gdp-export-links">
				<?php esc_html_e( 'Acta:', 'gestion-de-proyectos' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_minutes&id=' . $id . '&format=tex' ), 'gdp_minutes_' . $id ) ); ?>">LaTeX</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_minutes&id=' . $id . '&format=docx' ), 'gdp_minutes_' . $id ) ); ?>">Word</a>
			</span>
			<?php if ( $can_edit ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
					<?php wp_nonce_field( 'gdp_delete_meeting_' . $id ); ?>
					<input type="hidden" name="action" value="gdp_delete_meeting">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Datos', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $m['meeting_date'] . ( $m['start_time'] ? ', ' . $m['start_time'] . ( $m['end_time'] ? ' a ' . $m['end_time'] : '' ) : '' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( MeetingRepository::kind_labels()[ $m['kind'] ] ?? $m['kind'] ); ?> · <?php echo esc_html( MeetingRepository::status_labels()[ $m['status'] ] ?? $m['status'] ); ?></td></tr>
					<?php if ( '' !== $m['location'] ) : ?><tr><th><?php esc_html_e( 'Lugar', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $m['location'] ); ?></td></tr><?php endif; ?>
					<tr><th><?php esc_html_e( 'Organiza', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( ScheduleService::user_name( $m['organizer_id'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Asistentes', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( implode( '; ', array_map( static fn( array $a ): string => $a['name'] . ( '' !== $a['organization'] ? ' (' . $a['organization'] . ')' : '' ) . ( $a['attended'] ? '' : ' [' . __( 'ausente', 'gestion-de-proyectos' ) . ']' ), $m['attendees'] ) ) ?: '—' ); ?></td></tr>
				</table>
				<?php if ( '' !== $m['agenda'] ) : ?><h3><?php esc_html_e( 'Temas', 'gestion-de-proyectos' ); ?></h3><div class="gdp-documents__body"><?php echo wp_kses_post( wpautop( $m['agenda'] ) ); ?></div><?php endif; ?>
				<?php if ( '' !== $m['summary'] ) : ?><h3><?php esc_html_e( 'Resumen', 'gestion-de-proyectos' ); ?></h3><div class="gdp-documents__body"><?php echo wp_kses_post( wpautop( $m['summary'] ) ); ?></div><?php endif; ?>
			</div>

			<?php if ( $can_edit ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Registrar acuerdos', 'gestion-de-proyectos' ); ?></h2>
				<?php self::render_agreement_forms( $project_id, $id, $ed, $members, $labels ); ?>
			</div>
			<?php endif; ?>

			<div class="gdp-card gdp-card--wide">
				<h2><?php esc_html_e( 'Acuerdos de esta reunión', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $agreements ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin acuerdos registrados.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th>N.º</th><th><?php esc_html_e( 'Acuerdo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plazo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $agreements as $a ) : ?>
							<?php $eff = AgreementRepository::effective_status( $a ); ?>
							<tr>
								<td><code><?php echo esc_html( $a['code'] ); ?></code></td>
								<td class="gdp-agreement-text"><?php echo esc_html( $a['description'] ); ?><?php if ( $a['activity_id'] > 0 ) : ?> <a class="gdp-small" href="<?php echo esc_url( PlanningPage::url( $project_id, 'edit', array( 'id' => $a['activity_id'] ) ) ); ?>"><?php esc_html_e( '(actividad)', 'gestion-de-proyectos' ); ?></a><?php endif; ?><?php echo 'propuesto' === $a['origin'] ? ' <span class="gdp-muted">(' . esc_html__( 'propuesto', 'gestion-de-proyectos' ) . ')</span>' : ''; ?></td>
								<td><?php echo esc_html( $a['owner_name'] ); ?></td>
								<td class="<?php echo $a['overdue'] ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( (string) $a['due_date'] ); ?></td>
								<td><?php echo self::badge( 'cumplido' === $eff ? 'ok' : ( 'cancelado' === $eff ? 'cerrado' : ( $a['overdue'] ? 'fail' : 'planned' ) ), $labels[ $eff ] ?? $eff ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td class="gdp-small gdp-agreement-actions">
									<?php if ( $can_edit ) : ?>
										<a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $id, 'agreement' => $a['id'] ) ) ); ?>#gdp-agreement-form"><?php esc_html_e( 'editar', 'gestion-de-proyectos' ); ?></a>
										<?php if ( 0 === $a['activity_id'] && $can_plan ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
												<?php wp_nonce_field( 'gdp_agreement_activity_' . $a['id'] ); ?>
												<input type="hidden" name="action" value="gdp_agreement_activity">
												<input type="hidden" name="agreement_id" value="<?php echo (int) $a['id']; ?>">
												<select name="parent_id" aria-label="<?php esc_attr_e( 'Fase', 'gestion-de-proyectos' ); ?>"><option value="0"><?php esc_html_e( 'primer nivel', 'gestion-de-proyectos' ); ?></option><?php foreach ( $summaries as $s ) : ?><option value="<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s['code'] . ' ' . $s['name'] ); ?></option><?php endforeach; ?></select>
												<button type="submit" class="button-link"><?php esc_html_e( 'crear actividad', 'gestion-de-proyectos' ); ?></button>
											</form>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
											<?php wp_nonce_field( 'gdp_delete_agreement_' . $a['id'] ); ?>
											<input type="hidden" name="action" value="gdp_delete_agreement">
											<input type="hidden" name="agreement_id" value="<?php echo (int) $a['id']; ?>">
											<button type="submit" class="button-link gdp-button-link-danger"><?php esc_html_e( 'quitar', 'gestion-de-proyectos' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="gdp-card gdp-card--wide">
				<h2><?php esc_html_e( 'Seguimiento de acuerdos anteriores', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $carried ) && empty( $reviewed ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'No hay acuerdos abiertos de reuniones anteriores.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th>N.º</th><th><?php esc_html_e( 'Acuerdo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plazo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Revisión en esta reunión', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php $shown = array(); ?>
						<?php foreach ( array_merge( $reviewed, $carried ) as $a ) : ?>
							<?php if ( isset( $shown[ $a['id'] ] ) ) : ?><?php continue; ?><?php endif; ?>
							<?php $shown[ $a['id'] ] = true; ?>
							<?php $eff = AgreementRepository::effective_status( $a ); ?>
							<?php $origin = MeetingRepository::find( $a['meeting_id'] ); ?>
							<tr>
								<td><code><?php echo esc_html( $a['code'] ); ?></code><br><span class="gdp-muted"><?php echo esc_html( $origin ? $origin['meeting_date'] : '' ); ?></span></td>
								<td class="gdp-agreement-text"><?php echo esc_html( $a['description'] ); ?></td>
								<td><?php echo esc_html( $a['owner_name'] ); ?></td>
								<td class="<?php echo $a['overdue'] ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( (string) $a['due_date'] ); ?></td>
								<td><?php echo self::badge( 'cumplido' === $eff ? 'ok' : ( 'cancelado' === $eff ? 'cerrado' : ( $a['overdue'] ? 'fail' : 'planned' ) ), $labels[ $eff ] ?? $eff ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td>
									<?php if ( isset( $a['review'] ) ) : ?>
										<span class="gdp-muted"><?php echo esc_html( ( $labels[ $a['review']['to'] ] ?? $a['review']['to'] ) . ( '' !== $a['review']['note'] ? ': ' . $a['review']['note'] : '' ) ); ?></span>
									<?php elseif ( $can_edit ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form gdp-agreement-review">
											<?php wp_nonce_field( 'gdp_review_agreement_' . $a['id'] ); ?>
											<input type="hidden" name="action" value="gdp_review_agreement">
											<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
											<input type="hidden" name="agreement_id" value="<?php echo (int) $a['id']; ?>">
											<select name="status"><?php foreach ( $labels as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $a['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
											<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota', 'gestion-de-proyectos' ); ?>">
											<button type="submit" class="button"><?php esc_html_e( 'Revisar', 'gestion-de-proyectos' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Formularios para registrar un acuerdo a mano o proponerlos desde un texto.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param int                            $id         Reunión.
	 * @param array<string,mixed>|null       $ed         Acuerdo en edición.
	 * @param array<int,array<string,mixed>> $members    Miembros del proyecto.
	 * @param array<string,string>           $labels     Etiquetas de estado.
	 * @return void
	 */
	private static function render_agreement_forms( int $project_id, int $id, ?array $ed, array $members, array $labels ): void {
		$m = MeetingRepository::find( $id );
		if ( ! $m ) {
			return;
		}
		?>
			<h3 id="gdp-agreement-form"><?php echo $ed ? esc_html__( 'Editar acuerdo', 'gestion-de-proyectos' ) : esc_html__( 'Nuevo acuerdo', 'gestion-de-proyectos' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
				<?php wp_nonce_field( 'gdp_save_agreement_' . $id ); ?>
				<input type="hidden" name="action" value="gdp_save_agreement">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="agreement_id" value="<?php echo $ed ? (int) $ed['id'] : 0; ?>">
				<input type="text" name="description" class="large-text" required placeholder="<?php esc_attr_e( 'Acuerdo', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( $ed ? (string) $ed['description'] : '' ); ?>">
				<select name="owner_id" aria-label="<?php esc_attr_e( 'Responsable', 'gestion-de-proyectos' ); ?>">
					<option value="0"><?php esc_html_e( 'Responsable del sitio', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $members as $member ) : ?><option value="<?php echo (int) $member['user_id']; ?>" <?php selected( $ed ? (int) $ed['owner_id'] : 0, (int) $member['user_id'] ); ?>><?php echo esc_html( $member['display_name'] ); ?></option><?php endforeach; ?>
				</select>
				<input type="text" name="owner_name" placeholder="<?php esc_attr_e( 'o nombre externo', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( $ed && 0 === $ed['owner_id'] ? (string) $ed['owner_name'] : '' ); ?>">
				<input type="date" name="due_date" value="<?php echo esc_attr( $ed ? (string) $ed['due_date'] : '' ); ?>">
				<?php if ( $ed ) : ?>
					<select name="status"><?php foreach ( $labels as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $ed['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<?php endif; ?>
				<button type="submit" class="button"><?php echo $ed ? esc_html__( 'Guardar', 'gestion-de-proyectos' ) : esc_html__( 'Añadir acuerdo', 'gestion-de-proyectos' ); ?></button>
				<?php if ( $ed ) : ?><a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a><?php endif; ?>
			</form>
			<h3><?php esc_html_e( 'Proponer acuerdos desde un resumen o transcripción', 'gestion-de-proyectos' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gdp_propose_agreements_' . $id ); ?>
				<input type="hidden" name="action" value="gdp_propose_agreements">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<textarea name="text" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'Pegue el resumen o la transcripción. Se detectan frases con "se acuerda", "a cargo de", "deberá", "enviará" y las viñetas bajo "Acuerdos"; responsables y plazos se extraen cuando se nombran.', 'gestion-de-proyectos' ); ?>"><?php echo esc_textarea( $m['transcript'] ); ?></textarea>
				<p><label><input type="checkbox" name="save_transcript" value="1" checked> <?php esc_html_e( 'Guardar el texto como transcripción de la reunión', 'gestion-de-proyectos' ); ?></label> <button type="submit" class="button"><?php esc_html_e( 'Analizar y proponer', 'gestion-de-proyectos' ); ?></button></p>
			</form>
		<?php
	}

	/**
	 * Etiqueta de confianza de un acuerdo propuesto.
	 *
	 * @param string $confidence alta, media o baja.
	 * @return string
	 */
	private static function confidence_label( string $confidence ): string {
		$labels = array(
			'alta'  => __( 'Alta', 'gestion-de-proyectos' ),
			'media' => __( 'Media', 'gestion-de-proyectos' ),
			'baja'  => __( 'Baja', 'gestion-de-proyectos' ),
		);

		return $labels[ $confidence ] ?? $confidence;
	}

	/**
	 * Vista previa de acuerdos propuestos desde un texto.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_proposal( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'meetings.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$op_id = isset( $_GET['op'] ) ? (int) $_GET['op'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$op    = OperationManager::get( $op_id );
		if ( ! $op || 'meeting' !== $op['handler'] || 'propose_from_text' !== $op['action'] || (int) $op['project_id'] !== $project_id ) {
			wp_die( esc_html__( 'La propuesta no existe.', 'gestion-de-proyectos' ), 404 );
		}
		$meeting_id = (int) ( $op['payload']['meeting_id'] ?? 0 );
		$preview    = is_array( $op['preview'] ) ? $op['preview'] : array();
		$members    = MemberRepository::for_project( $project_id );

		self::header( $project, 'show', __( 'Acuerdos propuestos', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-card">
			<h2><?php echo esc_html( (string) ( $preview['summary'] ?? '' ) ); ?></h2>
			<?php if ( OperationManager::STATUS_PROPOSED !== $op['status'] ) : ?>
				<p><?php esc_html_e( 'Esta propuesta ya fue resuelta.', 'gestion-de-proyectos' ); ?> <a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $meeting_id ) ) ); ?>"><?php esc_html_e( 'Volver a la reunión', 'gestion-de-proyectos' ); ?></a></p>
			<?php else : ?>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Corrija descripción, responsable y plazo antes de confirmar; los acuerdos sin marcar no se crean.', 'gestion-de-proyectos' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gdp_confirm_agreements_' . $op_id ); ?>
					<input type="hidden" name="action" value="gdp_confirm_agreements">
					<input type="hidden" name="op" value="<?php echo (int) $op_id; ?>">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<table class="widefat striped gdp-table gdp-small gdp-proposal">
						<thead><tr><th></th><th><?php esc_html_e( 'Acuerdo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plazo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Confianza', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( (array) ( $preview['agreements'] ?? array() ) as $i => $a ) : ?>
							<tr>
								<td><input type="checkbox" name="keep[<?php echo (int) $i; ?>]" value="1" <?php checked( 'baja' !== ( $a['confidence'] ?? '' ) ); ?>></td>
								<td><input type="text" name="rows[<?php echo (int) $i; ?>][description]" class="large-text" value="<?php echo esc_attr( (string) $a['description'] ); ?>"></td>
								<td>
									<select name="rows[<?php echo (int) $i; ?>][owner_id]">
										<option value="0"><?php echo esc_html( '' !== (string) ( $a['owner_name'] ?? '' ) ? $a['owner_name'] . ' (' . __( 'externo', 'gestion-de-proyectos' ) . ')' : __( 'Sin responsable', 'gestion-de-proyectos' ) ); ?></option>
										<?php foreach ( $members as $member ) : ?><option value="<?php echo (int) $member['user_id']; ?>" <?php selected( (int) ( $a['owner_id'] ?? 0 ), (int) $member['user_id'] ); ?>><?php echo esc_html( $member['display_name'] ); ?></option><?php endforeach; ?>
									</select>
									<input type="hidden" name="rows[<?php echo (int) $i; ?>][owner_name]" value="<?php echo esc_attr( (string) ( $a['owner_name'] ?? '' ) ); ?>">
								</td>
								<td><input type="date" name="rows[<?php echo (int) $i; ?>][due_date]" value="<?php echo esc_attr( (string) ( $a['due_date'] ?? '' ) ); ?>"></td>
								<td class="gdp-confidence"><?php echo esc_html( self::confidence_label( (string) ( $a['confidence'] ?? '' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirmar acuerdos', 'gestion-de-proyectos' ); ?></button>
						<button type="submit" name="cancel" value="1" class="button"><?php esc_html_e( 'Descartar propuesta', 'gestion-de-proyectos' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Todos los acuerdos del proyecto.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_agreements( array $project ): void {
		$project_id = (int) $project['id'];
		$filters    = array(
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'overdue'  => ! empty( $_GET['overdue'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'open'     => ! empty( $_GET['open'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'owner_id' => isset( $_GET['owner_id'] ) ? (int) $_GET['owner_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$rows       = AgreementRepository::for_project( $project_id, array_filter( $filters ) );
		$labels     = AgreementRepository::status_labels();
		$members    = MemberRepository::for_project( $project_id );

		self::header( $project, 'agreements', __( 'Acuerdos', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<input type="hidden" name="view" value="agreements">
				<select name="status"><option value=""><?php esc_html_e( 'Todos los estados', 'gestion-de-proyectos' ); ?></option><?php foreach ( $labels as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<select name="owner_id"><option value="0"><?php esc_html_e( 'Todos los responsables', 'gestion-de-proyectos' ); ?></option><?php foreach ( $members as $member ) : ?><option value="<?php echo (int) $member['user_id']; ?>" <?php selected( $filters['owner_id'], (int) $member['user_id'] ); ?>><?php echo esc_html( $member['display_name'] ); ?></option><?php endforeach; ?></select>
				<label><input type="checkbox" name="overdue" value="1" <?php checked( $filters['overdue'] ); ?>> <?php esc_html_e( 'Vencidos', 'gestion-de-proyectos' ); ?></label>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
			</form>
		</div>
		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'Ningún acuerdo coincide con el filtro.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<table class="widefat striped gdp-table">
			<thead><tr><th>N.º</th><th><?php esc_html_e( 'Acuerdo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Reunión', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plazo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Última revisión', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $a ) : ?>
				<?php $eff = AgreementRepository::effective_status( $a ); ?>
				<?php $origin = MeetingRepository::find( $a['meeting_id'] ); ?>
				<?php $last = end( $a['follow_up'] ); ?>
				<tr>
					<td><code><?php echo esc_html( $a['code'] ); ?></code></td>
					<td><?php echo esc_html( $a['description'] ); ?><?php if ( $a['activity_id'] > 0 ) : ?> <a class="gdp-small" href="<?php echo esc_url( PlanningPage::url( $project_id, 'edit', array( 'id' => $a['activity_id'] ) ) ); ?>"><?php esc_html_e( '(actividad)', 'gestion-de-proyectos' ); ?></a><?php endif; ?></td>
					<td><?php if ( $origin ) : ?><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $origin['id'] ) ) ); ?>"><?php echo esc_html( $origin['meeting_date'] ); ?></a><?php endif; ?></td>
					<td><?php echo esc_html( $a['owner_name'] ); ?></td>
					<td class="<?php echo $a['overdue'] ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( (string) $a['due_date'] ); ?></td>
					<td><?php echo self::badge( 'cumplido' === $eff ? 'ok' : ( 'cancelado' === $eff ? 'cerrado' : ( $a['overdue'] ? 'fail' : 'planned' ) ), $labels[ $eff ] ?? $eff ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="gdp-small"><?php echo $last ? esc_html( ( $last['meeting_code'] ?? '' ) . ' ' . ( $last['date'] ?? '' ) . ( '' !== (string) ( $last['note'] ?? '' ) ? ': ' . $last['note'] : '' ) ) : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Guarda una reunión.
	 *
	 * @return void
	 */
	public static function handle_save_meeting(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_save_meeting_' . $id );
		if ( ! Access::can( 'meetings.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$data = array();
		foreach ( array( 'title', 'kind', 'status', 'meeting_date', 'start_time', 'end_time', 'location' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		foreach ( array( 'agenda', 'summary', 'notes' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_kses_post( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		$data['organizer_id'] = isset( $_POST['organizer_id'] ) ? (int) $_POST['organizer_id'] : 0;
		$data['activity_id']  = isset( $_POST['activity_id'] ) ? (int) $_POST['activity_id'] : 0;
		$attendees            = array();
		foreach ( (array) ( $_POST['attendees'] ?? array() ) as $uid ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$attendees[] = array( 'user_id' => (int) $uid );
		}
		foreach ( preg_split( '/\r\n|\r|\n/', isset( $_POST['external'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['external'] ) ) : '' ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$attended = ! preg_match( '/\[ausente\]/i', $line );
			$line     = trim( (string) preg_replace( '/\[ausente\]/i', '', $line ) );
			$org      = '';
			if ( preg_match( '/^(.*?)\s*\((.*)\)\s*$/', $line, $mm ) ) {
				$line = trim( $mm[1] );
				$org  = trim( $mm[2] );
			}
			$attendees[] = array( 'name' => $line, 'organization' => $org, 'attended' => $attended );
		}
		if ( 0 === $id ) {
			$result = OperationManager::execute( 'meeting', 'create', array( 'data' => $data, 'attendees' => $attendees ), $project_id );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ), implode( ' ', $result->get_error_messages() ), 'error' );
			}
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => (int) $result['result']['meeting_id'] ) ), __( 'Reunión creada.', 'gestion-de-proyectos' ) );
		}
		$expected = isset( $_POST['expected_version'] ) ? (int) $_POST['expected_version'] : null;
		$result   = OperationManager::execute( 'meeting', 'update', array( 'meeting_id' => $id, 'data' => $data, 'attendees' => $attendees, 'expected_version' => $expected ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'edit', 'id' => $id ) ), implode( ' ', $result->get_error_messages() ), 'error' );
		}
		Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ), __( 'Reunión actualizada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una reunión.
	 *
	 * @return void
	 */
	public static function handle_delete_meeting(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_meeting_' . $id );
		$m = MeetingRepository::find( $id );
		if ( ! $m || ! Access::can( 'meetings.edit', $m['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$result = OperationManager::execute( 'meeting', 'delete', array( 'meeting_id' => $id ), $m['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $m['project_id'], array( 'view' => 'show', 'id' => $id ) ), $result->get_error_message(), 'error' );
		}
		/* translators: URL de la papelera. */
		Admin::redirect_with_notice( self::url( $m['project_id'] ), sprintf( __( 'Reunión eliminada. Puede restaurarla desde la <a href="%s">papelera</a>.', 'gestion-de-proyectos' ), esc_url( Admin::url( 'trash', array( 'project_id' => $m['project_id'] ) ) ) ) );
	}

	/**
	 * Estado de la reunión.
	 *
	 * @return void
	 */
	public static function handle_meeting_status(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_meeting_status_' . $id );
		$m = MeetingRepository::find( $id );
		if ( ! $m || ! Access::can( 'meetings.edit', $m['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $m['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute( 'meeting', 'set_status', array( 'meeting_id' => $id, 'status' => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '' ), $m['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Estado actualizado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Crea o actualiza un acuerdo.
	 *
	 * @return void
	 */
	public static function handle_save_agreement(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_save_agreement_' . $id );
		$m = MeetingRepository::find( $id );
		if ( ! $m || ! Access::can( 'meetings.edit', $m['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$agreement_id = isset( $_POST['agreement_id'] ) ? (int) $_POST['agreement_id'] : 0;
		$data         = array(
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
			'owner_id'    => isset( $_POST['owner_id'] ) ? (int) $_POST['owner_id'] : 0,
			'owner_name'  => isset( $_POST['owner_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['owner_name'] ) ) : '',
			'due_date'    => isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['due_date'] ) ) : '',
		);
		if ( isset( $_POST['status'] ) ) {
			$data['status'] = sanitize_key( wp_unslash( (string) $_POST['status'] ) );
		}
		$back = self::url( $m['project_id'], array( 'view' => 'show', 'id' => $id ) );
		if ( $agreement_id > 0 ) {
			$result = OperationManager::execute( 'meeting', 'update_agreement', array( 'agreement_id' => $agreement_id, 'data' => $data ), $m['project_id'] );
		} else {
			$result = OperationManager::execute( 'meeting', 'add_agreements', array( 'meeting_id' => $id, 'agreements' => array( $data ), 'origin' => 'manual' ), $m['project_id'] );
		}
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, implode( ' ', $result->get_error_messages() ), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Acuerdo guardado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina un acuerdo.
	 *
	 * @return void
	 */
	public static function handle_delete_agreement(): void {
		$agreement_id = isset( $_POST['agreement_id'] ) ? (int) $_POST['agreement_id'] : 0;
		check_admin_referer( 'gdp_delete_agreement_' . $agreement_id );
		$a = AgreementRepository::find( $agreement_id );
		if ( ! $a || ! Access::can( 'meetings.edit', $a['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $a['project_id'], array( 'view' => 'show', 'id' => $a['meeting_id'] ) );
		$result = OperationManager::execute( 'meeting', 'delete_agreement', array( 'agreement_id' => $agreement_id ), $a['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Acuerdo eliminado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Revisa un acuerdo anterior en la reunión actual.
	 *
	 * @return void
	 */
	public static function handle_review_agreement(): void {
		$id           = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$agreement_id = isset( $_POST['agreement_id'] ) ? (int) $_POST['agreement_id'] : 0;
		check_admin_referer( 'gdp_review_agreement_' . $agreement_id );
		$m = MeetingRepository::find( $id );
		if ( ! $m || ! Access::can( 'meetings.edit', $m['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $m['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute( 'meeting', 'review_agreement', array( 'agreement_id' => $agreement_id, 'meeting_id' => $id, 'status' => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '', 'note' => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '' ), $m['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Revisión registrada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Convierte un acuerdo en actividad.
	 *
	 * @return void
	 */
	public static function handle_agreement_activity(): void {
		$agreement_id = isset( $_POST['agreement_id'] ) ? (int) $_POST['agreement_id'] : 0;
		check_admin_referer( 'gdp_agreement_activity_' . $agreement_id );
		$a = AgreementRepository::find( $agreement_id );
		if ( ! $a || ! Access::can( 'meetings.edit', $a['project_id'] ) || ! Access::can( 'planning.edit', $a['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $a['project_id'], array( 'view' => 'show', 'id' => $a['meeting_id'] ) );
		$result = OperationManager::execute( 'meeting', 'agreement_to_activity', array( 'agreement_id' => $agreement_id, 'parent_id' => isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0 ), $a['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		/* translators: código de la actividad. */
		Admin::redirect_with_notice( $back, sprintf( __( 'Actividad %s creada en el cronograma.', 'gestion-de-proyectos' ), (string) ( $result['result']['activity']['code'] ?? '' ) ) );
	}

	/**
	 * Analiza un texto y propone acuerdos (operación en dos tiempos).
	 *
	 * @return void
	 */
	public static function handle_propose_agreements(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_propose_agreements_' . $id );
		$m = MeetingRepository::find( $id );
		if ( ! $m || ! Access::can( 'meetings.edit', $m['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back = self::url( $m['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$op   = OperationManager::propose( 'meeting', 'propose_from_text', array( 'meeting_id' => $id, 'text' => isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['text'] ) ) : '', 'save_transcript' => ! empty( $_POST['save_transcript'] ) ), $m['project_id'], 'admin' );
		if ( is_wp_error( $op ) ) {
			Admin::redirect_with_notice( $back, $op->get_error_message(), 'error' );
		}
		wp_safe_redirect( self::url( $m['project_id'], array( 'view' => 'proposal', 'op' => (int) $op['operation_id'] ) ) );
		exit;
	}

	/**
	 * Confirma (con correcciones) o descarta los acuerdos propuestos.
	 *
	 * @return void
	 */
	public static function handle_confirm_agreements(): void {
		$op_id      = isset( $_POST['op'] ) ? (int) $_POST['op'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_confirm_agreements_' . $op_id );
		$op = OperationManager::get( $op_id );
		if ( ! $op || (int) $op['project_id'] !== $project_id || ! Access::can( 'meetings.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$meeting_id = (int) ( $op['payload']['meeting_id'] ?? 0 );
		$back       = self::url( $project_id, array( 'view' => 'show', 'id' => $meeting_id ) );
		if ( ! empty( $_POST['cancel'] ) ) {
			OperationManager::cancel( $op_id );
			Admin::redirect_with_notice( $back, __( 'Propuesta descartada.', 'gestion-de-proyectos' ), 'info' );
		}
		// Las correcciones del formulario sustituyen la lista propuesta: se cancela la propuesta original y se registran los acuerdos corregidos.
		$rows = array();
		foreach ( (array) ( $_POST['rows'] ?? array() ) as $i => $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( empty( $_POST['keep'][ $i ] ) || ! is_array( $row ) ) {
				continue;
			}
			$row    = wp_unslash( $row );
			$rows[] = array(
				'description' => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
				'owner_id'    => (int) ( $row['owner_id'] ?? 0 ),
				'owner_name'  => sanitize_text_field( (string) ( $row['owner_name'] ?? '' ) ),
				'due_date'    => sanitize_text_field( (string) ( $row['due_date'] ?? '' ) ),
			);
		}
		OperationManager::cancel( $op_id );
		if ( empty( $rows ) ) {
			Admin::redirect_with_notice( $back, __( 'No se seleccionó ningún acuerdo.', 'gestion-de-proyectos' ), 'warning' );
		}
		$result = OperationManager::execute( 'meeting', 'add_agreements', array( 'meeting_id' => $meeting_id, 'agreements' => $rows, 'origin' => 'propuesto' ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		if ( ! empty( $op['payload']['save_transcript'] ) && ! empty( $op['payload']['text'] ) ) {
			MeetingRepository::update( $meeting_id, array( 'transcript' => (string) $op['payload']['text'] ), null );
		}
		$count = (int) ( $result['result']['count'] ?? 0 );
		/* translators: número de acuerdos. */
		Admin::redirect_with_notice( $back, sprintf( _n( '%d acuerdo registrado.', '%d acuerdos registrados.', $count, 'gestion-de-proyectos' ), $count ) );
	}

	/**
	 * Descarga del acta.
	 *
	 * @return void
	 */
	public static function handle_minutes(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'gdp_minutes_' . $id );
		$m = MeetingRepository::find( $id );
		if ( ! $m || ! Access::can( 'meetings.view', $m['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'tex';
		$base   = sanitize_file_name( 'acta-' . str_replace( '/', '-', $m['code'] ) );
		if ( 'docx' === $format ) {
			$body = MinutesTemplate::docx( $m );
			if ( is_wp_error( $body ) ) {
				wp_die( esc_html( $body->get_error_message() ), 500 );
			}
			$type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$ext  = 'docx';
		} else {
			$body = MinutesTemplate::latex( $m );
			$type = 'application/x-tex; charset=UTF-8';
			$ext  = 'tex';
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $base . '.' . $ext . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga de archivo generado.
		exit;
	}
}
