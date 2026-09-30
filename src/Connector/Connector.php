<?php
/**
 * Conector para asistentes de inteligencia artificial.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Connector;

use GDP\Core\Access;
use GDP\Core\Options;
use GDP\Core\Roles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Expone las funciones del plugin como habilidades de WordPress (Abilities
 * API, núcleo desde la versión 6.9) y, a través del adaptador MCP oficial,
 * como herramientas del protocolo MCP en un servidor propio:
 *
 *   https://<sitio>/wp-json/gestion-de-proyectos/mcp
 *
 * Las herramientas de consulta devuelven datos; las de escritura solo proponen
 * cambios, que se aplican al confirmarlos (capa de operaciones).
 */
final class Connector {

	public const CATEGORY       = 'gestion-de-proyectos';
	public const ABILITY_PREFIX = 'gestion-de-proyectos/';
	public const REST_NAMESPACE = 'gestion-de-proyectos';
	public const REST_ROUTE     = 'mcp';
	public const SERVER_ID      = 'gestion-de-proyectos';

	/**
	 * Registra la autenticación, las habilidades y el servidor MCP.
	 *
	 * @return void
	 */
	public static function register(): void {
		Auth::register();

		if ( ! Options::get( 'connector_enabled', true ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( self::class, 'register_server' ) );
	}

	/**
	 * Indica si la API de habilidades está disponible (WordPress 6.9 o superior).
	 *
	 * @return bool
	 */
	public static function abilities_api_available(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Indica si el adaptador MCP oficial está activo.
	 *
	 * @return bool
	 */
	public static function adapter_active(): bool {
		return class_exists( '\\WP\\MCP\\Plugin' ) || class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Versión del adaptador MCP, si está instalado.
	 *
	 * @return string
	 */
	public static function adapter_version(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( 0 === strpos( $file, 'mcp-adapter/' ) ) {
				return (string) ( $data['Version'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * URL del punto de entrada MCP del plugin.
	 *
	 * @return string
	 */
	public static function endpoint_url(): string {
		return rest_url( self::REST_NAMESPACE . '/' . self::REST_ROUTE );
	}

	/**
	 * Registra la categoría de habilidades del plugin.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		if ( ! self::abilities_api_available() ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Gestión de Proyectos', 'gestion-de-proyectos' ),
				'description' => __( 'Consulta y operación de proyectos de investigación y desarrollo gestionados con el plugin.', 'gestion-de-proyectos' ),
			)
		);
	}

	/**
	 * Registra todas las habilidades del catálogo.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		if ( ! self::abilities_api_available() ) {
			return;
		}

		foreach ( Abilities::definitions() as $short_name => $definition ) {
			$definition = array_merge(
				array(
					'category'            => self::CATEGORY,
					'permission_callback' => array( self::class, 'default_permission' ),
					'meta'                => array(),
				),
				$definition
			);

			$definition['meta'] = array_merge(
				array(
					'show_in_rest' => false,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
				$definition['meta']
			);

			wp_register_ability( self::ABILITY_PREFIX . $short_name, $definition );
		}
	}

	/**
	 * Permiso por defecto de las habilidades: usuario autenticado con acceso al plugin.
	 *
	 * @return bool|WP_Error
	 */
	public static function default_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'gdp_not_logged_in', __( 'Autenticación requerida.', 'gestion-de-proyectos' ), array( 'status' => 401 ) );
		}

		if ( ! Access::can_access() ) {
			return new WP_Error( 'gdp_no_access', __( 'El usuario no tiene acceso a Gestión de Proyectos.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Permiso de las habilidades de escritura: además, alcance "write" del token.
	 *
	 * @return bool|WP_Error
	 */
	public static function write_permission() {
		$base = self::default_permission();
		if ( true !== $base ) {
			return $base;
		}

		if ( ! Auth::can_write() ) {
			return new WP_Error( 'gdp_read_only_token', __( 'El token del conector es de solo lectura.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Crea el servidor MCP propio con las herramientas del plugin.
	 *
	 * @param object $adapter Instancia del adaptador (WP\MCP\Core\McpAdapter).
	 * @return void
	 */
	public static function register_server( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$tools = array();
		foreach ( array_keys( Abilities::definitions() ) as $short_name ) {
			$tools[] = self::ABILITY_PREFIX . $short_name;
		}

		$error_handler = class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' ) ? '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' : null;
		$observability = class_exists( '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' ) ? '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' : null;

		$transports = array();
		if ( class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ) {
			$transports[] = '\\WP\\MCP\\Transport\\HttpTransport';
		}

		if ( empty( $transports ) ) {
			return;
		}

		try {
			$adapter->create_server(
				self::SERVER_ID,
				self::REST_NAMESPACE,
				self::REST_ROUTE,
				sprintf( '%s · Gestión de Proyectos', get_bloginfo( 'name' ) ),
				__( 'Consulta y operación de los proyectos gestionados en este sitio. Las herramientas de escritura proponen cambios que deben confirmarse.', 'gestion-de-proyectos' ),
				GDP_VERSION,
				$transports,
				$error_handler,
				$observability,
				$tools,
				array(),
				array(),
				array( self::class, 'transport_permission' )
			);
		} catch ( \Throwable $e ) {
			// Se registra en el log de PHP; el diagnóstico del panel lo mostrará.
			error_log( 'Gestión de Proyectos: no se pudo crear el servidor MCP: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Permiso de transporte del servidor: usuario autenticado con acceso al plugin
	 * y capacidad de usar el conector.
	 *
	 * @return bool|WP_Error
	 */
	public static function transport_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'gdp_not_logged_in', __( 'Autenticación requerida: use un token del conector o una contraseña de aplicación.', 'gestion-de-proyectos' ), array( 'status' => 401 ) );
		}

		if ( ! Access::can_access() ) {
			return new WP_Error( 'gdp_no_access', __( 'El usuario no tiene acceso a Gestión de Proyectos.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		if ( ! Access::is_manager() && ! current_user_can( Roles::CAP_CONNECTOR ) ) {
			return new WP_Error( 'gdp_no_connector', __( 'El usuario no tiene permiso para usar el conector.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return true;
	}
}
