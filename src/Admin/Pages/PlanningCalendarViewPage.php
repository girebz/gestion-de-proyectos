<?php
/**
 * Calendario integrado (mes, semana, agenda) y suscripción iCalendar.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use DateTimeImmutable;
use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\CalendarRepository;
use GDP\Modules\Planning\EventsService;
use GDP\Modules\Planning\WeeklyReport;

defined( 'ABSPATH' ) || exit;

/**
 * Hitos, inicios y términos de actividad y término contractual, más los
 * eventos que aporten otros módulos, en cuadrícula mensual, semana o agenda;
 * enlace de suscripción iCalendar por proyecto.
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
	 * Pantalla.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render( array $project ): void {
		$project_id = (int) $project['id'];
		$mode       = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( (string) $_GET['mode'] ) ) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode       = in_array( $mode, array( 'month', 'week', 'agenda' ), true ) ? $mode : 'month';
		$date       = isset( $_GET['date'] ) ? ActivityRepository::normalize_date( sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date       = $date ? $date : current_time( 'Y-m-d' );
		$today      = current_time( 'Y-m-d' );
		$calendar   = CalendarRepository::build( $project_id );
		$anchor     = new DateTimeImmutable( $date );

		if ( 'month' === $mode ) {
			$first = $anchor->modify( 'first day of this month' );
			$last  = $anchor->modify( 'last day of this month' );
			$from  = WeeklyReport::monday( $first->format( 'Y-m-d' ) );
			$to    = WeeklyReport::monday( $last->format( 'Y-m-d' ) )->modify( '+6 days' );
			$prev  = $first->modify( '-1 month' )->format( 'Y-m-d' );
			$next  = $first->modify( '+1 month' )->format( 'Y-m-d' );
			$title = date_i18n( 'F Y', $first->getTimestamp() );
		} elseif ( 'week' === $mode ) {
			$from  = WeeklyReport::monday( $date );
			$to    = $from->modify( '+6 days' );
			$prev  = $from->modify( '-7 days' )->format( 'Y-m-d' );
			$next  = $from->modify( '+7 days' )->format( 'Y-m-d' );
			$title = sprintf( __( 'Semana %1$d: %2$s al %3$s', 'gestion-de-proyectos' ), (int) $from->format( 'W' ), WeeklyReport::human_date( $from->format( 'Y-m-d' ) ), WeeklyReport::human_date( $to->format( 'Y-m-d' ) ) );
		} else {
			$from  = $anchor;
			$to    = $anchor->modify( '+59 days' );
			$prev  = $anchor->modify( '-60 days' )->format( 'Y-m-d' );
			$next  = $anchor->modify( '+60 days' )->format( 'Y-m-d' );
			$title = sprintf( __( 'Agenda: %1$s al %2$s', 'gestion-de-proyectos' ), WeeklyReport::human_date( $from->format( 'Y-m-d' ) ), WeeklyReport::human_date( $to->format( 'Y-m-d' ) ) );
		}

		$events = EventsService::between( $project_id, $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
		$by_day = array();
		foreach ( $events as $e ) {
			$by_day[ $e['date'] ][] = $e;
		}
		$days = array( __( 'Lun', 'gestion-de-proyectos' ), __( 'Mar', 'gestion-de-proyectos' ), __( 'Mié', 'gestion-de-proyectos' ), __( 'Jue', 'gestion-de-proyectos' ), __( 'Vie', 'gestion-de-proyectos' ), __( 'Sáb', 'gestion-de-proyectos' ), __( 'Dom', 'gestion-de-proyectos' ) );

		PlanningPage::header( $project, 'calendar', __( 'Calendario', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-toolbar">
			<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar', array( 'mode' => $mode, 'date' => $prev ) ) ); ?>">&larr;</a>
			<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar', array( 'mode' => $mode, 'date' => $today ) ) ); ?>"><?php esc_html_e( 'Hoy', 'gestion-de-proyectos' ); ?></a>
			<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar', array( 'mode' => $mode, 'date' => $next ) ) ); ?>">&rarr;</a>
			<strong class="gdp-calendar__title"><?php echo esc_html( $title ); ?></strong>
			<span class="gdp-actions">
				<?php foreach ( array( 'month' => __( 'Mes', 'gestion-de-proyectos' ), 'week' => __( 'Semana', 'gestion-de-proyectos' ), 'agenda' => __( 'Agenda', 'gestion-de-proyectos' ) ) as $m => $label ) : ?>
					<a class="button button-small <?php echo $m === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar', array( 'mode' => $m, 'date' => $date ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</span>
		</div>

		<?php if ( 'agenda' === $mode ) : ?>
			<?php if ( empty( $events ) ) : ?>
				<p class="gdp-muted"><?php esc_html_e( 'Sin eventos en el periodo.', 'gestion-de-proyectos' ); ?></p>
			<?php else : ?>
				<div class="gdp-card gdp-agenda">
					<?php foreach ( $by_day as $day => $list ) : ?>
						<h3 class="<?php echo $day === $today ? 'gdp-agenda__today' : ''; ?>"><?php echo esc_html( date_i18n( 'l j \d\e F', strtotime( $day ) ) ); ?><?php echo $calendar->is_working( $day ) ? '' : ' <span class="gdp-muted gdp-small">' . esc_html__( '(no laborable)', 'gestion-de-proyectos' ) . '</span>'; ?></h3>
						<ul class="gdp-list">
							<?php foreach ( $list as $e ) : ?>
								<li><?php echo self::chip( $e, $project_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php else : ?>
			<table class="gdp-calendar gdp-calendar--<?php echo esc_attr( $mode ); ?>">
				<thead><tr><?php foreach ( $days as $d ) : ?><th><?php echo esc_html( $d ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php for ( $cursor = $from; $cursor <= $to; $cursor = $cursor->modify( '+7 days' ) ) : ?>
					<tr>
					<?php for ( $i = 0; $i < 7; $i++ ) : ?>
						<?php
						$d       = $cursor->modify( '+' . $i . ' days' );
						$key     = $d->format( 'Y-m-d' );
						$classes = array( 'gdp-calendar__day' );
						if ( ! $calendar->is_working( $key ) ) {
							$classes[] = 'gdp-calendar__day--off';
						}
						if ( $key === $today ) {
							$classes[] = 'gdp-calendar__day--today';
						}
						if ( 'month' === $mode && $d->format( 'm' ) !== $anchor->format( 'm' ) ) {
							$classes[] = 'gdp-calendar__day--other';
						}
						$list = $by_day[ $key ] ?? array();
						?>
						<td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
							<div class="gdp-calendar__num"><a href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar', array( 'mode' => 'agenda', 'date' => $key ) ) ); ?>"><?php echo (int) $d->format( 'j' ); ?></a></div>
							<?php foreach ( array_slice( $list, 0, 'month' === $mode ? 4 : 30 ) as $e ) : ?>
								<?php echo self::chip( $e, $project_id, 'week' === $mode ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endforeach; ?>
							<?php if ( 'month' === $mode && count( $list ) > 4 ) : ?>
								<a class="gdp-calendar__more" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar', array( 'mode' => 'agenda', 'date' => $key ) ) ); ?>">+<?php echo count( $list ) - 4; ?></a>
							<?php endif; ?>
						</td>
					<?php endfor; ?>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>
		<?php endif; ?>

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
	 * Ficha compacta de un evento.
	 *
	 * @param array<string,mixed> $e          Evento.
	 * @param int                 $project_id Proyecto.
	 * @param bool                $long       Con rótulo del tipo.
	 * @return string HTML.
	 */
	private static function chip( array $e, int $project_id, bool $long ): string {
		$url = '' !== $e['url'] ? $e['url'] : ( $e['activity_id'] > 0 && Access::can( 'planning.edit', $project_id ) ? PlanningPage::url( $project_id, 'edit', array( 'id' => $e['activity_id'] ) ) : '' );
		$classes = array( 'gdp-chip', 'gdp-chip--' . $e['type'], 'gdp-chip--' . $e['status'] );
		if ( $e['critical'] ) {
			$classes[] = 'gdp-chip--critical';
		}
		$text = ( $long ? $e['label'] . ': ' : '' ) . $e['title'];
		$html = sprintf( '<span class="%s" title="%s">%s</span>', esc_attr( implode( ' ', $classes ) ), esc_attr( $e['label'] . ': ' . $e['title'] ), esc_html( $text ) );

		return '' !== $url ? sprintf( '<a href="%s" class="gdp-chip-link">%s</a>', esc_url( $url ), $html ) : $html;
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
