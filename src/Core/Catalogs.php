<?php
/**
 * Catálogos configurables.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

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
