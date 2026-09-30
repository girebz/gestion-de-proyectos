<?php
/**
 * Alertas e informe semanal.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\PlanningCron;
use GDP\Modules\Planning\ProgressCurve;
use GDP\Modules\Planning\ScheduleService;
use GDP\Modules\Planning\WeeklyReport;
use GDP\Modules\Planning\WorkloadService;

defined( 'ABSPATH' ) || exit;

/**
 * Alertas de plazo en vivo, informe semanal y sus exportaciones (LaTeX,
 * CSV, JSON, iCalendar).
 */
final class PlanningReportPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_export_report', array( self::class, 'handle_export' ) );
	}

	/**
	 * Alertas.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render_alerts( array $project ): void {
		$project_id = (int) $project['id'];
		$alerts     = ScheduleService::alerts( $project_id );
		$stored     = PlanningCron::stored_alerts( $project_id );
		$types      = array(
			'overdue'        => __( 'Vencida', 'gestion-de-proyectos' ),
			'due_soon'       => __( 'Vence pronto', 'gestion-de-proyectos' ),
			'not_started'    => __( 'No iniciada', 'gestion-de-proyectos' ),
			'negative_float' => __( 'Holgura negativa', 'gestion-de-proyectos' ),
			'conflict'       => __( 'Conflicto de fechas', 'gestion-de-proyectos' ),
			'deadline'       => __( 'Término contractual', 'gestion-de-proyectos' ),
			'overallocation' => __( 'Sobreasignación', 'gestion-de-proyectos' ),
		);

		PlanningPage::header( $project, 'alerts', __( 'Alertas de plazo', 'gestion-de-proyectos' ) );
		?>
		<p class="gdp-muted"><?php printf( esc_html__( 'Calculadas ahora sobre el cronograma vigente (hoy es %s). El recálculo diario guarda un resumen y, si las notificaciones están activas, avisa por correo a directores e ingenieros cuando hay alertas de severidad alta.', 'gestion-de-proyectos' ), esc_html( current_time( 'Y-m-d' ) ) ); ?>
			<?php if ( $stored ) : ?><br><?php printf( esc_html__( 'Último recálculo programado: %1$s (%2$d altas, %3$d medias).', 'gestion-de-proyectos' ), esc_html( self::date( $stored['computed_at'] ) ), (int) $stored['high'], (int) $stored['medium'] ); ?><?php endif; ?>
		</p>
		<?php if ( empty( $alerts ) ) : ?>
			<div class="gdp-card"><p class="gdp-text-ok"><?php esc_html_e( 'Sin alertas: el cronograma está al día.', 'gestion-de-proyectos' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Severidad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Detalle', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $alerts as $al ) : ?>
					<tr>
						<td><?php echo self::badge( 'high' === $al['severity'] ? 'fail' : 'warn', 'high' === $al['severity'] ? __( 'Alta', 'gestion-de-proyectos' ) : __( 'Media', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $types[ $al['type'] ] ?? $al['type'] ); ?></td>
						<td>
							<?php if ( $al['activity_id'] > 0 && Access::can( 'planning.edit', $project_id ) ) : ?>
								<a href="<?php echo esc_url( PlanningPage::url( $project_id, 'edit', array( 'id' => $al['activity_id'] ) ) ); ?>"><?php echo esc_html( trim( $al['code'] . ' ' . $al['name'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( trim( $al['code'] . ' ' . $al['name'] ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $al['end_date'] ); ?></td>
						<td><?php echo esc_html( $al['message'] ); ?></td>
						<td><?php echo esc_html( ScheduleService::user_name( (int) $al['owner_id'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Informe semanal.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render_report( array $project ): void {
		$project_id = (int) $project['id'];
		$week       = isset( $_GET['week'] ) ? ActivityRepository::normalize_date( sanitize_text_field( wp_unslash( (string) $_GET['week'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report     = WeeklyReport::build( $project_id, $week ? $week : null );
		$monday     = WeeklyReport::monday( $report['week']['from'] );
		$labels     = ActivityRepository::status_labels();

		PlanningPage::header( $project, 'report', __( 'Informe semanal', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . PlanningPage::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<input type="hidden" name="view" value="report">
				<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'report', array( 'week' => $monday->modify( '-7 days' )->format( 'Y-m-d' ) ) ) ); ?>">&larr;</a>
				<input type="date" name="week" value="<?php echo esc_attr( $report['week']['from'] ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Ver semana', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'report', array( 'week' => $monday->modify( '+7 days' )->format( 'Y-m-d' ) ) ) ); ?>">&rarr;</a>
			</form>
			<span class="gdp-actions">
				<?php foreach ( array( 'tex' => 'LaTeX', 'csv' => 'CSV', 'json' => 'JSON', 'ics' => 'iCalendar' ) as $format => $label ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_export_report&project_id=' . $project_id . '&week=' . $report['week']['from'] . '&format=' . $format ), 'gdp_export_report_' . $project_id ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</span>
		</div>

		<h2><?php printf( esc_html__( 'Semana %1$d de %2$d (%3$s al %4$s)', 'gestion-de-proyectos' ), (int) $report['week']['number'], (int) $report['week']['year'], esc_html( WeeklyReport::human_date( $report['week']['from'] ) ), esc_html( WeeklyReport::human_date( $report['week']['to'] ) ) ); ?></h2>

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
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Frente', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Act.', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Term.', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'En curso', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Vencidas', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Avance', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $report['fronts'] as $f ) : ?>
						<tr><td><?php echo esc_html( $f['label'] ); ?></td><td class="gdp-num"><?php echo (int) $f['activities']; ?></td><td class="gdp-num"><?php echo (int) $f['done']; ?></td><td class="gdp-num"><?php echo (int) $f['in_progress']; ?></td><td class="gdp-num <?php echo $f['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $f['overdue']; ?></td><td class="gdp-num"><span class="gdp-progress"><span class="gdp-progress__bar" style="width: <?php echo (int) $f['percent']; ?>%"></span></span> <?php echo (int) $f['percent']; ?> %</td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
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
					<p class="gdp-muted gdp-small"><?php echo $curve['baseline'] ? esc_html( sprintf( __( 'Plan según la línea base "%s"; real según el historial de avances, ponderado por duración.', 'gestion-de-proyectos' ), $curve['baseline'] ) ) : esc_html__( 'Sin línea base vigente: el plan se toma del cronograma actual. Fije una línea base para medir la desviación contra el compromiso.', 'gestion-de-proyectos' ); ?></p>
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
									<span class="gdp-muted"><?php echo esc_html( 'milestone' === $a['kind'] ? (string) $a['end_date'] : $a['start_date'] . ' → ' . $a['end_date'] ); ?> · <?php echo (int) $a['percent']; ?> %<?php echo isset( $a['days_late'] ) ? ' · ' . esc_html( sprintf( __( '%d días de atraso', 'gestion-de-proyectos' ), (int) $a['days_late'] ) ) : ''; ?><?php echo $a['owner'] ? ' · ' . esc_html( $a['owner'] ) : ''; ?></span>
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
					<p class="gdp-muted"><?php esc_html_e( 'El proyecto no tiene una línea base vigente.', 'gestion-de-proyectos' ); ?> <a href="<?php echo esc_url( PlanningPage::url( $project_id, 'baselines' ) ); ?>"><?php esc_html_e( 'Fijar una', 'gestion-de-proyectos' ); ?></a></p>
				<?php else : ?>
					<?php $v = $report['variance']; ?>
					<p><?php printf( esc_html__( 'Línea base "%1$s" (%2$s): %3$d atrasadas, %4$d adelantadas, %5$d nuevas, %6$d eliminadas.', 'gestion-de-proyectos' ), esc_html( $v['baseline_name'] ), esc_html( $v['baseline_date'] ), (int) $v['summary']['delayed'], (int) $v['summary']['advanced'], (int) $v['summary']['new'], (int) $v['summary']['removed'] ); ?></p>
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
		<p class="gdp-muted gdp-small"><?php esc_html_e( 'La exportación LaTeX genera un documento completo (babel español, tablas longtable con continuación, carta Gantt resumida con pgfgantt) listo para compilar con pdflatex.', 'gestion-de-proyectos' ); ?></p>
		<?php
		self::close();
	}

	/**
	 * Carga de trabajo por persona y semana.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render_workload( array $project ): void {
		$project_id = (int) $project['id'];
		$from       = isset( $_GET['from'] ) ? ActivityRepository::normalize_date( sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$weeks      = isset( $_GET['weeks'] ) ? max( 4, min( 78, (int) $_GET['weeks'] ) ) : 26; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data       = WorkloadService::compute( $project_id, $from ? $from : null, $weeks );
		$monday     = WeeklyReport::monday( $data['weeks'][0]['start'] );
		$today_week = WeeklyReport::monday( current_time( 'Y-m-d' ) )->format( 'Y-m-d' );

		PlanningPage::header( $project, 'workload', __( 'Carga de trabajo', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . PlanningPage::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<input type="hidden" name="view" value="workload">
				<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'workload', array( 'from' => $monday->modify( '-' . ( 7 * $weeks ) . ' days' )->format( 'Y-m-d' ), 'weeks' => $weeks ) ) ); ?>">&larr;</a>
				<input type="date" name="from" value="<?php echo esc_attr( $data['weeks'][0]['start'] ); ?>">
				<select name="weeks">
					<?php foreach ( array( 8, 13, 26, 52 ) as $n ) : ?>
						<option value="<?php echo (int) $n; ?>" <?php selected( $n, $weeks ); ?>><?php echo esc_html( sprintf( __( '%d semanas', 'gestion-de-proyectos' ), $n ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Ver', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'workload', array( 'from' => $monday->modify( '+' . ( 7 * $weeks ) . ' days' )->format( 'Y-m-d' ), 'weeks' => $weeks ) ) ); ?>">&rarr;</a>
			</form>
			<span class="gdp-muted gdp-small"><?php esc_html_e( 'Porcentaje de dedicación por semana: responsables al 100 % salvo asignación propia, participantes por su dedicación, prorrateado por los días hábiles de cada actividad en la semana. Más de 100 % es sobreasignación.', 'gestion-de-proyectos' ); ?></span>
		</div>
		<?php if ( empty( $data['people'] ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'No hay actividades abiertas con responsable o asignaciones en este periodo.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<div class="gdp-workload-wrap">
			<table class="widefat gdp-table gdp-workload">
				<thead>
					<tr>
						<th class="gdp-workload__person"><?php esc_html_e( 'Persona', 'gestion-de-proyectos' ); ?></th>
						<?php foreach ( $data['weeks'] as $w ) : ?>
							<th class="gdp-num <?php echo $w['start'] === $today_week ? 'gdp-workload__today' : ''; ?>" title="<?php echo esc_attr( $w['iso'] ); ?>"><?php echo esc_html( $w['label'] ); ?></th>
						<?php endforeach; ?>
						<th class="gdp-num"><?php esc_html_e( 'Máx.', 'gestion-de-proyectos' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $data['people'] as $p ) : ?>
					<tr>
						<td class="gdp-workload__person"><?php echo esc_html( $p['name'] ); ?><?php echo $p['overallocated'] > 0 ? ' <span class="gdp-badge gdp-badge--fail">' . esc_html( sprintf( __( '%d sem. >100 %%', 'gestion-de-proyectos' ), (int) $p['overallocated'] ) ) . '</span>' : ''; ?></td>
						<?php foreach ( $data['weeks'] as $i => $w ) : ?>
							<?php
							$cell  = $p['cells'][ $i ] ?? null;
							$pct   = $cell ? (int) $cell['percent'] : 0;
							$level = $pct > 100 ? 'over' : ( $pct >= 80 ? 'high' : ( $pct > 0 ? 'some' : 'none' ) );
							$title = $cell ? implode( "
", array_map( static fn( array $x ): string => sprintf( '%s %s (%d %%)', $x['code'], $x['name'], $x['percent'] ), $cell['activities'] ) ) : '';
							?>
							<td class="gdp-num gdp-workload__cell gdp-workload__cell--<?php echo esc_attr( $level ); ?>" title="<?php echo esc_attr( $title ); ?>"><?php echo $pct > 0 ? (int) $pct : ''; ?></td>
						<?php endforeach; ?>
						<td class="gdp-num"><strong><?php echo (int) $p['max']; ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Descarga del informe.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		check_admin_referer( 'gdp_export_report_' . $project_id );

		$project = ProjectRepository::find( $project_id );
		if ( ! $project || ! Access::can( 'planning.view', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'json';
		$week   = isset( $_GET['week'] ) ? ActivityRepository::normalize_date( sanitize_text_field( wp_unslash( (string) $_GET['week'] ) ) ) : null;
		$report = WeeklyReport::build( $project_id, $week ? $week : null );
		$base   = sanitize_file_name( sprintf( 'informe-semanal-%s-%s', $project['code'], $report['week']['iso'] ) );

		switch ( $format ) {
			case 'tex':
				$body = WeeklyReport::to_latex( $report );
				$type = 'application/x-tex; charset=UTF-8';
				$ext  = 'tex';
				break;
			case 'csv':
				$body = WeeklyReport::to_csv( $report );
				$type = 'text/csv; charset=UTF-8';
				$ext  = 'csv';
				$base = sanitize_file_name( sprintf( 'cronograma-%s-%s', $project['code'], $report['week']['from'] ) );
				break;
			case 'ics':
				$body = WeeklyReport::to_ics( $report );
				$type = 'text/calendar; charset=UTF-8';
				$ext  = 'ics';
				$base = sanitize_file_name( sprintf( 'cronograma-%s', $project['code'] ) );
				break;
			default:
				$body = (string) wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
				$type = 'application/json; charset=UTF-8';
				$ext  = 'json';
		}

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $base . '.' . $ext . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga de archivo generado.
		exit;
	}
}
