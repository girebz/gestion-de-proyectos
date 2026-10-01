<?php
/**
 * Respaldos completos del sitio: datos, diccionario, SQL y adjuntos en un ZIP.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Core\Audit;
use GDP\Core\Options;
use GDP\Core\Schema;
use GDP\Core\Storage;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Un respaldo es un ZIP en el directorio privado (respaldos/) con datos.json
 * (todas las tablas, identificadores incluidos), datos.sql (sentencias
 * INSERT para MariaDB o MySQL), diccionario.json, meta.json y la carpeta
 * adjuntos/ con los archivos del directorio privado. La restauración vacía
 * las tablas y reinserta las filas tal cual; antes se crea un respaldo de
 * seguridad para poder revertirla.
 */
final class BackupService {

	public const DIR    = 'respaldos';
	public const PREFIX = 'respaldo-';

	/**
	 * Carpeta absoluta de respaldos.
	 *
	 * @return string
	 */
	public static function dir(): string {
		return trailingslashit( Storage::private_dir() ) . self::DIR;
	}

	/**
	 * Crea un respaldo.
	 *
	 * @param string $note    Nota.
	 * @param string $trigger manual, programado o seguridad.
	 * @return array<string,mixed>|WP_Error Metadatos del respaldo creado.
	 */
	public static function create( string $note = '', string $trigger = 'manual' ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para los respaldos.', 'gestion-de-proyectos' ) );
		}
		Storage::ensure_private_dir();
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'dir', __( 'No se pudo crear la carpeta de respaldos.', 'gestion-de-proyectos' ) );
		}
		$name = self::PREFIX . gmdate( 'Ymd-His' ) . ( 'seguridad' === $trigger ? '-seguridad' : '' ) . '.zip';
		$path = $dir . '/' . $name;
		$doc  = Exporter::site( array( 'attachments' => true ) );
		$meta = array(
			'name'           => $name,
			'created_at'     => gmdate( 'c' ),
			'plugin_version' => GDP_VERSION,
			'schema_version' => (int) GDP_DB_VERSION,
			'trigger'        => $trigger,
			'note'           => sanitize_text_field( $note ),
			'site'           => $doc['site'],
			'counts'         => $doc['counts'],
			'rows'           => array_sum( $doc['counts'] ),
			'projects'       => array_map( static fn( array $p ): string => (string) $p['code'], (array) ( $doc['tables']['projects'] ?? array() ) ),
			'attachments'    => count( Exporter::attachment_paths( $doc ) ),
			'created_by'     => wp_get_current_user()->user_login,
		);
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', __( 'No se pudo crear el archivo del respaldo.', 'gestion-de-proyectos' ) );
		}
		$zip->addFromString( 'datos.json', Exporter::to_json( $doc ) );
		$zip->addFromString( 'datos.sql', self::sql( $doc ) );
		$zip->addFromString( 'diccionario.json', (string) wp_json_encode( DataDictionary::build(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$zip->addFromString( 'LEEME.txt', self::readme( $meta ) );
		$base = trailingslashit( Storage::private_dir() );
		foreach ( Exporter::attachment_paths( $doc ) as $relative ) {
			if ( is_file( $base . $relative ) ) {
				$zip->addFile( $base . $relative, 'adjuntos/' . $relative );
			}
		}
		$zip->addFromString( 'meta.json', (string) wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$zip->close();
		$meta['size'] = (int) filesize( $path );
		$meta['path'] = $path;
		Audit::log( 'backup', 0, 'create', 0, sprintf( 'Respaldo creado: %s (%s)', $name, $trigger ), null, array( 'name' => $name, 'rows' => $meta['rows'], 'size' => $meta['size'] ) );
		self::copy_out( $path, $meta );
		/**
		 * Se dispara al crear un respaldo, con la ruta del ZIP, para copiarlo a un destino externo.
		 *
		 * @param string $path Ruta absoluta del ZIP.
		 * @param array  $meta Metadatos.
		 */
		do_action( 'gdp_backup_created', $path, $meta );

		return $meta;
	}

	/**
	 * Respaldos existentes, del más reciente al más antiguo.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( $dir . '/' . self::PREFIX . '*.zip' ) as $path ) {
			$meta = self::meta( basename( (string) $path ) );
			if ( $meta ) {
				$out[] = $meta;
			}
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( (string) $b['name'], (string) $a['name'] ) );

		return $out;
	}

	/**
	 * Metadatos de un respaldo.
	 *
	 * @param string $name Nombre del archivo.
	 * @return array<string,mixed>|null
	 */
	public static function meta( string $name ): ?array {
		$path = self::path( $name );
		if ( null === $path || ! is_file( $path ) || ! class_exists( ZipArchive::class ) ) {
			return null;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}
		$json = $zip->getFromName( 'meta.json' );
		$zip->close();
		$meta = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $meta ) ) {
			$meta = array( 'name' => $name, 'created_at' => gmdate( 'c', (int) filemtime( $path ) ), 'trigger' => '', 'note' => '', 'rows' => 0, 'counts' => array(), 'projects' => array(), 'attachments' => 0 );
		}
		$meta['name'] = $name;
		$meta['size'] = (int) filesize( $path );
		$meta['path'] = $path;

		return $meta;
	}

	/**
	 * Ruta absoluta validada de un respaldo.
	 *
	 * @param string $name Nombre.
	 * @return string|null
	 */
	public static function path( string $name ): ?string {
		$name = basename( $name );
		if ( ! preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[0-9]{8}-[0-9]{6}(-seguridad)?\.zip$/', $name ) ) {
			return null;
		}

		return self::dir() . '/' . $name;
	}

	/**
	 * Elimina un respaldo.
	 *
	 * @param string $name Nombre.
	 * @return bool|WP_Error
	 */
	public static function delete( string $name ) {
		$path = self::path( $name );
		if ( null === $path || ! is_file( $path ) ) {
			return new WP_Error( 'not_found', __( 'El respaldo no existe.', 'gestion-de-proyectos' ) );
		}
		wp_delete_file( $path );
		Audit::log( 'backup', 0, 'delete', 0, sprintf( 'Respaldo eliminado: %s', basename( $path ) ) );

		return true;
	}

	/**
	 * Restaura un respaldo (vacía las tablas y reinserta todo); antes crea un respaldo de seguridad.
	 *
	 * @param string $name Nombre.
	 * @return array<string,mixed>|WP_Error { safety: nombre del respaldo de seguridad, counts: filas por tabla }.
	 */
	public static function restore( string $name ) {
		$path = self::path( $name );
		if ( null === $path || ! is_file( $path ) ) {
			return new WP_Error( 'not_found', __( 'El respaldo no existe.', 'gestion-de-proyectos' ) );
		}
		$read = Importer::read_file( $path, true );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$safety = self::create( sprintf( 'Antes de restaurar %s', $name ), 'seguridad' );
		if ( is_wp_error( $safety ) ) {
			return $safety;
		}
		$counts = Importer::replace_all( $read['document'], $read['attachments'] );
		self::remove_dir( $read['attachments'] );
		if ( is_wp_error( $counts ) ) {
			return $counts;
		}
		Audit::log( 'backup', 0, 'restore', 0, sprintf( 'Respaldo restaurado: %s', $name ), array( 'safety' => $safety['name'] ), array( 'rows' => array_sum( $counts ) ) );

		return array( 'safety' => $safety['name'], 'counts' => $counts );
	}

	/**
	 * Tarea semanal: respaldo automático y retención.
	 *
	 * @return void
	 */
	public static function scheduled(): void {
		if ( ! Options::get( 'backups_enabled', false ) ) {
			return;
		}
		$meta = self::create( __( 'Respaldo semanal automático', 'gestion-de-proyectos' ), 'programado' );
		if ( is_wp_error( $meta ) ) {
			return;
		}
		$keep      = max( 1, (int) Options::get( 'backups_keep', 8 ) );
		$automatic = array_values( array_filter( self::all(), static fn( array $b ): bool => 'programado' === ( $b['trigger'] ?? '' ) ) );
		foreach ( array_slice( $automatic, $keep ) as $old ) {
			self::delete( (string) $old['name'] );
		}
	}

	/**
	 * Copia el respaldo a la carpeta externa configurada, si existe y se puede escribir.
	 *
	 * @param string              $path Ruta del ZIP.
	 * @param array<string,mixed> $meta Metadatos.
	 * @return void
	 */
	private static function copy_out( string $path, array $meta ): void {
		$target = trim( (string) Options::get( 'backups_copy_dir', '' ) );
		if ( '' === $target ) {
			return;
		}
		if ( ! is_dir( $target ) || ! is_writable( $target ) ) {
			Audit::log( 'backup', 0, 'copy_failed', 0, sprintf( 'No se pudo copiar el respaldo a %s (carpeta inexistente o sin permiso de escritura)', $target ) );
			return;
		}
		copy( $path, trailingslashit( $target ) . basename( $path ) );
	}

	/**
	 * Sentencias SQL (INSERT) equivalentes al contenido del respaldo, para MariaDB o MySQL.
	 *
	 * @param array<string,mixed> $document Documento del sitio.
	 * @return string
	 */
	public static function sql( array $document ): string {
		$columns = DataSchema::columns();
		$json    = DataSchema::json_columns();
		$out     = "-- Gestión de Proyectos " . GDP_VERSION . ' (esquema ' . GDP_DB_VERSION . "), generado el " . gmdate( 'c' ) . "\n-- Prefijo de tablas del sitio de origen: " . $GLOBALS['wpdb']->prefix . "\n-- Las tablas deben existir (créelas activando el plugin); vacíelas antes de cargar si quiere una copia exacta.\nSET NAMES utf8mb4;\n\n";
		foreach ( (array) $document['tables'] as $table => $rows ) {
			if ( ! isset( $columns[ $table ] ) || empty( $rows ) ) {
				continue;
			}
			$name = Schema::table( $table );
			$cols = array_keys( $columns[ $table ] );
			$out .= "-- {$table}\n";
			foreach ( array_chunk( (array) $rows, 200 ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $row ) {
					$line = array();
					foreach ( $cols as $col ) {
						$v = $row[ $col ] ?? null;
						if ( in_array( $col, $json[ $table ] ?? array(), true ) && is_array( $v ) ) {
							$v = wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
						}
						if ( null === $v ) {
							$line[] = 'NULL';
						} elseif ( is_bool( $v ) ) {
							$line[] = $v ? '1' : '0';
						} elseif ( is_int( $v ) || is_float( $v ) ) {
							$line[] = (string) $v;
						} else {
							$line[] = "'" . strtr( (string) $v, array( '\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z' ) ) . "'";
						}
					}
					$values[] = '(' . implode( ', ', $line ) . ')';
				}
				$out .= "INSERT INTO `{$name}` (`" . implode( '`, `', $cols ) . "`) VALUES\n" . implode( ",\n", $values ) . ";\n";
			}
			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Nota de lectura del respaldo.
	 *
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string
	 */
	private static function readme( array $meta ): string {
		return implode(
			"\n",
			array(
				sprintf( 'Respaldo de %s creado el %s (%s).', $meta['site']['name'] ?? '', $meta['created_at'], $meta['trigger'] ),
				sprintf( 'Gestión de Proyectos %s, esquema %s; %d filas; %d adjuntos; proyectos: %s.', $meta['plugin_version'], $meta['schema_version'], $meta['rows'], $meta['attachments'], implode( ', ', (array) $meta['projects'] ) ),
				'',
				'datos.json: todas las tablas en el formato gestion-de-proyectos/export (identificadores originales). Se restaura desde Proyectos → Datos → Respaldos.',
				'datos.sql: las mismas filas como sentencias INSERT para MariaDB o MySQL, por si hay que cargarlas sin WordPress.',
				'adjuntos/: archivos del directorio privado referidos por las versiones de documentos.',
				'diccionario.json: campos, tipos, unidades y significado.',
				'No incluye tokens del conector ni operaciones pendientes.',
			)
		) . "\n";
	}

	/**
	 * Borra una carpeta temporal.
	 *
	 * @param string $dir Carpeta.
	 * @return void
	 */
	public static function remove_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
