<?php
/**
 * Exportación de datos por proyecto o del sitio completo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Core\Schema;
use GDP\Core\Spreadsheet;
use GDP\Core\Storage;
use GDP\Domain\Projects\ProjectRepository;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Produce un documento de exportación (formato gestion-de-proyectos/export,
 * versión 1): metadatos, usuarios referidos, diccionario opcional y las
 * filas de cada tabla con sus referencias resueltas a códigos y nombres.
 * El documento se serializa en JSON, en CSV (un archivo por tabla dentro de
 * un ZIP), en XLSX (una hoja por tabla) o en ZIP con adjuntos.
 */
final class Exporter {

	public const FORMAT         = 'gestion-de-proyectos/export';
	public const FORMAT_VERSION = 1;

	/**
	 * Documento de exportación de un proyecto.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $options    modules (lista; todos salvo audit por defecto), anonymize, dictionary, resolve, attachments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function project( int $project_id, array $options = array() ) {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
		}
		$modules   = self::modules_from( $options['modules'] ?? null, false );
		$anonymize = ! empty( $options['anonymize'] );
		$tables    = array();
		foreach ( $modules as $slug ) {
			foreach ( DataSchema::modules()[ $slug ] ?? array() as $table ) {
				$tables[] = $table;
			}
		}
		$document = self::skeleton( array( 'type' => 'project', 'project_id' => $project_id, 'project_code' => $project['code'], 'project_name' => $project['name'], 'modules' => $modules, 'anonymized' => $anonymize, 'attachments' => ! empty( $options['attachments'] ) ), $anonymize );
		$ids      = array();
		foreach ( DataSchema::order() as $table ) {
			if ( ! in_array( $table, $tables, true ) ) {
				continue;
			}
			$rows                         = self::rows_for_project( $table, $project_id, $ids );
			$ids[ $table ]                = DataSchema::has_id( $table ) ? array_map( static fn( array $r ): int => (int) $r['id'], $rows ) : array();
			$document['tables'][ $table ] = $rows;
		}

		return self::finish( $document, $options );
	}

	/**
	 * Documento de exportación del sitio completo (todas las tablas, para respaldos).
	 *
	 * @param array<string,mixed> $options dictionary, resolve, attachments.
	 * @return array<string,mixed>
	 */
	public static function site( array $options = array() ): array {
		global $wpdb;

		$document = self::skeleton( array( 'type' => 'site', 'modules' => array_keys( DataSchema::modules() ), 'anonymized' => false, 'attachments' => ! empty( $options['attachments'] ) ), false );
		$columns  = DataSchema::columns();
		foreach ( array_keys( $columns ) as $table ) {
			if ( in_array( $table, DataSchema::never_exported(), true ) ) {
				continue;
			}
			$name = Schema::table( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows                         = $wpdb->get_results( "SELECT * FROM {$name}" . ( isset( $columns[ $table ]['id'] ) ? ' ORDER BY id ASC' : '' ), ARRAY_A );
			$document['tables'][ $table ] = self::typed( $table, is_array( $rows ) ? $rows : array() );
		}
		$options['resolve'] = $options['resolve'] ?? false;

		return self::finish( $document, $options );
	}

	/**
	 * Módulos válidos a partir de la petición.
	 *
	 * @param mixed $requested Lista pedida o null.
	 * @param bool  $with_audit Incluir la bitácora por defecto.
	 * @return string[]
	 */
	public static function modules_from( $requested, bool $with_audit ): array {
		$all = array_keys( DataSchema::modules() );
		if ( ! is_array( $requested ) || empty( $requested ) ) {
			return $with_audit ? $all : array_values( array_diff( $all, array( 'audit' ) ) );
		}
		$out = array();
		foreach ( $all as $slug ) {
			if ( in_array( $slug, $requested, true ) ) {
				$out[] = $slug;
			}
		}
		if ( ! in_array( 'core', $out, true ) ) {
			array_unshift( $out, 'core' );
		}

		return $out;
	}

	/**
	 * Serializa en JSON legible.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return string
	 */
	public static function to_json( array $document ): string {
		return (string) wp_json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Filas planas de una tabla del documento (cabecera + datos), con las referencias resueltas como columnas adicionales.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @param string              $table    Tabla.
	 * @return array<int,array<int,mixed>>
	 */
	public static function table_rows( array $document, string $table ): array {
		$rows = (array) ( $document['tables'][ $table ] ?? array() );
		if ( empty( $rows ) ) {
			$cols = array_keys( DataSchema::columns()[ $table ] ?? array() );
			return array( $cols );
		}
		$cols = array();
		$refs = array();
		foreach ( $rows as $row ) {
			foreach ( $row as $k => $v ) {
				if ( '_refs' === $k ) {
					foreach ( (array) $v as $rk => $rv ) {
						$refs[ $rk ] = true;
					}
				} elseif ( ! isset( $cols[ $k ] ) ) {
					$cols[ $k ] = true;
				}
			}
		}
		$cols   = array_keys( $cols );
		$refs   = array_keys( $refs );
		$header = array_merge( $cols, array_map( static fn( string $r ): string => $r . '_ref', $refs ) );
		$out    = array( $header );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $cols as $c ) {
				$v      = $row[ $c ] ?? null;
				$line[] = is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : $v;
			}
			foreach ( $refs as $r ) {
				$line[] = $row['_refs'][ $r ] ?? '';
			}
			$out[] = $line;
		}

		return $out;
	}

