<?php
/**
 * Vista de calendario (mes, semana, agenda).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

use DateTimeImmutable;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\CalendarRepository;
use GDP\Modules\Planning\EventsService;
use GDP\Modules\Planning\WeeklyReport;

defined( 'ABSPATH' ) || exit;

/**
 * Hitos, inicios y términos de actividad, término contractual y los eventos de
 * otros módulos, en cuadrícula mensual, semana o agenda, con navegación.
 */
final class CalendarView {

	/**
	 * Imprime la vista.
	 *
	 * @param int         $project_id Proyecto.
	 * @param ViewContext $ctx        Contexto.
	 * @return void
	 */
	public static function render( int $project_id, ViewContext $ctx ): void {
		$mode     = sanitize_key( $ctx->get( 'mode', 'month' ) );
		$mode     = in_array( $mode, array( 'month', 'week', 'agenda' ), true ) ? $mode : 'month';
		$date     = ActivityRepository::normalize_date( $ctx->get( 'date', '' ) );
		$date     = $date ? $date : current_time( 'Y-m-d' );
		$today    = current_time( 'Y-m-d' );
		$calendar = CalendarRepository::build( $project_id );
		$anchor   = new DateTimeImmutable( $date );

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
			/* translators: 1: número de semana, 2: fecha de inicio, 3: fecha de término. */
			$title = sprintf( __( 'Semana %1$d: %2$s al %3$s', 'gestion-de-proyectos' ), (int) $from->format( 'W' ), WeeklyReport::human_date( $from->format( 'Y-m-d' ) ), WeeklyReport::human_date( $to->format( 'Y-m-d' ) ) );
		} else {
			$from  = $anchor;
			$to    = $anchor->modify( '+59 days' );
			$prev  = $anchor->modify( '-60 days' )->format( 'Y-m-d' );
			$next  = $anchor->modify( '+60 days' )->format( 'Y-m-d' );
			/* translators: 1: fecha de inicio, 2: fecha de término. */
			$title = sprintf( __( 'Agenda: %1$s al %2$s', 'gestion-de-proyectos' ), WeeklyReport::human_date( $from->format( 'Y-m-d' ) ), WeeklyReport::human_date( $to->format( 'Y-m-d' ) ) );
		}

		$events = EventsService::between( $project_id, $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
		$by_day = array();
		foreach ( $events as $e ) {
			$by_day[ $e['date'] ][] = $e;
		}
		$days = array( __( 'Lun', 'gestion-de-proyectos' ), __( 'Mar', 'gestion-de-proyectos' ), __( 'Mié', 'gestion-de-proyectos' ), __( 'Jue', 'gestion-de-proyectos' ), __( 'Vie', 'gestion-de-proyectos' ), __( 'Sáb', 'gestion-de-proyectos' ), __( 'Dom', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-toolbar">
			<a class="button" href="<?php echo esc_url( $ctx->url( array( 'mode' => $mode, 'date' => $prev ) ) ); ?>" aria-label="<?php esc_attr_e( 'Anterior', 'gestion-de-proyectos' ); ?>">&larr;</a>
			<a class="button" href="<?php echo esc_url( $ctx->url( array( 'mode' => $mode, 'date' => $today ) ) ); ?>"><?php esc_html_e( 'Hoy', 'gestion-de-proyectos' ); ?></a>
			<a class="button" href="<?php echo esc_url( $ctx->url( array( 'mode' => $mode, 'date' => $next ) ) ); ?>" aria-label="<?php esc_attr_e( 'Siguiente', 'gestion-de-proyectos' ); ?>">&rarr;</a>
			<strong class="gdp-calendar__title"><?php echo esc_html( $title ); ?></strong>
			<span class="gdp-actions">
				<?php foreach ( array( 'month' => __( 'Mes', 'gestion-de-proyectos' ), 'week' => __( 'Semana', 'gestion-de-proyectos' ), 'agenda' => __( 'Agenda', 'gestion-de-proyectos' ) ) as $m => $label ) : ?>
					<a class="button button-small <?php echo $m === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url( $ctx->url( array( 'mode' => $m, 'date' => $date ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
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
								<li><?php echo self::chip( $e, $ctx, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
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
							<div class="gdp-calendar__num"><a href="<?php echo esc_url( $ctx->url( array( 'mode' => 'agenda', 'date' => $key ) ) ); ?>"><?php echo (int) $d->format( 'j' ); ?></a></div>
							<?php foreach ( array_slice( $list, 0, 'month' === $mode ? 4 : 30 ) as $e ) : ?>
								<?php echo self::chip( $e, $ctx, 'week' === $mode ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endforeach; ?>
							<?php if ( 'month' === $mode && count( $list ) > 4 ) : ?>
								<a class="gdp-calendar__more" href="<?php echo esc_url( $ctx->url( array( 'mode' => 'agenda', 'date' => $key ) ) ); ?>">+<?php echo count( $list ) - 4; ?></a>
							<?php endif; ?>
						</td>
					<?php endfor; ?>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Ficha compacta de un evento.
	 *
	 * @param array<string,mixed> $e    Evento.
	 * @param ViewContext         $ctx  Contexto.
	 * @param bool                $long Con rótulo del tipo.
	 * @return string HTML.
	 */
	private static function chip( array $e, ViewContext $ctx, bool $long ): string {
		$url     = '';
		if ( $ctx->links() ) {
			$url = '' !== $e['url'] ? $e['url'] : $ctx->edit_url( (int) $e['activity_id'] );
		}
		$classes = array( 'gdp-chip', 'gdp-chip--' . $e['type'], 'gdp-chip--' . $e['status'] );
		if ( $e['critical'] ) {
			$classes[] = 'gdp-chip--critical';
		}
		$text = ( $long ? $e['label'] . ': ' : '' ) . $e['title'];
		$html = sprintf( '<span class="%s" title="%s">%s</span>', esc_attr( implode( ' ', $classes ) ), esc_attr( $e['label'] . ': ' . $e['title'] ), esc_html( $text ) );

		return '' !== $url ? sprintf( '<a href="%s" class="gdp-chip-link">%s</a>', esc_url( $url ), $html ) : $html;
	}
}
