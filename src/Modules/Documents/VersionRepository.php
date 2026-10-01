<?php
/**
 * Versiones de archivo de un documento.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Core\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada subida es una versión numerada del documento; los archivos se guardan
 * en el directorio privado del plugin (documentos/<proyecto>/<documento>/) y
 * se entregan solo a quien tenga el permiso documents.view del proyecto. Al
 * eliminar un documento los archivos se apartan en una papelera de disco para
 * poder restaurarlos.
 */
final class VersionRepository {

	public const BASE       = 'documentos';
	public const TRASH      = 'documentos/_papelera';
	public const MAX_BYTES  = 52428800;
	public const EXTENSIONS = array( 'pdf', 'doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'txt', 'md', 'tex', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'xml', 'json' );

	/**
	 * Registra el filtro de acceso a archivos.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'gdp_file_access', array( self::class, 'file_access' ), 10, 3 );
	}

	/**
	 * Decide si el usuario puede descargar un archivo del módulo.
	 *
	 * @param bool   $allowed  Decisión previa.
	 * @param string $relative Ruta relativa.
	 * @param int    $user_id  Usuario.
	 * @return bool
	 */
	public static function file_access( bool $allowed, string $relative, int $user_id ): bool {
		if ( ! preg_match( '#^' . preg_quote( self::BASE, '#' ) . '/(\d+)/#', $relative, $m ) ) {
			return $allowed;
		}

		return Access::can( 'documents.view', (int) $m[1], $user_id );
	}

	/**
	 * Versiones de un documento, de la más antigua a la más reciente.
	 *
	 * @param int $document_id Documento.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_document( int $document_id ): array {
		global $wpdb;

		$table = Schema::table( 'document_versions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE document_id = %d ORDER BY version_no ASC", $document_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Una versión.
	 *
	 * @param int $id Versión.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'document_versions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Guarda un archivo subido como nueva versión.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @param array<string,mixed> $file     Entrada de $_FILES.
	 * @param string              $note     Nota de la versión.
	 * @return array<string,mixed>|WP_Error La versión creada.
	 */
	public static function store_upload( array $document, array $file, string $note = '' ) {
		global $wpdb;

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'upload', __( 'No se recibió ningún archivo.', 'gestion-de-proyectos' ) );
		}
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload', __( 'La subida falló; revise el tamaño máximo permitido por el servidor.', 'gestion-de-proyectos' ) );
		}
		$size = (int) ( $file['size'] ?? filesize( (string) $file['tmp_name'] ) );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'size', __( 'El archivo está vacío o supera los 50 MB.', 'gestion-de-proyectos' ) );
		}
		$original = sanitize_file_name( (string) ( $file['name'] ?? 'archivo' ) );
		$check    = wp_check_filetype_and_ext( (string) $file['tmp_name'], $original );
		$ext      = strtolower( (string) ( $check['ext'] ? $check['ext'] : pathinfo( $original, PATHINFO_EXTENSION ) ) );
		if ( ! in_array( $ext, self::EXTENSIONS, true ) ) {
			return new WP_Error( 'type', __( 'Tipo de archivo no admitido.', 'gestion-de-proyectos' ) );
		}
		if ( ! Storage::ensure_private_dir() ) {
			return new WP_Error( 'storage', __( 'El directorio privado no es escribible.', 'gestion-de-proyectos' ) );
		}

		$version_no = 1;
		foreach ( self::for_document( (int) $document['id'] ) as $v ) {
			$version_no = max( $version_no, $v['version_no'] + 1 );
		}
		$dir      = self::dir( (int) $document['project_id'], (int) $document['id'] );
		$relative = self::BASE . '/' . (int) $document['project_id'] . '/' . (int) $document['id'] . '/v' . $version_no . '-' . $original;
		$target   = trailingslashit( Storage::private_dir() ) . $relative;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'storage', __( 'No se pudo crear la carpeta del documento.', 'gestion-de-proyectos' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $target ) ) {
			return new WP_Error( 'storage', __( 'No se pudo guardar el archivo.', 'gestion-de-proyectos' ) );
		}
		$row = array(
			'document_id' => (int) $document['id'],
			'version_no'  => $version_no,
			'filename'    => $original,
			'path'        => $relative,
			'mime'        => (string) ( $check['type'] ? $check['type'] : 'application/octet-stream' ),
			'byte_size'   => $size,
			'sha256'      => (string) hash_file( 'sha256', $target ),
			'note'        => sanitize_text_field( $note ),
			'uploaded_by' => get_current_user_id(),
			'created_at'  => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'document_versions' ), $row ) ) {
			wp_delete_file( $target );
			return new WP_Error( 'db', __( 'No se pudo registrar la versión.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$row['id'] = (int) $wpdb->insert_id;
		$row['size'] = $size;
		DocumentRepository::set_current_version( (int) $document['id'], $version_no );
		Audit::log( 'document', (int) $document['id'], 'version', (int) $document['project_id'], sprintf( 'Versión %d de %s: %s', $version_no, $document['number'] ? $document['number'] : $document['subject'], $original ), null, $row );

		return self::hydrate( $row );
	}

	/**
	 * Enlace de descarga controlada.
	 *
	 * @param array<string,mixed> $version Versión.
	 * @return string
	 */
	public static function download_url( array $version ): string {
		return Storage::url( (string) $version['path'] );
	}

	/**
	 * Aparta los archivos de un documento en la papelera de disco y borra sus filas.
	 *
	 * @param int $document_id Documento.
	 * @return void
	 */
	public static function park( int $document_id ): void {
		global $wpdb;

		$doc = DocumentRepository::find( $document_id );
		if ( $doc ) {
			$from = self::dir( (int) $doc['project_id'], $document_id );
			$to   = trailingslashit( Storage::private_dir() ) . self::TRASH . '/' . (int) $doc['project_id'] . '/' . $document_id;
			if ( is_dir( $from ) ) {
				wp_mkdir_p( dirname( $to ) );
				if ( is_dir( $to ) ) {
					self::remove_dir( $to );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $from, $to );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'document_versions' ), array( 'document_id' => $document_id ) );
	}

	/**
	 * Devuelve los archivos desde la papelera de disco y reinserta las filas.
	 *
	 * @param int                            $document_id Documento (ya restaurado).
	 * @param array<int,array<string,mixed>> $rows        Filas de la instantánea.
	 * @return void
	 */
	public static function restore( int $document_id, array $rows ): void {
		global $wpdb;

		$doc = DocumentRepository::find( $document_id );
		if ( $doc ) {
			$from = trailingslashit( Storage::private_dir() ) . self::TRASH . '/' . (int) $doc['project_id'] . '/' . $document_id;
			$to   = self::dir( (int) $doc['project_id'], $document_id );
			if ( is_dir( $from ) && ! is_dir( $to ) ) {
				wp_mkdir_p( dirname( $to ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $from, $to );
			}
		}
		foreach ( $rows as $row ) {
			$row = (array) $row;
			if ( array_key_exists( 'size', $row ) ) {
				$row['byte_size'] = (int) $row['size'];
			}
			$row = array_intersect_key( $row, array_flip( array( 'id', 'document_id', 'version_no', 'filename', 'path', 'mime', 'byte_size', 'sha256', 'note', 'uploaded_by', 'created_at' ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( Schema::table( 'document_versions' ), $row );
		}
	}

	/**
	 * Carpeta absoluta de un documento.
	 *
	 * @param int $project_id  Proyecto.
	 * @param int $document_id Documento.
	 * @return string
	 */
	public static function dir( int $project_id, int $document_id ): string {
		return trailingslashit( Storage::private_dir() ) . self::BASE . '/' . $project_id . '/' . $document_id;
	}

	/**
	 * Borra una carpeta y su contenido.
	 *
	 * @param string $dir Carpeta.
	 * @return void
	 */
	private static function remove_dir( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::remove_dir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['size'] = $row['byte_size'] ?? ( $row['size'] ?? 0 );
		unset( $row['byte_size'] );
		foreach ( array( 'id', 'document_id', 'version_no', 'size', 'uploaded_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$user                 = get_userdata( $row['uploaded_by'] );
		$row['uploaded_name'] = $user ? $user->display_name : '';

		return $row;
	}
}
