<?php
/**
 * Datos de los tableros público y del equipo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Dashboards;

use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\DocumentRepository;
use GDP\Modules\Meetings\AgreementRepository;
use GDP\Modules\Meetings\MeetingRepository;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\ProgressCurve;
use GDP\Modules\Planning\ScheduleService;
use GDP\Modules\Procurement\PurchaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reúne, sin escribir en la base de datos, lo que cada tablero muestra. El
 * público parte de la configuración (solo lo declarado publicable); el del
 * equipo, de los datos de gestión, con los montos reservados a quien pueda
 * verlos.
 */
final class DashboardData {

	/**
	 * Datos del tablero público.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function public_data( int $project_id ): ?array {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return null;
		}
		$conf       = DashboardSettings::get( $project_id )['public'];
		$activities = ActivityRepository::for_project( $project_id );
		$today      = current_time( 'Y-m-d' );
		$curve      = self::curve( $project_id, $today );
		$progress   = null === $curve ? self::progress( $activities ) : (int) round( $curve['actual'] );
		$time       = self::time_elapsed( $project, $today );

		$stages = array();
		foreach ( $activities as $a ) {
			if ( 0 !== (int) $a['parent_id'] || 'summary' !== $a['kind'] ) {
				continue;
			}
			$c = $conf['stages'][ (string) $a['code'] ] ?? null;
			if ( is_array( $c ) && empty( $c['visible'] ) ) {
				continue;
			}
			$percent  = self::progress( self::descendants( $activities, (int) $a['id'] ) );
			$stages[] = array(
				'label'   => is_array( $c ) && '' !== (string) $c['label'] ? (string) $c['label'] : (string) $a['name'],
				'text'    => is_array( $c ) ? (string) $c['text'] : '',
				'percent' => $percent,
				'state'   => $percent >= 100 ? 'done' : ( $percent > 0 || ( $a['start_date'] && $a['start_date'] <= $today ) ? 'active' : 'upcoming' ),
			);
		}

		$achieved = array();
		$upcoming = array();
		foreach ( $activities as $a ) {
			$h = $conf['highlights'][ (string) $a['code'] ] ?? null;
			if ( null === $h || 'cancelada' === $a['status'] ) {
				continue;
			}
			$item = array( 'label' => '' !== (string) ( $h['label'] ?? '' ) ? (string) $h['label'] : (string) $a['name'] );
			if ( 'terminada' === $a['status'] ) {
				$item['date'] = $a['actual_finish'] ? $a['actual_finish'] : $a['end_date'];
				$achieved[]   = $item;
			} else {
				$item['date'] = $a['end_date'];
				$upcoming[]   = $item;
			}
		}
		usort( $achieved, static fn( array $x, array $y ): int => strcmp( (string) $y['date'], (string) $x['date'] ) );
		usort( $upcoming, static fn( array $x, array $y ): int => strcmp( (string) $x['date'], (string) $y['date'] ) );

		$indicators = array();
		foreach ( $conf['indicators'] as $ind ) {
			$value = self::indicator( (string) $ind['source'], (string) $ind['value'], $project, $activities, $progress, $time );
			if ( '' === $value ) {
				continue;
			}
			$indicators[] = array( 'label' => (string) $ind['label'], 'value' => $value );
		}

		return array(
			'project'    => array( 'id' => $project_id, 'code' => $project['code'], 'name' => $project['name'] ),
			'enabled'    => (bool) $conf['enabled'],
			'blocks'     => $conf['blocks'],
			'headline'   => '' !== $conf['headline'] ? $conf['headline'] : (string) $project['name'],
			'summary'    => $conf['summary'],
			'progress'   => $progress,
			'time'       => $time,
			'stages'     => $stages,
			'achieved'   => array_slice( $achieved, 0, 6 ),
			'upcoming'   => array_slice( $upcoming, 0, 4 ),
			'indicators' => $indicators,
			'partners'   => $conf['partners'],
			'funding'    => $conf['funding'],
			'cta'        => array( 'title' => $conf['cta_title'], 'text' => $conf['cta_text'], 'button' => $conf['cta_button'], 'email' => $conf['cta_email'], 'subject' => $conf['cta_subject'] ),
			'updated'    => $conf['show_updated'] ? current_time( 'Y-m-d' ) : null,
		);
	}

	/**
	 * Datos del tablero del equipo.
	 *
	 * @param int $project_id   Proyecto.
	 * @param bool $amounts     Incluir montos.
	 * @return array<string,mixed>|null
	 */
	public static function team_data( int $project_id, bool $amounts ): ?array {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return null;
		}
		$conf       = DashboardSettings::get( $project_id )['team'];
		$today      = current_time( 'Y-m-d' );
		$until      = gmdate( 'Y-m-d', (int) strtotime( $today . ' +' . (int) $conf['horizon_days'] . ' days' ) );
		$week_ago   = gmdate( 'Y-m-d', (int) strtotime( $today . ' -7 days' ) );
		$activities = ActivityRepository::for_project( $project_id );
		$curve      = self::curve( $project_id, $today );
		$progress   = null === $curve ? self::progress( $activities ) : (int) round( $curve['actual'] );
		$planned    = null === $curve ? null : round( $curve['planned'], 1 );

