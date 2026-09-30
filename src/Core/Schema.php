<?php
/**
 * Definición del esquema de base de datos del núcleo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Tablas propias del plugin (prefijo gdp_) y su instalación con dbDelta.
 *
 * Convenciones:
 * - Todo registro pertenece a un proyecto (project_id), salvo los catálogos
 *   globales (project_id = 0) y los tokens del conector (por usuario).
 * - Las columnas *_data almacenan JSON; nunca se consultan por su contenido.
 * - La columna version permite detectar conflictos en importaciones y en las
 *   operaciones propuestas por el conector (control optimista).
 */
final class Schema {

	/**
	 * Nombre completo de una tabla del plugin.
	 *
	 * @param string $short Nombre corto (por ejemplo, "projects").
	 * @return string
	 */
	public static function table( string $short ): string {
		global $wpdb;

		return $wpdb->prefix . 'gdp_' . $short;
	}

	/**
	 * Sentencias CREATE TABLE del núcleo, indexadas por nombre corto.
	 *
	 * @return array<string,string>
	 */
	public static function definitions(): array {
		global $wpdb;

		$collate  = $wpdb->get_charset_collate();
		$projects = self::table( 'projects' );
		$members  = self::table( 'project_members' );
		$audit    = self::table( 'audit_log' );
		$ops      = self::table( 'operations' );
		$tokens   = self::table( 'connector_tokens' );
		$catalog  = self::table( 'catalog_items' );

		return array_merge( self::core_definitions( $collate, $projects, $members, $audit, $ops, $tokens, $catalog ), self::planning_definitions( $collate ) );
	}

	/**
	 * Tablas del módulo de planificación y tiempo.
	 *
	 * Las fechas programadas (start_date, end_date, late_*, holguras, ruta
	 * crítica) son una caché del motor de programación: se recalculan tras cada
	 * cambio y nunca se editan a mano. Las fechas reales y las restricciones
	 * son datos de entrada.
	 *
	 * @param string $collate Cotejamiento.
	 * @return array<string,string>
	 */
	private static function planning_definitions( string $collate ): array {
		$activities   = self::table( 'activities' );
		$dependencies = self::table( 'dependencies' );
		$calendars    = self::table( 'calendars' );
		$exceptions   = self::table( 'calendar_exceptions' );
		$baselines    = self::table( 'baselines' );
		$baseline_act = self::table( 'baseline_activities' );
		$assignments  = self::table( 'assignments' );
		$progress     = self::table( 'progress' );

		return array(
			'activities'          => "CREATE TABLE {$activities} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
	code varchar(32) NOT NULL DEFAULT '',
	name varchar(255) NOT NULL,
	description longtext NULL,
	kind varchar(16) NOT NULL DEFAULT 'activity',
	work_front varchar(64) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'pendiente',
	priority tinyint(3) unsigned NOT NULL DEFAULT 2,
	sort_order int(11) NOT NULL DEFAULT 0,
	duration int(10) unsigned NOT NULL DEFAULT 1,
	constraint_type varchar(8) NOT NULL DEFAULT 'asap',
	constraint_date date NULL,
	actual_start date NULL,
	actual_finish date NULL,
	percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
	deliverable varchar(255) NOT NULL DEFAULT '',
	budget_line varchar(64) NOT NULL DEFAULT '',
	cost_planned decimal(18,2) NULL,
	notes longtext NULL,
	start_date date NULL,
	end_date date NULL,
	late_start date NULL,
	late_finish date NULL,
	total_float int(11) NULL,
	free_float int(11) NULL,
	is_critical tinyint(1) NOT NULL DEFAULT 0,
	schedule_conflicts text NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY parent (project_id,parent_id,sort_order),
	KEY status (project_id,status),
	KEY owner_id (owner_id),
	KEY end_date (end_date)
) {$collate};",

			'dependencies'        => "CREATE TABLE {$dependencies} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	predecessor_type varchar(32) NOT NULL DEFAULT 'activity',
	predecessor_id bigint(20) unsigned NOT NULL,
	successor_id bigint(20) unsigned NOT NULL,
	type char(2) NOT NULL DEFAULT 'FS',
	lag_days int(11) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY pair (project_id,predecessor_type,predecessor_id,successor_id),
	KEY successor_id (successor_id)
) {$collate};",

