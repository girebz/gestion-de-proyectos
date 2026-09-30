<?php
/**
 * Repositorio de dependencias entre actividades.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Schema;
use GDP\Planning\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Vínculos predecesora → sucesora con tipo (FS, SS, FF, SF) y retraso en
 * días hábiles (negativo = adelanto; columna lag_days, expuesta como "lag",
 * porque LAG es palabra reservada de MySQL). predecessor_type queda reservado para
 * dependencias con entidades de otros módulos (por ejemplo, la recepción de
 * una compra); hoy solo se usa "activity".
 */
final class DependencyRepository {

	/**
	 * Etiquetas de los tipos.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels(): array {
		return array(
			'FS' => __( 'Fin a inicio', 'gestion-de-proyectos' ),
			'SS' => __( 'Inicio a inicio', 'gestion-de-proyectos' ),
			'FF' => __( 'Fin a fin', 'gestion-de-proyectos' ),
			'SF' => __( 'Inicio a fin', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Dependencias de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'dependencies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY successor_id ASC, id ASC", $project_id ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Dependencias en las que participa una actividad (como predecesora o sucesora).
	 *
	 * @param int $activity_id Actividad.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_activity( int $activity_id ): array {
		global $wpdb;

		$table = Schema::table( 'dependencies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE (predecessor_type = 'activity' AND predecessor_id = %d) OR successor_id = %d ORDER BY id ASC", $activity_id, $activity_id ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Predecesoras de una actividad.
	 *
	 * @param int $activity_id Actividad.
	 * @return array<int,array<string,mixed>>
	 */
	public static function predecessors( int $activity_id ): array {
		return array_values( array_filter( self::for_activity( $activity_id ), static fn( array $d ): bool => $d['successor_id'] === $activity_id ) );
	}

	/**
	 * Sucesoras de una actividad.
	 *
	 * @param int $activity_id Actividad.
	 * @return array<int,array<string,mixed>>
	 */
	public static function successors( int $activity_id ): array {
		return array_values( array_filter( self::for_activity( $activity_id ), static fn( array $d ): bool => $d['predecessor_id'] === $activity_id && 'activity' === $d['predecessor_type'] ) );
	}

	/**
	 * Valida una lista de predecesoras para una sucesora.
	 *
	 * @param int                            $project_id   Proyecto.
	 * @param int                            $successor_id Sucesora.
	 * @param array<int,array<string,mixed>> $list         Lista: predecessor_id, type, lag.
	 * @return array<int,array{predecessor_id:int,type:string,lag:int}>|WP_Error
	 */
	public static function validate_list( int $project_id, int $successor_id, array $list ) {
		$successor = ActivityRepository::find( $successor_id );
		if ( ! $successor || $successor['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'La actividad sucesora no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}
		if ( 'summary' === $successor['kind'] ) {
			return new WP_Error( 'summary', __( 'Los resúmenes no admiten dependencias; vincule sus actividades.', 'gestion-de-proyectos' ) );
		}

		$clean = array();
		$seen  = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pred_id = (int) ( $item['predecessor_id'] ?? 0 );
			$type    = strtoupper( trim( (string) ( $item['type'] ?? 'FS' ) ) );
			$lag     = (int) ( $item['lag'] ?? 0 );

			if ( $pred_id <= 0 || $pred_id === $successor_id ) {
				return new WP_Error( 'self', __( 'Una actividad no puede depender de sí misma.', 'gestion-de-proyectos' ) );
			}
			$pred = ActivityRepository::find( $pred_id );
			if ( ! $pred || $pred['project_id'] !== $project_id ) {
				/* translators: identificador de la actividad. */
				return new WP_Error( 'not_found', sprintf( __( 'La predecesora %d no existe en este proyecto.', 'gestion-de-proyectos' ), $pred_id ) );
			}
			if ( 'summary' === $pred['kind'] ) {
				/* translators: código de la actividad. */
				return new WP_Error( 'summary', sprintf( __( 'La predecesora %s es un resumen; vincule una de sus actividades.', 'gestion-de-proyectos' ), $pred['code'] ) );
			}
			if ( ! in_array( $type, Scheduler::TYPES, true ) ) {
				/* translators: tipo recibido. */
				return new WP_Error( 'type', sprintf( __( 'Tipo de dependencia no válido: %s.', 'gestion-de-proyectos' ), $type ) );
			}
			if ( $lag < -365 || $lag > 365 ) {
				return new WP_Error( 'lag', __( 'El retraso o adelanto debe estar entre -365 y 365 días hábiles.', 'gestion-de-proyectos' ) );
			}
			if ( isset( $seen[ $pred_id ] ) ) {
				continue;
			}
			$seen[ $pred_id ] = true;
			$clean[]          = array(
				'predecessor_id' => $pred_id,
				'type'           => $type,
				'lag'            => $lag,
			);
		}

		// Comprobación de ciclos con el motor: se simula la lista propuesta.
		$activities   = ActivityRepository::for_project( $project_id );
		$dependencies = array_values( array_filter( self::for_project( $project_id ), static fn( array $d ): bool => $d['successor_id'] !== $successor_id ) );
		foreach ( $clean as $c ) {
			$dependencies[] = array(
				'predecessor_id' => $c['predecessor_id'],
				'successor_id'   => $successor_id,
				'type'           => $c['type'],
				'lag'            => $c['lag'],
			);
		}
		$calendar = CalendarRepository::build( $project_id );
		$result   = ( new Scheduler( $calendar, current_time( 'Y-m-d' ) ) )->schedule( $activities, $dependencies );
		if ( ! empty( $result['cycle'] ) ) {
			return new WP_Error( 'cycle', __( 'La dependencia crearía un ciclo: una actividad terminaría dependiendo de sí misma.', 'gestion-de-proyectos' ), array( 'cycle' => $result['cycle'] ) );
		}

		return $clean;
	}

