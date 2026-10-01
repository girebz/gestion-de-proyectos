<?php
/**
 * Repositorio de documentos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Core\Audit;
use GDP\Core\Catalogs;
use GDP\Core\Schema;
use GDP\Domain\Projects\ProjectRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cartas, oficios, contratos, órdenes y demás documentos de un proyecto, con
 * numeración correlativa por tipo, plazo de respuesta, responsable y control
 * optimista de versión. Los archivos viven en VersionRepository; los vínculos
 * con otras entidades en LinkRepository; las referencias externas en
 * ExternalRefRepository.
 */
final class DocumentRepository {

	public const STATUSES   = array( 'borrador', 'enviado', 'recibido', 'respondido', 'aprobado', 'cerrado', 'anulado' );
	public const DIRECTIONS = array( 'out', 'in', 'internal' );
	public const OPEN       = array( 'borrador', 'enviado', 'recibido' );

	/**
	 * Etiquetas de estado.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'borrador'   => __( 'Borrador', 'gestion-de-proyectos' ),
			'enviado'    => __( 'Enviado', 'gestion-de-proyectos' ),
			'recibido'   => __( 'Recibido', 'gestion-de-proyectos' ),
			'respondido' => __( 'Respondido', 'gestion-de-proyectos' ),
			'aprobado'   => __( 'Aprobado', 'gestion-de-proyectos' ),
			'cerrado'    => __( 'Cerrado', 'gestion-de-proyectos' ),
			'anulado'    => __( 'Anulado', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiquetas de sentido.
	 *
	 * @return array<string,string>
	 */
	public static function direction_labels(): array {
		return array(
			'out'      => __( 'Enviado por el proyecto', 'gestion-de-proyectos' ),
			'in'       => __( 'Recibido', 'gestion-de-proyectos' ),
			'internal' => __( 'Interno', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Tipos de documento del catálogo, con su configuración (numerado, sentido, prefijo).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,array{slug:string,label:string,numbered:bool,direction:string,prefix:string}>
	 */
	public static function types( int $project_id ): array {
		$types = array();
		foreach ( Catalogs::items( Catalogs::DOCUMENT_TYPE, $project_id ) as $item ) {
			$meta                   = is_array( $item['meta'] ?? null ) ? $item['meta'] : array();
			$types[ $item['slug'] ] = array(
				'slug'      => (string) $item['slug'],
				'label'     => (string) $item['label'],
				'numbered'  => ! empty( $meta['numbered'] ),
				'direction' => in_array( $meta['direction'] ?? '', self::DIRECTIONS, true ) ? (string) $meta['direction'] : 'internal',
				'prefix'    => ! empty( $meta['prefix'] ) ? (string) $meta['prefix'] : Numbering::default_prefix( (string) $item['slug'] ),
			);
		}

		return $types;
	}

	/**
	 * Un documento.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'documents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Documentos de un proyecto con filtros.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $filters    type, status, direction, overdue (bool), open (bool), owner_id, activity_id, search, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, array $filters = array() ): array {
		global $wpdb;

		$table = Schema::table( 'documents' );
		$where = array( 'project_id = %d' );
		$args  = array( $project_id );

		if ( ! empty( $filters['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = (string) $filters['type'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['direction'] ) ) {
			$where[] = 'direction = %s';
			$args[]  = (string) $filters['direction'];
		}
		if ( ! empty( $filters['owner_id'] ) ) {
			$where[] = 'owner_id = %d';
			$args[]  = (int) $filters['owner_id'];
		}
		if ( ! empty( $filters['activity_id'] ) ) {
			$where[] = 'activity_id = %d';
			$args[]  = (int) $filters['activity_id'];
		}
		if ( ! empty( $filters['open'] ) ) {
			$where[] = "status IN ('borrador','enviado','recibido')";
		}
		if ( ! empty( $filters['overdue'] ) ) {
			$where[] = "response_due IS NOT NULL AND response_due < %s AND status IN ('enviado','recibido')";
			$args[]  = current_time( 'Y-m-d' );
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(subject LIKE %s OR doc_number LIKE %s OR sender LIKE %s OR recipient LIKE %s OR body LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$limit = isset( $filters['limit'] ) ? max( 1, min( 1000, (int) $filters['limit'] ) ) : 500;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY COALESCE(doc_date, '1970-01-01') DESC, id DESC LIMIT {$limit}", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Número de documentos del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return int
	 */
	public static function count( int $project_id ): int {
		global $wpdb;

		$table = Schema::table( 'documents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id = %d", $project_id ) );
	}

	/**
	 * Resumen para la ficha del proyecto y el panel.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{total:int,open:int,overdue:int,due_soon:int,by_status:array<string,int>}
	 */
	public static function stats( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'documents' );
		$today = current_time( 'Y-m-d' );
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE project_id = %d GROUP BY status", $project_id ), ARRAY_A );
		$by   = array();
		$tot  = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$by[ $r['status'] ] = (int) $r['n'];
			$tot               += (int) $r['n'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$overdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id = %d AND response_due IS NOT NULL AND response_due < %s AND status IN ('enviado','recibido')", $project_id, $today ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$due_soon = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id = %d AND response_due IS NOT NULL AND response_due >= %s AND response_due <= %s AND status IN ('enviado','recibido')", $project_id, $today, $soon ) );

		return array(
			'total'     => $tot,
			'open'      => ( $by['borrador'] ?? 0 ) + ( $by['enviado'] ?? 0 ) + ( $by['recibido'] ?? 0 ),
			'overdue'   => $overdue,
			'due_soon'  => $due_soon,
			'by_status' => $by,
		);
	}

	/**
	 * Documentos con respuesta pendiente, vencida o por vencer, de todos los proyectos o de uno.
	 *
	 * @param int|null $project_id Proyecto (null = todos).
	 * @param int      $within     Días hacia adelante para "por vencer".
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending_responses( ?int $project_id = null, int $within = 7 ): array {
		global $wpdb;

		$table = Schema::table( 'documents' );
		$limit = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $within . ' days' ) );
		$sql   = "SELECT * FROM {$table} WHERE response_due IS NOT NULL AND response_due <= %s AND status IN ('enviado','recibido')";
		$args  = array( $limit );
		if ( null !== $project_id ) {
			$sql   .= ' AND project_id = %d';
			$args[] = $project_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY response_due ASC', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Valida y normaliza los campos.
	 *
	 * @param array<string,mixed> $data       Datos.
	 * @param int                 $project_id Proyecto.
	 * @param int                 $exclude_id Documento que se edita (para unicidad del número).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $project_id, int $exclude_id = 0 ) {
		$clean  = array();
		$errors = new WP_Error();
		$types  = self::types( $project_id );

		if ( array_key_exists( 'type', $data ) ) {
			$type = sanitize_key( (string) $data['type'] );
			if ( '' === $type || ! isset( $types[ $type ] ) ) {
				$errors->add( 'type', __( 'El tipo de documento no está en el catálogo.', 'gestion-de-proyectos' ) );
			} else {
				$clean['type'] = $type;
			}
		}
		if ( array_key_exists( 'direction', $data ) && '' !== (string) $data['direction'] ) {
			$direction = sanitize_key( (string) $data['direction'] );
			if ( ! in_array( $direction, self::DIRECTIONS, true ) ) {
				$errors->add( 'direction', __( 'El sentido del documento no es válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['direction'] = $direction;
			}
		}
		if ( array_key_exists( 'subject', $data ) ) {
			$clean['subject'] = sanitize_text_field( (string) $data['subject'] );
			if ( '' === $clean['subject'] ) {
				$errors->add( 'subject', __( 'El asunto es obligatorio.', 'gestion-de-proyectos' ) );
			}
		}
		foreach ( array( 'sender', 'recipient' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}
		if ( array_key_exists( 'number', $data ) ) {
			$clean['number'] = sanitize_text_field( (string) $data['number'] );
			if ( '' !== $clean['number'] && self::number_exists( $project_id, $clean['number'], $exclude_id ) ) {
				$errors->add( 'number', __( 'Ya existe un documento con ese número en el proyecto.', 'gestion-de-proyectos' ) );
			}
		}
		foreach ( array( 'body', 'notes' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = wp_kses_post( (string) $data[ $field ] );
			}
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				$errors->add( 'status', __( 'El estado no es válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['status'] = $status;
			}
		}
		foreach ( array( 'doc_date', 'response_due', 'responded_at' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$date = \GDP\Modules\Planning\ActivityRepository::normalize_date( $data[ $field ] );
				if ( false === $date ) {
					$errors->add( $field, __( 'Fecha no válida (use AAAA-MM-DD).', 'gestion-de-proyectos' ) );
				} else {
					$clean[ $field ] = $date;
				}
			}
		}
		if ( array_key_exists( 'owner_id', $data ) ) {
			$owner = (int) $data['owner_id'];
			if ( $owner > 0 && ! get_userdata( $owner ) ) {
				$errors->add( 'owner_id', __( 'El responsable no existe.', 'gestion-de-proyectos' ) );
			} else {
				$clean['owner_id'] = max( 0, $owner );
			}
		}
		if ( array_key_exists( 'activity_id', $data ) ) {
			$activity_id = (int) $data['activity_id'];
			if ( $activity_id > 0 ) {
				$activity = \GDP\Modules\Planning\ActivityRepository::find( $activity_id );
				if ( ! $activity || $activity['project_id'] !== $project_id ) {
					$errors->add( 'activity_id', __( 'La actividad no existe en este proyecto.', 'gestion-de-proyectos' ) );
				}
			}
			$clean['activity_id'] = max( 0, $activity_id );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea un documento; asigna número correlativo a los tipos numerados si no se indica uno.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$data  = array_merge( array( 'type' => 'otro', 'status' => 'borrador', 'number' => '', 'doc_date' => current_time( 'Y-m-d' ) ), $data );
		$clean = self::validate( $data, $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['subject'] ) ) {
			return new WP_Error( 'required', __( 'El asunto es obligatorio.', 'gestion-de-proyectos' ) );
		}
		$types = self::types( $project_id );
		$type  = $types[ $clean['type'] ];
		if ( empty( $clean['direction'] ) ) {
			$clean['direction'] = $type['direction'];
		}
		$clean['sequence'] = 0;
		if ( '' === ( $clean['number'] ?? '' ) && $type['numbered'] ) {
			$next              = self::next_number( $project_id, $clean['type'], $clean['doc_date'] ?? null );
			$clean['sequence'] = $next['sequence'];
			$clean['number']   = $next['number'];
		}

		$now                 = current_time( 'mysql', true );
		$clean['project_id'] = $project_id;
		$clean['version']    = 1;
		$clean['created_by'] = get_current_user_id();
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( Schema::table( 'documents' ), self::to_row( $clean ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar el documento.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		Audit::log( 'document', $id, 'create', $project_id, sprintf( 'Documento creado: %s %s', $clean['number'], $clean['subject'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza un documento con control optimista de versión.
	 *
	 * @param int                 $id               Documento.
	 * @param array<string,mixed> $data             Campos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El documento no existe.', 'gestion-de-proyectos' ) );
		}
		if ( null !== $expected_version && $expected_version !== $current['version'] ) {
			return new WP_Error( 'version_conflict', sprintf( 'El documento cambió (versión %d, se esperaba %d). Vuelva a leerlo antes de modificarlo.', $current['version'], $expected_version ) );
		}
		$clean = self::validate( $data, $current['project_id'], $id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean ) ) {
			return $current;
		}
		if ( isset( $clean['type'] ) && $clean['type'] !== $current['type'] && '' === ( $clean['number'] ?? $current['number'] ) ) {
			$types = self::types( $current['project_id'] );
			if ( ! empty( $types[ $clean['type'] ]['numbered'] ) ) {
				$next              = self::next_number( $current['project_id'], $clean['type'], $clean['doc_date'] ?? $current['doc_date'] );
				$clean['sequence'] = $next['sequence'];
				$clean['number']   = $next['number'];
			}
		}
		$clean['version']    = $current['version'] + 1;
		$clean['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( Schema::table( 'documents' ), self::to_row( $clean ), array( 'id' => $id ) );
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar el documento.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = self::find( $id );
		Audit::log( 'document', $id, 'update', $current['project_id'], sprintf( 'Documento actualizado: %s', $updated['subject'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Elimina un documento con sus versiones, vínculos y referencias.
	 *
	 * @param int $id Documento.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El documento no existe.', 'gestion-de-proyectos' ) );
		}
		VersionRepository::park( $id );
		LinkRepository::delete_for_entity( 'document', $id );
		ExternalRefRepository::delete_for_entity( 'document', $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'documents' ), array( 'id' => $id ) );
		Audit::log( 'document', $id, 'delete', $current['project_id'], sprintf( 'Documento eliminado: %s %s', $current['number'], $current['subject'] ), $current, null );

		return true;
	}

	/**
	 * Instantánea completa para poder revertir una eliminación.
	 *
	 * @param int $id Documento.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $id ): ?array {
		$doc = self::find( $id );
		if ( ! $doc ) {
			return null;
		}

		return array(
			'document' => $doc,
			'versions' => VersionRepository::for_document( $id ),
			'links'    => LinkRepository::rows_for_entity( 'document', $id ),
			'refs'     => ExternalRefRepository::for_entity( 'document', $id ),
		);
	}

	/**
	 * Restaura un documento desde su instantánea (mismo identificador).
	 *
	 * @param array<string,mixed> $snapshot Instantánea.
	 * @return bool|WP_Error
	 */
	public static function restore( array $snapshot ) {
		global $wpdb;

		$doc = $snapshot['document'] ?? null;
		if ( ! is_array( $doc ) || empty( $doc['id'] ) ) {
			return new WP_Error( 'snapshot', __( 'La instantánea del documento está incompleta.', 'gestion-de-proyectos' ) );
		}
		if ( self::find( (int) $doc['id'] ) ) {
			return new WP_Error( 'exists', __( 'El documento ya existe; no se puede restaurar sobre él.', 'gestion-de-proyectos' ) );
		}
		$row = array_intersect_key( $doc, array_flip( array( 'id', 'project_id', 'type', 'direction', 'sequence', 'number', 'doc_date', 'sender', 'recipient', 'subject', 'body', 'status', 'response_due', 'responded_at', 'owner_id', 'activity_id', 'notes', 'current_version', 'version', 'created_by', 'created_at', 'updated_at' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'documents' ), self::to_row( $row ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo restaurar el documento.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		VersionRepository::restore( (int) $doc['id'], (array) ( $snapshot['versions'] ?? array() ) );
		LinkRepository::restore( (array) ( $snapshot['links'] ?? array() ) );
		ExternalRefRepository::restore( (array) ( $snapshot['refs'] ?? array() ) );
		Audit::log( 'document', (int) $doc['id'], 'restore', (int) $doc['project_id'], sprintf( 'Documento restaurado: %s %s', $doc['number'], $doc['subject'] ), null, self::find( (int) $doc['id'] ) );

		return true;
	}

	/**
	 * Siguiente correlativo y número con formato para un tipo.
	 *
	 * @param int         $project_id Proyecto.
	 * @param string      $type       Tipo.
	 * @param string|null $date       Fecha del documento (para el año).
	 * @return array{sequence:int,number:string}
	 */
	public static function next_number( int $project_id, string $type, ?string $date = null ): array {
		global $wpdb;

		$project = ProjectRepository::find( $project_id );
		$pattern = (string) ( $project['settings']['documents']['pattern'] ?? Numbering::DEFAULT_PATTERN );
		$types   = self::types( $project_id );
		$prefix  = $types[ $type ]['prefix'] ?? Numbering::default_prefix( $type );
		$year    = (int) substr( $date ? $date : current_time( 'Y-m-d' ), 0, 4 );
		$table   = Schema::table( 'documents' );

		if ( Numbering::yearly( $pattern ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) FROM {$table} WHERE project_id = %d AND type = %s AND doc_date >= %s AND doc_date <= %s", $project_id, $type, $year . '-01-01', $year . '-12-31' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) FROM {$table} WHERE project_id = %d AND type = %s", $project_id, $type ) );
		}
		$sequence = $max + 1;
		$number   = Numbering::format( $pattern, $prefix, $sequence, $year, (string) ( $project['code'] ?? '' ) );
		while ( self::number_exists( $project_id, $number ) ) {
			++$sequence;
			$number = Numbering::format( $pattern, $prefix, $sequence, $year, (string) ( $project['code'] ?? '' ) );
		}

		return array( 'sequence' => $sequence, 'number' => $number );
	}

	/**
	 * Indica si un número ya está en uso en el proyecto.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $number     Número.
	 * @param int    $exclude_id Documento a excluir.
	 * @return bool
	 */
	public static function number_exists( int $project_id, string $number, int $exclude_id = 0 ): bool {
		global $wpdb;

		$table = Schema::table( 'documents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id = %d AND doc_number = %s AND id <> %d", $project_id, $number, $exclude_id ) ) > 0;
	}

	/**
	 * Marca la versión de archivo vigente.
	 *
	 * @param int $id         Documento.
	 * @param int $version_no Versión.
	 * @return void
	 */
	public static function set_current_version( int $id, int $version_no ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'documents' ), array( 'current_version' => $version_no, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	/**
	 * Elimina todos los documentos de un proyecto (al borrar el proyecto).
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		foreach ( self::for_project( $project_id, array( 'limit' => 1000 ) ) as $doc ) {
			self::delete( (int) $doc['id'] );
		}
	}

	/**
	 * Traduce las claves públicas a columnas (seq_no, doc_number evitan palabras reservadas).
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>
	 */
	private static function to_row( array $data ): array {
		if ( array_key_exists( 'sequence', $data ) ) {
			$data['seq_no'] = (int) $data['sequence'];
			unset( $data['sequence'] );
		}
		if ( array_key_exists( 'number', $data ) ) {
			$data['doc_number'] = (string) $data['number'];
			unset( $data['number'] );
		}

		return $data;
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['sequence'] = $row['seq_no'] ?? 0;
		$row['number']   = (string) ( $row['doc_number'] ?? '' );
		unset( $row['seq_no'], $row['doc_number'] );
		foreach ( array( 'id', 'project_id', 'sequence', 'owner_id', 'activity_id', 'current_version', 'version', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'doc_date', 'response_due', 'responded_at' ) as $date ) {
			$row[ $date ] = $row[ $date ] ? (string) $row[ $date ] : null;
		}
		$row['body']  = (string) $row['body'];
		$row['notes'] = (string) $row['notes'];

		return $row;
	}
}
