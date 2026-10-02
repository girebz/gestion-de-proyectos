<?php
/**
 * Vista del informe semanal.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

use GDP\Admin\Pages\PlanningReportPage;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\ProgressCurve;
use GDP\Modules\Planning\WeeklyReport;

defined( 'ABSPATH' ) || exit;

/**
 * Informe semanal: resumen, avance por frente, cambios respecto de la semana
 * anterior, curva S, avances registrados, secciones por periodo, desviación
 * respecto de la línea base y alertas altas; navegación por semana y, si el
 * contexto lo permite, descargas.
 */
final class ReportView {

	/**
	 * Imprime la vista.
	 *
	 * @param int         $project_id Proyecto.
	 * @param ViewContext $ctx        Contexto.
	 * @return void
	 */
	public static function render( int $project_id, ViewContext $ctx ): void {
		$week   = ActivityRepository::normalize_date( $ctx->get( 'week', '' ) );
		$report = WeeklyReport::build( $project_id, $week ? $week : null );
		$monday = WeeklyReport::monday( $report['week']['from'] );
		$labels = ActivityRepository::status_labels();
		?>
		<div class="gdp-planning-toolbar">
			<?php echo $ctx->form_start(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<a class="button" href="<?php echo esc_url( $ctx->url( array( 'week' => $monday->modify( '-7 days' )->format( 'Y-m-d' ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Semana anterior', 'gestion-de-proyectos' ); ?>">&larr;</a>
				<input type="date" name="<?php echo esc_attr( $ctx->param( 'week' ) ); ?>" value="<?php echo esc_attr( $report['week']['from'] ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Ver semana', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( $ctx->url( array( 'week' => $monday->modify( '+7 days' )->format( 'Y-m-d' ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Semana siguiente', 'gestion-de-proyectos' ); ?>">&rarr;</a>
			</form>
			<?php if ( $ctx->exports() ) : ?>
				<span class="gdp-actions">
					<?php foreach ( array( 'tex' => 'LaTeX', 'json' => 'JSON' ) + PlanningReportPage::schedule_formats() as $format => $label ) : ?>
						<a class="button" href="<?php echo esc_url( PlanningReportPage::export_url( $project_id, $format, $report['week']['from'] ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</div>

		<h2 class="gdp-report__title"><?php printf( /* translators: 1: número de semana, 2: año, 3: fecha de inicio, 4: fecha de término. */ esc_html__( 'Semana %1$d de %2$d (%3$s al %4$s)', 'gestion-de-proyectos' ), (int) $report['week']['number'], (int) $report['week']['year'], esc_html( WeeklyReport::human_date( $report['week']['from'] ) ), esc_html( WeeklyReport::human_date( $report['week']['to'] ) ) ); ?></h2>

		<div class="gdp-planning-summary">
			<span><strong><?php echo (int) $report['stats']['percent']; ?> %</strong> <?php esc_html_e( 'avance global', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $report['stats']['by_status']['terminada']; ?></strong> <?php esc_html_e( 'terminadas', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $report['stats']['by_status']['en_curso']; ?></strong> <?php esc_html_e( 'en curso', 'gestion-de-proyectos' ); ?></span>
			<span class="<?php echo count( $report['overdue'] ) > 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo count( $report['overdue'] ); ?></strong> <?php esc_html_e( 'vencidas', 'gestion-de-proyectos' ); ?></span>
			<?php if ( $report['schedule'] && null !== $report['schedule']['deadline_slack'] ) : ?>
				<span class="<?php echo $report['schedule']['deadline_slack'] < 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><strong><?php echo (int) $report['schedule']['deadline_slack']; ?></strong> <?php esc_html_e( 'días hábiles de holgura contractual', 'gestion-de-proyectos' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Avance por frente de trabajo', 'gestion-de-proyectos' ); ?></h2>
				<div class="gdp-table-scroll">
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Frente', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Act.', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Term.', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'En curso', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Vencidas', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Avance', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $report['fronts'] as $f ) : ?>
						<tr><td><?php echo esc_html( $f['label'] ); ?></td><td class="gdp-num"><?php echo (int) $f['activities']; ?></td><td class="gdp-num"><?php echo (int) $f['done']; ?></td><td class="gdp-num"><?php echo (int) $f['in_progress']; ?></td><td class="gdp-num <?php echo $f['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $f['overdue']; ?></td><td class="gdp-num"><span class="gdp-progress"><span class="gdp-progress__bar" style="width: <?php echo (int) $f['percent']; ?>%"></span></span> <?php echo (int) $f['percent']; ?> %</td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Cambios respecto de la semana anterior', 'gestion-de-proyectos' ); ?></h2>
				<?php $ch = $report['changes']; ?>
				<?php if ( ! $ch ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Semana futura: sin fotografía del cronograma todavía.', 'gestion-de-proyectos' ); ?></p>
				<?php elseif ( ! $ch['has_previous'] ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Primera fotografía del cronograma: la comparación empieza la próxima semana.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="gdp-facts">
						<tr><th><?php esc_html_e( 'Frentes que se abren', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( empty( $ch['fronts_opened'] ) ? '—' : implode( ', ', $ch['fronts_opened'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Frentes que se cierran', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( empty( $ch['fronts_closed'] ) ? '—' : implode( ', ', $ch['fronts_closed'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Entran en la ruta crítica', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( empty( $ch['critical_in'] ) ? '—' : implode( ', ', array_map( static fn( array $x ): string => $x['code'] . ' ' . $x['name'], $ch['critical_in'] ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Salen de la ruta crítica', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( empty( $ch['critical_out'] ) ? '—' : implode( ', ', array_map( static fn( array $x ): string => $x['code'] . ' ' . $x['name'], $ch['critical_out'] ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Término programado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $ch['finish_before'] && $ch['finish_now'] && $ch['finish_before'] !== $ch['finish_now'] ? $ch['finish_before'] . ' → ' . $ch['finish_now'] : ( $ch['finish_now'] ?? '—' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Avance global', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( null !== $ch['percent_before'] ? $ch['percent_before'] . ' % → ' . $ch['percent_now'] . ' %' : (string) $ch['percent_now'] . ' %' ); ?> <span class="gdp-muted">(<?php echo esc_html( sprintf( /* translators: lunes de la semana comparada. */ __( 'semana comparada: %s', 'gestion-de-proyectos' ), (string) $ch['previous_week'] ) ); ?>)</span></td></tr>
					</table>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Curva S de avance', 'gestion-de-proyectos' ); ?></h2>
				<?php $curve = $report['curve']; ?>
				<?php if ( ! empty( $curve['points'] ) ) : ?>
					<p class="gdp-planning-summary">
						<span><strong><?php echo esc_html( number_format_i18n( (float) $curve['planned_today'], 1 ) ); ?> %</strong> <?php esc_html_e( 'planificado a la fecha', 'gestion-de-proyectos' ); ?></span>
						<span><strong><?php echo esc_html( number_format_i18n( (float) $curve['actual_today'], 1 ) ); ?> %</strong> <?php esc_html_e( 'real', 'gestion-de-proyectos' ); ?></span>
						<span class="<?php echo $curve['variance'] < 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><strong><?php echo esc_html( ( $curve['variance'] > 0 ? '+' : '' ) . number_format_i18n( (float) $curve['variance'], 1 ) ); ?></strong> <?php esc_html_e( 'puntos de desviación', 'gestion-de-proyectos' ); ?></span>
					</p>
					<?php echo ProgressCurve::svg( $curve ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p class="gdp-muted gdp-small"><?php echo $curve['baseline'] ? esc_html( sprintf( /* translators: nombre de la línea base. */ __( 'Plan según la línea base "%s"; real según el historial de avances, ponderado por duración.', 'gestion-de-proyectos' ), $curve['baseline'] ) ) : esc_html__( 'Sin línea base vigente: el plan se toma del cronograma actual. Fije una línea base para medir la desviación contra el compromiso.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin actividades con fechas y duración.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Avances registrados en la semana', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $report['progress'] ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'No se registraron avances durante la semana.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Avance', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Quién', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $report['progress'] as $p ) : ?>
							<tr><td><code><?php echo esc_html( $p['code'] ); ?></code> <?php echo esc_html( $p['name'] ); ?></td><td><?php echo (int) $p['previous_percent']; ?> % → <?php echo (int) $p['percent']; ?> %</td><td><?php echo esc_html( $labels[ $p['status'] ] ?? $p['status'] ); ?></td><td><?php echo esc_html( $p['note'] ); ?></td><td><?php echo esc_html( $p['user'] ); ?> <span class="gdp-muted"><?php echo esc_html( $p['reported_at'] ); ?></span></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php
			$sections = array(
				'completed'  => __( 'Terminado en la semana', 'gestion-de-proyectos' ),
				'started'    => __( 'Iniciado en la semana', 'gestion-de-proyectos' ),
				'in_week'    => __( 'En ejecución durante la semana', 'gestion-de-proyectos' ),
				'upcoming'   => __( 'Comienza la próxima semana', 'gestion-de-proyectos' ),
				'milestones' => __( 'Hitos de las próximas cuatro semanas', 'gestion-de-proyectos' ),
				'overdue'    => __( 'Vencidas', 'gestion-de-proyectos' ),
			);
			foreach ( $sections as $key => $title ) :
				?>
				<div class="gdp-card <?php echo 'overdue' === $key && ! empty( $report[ $key ] ) ? 'gdp-card--accent' : ''; ?>">
					<h2><?php echo esc_html( $title ); ?> <span class="gdp-muted">(<?php echo count( $report[ $key ] ); ?>)</span></h2>
					<?php if ( empty( $report[ $key ] ) ) : ?>
						<p class="gdp-muted"><?php esc_html_e( 'Sin registros.', 'gestion-de-proyectos' ); ?></p>
					<?php else : ?>
						<ul class="gdp-list">
							<?php foreach ( $report[ $key ] as $a ) : ?>
								<li>
									<code><?php echo esc_html( $a['code'] ); ?></code> <?php echo esc_html( $a['name'] ); ?>
									<span class="gdp-muted"><?php echo esc_html( 'milestone' === $a['kind'] ? (string) $a['end_date'] : $a['start_date'] . ' → ' . $a['end_date'] ); ?> · <?php echo (int) $a['percent']; ?> %<?php echo isset( $a['days_late'] ) ? ' · ' . esc_html( sprintf( /* translators: días hábiles de atraso. */ __( '%d días de atraso', 'gestion-de-proyectos' ), (int) $a['days_late'] ) ) : ''; ?><?php echo $a['owner'] ? ' · ' . esc_html( $a['owner'] ) : ''; ?></span>
									<?php if ( $a['is_critical'] ) : ?><span class="gdp-badge gdp-badge--critical"><?php esc_html_e( 'crítica', 'gestion-de-proyectos' ); ?></span><?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Desviación respecto de la línea base', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( ! $report['variance'] ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'El proyecto no tiene una línea base vigente.', 'gestion-de-proyectos' ); ?><?php if ( '' !== $ctx->baselines_url() ) : ?> <a href="<?php echo esc_url( $ctx->baselines_url() ); ?>"><?php esc_html_e( 'Fijar una', 'gestion-de-proyectos' ); ?></a><?php endif; ?></p>
				<?php else : ?>
					<?php $v = $report['variance']; ?>
					<p><?php printf( /* translators: 1: nombre de la línea base, 2: fecha, 3: atrasadas, 4: adelantadas, 5: nuevas, 6: eliminadas. */ esc_html__( 'Línea base "%1$s" (%2$s): %3$d atrasadas, %4$d adelantadas, %5$d nuevas, %6$d eliminadas.', 'gestion-de-proyectos' ), esc_html( $v['baseline_name'] ), esc_html( $v['baseline_date'] ), (int) $v['summary']['delayed'], (int) $v['summary']['advanced'], (int) $v['summary']['new'], (int) $v['summary']['removed'] ); ?></p>
					<?php if ( ! empty( $v['top_delayed'] ) ) : ?>
						<ul class="gdp-list">
							<?php foreach ( $v['top_delayed'] as $r ) : ?>
								<li><code><?php echo esc_html( $r['code'] ); ?></code> <?php echo esc_html( $r['name'] ); ?> <span class="gdp-text-danger">+<?php echo (int) $r['variance']; ?></span> <span class="gdp-muted"><?php echo esc_html( $r['baseline']['end_date'] . ' → ' . $r['current']['end_date'] ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $report['alerts'] ) ) : ?>
			<div class="gdp-card gdp-card--accent">
				<h2><?php esc_html_e( 'Alertas de severidad alta', 'gestion-de-proyectos' ); ?></h2>
				<ul class="gdp-list">
					<?php foreach ( $report['alerts'] as $al ) : ?>
						<li><strong><?php echo esc_html( trim( $al['code'] . ' ' . $al['name'] ) ); ?></strong>: <?php echo esc_html( $al['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
		<?php if ( $ctx->exports() ) : ?>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'La exportación LaTeX genera un documento completo (babel español, tablas longtable con continuación, carta Gantt resumida con pgfgantt) listo para compilar con pdflatex.', 'gestion-de-proyectos' ); ?></p>
		<?php endif; ?>
		<?php
	}
}