	/**
	 * Sustituye las predecesoras de una actividad por la lista dada (ya validada).
	 *
	 * @param int                                              $project_id   Proyecto.
	 * @param int                                              $successor_id Sucesora.
	 * @param array<int,array{predecessor_id:int,type:string,lag:int}> $list Lista validada.
	 * @return void
	 */
	public static function replace_predecessors( int $project_id, int $successor_id, array $list ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'dependencies' ), array( 'project_id' => $project_id, 'successor_id' => $successor_id ), array( '%d', '%d' ) );

		foreach ( $list as $item ) {
			self::add( $project_id, (int) $item['predecessor_id'], $successor_id, (string) $item['type'], (int) $item['lag'] );
		}
	}

	/**
	 * Añade una dependencia (idempotente).
	 *
	 * @param int    $project_id       Proyecto.
	 * @param int    $predecessor_id   Predecesora.
	 * @param int    $successor_id     Sucesora.
	 * @param string $type             Tipo.
	 * @param int    $lag              Retraso.
	 * @param string $predecessor_type Tipo de entidad predecesora.
	 * @return int Identificador.
	 */
	public static function add( int $project_id, int $predecessor_id, int $successor_id, string $type = 'FS', int $lag = 0, string $predecessor_type = 'activity' ): int {
		global $wpdb;

		$table = Schema::table( 'dependencies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = %d AND predecessor_type = %s AND predecessor_id = %d AND successor_id = %d", $project_id, $predecessor_type, $predecessor_id, $successor_id ), ARRAY_A );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'type' => $type, 'lag_days' => $lag ), array( 'id' => (int) $existing['id'] ), array( '%s', '%d' ), array( '%d' ) );
			return (int) $existing['id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'project_id'       => $project_id,
				'predecessor_type' => $predecessor_type,
				'predecessor_id'   => $predecessor_id,
				'successor_id'     => $successor_id,
				'type'             => $type,
				'lag_days'         => $lag,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Elimina una dependencia.
	 *
	 * @param int $id Identificador.
	 * @return void
	 */
	public static function remove( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'dependencies' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Elimina todas las dependencias de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'dependencies' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}

	/**
	 * Notación compacta de las predecesoras de una actividad ("1.2FS+3, 1.3SS").
	 *
	 * @param int                            $activity_id Actividad.
	 * @param array<int,array<string,mixed>> $index       Actividades por id (para los códigos).
	 * @param array<int,array<string,mixed>> $deps        Dependencias del proyecto.
	 * @return string
	 */
	public static function notation( int $activity_id, array $index, array $deps ): string {
		$parts = array();
		foreach ( $deps as $d ) {
			if ( $d['successor_id'] !== $activity_id ) {
				continue;
			}
			$code  = $index[ $d['predecessor_id'] ]['code'] ?? (string) $d['predecessor_id'];
			$part  = $code . ( 'FS' === $d['type'] && 0 === $d['lag'] ? '' : $d['type'] );
			$part .= 0 === $d['lag'] ? '' : sprintf( '%+d', $d['lag'] );
			$parts[] = $part;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Convierte una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['project_id']     = (int) $row['project_id'];
		$row['predecessor_id'] = (int) $row['predecessor_id'];
		$row['successor_id']   = (int) $row['successor_id'];
		$row['lag']            = (int) ( $row['lag_days'] ?? 0 );
		$row['type']           = strtoupper( (string) $row['type'] );
		unset( $row['lag_days'] );

		return $row;
	}
}
