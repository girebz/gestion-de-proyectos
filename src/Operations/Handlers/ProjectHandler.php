<?php
/**
 * Manejador de operaciones sobre proyectos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Operations\Handlers;

use GDP\Core\Access;
use GDP\Core\Roles;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Crear, actualizar y administrar miembros de un proyecto en dos tiempos.
 * Sirve de patrón para los manejadores de los demás módulos.
 */
final class ProjectHandler implements HandlerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'project';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array( 'create', 'update', 'set_member', 'remove_member' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( 'create' === $action ) {
			return Access::is_manager( $user_id );
		}

		if ( 'update' === $action ) {
			return Access::can( 'project.edit', $project_id, $user_id );
		}

		return Access::can( 'project.members', $project_id, $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$data = $payload['data'] ?? $payload;
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'invalid_payload', __( 'Los datos del proyecto deben ser un objeto.', 'gestion-de-proyectos' ) );
				}
				$data  = array_merge( array( 'status' => 'planificacion', 'currency' => 'CLP' ), $data );
				$clean = ProjectRepository::validate( $data );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['code'] ) || empty( $clean['name'] ) ) {
					return new WP_Error( 'required', __( 'Código y nombre son obligatorios.', 'gestion-de-proyectos' ) );
				}
				return array( 'data' => $clean );

			case 'update':
				if ( ! ProjectRepository::find( $project_id ) ) {
					return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
				}
				$data = $payload['data'] ?? array();
				if ( ! is_array( $data ) || empty( $data ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar en "data".', 'gestion-de-proyectos' ) );
				}
				$clean = ProjectRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array(
					'data'             => $clean,
					'expected_version' => isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null,
				);

			case 'set_member':
				$user_id = (int) ( $payload['user_id'] ?? 0 );
				$role    = sanitize_key( (string) ( $payload['role'] ?? '' ) );
				if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
					return new WP_Error( 'invalid_user', __( 'El usuario indicado no existe.', 'gestion-de-proyectos' ) );
				}
				if ( ! isset( Roles::project_roles()[ $role ] ) ) {
					return new WP_Error( 'invalid_role', __( 'Perfil de proyecto no válido.', 'gestion-de-proyectos' ) );
				}
				return array( 'user_id' => $user_id, 'role' => $role );

			case 'remove_member':
				$user_id = (int) ( $payload['user_id'] ?? 0 );
				if ( null === MemberRepository::role_for_user( $project_id, $user_id ) ) {
					return new WP_Error( 'not_member', __( 'El usuario no es miembro del proyecto.', 'gestion-de-proyectos' ) );
				}
				return array( 'user_id' => $user_id );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$preview = array(
			'summary'   => '',
			'changes'   => array(),
			'warnings'  => array(),
			'conflicts' => array(),
		);

		switch ( $action ) {
			case 'create':
				$preview['summary'] = sprintf( 'Crear el proyecto "%s" (código %s)', $payload['data']['name'], $payload['data']['code'] );
				foreach ( $payload['data'] as $field => $value ) {
					$preview['changes'][ $field ] = array( 'before' => null, 'after' => $value );
				}
				break;

			case 'update':
				$current            = ProjectRepository::find( $project_id );
				$preview['summary'] = sprintf( 'Actualizar el proyecto "%s"', $current['name'] );
				foreach ( $payload['data'] as $field => $value ) {
					$before = $current[ $field ] ?? null;
					if ( 'settings' === $field && is_string( $value ) ) {
						$value = json_decode( $value, true );
					}
					if ( $before !== $value ) {
						$preview['changes'][ $field ] = array( 'before' => $before, 'after' => $value );
					}
				}
				if ( null !== $payload['expected_version'] && (int) $current['version'] !== $payload['expected_version'] ) {
					$preview['conflicts'][] = sprintf( 'El proyecto está en la versión %d y la propuesta se basó en la versión %d.', (int) $current['version'], $payload['expected_version'] );
				}
				if ( empty( $preview['changes'] ) ) {
					$preview['warnings'][] = 'Ningún campo cambia respecto del estado actual.';
				}
				break;

			case 'set_member':
				$user               = get_userdata( $payload['user_id'] );
				$previous           = MemberRepository::role_for_user( $project_id, $payload['user_id'] );
				$preview['summary'] = sprintf( 'Asignar el perfil "%s" a %s', Roles::project_roles()[ $payload['role'] ], $user ? $user->display_name : (string) $payload['user_id'] );
				$preview['changes']['role'] = array( 'before' => $previous, 'after' => $payload['role'] );
				break;

			case 'remove_member':
				$user               = get_userdata( $payload['user_id'] );
				$previous           = MemberRepository::role_for_user( $project_id, $payload['user_id'] );
				$preview['summary'] = sprintf( 'Retirar del proyecto a %s', $user ? $user->display_name : (string) $payload['user_id'] );
				$preview['changes']['role'] = array( 'before' => $previous, 'after' => null );
				break;
		}

		return $preview;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$id = ProjectRepository::create( $payload['data'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return array( 'before' => null, 'result' => array( 'project_id' => $id, 'project' => ProjectRepository::find( $id ) ) );

			case 'update':
				$before  = ProjectRepository::find( $project_id );
				$updated = ProjectRepository::update( $project_id, $payload['data'], $payload['expected_version'] );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				return array( 'before' => $before, 'result' => array( 'project_id' => $project_id, 'project' => $updated ) );

			case 'set_member':
				$previous = MemberRepository::role_for_user( $project_id, $payload['user_id'] );
				$ok       = MemberRepository::set_role( $project_id, $payload['user_id'], $payload['role'] );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				return array(
					'before' => array( 'user_id' => $payload['user_id'], 'role' => $previous ),
					'result' => array( 'project_id' => $project_id, 'user_id' => $payload['user_id'], 'role' => $payload['role'] ),
				);

			case 'remove_member':
				$previous = MemberRepository::role_for_user( $project_id, $payload['user_id'] );
				MemberRepository::remove( $project_id, $payload['user_id'] );
				return array(
					'before' => array( 'user_id' => $payload['user_id'], 'role' => $previous ),
					'result' => array( 'project_id' => $project_id, 'user_id' => $payload['user_id'], 'role' => null ),
				);
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$id = (int) ( $result['project_id'] ?? 0 );
				return $id > 0 ? ProjectRepository::delete( $id ) : new WP_Error( 'nothing_to_revert', __( 'No hay proyecto que revertir.', 'gestion-de-proyectos' ) );

			case 'update':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No se guardó el estado anterior.', 'gestion-de-proyectos' ) );
				}
				$fields = array_intersect_key( $before, array_flip( array( 'code', 'name', 'short_name', 'description', 'funder', 'funding_code', 'executing_entity', 'status', 'start_date', 'end_date', 'budget_total', 'currency', 'settings' ) ) );
				$ok     = ProjectRepository::update( $project_id, $fields, null );
				return is_wp_error( $ok ) ? $ok : true;

			case 'set_member':
			case 'remove_member':
				$user_id = (int) ( $before['user_id'] ?? 0 );
				$role    = $before['role'] ?? null;
				if ( $user_id <= 0 ) {
					return new WP_Error( 'nothing_to_revert', __( 'No se guardó el estado anterior.', 'gestion-de-proyectos' ) );
				}
				if ( null === $role ) {
					MemberRepository::remove( $project_id, $user_id );
					return true;
				}
				$ok = MemberRepository::set_role( $project_id, $user_id, (string) $role );
				return is_wp_error( $ok ) ? $ok : true;
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}
}
