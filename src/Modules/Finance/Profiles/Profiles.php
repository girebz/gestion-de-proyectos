<?php
/**
 * Registro de perfiles de fondo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * Perfiles disponibles. Las extensiones añaden los suyos con el filtro
 * gdp_finance_profiles.
 */
final class Profiles {

	/**
	 * Caché.
	 *
	 * @var array<string,Profile>|null
	 */
	private static ?array $cache = null;

	/**
	 * Todos los perfiles, por slug.
	 *
	 * @return array<string,Profile>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$profiles = array( new FrpdCoquimbo() );

		/**
		 * Permite registrar perfiles de fondo adicionales.
		 *
		 * @param Profile[] $profiles Perfiles.
		 */
		$profiles = (array) apply_filters( 'gdp_finance_profiles', $profiles );

		self::$cache = array();
		foreach ( $profiles as $p ) {
			if ( $p instanceof Profile ) {
				self::$cache[ $p->slug() ] = $p;
			}
		}

		return self::$cache;
	}

	/**
	 * Un perfil por slug; el primero disponible si no existe.
	 *
	 * @param string $slug Slug.
	 * @return Profile
	 */
	public static function get( string $slug ): Profile {
		$all = self::all();
		if ( isset( $all[ $slug ] ) ) {
			return $all[ $slug ];
		}

		return reset( $all ) ?: new FrpdCoquimbo();
	}

	/**
	 * Etiquetas por slug.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$out = array();
		foreach ( self::all() as $slug => $p ) {
			$out[ $slug ] = $p->label();
		}

		return $out;
	}
}
