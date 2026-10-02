<?php
/**
 * Importación de documentos de exportación con reasignación de identificadores.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Core\Schema;
use GDP\Core\Storage;
use GDP\Domain\Projects\ProjectRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lee un documento gestion-de-proyectos/export y lo carga en el sitio.
 *
 * Los identificadores del documento son locales a él: cada fila se reconoce
 * por su clave natural (código, nombre, fecha) y las referencias entre tablas
 * se reasignan a los identificadores del sitio en orden de carga, con un
 * segundo paso para las autorreferencias (resumen padre, cotización elegida).
 * El mismo recorrido sirve para la vista previa (sin escribir) y para la
 * aplicación, de modo que lo que se muestra es lo que se hace.
 */
final class Importer {

	public const MODES = array( 'new', 'update' );

	/**
	 * Tablas y usuarios resueltos durante un recorrido.
	 *
	 * @var array<string,mixed>
	 */
	private $state = array();

	/**
	 * Valida la estructura del documento y lo normaliza (tablas y columnas conocidas, tipos).
	 *
	 * @param mixed $document Documento decodificado.
	 * @param bool  $site     Restauración del sitio completo: conserva historial y admite varios proyectos.
	 * @return array<string,mixed>|WP_Error Documento normalizado con 'notes' (avisos de normalización).
	 */
	public static function validate( $document, bool $site = false ) {
		if ( ! is_array( $document ) || ( $document['format'] ?? '' ) !== Exporter::FORMAT ) {
			return new WP_Error( 'format', __( 'El archivo no es una exportación de Gestión de Proyectos (falta el campo format).', 'gestion-de-proyectos' ) );
		}
		if ( (int) ( $document['format_version'] ?? 0 ) > Exporter::FORMAT_VERSION ) {
			return new WP_Error( 'format_version', __( 'El archivo usa una versión del formato más nueva que la de este sitio; actualice el plugin.', 'gestion-de-proyectos' ) );
		}
		if ( (int) ( $document['schema_version'] ?? 0 ) > (int) GDP_DB_VERSION ) {
			/* translators: 1: versión del esquema del archivo, 2: versión del esquema del sitio. */
			return new WP_Error( 'schema_version', sprintf( __( 'El archivo proviene de un esquema más nuevo (%1$s) que el de este sitio (%2$s); actualice el plugin antes de importar.', 'gestion-de-proyectos' ), (int) $document['schema_version'], (int) GDP_DB_VERSION ) );
		}
		if ( ! is_array( $document['tables'] ?? null ) || empty( $document['tables'] ) ) {
			return new WP_Error( 'empty', __( 'El archivo no contiene tablas.', 'gestion-de-proyectos' ) );
		}
		$columns = DataSchema::columns();
		$notes   = array();
		$tables  = array();
		$skip    = $site ? DataSchema::never_exported() : array_merge( DataSchema::never_exported(), DataSchema::export_only() );
		foreach ( (array) $document['tables'] as $table => $rows ) {
			$table = sanitize_key( (string) $table );
			if ( ! isset( $columns[ $table ] ) ) {
				/* translators: nombre de la tabla. */
				$notes[] = sprintf( __( 'Tabla desconocida omitida: %s.', 'gestion-de-proyectos' ), $table );
				continue;
			}
			if ( in_array( $table, $skip, true ) ) {
				/* translators: nombre de la tabla. */
				$notes[] = sprintf( __( 'La tabla %s no se importa (historial o datos derivados del sitio de origen).', 'gestion-de-proyectos' ), $table );
				continue;
			}
			if ( ! is_array( $rows ) ) {
				continue;
			}
			$unknown = array();
			$clean   = array();
			foreach ( $rows as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				unset( $row['_refs'] );
				foreach ( array_keys( $row ) as $col ) {
					if ( ! isset( $columns[ $table ][ $col ] ) ) {
						$unknown[ $col ] = true;
						unset( $row[ $col ] );
					}
				}
				if ( DataSchema::has_id( $table ) && empty( $row['id'] ) ) {
					/* translators: 1: nombre de la tabla, 2: posición de la fila. */
					return new WP_Error( 'row_id', sprintf( __( 'La fila %2$d de la tabla %1$s no tiene identificador (id).', 'gestion-de-proyectos' ), $table, (int) $i + 1 ) );
				}
				$clean[] = $row;
			}
			if ( ! empty( $unknown ) ) {
				/* translators: 1: nombre de la tabla, 2: lista de columnas. */
				$notes[] = sprintf( __( 'Columnas desconocidas omitidas en %1$s: %2$s.', 'gestion-de-proyectos' ), $table, implode( ', ', array_keys( $unknown ) ) );
			}
			$tables[ $table ] = $clean;
		}
		if ( ! $site && empty( $tables['projects'] ) ) {
			return new WP_Error( 'no_project', __( 'El archivo no contiene la tabla projects con el proyecto a importar.', 'gestion-de-proyectos' ) );
		}
		if ( ! $site && count( $tables['projects'] ) > 1 ) {
			return new WP_Error( 'many_projects', __( 'El archivo contiene más de un proyecto; importe los proyectos de uno en uno (o restaure un respaldo del sitio).', 'gestion-de-proyectos' ) );
		}
		$document['tables'] = $tables;
		$document['notes']  = $notes;
		$document['users']  = is_array( $document['users'] ?? null ) ? $document['users'] : array();

		return $document;
	}

