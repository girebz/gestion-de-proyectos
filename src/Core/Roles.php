<?php
/**
 * Roles y capacidades.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Define las capacidades del plugin y los perfiles por proyecto.
 *
 * Hay dos niveles:
 * 1. Capacidades de WordPress (gdp_manage, gdp_access, gdp_use_connector), que
 *    se asignan a roles del sitio.
 * 2. Perfiles por proyecto (director, ingeniero, investigador, apoyo,
 *    observador), almacenados en la tabla de miembros y traducidos a permisos
 *    finos mediante el mapa de esta clase. Un mismo usuario puede tener
 *    perfiles distintos en proyectos distintos.
 */
final class Roles {

	public const CAP_MANAGE    = 'gdp_manage';
	public const CAP_ACCESS    = 'gdp_access';
	public const CAP_CONNECTOR = 'gdp_use_connector';

	public const ROLE_MEMBER = 'gdp_miembro';

	/**
	 * Perfiles por proyecto y sus etiquetas.
	 *
	 * @return array<string,string>
	 */
	public static function project_roles(): array {
		return array(
			'director'     => __( 'Director del proyecto', 'gestion-de-proyectos' ),
			'ingeniero'    => __( 'Ingeniero de proyectos', 'gestion-de-proyectos' ),
			'investigador' => __( 'Investigador', 'gestion-de-proyectos' ),
			'apoyo'        => __( 'Apoyo administrativo', 'gestion-de-proyectos' ),
			'observador'   => __( 'Observador externo', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Permisos finos que otorga cada perfil dentro de un proyecto.
	 *
	 * Los permisos se nombran "modulo.accion". El ingeniero de proyectos actúa
	 * como administrador funcional; el director conserva la aprobación y la
	 * gestión de miembros; el apoyo administrativo ve y edita documentos y
	 * compras, pero no resultados de laboratorio; el investigador ve y edita el
	 * frente técnico y consulta lo administrativo sin montos.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function permissions_map(): array {
		$all = array(
			'project.view',
			'project.edit',
			'project.members',
			'planning.view',
			'planning.edit',
			'planning.baseline',
			'documents.view',
			'documents.edit',
			'documents.approve',
			'procurement.view',
			'procurement.view_amounts',
			'procurement.edit',
			'procurement.approve',
			'lab.view',
			'lab.edit',
			'meetings.view',
			'meetings.edit',
			'evidence.view',
			'evidence.edit',
			'publications.view',
			'publications.edit',
			'data.export',
			'data.import',
			'operations.confirm',
			'audit.view',
		);

		return array(
			'director'     => $all,
			'ingeniero'    => array_values( array_diff( $all, array( 'documents.approve', 'procurement.approve' ) ) ),
			'investigador' => array(
				'project.view',
				'planning.view',
				'planning.edit',
				'documents.view',
				'procurement.view',
				'lab.view',
				'lab.edit',
				'meetings.view',
				'meetings.edit',
				'evidence.view',
				'evidence.edit',
				'publications.view',
				'publications.edit',
				'data.export',
			),
			'apoyo'        => array(
				'project.view',
				'planning.view',
				'documents.view',
				'documents.edit',
				'procurement.view',
				'procurement.view_amounts',
				'procurement.edit',
				'meetings.view',
				'evidence.view',
				'evidence.edit',
				'data.export',
			),
			'observador'   => array(
				'project.view',
				'planning.view',
				'documents.view',
				'meetings.view',
				'publications.view',
			),
		);
	}

	/**
	 * Crea el rol de sitio "Miembro de proyectos" y otorga capacidades al administrador.
	 *
	 * @return void
	 */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_ACCESS );
			$admin->add_cap( self::CAP_CONNECTOR );
		}

		if ( ! get_role( self::ROLE_MEMBER ) ) {
			add_role(
				self::ROLE_MEMBER,
				__( 'Miembro de proyectos', 'gestion-de-proyectos' ),
				array(
					'read'              => true,
					self::CAP_ACCESS    => true,
					self::CAP_CONNECTOR => true,
				)
			);
		}
	}

	/**
	 * Retira el rol y las capacidades (desinstalación).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAP_MANAGE );
			$admin->remove_cap( self::CAP_ACCESS );
			$admin->remove_cap( self::CAP_CONNECTOR );
		}

		remove_role( self::ROLE_MEMBER );
	}
}
