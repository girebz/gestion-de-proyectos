<?php
/**
 * Capa única de operaciones: proponer, previsualizar, confirmar, revertir.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Operations;

use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Options;
use GDP\Core\Schema;
use GDP\Operations\Handlers\ProjectHandler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Toda escritura que no provenga de un formulario del panel (conector,
 * importación de JSON o planillas) pasa por aquí. El flujo es en dos tiempos:
 *
 * 1. propose(): valida, comprueba permisos y guarda la operación con su vista
 *    previa en estado "proposed". No modifica datos.
 * 2. confirm(): un usuario con permiso la aplica. Se vuelve a validar (por si
 *    los datos cambiaron entre medio), se ejecuta, se guarda el estado anterior
 *    y se anota la bitácora. Las operaciones no confirmadas caducan.
 * 3. revert(): deshace una operación aplicada a partir del estado anterior.
 */
final class OperationManager {

	public const STATUS_PROPOSED  = 'proposed';
	public const STATUS_APPLIED   = 'applied';
	public const STATUS_REVERTED  = 'reverted';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Manejadores registrados, por clave.
	 *
	 * @var array<string,HandlerInterface>|null
	 */
	private static ?array $handlers = null;

	/**
	 * Manejadores disponibles.
	 *
	 * @return array<string,HandlerInterface>
	 */
	public static function handlers(): array {
		if ( null === self::$handlers ) {
			self::$handlers = array();
			self::register_handler( new ProjectHandler() );

			/**
			 * Permite a los módulos registrar sus manejadores.
			 *
			 * @param OperationManager $manager Clase gestora (métodos estáticos).
			 */
			do_action( 'gdp_register_operation_handlers', self::class );
		}

		return self::$handlers;
	}

	/**
	 * Registra un manejador.
	 *
	 * @param HandlerInterface $handler Manejador.
	 * @return void
	 */
	public static function register_handler( HandlerInterface $handler ): void {
		if ( null === self::$handlers ) {
			self::$handlers = array();
		}
		self::$handlers[ $handler->key() ] = $handler;
	}

	/**
	 * Obtiene un manejador por clave.
	 *
	 * @param string $key Clave.
	 * @return HandlerInterface|null
	 */
	public static function handler( string $key ): ?HandlerInterface {
		$handlers = self::handlers();

		return $handlers[ $key ] ?? null;
	}