	/**
	 * Lee un archivo JSON o ZIP y devuelve el documento validado y, si lo hay, el directorio con adjuntos.
	 *
	 * @param string $path Ruta del archivo.
	 * @param bool   $site Restauración del sitio completo.
	 * @return array{document:array<string,mixed>,attachments:string}|WP_Error
	 */
	public static function read_file( string $path, bool $site = false ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'missing_file', __( 'El archivo a importar no existe.', 'gestion-de-proyectos' ) );
		}
		$attachments = '';
		$handle      = fopen( $path, 'rb' );
		$head        = $handle ? (string) fread( $handle, 4 ) : '';
		if ( $handle ) {
			fclose( $handle );
		}
		if ( 0 === strpos( $head, "PK\x03\x04" ) ) {
			if ( ! class_exists( \ZipArchive::class ) ) {
				return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP.', 'gestion-de-proyectos' ) );
			}
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return new WP_Error( 'zip', __( 'No se pudo abrir el archivo ZIP.', 'gestion-de-proyectos' ) );
			}
			$json = $zip->getFromName( 'datos.json' );
			if ( false === $json ) {
				$zip->close();
				return new WP_Error( 'zip_json', __( 'El ZIP no contiene datos.json.', 'gestion-de-proyectos' ) );
			}
			$dir = preg_replace( '/\.zip$/i', '', $path ) . '-adjuntos';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( 0 === strpos( $name, 'adjuntos/' ) && false === strpos( $name, '..' ) && '/' !== substr( $name, -1 ) ) {
					$target = $dir . '/' . substr( $name, 9 );
					wp_mkdir_p( dirname( $target ) );
					file_put_contents( $target, (string) $zip->getFromIndex( $i ) );
					$attachments = $dir;
				}
			}
			$zip->close();
		} else {
			$json = (string) file_get_contents( $path );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'json', __( 'El archivo no contiene JSON válido.', 'gestion-de-proyectos' ) );
		}
		$document = self::validate( $decoded, $site );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return array( 'document' => $document, 'attachments' => $attachments );
	}

	/**
	 * Vista previa: qué se crearía, qué se actualizaría y qué entra en conflicto.
	 *
	 * @param array<string,mixed> $document Documento validado.
	 * @param array<string,mixed> $options  mode, project_code, tables, attachments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function plan( array $document, array $options ) {
		$importer = new self();

		return $importer->run( $document, $options, true );
	}

	/**
	 * Aplica la importación.
	 *
	 * @param array<string,mixed> $document Documento validado.
	 * @param array<string,mixed> $options  mode, project_code, tables, attachments.
	 * @return array<string,mixed>|WP_Error Plan ejecutado con 'before' y 'result'.
	 */
	public static function apply( array $document, array $options ) {
		$importer = new self();

		return $importer->run( $document, $options, false );
	}

	/**
	 * Deshace una importación: elimina lo creado y restaura lo actualizado.
	 *
	 * @param array<string,mixed> $before Estado guardado por apply().
	 * @return bool|WP_Error
	 */
	public static function revert( array $before ) {
		global $wpdb;

		$order = array_reverse( DataSchema::order() );
		foreach ( $order as $table ) {
			foreach ( (array) ( $before['created'][ $table ] ?? array() ) as $key ) {
				$name = Schema::table( $table );
				if ( DataSchema::has_id( $table ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( $name, array( 'id' => (int) $key ) );
				} elseif ( is_array( $key ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( $name, $key );
				}
			}
		}
		foreach ( $order as $table ) {
			foreach ( (array) ( $before['updated'][ $table ] ?? array() ) as $old ) {
				$old = (array) $old;
				if ( DataSchema::has_id( $table ) && ! empty( $old['id'] ) ) {
					$where = array( 'id' => (int) $old['id'] );
				} else {
					$keys  = DataSchema::keys()[ $table ] ?? array();
					$where = array_intersect_key( $old, array_flip( $keys ) );
				}
				if ( empty( $where ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( $table ), $old, $where );
			}
		}
		$base = trailingslashit( Storage::private_dir() );
		foreach ( (array) ( $before['files'] ?? array() ) as $relative ) {
			if ( is_file( $base . $relative ) ) {
				wp_delete_file( $base . $relative );
			}
		}
		$project_id = (int) ( $before['project_id'] ?? 0 );
		if ( $project_id > 0 && ProjectRepository::find( $project_id ) && class_exists( \GDP\Modules\Planning\ScheduleService::class ) ) {
			\GDP\Modules\Planning\ScheduleService::recalculate( $project_id );
		}

		return true;
	}

	/**
	 * Recorrido común de vista previa y aplicación.
	 *
	 * @param array<string,mixed> $document Documento validado.
	 * @param array<string,mixed> $options  Opciones.
	 * @param bool                $dry      Sin escribir.
	 * @return array<string,mixed>|WP_Error
	 */
	private function run( array $document, array $options, bool $dry ) {
		global $wpdb;

		$mode = in_array( $options['mode'] ?? '', self::MODES, true ) ? (string) $options['mode'] : 'new';
		$this->state = array(
			'dry'      => $dry,
			'mode'     => $mode,
			'map'      => array(),
			'users'    => array(),
			'missing'  => array(),
			'warnings' => (array) ( $document['notes'] ?? array() ),
			'conflicts' => array(),
			'deferred' => array(),
			'created'  => array(),
			'updated'  => array(),
			'files'    => array(),
			'attachments' => (string) ( $options['attachments'] ?? '' ),
			'project_id' => 0,
			'old_project_id' => 0,
			'tables'   => array(),
			'touched_planning' => false,
		);
		// Tablas que el usuario no puede escribir: se omiten siempre; el proyecto solo ubica el destino.
		$this->state['skip'] = array_map( 'sanitize_key', (array) ( $options['skip'] ?? array() ) );

		// Proyecto de destino.
		$source = $document['tables']['projects'][0] ?? null;
		$code   = sanitize_text_field( (string) ( $options['project_code'] ?? '' ) );
		if ( '' === $code ) {
			$code = sanitize_text_field( (string) ( $source['code'] ?? '' ) );
		}
		if ( '' === $code ) {
			return new WP_Error( 'project_code', __( 'Indique el código del proyecto a crear.', 'gestion-de-proyectos' ) );
		}
		$existing = ProjectRepository::find_by_code( $code );
		if ( 'new' === $mode && $existing ) {
			/* translators: código del proyecto. */
			return new WP_Error( 'exists', sprintf( __( 'Ya existe un proyecto con el código %s; elija "actualizar el proyecto existente" o indique otro código.', 'gestion-de-proyectos' ), $code ) );
		}
		if ( 'update' === $mode && ! $existing ) {
			/* translators: código del proyecto. */
			return new WP_Error( 'not_found', sprintf( __( 'No existe un proyecto con el código %s para actualizar; elija "crear proyecto nuevo".', 'gestion-de-proyectos' ), $code ) );
		}
		$this->state['old_project_id'] = (int) ( $source['id'] ?? 0 );
		if ( $source ) {
			$document['tables']['projects'][0]['code'] = $code;
		}

		// Usuarios: por usuario, luego por correo.
		foreach ( (array) $document['users'] as $old => $u ) {
			$user = get_user_by( 'login', (string) ( $u['login'] ?? '' ) );
			if ( ! $user && ! empty( $u['email'] ) ) {
				$user = get_user_by( 'email', (string) $u['email'] );
			}
			if ( $user ) {
				$this->state['users'][ (string) $old ] = (int) $user->ID;
			} else {
				$this->state['users'][ (string) $old ] = 0;
				$this->state['missing'][ (string) $old ] = (string) ( $u['display_name'] ?? ( $u['login'] ?? $old ) );
			}
		}
		if ( ! empty( $this->state['missing'] ) ) {
			/* translators: lista de usuarios. */
			$this->state['warnings'][] = sprintf( __( 'Usuarios del sitio de origen que no existen aquí (sus referencias quedan sin usuario; las pertenencias al equipo y asignaciones se omiten): %s.', 'gestion-de-proyectos' ), implode( ', ', array_unique( $this->state['missing'] ) ) );
		}

		$selected = is_array( $options['tables'] ?? null ) && ! empty( $options['tables'] ) ? array_map( 'sanitize_key', $options['tables'] ) : null;
		$skip     = $this->state['skip'];
		foreach ( DataSchema::order() as $table ) {
			if ( ! isset( $document['tables'][ $table ] ) ) {
				continue;
			}
			if ( 'projects' !== $table && ( in_array( $table, $skip, true ) || ( null !== $selected && ! in_array( $table, $selected, true ) ) ) ) {
				$this->state['tables'][ $table ] = array( 'create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => count( $document['tables'][ $table ] ), 'conflicts' => 0, 'samples' => array(), 'selected' => false );
				continue;
			}
			$result = $this->process_table( $table, $document['tables'][ $table ] );
			if ( is_wp_error( $result ) ) {
				if ( ! $dry ) {
					self::revert( $this->before() );
				}
				return $result;
			}
		}
		if ( ! $dry ) {
			$this->apply_deferred();
			if ( $this->state['touched_planning'] && $this->state['project_id'] > 0 && class_exists( \GDP\Modules\Planning\ScheduleService::class ) ) {
				\GDP\Modules\Planning\ScheduleService::recalculate( $this->state['project_id'] );
			}
		}
		$totals = array( 'create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0, 'conflicts' => 0 );
		foreach ( $this->state['tables'] as $t ) {
			foreach ( $totals as $k => $v ) {
				$totals[ $k ] += (int) $t[ $k ];
			}
		}

		return array(
			'mode'       => $mode,
			'project'    => array( 'code' => $code, 'name' => (string) ( $source['name'] ?? ( $existing['name'] ?? '' ) ), 'exists' => (bool) $existing, 'project_id' => $this->state['project_id'] > 0 ? $this->state['project_id'] : (int) ( $existing['id'] ?? 0 ) ),
			'tables'     => $this->state['tables'],
			'totals'     => $totals,
			'warnings'   => array_values( array_unique( $this->state['warnings'] ) ),
			'conflicts'  => array_values( array_unique( $this->state['conflicts'] ) ),
			'before'     => $dry ? null : $this->before(),
			'result'     => $dry ? null : array( 'project_id' => $this->state['project_id'], 'created' => array_map( 'count', $this->state['created'] ), 'updated' => array_map( 'count', $this->state['updated'] ), 'files' => count( $this->state['files'] ) ),
		);
	}

	/**
	 * Estado anterior para revertir.
	 *
	 * @return array<string,mixed>
	 */
	private function before(): array {
		return array( 'mode' => $this->state['mode'], 'project_id' => $this->state['project_id'], 'created' => $this->state['created'], 'updated' => $this->state['updated'], 'files' => $this->state['files'] );
	}

	/**
	 * Procesa las filas de una tabla.
	 *
	 * @param string                           $table Tabla.
	 * @param array<int,array<string,mixed>>   $rows  Filas.
	 * @return true|WP_Error
	 */
	private function process_table( string $table, array $rows ) {
		$stats = array( 'create' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0, 'conflicts' => 0, 'samples' => array(), 'selected' => true );
		$dry   = $this->state['dry'];
		foreach ( $rows as $row ) {
			$old_id = DataSchema::has_id( $table ) ? (int) $row['id'] : 0;
			$prep   = $this->prepare( $table, $row );
			if ( null === $prep ) {
				++$stats['skipped'];
				continue;
			}
			list( $clean, $deferred ) = $prep;
			$existing                 = $this->find_existing( $table, $clean, $old_id );
			if ( $existing ) {
				$local_id = DataSchema::has_id( $table ) ? (int) $existing['id'] : 0;
				if ( $old_id > 0 ) {
					$this->state['map'][ $table ][ $old_id ] = $local_id;
				}
				if ( 'projects' === $table ) {
					$this->state['project_id'] = $local_id;
				}
				$diff = $this->diff( $table, $existing, array_diff_key( $clean, $deferred ) );
				if ( empty( $diff ) ) {
					++$stats['unchanged'];
					continue;
				}
				// El proyecto se recorre aunque el usuario no pueda editarlo: ubica el destino, pero no se escribe.
				if ( in_array( $table, $this->state['skip'], true ) ) {
					++$stats['skipped'];
					continue;
				}
				if ( isset( $clean['version'], $existing['version'] ) && (int) $existing['version'] > (int) $clean['version'] ) {
					++$stats['conflicts'];
					/* translators: 1: tabla, 2: registro, 3: versión en el sitio, 4: versión en el archivo. */
					$this->state['conflicts'][] = sprintf( __( '%1$s %2$s: modificado en el sitio después de la exportación (versión %3$d frente a %4$d).', 'gestion-de-proyectos' ), $table, Exporter::label( $table, $existing ), (int) $existing['version'], (int) $clean['version'] );
					continue;
				}
				++$stats['update'];
				if ( count( $stats['samples'] ) < 5 ) {
					$stats['samples'][] = array( 'op' => 'update', 'label' => Exporter::label( $table, $existing ), 'changes' => $diff );
				}
				if ( ! $dry ) {
					$write = $this->for_db( $table, array_diff_key( $clean, $deferred ) );
					unset( $write['id'] );
					if ( isset( $write['version'] ) ) {
						$write['version'] = (int) $existing['version'] + 1;
					}
					$where = DataSchema::has_id( $table ) ? array( 'id' => $local_id ) : array_intersect_key( $existing, array_flip( DataSchema::keys()[ $table ] ?? array() ) );
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$ok = $wpdb->update( Schema::table( $table ), $write, $where );
					if ( false === $ok ) {
						return new WP_Error( 'db', sprintf( 'No se pudo actualizar %s %s: %s', $table, Exporter::label( $table, $clean ), $wpdb->last_error ) );
					}
					$this->state['updated'][ $table ][] = $existing;
					$this->register_deferred( $table, $local_id, $deferred );
					$this->note_planning( $table );
				}
				continue;
			}
			++$stats['create'];
			if ( count( $stats['samples'] ) < 5 ) {
				$stats['samples'][] = array( 'op' => 'create', 'label' => Exporter::label( $table, $clean ), 'changes' => array() );
			}
			if ( $dry ) {
				if ( $old_id > 0 ) {
					$this->state['map'][ $table ][ $old_id ] = null;
				}
				continue;
			}
			$write = $this->for_db( $table, $clean );
			unset( $write['id'] );
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert( Schema::table( $table ), $write );
			if ( false === $ok ) {
				return new WP_Error( 'db', sprintf( 'No se pudo crear %s %s: %s', $table, Exporter::label( $table, $clean ), $wpdb->last_error ) );
			}
			$new_id = DataSchema::has_id( $table ) ? (int) $wpdb->insert_id : 0;
			if ( $old_id > 0 ) {
				$this->state['map'][ $table ][ $old_id ] = $new_id;
			}
			if ( 'projects' === $table ) {
				$this->state['project_id'] = $new_id;
			}
			$this->state['created'][ $table ][] = DataSchema::has_id( $table ) ? $new_id : array_intersect_key( $write, array_flip( DataSchema::keys()[ $table ] ?? array() ) );
			$this->register_deferred( $table, $new_id, $deferred );
			$this->note_planning( $table );
			if ( 'document_versions' === $table ) {
				$this->copy_attachment( $row, $write, $new_id );
			}
		}
		$this->state['tables'][ $table ] = $stats;

		return true;
	}

	/**
	 * Marca que se tocaron tablas del cronograma (para recalcular al final).
	 *
	 * @param string $table Tabla.
	 * @return void
	 */
	private function note_planning( string $table ): void {
		if ( in_array( $table, array( 'activities', 'dependencies', 'calendars', 'calendar_exceptions', 'projects' ), true ) ) {
			$this->state['touched_planning'] = true;
		}
	}

	/**
	 * Prepara una fila: reasigna referencias y usuarios; decide si se omite.
	 *
	 * @param string              $table Tabla.
	 * @param array<string,mixed> $row   Fila del documento.
	 * @return array{0:array<string,mixed>,1:array<string,int>}|null Fila preparada y referencias diferidas (columna => id antiguo), o null si se omite.
	 */
	private function prepare( string $table, array $row ): ?array {
		$refs     = DataSchema::refs()[ $table ] ?? array();
		$deferred = array();
		$order    = DataSchema::order();
		$position = (int) array_search( $table, $order, true );
		foreach ( $refs as $col => $target ) {
			$old = (int) ( $row[ $col ] ?? 0 );
			if ( $old <= 0 ) {
				$row[ $col ] = 0;
				continue;
			}
			if ( 'project_id' === $col ) {
				$row[ $col ] = $old === $this->state['old_project_id'] ? $this->state['project_id'] : ( array_key_exists( $old, $this->state['map']['projects'] ?? array() ) ? (int) $this->state['map']['projects'][ $old ] : 0 );
				if ( 'projects' !== $table && 0 === $row[ $col ] && $old === $this->state['old_project_id'] && $this->state['dry'] ) {
					$row[ $col ] = -1; // Proyecto por crear: marcador en la vista previa.
				}
				continue;
			}
			$resolved = $this->resolve_ref( $target, $old, $table, $position, $order );
			if ( 'defer' === $resolved ) {
				$deferred[ $col ] = $old;
				$row[ $col ]      = 0;
			} else {
				$row[ $col ] = (int) $resolved;
			}
		}
		foreach ( DataSchema::polymorphic()[ $table ] ?? array() as $pair ) {
			$target = DataSchema::entity_table( (string) ( $row[ $pair[0] ] ?? '' ) );
			$old    = (int) ( $row[ $pair[1] ] ?? 0 );
			if ( ! $target || $old <= 0 ) {
				continue;
			}
			$resolved = $this->resolve_ref( $target, $old, $table, $position, $order );
			if ( 'defer' === $resolved ) {
				$deferred[ $pair[1] ] = $old;
				$row[ $pair[1] ]      = 0;
			} else {
				$row[ $pair[1] ] = (int) $resolved;
				if ( 0 === $row[ $pair[1] ] ) {
					return null; // Un vínculo o referencia sin entidad no tiene sentido.
				}
			}
		}
		foreach ( DataSchema::user_columns() as $col ) {
			if ( ! array_key_exists( $col, $row ) || isset( $refs[ $col ] ) ) {
				continue;
			}
			$old         = (string) (int) $row[ $col ];
			$row[ $col ] = (int) $row[ $col ] > 0 ? (int) ( $this->state['users'][ $old ] ?? 0 ) : 0;
		}
		if ( in_array( $table, array( 'project_members', 'assignments' ), true ) && 0 === (int) ( $row['user_id'] ?? 0 ) ) {
			return null;
		}
		if ( 'meeting_attendees' === $table && 0 === (int) ( $row['user_id'] ?? 0 ) && '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
			return null;
		}
		if ( 'agreements' === $table && is_array( $row['follow_up'] ?? null ) ) {
			foreach ( $row['follow_up'] as &$entry ) {
				if ( is_array( $entry ) && ! empty( $entry['meeting_id'] ) ) {
					$entry['meeting_id'] = (int) ( $this->state['map']['meetings'][ (int) $entry['meeting_id'] ] ?? 0 );
				}
			}
			unset( $entry );
		}
		if ( 'document_versions' === $table ) {
			$row['path'] = $this->new_attachment_path( $row );
		}

		/**
		 * Permite a los módulos reasignar identificadores guardados dentro de
		 * columnas JSON (por ejemplo, documentos de respaldo de un pago).
		 *
		 * @param array<string,mixed>                $row   Fila con referencias ya reasignadas.
		 * @param string                             $table Tabla.
		 * @param array<string,array<int,int|null>> $map   Tabla => identificador antiguo => nuevo (null: por crear).
		 */
		$row = (array) apply_filters( 'gdp_data_import_row', $row, $table, $this->state['map'] );
		// Filas hijas cuyo padre no se importó (omitido o sin usuario) se omiten.
		foreach ( DataSchema::parents() as $child => $pair ) {
			if ( $child === $table && 0 === (int) ( $row[ $pair[0] ] ?? 0 ) && ! isset( $deferred[ $pair[0] ] ) ) {
				return null;
			}
		}

		return array( $row, $deferred );
	}

	/**
	 * Resuelve una referencia a otra tabla.
	 *
	 * @param string   $target   Tabla referida.
	 * @param int      $old      Identificador en el documento.
	 * @param string   $table    Tabla en curso.
	 * @param int      $position Posición de la tabla en curso en el orden de carga.
	 * @param string[] $order    Orden de carga.
	 * @return int|string Identificador local, 0 si no existe, o 'defer'.
	 */
	private function resolve_ref( string $target, int $old, string $table, int $position, array $order ) {
		if ( array_key_exists( $old, $this->state['map'][ $target ] ?? array() ) ) {
			$mapped = $this->state['map'][ $target ][ $old ];
			return null === $mapped ? -1 : (int) $mapped; // -1: registro por crear (solo en la vista previa).
		}
		$target_pos = (int) array_search( $target, $order, true );
		if ( $target === $table || $target_pos > $position ) {
			return 'defer';
		}
		// Tabla no incluida en el documento: en modo de actualización se conserva la referencia si el registro existe en el proyecto.
		if ( 'update' === $this->state['mode'] && ! isset( $this->state['tables'][ $target ] ) && $this->exists_in_project( $target, $old ) ) {
			return $old;
		}
		/* translators: 1: tabla, 2: tabla referida, 3: identificador. */
		$this->state['warnings'][] = sprintf( __( 'Referencia sin destino omitida: %1$s → %2$s %3$d.', 'gestion-de-proyectos' ), $table, $target, $old );

		return 0;
	}

	/**
	 * Comprueba que un registro existe en el sitio y pertenece al proyecto de destino (o es global).
	 *
	 * @param string $table Tabla.
	 * @param int    $id    Identificador.
	 * @return bool
	 */
	private function exists_in_project( string $table, int $id ): bool {
		global $wpdb;

		if ( ! DataSchema::has_id( $table ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( $table ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		if ( isset( $row['project_id'] ) ) {
			return in_array( (int) $row['project_id'], array( 0, $this->state['project_id'] ), true );
		}

		return true;
	}

	/**
	 * Registra referencias diferidas (autorreferencias y referencias hacia adelante).
	 *
	 * @param string            $table    Tabla.
	 * @param int               $local_id Identificador local de la fila.
	 * @param array<string,int> $deferred Columna => identificador antiguo.
	 * @return void
	 */
	private function register_deferred( string $table, int $local_id, array $deferred ): void {
		foreach ( $deferred as $col => $old ) {
			$this->state['deferred'][] = array( $table, $local_id, $col, $old );
		}
	}

	/**
	 * Segundo paso: escribe las referencias diferidas ya reasignadas.
	 *
	 * @return void
	 */
	private function apply_deferred(): void {
		global $wpdb;

		$refs = DataSchema::refs();
		$poly = DataSchema::polymorphic();
		foreach ( $this->state['deferred'] as $item ) {
			list( $table, $local_id, $col, $old ) = $item;
			$target                               = $refs[ $table ][ $col ] ?? null;
			if ( null === $target ) {
				foreach ( $poly[ $table ] ?? array() as $pair ) {
					if ( $pair[1] === $col ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$type   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ' . $pair[0] . ' FROM ' . Schema::table( $table ) . ' WHERE id = %d', $local_id ) );
						$target = DataSchema::entity_table( $type );
					}
				}
			}
			$new = $target ? (int) ( $this->state['map'][ $target ][ $old ] ?? 0 ) : 0;
			if ( $new > 0 && $local_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::table( $table ), array( $col => $new ), array( 'id' => $local_id ) );
			}
		}
	}

	/**
	 * Busca un registro existente por su clave natural.
	 *
	 * @param string              $table Tabla.
	 * @param array<string,mixed> $row   Fila preparada.
	 * @return array<string,mixed>|null
	 */
	private function find_existing( string $table, array $row, int $old_id = 0 ): ?array {
		global $wpdb;

		if ( 'new' === $this->state['mode'] && isset( $row['project_id'] ) && (int) $row['project_id'] !== 0 ) {
			return null; // En un proyecto nuevo nada existe todavía, salvo lo global.
		}
		$keys = DataSchema::keys()[ $table ] ?? array();
		if ( empty( $keys ) ) {
			return $this->find_same_id( $table, $row, $old_id );
		}
		foreach ( DataSchema::key_required()[ $table ] ?? array() as $required ) {
			if ( '' === trim( (string) ( $row[ $required ] ?? '' ) ) ) {
				return $this->find_same_id( $table, $row, $old_id );
			}
		}
		$where = array();
		$args  = array();
		foreach ( $keys as $k ) {
			if ( ! array_key_exists( $k, $row ) ) {
				return null;
			}
			$v = $row[ $k ];
			if ( is_int( $v ) && $v < 0 ) {
				return null; // Depende de un registro por crear.
			}
			if ( null === $v ) {
				$where[] = "{$k} IS NULL";
				continue;
			}
			$where[] = "{$k} = " . ( is_int( $v ) || is_bool( $v ) ? '%d' : '%s' );
			$args[]  = is_bool( $v ) ? (int) $v : $v;
		}
		$sql = 'SELECT * FROM ' . Schema::table( $table ) . ' WHERE ' . implode( ' AND ', $where ) . ( DataSchema::has_id( $table ) ? ' ORDER BY id ASC' : '' ) . ' LIMIT 20';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_results( empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $found ) ) {
			return null;
		}
		// Varias coincidencias (nombres repetidos): se prefiere la del mismo identificador, si viene del mismo sitio.
		foreach ( $found as $candidate ) {
			if ( $old_id > 0 && (int) ( $candidate['id'] ?? 0 ) === $old_id ) {
				return $candidate;
			}
		}

		return $found[0];
	}

	/**
	 * Registro con el mismo identificador dentro del proyecto de destino (importaciones del mismo sitio
	 * para filas sin clave natural, como cotizaciones sin número).
	 *
	 * @param string              $table  Tabla.
	 * @param array<string,mixed> $row    Fila preparada.
	 * @param int                 $old_id Identificador en el documento.
	 * @return array<string,mixed>|null
	 */
	private function find_same_id( string $table, array $row, int $old_id ): ?array {
		global $wpdb;

		if ( $old_id <= 0 || 'update' !== $this->state['mode'] || ! DataSchema::has_id( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( $table ) . ' WHERE id = %d', $old_id ), ARRAY_A );
		if ( ! $found ) {
			return null;
		}
		if ( isset( $found['project_id'] ) && (int) $found['project_id'] !== (int) ( $row['project_id'] ?? -1 ) ) {
			return null;
		}
		// La fila debe colgar del mismo padre ya reasignado.
		$parents = DataSchema::parents();
		if ( isset( $parents[ $table ] ) ) {
			$col = $parents[ $table ][0];
			if ( (int) ( $found[ $col ] ?? 0 ) !== (int) ( $row[ $col ] ?? -1 ) ) {
				return null;
			}
		}

		return $found;
	}

	/**
	 * Diferencias entre el registro existente y el importado (columnas no volátiles).
	 *
	 * @param string              $table    Tabla.
	 * @param array<string,mixed> $existing Fila del sitio.
	 * @param array<string,mixed> $incoming Fila preparada.
	 * @return array<string,array{0:string,1:string}>
	 */
	private function diff( string $table, array $existing, array $incoming ): array {
		$columns  = DataSchema::columns()[ $table ] ?? array();
		$json     = DataSchema::json_columns()[ $table ] ?? array();
		$volatile = DataSchema::volatile_columns();
		$out      = array();
		foreach ( $incoming as $col => $value ) {
			if ( ! isset( $columns[ $col ] ) || in_array( $col, $volatile, true ) || ! array_key_exists( $col, $existing ) ) {
				continue;
			}
			$type = in_array( $col, $json, true ) ? 'json' : DataSchema::logical_type( $columns[ $col ] );
			$a    = self::normalize( $existing[ $col ], $type );
			$b    = self::normalize( $value, $type );
			if ( $a !== $b ) {
				$out[ $col ] = array( mb_substr( $a, 0, 80 ), mb_substr( $b, 0, 80 ) );
			}
		}

		return $out;
	}

	/**
	 * Representación canónica de un valor para comparar.
	 *
	 * @param mixed  $value Valor.
	 * @param string $type  Tipo lógico.
	 * @return string
	 */
	public static function normalize( $value, string $type ): string {
		if ( null === $value ) {
			return '';
		}
		switch ( $type ) {
			case 'integer':
				return (string) (int) $value;
			case 'boolean':
				return ( is_bool( $value ) ? $value : (bool) (int) $value ) ? '1' : '0';
			case 'decimal':
				return rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
			case 'json':
				$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
				return null === $decoded ? (string) $value : (string) wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
			case 'time':
				return substr( (string) $value, 0, 5 );
		}

		return trim( (string) $value );
	}

	/**
	 * Convierte la fila a los tipos que espera la base de datos.
	 *
	 * @param string              $table Tabla.
	 * @param array<string,mixed> $row   Fila preparada.
	 * @return array<string,mixed>
	 */
	private function for_db( string $table, array $row ): array {
		$columns = DataSchema::columns()[ $table ] ?? array();
		$json    = DataSchema::json_columns()[ $table ] ?? array();
		$out     = array();
		foreach ( $columns as $col => $sql_type ) {
			if ( ! array_key_exists( $col, $row ) ) {
				continue;
			}
			$value = $row[ $col ];
			if ( in_array( $col, $json, true ) ) {
				$value = null === $value ? null : ( is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value );
			} elseif ( null !== $value ) {
				switch ( DataSchema::logical_type( $sql_type ) ) {
					case 'integer':
						$value = (int) $value;
						break;
					case 'boolean':
						$value = ( is_bool( $value ) ? $value : (bool) (int) $value ) ? 1 : 0;
						break;
					case 'decimal':
						$value = (float) $value;
						break;
					default:
						$value = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value;
				}
			}
			if ( null === $value && ! DataSchema::nullable( $table, $col ) ) {
				$value = in_array( DataSchema::logical_type( $sql_type ), array( 'integer', 'boolean', 'decimal' ), true ) ? 0 : ( 'datetime' === DataSchema::logical_type( $sql_type ) ? current_time( 'mysql', true ) : '' );
			}
			$out[ $col ] = $value;
		}
		if ( isset( $columns['created_at'] ) && empty( $out['created_at'] ) ) {
			$out['created_at'] = current_time( 'mysql', true );
		}
		if ( isset( $columns['updated_at'] ) ) {
			$out['updated_at'] = current_time( 'mysql', true );
		}
		if ( isset( $columns['version'] ) && empty( $out['version'] ) ) {
			$out['version'] = 1;
		}

		return $out;
	}

	/**
	 * Ruta de destino de una versión de archivo (con los identificadores nuevos del documento).
	 *
	 * @param array<string,mixed> $row Fila de document_versions ya reasignada.
	 * @return string
	 */
	private function new_attachment_path( array $row ): string {
		$path = (string) ( $row['path'] ?? '' );
		$doc  = (int) ( $row['document_id'] ?? 0 );
		if ( $doc <= 0 || $this->state['dry'] ) {
			return $path;
		}
		$base = basename( $path );
		if ( '' === $base ) {
			return $path;
		}

		return 'documentos/' . $this->state['project_id'] . '/' . $doc . '/' . $base;
	}

	/**
	 * Copia el adjunto de una versión desde el directorio extraído del ZIP.
	 *
	 * @param array<string,mixed> $original Fila original del documento.
	 * @param array<string,mixed> $written  Fila escrita.
	 * @param int                 $new_id   Identificador nuevo.
	 * @return void
	 */
	private function copy_attachment( array $original, array $written, int $new_id ): void {
		$dir = $this->state['attachments'];
		if ( '' === $dir ) {
			return;
		}
		$source = trailingslashit( $dir ) . ltrim( str_replace( '\\', '/', (string) ( $original['path'] ?? '' ) ), '/' );
		$target = trailingslashit( Storage::private_dir() ) . (string) $written['path'];
		if ( ! is_file( $source ) || false !== strpos( (string) $written['path'], '..' ) ) {
			/* translators: nombre del archivo. */
			$this->state['warnings'][] = sprintf( __( 'Adjunto no incluido en el paquete: %s.', 'gestion-de-proyectos' ), (string) ( $original['filename'] ?? $original['path'] ?? $new_id ) );
			return;
		}
		wp_mkdir_p( dirname( $target ) );
		if ( copy( $source, $target ) ) {
			$this->state['files'][] = (string) $written['path'];
		}
	}

	/**
	 * Restauración completa: vacía las tablas y reinserta las filas tal cual (identificadores incluidos).
	 *
	 * @param array<string,mixed> $document    Documento validado de un respaldo del sitio.
	 * @param string              $attachments Directorio con los adjuntos extraídos (o vacío).
	 * @return array<string,int>|WP_Error Filas insertadas por tabla.
	 */
	public static function replace_all( array $document, string $attachments = '' ) {
		global $wpdb;

		$columns = DataSchema::columns();
		$keep    = array( 'operations', 'connector_tokens' );
		$counts  = array();
		foreach ( array_keys( $columns ) as $table ) {
			if ( in_array( $table, $keep, true ) ) {
				continue;
			}
			$name = Schema::table( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$name}" );
			$counts[ $table ] = 0;
			$json             = DataSchema::json_columns()[ $table ] ?? array();
			foreach ( (array) ( $document['tables'][ $table ] ?? array() ) as $row ) {
				$write = array();
				foreach ( $columns[ $table ] as $col => $sql_type ) {
					if ( ! array_key_exists( $col, $row ) ) {
						continue;
					}
					$value = $row[ $col ];
					if ( in_array( $col, $json, true ) ) {
						$value = null === $value ? null : ( is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value );
					} elseif ( is_bool( $value ) ) {
						$value = $value ? 1 : 0;
					} elseif ( is_array( $value ) ) {
						$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
					}
					if ( null === $value && ! DataSchema::nullable( $table, $col ) ) {
						$value = in_array( DataSchema::logical_type( $sql_type ), array( 'integer', 'boolean', 'decimal' ), true ) ? 0 : '';
					}
					$write[ $col ] = $value;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( false !== $wpdb->insert( $name, $write ) ) {
					++$counts[ $table ];
				}
			}
		}
		if ( '' !== $attachments && is_dir( $attachments ) ) {
			$base = trailingslashit( Storage::private_dir() );
			$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $attachments, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$relative = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $attachments ) ) ), '/' );
				if ( false !== strpos( $relative, '..' ) ) {
					continue;
				}
				wp_mkdir_p( dirname( $base . $relative ) );
				copy( $file->getPathname(), $base . $relative );
			}
		}

		return $counts;
	}
}
