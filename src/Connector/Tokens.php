<?php
/**
 * Tokens de acceso del conector.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Connector;

use GDP\Core\Audit;
use GDP\Core\Options;
use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada usuario genera sus propios tokens desde el panel. El token se muestra
 * una sola vez; en la base de datos solo se guarda su resumen SHA-256, de
 * modo que una fuga de la base no revela los tokens. Los tokens heredan el
 * perfil del usuario en cada proyecto: no otorgan permisos adicionales.
 */
final class Tokens {

	public const PREFIX = 'gdp_';

	/**
	 * Genera un token nuevo para un usuario.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $label   Etiqueta (por ejemplo, "Claude, cuenta personal").
	 * @param string $scopes  Alcance: "read" o "read,write".
	 * @param int    $days    Días de validez (0 = sin caducidad).
	 * @return array{id:int,token:string,expires_at:?string}|WP_Error
	 */
	public static function create( int $user_id, string $label, string $scopes = 'read,write', int $days = 0 ) {
		global $wpdb;

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'invalid_user', __( 'Usuario no válido.', 'gestion-de-proyectos' ) );
		}

		$scopes = implode( ',', array_intersect( array_map( 'trim', explode( ',', $scopes ) ), array( 'read', 'write' ) ) );
		if ( '' === $scopes ) {
			$scopes = 'read';
		}

		try {
			$secret = bin2hex( random_bytes( 24 ) );
		} catch ( \Exception $e ) {
			$secret = wp_generate_password( 48, false, false );
		}

		$token      = self::PREFIX . $secret;
		$hash       = hash( 'sha256', $token );
		$expires_at = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Schema::table( 'connector_tokens' ),
			array(
				'user_id'      => $user_id,
				'label'        => sanitize_text_field( $label ),
				'token_hash'   => $hash,
				'token_prefix' => substr( $token, 0, 8 ),
				'scopes'       => $scopes,
				'expires_at'   => $expires_at,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el token.', 'gestion-de-proyectos' ) );
		}

		$id = (int) $wpdb->insert_id;
		Audit::log( 'connector_token', $id, 'create', 0, sprintf( 'Token del conector creado para el usuario %d (%s)', $user_id, $label ) );

		return array(
			'id'         => $id,
			'token'      => $token,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Valida un token y devuelve su fila (o null).
	 *
	 * @param string $token Token en claro.
	 * @return array<string,mixed>|null
	 */
	public static function validate( string $token ): ?array {
		global $wpdb;

		$token = trim( $token );
		if ( 0 !== strpos( $token, self::PREFIX ) || strlen( $token ) < 20 ) {
			return null;
		}

		$table = Schema::table( 'connector_tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", hash( 'sha256', $token ) ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		if ( ! empty( $row['revoked_at'] ) ) {
			return null;
		}

		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}

		if ( ! get_userdata( (int) $row['user_id'] ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Anota el uso de un token.
	 *
	 * @param int $id Token.
	 * @return void
	 */
	public static function touch( int $id ): void {
		global $wpdb;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'connector_tokens' ), array( 'last_used_at' => current_time( 'mysql', true ), 'last_ip' => $ip ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * Revoca un token (el usuario solo puede revocar los suyos, salvo administradores).
	 *
	 * @param int $id      Token.
	 * @param int $user_id Usuario que revoca.
	 * @param bool $force  Ignorar la propiedad (administradores).
	 * @return bool
	 */
	public static function revoke( int $id, int $user_id, bool $force = false ): bool {
		global $wpdb;

		$where = array( 'id' => $id );
		$fmt   = array( '%d' );
		if ( ! $force ) {
			$where['user_id'] = $user_id;
			$fmt[]            = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( Schema::table( 'connector_tokens' ), array( 'revoked_at' => current_time( 'mysql', true ) ), $where, array( '%s' ), $fmt );

		if ( $ok ) {
			Audit::log( 'connector_token', $id, 'revoke', 0, sprintf( 'Token %d revocado por el usuario %d', $id, $user_id ) );
		}

		return (bool) $ok;
	}

	/**
	 * Tokens de un usuario (sin el secreto).
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_user( int $user_id ): array {
		global $wpdb;

		$table = Schema::table( 'connector_tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, label, token_prefix, scopes, last_used_at, last_ip, expires_at, revoked_at, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Días de validez por defecto según los ajustes.
	 *
	 * @return int
	 */
	public static function default_days(): int {
		return max( 0, (int) Options::get( 'connector_token_days', 365 ) );
	}
}
