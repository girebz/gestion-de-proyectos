<?php
/**
 * Bitácora de auditoría.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registra quién cambió qué, cuándo y por qué canal. Todo módulo escribe aquí
 * en cada creación, modificación, eliminación, importación y llamada del
 * conector. La bitácora es de solo anexado: nunca se edita ni se borra desde
 * la interfaz.
 */
final class Audit {

	/**
	 * Canal por el que llega la acción en curso (admin, connector, import, cron, cli).
	 *
	 * @var string
	 */
	private static string $channel = 'admin';

	/**
	 * Fija el canal de la petición actual.
	 *
	 * @param string $channel Canal.
	 * @return void
	 */
	public static function set_channel( string $channel ): void {
		self::$channel = sanitize_key( $channel );
	}

	/**
	 * Canal actual.
	 *
	 * @return string
	 */
	public static function channel(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}

		return self::$channel;
	}

	/**
	 * Anexa una entrada.
	 *
	 * @param string     $entity_type  Tipo de entidad (project, document, ...).
	 * @param int        $entity_id    Identificador de la entidad.
	 * @param string     $action       Acción (create, update, delete, import, apply, revert, ...).
	 * @param int        $project_id   Proyecto (0 si no aplica).
	 * @param string     $summary      Resumen legible.
	 * @param array|null $before       Estado anterior.
	 * @param array|null $after        Estado posterior.
	 * @param int        $operation_id Operación asociada (0 si no aplica).
	 * @return int Identificador de la entrada.
	 */
	public static function log( string $entity_type, int $entity_id, string $action, int $project_id = 0, string $summary = '', ?array $before = null, ?array $after = null, int $operation_id = 0 ): int {
		global $wpdb;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( 'audit_log' ),
			array(
				'project_id'   => $project_id,
				'user_id'      => get_current_user_id(),
				'channel'      => self::channel(),
				'entity_type'  => sanitize_key( $entity_type ),
				'entity_id'    => $entity_id,
				'action'       => sanitize_key( $action ),
				'summary'      => mb_substr( $summary, 0, 255 ),
				'before_data'  => null === $before ? null : wp_json_encode( $before, JSON_UNESCAPED_UNICODE ),
				'after_data'   => null === $after ? null : wp_json_encode( $after, JSON_UNESCAPED_UNICODE ),
				'operation_id' => $operation_id,
				'ip'           => $ip,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Últimas entradas, para el panel y para el conector.
	 *
	 * @param int      $limit      Máximo de filas.
	 * @param int|null $project_id Filtrar por proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50, ?int $project_id = null ): array {
		global $wpdb;

		$table = Schema::table( 'audit_log' );
		$limit = max( 1, min( 500, $limit ) );

		if ( null === $project_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY id DESC LIMIT %d", $project_id, $limit ), ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}
}