			'calendars'           => "CREATE TABLE {$calendars} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(100) NOT NULL,
	weekdays varchar(20) NOT NULL DEFAULT '1,2,3,4,5',
	is_default tinyint(1) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id)
) {$collate};",

			'calendar_exceptions' => "CREATE TABLE {$exceptions} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	calendar_id bigint(20) unsigned NOT NULL,
	exception_date date NOT NULL,
	working tinyint(1) NOT NULL DEFAULT 0,
	label varchar(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY calendar_date (calendar_id,exception_date)
) {$collate};",

			'baselines'           => "CREATE TABLE {$baselines} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	name varchar(100) NOT NULL,
	description text NULL,
	is_current tinyint(1) NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id)
) {$collate};",

			'baseline_activities' => "CREATE TABLE {$baseline_act} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	baseline_id bigint(20) unsigned NOT NULL,
	activity_id bigint(20) unsigned NOT NULL,
	code varchar(32) NOT NULL DEFAULT '',
	name varchar(255) NOT NULL DEFAULT '',
	kind varchar(16) NOT NULL DEFAULT 'activity',
	start_date date NULL,
	end_date date NULL,
	duration int(10) unsigned NOT NULL DEFAULT 0,
	percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	cost_planned decimal(18,2) NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY baseline_activity (baseline_id,activity_id)
) {$collate};",

			'assignments'         => "CREATE TABLE {$assignments} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	activity_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	role varchar(32) NOT NULL DEFAULT 'participante',
	allocation tinyint(3) unsigned NOT NULL DEFAULT 100,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY activity_user (activity_id,user_id),
	KEY project_user (project_id,user_id)
) {$collate};",

			'progress'            => "CREATE TABLE {$progress} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	activity_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	reported_at datetime NOT NULL,
	percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	previous_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT '',
	actual_start date NULL,
	actual_finish date NULL,
	note text NULL,
	source varchar(20) NOT NULL DEFAULT 'admin',
	PRIMARY KEY  (id),
	KEY activity_id (activity_id),
	KEY project_reported (project_id,reported_at)
) {$collate};",
		);
	}

	/**
	 * Tablas del núcleo.
	 *
	 * @param string $collate  Cotejamiento.
	 * @param string $projects Tabla de proyectos.
	 * @param string $members  Tabla de miembros.
	 * @param string $audit    Tabla de bitácora.
	 * @param string $ops      Tabla de operaciones.
	 * @param string $tokens   Tabla de tokens.
	 * @param string $catalog  Tabla de catálogos.
	 * @return array<string,string>
	 */
	private static function core_definitions( string $collate, string $projects, string $members, string $audit, string $ops, string $tokens, string $catalog ): array {
		return array(
			'projects'         => "CREATE TABLE {$projects} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	code varchar(64) NOT NULL,
	name varchar(255) NOT NULL,
	short_name varchar(100) NOT NULL DEFAULT '',
	description longtext NULL,
	funder varchar(255) NOT NULL DEFAULT '',
	funding_code varchar(100) NOT NULL DEFAULT '',
	executing_entity varchar(255) NOT NULL DEFAULT '',
	status varchar(32) NOT NULL DEFAULT 'planificacion',
	start_date date NULL,
	end_date date NULL,
	budget_total decimal(18,2) NULL,
	currency char(3) NOT NULL DEFAULT 'CLP',
	settings longtext NULL,
	version int(10) unsigned NOT NULL DEFAULT 1,
	created_by bigint(20) unsigned NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY status (status)
) {$collate};",

			'project_members'  => "CREATE TABLE {$members} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	role varchar(32) NOT NULL DEFAULT 'observador',
	active tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY project_user (project_id,user_id),
	KEY user_id (user_id)
) {$collate};",

			'audit_log'        => "CREATE TABLE {$audit} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	channel varchar(20) NOT NULL DEFAULT 'admin',
	entity_type varchar(64) NOT NULL,
	entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
	action varchar(32) NOT NULL,
	summary varchar(255) NOT NULL DEFAULT '',
	before_data longtext NULL,
	after_data longtext NULL,
	operation_id bigint(20) unsigned NOT NULL DEFAULT 0,
	ip varchar(45) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY project_id (project_id),
	KEY entity (entity_type,entity_id),
	KEY user_id (user_id),
	KEY created_at (created_at)
) {$collate};",

			'operations'       => "CREATE TABLE {$ops} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	channel varchar(20) NOT NULL DEFAULT 'connector',
	handler varchar(64) NOT NULL,
	action varchar(32) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'proposed',
	summary varchar(255) NOT NULL DEFAULT '',
	payload longtext NULL,
	preview longtext NULL,
	before_data longtext NULL,
	result longtext NULL,
	error text NULL,
	created_at datetime NOT NULL,
	expires_at datetime NULL,
	applied_at datetime NULL,
	reverted_at datetime NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY project_id (project_id),
	KEY user_id (user_id)
) {$collate};",

			'connector_tokens' => "CREATE TABLE {$tokens} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL,
	label varchar(100) NOT NULL DEFAULT '',
	token_hash char(64) NOT NULL,
	token_prefix char(8) NOT NULL DEFAULT '',
	scopes varchar(255) NOT NULL DEFAULT 'read',
	last_used_at datetime NULL,
	last_ip varchar(45) NOT NULL DEFAULT '',
	expires_at datetime NULL,
	revoked_at datetime NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY user_id (user_id)
) {$collate};",

			'catalog_items'    => "CREATE TABLE {$catalog} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	project_id bigint(20) unsigned NOT NULL DEFAULT 0,
	catalog varchar(64) NOT NULL,
	slug varchar(100) NOT NULL,
	label varchar(255) NOT NULL,
	description text NULL,
	sort_order int(11) NOT NULL DEFAULT 0,
	meta longtext NULL,
	active tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY catalog_slug (project_id,catalog,slug),
	KEY catalog (catalog)
) {$collate};",
		);
	}

	/**
	 * Crea o actualiza las tablas con dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Elimina todas las tablas del plugin (solo en desinstalación con borrado de datos).
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( array_keys( self::definitions() ) as $short ) {
			$table = self::table( $short );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Indica si las tablas del núcleo existen.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::table( 'projects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}
}
