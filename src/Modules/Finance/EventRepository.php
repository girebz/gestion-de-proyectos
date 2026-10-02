<?php
/**
 * Eventos fechados: estados declarados y pasos cumplidos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Planning\ScheduleService;

defined( 'ABSPATH' ) || exit;

/**
 * La plataforma no ofrece un medio para leer sus estados; el módulo los
 * registra por declaración, con fecha, autor, nota y documento. Los pasos
 * de las hojas de ejecución se registran del mismo modo, de forma que
 * ejecutar y dejar constancia sean un solo acto.
 */
final class EventRepository extends Repository {

	protected const TABLE  = 'finance_events';
	protected const ENTITY = '';
	protected const CASTS  = array( 'entity_id' => 'int', 'event_date' => 'date', 'user_id' => 'int', 'document_id' => 'int' );

	/**
	 * Registra un evento.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $entity_type Entidad (rendition, installment, payment, project, guarantee, supplier).
	 * @param int    $entity_id   Identificador.
	 * @param string $kind        estado o paso.
	 * @param string $key         Estado o clave del paso.
	 * @param string $date        Fecha.
	 * @param string $note        Nota.
	 * @param int    $document_id Documento.
	 * @param string $guide       Guía (para los pasos).
	 * @return int
	 */
	public static function log( int $project_id, string $entity_type, int $entity_id, string $kind, string $key, string $date, string $note = '', int $document_id = 0, string $guide = '' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table(),
			array(
				'project_id'  => $project_id,
				'entity_type' => sanitize_key( $entity_type ),
				'entity_id'   => $entity_id,
				'kind'        => sanitize_key( $kind ),
				'event_key'   => sanitize_key( $key ),
				'guide'       => sanitize_key( $guide ),
				'event_date'  => $date,
				'user_id'     => get_current_user_id(),
				'note'        => sanitize_textarea_field( $note ),
				'document_id' => $document_id,
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		$id = (int) $wpdb->insert_id;
		if ( null !== self::$captured && $id > 0 ) {
			self::$captured[] = $id;
		}

		return $id;
	}

	/**
	 * Eventos registrados durante una captura (para revertir operaciones en cascada).
	 *
	 * @var int[]|null
	 */
	private static ?array $captured = null;

	/**
	 * Comienza a registrar los identificadores de los eventos que se crean.
	 *
	 * @return void
	 */
	public static function capture_start(): void {
		self::$captured = array();
	}

	/**
	 * Termina la captura y devuelve los identificadores creados.
	 *
	 * @return int[]
	 */
	public static function capture_stop(): array {
		$ids            = self::$captured ?? array();
		self::$captured = null;

		return $ids;
	}

	/**
	 * Elimina eventos por identificador.
	 *
	 * @param int[] $ids Identificadores.
	 * @return void
	 */
	public static function delete_ids( array $ids ): void {
		global $wpdb;

		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		}
	}

	/**
	 * Eventos de una entidad, del más reciente al más antiguo.
	 *
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @param string $kind        Filtro por tipo (vacío = todos).
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_entity( string $entity_type, int $entity_id, string $kind = '' ): array {
		global $wpdb;

		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d" . ( '' !== $kind ? ' AND kind = %s' : '' ) . ' ORDER BY event_date DESC, id DESC LIMIT 500';
		$args  = '' !== $kind ? array( $entity_type, $entity_id, $kind ) : array( $entity_type, $entity_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$r         = self::hydrate( $r );
			$r['user'] = ScheduleService::user_name( $r['user_id'] );
			$out[]     = $r;
		}

		return $out;
	}

	/**
	 * Pasos cumplidos de una guía sobre una entidad: clave => evento.
	 *
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @param string $guide       Guía.
	 * @return array<string,array<string,mixed>>
	 */
	public static function steps_done( string $entity_type, int $entity_id, string $guide ): array {
		$out = array();
		foreach ( self::for_entity( $entity_type, $entity_id, 'paso' ) as $e ) {
			if ( $e['guide'] === $guide && ! isset( $out[ $e['event_key'] ] ) ) {
				$out[ $e['event_key'] ] = $e;
			}
		}

		return $out;
	}

	/**
	 * Quita la constancia de un paso (lo deja pendiente).
	 *
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @param string $guide       Guía.
	 * @param string $step        Paso.
	 * @return void
	 */
	public static function undo_step( string $entity_type, int $entity_id, string $guide, string $step ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'kind' => 'paso', 'guide' => $guide, 'event_key' => $step ) );
	}

	/**
	 * Último evento de estado de una entidad.
	 *
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function last_status( string $entity_type, int $entity_id ): ?array {
		$events = self::for_entity( $entity_type, $entity_id, 'estado' );

		return $events[0] ?? null;
	}
}
