<?php
/**
 * Servicio de programación: une datos, calendario y motor.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Domain\Projects\ProjectRepository;
use GDP\Planning\Scheduler;
use GDP\Planning\WorkCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Recalcula el cronograma de un proyecto y guarda las fechas en las
 * actividades. El cálculo es determinista y barato, de modo que se repite en
 * cada lectura y solo se escriben las filas cuyo resultado cambió.
 */
final class ScheduleService {

	/**
	 * Recalcula y devuelve el cronograma.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed> Resultado del motor con "activities" (en orden de árbol, con fechas).
	 */
	public static function recalculate( int $project_id ): array {
		$project    = ProjectRepository::find( $project_id );
		$activities = ActivityRepository::for_project( $project_id );
		$calendar   = CalendarRepository::build( $project_id );
		$start      = self::project_start( $project, $activities, $calendar );
		$end        = $project && ! empty( $project['end_date'] ) ? (string) $project['end_date'] : null;

		$scheduler = new Scheduler( $calendar, $start, $end );
		$result    = $scheduler->schedule( $activities, DependencyRepository::for_project( $project_id ) );

		if ( ! empty( $result['nodes'] ) ) {
			self::persist( $project_id, $activities, $result['nodes'] );
			// Se vuelven a leer para exponer las fechas guardadas.
			$activities = ActivityRepository::for_project( $project_id );
		}

		$result['project_id'] = $project_id;
		$result['activities'] = $activities;
		$result['calendar']   = array(
			'weekdays'   => $calendar->weekdays(),
			'exceptions' => count( $calendar->exceptions() ),
		);

		return $result;
	}

	/**
	 * Cronograma en forma resumida para el conector y los informes.
	 *
	 * @param int  $project_id     Proyecto.
	 * @param bool $with_relations Incluir dependencias y asignaciones.
	 * @return array<string,mixed>
	 */
	public static function summary( int $project_id, bool $with_relations = true ): array {
		$result   = self::recalculate( $project_id );
		$deps     = DependencyRepository::for_project( $project_id );
		$index    = array();
		foreach ( $result['activities'] as $a ) {
			$index[ $a['id'] ] = $a;
		}
		$assignments = $with_relations ? AssignmentRepository::for_project( $project_id ) : array();

		$rows = array();
		foreach ( $result['activities'] as $a ) {
			$row = array(
				'id'              => $a['id'],
				'code'            => $a['code'],
				'level'           => $a['level'],
				'parent_id'       => $a['parent_id'],
				'name'            => $a['name'],
				'kind'            => $a['kind'],
				'work_front'      => $a['work_front'],
				'status'          => $a['status'],
				'priority'        => $a['priority'],
				'duration'        => $a['duration'],
				'percent'         => $a['percent'],
				'start_date'      => $a['start_date'],
				'end_date'        => $a['end_date'],
				'late_start'      => $a['late_start'],
				'late_finish'     => $a['late_finish'],
				'total_float'     => $a['total_float'],
				'free_float'      => $a['free_float'],
				'is_critical'     => $a['is_critical'],
				'constraint_type' => $a['constraint_type'],
				'constraint_date' => $a['constraint_date'],
				'actual_start'    => $a['actual_start'],
				'actual_finish'   => $a['actual_finish'],
				'owner_id'        => $a['owner_id'],
				'owner'           => self::user_name( $a['owner_id'] ),
				'deliverable'     => $a['deliverable'],
				'conflicts'       => $a['schedule_conflicts'],
				'version'         => $a['version'],
			);
			if ( $with_relations ) {
				$row['predecessors'] = DependencyRepository::notation( $a['id'], $index, $deps );
				$row['assignees']    = array_map( static fn( array $s ): string => $s['display_name'], $assignments[ $a['id'] ] ?? array() );
			}
			$rows[] = $row;
		}

		return array(
			'project_id' => $project_id,
			'ok'         => $result['ok'],
			'errors'     => $result['errors'],
			'project'    => $result['project'],
			'calendar'   => $result['calendar'],
			'stats'      => ActivityRepository::stats( $project_id ),
			'activities' => $rows,
		);
	}

