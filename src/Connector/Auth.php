<?php
/**
 * Autenticación de las peticiones del conector.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Connector;

use GDP\Core\Audit;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Acepta dos credenciales sobre el punto de entrada MCP del plugin:
 *
 * 1. Token del plugin, en la cabecera "X-GDP-Token: gdp_..." o en
 *    "Authorization: Bearer gdp_...". Es la vía recomendada para Claude, que
 *    permite fijar cabeceras en la configuración del conector.
 * 2. Contraseña de aplicación de WordPress con autenticación básica, que el
 *    núcleo ya resuelve por su cuenta.
 *
 * El usuario autenticado por token conserva exactamente sus permisos.
 */
final class Auth {

	public const HEADER = 'X-GDP-Token';

	/**
	 * Token inválido detectado en la petición (para informar el error).
	 *
	 * @var bool
	 */
	private static bool $invalid_token = false;

	/**
	 * Alcance del token autenticado (read, write) o null si no hubo token.
	 *
	 * @var string[]|null
	 */
	private static ?array $scopes = null;

	/**
	 * Registra los filtros de autenticación.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'determine_current_user', array( self::class, 'determine_current_user' ), 25 );
		add_filter( 'rest_authentication_errors', array( self::class, 'authentication_errors' ), 25 );
	}

	/**
	 * Indica si la petición actual va dirigida al punto de entrada MCP del plugin.
	 *
	 * @return bool
	 */
	public static function is_connector_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( false !== strpos( $uri, '/' . Connector::REST_NAMESPACE . '/' . Connector::REST_ROUTE ) ) {
			return true;
		}

		// Enlaces permanentes simples: /?rest_route=/gestion-de-proyectos/mcp
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( (string) $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false !== strpos( $route, '/' . Connector::REST_NAMESPACE . '/' . Connector::REST_ROUTE );
		}

		return false;
	}

	/**
	 * Extrae el token de las cabeceras.
	 *
	 * @return string
	 */
	private static function token_from_headers(): string {
		$candidates = array();

		$header_key = 'HTTP_' . strtoupper( str_replace( '-', '_', self::HEADER ) );
		if ( ! empty( $_SERVER[ $header_key ] ) ) {
			$candidates[] = (string) $_SERVER[ $header_key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && preg_match( '/^Bearer\s+(\S+)$/i', (string) $_SERVER[ $key ], $m ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$candidates[] = $m[1];
			}
		}

		if ( empty( $candidates ) && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, self::HEADER ) ) {
					$candidates[] = (string) $value;
				} elseif ( 0 === strcasecmp( (string) $name, 'Authorization' ) && preg_match( '/^Bearer\s+(\S+)$/i', (string) $value, $m ) ) {
					$candidates[] = $m[1];
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( 0 === strpos( $candidate, Tokens::PREFIX ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Resuelve el usuario a partir del token del plugin.
	 *
	 * @param int|false $user_id Usuario ya determinado por otros medios.
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( ! self::is_connector_request() ) {
			return $user_id;
		}

		$token = self::token_from_headers();
		if ( '' === $token ) {
			return $user_id;
		}

		$row = Tokens::validate( $token );
		if ( ! $row ) {
			self::$invalid_token = true;
			return $user_id;
		}

		self::$scopes = array_map( 'trim', explode( ',', (string) $row['scopes'] ) );
		Tokens::touch( (int) $row['id'] );
		Audit::set_channel( 'connector' );

		return (int) $row['user_id'];
	}

	/**
	 * Informa un token inválido en lugar de dejar pasar la petición como anónima.
	 *
	 * @param WP_Error|null|true $result Resultado previo.
	 * @return WP_Error|null|true
	 */
	public static function authentication_errors( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( self::$invalid_token ) {
			return new WP_Error( 'gdp_invalid_token', __( 'El token del conector no es válido, caducó o fue revocado.', 'gestion-de-proyectos' ), array( 'status' => 401 ) );
		}

		return $result;
	}

	/**
	 * Indica si la sesión actual (autenticada por token) permite escribir.
	 * Las sesiones sin token (contraseña de aplicación o cookie) heredan todo.
	 *
	 * @return bool
	 */
	public static function can_write(): bool {
		if ( null === self::$scopes ) {
			return true;
		}

		return in_array( 'write', self::$scopes, true );
	}

	/**
	 * Indica si la petición actual se autenticó con token del plugin.
	 *
	 * @return bool
	 */
	public static function authenticated_by_token(): bool {
		return null !== self::$scopes;
	}
}