	/**
	 * Propone una operación y devuelve su vista previa.
	 *
	 * @param string $handler_key Manejador.
	 * @param string $action      Acción.
	 * @param array  $payload     Datos.
	 * @param int    $project_id  Proyecto (0 cuando la acción crea uno).
	 * @param string $channel     Canal (connector, import, admin).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose( string $handler_key, string $action, array $payload, int $project_id = 0, string $channel = 'connector' ) {
		global $wpdb;

		$handler = self::handler( $handler_key );
		if ( ! $handler ) {
			return new WP_Error( 'unknown_handler', sprintf( 'Manejador desconocido: %s', $handler_key ) );
		}

		$action = sanitize_key( $action );
		if ( ! in_array( $action, $handler->actions(), true ) ) {
			return new WP_Error( 'unknown_action', sprintf( 'Acción no admitida por %s: %s', $handler_key, $action ) );
		}

		$user_id = get_current_user_id();
		if ( ! $handler->can( $action, $payload, $project_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'No tiene permiso para proponer esta operación.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		if ( Options::get( 'connector_read_only', false ) && 'connector' === $channel ) {
			return new WP_Error( 'read_only', __( 'El conector está configurado en modo de solo lectura.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		$clean = $handler->validate( $action, $payload, $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$preview = $handler->preview( $action, $clean, $project_id );
		$ttl     = max( 1, (int) Options::get( 'operation_ttl_hours', 24 ) );
		$now     = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Schema::table( 'operations' ),
			array(
				'project_id' => $project_id,
				'user_id'    => $user_id,
				'channel'    => sanitize_key( $channel ),
				'handler'    => $handler_key,
				'action'     => $action,
				'status'     => self::STATUS_PROPOSED,
				'summary'    => mb_substr( (string) ( $preview['summary'] ?? '' ), 0, 255 ),
				'payload'    => wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ),
				'preview'    => wp_json_encode( $preview, JSON_UNESCAPED_UNICODE ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl * HOUR_IN_SECONDS ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo registrar la operación.', 'gestion-de-proyectos' ) );
		}

		$id = (int) $wpdb->insert_id;

		Audit::log( 'operation', $id, 'propose', $project_id, (string) ( $preview['summary'] ?? '' ), null, array( 'handler' => $handler_key, 'action' => $action ), $id );

		return array(
			'operation_id' => $id,
			'status'       => self::STATUS_PROPOSED,
			'handler'      => $handler_key,
			'action'       => $action,
			'project_id'   => $project_id,
			'preview'      => $preview,
			'expires_at'   => gmdate( 'c', $now + $ttl * HOUR_IN_SECONDS ),
			'confirmable'  => empty( $preview['conflicts'] ),
			'next_step'    => __( 'Revise la vista previa. Para aplicar los cambios, confirme la operación indicando su identificador y confirm=true.', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Confirma y aplica una operación propuesta.
	 *
	 * @param int $id Identificador de la operación.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function confirm( int $id ) {
		global $wpdb;

		$op = self::get( $id );
		if ( ! $op ) {
			return new WP_Error( 'not_found', __( 'La operación no existe.', 'gestion-de-proyectos' ) );
		}

		if ( self::STATUS_PROPOSED !== $op['status'] ) {
			return new WP_Error( 'invalid_status', sprintf( __( 'La operación está en estado "%s" y no puede confirmarse.', 'gestion-de-proyectos' ), $op['status'] ) );
		}

		if ( $op['expires_at'] && strtotime( $op['expires_at'] . ' UTC' ) < time() ) {
			self::set_status( $id, self::STATUS_EXPIRED );
			return new WP_Error( 'expired', __( 'La operación caducó; vuelva a proponerla.', 'gestion-de-proyectos' ) );
		}

		$handler = self::handler( $op['handler'] );
		if ( ! $handler ) {
			return new WP_Error( 'unknown_handler', __( 'El manejador de la operación ya no está disponible.', 'gestion-de-proyectos' ) );
		}

		$user_id    = get_current_user_id();
		$project_id = (int) $op['project_id'];

		$may_confirm = Access::is_manager( $user_id )
			|| ( $project_id > 0 && Access::can( 'operations.confirm', $project_id, $user_id ) )
			|| ( $user_id === (int) $op['user_id'] && $handler->can( $op['action'], $op['payload'], $project_id, $user_id ) );

		if ( ! $may_confirm ) {
			return new WP_Error( 'forbidden', __( 'No tiene permiso para confirmar esta operación.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		// Se revalida: los datos pueden haber cambiado desde la propuesta.
		$clean = $handler->validate( $op['action'], $op['payload'], $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$preview = $handler->preview( $op['action'], $clean, $project_id );
		if ( ! empty( $preview['conflicts'] ) ) {
			return new WP_Error( 'conflict', __( 'Los datos cambiaron después de la propuesta. Revise los conflictos y vuelva a proponer la operación.', 'gestion-de-proyectos' ), array( 'conflicts' => $preview['conflicts'] ) );
		}

		$outcome = $handler->apply( $op['action'], $clean, $project_id );
		if ( is_wp_error( $outcome ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Schema::table( 'operations' ), array( 'status' => self::STATUS_FAILED, 'error' => $outcome->get_error_message() ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
			return $outcome;
		}

		$effective_project = $project_id > 0 ? $project_id : (int) ( $outcome['result']['project_id'] ?? 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Schema::table( 'operations' ),
			array(
				'status'      => self::STATUS_APPLIED,
				'project_id'  => $effective_project,
				'before_data' => wp_json_encode( $outcome['before'], JSON_UNESCAPED_UNICODE ),
				'result'      => wp_json_encode( $outcome['result'], JSON_UNESCAPED_UNICODE ),
				'applied_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		Audit::log( 'operation', $id, 'apply', $effective_project, (string) $op['summary'], $outcome['before'], $outcome['result'], $id );

		return array(
			'operation_id' => $id,
			'status'       => self::STATUS_APPLIED,
			'result'       => $outcome['result'],
		);
	}

	/**
	 * Cancela una operación propuesta.
	 *
	 * @param int $id Identificador.
	 * @return bool|WP_Error
	 */
	public static function cancel( int $id ) {
		$op = self::get( $id );
		if ( ! $op ) {
			return new WP_Error( 'not_found', __( 'La operación no existe.', 'gestion-de-proyectos' ) );
		}

		if ( self::STATUS_PROPOSED !== $op['status'] ) {
			return new WP_Error( 'invalid_status', __( 'Solo pueden cancelarse operaciones propuestas.', 'gestion-de-proyectos' ) );
		}

		$user_id = get_current_user_id();
		if ( ! Access::is_manager( $user_id ) && $user_id !== (int) $op['user_id'] ) {
			return new WP_Error( 'forbidden', __( 'No tiene permiso para cancelar esta operación.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		self::set_status( $id, self::STATUS_CANCELLED );
		Audit::log( 'operation', $id, 'cancel', (int) $op['project_id'], (string) $op['summary'], null, null, $id );

		return true;
	}

	/**
	 * Revierte una operación aplicada.
	 *
	 * @param int $id Identificador.
	 * @return bool|WP_Error
	 */
	public static function revert( int $id ) {
		global $wpdb;

		$op = self::get( $id );
		if ( ! $op ) {
			return new WP_Error( 'not_found', __( 'La operación no existe.', 'gestion-de-proyectos' ) );
		}

		if ( self::STATUS_APPLIED !== $op['status'] ) {
			return new WP_Error( 'invalid_status', __( 'Solo pueden revertirse operaciones aplicadas.', 'gestion-de-proyectos' ) );
		}

		$handler = self::handler( $op['handler'] );
		if ( ! $handler ) {
			return new WP_Error( 'unknown_handler', __( 'El manejador de la operación ya no está disponible.', 'gestion-de-proyectos' ) );
		}

		$user_id    = get_current_user_id();
		$project_id = (int) $op['project_id'];

		if ( ! Access::is_manager( $user_id ) && ! ( $project_id > 0 && Access::can( 'operations.confirm', $project_id, $user_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'No tiene permiso para revertir esta operación.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		$ok = $handler->revert( $op['action'], $op['before_data'], $op['result'] ?? array(), $project_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'operations' ), array( 'status' => self::STATUS_REVERTED, 'reverted_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );

		Audit::log( 'operation', $id, 'revert', $project_id, (string) $op['summary'], $op['result'], $op['before_data'], $id );

		return true;
	}

	/**
	 * Recupera una operación con sus campos JSON decodificados.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'operations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Operaciones por estado, visibles para el usuario actual.
	 *
	 * @param string   $status     Estado.
	 * @param int|null $project_id Proyecto (null = todos los visibles).
	 * @param int      $limit      Máximo.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_by_status( string $status = self::STATUS_PROPOSED, ?int $project_id = null, int $limit = 50 ): array {
		global $wpdb;

		$table   = Schema::table( 'operations' );
		$visible = Access::visible_project_ids();
		$where   = array( 'status = %s' );
		$args    = array( $status );

		if ( null !== $project_id ) {
			$where[] = 'project_id = %d';
			$args[]  = $project_id;
		}

		if ( is_array( $visible ) ) {
			// Operaciones de proyectos visibles o propias sin proyecto (creación).
			$ids     = empty( $visible ) ? array( 0 ) : array_map( 'intval', $visible );
			$where[] = '(project_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') OR (project_id = 0 AND user_id = %d))';
			$args    = array_merge( $args, $ids, array( get_current_user_id() ) );
		}

		$args[] = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Marca como caducadas las propuestas vencidas.
	 *
	 * @return int Número de operaciones caducadas.
	 */
	public static function expire_stale(): int {
		global $wpdb;

		$table = Schema::table( 'operations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s", self::STATUS_EXPIRED, self::STATUS_PROPOSED, current_time( 'mysql', true ) ) );

		return (int) $count;
	}

	/**
	 * Cambia el estado de una operación.
	 *
	 * @param int    $id     Identificador.
	 * @param string $status Estado.
	 * @return void
	 */
	private static function set_status( int $id, string $status ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'operations' ), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Decodifica los campos JSON de una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'payload', 'preview', 'before_data', 'result' ) as $field ) {
			$decoded       = is_string( $row[ $field ] ) ? json_decode( $row[ $field ], true ) : null;
			$row[ $field ] = is_array( $decoded ) ? $decoded : ( 'before_data' === $field ? null : array() );
		}

		$row['id']         = (int) $row['id'];
		$row['project_id'] = (int) $row['project_id'];
		$row['user_id']    = (int) $row['user_id'];

		return $row;
	}
}
