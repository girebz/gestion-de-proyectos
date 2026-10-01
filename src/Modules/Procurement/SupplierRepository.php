<?php
/**
 * Repositorio de proveedores.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Audit;
use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Proveedores con contacto; un proveedor puede ser global (project_id 0,
 * visible en todos los proyectos) o propio de un proyecto.
 */
final class SupplierRepository {

	/**
	 * Un proveedor.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'suppliers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Proveedores visibles en un proyecto (globales y propios).
	 *
	 * @param int    $project_id  Proyecto.
	 * @param bool   $only_active Solo activos.
	 * @param string $search      Texto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, bool $only_active = false, string $search = '' ): array {
		global $wpdb;

		$table = Schema::table( 'suppliers' );
		$where = array( '(project_id = 0 OR project_id = %d)' );
		$args  = array( $project_id );
		if ( $only_active ) {
			$where[] = 'active = 1';
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(name LIKE %s OR tax_id LIKE %s OR contact_name LIKE %s OR category LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Valida y normaliza.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data ) {
		$clean  = array();
		$errors = new WP_Error();
		if ( array_key_exists( 'name', $data ) ) {
			$clean['name'] = sanitize_text_field( (string) $data['name'] );
			if ( '' === $clean['name'] ) {
				$errors->add( 'name', __( 'El nombre del proveedor es obligatorio.', 'gestion-de-proyectos' ) );
			}
		}
		foreach ( array( 'tax_id', 'contact_name', 'phone', 'address', 'category' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}
		if ( array_key_exists( 'email', $data ) ) {
			$email = sanitize_email( (string) $data['email'] );
			if ( '' !== (string) $data['email'] && ! is_email( $email ) ) {
				$errors->add( 'email', __( 'El correo del proveedor no es válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['email'] = $email;
			}
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$clean['notes'] = wp_kses_post( (string) $data['notes'] );
		}
		if ( array_key_exists( 'active', $data ) ) {
			$clean['active'] = ! empty( $data['active'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'project_id', $data ) ) {
			$clean['project_id'] = max( 0, (int) $data['project_id'] );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea un proveedor.
	 *
	 * @param array<string,mixed> $data Datos (project_id 0 = global).
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$clean = self::validate( array_merge( array( 'active' => 1, 'project_id' => 0 ), $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['name'] ) ) {
			return new WP_Error( 'required', __( 'El nombre del proveedor es obligatorio.', 'gestion-de-proyectos' ) );
		}
		$now                 = current_time( 'mysql', true );
		$clean['created_by'] = get_current_user_id();
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'suppliers' ), $clean ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el proveedor.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		Audit::log( 'supplier', $id, 'create', (int) $clean['project_id'], sprintf( 'Proveedor creado: %s', $clean['name'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza un proveedor.
	 *
	 * @param int                 $id   Proveedor.
	 * @param array<string,mixed> $data Campos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El proveedor no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean ) ) {
			return $current;
		}
		$clean['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( Schema::table( 'suppliers' ), $clean, array( 'id' => $id ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar el proveedor.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = self::find( $id );
		Audit::log( 'supplier', $id, 'update', (int) $current['project_id'], sprintf( 'Proveedor actualizado: %s', $updated['name'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Elimina un proveedor sin compras ni cotizaciones.
	 *
	 * @param int $id Proveedor.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El proveedor no existe.', 'gestion-de-proyectos' ) );
		}
		$purchases = Schema::table( 'purchases' );
		$quotes    = Schema::table( 'quotes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$purchases} WHERE supplier_id = %d) + (SELECT COUNT(*) FROM {$quotes} WHERE supplier_id = %d)", $id, $id ) );
		if ( $used > 0 ) {
			return new WP_Error( 'in_use', __( 'El proveedor tiene compras o cotizaciones; desactívelo en lugar de eliminarlo.', 'gestion-de-proyectos' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'suppliers' ), array( 'id' => $id ) );
		Audit::log( 'supplier', $id, 'delete', (int) $current['project_id'], sprintf( 'Proveedor eliminado: %s', $current['name'] ), $current, null );

		return true;
	}

	/**
	 * Reinserta un proveedor (restauración).
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return bool
	 */
	public static function restore( array $row ): bool {
		global $wpdb;

		$row = array_intersect_key( $row, array_flip( array( 'id', 'project_id', 'name', 'tax_id', 'contact_name', 'email', 'phone', 'address', 'category', 'notes', 'active', 'created_by', 'created_at', 'updated_at' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->insert( Schema::table( 'suppliers' ), $row );
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'active', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['notes'] = (string) $row['notes'];

		return $row;
	}
}