	/**
	 * CSV (separador punto y coma, UTF-8 con marca de orden) a partir de filas.
	 *
	 * @param array<int,array<int,mixed>> $rows Filas.
	 * @return string
	 */
	public static function csv( array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( static fn( $v ): string => is_bool( $v ) ? ( $v ? '1' : '0' ) : ( null === $v ? '' : (string) $v ), $row ), ';', '"', '\\' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * ZIP con un CSV por tabla, el diccionario y una nota de lectura.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return string|WP_Error Contenido binario.
	 */
	public static function to_csv_zip( array $document ) {
		return self::zip(
			static function ( ZipArchive $zip ) use ( $document ): void {
				foreach ( array_keys( (array) $document['tables'] ) as $table ) {
					$zip->addFromString( $table . '.csv', self::csv( self::table_rows( $document, $table ) ) );
				}
				$zip->addFromString( 'diccionario.csv', DataDictionary::to_csv( array_keys( (array) $document['tables'] ) ) );
				$zip->addFromString( 'LEEME.txt', self::readme( $document, 'csv' ) );
			}
		);
	}

	/**
	 * XLSX con una hoja por tabla y una hoja de diccionario.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return string|WP_Error Contenido binario.
	 */
	public static function to_xlsx( array $document ) {
		$sheets = array();
		foreach ( array_keys( (array) $document['tables'] ) as $table ) {
			$sheets[ mb_substr( $table, 0, 31 ) ] = self::table_rows( $document, $table );
		}
		$sheets['diccionario'] = DataDictionary::rows( array_keys( (array) $document['tables'] ) );

		return Spreadsheet::write( $sheets );
	}

	/**
	 * ZIP con el JSON, el diccionario y, si se pide, los adjuntos del directorio privado.
	 *
	 * @param array<string,mixed> $document    Documento.
	 * @param bool                $attachments Incluir archivos de versiones de documentos.
	 * @return string|WP_Error Contenido binario.
	 */
	public static function to_zip( array $document, bool $attachments ) {
		$document['scope']['attachments'] = $attachments;

		return self::zip(
			static function ( ZipArchive $zip ) use ( $document, $attachments ): void {
				$zip->addFromString( 'datos.json', self::to_json( $document ) );
				$zip->addFromString( 'diccionario.json', (string) wp_json_encode( DataDictionary::build( array_keys( (array) $document['tables'] ) ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
				$zip->addFromString( 'LEEME.txt', self::readme( $document, 'zip' ) );
				if ( $attachments ) {
					self::add_attachments( $zip, $document );
				}
			}
		);
	}

	/**
	 * Rutas relativas de los archivos adjuntos referidos por el documento.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return string[]
	 */
	public static function attachment_paths( array $document ): array {
		$paths = array();
		foreach ( (array) ( $document['tables']['document_versions'] ?? array() ) as $v ) {
			$path = ltrim( str_replace( '\\', '/', (string) ( $v['path'] ?? '' ) ), '/' );
			if ( '' !== $path && false === strpos( $path, '..' ) ) {
				$paths[] = $path;
			}
		}
		/**
		 * Permite a otros módulos declarar archivos del directorio privado que acompañan a sus datos.
		 *
		 * @param string[] $paths    Rutas relativas al directorio privado.
		 * @param array    $document Documento de exportación.
		 */
		return array_values( array_unique( (array) apply_filters( 'gdp_data_attachments', $paths, $document ) ) );
	}

	/**
	 * Añade los adjuntos al ZIP bajo adjuntos/.
	 *
	 * @param ZipArchive          $zip      Archivo.
	 * @param array<string,mixed> $document Documento.
	 * @return void
	 */
	private static function add_attachments( ZipArchive $zip, array $document ): void {
		$base = trailingslashit( Storage::private_dir() );
		foreach ( self::attachment_paths( $document ) as $path ) {
			if ( is_file( $base . $path ) ) {
				$zip->addFile( $base . $path, 'adjuntos/' . $path );
			}
		}
	}

	/**
	 * Crea un ZIP temporal, lo rellena y devuelve su contenido.
	 *
	 * @param callable $fill Función que añade entradas.
	 * @return string|WP_Error
	 */
	private static function zip( callable $fill ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP.', 'gestion-de-proyectos' ) );
		}
		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdp-export' ) : (string) tempnam( get_temp_dir(), 'gdp-export' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', __( 'No se pudo crear el archivo ZIP.', 'gestion-de-proyectos' ) );
		}
		$fill( $zip );
		$zip->close();
		$binary = (string) file_get_contents( $tmp );
		wp_delete_file( $tmp );

		return $binary;
	}

	/**
	 * Nota de lectura incluida en los paquetes.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @param string              $kind     csv o zip.
	 * @return string
	 */
	private static function readme( array $document, string $kind ): string {
		$lines   = array();
		$lines[] = sprintf( 'Exportación de %s', $document['site']['name'] ?? '' );
		$lines[] = sprintf( 'Generada el %s con Gestión de Proyectos %s (esquema %s).', $document['generated_at'], $document['plugin_version'], $document['schema_version'] );
		if ( 'project' === ( $document['scope']['type'] ?? '' ) ) {
			$lines[] = sprintf( 'Proyecto: %s %s', $document['scope']['project_code'], $document['scope']['project_name'] );
		} else {
			$lines[] = 'Alcance: sitio completo.';
		}
		$lines[] = 'Módulos: ' . implode( ', ', (array) $document['scope']['modules'] ) . '.';
		if ( ! empty( $document['scope']['anonymized'] ) ) {
			$lines[] = 'Exportación anonimizada: sin usuarios, nombres de contacto, correos ni montos individuales.';
		}
		$lines[] = '';
		if ( 'csv' === $kind ) {
			$lines[] = 'Cada archivo CSV corresponde a una tabla (separador punto y coma, UTF-8). Las columnas terminadas en _ref traducen un identificador a su código o nombre.';
		} else {
			$lines[] = 'datos.json contiene las tablas en el formato gestion-de-proyectos/export, importable desde Proyectos → Datos → Importar; la carpeta adjuntos/ reproduce los archivos del directorio privado que referencian las versiones de documentos.';
		}
		$lines[] = 'diccionario: campo, tipo, unidad y significado de cada columna.';
		$lines[] = 'Los identificadores (id y columnas *_id) son internos del sitio de origen; al importar se reasignan y los registros se reconocen por su código o clave natural.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Esqueleto del documento.
	 *
	 * @param array<string,mixed> $scope     Alcance.
	 * @param bool                $anonymize Anonimizado.
	 * @return array<string,mixed>
	 */
	private static function skeleton( array $scope, bool $anonymize ): array {
		$user = wp_get_current_user();

		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => GDP_VERSION,
			'schema_version' => (int) GDP_DB_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'generated_by'   => $anonymize || ! $user->exists() ? '' : $user->user_login,
			'site'           => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
			'scope'          => $scope,
			'users'          => array(),
			'tables'         => array(),
		);
	}

	/**
	 * Completa el documento: usuarios, referencias resueltas, anonimización y diccionario.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @param array<string,mixed> $options  Opciones.
	 * @return array<string,mixed>
	 */
	private static function finish( array $document, array $options ): array {
		self::strip_secrets( $document );
		$document['users'] = self::users( $document );
		if ( $options['resolve'] ?? true ) {
			self::resolve( $document );
		}
		if ( ! empty( $document['scope']['anonymized'] ) ) {
			self::anonymize( $document );
		}
		if ( ! empty( $options['dictionary'] ) ) {
			$document['dictionary'] = DataDictionary::build( array_keys( $document['tables'] ) );
		}
		$document['counts'] = array_map( 'count', $document['tables'] );

		return $document;
	}

	/**
	 * Filas de una tabla que pertenecen al proyecto.
	 *
	 * @param string                     $table      Tabla.
	 * @param int                        $project_id Proyecto.
	 * @param array<string,int[]>        $ids        Identificadores ya exportados por tabla.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows_for_project( string $table, int $project_id, array $ids ): array {
		global $wpdb;

		$name    = Schema::table( $table );
		$columns = DataSchema::columns()[ $table ] ?? array();
		$order   = isset( $columns['id'] ) ? ' ORDER BY id ASC' : '';
		$parents = DataSchema::parents();
		if ( 'projects' === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$name} WHERE id = %d", $project_id ), ARRAY_A );
		} elseif ( isset( $parents[ $table ] ) ) {
			list( $column, $parent ) = $parents[ $table ];
			$parent_ids              = $ids[ $parent ] ?? array();
			if ( empty( $parent_ids ) ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$name} WHERE {$column} IN (" . implode( ',', array_map( 'intval', $parent_ids ) ) . ')' . $order, ARRAY_A );
		} elseif ( isset( $columns['project_id'] ) ) {
			$scope = in_array( $table, DataSchema::project_or_global(), true ) ? 'project_id IN (0, %d)' : 'project_id = %d';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$name} WHERE {$scope}{$order}", $project_id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$name}" . ( isset( $columns['rate_date'] ) ? ' ORDER BY rate_date ASC' : $order ), ARRAY_A );
		}

		return self::typed( $table, is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Convierte los valores de texto de la base de datos a tipos JSON (enteros, decimales, booleanos, JSON decodificado).
	 *
	 * @param string                           $table Tabla.
	 * @param array<int,array<string,mixed>>   $rows  Filas.
	 * @return array<int,array<string,mixed>>
	 */
	public static function typed( string $table, array $rows ): array {
		$columns = DataSchema::columns()[ $table ] ?? array();
		$json    = DataSchema::json_columns()[ $table ] ?? array();
		foreach ( $rows as &$row ) {
			foreach ( $row as $col => $value ) {
				if ( null === $value ) {
					continue;
				}
				if ( in_array( $col, $json, true ) ) {
					$decoded     = json_decode( (string) $value, true );
					$row[ $col ] = null === $decoded && 'null' !== trim( (string) $value ) ? $value : $decoded;
					continue;
				}
				switch ( DataSchema::logical_type( $columns[ $col ] ?? 'text' ) ) {
					case 'integer':
						$row[ $col ] = (int) $value;
						break;
					case 'boolean':
						$row[ $col ] = (bool) (int) $value;
						break;
					case 'decimal':
						$row[ $col ] = (float) $value;
						break;
					default:
						$row[ $col ] = (string) $value;
				}
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Quita de los ajustes del proyecto las claves que no deben salir del sitio.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return void
	 */
	private static function strip_secrets( array &$document ): void {
		if ( empty( $document['tables']['projects'] ) ) {
			return;
		}
		foreach ( array_keys( $document['tables']['projects'] ) as $i ) {
			if ( is_array( $document['tables']['projects'][ $i ]['settings'] ?? null ) ) {
				foreach ( DataSchema::secret_settings() as $key ) {
					unset( $document['tables']['projects'][ $i ]['settings'][ $key ] );
				}
			}
		}
	}

	/**
	 * Usuarios del sitio referidos en el documento.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return array<string,array{login:string,display_name:string,email:string}>
	 */
	private static function users( array $document ): array {
		$ids = array();
		foreach ( $document['tables'] as $rows ) {
			foreach ( $rows as $row ) {
				foreach ( DataSchema::user_columns() as $col ) {
					if ( ! empty( $row[ $col ] ) ) {
						$ids[ (int) $row[ $col ] ] = true;
					}
				}
			}
		}
		$out = array();
		foreach ( array_keys( $ids ) as $id ) {
			$user = get_userdata( $id );
			if ( $user ) {
				$out[ (string) $id ] = array( 'login' => $user->user_login, 'display_name' => $user->display_name, 'email' => $user->user_email );
			}
		}
		ksort( $out, SORT_NUMERIC );

		return $out;
	}

	/**
	 * Etiqueta legible de un registro de una tabla.
	 *
	 * @param string              $table Tabla.
	 * @param array<string,mixed> $row   Fila.
	 * @return string
	 */
	public static function label( string $table, array $row ): string {
		switch ( $table ) {
			case 'projects':
			case 'purchases':
			case 'meetings':
			case 'agreements':
				return (string) ( $row['code'] ?? '' );
			case 'activities':
				return trim( (string) ( $row['code'] ?? '' ) . ' ' . (string) ( $row['name'] ?? '' ) );
			case 'documents':
				return trim( (string) ( $row['doc_number'] ?? '' ) . ' ' . (string) ( $row['subject'] ?? '' ) );
			case 'quotes':
				return (string) ( $row['quote_number'] ?? '' );
			case 'suppliers':
			case 'baselines':
			case 'calendars':
				return (string) ( $row['name'] ?? '' );
			case 'catalog_items':
				return trim( (string) ( $row['catalog'] ?? '' ) . '/' . (string) ( $row['slug'] ?? '' ) );
			case 'budget_lines':
				return trim( (string) ( $row['line_code'] ?? '' ) . ' ' . (string) ( $row['label'] ?? '' ) );
			case 'uf_rates':
				return (string) ( $row['rate_date'] ?? '' );
			case 'calendar_exceptions':
				return trim( (string) ( $row['exception_date'] ?? '' ) . ' ' . (string) ( $row['label'] ?? '' ) );
			case 'quote_items':
				return (string) ( $row['description'] ?? '' );
			case 'purchase_stages':
				return trim( (string) ( $row['stage'] ?? '' ) . ' ' . (string) ( $row['stage_date'] ?? '' ) );
			case 'meeting_attendees':
				return '' !== (string) ( $row['name'] ?? '' ) ? (string) $row['name'] : sprintf( 'usuario %d', (int) ( $row['user_id'] ?? 0 ) );
			case 'progress':
				return trim( (string) ( $row['reported_at'] ?? '' ) . ' ' . (string) ( $row['percent'] ?? '' ) . '%' );
			case 'external_refs':
				return trim( (string) ( $row['system_name'] ?? '' ) . ' ' . (string) ( $row['ref_number'] ?? '' ) );
			case 'links':
				return sprintf( '%s %d → %s %d', (string) ( $row['from_type'] ?? '' ), (int) ( $row['from_id'] ?? 0 ), (string) ( $row['to_type'] ?? '' ), (int) ( $row['to_id'] ?? 0 ) );
			case 'dependencies':
				return sprintf( '%d → %d %s', (int) ( $row['predecessor_id'] ?? 0 ), (int) ( $row['successor_id'] ?? 0 ), (string) ( $row['type'] ?? '' ) );
			case 'assignments':
				return sprintf( 'actividad %d, usuario %d', (int) ( $row['activity_id'] ?? 0 ), (int) ( $row['user_id'] ?? 0 ) );
			case 'project_members':
				return sprintf( 'usuario %d (%s)', (int) ( $row['user_id'] ?? 0 ), (string) ( $row['role'] ?? '' ) );
			case 'baseline_activities':
				return trim( (string) ( $row['code'] ?? '' ) . ' ' . (string) ( $row['name'] ?? '' ) );
			case 'document_versions':
				return 'v' . (int) ( $row['version_no'] ?? 0 ) . ' ' . (string) ( $row['filename'] ?? '' );
		}

		return (string) ( $row['code'] ?? ( $row['name'] ?? ( $row['title'] ?? '' ) ) );
	}

	/**
	 * Añade a cada fila un objeto _refs con los identificadores traducidos a códigos y nombres.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return void
	 */
	private static function resolve( array &$document ): void {
		$index = array();
		foreach ( $document['tables'] as $table => $rows ) {
			if ( ! DataSchema::has_id( $table ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$index[ $table ][ (int) $row['id'] ] = self::label( $table, $row );
			}
		}
		$refs    = DataSchema::refs();
		$poly    = DataSchema::polymorphic();
		$users   = $document['users'];
		$ucols   = DataSchema::user_columns();
		$lookup  = static function ( string $table, int $id ) use ( &$index ): string {
			if ( $id <= 0 ) {
				return '';
			}
			if ( isset( $index[ $table ][ $id ] ) ) {
				return $index[ $table ][ $id ];
			}
			global $wpdb;
			if ( ! DataSchema::has_id( $table ) ) {
				return '';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row                   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( $table ) . ' WHERE id = %d', $id ), ARRAY_A );
			$index[ $table ][ $id ] = $row ? self::label( $table, $row ) : '';

			return $index[ $table ][ $id ];
		};
		foreach ( $document['tables'] as $table => &$rows ) {
			foreach ( $rows as &$row ) {
				$r = array();
				foreach ( $refs[ $table ] ?? array() as $col => $target ) {
					if ( 'project_id' === $col || empty( $row[ $col ] ) ) {
						continue;
					}
					$r[ preg_replace( '/_id$/', '', $col ) ] = $lookup( $target, (int) $row[ $col ] );
				}
				foreach ( $poly[ $table ] ?? array() as $pair ) {
					$target = DataSchema::entity_table( (string) ( $row[ $pair[0] ] ?? '' ) );
					if ( $target && ! empty( $row[ $pair[1] ] ) ) {
						$r[ preg_replace( '/_id$/', '', $pair[1] ) ] = $lookup( $target, (int) $row[ $pair[1] ] );
					}
				}
				foreach ( $ucols as $col ) {
					if ( ! empty( $row[ $col ] ) && ! isset( $refs[ $table ][ $col ] ) ) {
						$r[ preg_replace( '/_id$/', '', $col ) ] = (string) ( $users[ (string) $row[ $col ] ]['display_name'] ?? '' );
					}
				}
				if ( ! empty( $r ) ) {
					$row['_refs'] = $r;
				}
			}
			unset( $row );
		}
		unset( $rows );
	}

	/**
	 * Anonimiza: usuarios con seudónimo, columnas personales vacías, montos nulos, bitácora excluida.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return void
	 */
	private static function anonymize( array &$document ): void {
		unset( $document['tables']['audit_log'] );
		$pseudo   = array();
		$next     = 1;
		$personal = DataSchema::personal_columns();
		$amounts  = DataSchema::amount_columns();
		foreach ( $document['tables'] as $table => &$rows ) {
			foreach ( $rows as &$row ) {
				foreach ( DataSchema::user_columns() as $col ) {
					if ( ! empty( $row[ $col ] ) ) {
						$id = (int) $row[ $col ];
						if ( ! isset( $pseudo[ $id ] ) ) {
							$pseudo[ $id ] = $next++;
						}
						$row[ $col ] = $pseudo[ $id ];
					}
				}
				foreach ( $personal[ $table ] ?? array() as $col ) {
					if ( array_key_exists( $col, $row ) ) {
						$row[ $col ] = is_array( $row[ $col ] ) ? null : '';
					}
				}
				foreach ( $amounts[ $table ] ?? array() as $col ) {
					if ( array_key_exists( $col, $row ) ) {
						$row[ $col ] = null;
					}
				}
				if ( isset( $row['_refs'] ) ) {
					foreach ( DataSchema::user_columns() as $col ) {
						unset( $row['_refs'][ preg_replace( '/_id$/', '', $col ) ] );
					}
					if ( empty( $row['_refs'] ) ) {
						unset( $row['_refs'] );
					}
				}
			}
			unset( $row );
		}
		unset( $rows );
		$document['users']        = array();
		$document['generated_by'] = '';
	}
}
