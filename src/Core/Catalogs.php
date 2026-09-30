<?php
/**
 * Catálogos configurables.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Vocabulario configurable del plugin (tipos de documento, etapas de compra,
 * partidas, frentes de trabajo, medios de verificación, criterios de
 * acreditación). Los catálogos globales (project_id = 0) sirven de base y cada
 * proyecto puede añadir o desactivar entradas propias.
 */
final class Catalogs {

	public const DOCUMENT_TYPE      = 'document_type';
	public const PROCUREMENT_STAGE  = 'procurement_stage';
	public const PROJECT_STATUS     = 'project_status';
	public const WORK_FRONT         = 'work_front';
	public const BUDGET_LINE        = 'budget_line';
	public const VERIFICATION_MEANS = 'verification_means';
	public const ACCREDITATION      = 'accreditation_criterion';

	/**
	 * Entradas globales por defecto.
	 *
	 * @return array<string,array<int,array{slug:string,label:string,meta?:array<string,mixed>}>>
	 */
	public static function defaults(): array {
		return array(
			self::PROJECT_STATUS    => array(
				array( 'slug' => 'planificacion', 'label' => __( 'En planificación', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'ejecucion', 'label' => __( 'En ejecución', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'suspendido', 'label' => __( 'Suspendido', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'cierre', 'label' => __( 'En cierre', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'cerrado', 'label' => __( 'Cerrado', 'gestion-de-proyectos' ) ),
			),
			self::DOCUMENT_TYPE     => array(
				array( 'slug' => 'carta', 'label' => __( 'Carta enviada', 'gestion-de-proyectos' ), 'meta' => array( 'direction' => 'out', 'numbered' => true ) ),
				array( 'slug' => 'oficio', 'label' => __( 'Oficio recibido', 'gestion-de-proyectos' ), 'meta' => array( 'direction' => 'in' ) ),
				array( 'slug' => 'contrato', 'label' => __( 'Contrato', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'orden_compra', 'label' => __( 'Orden de compra', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'cotizacion', 'label' => __( 'Cotización', 'gestion-de-proyectos' ), 'meta' => array( 'direction' => 'in' ) ),
				array( 'slug' => 'factura', 'label' => __( 'Factura', 'gestion-de-proyectos' ), 'meta' => array( 'direction' => 'in' ) ),
				array( 'slug' => 'acta', 'label' => __( 'Acta', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'informe', 'label' => __( 'Informe', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'otro', 'label' => __( 'Otro', 'gestion-de-proyectos' ) ),
			),
			self::PROCUREMENT_STAGE => array(
				array( 'slug' => 'solicitud_cotizacion', 'label' => __( 'Solicitud de cotización', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'cotizacion_recibida', 'label' => __( 'Cotización recibida', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'seguimiento', 'label' => __( 'Seguimiento', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'eleccion', 'label' => __( 'Elección', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'solicitud_interna', 'label' => __( 'Solicitud interna', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'orden_compra', 'label' => __( 'Orden de compra', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'factura', 'label' => __( 'Factura', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'pago', 'label' => __( 'Pago', 'gestion-de-proyectos' ) ),
			),
			self::VERIFICATION_MEANS => array(
				array( 'slug' => 'fotografias', 'label' => __( 'Registro fotográfico', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'certificado', 'label' => __( 'Certificado de aprobación o participación', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'lista_asistencia', 'label' => __( 'Lista de asistencia', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'programa', 'label' => __( 'Programa de actividades', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'material', 'label' => __( 'Material entregado', 'gestion-de-proyectos' ) ),
				array( 'slug' => 'informe', 'label' => __( 'Informe', 'gestion-de-proyectos' ) ),
			),
		);
	}

	/**
	 * Inserta las entradas globales que falten (idempotente).
	 *
	 * @return void
	 */
	public static function seed_defaults(): void {
		global $wpdb;

		$table = Schema::table( 'catalog_items' );
		$now   = current_time( 'mysql', true );

		foreach ( self::defaults() as $catalog => $items ) {
			foreach ( $items as $index => $item ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = 0 AND catalog = %s AND slug = %s", $catalog, $item['slug'] ) );
				if ( $exists > 0 ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$table,
					array(
						'project_id' => 0,
						'catalog'    => $catalog,
						'slug'       => $item['slug'],
						'label'      => $item['label'],
						'sort_order' => (int) $index,
						'meta'       => isset( $item['meta'] ) ? wp_json_encode( $item['meta'], JSON_UNESCAPED_UNICODE ) : null,
						'active'     => 1,
						'created_at' => $now,
						'updated_at' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Entradas activas de un catálogo, combinando las globales con las del proyecto.
	 *
	 * @param string $catalog    Catálogo.
	 * @param int    $project_id Proyecto (0 = solo globales).
	 * @return array<int,array<string,mixed>>
	 */
	public static function items( string $catalog, int $project_id = 0 ): array {
		global $wpdb;

		$table = Schema::table( 'catalog_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE catalog = %s AND active = 1 AND (project_id = 0 OR project_id = %d) ORDER BY sort_order ASC, label ASC",
				$catalog,
				$project_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Una entrada del proyecto con el mismo slug sustituye a la global.
		$by_slug = array();
		foreach ( $rows as $row ) {
			$slug = (string) $row['slug'];
			if ( ! isset( $by_slug[ $slug ] ) || (int) $row['project_id'] > 0 ) {
				$row['meta']      = self::decode( $row['meta'] );
				$by_slug[ $slug ] = $row;
			}
		}

		return array_values( $by_slug );
	}

	/**
	 * Nombres visibles de los catálogos editables.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::WORK_FRONT         => __( 'Frentes de trabajo', 'gestion-de-proyectos' ),
			self::BUDGET_LINE        => __( 'Partidas presupuestarias', 'gestion-de-proyectos' ),
			self::DOCUMENT_TYPE      => __( 'Tipos de documento', 'gestion-de-proyectos' ),
			self::PROCUREMENT_STAGE  => __( 'Etapas de compra', 'gestion-de-proyectos' ),
			self::VERIFICATION_MEANS => __( 'Medios de verificación', 'gestion-de-proyectos' ),
			self::ACCREDITATION      => __( 'Criterios de acreditación', 'gestion-de-proyectos' ),
			self::PROJECT_STATUS     => __( 'Estados de proyecto', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Todas las entradas de un catálogo y ámbito (activas o no), sin combinar.
	 *
	 * @param string $catalog    Catálogo.
	 * @param int    $project_id Ámbito (0 = global).
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_items( string $catalog, int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'catalog_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE catalog = %s AND project_id = %d ORDER BY sort_order ASC, label ASC", $catalog, $project_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['id']         = (int) $row['id'];
			$row['project_id'] = (int) $row['project_id'];
			$row['sort_order'] = (int) $row['sort_order'];
			$row['active']     = ! empty( $row['active'] );
			$row['meta']       = self::decode( $row['meta'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Crea o actualiza una entrada de catálogo.
	 *
	 * @param string $catalog     Catálogo.
	 * @param int    $project_id  Ámbito (0 = global).
	 * @param string $slug        Clave (se genera del nombre si viene vacía).
	 * @param string $label       Nombre visible.
	 * @param string $description Descripción.
	 * @param int    $sort_order  Orden.
	 * @param bool   $active      Activa.
	 * @return int|WP_Error Identificador de la entrada.
	 */
	public static function save_item( string $catalog, int $project_id, string $slug, string $label, string $description = '', int $sort_order = 0, bool $active = true ) {
		global $wpdb;

		$catalog = sanitize_key( $catalog );
		if ( ! isset( self::labels()[ $catalog ] ) ) {
			return new WP_Error( 'catalog', __( 'Catálogo desconocido.', 'gestion-de-proyectos' ) );
		}
		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			return new WP_Error( 'label', __( 'La entrada necesita un nombre.', 'gestion-de-proyectos' ) );
		}
		$slug = sanitize_key( str_replace( '-', '_', sanitize_title( '' !== trim( $slug ) ? $slug : $label ) ) );
		if ( '' === $slug ) {
			return new WP_Error( 'slug', __( 'La clave no es válida.', 'gestion-de-proyectos' ) );
		}

		$table = Schema::table( 'catalog_items' );
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE project_id = %d AND catalog = %s AND slug = %s", $project_id, $catalog, $slug ) );

		$data = array(
			'label'       => $label,
			'description' => sanitize_textarea_field( $description ),
			'sort_order'  => $sort_order,
			'active'      => $active ? 1 : 0,
			'updated_at'  => $now,
		);
		if ( $existing > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $existing ), array( '%s', '%s', '%d', '%d', '%s' ), array( '%d' ) );
			Audit::log( 'catalog_item', $existing, 'update', $project_id, sprintf( 'Catálogo %s: %s', $catalog, $label ) );

			return $existing;
		}

		$data['project_id'] = $project_id;
		$data['catalog']    = $catalog;
		$data['slug']       = $slug;
		$data['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $table, $data, array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la entrada.', 'gestion-de-proyectos' ) );
		}
		$id = (int) $wpdb->insert_id;
		Audit::log( 'catalog_item', $id, 'create', $project_id, sprintf( 'Catálogo %s: %s', $catalog, $label ) );

		return $id;
	}

	/**
	 * Elimina una entrada de catálogo (los registros que la usan conservan la clave).
	 *
	 * @param int $id Entrada.
	 * @return bool
	 */
	public static function delete_item( int $id ): bool {
		global $wpdb;

		$table = Schema::table( 'catalog_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		Audit::log( 'catalog_item', $id, 'delete', (int) $row['project_id'], sprintf( 'Catálogo %s: %s', $row['catalog'], $row['label'] ), $row, null );

		return true;
	}

	/**
	 * Etiqueta de una entrada.
	 *
	 * @param string $catalog    Catálogo.
	 * @param string $slug       Entrada.
	 * @param int    $project_id Proyecto.
	 * @return string
	 */
	public static function label( string $catalog, string $slug, int $project_id = 0 ): string {
		foreach ( self::items( $catalog, $project_id ) as $item ) {
			if ( $item['slug'] === $slug ) {
				return (string) $item['label'];
			}
		}

		return $slug;
	}

	/**
	 * Decodifica el campo meta.
	 *
	 * @param mixed $meta JSON o null.
	 * @return array<string,mixed>
	 */
	private static function decode( $meta ): array {
		if ( ! is_string( $meta ) || '' === $meta ) {
			return array();
		}

		$decoded = json_decode( $meta, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
