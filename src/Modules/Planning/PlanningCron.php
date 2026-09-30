<?php
/**
 * Tareas programadas del módulo de planificación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Admin\Admin;
use GDP\Core\Options;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Diariamente recalcula los cronogramas y guarda las alertas; semanalmente
 * envía por correo el resumen del informe a los directores e ingenieros de
 * cada proyecto en ejecución.
 */
final class PlanningCron {

	public const ALERTS_OPTION = 'gdp_planning_alerts';

	/**
	 * Proyectos con cronograma activo.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_projects(): array {
		return array_values( array_filter( ProjectRepository::all( null ), static fn( array $p ): bool => in_array( $p['status'], array( 'planificacion', 'ejecucion', 'cierre' ), true ) ) );
	}

	/**
	 * Recálculo diario y alertas.
	 *
	 * @return void
	 */
	public static function daily(): void {
		$all = array();
		foreach ( self::active_projects() as $p ) {
			$alerts = ScheduleService::alerts( (int) $p['id'] );
			$all[ (int) $p['id'] ] = array(
				'computed_at' => current_time( 'mysql', true ),
				'high'        => count( array_filter( $alerts, static fn( array $a ): bool => 'high' === $a['severity'] ) ),
				'medium'      => count( array_filter( $alerts, static fn( array $a ): bool => 'medium' === $a['severity'] ) ),
				'alerts'      => array_slice( $alerts, 0, 100 ),
			);

			if ( Options::get( 'notifications_enabled', false ) && $all[ (int) $p['id'] ]['high'] > 0 ) {
				self::notify_alerts( $p, $alerts );
			}
		}

		update_option( self::ALERTS_OPTION, $all, false );
	}

