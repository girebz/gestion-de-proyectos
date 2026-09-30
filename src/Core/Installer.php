<?php
/**
 * Instalación y migraciones del plugin.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta la instalación inicial y las actualizaciones de esquema.
 */
final class Installer {

	public const OPTION_DB_VERSION = 'gdp_db_version';
	public const OPTION_VERSION    = 'gdp_version';
	public const OPTION_INSTALLED  = 'gdp_installed_at';

	/**
	 * Instalación completa (activación).
	 *
	 * @return void
	 */
	public static function install(): void {
		Schema::install();
		Roles::install();
		Storage::ensure_private_dir();
		Catalogs::seed_defaults();
		Cron::schedule();

		if ( ! get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, current_time( 'mysql', true ) );
		}

		update_option( self::OPTION_DB_VERSION, GDP_DB_VERSION );
		update_option( self::OPTION_VERSION, GDP_VERSION );

		/**
		 * Se dispara tras instalar o actualizar el plugin.
		 */
		do_action( 'gdp_installed' );
	}

	/**
	 * Aplica migraciones si la versión almacenada difiere de la del código.
	 *
	 * Se ejecuta en cada carga, pero solo actúa cuando hay un cambio de versión,
	 * lo que cubre las actualizaciones por subida de ZIP (que no disparan el
	 * gancho de activación).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$db_version = (string) get_option( self::OPTION_DB_VERSION, '0' );
		$version    = (string) get_option( self::OPTION_VERSION, '0' );

		if ( GDP_DB_VERSION === $db_version && GDP_VERSION === $version ) {
			return;
		}

		self::install();
	}
}
