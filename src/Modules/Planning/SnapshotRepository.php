<?php
/**
 * Fotografías semanales del cronograma.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Una fotografía por proyecto y semana (lunes) con el estado de cada
 * actividad: frente, estado, criticidad, fechas y avance. Permite que el
 * informe semanal diga qué frentes se abrieron o cerraron y qué actividades
 * entraron o salieron de la ruta crítica respecto de la semana anterior.
 */
final class SnapshotRepository {

	/**
	 * Toma (o actualiza) la fotografía de una semana con el cronograma actual.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $week_start Lunes (Y-m-d).
	 * @return array<string,mixed> Fotografía guardada.
	 */
	public static function take( int $project_id, string $week_start ): array {
		global $wpdb;

		$result = ScheduleService::recalculate( $project_id );
		$data   = array();
		foreach ( $result['activities'] as $a ) {
			if ( 'summary' === $a['kind'] ) {
				continue;
			}
			$data[ $a['id'] ] = array(
				'code'     => $a['code'],
				'name'     => $a['name'],
				'front'    => $a['work_front'],
				'status'   => $a['status'],
				'critical' => $a['is_critical'],
				'start'    => $a['start_date'],
				'end'      => $a['end_date'],
				'percent'  => $a['percent'],
			);
		}
		$stats = ActivityRepository::stats( $project_id );
		$stats['finish_date']    = $result['project']['finish_date'] ?? null;
		$stats['deadline_slack'] = $result['project']['deadline_slack'] ?? null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			Schema::table( 'schedule_snapshots' ),
			array(
				'project_id' => $project_id,
				'week_start' => $week_start,
				'taken_at'   => current_time( 'mysql', true ),
				'stats'      => wp_json_encode( $stats, JSON_UNESCAPED_UNICODE ),
				'data'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return array( 'project_id' => $project_id, 'week_start' => $week_start, 'stats' => $stats, 'data' => $data );
	}

	/**
	 * Fotografía de una semana.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $week_start Lunes.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $project_id, string $week_start ): ?array {
		global $wpdb;

		$table = Schema::table( 'schedule_snapshots' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND week_start = %s", $project_id, $week_start ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Última fotografía anterior a una semana.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $week_start Lunes.
	 * @return array<string,mixed>|null
	 */
	public static function previous( int $project_id, string $week_start ): ?array {
		global $wpdb;

		$table = Schema::table( 'schedule_snapshots' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND week_start < %s ORDER BY week_start DESC LIMIT 1", $project_id, $week_start ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Elimina las fotografías de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'schedule_snapshots' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}

	/**
	 * Compara la fotografía actual con la anterior: frentes que se abren o cierran,
	 * actividades que entran o salen de la ruta crítica.
	 *
	 * @param array<string,mixed>      $current  Fotografía de la semana.
	 * @param array<string,mixed>|null $previous Fotografía anterior.
	 * @return array<string,mixed>
	 */
	public static function compare( array $current, ?array $previous ): array {
		$open   = static fn( array $a ): bool => in_array( $a['status'], array( 'en_curso', 'terminada' ), true );
		$closed = static fn( array $a ): bool => in_array( $a['status'], array( 'terminada', 'cancelada' ), true );

		$front_state = static function ( array $data ) use ( $open, $closed ): array {
			$fronts = array();
			foreach ( $data as $a ) {
				$slug = '' !== $a['front'] ? $a['front'] : 'sin_frente';
				if ( ! isset( $fronts[ $slug ] ) ) {
					$fronts[ $slug ] = array( 'started' => false, 'all_closed' => true, 'count' => 0 );
				}
				++$fronts[ $slug ]['count'];
				if ( $open( $a ) ) {
					$fronts[ $slug ]['started'] = true;
				}
				if ( ! $closed( $a ) ) {
					$fronts[ $slug ]['all_closed'] = false;
				}
			}
			return $fronts;
		};

		$now  = $front_state( $current['data'] );
		$then = $previous ? $front_state( $previous['data'] ) : array();

		$opened = array();
		$closed_fronts = array();
		foreach ( $now as $slug => $state ) {
			$before = $then[ $slug ] ?? null;
			if ( $state['started'] && ( ! $before || ! $before['started'] ) ) {
				$opened[] = $slug;
			}
			if ( $state['all_closed'] && $state['count'] > 0 && ( ! $before || ! $before['all_closed'] ) ) {
				$closed_fronts[] = $slug;
			}
		}

		$entered = array();
		$left    = array();
		foreach ( $current['data'] as $id => $a ) {
			$before = $previous['data'][ $id ] ?? null;
			if ( $a['critical'] && ( ! $before || ! $before['critical'] ) ) {
				$entered[] = array( 'id' => (int) $id, 'code' => $a['code'], 'name' => $a['name'] );
			}
		}
		foreach ( $previous['data'] ?? array() as $id => $b ) {
			$a = $current['data'][ $id ] ?? null;
			if ( $b['critical'] && ( ! $a || ! $a['critical'] ) ) {
				$left[] = array( 'id' => (int) $id, 'code' => $b['code'], 'name' => $b['name'] );
			}
		}

		$finish_before = $previous['stats']['finish_date'] ?? null;
		$finish_now    = $current['stats']['finish_date'] ?? null;

		return array(
			'has_previous'    => null !== $previous,
			'previous_week'   => $previous['week_start'] ?? null,
			'fronts_opened'   => $opened,
			'fronts_closed'   => $closed_fronts,
			'critical_in'     => $entered,
			'critical_out'    => $left,
			'finish_before'   => $finish_before,
			'finish_now'      => $finish_now,
			'percent_before'  => $previous['stats']['percent'] ?? null,
			'percent_now'     => $current['stats']['percent'] ?? null,
		);
	}

	/**
	 * Decodifica una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$data  = is_string( $row['data'] ) ? json_decode( $row['data'], true ) : null;
		$stats = is_string( $row['stats'] ) ? json_decode( $row['stats'], true ) : null;

		return array(
			'id'         => (int) $row['id'],
			'project_id' => (int) $row['project_id'],
			'week_start' => (string) $row['week_start'],
			'taken_at'   => (string) $row['taken_at'],
			'stats'      => is_array( $stats ) ? $stats : array(),
			'data'       => is_array( $data ) ? $data : array(),
		);
	}
}
