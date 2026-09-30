<?php
/**
 * Repositorio de asignaciones de personas a actividades.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Además del responsable (owner_id en la actividad), una actividad puede
 * tener participantes con un porcentaje de dedicación, base de la carga de
 * trabajo por persona.
 */
final class AssignmentRepository {

	public const ROLES = array( 'responsable', 'participante', 'revisor' );

	/**
	 * Etiquetas de los roles.
	 *
	 * @return array<string,string>
	 */
	public static function role_labels(): array {
		return array(
			'responsable'  => __( 'Responsable', 'gestion-de-proyectos' ),
			'participante' => __( 'Participante', 'gestion-de-proyectos' ),
			'revisor'      => __( 'Revisor', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Asignaciones de una actividad.
	 *
	 * @param int $activity_id Actividad.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_activity( int $activity_id ): array {
		global $wpdb;

		$table = Schema::table( 'assignments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE activity_id = %d ORDER BY id ASC", $activity_id ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Asignaciones de un proyecto, indexadas por actividad.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'assignments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY id ASC", $project_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = self::hydrate( $row );
			$out[ $row['activity_id'] ][] = $row;
		}

		return $out;
	}

	/**
	 * Actividades asignadas a una persona en un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $user_id    Usuario.
	 * @return int[] Identificadores de actividad.
	 */
	public static function activity_ids_for_user( int $project_id, int $user_id ): array {
		global $wpdb;

		$table = Schema::table( 'assignments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT activity_id FROM {$table} WHERE project_id = %d AND user_id = %d", $project_id, $user_id ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Crea o actualiza una asignación.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param int    $activity_id Actividad.
	 * @param int    $user_id     Usuario.
	 * @param string $role        Rol.
	 * @param int    $allocation  Dedicación (%).
	 * @return bool|WP_Error
	 */
	public static function set( int $project_id, int $activity_id, int $user_id, string $role = 'participante', int $allocation = 100 ) {
		global $wpdb;

		$role = sanitize_key( $role );
		if ( ! in_array( $role, self::ROLES, true ) ) {
			return new WP_Error( 'role', __( 'Rol de asignación no válido.', 'gestion-de-proyectos' ) );
		}
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'user', __( 'El usuario indicado no existe.', 'gestion-de-proyectos' ) );
		}
		$allocation = max( 1, min( 100, $allocation ) );

		$table = Schema::table( 'assignments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE activity_id = %d AND user_id = %d", $activity_id, $user_id ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'role' => $role, 'allocation' => $allocation ), array( 'id' => (int) $existing ), array( '%s', '%d' ), array( '%d' ) );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'project_id'  => $project_id,
				'activity_id' => $activity_id,
				'user_id'     => $user_id,
				'role'        => $role,
				'allocation'  => $allocation,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s' )
		);

		return false === $ok ? new WP_Error( 'db', __( 'No se pudo guardar la asignación.', 'gestion-de-proyectos' ) ) : true;
	}

	/**
	 * Elimina una asignación.
	 *
	 * @param int $activity_id Actividad.
	 * @param int $user_id     Usuario.
	 * @return void
	 */
	public static function remove( int $activity_id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'assignments' ), array( 'activity_id' => $activity_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
	}

	/**
	 * Elimina las asignaciones de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'assignments' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}

	/**
	 * Convierte una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'activity_id', 'user_id', 'allocation' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$user                = get_userdata( $row['user_id'] );
		$row['display_name'] = $user ? $user->display_name : sprintf( '#%d', $row['user_id'] );

		return $row;
	}
}
