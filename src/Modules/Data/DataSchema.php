<?php
/**
 * Conocimiento del esquema necesario para exportar e importar: tablas por
 * módulo, orden de carga, claves naturales, referencias entre tablas,
 * columnas de usuario, columnas JSON y columnas sensibles.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Core\Access;
use GDP\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Todo lo que el exportador y el importador necesitan saber de las tablas
 * se declara aquí, en un solo lugar, para que un módulo nuevo se incorpore
 * añadiendo sus entradas (o mediante los filtros gdp_data_modules,
 * gdp_data_refs y gdp_data_keys).
 */
final class DataSchema {

	/**
	 * Tablas por módulo, en orden de carga (las referencias apuntan hacia atrás
	 * salvo las autorreferencias y las marcadas como diferidas).
	 *
	 * @var array<string,string[]>
	 */
	private const MODULES = array(
		'core'        => array( 'projects', 'permission_groups', 'project_members', 'catalog_items' ),
		'planning'    => array( 'calendars', 'calendar_exceptions', 'activities', 'dependencies', 'assignments', 'progress', 'baselines', 'baseline_activities', 'schedule_snapshots' ),
		'procurement' => array( 'suppliers', 'budget_lines', 'uf_rates' ),
		'documents'   => array( 'documents', 'document_versions' ),
		'purchases'   => array( 'purchases', 'quotes', 'quote_items', 'purchase_stages' ),
		'meetings'    => array( 'meetings', 'meeting_attendees', 'agreements' ),
		'links'       => array( 'links', 'external_refs' ),
		'audit'       => array( 'audit_log' ),
	);

	/**
	 * Etiquetas de los módulos para la interfaz, en el orden de carga.
	 *
	 * Todo módulo que declare tablas por el filtro gdp_data_modules aparece
	 * aquí, aunque no declare etiqueta: si la lista fuera fija, un módulo
	 * nuevo existiría para la importación pero no se ofrecería al exportar,
	 * y su contenido se perdería en silencio en cada respaldo por proyecto.
	 *
	 * @return array<string,string>
	 */
	public static function module_labels(): array {
		$labels = array(
			'core'        => __( 'Proyecto, equipo, grupos de permisos y catálogos', 'gestion-de-proyectos' ),
			'planning'    => __( 'Planificación y tiempo', 'gestion-de-proyectos' ),
			'procurement' => __( 'Proveedores, partidas y unidad de fomento', 'gestion-de-proyectos' ),
			'documents'   => __( 'Control documental', 'gestion-de-proyectos' ),
			'purchases'   => __( 'Compras y cotizaciones', 'gestion-de-proyectos' ),
			'meetings'    => __( 'Reuniones y acuerdos', 'gestion-de-proyectos' ),
			'links'       => __( 'Vínculos y referencias externas', 'gestion-de-proyectos' ),
			'audit'       => __( 'Bitácora de auditoría (solo exportación)', 'gestion-de-proyectos' ),
		);

		/**
		 * Permite a otros módulos dar nombre a sus tablas en las pantallas de exportación.
		 *
		 * @param array<string,string> $labels Módulo => etiqueta.
		 */
		$labels = (array) apply_filters( 'gdp_data_module_labels', $labels );

		return self::labels_for( array_keys( self::modules() ), $labels );
	}

	/**
	 * Etiqueta de cada módulo de la lista, en su orden; el identificador
	 * cuando el módulo no declara etiqueta.
	 *
	 * @param string[]             $modules Módulos declarados.
	 * @param array<string,string> $labels  Etiquetas conocidas.
	 * @return array<string,string>
	 */
	public static function labels_for( array $modules, array $labels ): array {
		$out = array();
		foreach ( $modules as $slug ) {
			$label        = (string) ( $labels[ $slug ] ?? '' );
			$out[ $slug ] = '' !== $label ? $label : (string) $slug;
		}

		return $out;
	}

