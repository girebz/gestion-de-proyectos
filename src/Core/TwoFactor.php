<?php
/**
 * Doble factor de autenticación delegado.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * El plugin no implementa un segundo factor propio: delega en un plugin de
 * autenticación reconocido (Two Factor, WP 2FA, Wordfence Login Security) y,
 * cuando el ajuste "exigir doble factor" está activo y hay un proveedor,
 * niega los permisos sensibles (documentos, montos, exportación, bitácora)
 * a quien no lo tenga configurado. Otros proveedores pueden integrarse con
 * el filtro gdp_user_has_two_factor.
 */
final class TwoFactor {

	/**
	 * Permisos finos que exigen doble factor cuando la exigencia está activa.
	 */
	public const SENSITIVE = array(
		'documents.view',
		'documents.edit',
		'documents.approve',
		'procurement.view_amounts',
		'procurement.edit',
		'procurement.approve',
		'finance.edit',
		'finance.reconcile',
		'finance.rules',
		'data.export',
		'data.import',
		'audit.view',
	);

	/**
	 * Indica si la exigencia está activada en los ajustes.
	 *
	 * @return bool
	 */
	public static function is_required(): bool {
		return (bool) Options::get( 'require_two_factor', false );
	}

	/**
	 * Proveedores conocidos con su detección.
	 *
	 * @return array<string,array{label:string,active:bool,setup_url:string}>
	 */
	public static function providers(): array {
		$profile = admin_url( 'profile.php' );

		return array(
			'two-factor' => array(
				'label'     => 'Two Factor',
				'active'    => class_exists( '\Two_Factor_Core' ),
				'setup_url' => $profile . '#two-factor-options',
			),
			'wp-2fa'     => array(
				'label'     => 'WP 2FA',
				'active'    => class_exists( '\WP2FA\WP2FA' ) || defined( 'WP_2FA_VERSION' ),
				'setup_url' => $profile,
			),
			'wordfence'  => array(
				'label'     => 'Wordfence Login Security',
				'active'    => class_exists( '\WordfenceLS\Controller_Users' ),
				'setup_url' => admin_url( 'admin.php?page=WFLS' ),
			),
		);
	}

	/**
	 * Proveedor activo, si hay alguno.
	 *
	 * @return array{key:string,label:string,setup_url:string}|null
	 */
	public static function active_provider(): ?array {
		foreach ( self::providers() as $key => $p ) {
			if ( $p['active'] ) {
				return array( 'key' => $key, 'label' => $p['label'], 'setup_url' => $p['setup_url'] );
			}
		}

		/**
		 * Permite declarar un proveedor de doble factor no reconocido.
		 *
		 * @param array{key:string,label:string,setup_url:string}|null $provider Proveedor.
		 */
		$custom = apply_filters( 'gdp_two_factor_provider', null );

		return is_array( $custom ) && isset( $custom['key'], $custom['label'] ) ? array( 'key' => (string) $custom['key'], 'label' => (string) $custom['label'], 'setup_url' => (string) ( $custom['setup_url'] ?? admin_url( 'profile.php' ) ) ) : null;
	}

	/**
	 * Indica si el usuario tiene configurado un segundo factor.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function user_has( int $user_id ): bool {
		$has = null;
		if ( class_exists( '\Two_Factor_Core' ) && method_exists( '\Two_Factor_Core', 'is_user_using_two_factor' ) ) {
			$has = (bool) \Two_Factor_Core::is_user_using_two_factor( $user_id );
		} elseif ( class_exists( '\WP2FA\WP2FA' ) || defined( 'WP_2FA_VERSION' ) ) {
			$methods = get_user_meta( $user_id, 'wp_2fa_enabled_methods', true );
			$has     = ! empty( $methods );
		} elseif ( class_exists( '\WordfenceLS\Controller_Users' ) ) {
			$user = get_user_by( 'id', $user_id );
			$has  = $user instanceof WP_User && method_exists( \WordfenceLS\Controller_Users::shared(), 'has_2fa_active' ) ? (bool) \WordfenceLS\Controller_Users::shared()->has_2fa_active( $user ) : false;
		}

		/**
		 * Permite que otro plugin de autenticación informe si el usuario tiene doble factor.
		 *
		 * @param bool|null $has     Resultado detectado (null si ningún proveedor conocido respondió).
		 * @param int       $user_id Usuario.
		 */
		$has = apply_filters( 'gdp_user_has_two_factor', $has, $user_id );

