<?php
/**
 * Manejador de operaciones de datos: importación y restauración de respaldos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Storage;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Las importaciones y las restauraciones pasan por la capa de operaciones:
 * la vista previa es el plan (qué se crea, qué se actualiza, qué entra en
 * conflicto), la confirmación aplica el lote (completo o solo las tablas
 * elegidas) y la reversión lo deshace entero.
 */
final class DataHandler implements HandlerInterface {

	public const STAGING = 'importaciones';

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'data';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array( 'import', 'restore_backup' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( 'restore_backup' === $action ) {
			return Access::is_manager( $user_id );
		}
		if ( 'update' === ( $payload['mode'] ?? 'new' ) ) {
			$project = $project_id > 0 ? ProjectRepository::find( $project_id ) : ProjectRepository::find_by_code( (string) ( $payload['project_code'] ?? '' ) );
			return $project ? Access::can( 'data.import', (int) $project['id'], $user_id ) : Access::is_manager( $user_id );
		}

		return Access::is_manager( $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'import':
				$mode = in_array( $payload['mode'] ?? '', Importer::MODES, true ) ? (string) $payload['mode'] : 'new';
				$file = (string) ( $payload['file'] ?? '' );
				if ( '' === $file && isset( $payload['document'] ) ) {
					$file = self::stage( $payload['document'], 'conector' );
					if ( is_wp_error( $file ) ) {
						return $file;
					}
				}
				$path = self::staged_path( $file );
				if ( null === $path || ! is_file( $path ) ) {
					return new WP_Error( 'file', __( 'El archivo a importar no está disponible; vuelva a subirlo.', 'gestion-de-proyectos' ) );
				}
				$read = Importer::read_file( $path );
				if ( is_wp_error( $read ) ) {
					return $read;
				}
				$tables = array();
				foreach ( (array) ( $payload['tables'] ?? array() ) as $t ) {
					$t = sanitize_key( (string) $t );
					if ( isset( $read['document']['tables'][ $t ] ) ) {
						$tables[] = $t;
					}
				}
				$code = sanitize_text_field( (string) ( $payload['project_code'] ?? '' ) );
				// Tablas que el usuario no puede escribir en el proyecto de destino (núcleo y módulos con
				// permiso propio): se omiten. Al confirmar se conservan las omisiones de la propuesta, para
				// que se aplique exactamente lo que mostró la vista previa aunque confirme otra persona.
				$target = $project_id;
				if ( $target <= 0 && 'update' === $mode ) {
					$found  = ProjectRepository::find_by_code( '' !== $code ? $code : sanitize_text_field( (string) ( $read['document']['tables']['projects'][0]['code'] ?? '' ) ) );
					$target = $found ? (int) $found['id'] : 0;
				}
				$skip = array();
				if ( 'update' === $mode && $target > 0 ) {
					$blocked = array_merge( DataSchema::blocked_import_tables( $target ), array_map( 'sanitize_key', (array) ( $payload['skip'] ?? array() ) ) );
					$skip    = array_values( array_unique( array_intersect( $blocked, array_keys( (array) ( $read['document']['tables'] ?? array() ) ) ) ) );
				}
				$clean = array( 'file' => $file, 'mode' => $mode, 'project_code' => $code, 'tables' => array_values( array_diff( $tables, $skip ) ), 'skip' => $skip );
				$plan  = Importer::plan( $read['document'], $clean );
				if ( is_wp_error( $plan ) ) {
					return $plan;
				}
				if ( 'update' === $mode && $project_id > 0 && (int) $plan['project']['project_id'] !== $project_id ) {
					return new WP_Error( 'project_mismatch', __( 'El archivo corresponde a otro proyecto.', 'gestion-de-proyectos' ) );
				}
				$clean['project_code'] = (string) $plan['project']['code'];

				return $clean;

			case 'restore_backup':
				$name = basename( (string) ( $payload['backup'] ?? ( $payload['file'] ?? '' ) ) );
				$meta = BackupService::meta( $name );
				if ( ! $meta ) {
					return new WP_Error( 'not_found', __( 'El respaldo no existe.', 'gestion-de-proyectos' ) );
				}
				if ( (int) ( $meta['schema_version'] ?? 0 ) > (int) GDP_DB_VERSION ) {
					return new WP_Error( 'schema_version', __( 'El respaldo proviene de un esquema más nuevo que el de este sitio.', 'gestion-de-proyectos' ) );
				}

				return array( 'backup' => $name );
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$preview = array( 'summary' => '', 'changes' => array(), 'warnings' => array(), 'conflicts' => array() );
		if ( 'restore_backup' === $action ) {
			$meta               = BackupService::meta( $payload['backup'] ) ?? array();
			$preview['summary'] = sprintf( 'Restaurar el respaldo %s del %s', $payload['backup'], substr( (string) ( $meta['created_at'] ?? '' ), 0, 10 ) );
			$preview['changes'] = array( 'rows' => array( 'before' => array_sum( Exporter::site( array( 'resolve' => false ) )['counts'] ), 'after' => (int) ( $meta['rows'] ?? 0 ) ), 'projects' => array( 'before' => null, 'after' => implode( ', ', (array) ( $meta['projects'] ?? array() ) ) ) );
			$preview['warnings'][] = 'Se vaciarán todas las tablas del plugin (salvo tokens del conector y operaciones) y se cargarán las del respaldo, adjuntos incluidos. Antes se creará un respaldo de seguridad, que es el que se restaura al revertir.';
			if ( (int) ( $meta['schema_version'] ?? 0 ) < (int) GDP_DB_VERSION ) {
				$preview['warnings'][] = sprintf( 'El respaldo es de un esquema anterior (%d); las columnas nuevas quedarán con sus valores por defecto.', (int) $meta['schema_version'] );
			}

			return $preview;
		}
		$read = Importer::read_file( (string) self::staged_path( $payload['file'] ) );
		if ( is_wp_error( $read ) ) {
			$preview['conflicts'][] = $read->get_error_message();
			return $preview;
		}
		$plan = Importer::plan( $read['document'], $payload );
		if ( is_wp_error( $plan ) ) {
			$preview['conflicts'][] = $plan->get_error_message();
			return $preview;
		}
		$preview['summary'] = sprintf(
			'%s el proyecto %s %s: %d registros nuevos, %d actualizados, %d sin cambios%s',
			'new' === $plan['mode'] ? 'Importar como proyecto nuevo' : 'Actualizar',
			$plan['project']['code'],
			$plan['project']['name'],
			$plan['totals']['create'],
			$plan['totals']['update'],
			$plan['totals']['unchanged'],
			$plan['totals']['skipped'] > 0 ? sprintf( ', %d omitidos', $plan['totals']['skipped'] ) : ''
		);
		foreach ( $plan['tables'] as $table => $t ) {
			$preview['changes'][ $table ] = array( 'before' => null, 'after' => $t );
		}
		$preview['plan']      = $plan;
		$preview['warnings']  = $plan['warnings'];
		$preview['conflicts'] = $plan['conflicts'];
		if ( ! empty( $payload['tables'] ) ) {
			$preview['warnings'][] = sprintf( 'Aplicación parcial: solo se cargan las tablas %s.', implode( ', ', $payload['tables'] ) );
		}
		if ( ! empty( $payload['skip'] ) ) {
			/* translators: lista de tablas. */
			$preview['warnings'][] = sprintf( __( 'Sin permiso para escribir estas tablas en el proyecto; sus cambios se omiten: %s.', 'gestion-de-proyectos' ), implode( ', ', (array) $payload['skip'] ) );
		}

		return $preview;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $action, array $payload, int $project_id ) {
		if ( 'restore_backup' === $action ) {
			$result = BackupService::restore( $payload['backup'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array( 'before' => array( 'safety' => $result['safety'] ), 'result' => array( 'backup' => $payload['backup'], 'rows' => array_sum( $result['counts'] ), 'counts' => $result['counts'] ) );
		}
		$read = Importer::read_file( (string) self::staged_path( $payload['file'] ) );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$payload['attachments'] = $read['attachments'];
		$applied                = Importer::apply( $read['document'], $payload );
		BackupService::remove_dir( $read['attachments'] );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$result = $applied['result'];
		Audit::log( 'project', (int) $result['project_id'], 'import', (int) $result['project_id'], sprintf( 'Importación del proyecto %s (%s): %d creados, %d actualizados', $payload['project_code'], $payload['mode'], array_sum( $result['created'] ), array_sum( $result['updated'] ) ), null, $result );

		return array( 'before' => $applied['before'], 'result' => $result + array( 'totals' => $applied['totals'], 'warnings' => $applied['warnings'] ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id ) {
		if ( 'restore_backup' === $action ) {
			$safety = (string) ( $before['safety'] ?? '' );
			if ( '' === $safety ) {
				return new WP_Error( 'nothing_to_revert', __( 'No hay respaldo de seguridad asociado.', 'gestion-de-proyectos' ) );
			}
			$restored = BackupService::restore( $safety );

			return is_wp_error( $restored ) ? $restored : true;
		}
		if ( ! $before ) {
			return new WP_Error( 'nothing_to_revert', __( 'No hay estado anterior guardado.', 'gestion-de-proyectos' ) );
		}

		return Importer::revert( $before );
	}

	/**
	 * Guarda un archivo subido o un documento en el área de importaciones del directorio privado.
	 *
	 * @param mixed  $source Ruta de un archivo subido (string) o documento (array) a serializar.
	 * @param string $label  Etiqueta para el nombre.
	 * @return string|WP_Error Nombre relativo (importaciones/...).
	 */
	public static function stage( $source, string $label = 'archivo' ) {
		Storage::ensure_private_dir();
		$dir = trailingslashit( Storage::private_dir() ) . self::STAGING;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'dir', __( 'No se pudo crear la carpeta de importaciones.', 'gestion-de-proyectos' ) );
		}
		$stamp = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
		if ( is_array( $source ) ) {
			$name = $stamp . '-' . sanitize_file_name( $label ) . '.json';
			file_put_contents( $dir . '/' . $name, wp_json_encode( $source, JSON_UNESCAPED_UNICODE ) );
		} else {
			$original = sanitize_file_name( $label );
			$ext      = strtolower( (string) pathinfo( $original, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'json', 'zip' ), true ) ) {
				return new WP_Error( 'type', __( 'Suba un archivo JSON o ZIP exportado por el plugin.', 'gestion-de-proyectos' ) );
			}
			$name = $stamp . '-' . $original;
			if ( ! is_file( (string) $source ) || ! copy( (string) $source, $dir . '/' . $name ) ) {
				return new WP_Error( 'upload', __( 'No se pudo guardar el archivo subido.', 'gestion-de-proyectos' ) );
			}
		}

		return self::STAGING . '/' . $name;
	}

	/**
	 * Ruta absoluta validada de un archivo del área de importaciones.
	 *
	 * @param string $relative Nombre relativo.
	 * @return string|null
	 */
	public static function staged_path( string $relative ): ?string {
		$relative = str_replace( array( '..', "\0", '\\' ), '', $relative );
		if ( 0 !== strpos( $relative, self::STAGING . '/' ) ) {
			return null;
		}

		return trailingslashit( Storage::private_dir() ) . $relative;
	}

	/**
	 * Elimina archivos de importación más antiguos que el plazo indicado.
	 *
	 * @param int $days Días.
	 * @return int
	 */
	public static function cleanup( int $days = 7 ): int {
		$dir = trailingslashit( Storage::private_dir() ) . self::STAGING;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		$limit   = time() - $days * DAY_IN_SECONDS;
		foreach ( (array) scandir( $dir ) as $entry ) {
			$path = $dir . '/' . $entry;
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( is_file( $path ) && filemtime( $path ) < $limit ) {
				wp_delete_file( $path );
				++$removed;
			} elseif ( is_dir( $path ) && filemtime( $path ) < $limit ) {
				BackupService::remove_dir( $path );
				++$removed;
			}
		}

		return $removed;
	}
}
