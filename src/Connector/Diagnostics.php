<?php
/**
 * Diagnóstico de requisitos del conector.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Connector;

use GDP\Core\Cron;
use GDP\Core\Options;
use GDP\Core\Roles;
use GDP\Core\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Comprueba, uno a uno, los requisitos para que un asistente externo pueda
 * conectarse, y explica cómo corregir cada fallo. El asistente de integración
 * del panel muestra estos resultados antes de las instrucciones.
 */
final class Diagnostics {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/**
	 * Ejecuta todas las comprobaciones.
	 *
	 * @param bool $remote Incluir comprobaciones que hacen peticiones al propio sitio.
	 * @return array<int,array{key:string,label:string,status:string,detail:string,fix:string}>
	 */
	public static function run( bool $remote = true ): array {
		global $wp_version;

		$checks = array();

		// 1. PHP.
		$php_ok   = version_compare( PHP_VERSION, GDP_MIN_PHP, '>=' );
		$checks[] = self::item(
			'php',
			__( 'Versión de PHP', 'gestion-de-proyectos' ),
			$php_ok ? self::OK : self::FAIL,
			sprintf( 'PHP %s', PHP_VERSION ),
			$php_ok ? '' : sprintf( __( 'Cambie la versión de PHP a %s o superior desde el panel del hosting (en cPanel: "Select PHP Version" o "MultiPHP Manager").', 'gestion-de-proyectos' ), GDP_MIN_PHP )
		);

		// 2. WordPress y API de habilidades.
		$wp_ok    = version_compare( (string) $wp_version, GDP_MIN_WP, '>=' ) && Connector::abilities_api_available();
		$checks[] = self::item(
			'wordpress',
			__( 'Versión de WordPress y API de habilidades', 'gestion-de-proyectos' ),
			$wp_ok ? self::OK : self::FAIL,
			sprintf( 'WordPress %s; API de habilidades: %s', (string) $wp_version, Connector::abilities_api_available() ? __( 'disponible', 'gestion-de-proyectos' ) : __( 'no disponible', 'gestion-de-proyectos' ) ),
			$wp_ok ? '' : sprintf( __( 'Actualice WordPress a la versión %s o superior (Escritorio → Actualizaciones). La API de habilidades forma parte del núcleo desde 6.9.', 'gestion-de-proyectos' ), GDP_MIN_WP )
		);

		// 3. Adaptador MCP.
		$adapter_ok = Connector::adapter_active();
		$checks[]   = self::item(
			'mcp_adapter',
			__( 'Plugin MCP Adapter (oficial de WordPress)', 'gestion-de-proyectos' ),
			$adapter_ok ? self::OK : self::FAIL,
			$adapter_ok ? sprintf( __( 'Activo (versión %s)', 'gestion-de-proyectos' ), Connector::adapter_version() ) : __( 'No instalado o inactivo', 'gestion-de-proyectos' ),
			$adapter_ok ? '' : __( 'Instálelo desde Plugins → Añadir nuevo, buscando "MCP Adapter" (autor: WordPress.org Contributors), o suba el ZIP de https://github.com/WordPress/mcp-adapter/releases. Luego actívelo.', 'gestion-de-proyectos' )
		);

		// 4. Conector habilitado.
		$enabled  = (bool) Options::get( 'connector_enabled', true );
		$checks[] = self::item(
			'connector_enabled',
			__( 'Conector habilitado en los ajustes', 'gestion-de-proyectos' ),
			$enabled ? self::OK : self::FAIL,
			$enabled ? __( 'Habilitado', 'gestion-de-proyectos' ) : __( 'Deshabilitado', 'gestion-de-proyectos' ),
			$enabled ? '' : __( 'Actívelo en Gestión de Proyectos → Ajustes → Conector.', 'gestion-de-proyectos' )
		);

		// 5. HTTPS.
		$https    = 0 === strpos( home_url( '/' ), 'https://' );
		$checks[] = self::item(
			'https',
			__( 'Sitio servido por HTTPS', 'gestion-de-proyectos' ),
			$https ? self::OK : self::FAIL,
			home_url( '/' ),
			$https ? '' : __( 'Los conectores de Claude exigen HTTPS. Active el certificado SSL en el hosting (AutoSSL en cPanel) y cambie las direcciones del sitio a https:// en Ajustes → Generales.', 'gestion-de-proyectos' )
		);

		// 6. Enlaces permanentes.
		$pretty   = '' !== (string) get_option( 'permalink_structure' );
		$checks[] = self::item(
			'permalinks',
			__( 'Enlaces permanentes', 'gestion-de-proyectos' ),
			$pretty ? self::OK : self::WARN,
			$pretty ? __( 'Estructura personalizada activa', 'gestion-de-proyectos' ) : __( 'Estructura simple', 'gestion-de-proyectos' ),
			$pretty ? '' : __( 'Con enlaces simples el punto de entrada usa el parámetro rest_route; funciona, pero conviene activar una estructura de enlaces permanentes en Ajustes → Enlaces permanentes.', 'gestion-de-proyectos' )
		);

		// 7. Directorio privado.
		$storage    = Storage::status();
		$storage_ok = $storage['exists'] && $storage['writable'];
		$checks[]   = self::item(
			'storage',
			__( 'Directorio privado de adjuntos', 'gestion-de-proyectos' ),
			$storage_ok ? ( $storage['htaccess'] ? self::OK : self::WARN ) : self::FAIL,
			Storage::private_dir() . ( $storage['htaccess'] ? '' : ' ' . __( '(sin .htaccess)', 'gestion-de-proyectos' ) ),
			$storage_ok ? ( $storage['htaccess'] ? '' : __( 'En servidores Nginx, deniegue el acceso web a este directorio en la configuración del servidor (location con "deny all").', 'gestion-de-proyectos' ) ) : __( 'Revise los permisos de escritura de wp-content/uploads.', 'gestion-de-proyectos' )
		);

		// 8. Cron real.
		$cron     = Cron::status();
		$checks[] = self::item(
			'cron',
			__( 'Tareas programadas', 'gestion-de-proyectos' ),
			$cron['wp_cron_disabled'] ? self::OK : self::WARN,
			$cron['wp_cron_disabled'] ? __( 'WP-Cron desactivado; se asume un cron real del hosting', 'gestion-de-proyectos' ) : __( 'WP-Cron interno (depende de las visitas al sitio)', 'gestion-de-proyectos' ),
			$cron['wp_cron_disabled'] ? '' : __( 'Para alertas puntuales, añada en wp-config.php la línea define( \'DISABLE_WP_CRON\', true ); y cree en cPanel un cron job cada 15 minutos con: wget -q -O /dev/null "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '"', 'gestion-de-proyectos' )
		);

		// 9. Usuario actual: permiso y tokens.
		$user_ok  = current_user_can( Roles::CAP_MANAGE ) || current_user_can( Roles::CAP_CONNECTOR );
		$tokens   = array_filter( Tokens::for_user( get_current_user_id() ), static fn( $t ) => empty( $t['revoked_at'] ) );
		$checks[] = self::item(
			'user',
			__( 'Su usuario puede usar el conector', 'gestion-de-proyectos' ),
			$user_ok ? ( empty( $tokens ) ? self::WARN : self::OK ) : self::FAIL,
			$user_ok ? sprintf( _n( '%d token activo', '%d tokens activos', count( $tokens ), 'gestion-de-proyectos' ), count( $tokens ) ) : __( 'Sin permiso', 'gestion-de-proyectos' ),
			$user_ok ? ( empty( $tokens ) ? __( 'Genere un token en esta misma página (paso 2 del asistente).', 'gestion-de-proyectos' ) : '' ) : __( 'Pida a un administrador que le asigne el rol "Miembro de proyectos" o la capacidad gdp_use_connector.', 'gestion-de-proyectos' )
		);

		if ( $remote ) {
			$checks = array_merge( $checks, self::remote_checks() );
		}

		return $checks;
	}

	/**
	 * Comprobaciones que consultan el propio sitio por HTTP (bucle local).
	 *
	 * @return array<int,array{key:string,label:string,status:string,detail:string,fix:string}>
	 */
	private static function remote_checks(): array {
		$checks = array();

		// API REST alcanzable.
		$rest = wp_remote_get( rest_url(), array( 'timeout' => 10, 'sslverify' => true, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $rest ) ) {
			$checks[] = self::item( 'rest', __( 'API REST alcanzable', 'gestion-de-proyectos' ), self::WARN, $rest->get_error_message(), __( 'El servidor no pudo conectarse a sí mismo (frecuente en hosting compartido). Compruebe desde otro equipo que ' . rest_url() . ' responde con JSON. Si un plugin de seguridad bloquea la API REST, añada una excepción para la ruta gestion-de-proyectos.', 'gestion-de-proyectos' ) );
		} else {
			$code     = (int) wp_remote_retrieve_response_code( $rest );
			$checks[] = self::item( 'rest', __( 'API REST alcanzable', 'gestion-de-proyectos' ), 200 === $code ? self::OK : self::FAIL, sprintf( 'HTTP %d en %s', $code, rest_url() ), 200 === $code ? '' : __( 'Un cortafuegos, plugin de seguridad o regla del servidor está bloqueando la API REST. Revise ModSecurity, Wordfence u otros, y permita la ruta /wp-json/.', 'gestion-de-proyectos' ) );
		}

		// Punto de entrada MCP: sin credenciales debe responder 401 (existe y exige autenticación).
		$mcp = wp_remote_post(
			Connector::endpoint_url(),
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'      => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => array(
							'protocolVersion' => '2025-11-25',
							'capabilities'    => new \stdClass(),
							'clientInfo'      => array( 'name' => 'gdp-diagnostico', 'version' => GDP_VERSION ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $mcp ) ) {
			$checks[] = self::item( 'mcp_endpoint', __( 'Punto de entrada MCP', 'gestion-de-proyectos' ), self::WARN, $mcp->get_error_message(), __( 'No se pudo comprobar desde el propio servidor. Pruébelo desde fuera con la orden curl que se muestra en el paso 4.', 'gestion-de-proyectos' ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $mcp );
			if ( in_array( $code, array( 401, 403 ), true ) ) {
				$checks[] = self::item( 'mcp_endpoint', __( 'Punto de entrada MCP', 'gestion-de-proyectos' ), self::OK, sprintf( __( 'Responde y exige autenticación (HTTP %d)', 'gestion-de-proyectos' ), $code ), '' );
			} elseif ( 200 === $code ) {
				$checks[] = self::item( 'mcp_endpoint', __( 'Punto de entrada MCP', 'gestion-de-proyectos' ), self::WARN, __( 'Responde sin exigir autenticación', 'gestion-de-proyectos' ), __( 'Revise que el servidor MCP del plugin esté usando el permiso de transporte esperado.', 'gestion-de-proyectos' ) );
			} elseif ( 404 === $code ) {
				$checks[] = self::item( 'mcp_endpoint', __( 'Punto de entrada MCP', 'gestion-de-proyectos' ), self::FAIL, __( 'HTTP 404: la ruta no existe', 'gestion-de-proyectos' ), __( 'El servidor MCP no se registró. Compruebe que el plugin MCP Adapter esté activo y vuelva a guardar los enlaces permanentes.', 'gestion-de-proyectos' ) );
			} else {
				$checks[] = self::item( 'mcp_endpoint', __( 'Punto de entrada MCP', 'gestion-de-proyectos' ), self::WARN, sprintf( 'HTTP %d', $code ), __( 'Respuesta inesperada; revise el registro de errores de PHP.', 'gestion-de-proyectos' ) );
			}
		}

		return $checks;
	}

	/**
	 * Indica si todas las comprobaciones críticas pasaron.
	 *
	 * @param array<int,array<string,string>> $checks Resultados.
	 * @return bool
	 */
	public static function all_passed( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( self::FAIL === $check['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Construye un resultado.
	 *
	 * @param string $key    Clave.
	 * @param string $label  Etiqueta.
	 * @param string $status Estado.
	 * @param string $detail Detalle.
	 * @param string $fix    Cómo corregirlo.
	 * @return array{key:string,label:string,status:string,detail:string,fix:string}
	 */
	private static function item( string $key, string $label, string $status, string $detail, string $fix ): array {
		return compact( 'key', 'label', 'status', 'detail', 'fix' );
	}
}