	/**
	 * Ruta crítica: actividades críticas en orden de inicio.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function critical_path( int $project_id ): array {
		$result = self::recalculate( $project_id );
		$path   = array();
		foreach ( $result['activities'] as $a ) {
			if ( 'summary' === $a['kind'] || ! $a['is_critical'] ) {
				continue;
			}
			$path[] = array(
				'id'          => $a['id'],
				'code'        => $a['code'],
				'name'        => $a['name'],
				'kind'        => $a['kind'],
				'status'      => $a['status'],
				'percent'     => $a['percent'],
				'duration'    => $a['duration'],
				'start_date'  => $a['start_date'],
				'end_date'    => $a['end_date'],
				'total_float' => $a['total_float'],
				'conflicts'   => $a['schedule_conflicts'],
			);
		}
		usort( $path, static fn( array $a, array $b ): int => array( $a['start_date'], $a['end_date'], $a['code'] ) <=> array( $b['start_date'], $b['end_date'], $b['code'] ) );

		return array(
			'project_id' => $project_id,
			'project'    => $result['project'],
			'length'     => count( $path ),
			'activities' => $path,
		);
	}

	/**
	 * Alertas de plazo del proyecto.
	 *
	 * @param int         $project_id Proyecto.
	 * @param string|null $today      Fecha de referencia (por defecto, hoy).
	 * @param int         $horizon    Días hábiles de anticipación para "vence pronto".
	 * @return array<int,array<string,mixed>>
	 */
	public static function alerts( int $project_id, ?string $today = null, int $horizon = 5 ): array {
		$today    = $today ?? current_time( 'Y-m-d' );
		$result   = self::recalculate( $project_id );
		$calendar = CalendarRepository::build( $project_id );
		$soon     = $calendar->add_working_days( $today, $horizon );
		$alerts   = array();

		foreach ( $result['activities'] as $a ) {
			if ( 'summary' === $a['kind'] || in_array( $a['status'], array( 'terminada', 'cancelada' ), true ) ) {
				continue;
			}
			$base = array(
				'activity_id' => $a['id'],
				'code'        => $a['code'],
				'name'        => $a['name'],
				'kind'        => $a['kind'],
				'end_date'    => $a['end_date'],
				'percent'     => $a['percent'],
				'owner_id'    => $a['owner_id'],
			);

			if ( $a['end_date'] && $a['end_date'] < $today ) {
				$alerts[] = $base + array(
					'type'     => 'overdue',
					'severity' => 'high',
					'days'     => abs( $calendar->working_days_between( $a['end_date'], $today ) ),
					'message'  => sprintf( 'Vencida: debía terminar el %s.', $a['end_date'] ),
				);
			} elseif ( $a['end_date'] && $a['end_date'] <= $soon ) {
				$alerts[] = $base + array(
					'type'     => 'due_soon',
					'severity' => 'medium',
					'days'     => $calendar->working_days_between( $today, $a['end_date'] ),
					'message'  => sprintf( 'Vence el %s.', $a['end_date'] ),
				);
			}

			if ( 'pendiente' === $a['status'] && $a['start_date'] && $a['start_date'] < $today && 'milestone' !== $a['kind'] ) {
				$alerts[] = $base + array(
					'type'     => 'not_started',
					'severity' => 'medium',
					'days'     => abs( $calendar->working_days_between( $a['start_date'], $today ) ),
					'message'  => sprintf( 'Debió empezar el %s y no registra avance.', $a['start_date'] ),
				);
			}

			if ( null !== $a['total_float'] && $a['total_float'] < 0 ) {
				$alerts[] = $base + array(
					'type'     => 'negative_float',
					'severity' => 'high',
					'days'     => $a['total_float'],
					'message'  => sprintf( 'Holgura negativa de %d día(s): no alcanza a cumplir su fecha límite.', -$a['total_float'] ),
				);
			}

			foreach ( $a['schedule_conflicts'] as $conflict ) {
				$alerts[] = $base + array(
					'type'     => 'conflict',
					'severity' => 'medium',
					'days'     => null,
					'message'  => $conflict,
				);
			}
		}

		$project = $result['project'];
		if ( $project && null !== $project['deadline_slack'] && $project['deadline_slack'] < 0 ) {
			$alerts[] = array(
				'activity_id' => 0,
				'code'        => '',
				'name'        => __( 'Proyecto', 'gestion-de-proyectos' ),
				'kind'        => 'project',
				'end_date'    => $project['finish_date'],
				'percent'     => null,
				'owner_id'    => 0,
				'type'        => 'deadline',
				'severity'    => 'high',
				'days'        => $project['deadline_slack'],
				'message'     => sprintf( 'El cronograma termina el %s, %d día(s) hábil(es) después del término contractual (%s).', $project['finish_date'], -$project['deadline_slack'], $project['deadline_date'] ),
			);
		}

		foreach ( WorkloadService::alerts( $project_id ) as $overallocation ) {
			$alerts[] = $overallocation;
		}

		usort(
			$alerts,
			static function ( array $a, array $b ): int {
				$rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				return array( $rank[ $a['severity'] ], (string) $a['end_date'] ) <=> array( $rank[ $b['severity'] ], (string) $b['end_date'] );
			}
		);

		return $alerts;
	}

