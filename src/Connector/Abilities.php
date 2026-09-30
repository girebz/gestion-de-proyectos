<?php
/**
 * Catálogo de habilidades (herramientas del conector).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * Cada entrada se registra con wp_register_ability() bajo el prefijo
 * "gestion-de-proyectos/" y aparece en MCP como "gestion-de-proyectos-<nombre>".
 * Las descripciones importan: el asistente elige la herramienta leyéndolas.
 *
 * Los módulos añaden sus propias herramientas mediante el filtro
 * gdp_connector_abilities.
 */
final class Abilities {

	/**
	 * Definiciones de las habilidades del núcleo.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$read  = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
		$write = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false );

		$definitions = array(

			'system-status'          => array(
				'label'            => __( 'Estado del sistema', 'gestion-de-proyectos' ),
				'description'      => __( 'Devuelve el estado del plugin Gestión de Proyectos: versiones, módulos disponibles y planificados, número de proyectos, usuario autenticado y alcance del token. Úsela primero para orientarse.', 'gestion-de-proyectos' ),
				'input_schema'     => array( 'type' => 'object', 'additionalProperties' => false ),
				'output_schema'    => self::object_output( __( 'Estado del sistema.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'system_status' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'list-projects'          => array(
				'label'            => __( 'Listar proyectos', 'gestion-de-proyectos' ),
				'description'      => __( 'Lista los proyectos visibles para el usuario, con código, nombre, estado, fechas, financiador y presupuesto. Acepta filtro por estado (planificacion, ejecucion, suspendido, cierre, cerrado) y búsqueda por texto.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'status' => array( 'type' => 'string', 'description' => __( 'Filtrar por estado.', 'gestion-de-proyectos' ), 'enum' => array( 'planificacion', 'ejecucion', 'suspendido', 'cierre', 'cerrado' ) ),
						'search' => array( 'type' => 'string', 'description' => __( 'Texto a buscar en código, nombre y financiador.', 'gestion-de-proyectos' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Lista de proyectos.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'list_projects' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'get-project'            => array(
				'label'            => __( 'Obtener proyecto', 'gestion-de-proyectos' ),
				'description'      => __( 'Devuelve la ficha completa de un proyecto (por identificador numérico o por código), sus miembros con perfil, su configuración y los módulos activos. Incluye el número de versión, necesario para proponer cambios sin conflictos.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'project_id' => array( 'type' => 'integer', 'description' => __( 'Identificador numérico del proyecto.', 'gestion-de-proyectos' ) ),
						'code'       => array( 'type' => 'string', 'description' => __( 'Código del proyecto (alternativa al identificador).', 'gestion-de-proyectos' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Proyecto.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'get_project' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'list-users'             => array(
				'label'            => __( 'Listar usuarios', 'gestion-de-proyectos' ),
				'description'      => __( 'Lista usuarios del sitio (identificador, nombre y usuario) para asignarlos como miembros de un proyecto. Acepta búsqueda por texto. El correo solo se muestra a administradores del plugin.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array( 'type' => 'string', 'description' => __( 'Texto a buscar en nombre, usuario o correo.', 'gestion-de-proyectos' ) ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Usuarios.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'list_users' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'list-catalog'           => array(
				'label'            => __( 'Consultar catálogo', 'gestion-de-proyectos' ),
				'description'      => __( 'Devuelve las entradas de un catálogo configurable (document_type, procurement_stage, project_status, work_front, budget_line, verification_means, accreditation_criterion), combinando las globales con las del proyecto indicado.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'catalog'    => array( 'type' => 'string', 'description' => __( 'Nombre del catálogo.', 'gestion-de-proyectos' ) ),
						'project_id' => array( 'type' => 'integer', 'description' => __( 'Proyecto (opcional).', 'gestion-de-proyectos' ) ),
					),
					'required'             => array( 'catalog' ),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Entradas del catálogo.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'list_catalog' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'recent-audit'           => array(
				'label'            => __( 'Bitácora reciente', 'gestion-de-proyectos' ),
				'description'      => __( 'Devuelve las últimas entradas de la bitácora de auditoría (quién cambió qué, cuándo y por qué canal), opcionalmente filtradas por proyecto. Requiere el permiso audit.view en el proyecto.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'project_id' => array( 'type' => 'integer', 'description' => __( 'Proyecto (opcional).', 'gestion-de-proyectos' ) ),
						'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Entradas de la bitácora.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'recent_audit' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'propose-project-change' => array(
				'label'               => __( 'Proponer cambio en un proyecto', 'gestion-de-proyectos' ),
				'description'         => __( 'Propone crear un proyecto, actualizar sus datos o cambiar sus miembros. NO aplica nada: devuelve una vista previa con los cambios, advertencias y conflictos, más un operation_id. Los cambios se aplican solo al llamar a confirm-operation con ese identificador. Para update, envíe en expected_version la versión leída con get-project. Acciones: create (data con code, name, funder, funding_code, executing_entity, status, start_date, end_date, budget_total, currency, description), update (project_id, data, expected_version), set_member (project_id, user_id, role: director|ingeniero|investigador|apoyo|observador), remove_member (project_id, user_id).', 'gestion-de-proyectos' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'action'           => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'set_member', 'remove_member' ) ),
						'project_id'       => array( 'type' => 'integer', 'description' => __( 'Proyecto (obligatorio salvo en create).', 'gestion-de-proyectos' ) ),
						'data'             => array( 'type' => 'object', 'description' => __( 'Campos del proyecto (create y update).', 'gestion-de-proyectos' ), 'additionalProperties' => true ),
						'expected_version' => array( 'type' => 'integer', 'description' => __( 'Versión del proyecto vista por el asistente (update).', 'gestion-de-proyectos' ) ),
						'user_id'          => array( 'type' => 'integer', 'description' => __( 'Usuario (set_member, remove_member).', 'gestion-de-proyectos' ) ),
						'role'             => array( 'type' => 'string', 'enum' => array( 'director', 'ingeniero', 'investigador', 'apoyo', 'observador' ), 'description' => __( 'Perfil en el proyecto (set_member).', 'gestion-de-proyectos' ) ),
					),
					'required'             => array( 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::object_output( __( 'Vista previa de la operación propuesta.', 'gestion-de-proyectos' ) ),
				'execute_callback'    => array( Tools::class, 'propose_project_change' ),
				'permission_callback' => array( Connector::class, 'write_permission' ),
				'meta'                => array( 'annotations' => $write ),
			),

			'list-operations'        => array(
				'label'            => __( 'Listar operaciones', 'gestion-de-proyectos' ),
				'description'      => __( 'Lista operaciones por estado (proposed, applied, reverted, cancelled, expired, failed), opcionalmente por proyecto. Úsela para ver propuestas pendientes de confirmación.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'     => array( 'type' => 'string', 'enum' => array( 'proposed', 'applied', 'reverted', 'cancelled', 'expired', 'failed' ), 'default' => 'proposed' ),
						'project_id' => array( 'type' => 'integer' ),
						'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Operaciones.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'list_operations' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'get-operation'          => array(
				'label'            => __( 'Obtener operación', 'gestion-de-proyectos' ),
				'description'      => __( 'Devuelve una operación con su vista previa, estado, resultado y estado anterior.', 'gestion-de-proyectos' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array( 'operation_id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'operation_id' ),
					'additionalProperties' => false,
				),
				'output_schema'    => self::object_output( __( 'Operación.', 'gestion-de-proyectos' ) ),
				'execute_callback' => array( Tools::class, 'get_operation' ),
				'meta'             => array( 'annotations' => $read ),
			),

			'confirm-operation'      => array(
				'label'               => __( 'Confirmar operación', 'gestion-de-proyectos' ),
				'description'         => __( 'Aplica una operación propuesta. Llámela solo después de que la persona haya revisado la vista previa y aprobado explícitamente los cambios. Requiere confirm=true. Si los datos cambiaron desde la propuesta, devuelve un conflicto y no aplica nada.', 'gestion-de-proyectos' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'operation_id' => array( 'type' => 'integer' ),
						'confirm'      => array( 'type' => 'boolean', 'description' => __( 'Debe ser true para aplicar.', 'gestion-de-proyectos' ) ),
					),
					'required'             => array( 'operation_id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::object_output( __( 'Resultado de la operación.', 'gestion-de-proyectos' ) ),
				'execute_callback'    => array( Tools::class, 'confirm_operation' ),
				'permission_callback' => array( Connector::class, 'write_permission' ),
				'meta'                => array( 'annotations' => array_merge( $write, array( 'destructiveHint' => true ) ) ),
			),

			'cancel-operation'       => array(
				'label'               => __( 'Cancelar operación', 'gestion-de-proyectos' ),
				'description'         => __( 'Cancela una operación propuesta que no se va a aplicar.', 'gestion-de-proyectos' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'operation_id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'operation_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::object_output( __( 'Resultado.', 'gestion-de-proyectos' ) ),
				'execute_callback'    => array( Tools::class, 'cancel_operation' ),
				'permission_callback' => array( Connector::class, 'write_permission' ),
				'meta'                => array( 'annotations' => $write ),
			),

			'revert-operation'       => array(
				'label'               => __( 'Revertir operación', 'gestion-de-proyectos' ),
				'description'         => __( 'Deshace una operación ya aplicada, restaurando el estado anterior. Requiere confirm=true y aprobación explícita de la persona.', 'gestion-de-proyectos' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'operation_id' => array( 'type' => 'integer' ),
						'confirm'      => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'operation_id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::object_output( __( 'Resultado.', 'gestion-de-proyectos' ) ),
				'execute_callback'    => array( Tools::class, 'revert_operation' ),
				'permission_callback' => array( Connector::class, 'write_permission' ),
				'meta'                => array( 'annotations' => array_merge( $write, array( 'destructiveHint' => true ) ) ),
			),
		);

		/**
		 * Permite a los módulos añadir o modificar herramientas del conector.
		 *
		 * @param array<string,array<string,mixed>> $definitions Definiciones.
		 */
		return (array) apply_filters( 'gdp_connector_abilities', $definitions );
	}

	/**
	 * Esquema de salida genérico (objeto abierto con descripción).
	 *
	 * @param string $description Descripción.
	 * @return array<string,mixed>
	 */
	private static function object_output( string $description ): array {
		return array(
			'type'                 => 'object',
			'description'          => $description,
			'additionalProperties' => true,
		);
	}
}
