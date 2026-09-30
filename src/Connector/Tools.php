<?php
/**
 * Implementación de las herramientas del conector.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Connector;

use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Catalogs;
use GDP\Core\TwoFactor;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Registry;
use GDP\Operations\OperationManager;
use GDP\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada método recibe el arreglo de entrada validado contra el esquema y
 * devuelve un arreglo (serializado a JSON) o un WP_Error.
 */
final class Tools {

	/**
	 * Estado del sistema.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>
	 */
	public static function system_status( array $input = array() ): array {
		global $wp_version;

		$user    = wp_get_current_user();
		$visible = Access::visible_project_ids();

		return array(
			'plugin'    => array(
				'name'       => 'Gestión de Proyectos',
				'version'    => GDP_VERSION,
				'db_version' => GDP_DB_VERSION,
				'site'       => get_bloginfo( 'name' ),
				'site_url'   => home_url( '/' ),
				'endpoint'   => Connector::endpoint_url(),
			),
			'platform'  => array(
				'wordpress'      => $wp_version,
				'php'            => PHP_VERSION,
				'abilities_api'  => Connector::abilities_api_available(),
				'mcp_adapter'    => Connector::adapter_active(),
				'adapter_version' => Connector::adapter_version(),
				'timezone'       => wp_timezone_string(),
				'server_time'    => current_time( 'c' ),
			),
			'modules'   => Plugin::instance()->modules()->status(),
			'projects'  => array(
				'total'   => ProjectRepository::count(),
				'visible' => null === $visible ? ProjectRepository::count() : count( $visible ),
			),
			'user'      => array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'is_manager'   => Access::is_manager(),
				'auth'         => Auth::authenticated_by_token() ? 'token' : 'wordpress',
				'can_write'    => Auth::can_write(),
				'two_factor'   => array(
					'required' => TwoFactor::is_required(),
					'provider' => TwoFactor::active_provider()['label'] ?? null,
					'has'      => TwoFactor::user_has( (int) $user->ID ),
					'blocked'  => TwoFactor::blocks( (int) $user->ID ),
				),
			),
			'operations' => array(
				'pending' => count( OperationManager::find_by_status( OperationManager::STATUS_PROPOSED, null, 500 ) ),
			),
			'notes'     => array(
				__( 'Las herramientas de escritura proponen cambios; nada se aplica sin confirm-operation.', 'gestion-de-proyectos' ),
				__( 'Lea el proyecto con get-project antes de proponer una actualización y envíe expected_version.', 'gestion-de-proyectos' ),
			),
		);
	}

	/**
	 * Lista de proyectos visibles.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>
	 */
	public static function list_projects( array $input = array() ): array {
		$status  = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		$search  = isset( $input['search'] ) ? mb_strtolower( trim( (string) $input['search'] ) ) : '';
		$visible = Access::visible_project_ids();

		$projects = ProjectRepository::all( $visible, $status );

		if ( '' !== $search ) {
			$projects = array_values(
				array_filter(
					$projects,
					static function ( array $p ) use ( $search ): bool {
						$haystack = mb_strtolower( $p['code'] . ' ' . $p['name'] . ' ' . $p['short_name'] . ' ' . $p['funder'] . ' ' . $p['funding_code'] );
						return false !== mb_strpos( $haystack, $search );
					}
				)
			);
		}

		return array(
			'count'    => count( $projects ),
			'projects' => array_map( array( self::class, 'project_summary' ), $projects ),
		);
	}

	/**
	 * Ficha completa de un proyecto.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_project( array $input = array() ) {
		$project = self::resolve_project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$id = (int) $project['id'];
		if ( ! Access::can( 'project.view', $id ) ) {
			return new WP_Error( 'forbidden', __( 'No tiene acceso a este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		$registry = Plugin::instance()->modules();
		$modules  = array();
		foreach ( $registry->status() as $row ) {
			$row['enabled'] = $registry->is_enabled( $row['slug'], $id );
			$modules[]      = $row;
		}

		$members = Access::can( 'project.members', $id ) || Access::can( 'project.view', $id ) ? MemberRepository::for_project( $id ) : array();
		if ( ! Access::is_manager() ) {
			// El correo de los miembros solo se expone a administradores del plugin.
			$members = array_map(
				static function ( array $m ): array {
					unset( $m['email'] );
					return $m;
				},
				$members
			);
		}

		$project['status_label'] = Catalogs::label( Catalogs::PROJECT_STATUS, (string) $project['status'] );
		$project['members']      = $members;
		$project['modules']      = $modules;
		$project['my_role']      = Access::is_manager() ? 'administrador' : MemberRepository::role_for_user( $id, get_current_user_id() );

		if ( ! Access::can( 'procurement.view_amounts', $id ) ) {
			$project['budget_total'] = null;
		}

		return $project;
	}

	/**
	 * Usuarios del sitio para asignación de miembros.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>
	 */
	public static function list_users( array $input = array() ): array {
		$search = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
		$limit  = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;

		$args = array(
			'number'  => $limit,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'display_name', 'user_login', 'user_email' ),
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
		}

		$manager = Access::is_manager();
		$users   = array();

		foreach ( get_users( $args ) as $u ) {
			$row = array(
				'id'           => (int) $u->ID,
				'display_name' => $u->display_name,
				'login'        => $u->user_login,
			);
			if ( $manager ) {
				$row['email'] = $u->user_email;
			}
			$users[] = $row;
		}

		return array(
			'count' => count( $users ),
			'users' => $users,
		);
	}

	/**
	 * Entradas de un catálogo.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_catalog( array $input = array() ) {
		$catalog    = sanitize_key( (string) ( $input['catalog'] ?? '' ) );
		$project_id = isset( $input['project_id'] ) ? (int) $input['project_id'] : 0;

		if ( '' === $catalog ) {
			return new WP_Error( 'invalid_catalog', __( 'Indique el nombre del catálogo.', 'gestion-de-proyectos' ) );
		}

		if ( $project_id > 0 && ! Access::can( 'project.view', $project_id ) ) {
			return new WP_Error( 'forbidden', __( 'No tiene acceso a este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		$items = Catalogs::items( $catalog, $project_id );

		return array(
			'catalog' => $catalog,
			'count'   => count( $items ),
			'items'   => array_map(
				static function ( array $item ): array {
					return array(
						'slug'        => $item['slug'],
						'label'       => $item['label'],
						'description' => $item['description'],
						'scope'       => (int) $item['project_id'] > 0 ? 'project' : 'global',
						'meta'        => $item['meta'],
					);
				},
				$items
			),
		);
	}

	/**
	 * Bitácora reciente.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function recent_audit( array $input = array() ) {
		$project_id = isset( $input['project_id'] ) ? (int) $input['project_id'] : null;
		$limit      = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;

		if ( TwoFactor::blocks( get_current_user_id() ) ) {
			return new WP_Error( 'two_factor_required', wp_strip_all_tags( TwoFactor::blocked_message() ), array( 'status' => 403 ) );
		}
		if ( null === $project_id ) {
			if ( ! Access::is_manager() ) {
				return new WP_Error( 'forbidden', __( 'Indique un proyecto: la bitácora global solo la ven los administradores del plugin.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
			}
		} elseif ( ! Access::can( 'audit.view', $project_id ) ) {
			return new WP_Error( 'forbidden', __( 'No tiene permiso para ver la bitácora de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		$rows = Audit::recent( $limit, $project_id );

		return array(
			'count'   => count( $rows ),
			'entries' => array_map(
				static function ( array $row ): array {
					$user = get_userdata( (int) $row['user_id'] );
					return array(
						'id'           => (int) $row['id'],
						'when'         => $row['created_at'] . 'Z',
						'user'         => $user ? $user->display_name : ( (int) $row['user_id'] > 0 ? '#' . $row['user_id'] : 'sistema' ),
						'channel'      => $row['channel'],
						'entity_type'  => $row['entity_type'],
						'entity_id'    => (int) $row['entity_id'],
						'action'       => $row['action'],
						'summary'      => $row['summary'],
						'operation_id' => (int) $row['operation_id'],
					);
				},
				$rows
			),
		);
	}

	/**
	 * Propone un cambio en un proyecto (dos tiempos).
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_project_change( array $input = array() ) {
		$action     = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$project_id = isset( $input['project_id'] ) ? (int) $input['project_id'] : 0;

		if ( 'create' !== $action && $project_id <= 0 ) {
			return new WP_Error( 'missing_project', __( 'Indique project_id.', 'gestion-de-proyectos' ) );
		}

		$payload = array();
		switch ( $action ) {
			case 'create':
			case 'update':
				$payload['data'] = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
				if ( isset( $input['expected_version'] ) ) {
					$payload['expected_version'] = (int) $input['expected_version'];
				}
				break;
			case 'set_member':
				$payload['user_id'] = (int) ( $input['user_id'] ?? 0 );
				$payload['role']    = (string) ( $input['role'] ?? '' );
				break;
			case 'remove_member':
				$payload['user_id'] = (int) ( $input['user_id'] ?? 0 );
				break;
			default:
				return new WP_Error( 'invalid_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
		}

		return OperationManager::propose( 'project', $action, $payload, 'create' === $action ? 0 : $project_id, 'connector' );
	}

	/**
	 * Operaciones por estado.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>
	 */
	public static function list_operations( array $input = array() ): array {
		$status     = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : OperationManager::STATUS_PROPOSED;
		$project_id = isset( $input['project_id'] ) ? (int) $input['project_id'] : null;
		$limit      = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;

		$rows = OperationManager::find_by_status( $status, $project_id, $limit );

		return array(
			'count'      => count( $rows ),
			'operations' => array_map( array( self::class, 'operation_summary' ), $rows ),
		);
	}

	/**
	 * Detalle de una operación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_operation( array $input = array() ) {
		$op = OperationManager::get( (int) ( $input['operation_id'] ?? 0 ) );
		if ( ! $op ) {
			return new WP_Error( 'not_found', __( 'La operación no existe.', 'gestion-de-proyectos' ) );
		}

		if ( ! self::can_see_operation( $op ) ) {
			return new WP_Error( 'forbidden', __( 'No tiene acceso a esta operación.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return self::operation_summary( $op, true );
	}

	/**
	 * Confirma una operación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function confirm_operation( array $input = array() ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error( 'confirmation_required', __( 'Para aplicar la operación, envíe confirm=true tras la aprobación explícita de la persona.', 'gestion-de-proyectos' ) );
		}

		return OperationManager::confirm( (int) ( $input['operation_id'] ?? 0 ) );
	}

	/**
	 * Cancela una operación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function cancel_operation( array $input = array() ) {
		$ok = OperationManager::cancel( (int) ( $input['operation_id'] ?? 0 ) );

		return is_wp_error( $ok ) ? $ok : array( 'operation_id' => (int) $input['operation_id'], 'status' => OperationManager::STATUS_CANCELLED );
	}

	/**
	 * Revierte una operación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function revert_operation( array $input = array() ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error( 'confirmation_required', __( 'Para revertir la operación, envíe confirm=true tras la aprobación explícita de la persona.', 'gestion-de-proyectos' ) );
		}

		$ok = OperationManager::revert( (int) ( $input['operation_id'] ?? 0 ) );

		return is_wp_error( $ok ) ? $ok : array( 'operation_id' => (int) $input['operation_id'], 'status' => OperationManager::STATUS_REVERTED );
	}

	/**
	 * Resuelve un proyecto por identificador o código.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_project( array $input ) {
		$project = null;

		if ( ! empty( $input['project_id'] ) ) {
			$project = ProjectRepository::find( (int) $input['project_id'] );
		} elseif ( ! empty( $input['code'] ) ) {
			$project = ProjectRepository::find_by_code( sanitize_title( (string) $input['code'] ) );
		} else {
			return new WP_Error( 'missing_identifier', __( 'Indique project_id o code.', 'gestion-de-proyectos' ) );
		}

		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}

		return $project;
	}

	/**
	 * Resumen de un proyecto para listados.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return array<string,mixed>
	 */
	private static function project_summary( array $p ): array {
		$show_amounts = Access::can( 'procurement.view_amounts', (int) $p['id'] );

		return array(
			'id'           => (int) $p['id'],
			'code'         => $p['code'],
			'name'         => $p['name'],
			'short_name'   => $p['short_name'],
			'status'       => $p['status'],
			'status_label' => Catalogs::label( Catalogs::PROJECT_STATUS, (string) $p['status'] ),
			'funder'       => $p['funder'],
			'funding_code' => $p['funding_code'],
			'start_date'   => $p['start_date'],
			'end_date'     => $p['end_date'],
			'budget_total' => $show_amounts ? $p['budget_total'] : null,
			'currency'     => $p['currency'],
			'version'      => (int) $p['version'],
		);
	}

	/**
	 * Indica si el usuario actual puede ver una operación.
	 *
	 * @param array<string,mixed> $op Operación.
	 * @return bool
	 */
	private static function can_see_operation( array $op ): bool {
		if ( Access::is_manager() ) {
			return true;
		}

		if ( (int) $op['user_id'] === get_current_user_id() ) {
			return true;
		}

		return (int) $op['project_id'] > 0 && Access::can( 'project.view', (int) $op['project_id'] );
	}

	/**
	 * Resumen de una operación.
	 *
	 * @param array<string,mixed> $op       Operación.
	 * @param bool                $detailed Incluir payload, resultado y estado anterior.
	 * @return array<string,mixed>
	 */
	private static function operation_summary( array $op, bool $detailed = false ): array {
		$user = get_userdata( (int) $op['user_id'] );

		$row = array(
			'operation_id' => (int) $op['id'],
			'status'       => $op['status'],
			'handler'      => $op['handler'],
			'action'       => $op['action'],
			'project_id'   => (int) $op['project_id'],
			'summary'      => $op['summary'],
			'proposed_by'  => $user ? $user->display_name : '#' . $op['user_id'],
			'channel'      => $op['channel'],
			'created_at'   => $op['created_at'] . 'Z',
			'expires_at'   => $op['expires_at'] ? $op['expires_at'] . 'Z' : null,
			'applied_at'   => $op['applied_at'] ? $op['applied_at'] . 'Z' : null,
			'preview'      => $op['preview'],
		);

		if ( $detailed ) {
			$row['payload'] = $op['payload'];
			$row['result']  = $op['result'];
			$row['before']  = $op['before_data'];
			$row['error']   = $op['error'];
		}

		return $row;
	}
}
