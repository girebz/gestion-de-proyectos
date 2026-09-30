<?php
/**
 * Repositorio de avances reportados.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Cada cambio de avance, estado o fechas reales deja un registro fechado,
 * base del informe semanal ("qué se movió esta semana") y de la curva de
 * avance.
 */
final class ProgressRepository {

	/**
	 * Registra un avance.
	 *
	 * @param int         $project_id       Proyecto.
	 * @param int         $activity_id      Actividad.
	 * @param int         $percent          Avance nuevo.
	 * @param int         $previous_percent Avance anterior.
	 * @param string      $status           Estado nuevo.
	 * @param string|null $actual_start     Inicio real.
	 * @param string|null $actual_finish    Término real.
	 * @param string      $note             Nota.
	 * @param string      $source           Origen (admin, connector, import).
	 * @return int
	 */
	public static function record( int $project_id, int $activity_id, int $percent, int $previous_percent, string $status, ?string $actual_start, ?string $actual_finish, string $note = '', string $source = 'admin' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( 'progress' ),
			array(
				'project_id'       => $project_id,
				'activity_id'      => $activity_id,
				'user_id'          => get_current_user_id(),
				'reported_at'      => current_time( 'mysql', true ),
				'percent'          => max( 0, min( 100, $percent ) ),
				'previous_percent' => max( 0, min( 100, $previous_percent ) ),
				'status'           => sanitize_key( $status ),
				'actual_start'     => $actual_start,
				'actual_finish'    => $actual_finish,
				'note'             => sanitize_textarea_field( $note ),
				'source'           => sanitize_key( $source ),
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Avances de un proyecto en un intervalo (UTC).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $from       Desde (Y-m-d H:i:s).
	 * @param string $to         Hasta (Y-m-d H:i:s).
	 * @return array<int,array<string,mixed>>
	 */
	public static function between( int $project_id, string $from, string $to ): array {
		global $wpdb;

		$table = Schema::table( 'progress' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND reported_at >= %s AND reported_at < %s ORDER BY reported_at ASC", $project_id, $from, $to ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Historial de una actividad (el más reciente primero).
	 *
	 * @param int $activity_id Actividad.
	 * @param int $limit       Máximo.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_activity( int $activity_id, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'progress' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE activity_id = %d ORDER BY id DESC LIMIT %d", $activity_id, max( 1, $limit ) ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Elimina los avances de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'progress' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}

	/**
	 * Convierte una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'activity_id', 'user_id', 'percent', 'previous_percent' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$user                = get_userdata( $row['user_id'] );
		$row['display_name'] = $user ? $user->display_name : '';
		foreach ( array( 'actual_start', 'actual_finish' ) as $date ) {
			if ( '' === $row[ $date ] || '0000-00-00' === $row[ $date ] ) {
				$row[ $date ] = null;
			}
		}

		return $row;
	}
}
