<?php
/**
 * Vínculos entre entidades.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tabla genérica de relaciones: un documento responde a otro, se refiere a
 * una actividad, respalda una compra o una reunión. Cada módulo declara sus
 * entidades enlazables con el filtro gdp_link_entities (etiqueta y función que
 * resuelve identificador → título, código y enlace), de modo que los vínculos
 * se muestran con nombre en cualquier pantalla.
 */
final class LinkRepository {

	public const RELATIONS = array( 'responds_to', 'refers_to', 'supports', 'related' );

	/**
	 * Etiquetas de relación (lectura desde la entidad origen).
	 *
	 * @return array<string,string>
	 */
	public static function relation_labels(): array {
		return array(
			'responds_to' => __( 'responde a', 'gestion-de-proyectos' ),
			'refers_to'   => __( 'se refiere a', 'gestion-de-proyectos' ),
			'supports'    => __( 'respalda', 'gestion-de-proyectos' ),
			'related'     => __( 'se relaciona con', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de relación leídas desde la entidad destino.
	 *
	 * @return array<string,string>
	 */
	public static function inverse_labels(): array {
		return array(
			'responds_to' => __( 'es respondido por', 'gestion-de-proyectos' ),
			'refers_to'   => __( 'es referido por', 'gestion-de-proyectos' ),
			'supports'    => __( 'es respaldado por', 'gestion-de-proyectos' ),
			'related'     => __( 'se relaciona con', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Entidades enlazables declaradas por los módulos.
	 *
	 * @return array<string,array{label:string,resolve:callable,search?:callable}>
	 */
	public static function entities(): array {
		/**
		 * Declara entidades enlazables: tipo => [label, resolve(id): array{title,code,url}|null, search(project_id, text): list].
		 *
		 * @param array<string,array<string,mixed>> $entities Entidades.
		 */
		$entities = (array) apply_filters( 'gdp_link_entities', array() );
		$out      = array();
		foreach ( $entities as $type => $def ) {
			if ( is_array( $def ) && isset( $def['label'], $def['resolve'] ) && is_callable( $def['resolve'] ) ) {
				$out[ (string) $type ] = $def;
			}
		}

		return $out;
	}

	/**
	 * Resuelve una entidad a título, código y enlace.
	 *
	 * @param string $type Tipo.
	 * @param int    $id   Identificador.
	 * @return array{type:string,id:int,label:string,title:string,code:string,url:string}
	 */
	public static function resolve( string $type, int $id ): array {
		$entities = self::entities();
		$info     = null;
		if ( isset( $entities[ $type ] ) ) {
			$info = call_user_func( $entities[ $type ]['resolve'], $id );
		}

		return array(
			'type'  => $type,
			'id'    => $id,
			'label' => (string) ( $entities[ $type ]['label'] ?? $type ),
			'title' => is_array( $info ) ? (string) ( $info['title'] ?? '' ) : sprintf( '#%d', $id ),
			'code'  => is_array( $info ) ? (string) ( $info['code'] ?? '' ) : '',
			'url'   => is_array( $info ) ? (string) ( $info['url'] ?? '' ) : '',
		);
	}

	/**
	 * Vínculos de una entidad en ambos sentidos, con la otra entidad resuelta.
	 *
	 * @param string $type Tipo.
	 * @param int    $id   Identificador.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_entity( string $type, int $id ): array {
		$out = array();
		foreach ( self::rows_for_entity( $type, $id ) as $row ) {
			$outgoing = $row['from_type'] === $type && $row['from_id'] === $id;
			$other    = $outgoing ? self::resolve( $row['to_type'], $row['to_id'] ) : self::resolve( $row['from_type'], $row['from_id'] );
			$labels   = $outgoing ? self::relation_labels() : self::inverse_labels();
			$out[]    = array(
				'id'             => $row['id'],
				'relation'       => $row['relation'],
				'relation_label' => $labels[ $row['relation'] ] ?? $row['relation'],
				'outgoing'       => $outgoing,
				'note'           => $row['note'],
				'entity'         => $other,
			);
		}

		return $out;
	}

	/**
	 * Filas brutas de una entidad (para instantáneas).
	 *
	 * @param string $type Tipo.
	 * @param int    $id   Identificador.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_for_entity( string $type, int $id ): array {
		global $wpdb;

		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE (from_type = %s AND from_id = %d) OR (to_type = %s AND to_id = %d) ORDER BY id ASC", $type, $id, $type, $id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Un vínculo.
	 *
	 * @param int $id Vínculo.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Crea un vínculo (idempotente: si ya existe el mismo, devuelve su id).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $from_type  Tipo origen.
	 * @param int    $from_id    Origen.
	 * @param string $to_type    Tipo destino.
	 * @param int    $to_id      Destino.
	 * @param string $relation   Relación.
	 * @param string $note       Nota.
	 * @return int|WP_Error
	 */
	public static function add( int $project_id, string $from_type, int $from_id, string $to_type, int $to_id, string $relation = 'refers_to', string $note = '' ) {
		global $wpdb;

		$entities = self::entities();
		if ( ! isset( $entities[ $from_type ], $entities[ $to_type ] ) ) {
			return new WP_Error( 'entity', __( 'Tipo de entidad no enlazable.', 'gestion-de-proyectos' ) );
		}
		if ( ! in_array( $relation, self::RELATIONS, true ) ) {
			return new WP_Error( 'relation', __( 'Relación no válida.', 'gestion-de-proyectos' ) );
		}
		if ( $from_type === $to_type && $from_id === $to_id ) {
			return new WP_Error( 'self', __( 'Una entidad no puede vincularse consigo misma.', 'gestion-de-proyectos' ) );
		}
		$target = call_user_func( $entities[ $to_type ]['resolve'], $to_id );
		if ( ! is_array( $target ) ) {
			return new WP_Error( 'not_found', __( 'La entidad de destino no existe.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $target['project_id'] ) && (int) $target['project_id'] !== $project_id ) {
			return new WP_Error( 'project', __( 'La entidad de destino pertenece a otro proyecto.', 'gestion-de-proyectos' ) );
		}
		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE from_type = %s AND from_id = %d AND to_type = %s AND to_id = %d AND relation_type = %s", $from_type, $from_id, $to_type, $to_id, $relation ) );
		if ( $existing > 0 ) {
			return $existing;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'project_id' => $project_id,
				'from_type'  => $from_type,
				'from_id'    => $from_id,
				'to_type'    => $to_type,
				'to_id'      => $to_id,
				'relation_type' => $relation,
				'note'          => sanitize_text_field( $note ),
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);

		return false === $ok ? new WP_Error( 'db', __( 'No se pudo guardar el vínculo.', 'gestion-de-proyectos' ) ) : (int) $wpdb->insert_id;
	}

	/**
	 * Elimina un vínculo.
	 *
	 * @param int $id Vínculo.
	 * @return array<string,mixed>|null La fila eliminada.
	 */
	public static function remove( int $id ): ?array {
		global $wpdb;

		$row = self::find( $id );
		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Schema::table( 'links' ), array( 'id' => $id ) );
		}

		return $row;
	}

	/**
	 * Elimina todos los vínculos de una entidad.
	 *
	 * @param string $type Tipo.
	 * @param int    $id   Identificador.
	 * @return void
	 */
	public static function delete_for_entity( string $type, int $id ): void {
		global $wpdb;

		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE (from_type = %s AND from_id = %d) OR (to_type = %s AND to_id = %d)", $type, $id, $type, $id ) );
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
			$row = (array) $row;
			if ( array_key_exists( 'relation', $row ) ) {
				$row['relation_type'] = (string) $row['relation'];
			}
			$row = array_intersect_key( $row, array_flip( array( 'id', 'project_id', 'from_type', 'from_id', 'to_type', 'to_id', 'relation_type', 'note', 'created_by', 'created_at' ) ) );
			if ( ! empty( $row['id'] ) && self::find( (int) $row['id'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( Schema::table( 'links' ), $row );
		}
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['relation'] = (string) ( $row['relation_type'] ?? ( $row['relation'] ?? 'refers_to' ) );
		unset( $row['relation_type'] );
		foreach ( array( 'id', 'project_id', 'from_id', 'to_id', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}

		return $row;
	}
}
