<?php
/**
 * Ajustes generales del plugin.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Acceso tipado a la opción única gdp_settings.
 */
final class Options {

	public const OPTION = 'gdp_settings';

	/**
	 * Valores por defecto.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'uninstall_remove_data'   => false,
			'private_dir'             => 'gdp-privado',
			'operation_ttl_hours'     => 24,
			'connector_enabled'       => true,
			'connector_read_only'     => false,
			'connector_token_days'    => 365,
			'require_two_factor'      => false,
			'usd_rate'                => 0,
			'identity_use_site'       => true,
			'identity_overrides'      => array(),
			'weekly_report_day'       => 'friday',
			'notifications_enabled'   => true,
		);
	}

	/**
	 * Todos los ajustes, fusionados con los valores por defecto.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Un ajuste concreto.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor si no existe.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Guarda uno o varios ajustes.
	 *
	 * @param array<string,mixed> $values Valores a fusionar.
	 * @return void
	 */
	public static function update( array $values ): void {
		$all = self::all();
		update_option( self::OPTION, array_merge( $all, $values ), false );
	}
}
