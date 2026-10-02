<?php
/**
 * Calendario integrado (mes, semana, agenda) y suscripción iCalendar.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\EventsService;
use GDP\Modules\Planning\Views\CalendarView;

defined( 'ABSPATH' ) || exit;

/**
 * Calendario del proyecto (vista compartida con el código corto
 * [gdp_calendario]) y enlace de suscripción iCalendar por proyecto.
 */
final class PlanningCalendarViewPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_ics', array( self::class, 'handle_feed' ) );
		add_action( 'admin_post_nopriv_gdp_ics', array( self::class, 'handle_feed' ) );
		add_action( 'admin_post_gdp_ics_key', array( self::class, 'handle_key' ) );
	}

	/**
	 * Pantalla: la vista compartida del calendario y la tarjeta de suscripción.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render( array $project ): void {
		$project_id = (int) $project['id'];

		PlanningPage::header( $project, 'calendar', __( 'Calendario', 'gestion-de-proyectos' ) );
		CalendarView::render( $project_id, PlanningPage::context( $project_id, 'calendar' ) );
		?>
		<div class="gdp-card" style="margin-top:16px">
			<h2><?php esc_html_e( 'Suscripción desde su calendario', 'gestion-de-proyectos' ); ?></h2>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'Añada esta dirección como calendario por URL en Google Calendar, Outlook o Apple Calendar: los hitos, inicios y términos se actualizan solos (cada seis horas). La dirección lleva una clave secreta: no la publique; puede regenerarla para invalidar la anterior.', 'gestion-de-proyectos' ); ?></p>
			<?php if ( Access::can( 'planning.view', $project_id ) ) : ?>
				<div class="gdp-copy"><code id="gdp-ics-url"><?php echo esc_html( EventsService::feed_url( $project_id ) ); ?></code> <button type="button" class="button button-small gdp-copy-button" data-copy="#gdp-ics-url"><?php esc_html_e( 'Copiar', 'gestion-de-proyectos' ); ?></button></div>
				<?php if ( Access::can( 'planning.edit', $project_id ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1" style="margin-top:8px">
					<?php wp_nonce_field( 'gdp_ics_key_' . $project_id ); ?>
					<input type="hidden" name="action" value="gdp_ics_key">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Regenerar clave', 'gestion-de-proyectos' ); ?></button>
				</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Canal iCalendar (sin sesión: autentica por clave secreta).
	 *
	 * @return void
	 */
	public static function handle_feed(): void {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key        = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$project    = $project_id > 0 ? ProjectRepository::find( $project_id ) : null;
		$expected   = $project ? (string) ( $project['settings']['ics_key'] ?? '' ) : '';

		if ( ! $project || '' === $expected || '' === $key || ! hash_equals( $expected, $key ) ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'No autorizado.';
			exit;
		}

		$body = EventsService::to_ics( $project_id );
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'calendario-' . $project['code'] ) . '.ics"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar generado.
		exit;
	}

	/**
	 * Regenera la clave del canal.
	 *
	 * @return void
	 */
	public static function handle_key(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_ics_key_' . $project_id );
		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		EventsService::feed_key( $project_id, true );
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'calendar' ), __( 'Clave regenerada: las suscripciones anteriores dejan de funcionar.', 'gestion-de-proyectos' ) );
	}
}
