<?php
/**
 * Repositorio de líneas base.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Audit;
use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Una línea base es una fotografía del cronograma (fechas, duraciones,
 * avance y costo planificado de cada actividad) contra la que se mide la
 * desviación. La línea base "vigente" es la que se dibuja en la carta Gantt
 * y se usa en el informe semanal.
 */
final class BaselineRepository {

	/**
	 * Una línea base.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'baselines' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Líneas base de un proyecto (la más reciente primero).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'baselines' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY id DESC", $project_id ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Línea base vigente.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function current( int $project_id ): ?array {
		foreach ( self::for_project( $project_id ) as $b ) {
			if ( $b['is_current'] ) {
				return $b;
			}
		}

		return null;
	}

	/**
	 * Crea una línea base a partir del cronograma actual.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $name        Nombre.
	 * @param string $description Descripción.
	 * @param bool   $make_current Marcar como vigente.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, string $name, string $description = '', bool $make_current = true ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			$name = sprintf( __( 'Línea base %s', 'gestion-de-proyectos' ), current_time( 'Y-m-d' ) );
		}

		// La fotografía se toma sobre el cronograma recién recalculado.
		$activities = ScheduleService::recalculate( $project_id )['activities'];
		if ( empty( $activities ) ) {
			return new WP_Error( 'empty', __( 'No hay actividades que guardar en la línea base.', 'gestion-de-proyectos' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Schema::table( 'baselines' ),
			array(
				'project_id'  => $project_id,
				'name'        => $name,
				'description' => sanitize_textarea_field( $description ),
				'is_current'  => 0,
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo crear la línea base.', 'gestion-de-proyectos' ) );
		}
		$id = (int) $wpdb->insert_id;

		foreach ( $activities as $a ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				Schema::table( 'baseline_activities' ),
				array(
					'baseline_id'  => $id,
					'activity_id'  => $a['id'],
					'code'         => $a['code'],
					'name'         => $a['name'],
					'kind'         => $a['kind'],
					'start_date'   => $a['start_date'],
					'end_date'     => $a['end_date'],
					'duration'     => $a['duration'],
					'percent'      => $a['percent'],
					'cost_planned' => $a['cost_planned'],
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', null === $a['cost_planned'] ? '%s' : '%f' )
			);
		}

		if ( $make_current ) {
			self::set_current( $id );
		}

		Audit::log( 'baseline', $id, 'create', $project_id, sprintf( 'Línea base creada: %s (%d actividades)', $name, count( $activities ) ) );

		return $id;
	}

	/**
	 * Marca una línea base como vigente.
	 *
	 * @param int $id Línea base.
	 * @return void
	 */
	public static function set_current( int $id ): void {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return;
		}
		$table = Schema::table( 'baselines' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = CASE WHEN id = %d THEN 1 ELSE 0 END WHERE project_id = %d", $id, $current['project_id'] ) );
	}

