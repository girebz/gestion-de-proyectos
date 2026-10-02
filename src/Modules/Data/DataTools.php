<?php
/**
 * Herramientas del conector para exportar, importar y respaldar.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\OperationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * export-project y get-data-dictionary (consulta), propose-data-change
 * (propuesta de importación o restauración), list-backups y create-backup
 * (administradores del plugin).
 */
final class DataTools {

	/**
	 * Añade las habilidades del módulo.
	 *
	 * @param array<string,array<string,mixed>> $definitions Definiciones.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_abilities( array $definitions ): array {
		$read  = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
		$write = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false );
		$out   = array( 'type' => 'object', 'additionalProperties' => true );

		$definitions['export-project'] = array(
			'label'            => __( 'Exportar proyecto', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve la exportación completa de un proyecto en el formato gestion-de-proyectos/export: metadatos, usuarios referidos y las filas de cada tabla con sus referencias resueltas (_refs con códigos y nombres). Opciones: modules (lista de core, planning, procurement, documents, purchases, meetings, links, audit), anonymize (sin usuarios, contactos ni montos), dictionary (incluye el diccionario de datos). Puede ser extenso; use modules para acotar.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => array(
					'project_id' => array( 'type' => 'integer' ),
					'code'       => array( 'type' => 'string' ),
					'modules'    => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( DataSchema::modules() ) ) ),
					'anonymize'  => array( 'type' => 'boolean' ),
					'dictionary' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'export_project' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-data-dictionary'] = array(
			'label'            => __( 'Obtener diccionario de datos', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve el diccionario de datos del plugin: por tabla, etiqueta, módulo y campos con tipo, unidad, significado y tabla referida. Opcional: tables (lista de tablas).', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'tables' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_dictionary' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['propose-data-change'] = array(
			'label'               => __( 'Proponer importación o restauración', 'gestion-de-proyectos' ),
			'description'         => __( 'Propone una importación de datos o la restauración de un respaldo. NO aplica nada: devuelve la vista previa (plan por tabla: nuevos, actualizados, sin cambios, conflictos) y un operation_id que debe confirmarse con confirm-operation. Acciones: import (document: objeto en el formato gestion-de-proyectos/export, o file: nombre devuelto por una subida previa; mode new|update; project_code para crear con otro código; tables para aplicar solo algunas tablas), restore_backup (backup: nombre del respaldo; solo administradores; reemplaza todos los datos tras crear un respaldo de seguridad).', 'gestion-de-proyectos' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'action'       => array( 'type' => 'string', 'enum' => array( 'import', 'restore_backup' ) ),
					'project_id'   => array( 'type' => 'integer' ),
					'document'     => array( 'type' => 'object', 'additionalProperties' => true ),
					'file'         => array( 'type' => 'string' ),
					'mode'         => array( 'type' => 'string', 'enum' => Importer::MODES ),
					'project_code' => array( 'type' => 'string' ),
					'tables'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'backup'       => array( 'type' => 'string' ),
				),
				'required'             => array( 'action' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'propose_data_change' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => $write ),
		);

		$definitions['list-backups'] = array(
			'label'            => __( 'Listar respaldos', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los respaldos del sitio (nombre, fecha, origen manual|programado|seguridad, filas, adjuntos, proyectos, tamaño) y la configuración de respaldos automáticos. Solo administradores del plugin.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_backups' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['create-backup'] = array(
			'label'               => __( 'Crear respaldo', 'gestion-de-proyectos' ),
			'description'         => __( 'Crea ahora un respaldo completo del sitio (datos, SQL, diccionario y adjuntos) en el directorio privado y devuelve sus metadatos. No modifica datos. Solo administradores del plugin. Opcional: note.', 'gestion-de-proyectos' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => array( 'note' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'create_backup' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ) ),
		);

		return $definitions;
	}

	/**
	 * Exportación de un proyecto.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function export_project( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$modules  = DataSchema::exportable_modules( Exporter::modules_from( $input['modules'] ?? null, false ), (int) $project['id'] );
		$document = Exporter::project( (int) $project['id'], array( 'modules' => $modules, 'anonymize' => ! empty( $input['anonymize'] ), 'dictionary' => ! empty( $input['dictionary'] ) ) );
		if ( ! is_wp_error( $document ) ) {
			\GDP\Core\Audit::log( 'project', (int) $project['id'], 'export', (int) $project['id'], sprintf( 'Exportación por el conector (%s)', implode( ', ', (array) $document['scope']['modules'] ) ) );
		}

		return $document;
	}

	/**
	 * Diccionario de datos.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_dictionary( array $input = array() ) {
		if ( ! Access::can_access() ) {
			return new WP_Error( 'forbidden', __( 'Sin acceso al plugin.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$tables = isset( $input['tables'] ) && is_array( $input['tables'] ) && ! empty( $input['tables'] ) ? array_map( 'sanitize_key', $input['tables'] ) : null;

		return array( 'format' => Exporter::FORMAT, 'format_version' => Exporter::FORMAT_VERSION, 'schema_version' => (int) GDP_DB_VERSION, 'modules' => DataSchema::module_labels(), 'tables' => DataDictionary::build( $tables ) );
	}

	/**
	 * Propone una importación o restauración.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_data_change( array $input = array() ) {
		$action  = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$payload = array_intersect_key( $input, array_flip( array( 'document', 'file', 'mode', 'project_code', 'tables', 'backup' ) ) );
		$project = (int) ( $input['project_id'] ?? 0 );
		if ( 'import' === $action && $project <= 0 && 'update' === ( $payload['mode'] ?? '' ) ) {
			$found   = ProjectRepository::find_by_code( (string) ( $payload['project_code'] ?? ( $payload['document']['tables']['projects'][0]['code'] ?? '' ) ) );
			$project = $found ? (int) $found['id'] : 0;
		}

		return OperationManager::propose( 'data', $action, $payload, $project, 'connector' );
	}

	/**
	 * Respaldos existentes.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_backups( array $input = array() ) {
		if ( ! Access::is_manager() ) {
			return new WP_Error( 'forbidden', __( 'Solo los administradores del plugin gestionan respaldos.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$rows = array();
		foreach ( BackupService::all() as $b ) {
			unset( $b['path'] );
			$rows[] = $b;
		}

		return array(
			'count'    => count( $rows ),
			'settings' => array( 'enabled' => (bool) \GDP\Core\Options::get( 'backups_enabled', false ), 'keep' => (int) \GDP\Core\Options::get( 'backups_keep', 8 ), 'copy_dir' => (string) \GDP\Core\Options::get( 'backups_copy_dir', '' ) ),
			'backups'  => $rows,
		);
	}

	/**
	 * Crea un respaldo.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create_backup( array $input = array() ) {
		if ( ! Access::is_manager() ) {
			return new WP_Error( 'forbidden', __( 'Solo los administradores del plugin gestionan respaldos.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$meta = BackupService::create( (string) ( $input['note'] ?? '' ), 'manual' );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		unset( $meta['path'] );

		return $meta;
	}

	/**
	 * Proyecto de la entrada con permiso de exportación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function project( array $input ) {
		$project = null;
		if ( ! empty( $input['project_id'] ) ) {
			$project = ProjectRepository::find( (int) $input['project_id'] );
		} elseif ( ! empty( $input['code'] ) ) {
			$project = ProjectRepository::find_by_code( sanitize_title( (string) $input['code'] ) );
		} else {
			return new WP_Error( 'missing_identifier', __( 'Indique project_id o code.', 'gestion-de-proyectos' ) );
		}
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can( 'data.export', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para exportar este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return $project;
	}
}