		$overdue  = array();
		$due_soon = array();
		$critical = array();
		$finished = array();
		foreach ( $activities as $a ) {
			if ( 'summary' === $a['kind'] ) {
				continue;
			}
			if ( 'terminada' === $a['status'] ) {
				if ( $a['actual_finish'] && $a['actual_finish'] >= $week_ago ) {
					$finished[] = self::activity_row( $a );
				}
				continue;
			}
			if ( 'cancelada' === $a['status'] ) {
				continue;
			}
			if ( $a['end_date'] && $a['end_date'] < $today ) {
				$overdue[] = self::activity_row( $a ) + array( 'days' => (int) round( ( strtotime( $today ) - strtotime( (string) $a['end_date'] ) ) / DAY_IN_SECONDS ) );
			} elseif ( $a['end_date'] && $a['end_date'] <= $until ) {
				$due_soon[] = self::activity_row( $a );
			}
			if ( $a['is_critical'] && ( ! $a['start_date'] || $a['start_date'] <= $until ) ) {
				$critical[] = self::activity_row( $a );
			}
		}
		usort( $overdue, static fn( array $x, array $y ): int => $y['days'] <=> $x['days'] );
		usort( $due_soon, static fn( array $x, array $y ): int => strcmp( (string) $x['end_date'], (string) $y['end_date'] ) );

		$agreements = array();
		foreach ( AgreementRepository::for_project( $project_id, array( 'open' => true, 'limit' => 200 ) ) as $ag ) {
			$agreements[] = array( 'code' => $ag['code'], 'description' => $ag['description'], 'owner' => $ag['owner_name'], 'due_date' => $ag['due_date'], 'overdue' => (bool) $ag['overdue'] );
		}
		$next_meeting = null;
		foreach ( array_reverse( MeetingRepository::for_project( $project_id, array( 'status' => 'programada', 'from' => $today, 'limit' => 50 ) ) ) as $m ) {
			$next_meeting = array( 'code' => $m['code'], 'title' => $m['title'], 'date' => $m['meeting_date'], 'time' => $m['start_time'], 'location' => $m['location'] );
			break;
		}
		$documents = array();
		foreach ( DocumentRepository::pending_responses( $project_id, (int) $conf['horizon_days'] ) as $d ) {
			$documents[] = array( 'number' => $d['number'], 'subject' => $d['subject'], 'due' => $d['response_due'], 'overdue' => $d['response_due'] < $today );
		}
		$stages    = PurchaseRepository::stages( $project_id );
		$purchases = array();
		foreach ( PurchaseRepository::for_project( $project_id, array( 'status' => 'abierta', 'limit' => 100 ) ) as $p ) {
			$row = array(
				'code'     => $p['code'],
				'title'    => $p['title'],
				'stage'    => $stages[ $p['stage'] ] ?? $p['stage'],
				'expected' => $p['expected_at'],
				'waiting'  => PurchaseRepository::awaiting_decision( $p ),
			);
			if ( $amounts ) {
				$row['amount_clp'] = $p['amount_clp'];
			}
			$purchases[] = $row;
		}
		$recent_meetings = array();
		foreach ( MeetingRepository::for_project( $project_id, array( 'status' => 'realizada', 'from' => $week_ago, 'to' => $today, 'limit' => 20 ) ) as $m ) {
			$recent_meetings[] = array( 'code' => $m['code'], 'title' => $m['title'], 'date' => $m['meeting_date'] );
		}