	/**
	 * Nombre visible de un usuario (vacío si no existe).
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	public static function user_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );

		return $user ? (string) $user->display_name : sprintf( '#%d', $user_id );
	}

	/**
	 * Fecha de inicio del proyecto para el motor.
	 *
	 * @param array<string,mixed>|null       $project    Proyecto.
	 * @param array<int,array<string,mixed>> $activities Actividades.
	 * @param WorkCalendar                   $calendar   Calendario.
	 * @return string
	 */
	private static function project_start( ?array $project, array $activities, WorkCalendar $calendar ): string {
		if ( $project && ! empty( $project['start_date'] ) ) {
			return (string) $project['start_date'];
		}

		$earliest = null;
		foreach ( $activities as $a ) {
			foreach ( array( 'actual_start', 'actual_finish', 'constraint_date' ) as $field ) {
				if ( ! empty( $a[ $field ] ) && ( null === $earliest || $a[ $field ] < $earliest ) ) {
					$earliest = (string) $a[ $field ];
				}
			}
		}

		return $earliest ?? $calendar->next_working( current_time( 'Y-m-d' ) );
	}

	/**
	 * Guarda solo los nodos cuyo resultado cambió.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param array<int,array<string,mixed>> $activities Actividades actuales.
	 * @param array<int,array<string,mixed>> $nodes      Resultado del motor.
	 * @return void
	 */
	private static function persist( int $project_id, array $activities, array $nodes ): void {
		$index = array();
		foreach ( $activities as $a ) {
			$index[ $a['id'] ] = $a;
		}

		$changed = array();
		foreach ( $nodes as $id => $n ) {
			$a = $index[ $id ] ?? null;
			if ( ! $a ) {
				continue;
			}
			$same = $a['start_date'] === $n['start_date']
				&& $a['end_date'] === $n['end_date']
				&& $a['late_start'] === $n['late_start']
				&& $a['late_finish'] === $n['late_finish']
				&& $a['total_float'] === (int) $n['total_float']
				&& $a['free_float'] === (int) $n['free_float']
				&& $a['is_critical'] === (bool) $n['critical']
				&& $a['schedule_conflicts'] === array_values( $n['conflicts'] )
				&& ( ! $n['is_summary'] || ( $a['percent'] === (int) $n['percent'] && $a['duration'] === (int) $n['duration'] ) );
			if ( ! $same ) {
				$changed[ $id ] = $n;
			}
		}

		if ( ! empty( $changed ) ) {
			ActivityRepository::store_schedule( $project_id, $changed );
		}
	}
}
