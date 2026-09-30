<?php
/**
 * Interfaz de administración.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin;

use GDP\Admin\Pages\AuditPage;
use GDP\Admin\Pages\ConnectorPage;
use GDP\Admin\Pages\DashboardPage;
use GDP\Admin\Pages\OperationsPage;
use GDP\Admin\Pages\ProjectsPage;
use GDP\Admin\Pages\SettingsPage;
use GDP\Admin\Pages\TrashPage;
use GDP\Core\Access;
use GDP\Core\Identity;
use GDP\Core\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Menú, pantallas, estilos y manejadores de formularios del panel.
 */
final class Admin {

	public const SLUG = 'gdp';

	/**
	 * Registra los ganchos del panel.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_init', array( self::class, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . GDP_BASENAME, array( self::class, 'plugin_links' ) );

		ProjectsPage::register_handlers();
		ConnectorPage::register_handlers();
		SettingsPage::register_handlers();
		OperationsPage::register_handlers();
		TrashPage::register_handlers();

		/**
		 * Permite a los módulos registrar sus manejadores de formularios y peticiones.
		 */
		do_action( 'gdp_admin_register' );
	}

	/**
	 * Estructura del menú.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$identity = Identity::get();
		$title    = $identity['name'] ? sprintf( '%s · %s', $identity['name'], __( 'Proyectos', 'gestion-de-proyectos' ) ) : __( 'Gestión de Proyectos', 'gestion-de-proyectos' );

		add_menu_page(
			$title,
			__( 'Proyectos', 'gestion-de-proyectos' ),
			Roles::CAP_ACCESS,
			self::SLUG,
			array( DashboardPage::class, 'render' ),
			'dashicons-portfolio',
			26
		);

		add_submenu_page( self::SLUG, __( 'Panel', 'gestion-de-proyectos' ), __( 'Panel', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, self::SLUG, array( DashboardPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Proyectos', 'gestion-de-proyectos' ), __( 'Proyectos', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, self::SLUG . '-projects', array( ProjectsPage::class, 'render' ) );

		/**
		 * Permite a los módulos añadir sus pantallas tras la de proyectos.
		 *
		 * @param string $slug Slug del menú principal.
		 */
		do_action( 'gdp_admin_menu', self::SLUG );

		add_submenu_page( self::SLUG, __( 'Operaciones', 'gestion-de-proyectos' ), __( 'Operaciones', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, self::SLUG . '-operations', array( OperationsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Papelera', 'gestion-de-proyectos' ), __( 'Papelera', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, self::SLUG . '-trash', array( TrashPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Bitácora', 'gestion-de-proyectos' ), __( 'Bitácora', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, self::SLUG . '-audit', array( AuditPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Conector', 'gestion-de-proyectos' ), __( 'Conector', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, self::SLUG . '-connector', array( ConnectorPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Ajustes', 'gestion-de-proyectos' ), __( 'Ajustes', 'gestion-de-proyectos' ), Roles::CAP_MANAGE, self::SLUG . '-settings', array( SettingsPage::class, 'render' ) );
	}

	/**
	 * Estilos y scripts, solo en las pantallas del plugin.
	 *
	 * @param string $hook Pantalla actual.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'gdp-admin', GDP_URL . 'assets/css/admin.css', array(), GDP_VERSION );
		wp_add_inline_style( 'gdp-admin', Identity::css_variables() );
		wp_enqueue_script( 'gdp-admin', GDP_URL . 'assets/js/admin.js', array(), GDP_VERSION, true );
		wp_localize_script(
			'gdp-admin',
			'gdpAdmin',
			array(
				'copied'     => __( 'Copiado', 'gestion-de-proyectos' ),
				'copy'       => __( 'Copiar', 'gestion-de-proyectos' ),
				'confirmDel' => __( '¿Confirma la eliminación? Esta acción queda registrada en la bitácora.', 'gestion-de-proyectos' ),
			)
		);

		/**
		 * Permite a los módulos encolar sus propios estilos y scripts.
		 *
		 * @param string $hook Pantalla actual.
		 */
		do_action( 'gdp_admin_assets', $hook );
	}

	/**
	 * Tras activar, lleva al panel del plugin.
	 *
	 * @return void
	 */
	public static function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'gdp_activation_redirect' ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}

		delete_transient( 'gdp_activation_redirect' );

		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( Access::can_access() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}
	}

	/**
	 * Avisos generales (por ejemplo, adaptador MCP ausente) en las pantallas del plugin.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}

		$message = isset( $_GET['gdp_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['gdp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $message ) {
			$type = isset( $_GET['gdp_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['gdp_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
			$text = get_transient( 'gdp_notice_' . $message );
			if ( is_string( $text ) && '' !== $text ) {
				delete_transient( 'gdp_notice_' . $message );
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), wp_kses_post( $text ) );
			}
		}
	}

	/**
	 * Guarda un aviso y redirige a una URL del panel.
	 *
	 * @param string $url  Destino.
	 * @param string $text Texto del aviso.
	 * @param string $type success|error|warning|info.
	 * @return void
	 */
	public static function redirect_with_notice( string $url, string $text, string $type = 'success' ): void {
		$key = substr( md5( wp_rand() . microtime() ), 0, 12 );
		set_transient( 'gdp_notice_' . $key, $text, 120 );

		wp_safe_redirect( add_query_arg( array( 'gdp_notice' => $key, 'gdp_type' => $type ), $url ) );
		exit;
	}

	/**
	 * Enlaces en la lista de plugins.
	 *
	 * @param string[] $links Enlaces.
	 * @return string[]
	 */
	public static function plugin_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Panel', 'gestion-de-proyectos' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-connector' ) ), esc_html__( 'Conector', 'gestion-de-proyectos' ) )
		);

		return $links;
	}

	/**
	 * URL de una pantalla del plugin.
	 *
	 * @param string              $page Sufijo de la pantalla ('' = panel).
	 * @param array<string,mixed> $args Parámetros adicionales.
	 * @return string
	 */
	public static function url( string $page = '', array $args = array() ): string {
		$slug = '' === $page ? self::SLUG : self::SLUG . '-' . $page;

		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}
}