		return (bool) $has;
	}

	/**
	 * Indica si la exigencia puede aplicarse (activa y con proveedor).
	 *
	 * @return bool
	 */
	public static function enforced(): bool {
		return self::is_required() && null !== self::active_provider();
	}

	/**
	 * Indica si al usuario se le niegan los permisos sensibles por carecer de doble factor.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function blocks( int $user_id ): bool {
		static $cache = array();
		if ( ! self::enforced() ) {
			return false;
		}
		if ( ! isset( $cache[ $user_id ] ) ) {
			$cache[ $user_id ] = ! self::user_has( $user_id );
		}

		return $cache[ $user_id ];
	}

	/**
	 * Indica si un permiso exige doble factor.
	 *
	 * @param string $permission Permiso.
	 * @return bool
	 */
	public static function is_sensitive( string $permission ): bool {
		/**
		 * Permite ajustar la lista de permisos que exigen doble factor.
		 *
		 * @param string[] $permissions Permisos.
		 */
		$list = (array) apply_filters( 'gdp_two_factor_permissions', self::SENSITIVE );

		return in_array( $permission, $list, true );
	}

	/**
	 * Usuarios que deberían tener doble factor: administradores del plugin y
	 * miembros cuyo perfil otorga algún permiso sensible.
	 *
	 * @return array<int,array{user_id:int,name:string,roles:string[],has:bool}>
	 */
	public static function affected_users(): array {
		$map       = Roles::permissions_map();
		$sensitive = array();
		foreach ( $map as $role => $permissions ) {
			foreach ( $permissions as $permission ) {
				if ( self::is_sensitive( $permission ) ) {
					$sensitive[] = $role;
					break;
				}
			}
		}

		$users = array();
		foreach ( get_users( array( 'capability' => Roles::CAP_MANAGE, 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			$users[ (int) $u->ID ] = array( 'user_id' => (int) $u->ID, 'name' => (string) $u->display_name, 'roles' => array( __( 'administrador del plugin', 'gestion-de-proyectos' ) ) );
		}
		foreach ( ProjectRepository::all( null ) as $project ) {
			foreach ( MemberRepository::for_project( (int) $project['id'] ) as $m ) {
				if ( ! in_array( $m['role'], $sensitive, true ) ) {
					continue;
				}
				$id = (int) $m['user_id'];
				if ( ! isset( $users[ $id ] ) ) {
					$users[ $id ] = array( 'user_id' => $id, 'name' => (string) ( $m['display_name'] ?? $id ), 'roles' => array() );
				}
				$label = sprintf( '%s (%s)', $m['role_label'], $project['code'] );
				if ( ! in_array( $label, $users[ $id ]['roles'], true ) ) {
					$users[ $id ]['roles'][] = $label;
				}
			}
		}
		foreach ( $users as $id => &$u ) {
			$u['has'] = self::user_has( $id );
		}
		unset( $u );
		ksort( $users );

		return array_values( $users );
	}

	/**
	 * Mensaje para el usuario bloqueado, con el enlace de configuración del proveedor.
	 *
	 * @return string HTML.
	 */
	public static function blocked_message(): string {
		$provider = self::active_provider();
		$url      = $provider ? $provider['setup_url'] : admin_url( 'profile.php' );

		return sprintf(
			/* translators: 1: proveedor, 2: URL de configuración. */
			__( 'Su perfil tiene acceso a documentos o montos y este sitio exige doble factor de autenticación. Configure el segundo factor con %1$s en <a href="%2$s">su perfil</a>; hasta entonces esas secciones permanecen cerradas.', 'gestion-de-proyectos' ),
			esc_html( $provider ? $provider['label'] : __( 'el plugin de autenticación del sitio', 'gestion-de-proyectos' ) ),
			esc_url( $url )
		);
	}
}
