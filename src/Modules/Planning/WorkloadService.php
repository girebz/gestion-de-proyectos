<?php
/**
 * Carga de trabajo por persona y semana.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Para cada persona y semana suma la dedicación de las actividades abiertas
 * que la ocupan, prorrateada por los días hábiles de la actividad que caen en
 * la semana. El responsable cuenta al 100 % salvo que tenga una asignación
 * propia con otra dedicación; los participantes cuentan por su dedicación.
 * Más de 100 % en una semana es sobreasignación.
 */
final class WorkloadService {

	public const OVERALLOCATION = 100;

	/**
	 * Matriz de carga.
	 *
	 * @param int         $project_id Proyecto.
	 * @param string|null $from       Lunes inicial (por defecto, cuatro semanas antes de hoy).
	 * @param int         $weeks      Número de semanas.
	 * @return array{weeks:array<int,array{start:string,end:string,label:string,working_days:int}>,people:array<int,array{user_id:int,name:string,cells:array<int,array{percent:int,activities:array<int,array{code:string,name:string,percent:int}>}>,max:int,overallocated:int}>}
	 */
	public static function compute( int $project_id, ?string $from = null, int $weeks = 26 ): array {
		$calendar   = CalendarRepository::build( $project_id );
		$result     = ScheduleService::recalculate( $project_id );
		$activities = array_values( array_filter( $result['activities'], static fn( array $a ): bool => 'summary' !== $a['kind'] && ! in_array( $a['status'], array( 'terminada', 'cancelada' ), true ) && $a['start_date'] && $a['end_date'] ) );
		$assign     = AssignmentRepository::for_project( $project_id );

		$monday = WeeklyReport::monday( $from ?? ( new DateTimeImmutable( current_time( 'Y-m-d' ) ) )->modify( '-28 days' )->format( 'Y-m-d' ) );
		$weeks  = max( 1, min( 78, $weeks ) );
		$cols   = array();
		for ( $i = 0; $i < $weeks; $i++ ) {
			$start  = $monday->modify( '+' . ( 7 * $i ) . ' days' );
			$end    = $start->modify( '+6 days' );
			$cols[] = array(
				'start'        => $start->format( 'Y-m-d' ),
				'end'          => $end->format( 'Y-m-d' ),
				'label'        => $start->format( 'd-m' ),
				'iso'          => $start->format( 'o-\WW' ),
				'working_days' => $calendar->count_working_days( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
			);
		}

		$people = array();
		foreach ( $activities as $a ) {
			// Personas y dedicación de la actividad.
			$loads = array();
			foreach ( $assign[ $a['id'] ] ?? array() as $s ) {
				$loads[ $s['user_id'] ] = array( 'name' => $s['display_name'], 'allocation' => $s['allocation'] );
			}
			if ( $a['owner_id'] > 0 && ! isset( $loads[ $a['owner_id'] ] ) ) {
				$loads[ $a['owner_id'] ] = array( 'name' => ScheduleService::user_name( $a['owner_id'] ), 'allocation' => 100 );
			}
			if ( empty( $loads ) ) {
				continue;
			}
			$duration = max( 1, $calendar->count_working_days( $a['start_date'], $a['end_date'] ) );

			foreach ( $cols as $w => $col ) {
				if ( $a['start_date'] > $col['end'] || $a['end_date'] < $col['start'] || 0 === $col['working_days'] ) {
					continue;
				}
				$overlap = $calendar->count_working_days( max( $a['start_date'], $col['start'] ), min( $a['end_date'], $col['end'] ) );
				if ( 0 === $overlap ) {
					continue;
				}
				$share = $overlap / $col['working_days'];
				foreach ( $loads as $user_id => $load ) {
					if ( ! isset( $people[ $user_id ] ) ) {
						$people[ $user_id ] = array(
							'user_id'       => $user_id,
							'name'          => $load['name'],
							'cells'         => array(),
							'max'           => 0,
							'overallocated' => 0,
						);
					}
					$pct = (int) round( $load['allocation'] * $share );
					if ( ! isset( $people[ $user_id ]['cells'][ $w ] ) ) {
						$people[ $user_id ]['cells'][ $w ] = array( 'percent' => 0, 'activities' => array() );
					}
					$people[ $user_id ]['cells'][ $w ]['percent']     += $pct;
					$people[ $user_id ]['cells'][ $w ]['activities'][] = array( 'code' => $a['code'], 'name' => $a['name'], 'percent' => $pct );
				}
			}
		}

		foreach ( $people as &$p ) {
			foreach ( $p['cells'] as $cell ) {
				$p['max'] = max( $p['max'], $cell['percent'] );
				if ( $cell['percent'] > self::OVERALLOCATION ) {
					++$p['overallocated'];
				}
			}
		}
		unset( $p );
		usort( $people, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );

		return array( 'weeks' => $cols, 'people' => array_values( $people ) );
	}

	/**
	 * Sobreasignaciones próximas, como alertas.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $weeks      Horizonte en semanas desde la actual.
	 * @return array<int,array<string,mixed>>
	 */
	public static function alerts( int $project_id, int $weeks = 8 ): array {
		$data   = self::compute( $project_id, current_time( 'Y-m-d' ), $weeks );
		$alerts = array();
		foreach ( $data['people'] as $p ) {
			foreach ( $p['cells'] as $w => $cell ) {
				if ( $cell['percent'] <= self::OVERALLOCATION ) {
					continue;
				}
				$alerts[] = array(
					'activity_id' => 0,
					'code'        => '',
					'name'        => $p['name'],
					'kind'        => 'person',
					'end_date'    => $data['weeks'][ $w ]['start'],
					'percent'     => $cell['percent'],
					'owner_id'    => $p['user_id'],
					'type'        => 'overallocation',
					'severity'    => 'medium',
					'days'        => null,
					'message'     => sprintf( 'Sobreasignación: %d %% en la semana del %s (%s).', $cell['percent'], $data['weeks'][ $w ]['start'], implode( ', ', array_map( static fn( array $x ): string => $x['code'], $cell['activities'] ) ) ),
				);
			}
		}

		return $alerts;
	}
}
