<?php
/**
 * Grupos de permisos a medida.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Un grupo es un perfil de proyecto definido por el administrador: nombre,
 * descripción y una selección explícita de permisos finos. Se asigna a los
 * miembros igual que los perfiles predefinidos (tabla de miembros, columna
 * role) y se resuelve en Roles::permissions_map(). Los grupos son globales
 * (project_id = 0); la columna se conserva para una futura definición por
 * proyecto.
 *
 * El editor advierte las combinaciones que filtran información: un grupo sin
 * finance.view que conserve los montos de las compras podría reconstruir la
 * ejecución financiera sumándolos.
 */
final class Groups {

	/**
	 * Caché de la petición.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Todos los grupos a medida, por slug.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		if ( null !== self::$cache ) {
			return self::$cache;
		}
		self::$cache = array();
		// La tabla existe desde la versión 7 del esquema; antes de migrar no se consulta.
		if ( ! isset( $wpdb ) || (int) get_option( Installer::OPTION_DB_VERSION, '0' ) < 7 ) {
			return self::$cache;
		}
		$table = Schema::table( 'permission_groups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY label ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return self::$cache;
		}
		foreach ( $rows as $row ) {
			$group                          = self::hydrate( $row );
			self::$cache[ $group['slug'] ] = $group;
		}

		return self::$cache;
	}

	/**
	 * Un grupo por slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public static function find( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	/**
	 * Un grupo por identificador.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_id( int $id ): ?array {
		foreach ( self::all() as $group ) {
			if ( $group['id'] === $id ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Convierte una fila en un grupo con tipos nativos.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$permissions = json_decode( (string) ( $row['permissions'] ?? '[]' ), true );
		$valid       = Roles::all_permissions();

		return array(
			'id'          => (int) $row['id'],
			'project_id'  => (int) $row['project_id'],
			'slug'        => (string) $row['slug'],
			'label'       => (string) $row['label'],
			'description' => (string) ( $row['description'] ?? '' ),
			'permissions' => array_values( array_intersect( is_array( $permissions ) ? array_map( 'strval', $permissions ) : array(), $valid ) ),
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
		);
	}

	/**
	 * Valida los datos de un grupo.
	 *
	 * @param array<string,mixed> $data       label, slug, description, permissions.
	 * @param int                 $exclude_id Grupo que se está editando.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $exclude_id = 0 ) {
		$label = sanitize_text_field( (string) ( $data['label'] ?? '' ) );
		if ( '' === $label ) {
			return new WP_Error( 'label', __( 'Indique el nombre del grupo.', 'gestion-de-proyectos' ) );
		}
		$slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_key( str_replace( ' ', '_', remove_accents( $label ) ) );
		}
		if ( '' === $slug || strlen( $slug ) > 64 ) {
			return new WP_Error( 'slug', __( 'El identificador del grupo no es válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( Roles::builtin_roles()[ $slug ] ) ) {
			return new WP_Error( 'slug', __( 'El identificador coincide con un perfil predefinido.', 'gestion-de-proyectos' ) );
		}
		$existing = self::find( $slug );
		if ( $existing && $existing['id'] !== $exclude_id ) {
			return new WP_Error( 'slug', __( 'Ya existe un grupo con ese identificador.', 'gestion-de-proyectos' ) );
		}
		$permissions = isset( $data['permissions'] ) && is_array( $data['permissions'] ) ? array_map( 'strval', $data['permissions'] ) : array();
		$permissions = array_values( array_intersect( Roles::all_permissions(), $permissions ) );

		return array(
			'slug'        => $slug,
			'label'       => $label,
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'permissions' => $permissions,
		);
	}

	/**
	 * Crea un grupo.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( Schema::table( 'permission_groups' ), array( 'project_id' => 0, 'slug' => $clean['slug'], 'label' => $clean['label'], 'description' => $clean['description'], 'permissions' => wp_json_encode( $clean['permissions'] ), 'created_at' => $now, 'updated_at' => $now ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el grupo.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		self::$cache = null;
		$id          = (int) $wpdb->insert_id;
		Audit::log( 'permission_group', $id, 'create', 0, sprintf( 'Grupo de permisos creado: %s (%d permisos)', $clean['label'], count( $clean['permissions'] ) ), null, $clean );

		return $id;
	}

	/**
	 * Actualiza un grupo. El slug no cambia, porque los miembros lo referencian.
	 *
	 * @param int                 $id   Grupo.
	 * @param array<string,mixed> $data Datos.
	 * @return bool|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$current = self::find_by_id( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El grupo no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( array_merge( $data, array( 'slug' => $current['slug'] ) ), $id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( Schema::table( 'permission_groups' ), array( 'label' => $clean['label'], 'description' => $clean['description'], 'permissions' => wp_json_encode( $clean['permissions'] ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar el grupo.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		self::$cache = null;
		Audit::log( 'permission_group', $id, 'update', 0, sprintf( 'Grupo de permisos actualizado: %s (%d permisos)', $clean['label'], count( $clean['permissions'] ) ), $current, $clean );

		return true;
	}

	/**
	 * Elimina un grupo; los miembros que lo tenían pasan a observadores.
	 *
	 * @param int $id Grupo.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find_by_id( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El grupo no existe.', 'gestion-de-proyectos' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'project_members' ), array( 'role' => 'observador' ), array( 'role' => $current['slug'] ), array( '%s' ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'permission_groups' ), array( 'id' => $id ), array( '%d' ) );
		self::$cache = null;
		Audit::log( 'permission_group', $id, 'delete', 0, sprintf( 'Grupo de permisos eliminado: %s', $current['label'] ), $current, null );

		return true;
	}

	/**
	 * Número de miembros asignados a un grupo, en todos los proyectos.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	public static function member_count( string $slug ): int {
		global $wpdb;

		$table = Schema::table( 'project_members' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE role = %s AND active = 1", $slug ) );
	}

	/**
	 * Advertencias sobre una selección de permisos: combinaciones que filtran
	 * información o que dejan permisos sin su permiso de lectura.
	 *
	 * @param string[] $permissions Permisos seleccionados.
	 * @return string[]
	 */
	public static function warnings( array $permissions ): array {
		$has = static fn( string $p ): bool => in_array( $p, $permissions, true );
		$out = array();

		if ( ! $has( 'finance.view' ) ) {
			if ( $has( 'procurement.view_amounts' ) ) {
				$out[] = __( 'El grupo no ve finanzas pero sí los montos de las compras: sumándolos podría reconstruir la ejecución financiera. Quite "Ver montos de compras y presupuesto" si la parte financiera debe quedar reservada.', 'gestion-de-proyectos' );
			}
			if ( $has( 'data.export' ) ) {
				$out[] = __( 'El grupo no ve finanzas pero puede exportar datos: la exportación completa incluye las tablas financieras del proyecto. Quite "Exportar datos" o limite la exportación.', 'gestion-de-proyectos' );
			}
			foreach ( array( 'finance.edit', 'finance.reconcile', 'finance.export', 'finance.rules' ) as $p ) {
				if ( $has( $p ) ) {
					$out[] = __( 'El grupo tiene permisos de edición o exportación financiera sin el permiso de ver finanzas; añada "Ver finanzas y rendición de cuentas".', 'gestion-de-proyectos' );
					break;
				}
			}
		}
		if ( $has( 'finance.view' ) && ! $has( 'procurement.view' ) ) {
			$out[] = __( 'El grupo ve finanzas pero no las compras: los pagos remiten a compras que no podrá abrir.', 'gestion-de-proyectos' );
		}
		foreach ( array( 'planning', 'documents', 'procurement', 'lab', 'meetings', 'evidence', 'publications' ) as $module ) {
			if ( ! $has( $module . '.view' ) ) {
				foreach ( $permissions as $p ) {
					if ( 0 === strpos( $p, $module . '.' ) && $p !== $module . '.view' ) {
						/* translators: nombre del módulo. */
						$out[] = sprintf( __( 'El grupo tiene permisos de %s sin el permiso de ver ese módulo.', 'gestion-de-proyectos' ), Roles::permission_modules()[ $module ] ?? $module );
						break;
					}
				}
			}
		}
		if ( ! $has( 'project.view' ) && ! empty( $permissions ) ) {
			$out[] = __( 'Sin "Ver la ficha del proyecto" el grupo no podrá entrar al proyecto, de modo que los demás permisos no tendrán efecto.', 'gestion-de-proyectos' );
		}

		return array_values( array_unique( $out ) );
	}
}
