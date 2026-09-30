<?php
/**
 * Almacenamiento protegido de adjuntos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Los adjuntos del plugin (contratos, órdenes de compra, informes de
 * laboratorio) no se guardan en la carpeta pública de subidas, sino en un
 * directorio privado con acceso web denegado, y se entregan solo a través de
 * un punto de entrada que comprueba permisos.
 */
final class Storage {

	public const ACTION = 'gdp_file';

	/**
	 * Registra la entrega controlada de archivos.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'serve' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'deny' ) );
	}

	/**
	 * Ruta absoluta del directorio privado.
	 *
	 * @return string
	 */
	public static function private_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$name    = sanitize_file_name( (string) Options::get( 'private_dir', 'gdp-privado' ) );

		return trailingslashit( $uploads['basedir'] ) . $name;
	}

	/**
	 * Crea el directorio privado con las protecciones de servidor web.
	 *
	 * @return bool True si el directorio existe y es escribible.
	 */
	public static function ensure_private_dir(): bool {
		$dir = self::private_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Gestión de Proyectos: directorio privado, acceso web denegado.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, $rules );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silencio es oro.\n" );
		}

		return is_writable( $dir );
	}

	/**
	 * Indica si el directorio privado está efectivamente protegido en Apache;
	 * en Nginx la protección debe configurarse en el servidor (ver documentación).
	 *
	 * @return array{exists:bool,writable:bool,htaccess:bool,server:string}
	 */
	public static function status(): array {
		$dir = self::private_dir();

		return array(
			'exists'   => is_dir( $dir ),
			'writable' => is_writable( $dir ),
			'htaccess' => file_exists( trailingslashit( $dir ) . '.htaccess' ),
			'server'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		);
	}

	/**
	 * URL de descarga controlada de un archivo privado.
	 *
	 * @param string $relative Ruta relativa dentro del directorio privado.
	 * @return string
	 */
	public static function url( string $relative ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'file'   => rawurlencode( $relative ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $relative
		);
	}

	/**
	 * Entrega un archivo privado tras comprobar autenticación, nonce y permiso.
	 *
	 * La decisión de permiso se delega en los módulos mediante el filtro
	 * gdp_file_access, que recibe la ruta relativa y el usuario.
	 *
	 * @return void
	 */
	public static function serve(): void {
		$relative = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['file'] ) ) : '';
		$relative = str_replace( array( '..', "\0" ), '', ltrim( $relative, '/\\' ) );

		if ( '' === $relative || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), self::ACTION . '_' . $relative ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido.', 'gestion-de-proyectos' ), 403 );
		}

		/**
		 * Decide si el usuario actual puede descargar el archivo.
		 *
		 * @param bool   $allowed  Por defecto, solo administradores del plugin.
		 * @param string $relative Ruta relativa del archivo.
		 * @param int    $user_id  Usuario actual.
		 */
		$allowed = (bool) apply_filters( 'gdp_file_access', Access::is_manager(), $relative, get_current_user_id() );

		if ( ! $allowed ) {
			wp_die( esc_html__( 'No tiene permiso para descargar este archivo.', 'gestion-de-proyectos' ), 403 );
		}

		$path = trailingslashit( self::private_dir() ) . $relative;
		$real = realpath( $path );
		$base = realpath( self::private_dir() );

		if ( false === $real || false === $base || 0 !== strpos( $real, $base ) || ! is_file( $real ) ) {
			wp_die( esc_html__( 'El archivo no existe.', 'gestion-de-proyectos' ), 404 );
		}

		$type = wp_check_filetype( $real );
		$mime = $type['type'] ? $type['type'] : 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $real ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $real ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real );
		exit;
	}

	/**
	 * Respuesta a visitantes no autenticados.
	 *
	 * @return void
	 */
	public static function deny(): void {
		auth_redirect();
	}
}