		$budget = null;
		if ( $amounts && class_exists( \GDP\Modules\Procurement\BudgetService::class ) ) {
			$summary = \GDP\Modules\Procurement\BudgetService::summary( $project_id );
			$budget  = array( 'assigned' => $summary['totals']['assigned'] ?? null, 'committed' => $summary['totals']['committed'] ?? null, 'executed' => $summary['totals']['executed'] ?? null );
		}

		return array(
			'project'      => array( 'id' => $project_id, 'code' => $project['code'], 'name' => $project['name'] ),
			'today'        => $today,
			'horizon'      => (int) $conf['horizon_days'],
			'progress'     => $progress,
			'planned'      => $planned,
			'deviation'    => null === $planned ? null : round( $progress - $planned, 1 ),
			'time'         => self::time_elapsed( $project, $today ),
			'overdue'      => $overdue,
			'due_soon'     => $due_soon,
			'critical'     => array_slice( $critical, 0, 8 ),
			'finished'     => $finished,
			'agreements'   => $agreements,
			'next_meeting' => $next_meeting,
			'documents'    => $documents,
			'purchases'    => $purchases,
			'recent'       => $recent_meetings,
			'amounts'      => $amounts,
			'budget'       => $budget,
		);
	}

	/**
	 * Avance real y planificado a la fecha según la curva S (misma ponderación
	 * que el informe semanal, para que las cifras coincidan en todo el plugin).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $today      Fecha.
	 * @return array{actual:float,planned:float}|null Null si no hay plan.
	 */
	private static function curve( int $project_id, string $today ): ?array {
		$curve = ProgressCurve::build( $project_id, $today );
		if ( empty( $curve['weight'] ) || empty( $curve['points'] ) ) {
			return null;
		}

		return array( 'actual' => (float) $curve['actual_today'], 'planned' => (float) $curve['planned_today'] );
	}

	/**
	 * Avance ponderado por duración de las actividades no resumen (en porcentaje).
	 *
	 * @param array<int,array<string,mixed>> $activities Actividades.
	 * @return int
	 */
	public static function progress( array $activities ): int {
		$weight = 0;
		$earned = 0.0;
		foreach ( $activities as $a ) {
			if ( 'summary' === $a['kind'] || 'cancelada' === $a['status'] ) {
				continue;
			}
			$w       = max( 1, (int) $a['duration'] );
			$weight += $w;
			$earned += $w * ( 'terminada' === $a['status'] ? 100 : (int) $a['percent'] );
		}

		return $weight > 0 ? (int) round( $earned / $weight ) : 0;
	}

	/**
	 * Plazo transcurrido del proyecto.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string|null         $today   Fecha de referencia (por defecto, hoy).
	 * @return array{percent:int|null,months:int|null,start:string|null,end:string|null}
	 */
	public static function time_elapsed( array $project, ?string $today = null ): array {
		$start = $project['start_date'] ?? null;
		$end   = $project['end_date'] ?? null;
		if ( ! $start ) {
			return array( 'percent' => null, 'months' => null, 'start' => null, 'end' => $end );
		}
		$now    = strtotime( $today ?? current_time( 'Y-m-d' ) );
		$s      = strtotime( (string) $start );
		$months = max( 0, (int) floor( ( $now - $s ) / ( 30.4375 * DAY_IN_SECONDS ) ) );
		$pct    = null;
		if ( $end && strtotime( (string) $end ) > $s ) {
			$pct = (int) max( 0, min( 100, round( 100 * ( $now - $s ) / ( strtotime( (string) $end ) - $s ) ) ) );
		}

		return array( 'percent' => $pct, 'months' => $months, 'start' => $start, 'end' => $end );
	}

	/**
	 * Descendientes de un resumen.
	 *
	 * @param array<int,array<string,mixed>> $activities Actividades.
	 * @param int                            $root       Resumen.
	 * @return array<int,array<string,mixed>>
	 */
	private static function descendants( array $activities, int $root ): array {
		$children = array();
		foreach ( $activities as $a ) {
			$children[ (int) $a['parent_id'] ][] = $a;
		}
		$out   = array();
		$stack = array( $root );
		while ( $stack ) {
			$id = array_pop( $stack );
			foreach ( $children[ $id ] ?? array() as $c ) {
				$out[]   = $c;
				$stack[] = (int) $c['id'];
			}
		}

		return $out;
	}

	/**
	 * Valor de un indicador.
	 *
	 * @param string                         $source     Fuente.
	 * @param string                         $manual     Valor manual.
	 * @param array<string,mixed>            $project    Proyecto.
	 * @param array<int,array<string,mixed>> $activities Actividades.
	 * @param int                            $progress   Avance.
	 * @param array<string,mixed>            $time       Plazo.
	 * @return string
	 */
	private static function indicator( string $source, string $manual, array $project, array $activities, int $progress, array $time ): string {
		$leaves = array_filter( $activities, static fn( array $a ): bool => 'summary' !== $a['kind'] );
		switch ( $source ) {
			case 'avance':
				return $progress . ' %';
			case 'plazo':
				return null === $time['percent'] ? '' : $time['percent'] . ' %';
			case 'meses':
				return null === $time['months'] ? '' : (string) $time['months'];
			case 'hitos_cumplidos':
				return (string) count( array_filter( $leaves, static fn( array $a ): bool => 'milestone' === $a['kind'] && 'terminada' === $a['status'] ) );
			case 'actividades_terminadas':
				return (string) count( array_filter( $leaves, static fn( array $a ): bool => 'terminada' === $a['status'] ) );
			case 'reuniones':
				return (string) count( MeetingRepository::for_project( (int) $project['id'], array( 'status' => 'realizada', 'limit' => 1000 ) ) );
			case 'documentos':
				return (string) count( array_filter( DocumentRepository::for_project( (int) $project['id'], array( 'limit' => 1000 ) ), static fn( array $d ): bool => 'out' === $d['direction'] && ! in_array( $d['status'], array( 'borrador', 'anulado' ), true ) ) );
			case 'acuerdos_cumplidos':
				return (string) AgreementRepository::stats( (int) $project['id'] )['fulfilled'];
		}

		return trim( $manual );
	}

	/**
	 * Fila de actividad para el tablero del equipo.
	 *
	 * @param array<string,mixed> $a Actividad.
	 * @return array<string,mixed>
	 */
	private static function activity_row( array $a ): array {
		return array(
			'code'     => $a['code'],
			'name'     => $a['name'],
			'owner'    => ScheduleService::user_name( (int) $a['owner_id'] ),
			'start'    => $a['start_date'],
			'end_date' => $a['end_date'],
			'percent'  => (int) $a['percent'],
			'critical' => (bool) $a['is_critical'],
			'status'   => $a['status'],
		);
	}

	/**
	 * Indica si el usuario puede ver el tablero del equipo de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $user_id    Usuario.
	 * @return bool
	 */
	public static function team_allowed( int $project_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( Access::is_manager( $user_id ) ) {
			return true;
		}
		$conf = DashboardSettings::get( $project_id )['team'];
		if ( ! $conf['require_member'] ) {
			return true;
		}

		return Access::can( 'project.view', $project_id, $user_id );
	}
}