	/**
	 * Permisos propios que exige un módulo para exportar o importar sus
	 * tablas, además de data.export y data.import.
	 *
	 * Un permiso de datos general no debe abrir lo que el permiso del módulo
	 * cierra: quien no ve las finanzas tampoco debe poder llevárselas en una
	 * exportación ni escribirlas con una importación. La bitácora exige
	 * verla para exportarla: guarda cada registro antes y después de cada
	 * cambio (montos de pagos y compras incluidos) y la dirección de origen.
	 *
	 * @return array<string,array<string,string>> Módulo => [export => permiso, import => permiso].
	 */
	public static function module_permissions(): array {
		/**
		 * Permite a un módulo exigir permisos propios para exportar o importar sus tablas.
		 *
		 * @param array<string,array<string,string>> $permissions Módulo => [export => permiso, import => permiso].
		 */
		return (array) apply_filters( 'gdp_data_module_permissions', array( 'audit' => array( 'export' => 'audit.view' ) ) );
	}

	/**
	 * Módulos de la lista que el usuario actual puede exportar del proyecto.
	 *
	 * @param string[]      $modules    Módulos pedidos.
	 * @param int           $project_id Proyecto.
	 * @param callable|null $can        Comprobación de un permiso (para pruebas); por omisión, la del usuario actual.
	 * @return string[]
	 */
	public static function exportable_modules( array $modules, int $project_id, ?callable $can = null ): array {
		$can      = $can ?? static fn( string $permission ): bool => Access::can( $permission, $project_id );
		$required = self::module_permissions();
		$out      = array();
		foreach ( $modules as $slug ) {
			$permission = (string) ( $required[ $slug ]['export'] ?? '' );
			if ( '' === $permission || $can( $permission ) ) {
				$out[] = (string) $slug;
			}
		}

		return $out;
	}

	/**
	 * Permiso que exige importar cada tabla del núcleo, además de data.import:
	 * el mismo que exige editarla en el panel. Sin esta regla, quien solo
	 * puede importar podría cambiar su propio perfil en el equipo o los
	 * permisos de un grupo, que son globales y solo administra quien
	 * administra el plugin (seudopermiso "manage").
	 *
	 * @var array<string,string>
	 */
	public const TABLE_IMPORT_PERMISSIONS = array(
		'projects'          => 'project.edit',
		'project_members'   => 'project.members',
		'permission_groups' => 'manage',
	);

