<?php
/**
 * Registro de reglas por proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Finance\Profiles\Profiles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada regla tiene un valor, una fuente y una vigencia. Las del proyecto
 * prevalecen sobre las del perfil, y estas sobre los valores del núcleo;
 * cada valor efectivo indica de qué capa proviene.
 */
final class RulesRepository extends Repository {

	protected const TABLE  = 'finance_rules';
	protected const ENTITY = 'finance_rule';
	protected const CASTS  = array( 'valid_from' => 'date', 'valid_to' => 'date', 'updated_by' => 'int' );

	/**
	 * Reglas efectivas de un proyecto: clave => [value, source, label, type, layer, valid_from, valid_to, note].
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $profile    Slug del perfil.
	 * @param string $today      Fecha de referencia (vigencia).
	 * @return array<string,array<string,mixed>>
	 */
	public static function effective( int $project_id, string $profile, string $today = '' ): array {
		$today = '' !== $today ? $today : current_time( 'Y-m-d' );
		$out   = array();
		foreach ( Profiles::get( $profile )->rules() as $key => $rule ) {
			$out[ $key ] = $rule + array( 'layer' => 'perfil', 'valid_from' => null, 'valid_to' => null, 'note' => '' );
		}
		foreach ( self::for_project( $project_id, 'rule_key ASC' ) as $row ) {
			if ( ( $row['valid_from'] && $row['valid_from'] > $today ) || ( $row['valid_to'] && $row['valid_to'] < $today ) ) {
				continue;
			}
			$key         = (string) $row['rule_key'];
			$out[ $key ] = array(
				'value'      => (string) $row['value'],
				'source'     => (string) $row['source'],
				'label'      => $out[ $key ]['label'] ?? $key,
				'type'       => $out[ $key ]['type'] ?? 'text',
				'layer'      => 'convenio',
				'valid_from' => $row['valid_from'],
				'valid_to'   => $row['valid_to'],
				'note'       => (string) $row['note'],
				'id'         => $row['id'],
			);
		}

		return $out;
	}

	/**
	 * Valor efectivo de una regla.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $profile    Perfil.
	 * @param string $key        Clave.
	 * @param string $fallback   Valor si no existe.
	 * @return string
	 */
	public static function value( int $project_id, string $profile, string $key, string $fallback = '' ): string {
		static $cache = array();
		if ( ! isset( $cache[ $project_id ] ) ) {
			$cache[ $project_id ] = self::effective( $project_id, $profile );
		}

		return (string) ( $cache[ $project_id ][ $key ]['value'] ?? $fallback );
	}

	/**
	 * Fija (o sustituye) el valor de una regla en el proyecto.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       rule_key, value, source, valid_from, valid_to, note.
	 * @return int|WP_Error
	 */
	public static function set( int $project_id, array $data ) {
		global $wpdb;

		$errors = new WP_Error();
		$clean  = self::clean( $data, array( 'rule_key' => 'key', 'value' => 'text', 'source' => 'text', 'valid_from' => 'date', 'valid_to' => 'date', 'note' => 'textarea' ), $errors );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		if ( empty( $clean['rule_key'] ) ) {
			return new WP_Error( 'rule_key', __( 'Indique la clave de la regla.', 'gestion-de-proyectos' ) );
		}
		if ( ! array_key_exists( 'value', $clean ) ) {
			return new WP_Error( 'value', __( 'Indique el valor de la regla.', 'gestion-de-proyectos' ) );
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = %d AND rule_key = %s", $project_id, $clean['rule_key'] ) );
		$clean['updated_by'] = get_current_user_id();
		$clean['updated_at'] = current_time( 'mysql', true );
		if ( $existing > 0 ) {
			$before = self::find( $existing );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $clean, array( 'id' => $existing ) );
			\GDP\Core\Audit::log( self::ENTITY, $existing, 'update', $project_id, sprintf( 'Regla %s: %s', $clean['rule_key'], $clean['value'] ), $before, self::find( $existing ) );

			return $existing;
		}
		$clean['project_id'] = $project_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( $table, $clean ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la regla.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		\GDP\Core\Audit::log( self::ENTITY, $id, 'create', $project_id, sprintf( 'Regla %s: %s', $clean['rule_key'], $clean['value'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Vuelve una regla al valor del perfil (elimina la del proyecto).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $key        Clave.
	 * @return bool
	 */
	public static function reset( int $project_id, string $key ): bool {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = %d AND rule_key = %s", $project_id, sanitize_key( $key ) ) );

		return $id > 0 && self::delete_row( $id, sprintf( 'Regla %s devuelta al valor del perfil', $key ) );
	}
}
