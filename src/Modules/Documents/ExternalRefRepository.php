<?php
/**
 * Referencias en sistemas externos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Número y estado que una entidad del plugin tiene en un sistema
 * institucional (gestor documental de la universidad, plataforma del
 * financiador, portal de compras), con enlace. Tabla genérica: cualquier
 * entidad (documento, compra) puede tener varias referencias, una por sistema.
 */
final class ExternalRefRepository {

	/**
	 * Referencias de una entidad.
	 *
	 * @param string $type Tipo.
	 * @param int    $id   Identificador.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_entity( string $type, int $id ): array {
		global $wpdb;

		$table = Schema::table( 'external_refs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d ORDER BY system_name ASC", $type, $id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Una referencia.
	 *
	 * @param int $id Referencia.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'external_refs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Crea o actualiza la referencia de una entidad en un sistema.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $type       Tipo de entidad.
	 * @param int    $id         Entidad.
	 * @param string $system     Sistema.
	 * @param string $number     Número en el sistema.
	 * @param string $status     Estado en el sistema.
	 * @param string $url        Enlace.
	 * @return int|WP_Error Identificador de la referencia.
	 */
	public static function set( int $project_id, string $type, int $id, string $system, string $number = '', string $status = '', string $url = '' ) {
		global $wpdb;

		$system = sanitize_text_field( $system );
		if ( '' === $system ) {
			return new WP_Error( 'system', __( 'Indique el sistema externo.', 'gestion-de-proyectos' ) );
		}
		$url = esc_url_raw( $url );
		if ( '' !== $url && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
			return new WP_Error( 'url', __( 'El enlace no es válido.', 'gestion-de-proyectos' ) );
		}
		$table = Schema::table( 'external_refs' );
		$data  = array(
			'ref_number' => sanitize_text_field( $number ),
			'ref_status' => sanitize_text_field( $status ),
			'url'        => $url,
			'updated_by' => get_current_user_id(),
			'updated_at' => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE entity_type = %s AND entity_id = %d AND system_name = %s", $type, $id, $system ) );
		if ( $existing > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $existing ) );
			return $existing;
		}
		$data += array(
			'project_id'  => $project_id,
			'entity_type' => $type,
			'entity_id'   => $id,
			'system_name' => $system,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $table, $data );

		return false === $ok ? new WP_Error( 'db', __( 'No se pudo guardar la referencia.', 'gestion-de-proyectos' ) ) : (int) $wpdb->insert_id;
	}

	/**
	 * Elimina una referencia.
	 *
	 * @param int $id Referencia.
	 * @return array<string,mixed>|null La fila eliminada.
	 */
	public static function remove( int $id ): ?array {
		global $wpdb;

		$row = self::find( $id );
		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Schema::table( 'external_refs' ), array( 'id' => $id ) );
		}

		return $row;
	}

	/**
	 * Elimina las referencias de una entidad.
	 *
	 * @param string $type Tipo.
	 * @param int    $id   Identificador.
	 * @return void
	 */
	public static function delete_for_entity( string $type, int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'external_refs' ), array( 'entity_type' => $type, 'entity_id' => $id ) );
	}

	/**
	 * Reinserta filas (restauración).
	 *
	 * @param array<int,array<string,mixed>> $rows Filas.
	 * @return void
	 */
	public static function restore( array $rows ): void {
		global $wpdb;

		foreach ( $rows as $row ) {
			$row = array_intersect_key( (array) $row, array_flip( array( 'id', 'project_id', 'entity_type', 'entity_id', 'system_name', 'ref_number', 'ref_status', 'url', 'updated_by', 'updated_at' ) ) );
			if ( ! empty( $row['id'] ) && self::find( (int) $row['id'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( Schema::table( 'external_refs' ), $row );
		}
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'entity_id', 'updated_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}

		return $row;
	}
}
