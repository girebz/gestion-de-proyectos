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
