<?php
/**
 * Calendarios laborales del proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Modules\Planning\CalendarRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Días laborables, feriados y excepciones; carga de feriados de Chile.
 */
final class PlanningCalendarsPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_calendar', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_gdp_delete_calendar', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_gdp_calendar_default', array( self::class, 'handle_default' ) );
		add_action( 'admin_post_gdp_calendar_exception', array( self::class, 'handle_exception' ) );
		add_action( 'admin_post_gdp_calendar_holidays', array( self::class, 'handle_holidays' ) );
	}

	/**
	 * Pantalla.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'planning.edit', $project_id );
		$own        = CalendarRepository::for_project( $project_id );
		$global     = Access::is_manager() ? CalendarRepository::for_project( 0 ) : array();
		$effective  = CalendarRepository::effective( $project_id );
		$selected   = isset( $_GET['calendar_id'] ) ? (int) $_GET['calendar_id'] : ( $effective ? $effective['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$calendar   = $selected > 0 ? CalendarRepository::find( $selected ) : null;
		if ( $calendar && $calendar['project_id'] !== $project_id && 0 !== $calendar['project_id'] ) {
			$calendar = null;
		}
		$days = array( 1 => __( 'Lunes', 'gestion-de-proyectos' ), 2 => __( 'Martes', 'gestion-de-proyectos' ), 3 => __( 'Miércoles', 'gestion-de-proyectos' ), 4 => __( 'Jueves', 'gestion-de-proyectos' ), 5 => __( 'Viernes', 'gestion-de-proyectos' ), 6 => __( 'Sábado', 'gestion-de-proyectos' ), 7 => __( 'Domingo', 'gestion-de-proyectos' ) );
		$year = (int) current_time( 'Y' );

		PlanningPage::header( $project, 'calendars', __( 'Calendarios laborales', 'gestion-de-proyectos' ) );
		?>
		<p class="gdp-muted">
			<?php
			if ( $effective ) {
				printf( esc_html__( 'El proyecto programa con el calendario "%1$s" (%2$s).', 'gestion-de-proyectos' ), esc_html( $effective['name'] ), $effective['project_id'] > 0 ? esc_html__( 'propio del proyecto', 'gestion-de-proyectos' ) : esc_html__( 'global del sitio', 'gestion-de-proyectos' ) );
			} else {
				esc_html_e( 'El proyecto no tiene calendario propio ni existe uno global: se programa de lunes a viernes sin feriados. Cree un calendario y cargue los feriados de Chile.', 'gestion-de-proyectos' );
			}
			?>
		</p>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Calendarios', 'gestion-de-proyectos' ); ?></h2>
				<?php foreach ( array( __( 'Del proyecto', 'gestion-de-proyectos' ) => $own, __( 'Globales', 'gestion-de-proyectos' ) => $global ) as $title => $list ) : ?>
					<?php if ( empty( $list ) ) { continue; } ?>
					<h3><?php echo esc_html( $title ); ?></h3>
					<ul class="gdp-list">
						<?php foreach ( $list as $c ) : ?>
							<li>
								<a href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $c['id'] ) ) ); ?>"><?php echo esc_html( $c['name'] ); ?></a>
								<span class="gdp-muted"><?php echo esc_html( implode( ', ', array_map( static fn( int $d ) => mb_substr( $days[ $d ], 0, 3 ), $c['weekdays'] ) ) ); ?></span>
								<?php if ( $c['is_default'] ) : ?><span class="gdp-badge gdp-badge--ok"><?php esc_html_e( 'por defecto', 'gestion-de-proyectos' ); ?></span><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>

				<?php if ( $can_edit ) : ?>
				<h3><?php esc_html_e( 'Nuevo calendario', 'gestion-de-proyectos' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gdp_save_calendar_0' ); ?>
					<input type="hidden" name="action" value="gdp_save_calendar">
					<input type="hidden" name="id" value="0">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<p><input type="text" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'Nombre (por ejemplo, Calendario del proyecto)', 'gestion-de-proyectos' ); ?>"></p>
					<p>
						<?php foreach ( $days as $n => $label ) : ?>
							<label class="gdp-check"><input type="checkbox" name="weekdays[]" value="<?php echo (int) $n; ?>" <?php checked( $n <= 5 ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</p>
					<?php if ( Access::is_manager() ) : ?>
						<p><label class="gdp-check"><input type="checkbox" name="global" value="1"> <?php esc_html_e( 'Calendario global (disponible para todos los proyectos sin calendario propio)', 'gestion-de-proyectos' ); ?></label></p>
					<?php endif; ?>
					<p><label class="gdp-check"><input type="checkbox" name="holidays" value="1" checked> <?php printf( esc_html__( 'Cargar los feriados de Chile de %1$d a %2$d', 'gestion-de-proyectos' ), (int) $year, (int) $year + 3 ); ?></label></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Crear calendario', 'gestion-de-proyectos' ); ?></button></p>
				</form>
				<?php endif; ?>
			</div>

			<?php if ( $calendar ) : ?>
			<div class="gdp-card">
				<h2><?php echo esc_html( $calendar['name'] ); ?></h2>
				<?php $editable = $can_edit && ( $calendar['project_id'] === $project_id || Access::is_manager() ); ?>
				<?php if ( $editable ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gdp_save_calendar_' . $calendar['id'] ); ?>
					<input type="hidden" name="action" value="gdp_save_calendar">
					<input type="hidden" name="id" value="<?php echo (int) $calendar['id']; ?>">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<p><input type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $calendar['name'] ); ?>"></p>
					<p>
						<?php foreach ( $days as $n => $label ) : ?>
							<label class="gdp-check"><input type="checkbox" name="weekdays[]" value="<?php echo (int) $n; ?>" <?php checked( in_array( $n, $calendar['weekdays'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</p>
					<p>
						<button type="submit" class="button"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
					</p>
				</form>
				<div class="gdp-actions">
					<?php if ( ! $calendar['is_default'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
						<?php wp_nonce_field( 'gdp_calendar_default_' . $calendar['id'] ); ?>
						<input type="hidden" name="action" value="gdp_calendar_default">
						<input type="hidden" name="id" value="<?php echo (int) $calendar['id']; ?>">
						<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Usar por defecto', 'gestion-de-proyectos' ); ?></button>
					</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
						<?php wp_nonce_field( 'gdp_calendar_holidays_' . $calendar['id'] ); ?>
						<input type="hidden" name="action" value="gdp_calendar_holidays">
						<input type="hidden" name="id" value="<?php echo (int) $calendar['id']; ?>">
						<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
						<input type="number" name="from" min="2000" max="2100" value="<?php echo (int) $year; ?>" class="small-text" aria-label="<?php esc_attr_e( 'Desde', 'gestion-de-proyectos' ); ?>">
						<input type="number" name="to" min="2000" max="2100" value="<?php echo (int) $year + 3; ?>" class="small-text" aria-label="<?php esc_attr_e( 'Hasta', 'gestion-de-proyectos' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Cargar feriados de Chile', 'gestion-de-proyectos' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
						<?php wp_nonce_field( 'gdp_delete_calendar_' . $calendar['id'] ); ?>
						<input type="hidden" name="action" value="gdp_delete_calendar">
						<input type="hidden" name="id" value="<?php echo (int) $calendar['id']; ?>">
						<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
						<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar calendario', 'gestion-de-proyectos' ); ?></button>
					</form>
				</div>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Excepciones', 'gestion-de-proyectos' ); ?></h3>
				<?php if ( $editable ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
					<?php wp_nonce_field( 'gdp_calendar_exception_' . $calendar['id'] ); ?>
					<input type="hidden" name="action" value="gdp_calendar_exception">
					<input type="hidden" name="id" value="<?php echo (int) $calendar['id']; ?>">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<input type="hidden" name="op" value="set">
					<input type="date" name="date" required>
					<select name="working">
						<option value="0"><?php esc_html_e( 'No laborable (feriado)', 'gestion-de-proyectos' ); ?></option>
						<option value="1"><?php esc_html_e( 'Laborable por excepción', 'gestion-de-proyectos' ); ?></option>
					</select>
					<input type="text" name="label" placeholder="<?php esc_attr_e( 'Motivo', 'gestion-de-proyectos' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Añadir', 'gestion-de-proyectos' ); ?></button>
				</form>
				<?php endif; ?>
				<?php $exceptions = CalendarRepository::exceptions( $calendar['id'] ); ?>
				<?php if ( empty( $exceptions ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin excepciones. Cargue los feriados de Chile o añada fechas.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Motivo', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $exceptions as $e ) : ?>
							<tr>
								<td><?php echo esc_html( $e['exception_date'] ); ?> <span class="gdp-muted"><?php echo esc_html( date_i18n( 'D', strtotime( $e['exception_date'] ) ) ); ?></span></td>
								<td><?php echo $e['working'] ? esc_html__( 'Laborable', 'gestion-de-proyectos' ) : esc_html__( 'No laborable', 'gestion-de-proyectos' ); ?></td>
								<td><?php echo esc_html( $e['label'] ); ?></td>
								<td>
									<?php if ( $editable ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
										<?php wp_nonce_field( 'gdp_calendar_exception_' . $calendar['id'] ); ?>
										<input type="hidden" name="action" value="gdp_calendar_exception">
										<input type="hidden" name="id" value="<?php echo (int) $calendar['id']; ?>">
										<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
										<input type="hidden" name="op" value="remove">
										<input type="hidden" name="date" value="<?php echo esc_attr( $e['exception_date'] ); ?>">
										<button type="submit" class="button-link gdp-link-danger"><?php esc_html_e( 'Quitar', 'gestion-de-proyectos' ); ?></button>
									</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Comprueba permisos sobre un calendario.
	 *
	 * @param int $calendar_id Calendario (0 = nuevo).
	 * @param int $project_id  Proyecto.
	 * @return array<string,mixed>|null Calendario existente.
	 */
	private static function guard( int $calendar_id, int $project_id ): ?array {
		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		if ( 0 === $calendar_id ) {
			return null;
		}
		$calendar = CalendarRepository::find( $calendar_id );
		if ( ! $calendar || ( $calendar['project_id'] !== $project_id && ! ( 0 === $calendar['project_id'] && Access::is_manager() ) ) ) {
			wp_die( esc_html__( 'Calendario no encontrado o sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		return $calendar;
	}

	/**
	 * Crea o actualiza un calendario.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_save_calendar_' . $id );
		self::guard( $id, $project_id );

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$weekdays = isset( $_POST['weekdays'] ) && is_array( $_POST['weekdays'] ) ? array_map( 'intval', wp_unslash( $_POST['weekdays'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$back     = PlanningPage::url( $project_id, 'calendars' );

		if ( 0 === $id ) {
			$scope  = ! empty( $_POST['global'] ) && Access::is_manager() ? 0 : $project_id;
			$result = CalendarRepository::create( $scope, $name, $weekdays, true );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
			}
			if ( ! empty( $_POST['holidays'] ) ) {
				$year = (int) current_time( 'Y' );
				CalendarRepository::add_chile_holidays( $result, $year, $year + 3 );
			}
			ScheduleService::recalculate( $project_id );
			Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $result ) ), __( 'Calendario creado.', 'gestion-de-proyectos' ) );
		}

		$result = CalendarRepository::update( $id, $name, $weekdays );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		ScheduleService::recalculate( $project_id );
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $id ) ), __( 'Calendario guardado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina un calendario.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_delete_calendar_' . $id );
		self::guard( $id, $project_id );

		$result = OperationManager::execute( 'activity', 'delete_calendar', array( 'calendar_id' => $id ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $id ) ), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendars' ), sprintf( __( 'Calendario eliminado. Puede restaurarlo desde la <a href="%s">papelera</a>.', 'gestion-de-proyectos' ), esc_url( Admin::url( 'trash', array( 'project_id' => $project_id ) ) ) ) );
	}

	/**
	 * Marca el calendario por defecto.
	 *
	 * @return void
	 */
	public static function handle_default(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_calendar_default_' . $id );
		self::guard( $id, $project_id );

		CalendarRepository::set_default( $id );
		ScheduleService::recalculate( $project_id );
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $id ) ), __( 'Calendario por defecto actualizado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Añade o quita una excepción.
	 *
	 * @return void
	 */
	public static function handle_exception(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_calendar_exception_' . $id );
		self::guard( $id, $project_id );

		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : 'set';
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : '';
		$back = PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $id ) );

		if ( 'remove' === $op ) {
			CalendarRepository::remove_exception( $id, $date );
		} else {
			$result = CalendarRepository::set_exception( $id, $date, ! empty( $_POST['working'] ), isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '' );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
			}
		}
		ScheduleService::recalculate( $project_id );
		Admin::redirect_with_notice( $back, __( 'Excepciones actualizadas.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Carga los feriados de Chile.
	 *
	 * @return void
	 */
	public static function handle_holidays(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_calendar_holidays_' . $id );
		self::guard( $id, $project_id );

		$from = isset( $_POST['from'] ) ? max( 2000, min( 2100, (int) $_POST['from'] ) ) : (int) current_time( 'Y' );
		$to   = isset( $_POST['to'] ) ? max( $from, min( 2100, (int) $_POST['to'] ) ) : $from;
		$n    = CalendarRepository::add_chile_holidays( $id, $from, $to );
		ScheduleService::recalculate( $project_id );
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendars', array( 'calendar_id' => $id ) ), sprintf( __( '%d feriados cargados.', 'gestion-de-proyectos' ), $n ) );
	}
}