	/**
	 * Alertas guardadas de un proyecto (del último recálculo diario).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function stored_alerts( int $project_id ): ?array {
		$all = get_option( self::ALERTS_OPTION, array() );

		return is_array( $all ) && isset( $all[ $project_id ] ) ? $all[ $project_id ] : null;
	}

	/**
	 * Informe semanal por correo.
	 *
	 * @return void
	 */
	public static function weekly(): void {
		if ( ! Options::get( 'notifications_enabled', false ) ) {
			return;
		}

		foreach ( self::active_projects() as $p ) {
			$report = WeeklyReport::build( (int) $p['id'] );
			if ( ! $report ) {
				continue;
			}
			$recipients = self::recipients( (int) $p['id'] );
			if ( empty( $recipients ) ) {
				continue;
			}
			$subject = sprintf( '[%s] Informe semanal %s: avance %d%%', $p['code'], $report['week']['iso'], (int) $report['stats']['percent'] );
			$body    = self::report_html( $report );

			wp_mail( $recipients, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}

	/**
	 * Correo de alertas de plazo.
	 *
	 * @param array<string,mixed>            $p      Proyecto.
	 * @param array<int,array<string,mixed>> $alerts Alertas.
	 * @return void
	 */
	private static function notify_alerts( array $p, array $alerts ): void {
		$recipients = self::recipients( (int) $p['id'] );
		if ( empty( $recipients ) ) {
			return;
		}

		// Una vez al día como máximo.
		$key = 'gdp_alerts_sent_' . (int) $p['id'];
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS - HOUR_IN_SECONDS );

		$high  = array_filter( $alerts, static fn( array $a ): bool => 'high' === $a['severity'] );
		$lines = array();
		foreach ( $high as $a ) {
			$lines[] = sprintf( '<li><strong>%s</strong> %s: %s</li>', esc_html( $a['code'] ), esc_html( $a['name'] ), esc_html( $a['message'] ) );
		}
		$body = sprintf(
			'<p>%s</p><ul>%s</ul><p><a href="%s">%s</a></p>',
			/* translators: 1: nombre del proyecto, 2: número de alertas. */
			esc_html( sprintf( __( 'El proyecto %1$s tiene %2$d alerta(s) de plazo de severidad alta:', 'gestion-de-proyectos' ), $p['name'], count( $high ) ) ),
			implode( '', $lines ),
			esc_url( Admin::url( 'planning', array( 'project_id' => $p['id'], 'view' => 'alerts' ) ) ),
			esc_html__( 'Ver las alertas en el panel', 'gestion-de-proyectos' )
		);

		wp_mail( $recipients, sprintf( '[%s] %s', $p['code'], __( 'Alertas de plazo', 'gestion-de-proyectos' ) ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Destinatarios: directores e ingenieros del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return string[]
	 */
	private static function recipients( int $project_id ): array {
		$emails = array();
		foreach ( MemberRepository::for_project( $project_id ) as $m ) {
			if ( in_array( $m['role'], array( 'director', 'ingeniero' ), true ) && ! empty( $m['email'] ) ) {
				$emails[] = (string) $m['email'];
			}
		}

		/**
		 * Permite ajustar los destinatarios de los correos del módulo de planificación.
		 *
		 * @param string[] $emails     Correos.
		 * @param int      $project_id Proyecto.
		 */
		return array_values( array_unique( (array) apply_filters( 'gdp_planning_recipients', $emails, $project_id ) ) );
	}

	/**
	 * Resumen HTML del informe semanal para el correo.
	 *
	 * @param array<string,mixed> $r Informe.
	 * @return string
	 */
	public static function report_html( array $r ): string {
		$rows = '';
		foreach ( $r['fronts'] as $f ) {
			$rows .= sprintf( '<tr><td>%s</td><td align="right">%d</td><td align="right">%d</td><td align="right">%d</td><td align="right">%d%%</td></tr>', esc_html( $f['label'] ), (int) $f['activities'], (int) $f['done'], (int) $f['overdue'], (int) $f['percent'] );
		}
		$list = static function ( array $items, string $empty ): string {
			if ( empty( $items ) ) {
				return '<p><em>' . esc_html( $empty ) . '</em></p>';
			}
			$out = '<ul>';
			foreach ( $items as $a ) {
				$out .= sprintf( '<li>%s %s (%d%%)%s</li>', esc_html( $a['code'] ), esc_html( $a['name'] ), (int) $a['percent'], isset( $a['days_late'] ) ? esc_html( sprintf( ', %d días de atraso', (int) $a['days_late'] ) ) : '' );
			}
			return $out . '</ul>';
		};

		return sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><table border="1" cellpadding="4" cellspacing="0"><tr><th>Frente</th><th>Actividades</th><th>Terminadas</th><th>Vencidas</th><th>Avance</th></tr>%s</table><h3>Terminado esta semana</h3>%s<h3>Próxima semana</h3>%s<h3>Vencidas</h3>%s<p><a href="%s">%s</a></p>',
			esc_html( sprintf( 'Informe semanal %s: %s', $r['week']['iso'], $r['project']['name'] ) ),
			esc_html( sprintf( 'Semana del %s al %s. Avance global %d%%. Actividades: %d terminadas, %d en curso, %d vencidas.', WeeklyReport::human_date( $r['week']['from'] ), WeeklyReport::human_date( $r['week']['to'] ), (int) $r['stats']['percent'], (int) $r['stats']['by_status']['terminada'], (int) $r['stats']['by_status']['en_curso'], (int) $r['stats']['overdue'] ) ),
			esc_html( $r['schedule'] && null !== $r['schedule']['deadline_slack'] ? sprintf( 'Término programado: %s (holgura %d días hábiles respecto del término contractual).', WeeklyReport::human_date( $r['schedule']['finish_date'] ), (int) $r['schedule']['deadline_slack'] ) : '' ),
			$rows,
			$list( $r['completed'], 'Nada terminado esta semana.' ),
			$list( $r['upcoming'], 'Sin inicios programados.' ),
			$list( $r['overdue'], 'Sin actividades vencidas.' ),
			esc_url( Admin::url( 'planning', array( 'project_id' => $r['project']['id'], 'view' => 'report', 'week' => $r['week']['from'] ) ) ),
			esc_html__( 'Abrir el informe completo en el panel', 'gestion-de-proyectos' )
		);
	}
}