	/**
	 * Tablas que el usuario actual no puede importar en el proyecto: las del
	 * núcleo cuyo permiso de edición no tiene y las de los módulos cuyo
	 * permiso propio no tiene. El registro del proyecto se usa igual para
	 * ubicar el destino, pero sus cambios no se aplican.
	 *
	 * @param int           $project_id Proyecto de destino.
	 * @param callable|null $can        Comprobación de un permiso (para pruebas); por omisión, la del usuario actual.
	 * @return string[]
	 */
	public static function blocked_import_tables( int $project_id, ?callable $can = null ): array {
		$can     = $can ?? static function ( string $permission ) use ( $project_id ): bool {
			return 'manage' === $permission ? Access::is_manager() : Access::can( $permission, $project_id );
		};
		$modules = self::modules();
		$out     = array();
		foreach ( self::TABLE_IMPORT_PERMISSIONS as $table => $permission ) {
			if ( ! $can( $permission ) ) {
				$out[] = $table;
			}
		}
		foreach ( self::module_permissions() as $slug => $permissions ) {
			$permission = (string) ( $permissions['import'] ?? '' );
			if ( '' === $permission || $can( $permission ) ) {
				continue;
			}
			foreach ( $modules[ $slug ] ?? array() as $table ) {
				$out[] = (string) $table;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Tablas de cada módulo.
	 *
	 * @return array<string,string[]>
	 */
	public static function modules(): array {
		/**
		 * Permite a otros módulos declarar sus tablas para exportación e importación.
		 *
		 * @param array<string,string[]> $modules Tablas por módulo.
		 */
		return (array) apply_filters( 'gdp_data_modules', self::MODULES );
	}

	/**
	 * Todas las tablas en orden de carga.
	 *
	 * @return string[]
	 */
	public static function order(): array {
		$out = array();
		foreach ( self::modules() as $tables ) {
			foreach ( $tables as $t ) {
				$out[] = $t;
			}
		}

		return $out;
	}

	/**
	 * Tablas que nunca salen en una exportación de proyecto (credenciales y operaciones).
	 *
	 * @return string[]
	 */
	public static function never_exported(): array {
		return array( 'connector_tokens', 'operations' );
	}

	/**
	 * Tablas que se exportan pero no se importan en una importación de proyecto
	 * (historial y datos derivados con identificadores internos).
	 *
	 * @return string[]
	 */
	public static function export_only(): array {
		return array( 'audit_log', 'schedule_snapshots' );
	}

	/**
	 * Tablas sin columna project_id que se exportan completas cuando el proyecto las usa.
	 *
	 * @return string[]
	 */
	public static function global_tables(): array {
		return array( 'uf_rates' );
	}

	/**
	 * Tablas cuyo ámbito es el proyecto o global (project_id = 0).
	 *
	 * @return string[]
	 */
	public static function project_or_global(): array {
		return array( 'catalog_items', 'suppliers', 'calendars', 'permission_groups' );
	}

	/**
	 * Columna que enlaza cada tabla con su proyecto, directa o indirectamente.
	 * Las tablas sin project_id se filtran por su tabla padre.
	 *
	 * @return array<string,array{0:string,1:string}> tabla => [columna, tabla padre].
	 */
	public static function parents(): array {
		$parents = array(
			'calendar_exceptions' => array( 'calendar_id', 'calendars' ),
			'baseline_activities' => array( 'baseline_id', 'baselines' ),
			'document_versions'   => array( 'document_id', 'documents' ),
			'quote_items'         => array( 'quote_id', 'quotes' ),
			'purchase_stages'     => array( 'purchase_id', 'purchases' ),
			'meeting_attendees'   => array( 'meeting_id', 'meetings' ),
		);

		/**
		 * Permite a los módulos declarar tablas hijas (tabla => [columna, tabla padre]).
		 *
		 * @param array<string,array{0:string,1:string}> $parents Tablas hijas.
		 */
		return (array) apply_filters( 'gdp_data_parents', $parents );
	}

	/**
	 * Referencias entre tablas: tabla => columna => tabla referida.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function refs(): array {
		$refs = array(
			'permission_groups'   => array( 'project_id' => 'projects' ),
			'project_members'     => array( 'project_id' => 'projects' ),
			'catalog_items'       => array( 'project_id' => 'projects' ),
			'calendars'           => array( 'project_id' => 'projects' ),
			'calendar_exceptions' => array( 'calendar_id' => 'calendars' ),
			'activities'          => array( 'project_id' => 'projects', 'parent_id' => 'activities' ),
			'dependencies'        => array( 'project_id' => 'projects', 'successor_id' => 'activities' ),
			'assignments'         => array( 'project_id' => 'projects', 'activity_id' => 'activities' ),
			'progress'            => array( 'project_id' => 'projects', 'activity_id' => 'activities' ),
			'baselines'           => array( 'project_id' => 'projects' ),
			'baseline_activities' => array( 'baseline_id' => 'baselines', 'activity_id' => 'activities' ),
			'schedule_snapshots'  => array( 'project_id' => 'projects' ),
			'suppliers'           => array( 'project_id' => 'projects' ),
			'budget_lines'        => array( 'project_id' => 'projects' ),
			'documents'           => array( 'project_id' => 'projects', 'activity_id' => 'activities' ),
			'document_versions'   => array( 'document_id' => 'documents' ),
			'purchases'           => array( 'project_id' => 'projects', 'supplier_id' => 'suppliers', 'activity_id' => 'activities', 'chosen_quote_id' => 'quotes' ),
			'quotes'              => array( 'project_id' => 'projects', 'purchase_id' => 'purchases', 'supplier_id' => 'suppliers', 'document_id' => 'documents' ),
			'quote_items'         => array( 'quote_id' => 'quotes' ),
			'purchase_stages'     => array( 'purchase_id' => 'purchases', 'document_id' => 'documents' ),
			'meetings'            => array( 'project_id' => 'projects', 'activity_id' => 'activities', 'minutes_document_id' => 'documents' ),
			'meeting_attendees'   => array( 'meeting_id' => 'meetings' ),
			'agreements'          => array( 'project_id' => 'projects', 'meeting_id' => 'meetings', 'activity_id' => 'activities', 'last_review_meeting_id' => 'meetings' ),
			'links'               => array( 'project_id' => 'projects' ),
			'external_refs'       => array( 'project_id' => 'projects' ),
			'audit_log'           => array( 'project_id' => 'projects' ),
		);

		/**
		 * Permite declarar referencias de tablas de otros módulos.
		 *
		 * @param array<string,array<string,string>> $refs Referencias.
		 */
		return (array) apply_filters( 'gdp_data_refs', $refs );
	}

	/**
	 * Referencias polimórficas: tabla => lista de [columna de tipo, columna de identificador].
	 *
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	public static function polymorphic(): array {
		$pairs = array(
			'dependencies'  => array( array( 'predecessor_type', 'predecessor_id' ) ),
			'links'         => array( array( 'from_type', 'from_id' ), array( 'to_type', 'to_id' ) ),
			'external_refs' => array( array( 'entity_type', 'entity_id' ) ),
			'audit_log'     => array( array( 'entity_type', 'entity_id' ) ),
		);

		/**
		 * Permite a los módulos declarar referencias polimórficas
		 * (tabla => lista de [columna de tipo, columna de identificador]).
		 *
		 * @param array<string,array<int,array{0:string,1:string}>> $pairs Referencias.
		 */
		return (array) apply_filters( 'gdp_data_polymorphic', $pairs );
	}

	/**
	 * Tipo de entidad (como aparece en vínculos y bitácora) => tabla.
	 *
	 * @return array<string,string>
	 */
	public static function entity_tables(): array {
		$tables = array(
			'project'   => 'projects',
			'activity'  => 'activities',
			'document'  => 'documents',
			'supplier'  => 'suppliers',
			'purchase'  => 'purchases',
			'quote'     => 'quotes',
			'meeting'   => 'meetings',
			'agreement' => 'agreements',
			'baseline'  => 'baselines',
			'calendar'  => 'calendars',
		);

		/**
		 * Permite a los módulos declarar sus entidades enlazables (tipo => tabla).
		 *
		 * @param array<string,string> $tables Entidades.
		 */
		return (array) apply_filters( 'gdp_data_entity_tables', $tables );
	}

	/**
	 * Columnas que guardan identificadores de usuarios del sitio.
	 *
	 * @return string[]
	 */
	public static function user_columns(): array {
		return array( 'created_by', 'user_id', 'owner_id', 'organizer_id', 'uploaded_by', 'approved_by', 'updated_by' );
	}

	/**
	 * Columnas con JSON, que se exportan decodificadas.
	 *
	 * @return array<string,string[]>
	 */
	public static function json_columns(): array {
		$columns = array(
			'projects'           => array( 'settings' ),
			'permission_groups'  => array( 'permissions' ),
			'catalog_items'      => array( 'meta' ),
			'activities'         => array( 'schedule_conflicts' ),
			'schedule_snapshots' => array( 'stats', 'data' ),
			'agreements'         => array( 'follow_up' ),
			'audit_log'          => array( 'before_data', 'after_data' ),
		);

		/**
		 * Permite a los módulos declarar sus columnas JSON (tabla => columnas).
		 *
		 * @param array<string,array<int,string>> $columns Columnas JSON.
		 */
		return (array) apply_filters( 'gdp_data_json_columns', $columns );
	}

	/**
	 * Claves naturales con las que se reconoce un registro ya existente.
	 * Las tablas sin entrada se tratan como solo creación.
	 *
	 * @return array<string,string[]>
	 */
	public static function keys(): array {
		$keys = array(
			'projects'            => array( 'code' ),
			'permission_groups'   => array( 'project_id', 'slug' ),
			'project_members'     => array( 'project_id', 'user_id' ),
			'catalog_items'       => array( 'project_id', 'catalog', 'slug' ),
			'calendars'           => array( 'project_id', 'name' ),
			'calendar_exceptions' => array( 'calendar_id', 'exception_date' ),
			'activities'          => array( 'project_id', 'code' ),
			'dependencies'        => array( 'project_id', 'predecessor_type', 'predecessor_id', 'successor_id' ),
			'assignments'         => array( 'project_id', 'activity_id', 'user_id' ),
			'progress'            => array( 'project_id', 'activity_id', 'reported_at', 'percent' ),
			'baselines'           => array( 'project_id', 'name' ),
			'baseline_activities' => array( 'baseline_id', 'activity_id' ),
			'suppliers'           => array( 'project_id', 'name' ),
			'budget_lines'        => array( 'project_id', 'line_code' ),
			'uf_rates'            => array( 'rate_date' ),
			'documents'           => array( 'project_id', 'type', 'doc_number' ),
			'document_versions'   => array( 'document_id', 'version_no' ),
			'purchases'           => array( 'project_id', 'code' ),
			'quotes'              => array( 'purchase_id', 'supplier_id', 'quote_number' ),
			'quote_items'         => array( 'quote_id', 'sort_order' ),
			'purchase_stages'     => array( 'purchase_id', 'stage', 'stage_date' ),
			'meetings'            => array( 'project_id', 'code' ),
			'meeting_attendees'   => array( 'meeting_id', 'user_id', 'name' ),
			'agreements'          => array( 'project_id', 'code' ),
			'links'               => array( 'project_id', 'from_type', 'from_id', 'to_type', 'to_id', 'relation_type' ),
			'external_refs'       => array( 'project_id', 'entity_type', 'entity_id', 'system_name' ),
		);

		/**
		 * Permite declarar claves naturales de tablas de otros módulos.
		 *
		 * @param array<string,string[]> $keys Claves.
		 */
		return (array) apply_filters( 'gdp_data_keys', $keys );
	}

	/**
	 * Columnas de clave natural que, vacías, impiden reconocer el registro
	 * (por ejemplo, una actividad sin código siempre se crea).
	 *
	 * @return array<string,string[]>
	 */
	public static function key_required(): array {
		return array(
			'activities' => array( 'code' ),
			'documents'  => array( 'doc_number' ),
			'quotes'     => array( 'quote_number' ),
		);
	}

	/**
	 * Columnas que no se comparan al decidir si un registro cambió.
	 *
	 * @return string[]
	 */
	public static function volatile_columns(): array {
		return array( 'id', 'version', 'created_at', 'updated_at', 'fetched_at', 'created_by', 'updated_by', 'uploaded_by', 'start_date', 'end_date', 'late_start', 'late_finish', 'total_float', 'free_float', 'is_critical', 'schedule_conflicts', 'current_version' );
	}

	/**
	 * Columnas con datos personales que se vacían en una exportación anonimizada.
	 *
	 * @return array<string,string[]>
	 */
	public static function personal_columns(): array {
		$columns = array(
			'suppliers'         => array( 'contact_name', 'email', 'phone' ),
			'meeting_attendees' => array( 'name', 'email' ),
			'agreements'        => array( 'owner_name' ),
			'audit_log'         => array( 'ip', 'before_data', 'after_data' ),
		);

		/**
		 * Permite a los módulos declarar columnas con datos personales (tabla => columnas).
		 *
		 * @param array<string,array<int,string>> $columns Columnas personales.
		 */
		return (array) apply_filters( 'gdp_data_personal_columns', $columns );
	}

	/**
	 * Columnas con montos individuales que se vacían en una exportación anonimizada.
	 *
	 * @return array<string,string[]>
	 */
	public static function amount_columns(): array {
		$columns = array(
			'projects'     => array( 'budget_total' ),
			'activities'   => array( 'cost_planned' ),
			'baseline_activities' => array( 'cost_planned' ),
			'budget_lines' => array( 'assigned_clp' ),
			'purchases'    => array( 'amount_net', 'amount_total', 'amount_clp' ),
			'quotes'       => array( 'amount_net', 'amount_total' ),
			'quote_items'  => array( 'unit_price', 'line_total' ),
		);

		/**
		 * Permite a los módulos declarar sus columnas de montos (tabla => columnas).
		 *
		 * @param array<string,array<int,string>> $columns Columnas de montos.
		 */
		return (array) apply_filters( 'gdp_data_amount_columns', $columns );
	}

	/**
	 * Claves de projects.settings que no deben salir del sitio.
	 *
	 * @return string[]
	 */
	public static function secret_settings(): array {
		return array( 'ics_key' );
	}

	/**
	 * Columnas de cada tabla según las definiciones del esquema: nombre => tipo SQL.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function columns(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( Schema::definitions() as $short => $sql ) {
			$cache[ $short ] = array();
			if ( ! preg_match( '/\((.*)\)\s*[^()]*;?\s*$/s', $sql, $m ) ) {
				continue;
			}
			foreach ( preg_split( '/\r?\n/', $m[1] ) as $line ) {
				$line = trim( $line, " \t," );
				if ( '' === $line || preg_match( '/^(PRIMARY|UNIQUE|KEY|INDEX|FULLTEXT)\b/i', $line ) ) {
					continue;
				}
				if ( preg_match( '/^([a-z_][a-z0-9_]*)\s+([a-z]+(?:\([^)]*\))?)(.*)$/i', $line, $c ) ) {
					$type = strtolower( $c[2] ) . ( false !== stripos( $c[3], 'unsigned' ) ? ' unsigned' : '' );
					$cache[ $short ][ $c[1] ] = $type;
					self::$nullable[ $short ][ $c[1] ] = false === stripos( $c[3], 'NOT NULL' );
				}
			}
		}

		return $cache;
	}

	/**
	 * Columnas que admiten NULL: tabla => columna => bool.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private static $nullable = array();

	/**
	 * Indica si una columna admite NULL.
	 *
	 * @param string $table  Tabla.
	 * @param string $column Columna.
	 * @return bool
	 */
	public static function nullable( string $table, string $column ): bool {
		self::columns();

		return (bool) ( self::$nullable[ $table ][ $column ] ?? true );
	}

	/**
	 * Tipo lógico de una columna a partir del tipo SQL.
	 *
	 * @param string $sql_type Tipo SQL.
	 * @return string integer|decimal|boolean|date|datetime|time|text|json
	 */
	public static function logical_type( string $sql_type ): string {
		if ( preg_match( '/^tinyint\(1\)/', $sql_type ) ) {
			return 'boolean';
		}
		if ( preg_match( '/^(tiny|small|medium|big)?int/', $sql_type ) ) {
			return 'integer';
		}
		if ( 0 === strpos( $sql_type, 'decimal' ) || 0 === strpos( $sql_type, 'double' ) || 0 === strpos( $sql_type, 'float' ) ) {
			return 'decimal';
		}
		if ( 'date' === $sql_type ) {
			return 'date';
		}
		if ( 'datetime' === $sql_type ) {
			return 'datetime';
		}
		if ( 'time' === $sql_type ) {
			return 'time';
		}

		return 'text';
	}

	/**
	 * Indica si una tabla tiene columna id autoincremental.
	 *
	 * @param string $table Tabla.
	 * @return bool
	 */
	public static function has_id( string $table ): bool {
		$cols = self::columns();

		return isset( $cols[ $table ]['id'] );
	}

	/**
	 * Tabla de una entidad polimórfica.
	 *
	 * @param string $entity_type Tipo.
	 * @return string|null
	 */
	public static function entity_table( string $entity_type ): ?string {
		return self::entity_tables()[ $entity_type ] ?? null;
	}
}
