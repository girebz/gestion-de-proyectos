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
 *    observador, más los grupos a medida que define el administrador),
 *    almacenados en la tabla de miembros y traducidos a permisos finos
 *    mediante el mapa de esta clase. Un mismo usuario puede tener perfiles
 *    distintos en proyectos distintos.
 */
final class Roles {

	public const CAP_MANAGE    = 'gdp_manage';
	public const CAP_ACCESS    = 'gdp_access';
	public const CAP_CONNECTOR = 'gdp_use_connector';

	public const ROLE_MEMBER = 'gdp_miembro';

	/**
	 * Perfiles predefinidos (no editables) y sus etiquetas.
	 *
	 * @return array<string,string>
	 */
	public static function builtin_roles(): array {
		return array(
			'director'     => __( 'Director del proyecto', 'gestion-de-proyectos' ),
			'ingeniero'    => __( 'Ingeniero de proyectos', 'gestion-de-proyectos' ),
			'investigador' => __( 'Investigador', 'gestion-de-proyectos' ),
			'apoyo'        => __( 'Apoyo administrativo', 'gestion-de-proyectos' ),
			'observador'   => __( 'Observador externo', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Perfiles por proyecto y sus etiquetas: los predefinidos más los grupos a medida.
	 *
	 * @return array<string,string>
	 */
	public static function project_roles(): array {
		$roles = self::builtin_roles();
		foreach ( Groups::all() as $group ) {
			if ( ! isset( $roles[ $group['slug'] ] ) ) {
				$roles[ $group['slug'] ] = $group['label'];
			}
		}

		return $roles;
	}

	/**
	 * Todos los permisos finos, en el orden en que se presentan.
	 *
	 * @return string[]
	 */
	public static function all_permissions(): array {
		return array_keys( self::permission_labels() );
	}

	/**
	 * Etiquetas de los permisos finos, agrupadas por el prefijo del módulo.
	 *
	 * @return array<string,string>
	 */
	public static function permission_labels(): array {
		return array(
			'project.view'             => __( 'Ver la ficha del proyecto', 'gestion-de-proyectos' ),
			'project.edit'             => __( 'Editar el proyecto', 'gestion-de-proyectos' ),
			'project.members'          => __( 'Gestionar los miembros', 'gestion-de-proyectos' ),
			'planning.view'            => __( 'Ver la planificación', 'gestion-de-proyectos' ),
			'planning.edit'            => __( 'Editar la planificación', 'gestion-de-proyectos' ),
			'planning.baseline'        => __( 'Fijar líneas base', 'gestion-de-proyectos' ),
			'documents.view'           => __( 'Ver documentos', 'gestion-de-proyectos' ),
			'documents.edit'           => __( 'Editar documentos', 'gestion-de-proyectos' ),
			'documents.approve'        => __( 'Aprobar documentos', 'gestion-de-proyectos' ),
			'procurement.view'         => __( 'Ver compras', 'gestion-de-proyectos' ),
			'procurement.view_amounts' => __( 'Ver montos de compras y presupuesto', 'gestion-de-proyectos' ),
			'procurement.edit'         => __( 'Editar compras', 'gestion-de-proyectos' ),
			'procurement.approve'      => __( 'Aprobar compras', 'gestion-de-proyectos' ),
			'finance.view'             => __( 'Ver finanzas y rendición de cuentas', 'gestion-de-proyectos' ),
			'finance.edit'             => __( 'Registrar pagos, rendiciones y estados', 'gestion-de-proyectos' ),
			'finance.reconcile'        => __( 'Conciliar la caja (movimientos del centro de costo)', 'gestion-de-proyectos' ),
			'finance.export'           => __( 'Exportar expedientes, planillas y fichas de giro', 'gestion-de-proyectos' ),
			'finance.rules'            => __( 'Editar el convenio, las cuotas, los ítems y las reglas', 'gestion-de-proyectos' ),
			'lab.view'                 => __( 'Ver laboratorio', 'gestion-de-proyectos' ),
			'lab.edit'                 => __( 'Editar laboratorio', 'gestion-de-proyectos' ),
			'meetings.view'            => __( 'Ver reuniones', 'gestion-de-proyectos' ),
			'meetings.edit'            => __( 'Editar reuniones', 'gestion-de-proyectos' ),
			'evidence.view'            => __( 'Ver evidencias', 'gestion-de-proyectos' ),
			'evidence.edit'            => __( 'Editar evidencias', 'gestion-de-proyectos' ),
			'publications.view'        => __( 'Ver publicaciones', 'gestion-de-proyectos' ),
			'publications.edit'        => __( 'Editar publicaciones', 'gestion-de-proyectos' ),
			'data.export'              => __( 'Exportar datos', 'gestion-de-proyectos' ),
			'data.import'              => __( 'Importar datos', 'gestion-de-proyectos' ),
			'operations.confirm'       => __( 'Confirmar operaciones propuestas', 'gestion-de-proyectos' ),
			'audit.view'               => __( 'Ver la bitácora', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de los módulos a los que pertenecen los permisos (prefijo => etiqueta).
	 *
	 * @return array<string,string>
	 */
	public static function permission_modules(): array {
		return array(
			'project'      => __( 'Proyecto', 'gestion-de-proyectos' ),
			'planning'     => __( 'Planificación', 'gestion-de-proyectos' ),
			'documents'    => __( 'Documentos', 'gestion-de-proyectos' ),
			'procurement'  => __( 'Compras', 'gestion-de-proyectos' ),
			'finance'      => __( 'Finanzas y rendición', 'gestion-de-proyectos' ),
			'lab'          => __( 'Laboratorio', 'gestion-de-proyectos' ),
			'meetings'     => __( 'Reuniones', 'gestion-de-proyectos' ),
			'evidence'     => __( 'Evidencias', 'gestion-de-proyectos' ),
			'publications' => __( 'Publicaciones', 'gestion-de-proyectos' ),
			'data'         => __( 'Datos', 'gestion-de-proyectos' ),
			'operations'   => __( 'Operaciones', 'gestion-de-proyectos' ),
			'audit'        => __( 'Bitácora', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Permisos finos de los perfiles predefinidos.
	 *
	 * Los permisos se nombran "modulo.accion". El ingeniero de proyectos actúa
	 * como administrador funcional; el director conserva la aprobación y la
	 * gestión de miembros; el apoyo administrativo ve y edita documentos y
	 * compras, pero no resultados de laboratorio ni finanzas; el investigador
	 * ve y edita el frente técnico y consulta lo administrativo sin montos.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function builtin_permissions(): array {
		$all = self::all_permissions();

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
	 * Permisos finos que otorga cada perfil dentro de un proyecto, incluidos
	 * los grupos a medida.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function permissions_map(): array {
		$map = self::builtin_permissions();
		foreach ( Groups::all() as $group ) {
			if ( ! isset( $map[ $group['slug'] ] ) ) {
				$map[ $group['slug'] ] = $group['permissions'];
			}
		}

		return $map;
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