	/**
	 * Elimina una línea base.
	 *
	 * @param int $id Línea base.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'baseline_activities' ), array( 'baseline_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'baselines' ), array( 'id' => $id ), array( '%d' ) );

		Audit::log( 'baseline', $id, 'delete', $current['project_id'], sprintf( 'Línea base eliminada: %s', $current['name'] ), $current, null );

		return true;
	}

	/**
	 * Instantánea completa de una línea base (para eliminar de forma reversible).
	 *
	 * @param int $id Línea base.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $id ): ?array {
		$baseline = self::find( $id );
		if ( ! $baseline ) {
			return null;
		}

		return array(
			'baseline'   => $baseline,
			'activities' => array_values( self::activities( $id ) ),
		);
	}

	/**
	 * Reinserta una línea base eliminada a partir de su instantánea (mismos identificadores).
	 *
	 * @param array<string,mixed> $snapshot Instantánea de snapshot().
	 * @return bool
	 */
	public static function restore( array $snapshot ): bool {
		global $wpdb;

		$b = $snapshot['baseline'] ?? null;
		if ( ! is_array( $b ) || empty( $b['id'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			Schema::table( 'baselines' ),
			array(
				'id'          => (int) $b['id'],
				'project_id'  => (int) $b['project_id'],
				'name'        => (string) $b['name'],
				'description' => (string) ( $b['description'] ?? '' ),
				'is_current'  => ! empty( $b['is_current'] ) ? 1 : 0,
				'created_by'  => (int) $b['created_by'],
				'created_at'  => (string) $b['created_at'],
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		foreach ( $snapshot['activities'] ?? array() as $a ) {
			$row = array_intersect_key( $a, array_flip( array( 'id', 'baseline_id', 'activity_id', 'code', 'name', 'kind', 'start_date', 'end_date', 'duration', 'percent', 'cost_planned' ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace( Schema::table( 'baseline_activities' ), $row, array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', null === ( $row['cost_planned'] ?? null ) ? '%s' : '%f' ) );
		}
		if ( ! empty( $b['is_current'] ) ) {
			self::set_current( (int) $b['id'] );
		}

		return true;
	}

	/**
	 * Actividades de una línea base, indexadas por actividad.
	 *
	 * @param int $baseline_id Línea base.
	 * @return array<int,array<string,mixed>>
	 */
	public static function activities( int $baseline_id ): array {
		global $wpdb;

		$table = Schema::table( 'baseline_activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE baseline_id = %d", $baseline_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['activity_id']  = (int) $row['activity_id'];
			$row['duration']     = (int) $row['duration'];
			$row['percent']      = (int) $row['percent'];
			$row['cost_planned'] = null === $row['cost_planned'] ? null : (float) $row['cost_planned'];
			foreach ( array( 'start_date', 'end_date' ) as $date ) {
				if ( '' === $row[ $date ] || '0000-00-00' === $row[ $date ] ) {
					$row[ $date ] = null;
				}
			}
			$out[ $row['activity_id'] ] = $row;
		}

		return $out;
	}

	/**
	 * Desviación del cronograma actual respecto de una línea base.
	 *
	 * @param int $project_id  Proyecto.
	 * @param int $baseline_id Línea base.
	 * @return array<string,mixed>
	 */
	public static function compare( int $project_id, int $baseline_id ): array {
		$baseline = self::activities( $baseline_id );
		$current  = ScheduleService::recalculate( $project_id )['activities'];
		$calendar = CalendarRepository::build( $project_id );
		$rows     = array();
		$delayed  = 0;
		$advanced = 0;

		foreach ( $current as $a ) {
			$b = $baseline[ $a['id'] ] ?? null;
			if ( ! $b ) {
				$rows[] = array(
					'activity_id' => $a['id'],
					'code'        => $a['code'],
					'name'        => $a['name'],
					'kind'        => $a['kind'],
					'status'      => 'nueva',
					'baseline'    => null,
					'current'     => array( 'start_date' => $a['start_date'], 'end_date' => $a['end_date'], 'duration' => $a['duration'], 'percent' => $a['percent'] ),
					'variance'    => null,
				);
				continue;
			}
			$variance = null;
			if ( $b['end_date'] && $a['end_date'] ) {
				$variance = $calendar->working_days_between( $b['end_date'], $a['end_date'] );
				if ( $variance > 0 ) {
					++$delayed;
				} elseif ( $variance < 0 ) {
					++$advanced;
				}
			}
			$rows[] = array(
				'activity_id' => $a['id'],
				'code'        => $a['code'],
				'name'        => $a['name'],
				'kind'        => $a['kind'],
				'status'      => null === $variance ? 'sin_fechas' : ( $variance > 0 ? 'atrasada' : ( $variance < 0 ? 'adelantada' : 'en_plazo' ) ),
				'baseline'    => array( 'start_date' => $b['start_date'], 'end_date' => $b['end_date'], 'duration' => $b['duration'], 'percent' => $b['percent'] ),
				'current'     => array( 'start_date' => $a['start_date'], 'end_date' => $a['end_date'], 'duration' => $a['duration'], 'percent' => $a['percent'] ),
				'variance'    => $variance,
			);
		}

		$removed = array();
		$ids     = array_column( $current, 'id' );
		foreach ( $baseline as $aid => $b ) {
			if ( ! in_array( $aid, $ids, true ) ) {
				$removed[] = array( 'activity_id' => $aid, 'code' => $b['code'], 'name' => $b['name'] );
			}
		}

		return array(
			'baseline_id' => $baseline_id,
			'rows'        => $rows,
			'removed'     => $removed,
			'summary'     => array(
				'delayed'  => $delayed,
				'advanced' => $advanced,
				'new'      => count( array_filter( $rows, static fn( array $r ): bool => 'nueva' === $r['status'] ) ),
				'removed'  => count( $removed ),
			),
		);
	}

	/**
	 * Elimina las líneas base de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		foreach ( self::for_project( $project_id ) as $b ) {
			self::delete( $b['id'] );
		}
	}

	/**
	 * Convierte una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['project_id'] = (int) $row['project_id'];
		$row['created_by'] = (int) $row['created_by'];
		$row['is_current'] = ! empty( $row['is_current'] );

		return $row;
	}
}
