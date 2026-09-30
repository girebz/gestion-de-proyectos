<?php
/**
 * Comprobación de permisos por proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use GDP\Domain\Projects\MemberRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Punto único de decisión de acceso. Todo módulo y toda herramienta del
 * conector deben pasar por aquí, nunca por comprobaciones propias.
 */
final class Access {

	/**
	 * Registra el filtro que otorga gdp_access a los miembros de proyectos.
	 *
	 * Así, un usuario cuyo único vínculo con el plugin es su pertenencia a un
	 * proyecto ve el menú y entra al panel sin necesidad de un rol de sitio
	 * adicional.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_access_to_members' ), 10, 4 );
	}

	/**
	 * Concede gdp_access cuando el usuario es miembro activo de algún proyecto.
	 *
	 * @param array<string,bool> $allcaps Capacidades del usuario.
	 * @param string[]           $caps    Capacidades requeridas.
	 * @param array              $args    Argumentos (capacidad solicitada, usuario, ...).
	 * @param \WP_User           $user    Usuario.
	 * @return array<string,bool>
	 */
	public static function grant_access_to_members( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! in_array( Roles::CAP_ACCESS, $caps, true ) || ! empty( $allcaps[ Roles::CAP_ACCESS ] ) ) {
			return $allcaps;
		}

		if ( $user instanceof \WP_User && $user->ID > 0 && MemberRepository::count_for_user( (int) $user->ID ) > 0 ) {
			$allcaps[ Roles::CAP_ACCESS ] = true;
		}

		return $allcaps;
	}

	/**
	 * Indica si el usuario administra el plugin en todo el sitio.
	 *
	 * @param int|null $user_id Usuario (por defecto, el actual).
	 * @return bool
	 */
	public static function is_manager( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		return $user_id > 0 && user_can( $user_id, Roles::CAP_MANAGE );
	}

	/**
	 * Indica si el usuario puede entrar al panel del plugin.
	 *
	 * Basta con administrar el plugin, tener la capacidad de acceso o ser
	 * miembro activo de al menos un proyecto.
	 *
	 * @param int|null $user_id Usuario.
	 * @return bool
	 */
	public static function can_access( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( self::is_manager( $user_id ) || user_can( $user_id, Roles::CAP_ACCESS ) ) {
			return true;
		}

		return MemberRepository::count_for_user( $user_id ) > 0;
	}

	/**
	 * Comprueba un permiso fino ("modulo.accion") dentro de un proyecto.
	 *
	 * @param string   $permission Permiso, por ejemplo "documents.edit".
	 * @param int      $project_id Proyecto.
	 * @param int|null $user_id    Usuario (por defecto, el actual).
	 * @return bool
	 */
	public static function can( string $permission, int $project_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		// Doble factor exigido: los permisos sensibles se niegan a quien no lo tenga, incluso administradores.
		if ( TwoFactor::is_sensitive( $permission ) && TwoFactor::blocks( $user_id ) ) {
			return false;
		}

		if ( self::is_manager( $user_id ) ) {
			return true;
		}

		$role = MemberRepository::role_for_user( $project_id, $user_id );
		if ( null === $role ) {
			return false;
		}

		$map     = Roles::permissions_map();
		$allowed = $map[ $role ] ?? array();

		/**
		 * Permite ajustar la decisión de acceso.
		 *
		 * @param bool   $granted    Resultado calculado.
		 * @param string $permission Permiso solicitado.
		 * @param int    $project_id Proyecto.
		 * @param int    $user_id    Usuario.
		 * @param string $role       Perfil del usuario en el proyecto.
		 */
		return (bool) apply_filters( 'gdp_user_can', in_array( $permission, $allowed, true ), $permission, $project_id, $user_id, $role );
	}

	/**
	 * Identificadores de los proyectos visibles para el usuario.
	 *
	 * @param int|null $user_id Usuario.
	 * @return int[]|null Null significa "todos" (administrador del plugin).
	 */
	public static function visible_project_ids( ?int $user_id = null ): ?array {
		$user_id = $user_id ?? get_current_user_id();

		if ( self::is_manager( $user_id ) ) {
			return null;
		}

		return MemberRepository::project_ids_for_user( $user_id );
	}
}
