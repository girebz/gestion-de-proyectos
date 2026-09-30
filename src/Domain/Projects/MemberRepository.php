<?php
/**
 * Repositorio de miembros de proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Domain\Projects;

use GDP\Core\Audit;
use GDP\Core\Roles;
use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Pertenencia de usuarios de WordPress a proyectos, con un perfil por proyecto.
 */
final class MemberRepository {

	/**
	 * Perfil de un usuario en un proyecto (null si no es miembro activo).
	 *
	 * @param int $project_id Proyecto.
	 * @param int $user_id    Usuario.
	 * @return string|null
	 */
	public static function role_for_user( int $project_id, int $user_id ): ?string {
		global $wpdb;

		$table = Schema::table( 'project_members' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$role = $wpdb->get_var( $wpdb->prepare( "SELECT role FROM {$table} WHERE project_id = %d AND user_id = %d AND active = 1", $project_id, $user_id ) );

		return is_string( $role ) && '' !== $role ? $role : null;
	}

	/**
	 * Número de proyectos en que participa el usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function count_for_user( int $user_id ): int {
		global $wpdb;

		$table = Schema::table( 'project_members' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND active = 1", $user_id ) );
	}

	/**
	 * Identificadores de proyectos del usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return int[]
	 */
	public static function project_ids_for_user( int $user_id ): array {
		global $wpdb;

		$table = Schema::table( 'project_members' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT project_id FROM {$table} WHERE user_id = %d AND active = 1", $user_id ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Miembros de un proyecto con sus datos de usuario.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'project_members' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND active = 1 ORDER BY id ASC", $project_id ), ARRAY_A );

		$members = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user      = get_userdata( (int) $row['user_id'] );
			$members[] = array(
				'user_id'      => (int) $row['user_id'],
				'role'         => (string) $row['role'],
				'role_label'   => Roles::project_roles()[ $row['role'] ] ?? $row['role'],
				'display_name' => $user ? $user->display_name : '',
				'email'        => $user ? $user->user_email : '',
				'since'        => (string) $row['created_at'],
			);
		}

		return $members;
	}

	/**
	 * Asigna (o cambia) el perfil de un usuario en un proyecto.
	 *
	 * @param int    $project_id Proyecto.
	 * @param int    $user_id    Usuario.
	 * @param string $role       Perfil.
	 * @return bool|WP_Error
	 */
	public static function set_role( int $project_id, int $user_id, string $role ) {
		global $wpdb;

		$role = sanitize_key( $role );
		if ( ! isset( Roles::project_roles()[ $role ] ) ) {
			return new WP_Error( 'invalid_role', __( 'Perfil de proyecto no válido.', 'gestion-de-proyectos' ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'invalid_user', __( 'El usuario no existe.', 'gestion-de-proyectos' ) );
		}

		$table    = Schema::table( 'project_members' );
		$previous = self::role_for_user( $project_id, $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = %d AND user_id = %d", $project_id, $user_id ) );

		if ( $existing_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'role' => $role, 'active' => 1 ), array( 'id' => $existing_id ), array( '%s', '%d' ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'project_id' => $project_id,
					'user_id'    => $user_id,
					'role'       => $role,
					'active'     => 1,
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%d', '%s' )
			);
		}

		Audit::log( 'project_member', $user_id, $previous ? 'update' : 'create', $project_id, sprintf( 'Perfil %s asignado al usuario %d', $role, $user_id ), $previous ? array( 'role' => $previous ) : null, array( 'role' => $role ) );

		return true;
	}

	/**
	 * Retira a un usuario del proyecto (desactivación, no borrado).
	 *
	 * @param int $project_id Proyecto.
	 * @param int $user_id    Usuario.
	 * @return bool
	 */
	public static function remove( int $project_id, int $user_id ): bool {
		global $wpdb;

		$previous = self::role_for_user( $project_id, $user_id );
		if ( null === $previous ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'project_members' ), array( 'active' => 0 ), array( 'project_id' => $project_id, 'user_id' => $user_id ), array( '%d' ), array( '%d', '%d' ) );

		Audit::log( 'project_member', $user_id, 'delete', $project_id, sprintf( 'Usuario %d retirado del proyecto', $user_id ), array( 'role' => $previous ), null );

		return true;
	}
}
