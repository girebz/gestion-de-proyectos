<?php
/**
 * Desinstalación.
 *
 * Solo elimina datos si el administrador lo pidió expresamente en los ajustes
 * (uninstall_remove_data). En caso contrario, las tablas y ajustes se
 * conservan para poder reinstalar sin pérdida.
 *
 * @package GDP
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$gdp_settings = get_option( 'gdp_settings', array() );

if ( ! is_array( $gdp_settings ) || empty( $gdp_settings['uninstall_remove_data'] ) ) {
	return;
}

global $wpdb;

$gdp_tables = array(
	'gdp_projects',
	'gdp_project_members',
	'gdp_audit_log',
	'gdp_operations',
	'gdp_connector_tokens',
	'gdp_catalog_items',
	'gdp_activities',
	'gdp_dependencies',
	'gdp_calendars',
	'gdp_calendar_exceptions',
	'gdp_baselines',
	'gdp_baseline_activities',
	'gdp_assignments',
	'gdp_progress',
	'gdp_schedule_snapshots',
	'gdp_documents',
	'gdp_document_versions',
	'gdp_links',
	'gdp_external_refs',
	'gdp_suppliers',
	'gdp_purchases',
	'gdp_purchase_stages',
	'gdp_quotes',
	'gdp_quote_items',
	'gdp_budget_lines',
	'gdp_uf_rates',
);

/**
 * Permite a los módulos añadir sus tablas a la eliminación.
 *
 * @param string[] $gdp_tables Nombres cortos (con prefijo gdp_).
 */
$gdp_tables = (array) apply_filters( 'gdp_uninstall_tables', $gdp_tables );

foreach ( $gdp_tables as $gdp_table ) {
	$gdp_full = $wpdb->prefix . $gdp_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$gdp_full}" );
}

foreach ( array( 'gdp_settings', 'gdp_db_version', 'gdp_version', 'gdp_installed_at' ) as $gdp_option ) {
	delete_option( $gdp_option );
}

// Transitorios del plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gdp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_gdp\\_%'" );

// Roles y capacidades.
$gdp_admin = get_role( 'administrator' );
if ( $gdp_admin ) {
	$gdp_admin->remove_cap( 'gdp_manage' );
	$gdp_admin->remove_cap( 'gdp_access' );
	$gdp_admin->remove_cap( 'gdp_use_connector' );
}
remove_role( 'gdp_miembro' );

wp_clear_scheduled_hook( 'gdp_daily' );
wp_clear_scheduled_hook( 'gdp_weekly' );

// El directorio privado de adjuntos no se borra automáticamente: contiene
// documentos que el administrador debe conservar o eliminar a conciencia.
