<?php
/**
 * Importación de cronogramas desde CSV, XLSX y XML de Microsoft Project.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Catalogs;
use GDP\Core\Spreadsheet;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte el archivo en filas normalizadas (la misma forma para los tres
 * formatos), resuelve jerarquía, predecesoras, frentes y responsables, y
 * produce una vista previa con advertencias. La aplicación la hace el
 * manejador de operaciones (acción import), de modo que la importación es
 * una sola operación reversible.
 */
final class ScheduleImporter {

	/**
	 * Sinónimos de cabecera aceptados (minúsculas, sin tildes).
	 *
	 * @var array<string,string[]>
	 */
	private const HEADERS = array(
		'code'            => array( 'codigo', 'code', 'edt', 'wbs', 'id', 'ref', 'referencia', 'numero', 'outline number', 'numero de esquema' ),
		'level'           => array( 'nivel', 'level', 'outline level', 'nivel de esquema' ),
		'name'            => array( 'nombre', 'name', 'actividad', 'tarea', 'task', 'task name', 'nombre de tarea', 'descripcion' ),
		'kind'            => array( 'tipo', 'kind', 'type' ),
		'duration'        => array( 'duracion', 'duration', 'duracion_dias_habiles', 'dias', 'days' ),
		'predecessors'    => array( 'predecesoras', 'predecessors', 'predecesora', 'depende de' ),
		'work_front'      => array( 'frente', 'work_front', 'front', 'frente de trabajo' ),
		'owner'           => array( 'responsable', 'owner', 'encargado', 'nombres de los recursos', 'resource names', 'recurso' ),
		'percent'         => array( 'avance', 'percent', '% completado', 'porcentaje', 'progreso', 'complete' ),
		'status'          => array( 'estado', 'status' ),
		'start'           => array( 'inicio', 'start', 'comienzo', 'fecha de inicio' ),
		'finish'          => array( 'termino', 'fin', 'finish', 'end', 'fecha de termino', 'fecha de fin' ),
		'constraint_type' => array( 'restriccion', 'constraint', 'constraint_type', 'tipo de restriccion' ),
		'constraint_date' => array( 'fecha_restriccion', 'fecha de restriccion', 'constraint date' ),
		'actual_start'    => array( 'inicio_real', 'inicio real', 'actual start' ),
		'actual_finish'   => array( 'termino_real', 'termino real', 'fin real', 'actual finish' ),
		'deliverable'     => array( 'entregable', 'deliverable', 'producto' ),
		'priority'        => array( 'prioridad', 'priority' ),
	);

	/**
	 * Lee un archivo subido y devuelve filas normalizadas.
	 *
	 * @param string $path     Ruta temporal.
	 * @param string $filename Nombre original (para el formato).
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function parse_file( string $path, string $filename ) {
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'xml' === $ext ) {
			return ProjectXml::parse( (string) file_get_contents( $path ) );
		}
		if ( 'xlsx' === $ext ) {
			$rows = Spreadsheet::read( $path );
		} elseif ( in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			$rows = self::read_csv( $path );
		} else {
			return new WP_Error( 'format', __( 'Formato no admitido: use CSV, XLSX o XML de Microsoft Project.', 'gestion-de-proyectos' ) );
		}
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return self::from_table( $rows );
	}

	/**
	 * Lee un CSV detectando el separador y la codificación.
	 *
	 * @param string $path Ruta.
	 * @return array<int,array<int,string>>|WP_Error
	 */
	private static function read_csv( string $path ) {
		$content = (string) file_get_contents( $path );
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}
		$first     = strtok( $content, "\n" );
		$separator = substr_count( (string) $first, ';' ) >= substr_count( (string) $first, ',' ) ? ';' : ',';
		if ( substr_count( (string) $first, "\t" ) > substr_count( (string) $first, $separator ) ) {
			$separator = "\t";
		}
		$rows   = array();
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $content );
		rewind( $handle );
		while ( ( $row = fgetcsv( $handle, 0, $separator, '"', '\\' ) ) !== false ) {
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue;
			}
			$rows[] = array_map( static fn( $v ): string => trim( (string) $v ), $row );
		}
		fclose( $handle );

		return empty( $rows ) ? new WP_Error( 'empty', __( 'El archivo está vacío.', 'gestion-de-proyectos' ) ) : $rows;
	}

	/**
	 * Convierte registros con nombre de campo (JSON del conector) en filas
	 * normalizadas. Los campos admiten los mismos nombres que las cabeceras de
	 * un CSV; las predecesoras pueden venir como texto ("1.2FS+3, 4") o como
	 * lista de objetos {ref, type, lag}.
	 *
	 * @param array<int,array<string,mixed>> $records Registros.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function from_records( array $records ) {
		$keys = array();
		foreach ( $records as $r ) {
			if ( is_array( $r ) ) {
				foreach ( array_keys( $r ) as $k ) {
					$keys[ (string) $k ] = true;
				}
			}
		}
		if ( empty( $keys ) ) {
			return new WP_Error( 'empty', __( 'No hay filas que importar.', 'gestion-de-proyectos' ) );
		}
		$header = array_keys( $keys );
		$table  = array( $header );
		foreach ( $records as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$row = array();
			foreach ( $header as $k ) {
				$v = $r[ $k ] ?? '';
				if ( is_array( $v ) ) {
					$v = implode(
						', ',
						array_map(
							static function ( $p ): string {
								if ( ! is_array( $p ) ) {
									return (string) $p;
								}
								$type = strtoupper( (string) ( $p['type'] ?? 'FS' ) );
								$lag  = (int) ( $p['lag'] ?? 0 );
								return (string) ( $p['ref'] ?? $p['predecessor'] ?? $p['code'] ?? '' ) . ( 'FS' !== $type || 0 !== $lag ? $type : '' ) . ( 0 !== $lag ? sprintf( '%+d', $lag ) : '' );
							},
							$v
						)
					);
				}
				$row[] = is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v;
			}
			$table[] = $row;
		}

		return self::from_table( $table );
	}

	/**
	 * Convierte una tabla (cabecera + filas) en filas normalizadas.
	 *
	 * @param array<int,array<int,string>> $table Tabla.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function from_table( array $table ) {
		if ( count( $table ) < 2 ) {
			return new WP_Error( 'empty', __( 'El archivo no tiene filas de datos.', 'gestion-de-proyectos' ) );
		}
		$header = array_map( array( self::class, 'normalize_header' ), $table[0] );
		$map    = array();
		$used   = array();
		// Primero coincidencias exactas; después cabeceras con calificativos ("duracion (dias habiles)").
		foreach ( array( true, false ) as $exact ) {
			foreach ( self::HEADERS as $field => $synonyms ) {
				if ( isset( $map[ $field ] ) ) {
					continue;
				}
				foreach ( $header as $i => $h ) {
					if ( isset( $used[ $i ] ) ) {
						continue;
					}
					$hit = $exact ? in_array( $h, $synonyms, true ) : self::starts_with_any( $h, $synonyms );
					if ( $hit ) {
						$map[ $field ] = $i;
						$used[ $i ]    = true;
						break;
					}
				}
			}
		}
		if ( ! isset( $map['name'] ) ) {
			return new WP_Error( 'header', __( 'No se encontró la columna de nombre de la actividad (Nombre, Actividad o Tarea).', 'gestion-de-proyectos' ) );
		}

		$get  = static fn( array $row, string $field ): string => isset( $map[ $field ] ) ? trim( (string) ( $row[ $map[ $field ] ] ?? '' ) ) : '';
		$rows = array();
		foreach ( array_slice( $table, 1 ) as $n => $row ) {
			$name = $get( $row, 'name' );
			if ( '' === $name ) {
				continue;
			}
			$code  = $get( $row, 'code' );
			$kind  = self::kind( $get( $row, 'kind' ) );
			$dur   = self::duration( $get( $row, 'duration' ) );
			$level = $get( $row, 'level' );
			$rows[] = array(
				'ref'             => '' !== $code ? $code : 'fila' . ( $n + 2 ),
				'code'            => $code,
				'level'           => '' !== $level ? (int) $level : null,
				'name'            => $name,
				'kind'            => $kind,
				'duration'        => $dur,
				'percent'         => max( 0, min( 100, (int) preg_replace( '/[^\d]/', '', $get( $row, 'percent' ) ) ) ),
				'status'          => self::status( $get( $row, 'status' ) ),
				'constraint_type' => self::constraint( $get( $row, 'constraint_type' ) ),
				'constraint_date' => self::date( $get( $row, 'constraint_date' ) ),
				'actual_start'    => self::date( $get( $row, 'actual_start' ) ),
				'actual_finish'   => self::date( $get( $row, 'actual_finish' ) ),
				'start'           => self::date( $get( $row, 'start' ) ),
				'finish'          => self::date( $get( $row, 'finish' ) ),
				'deliverable'     => $get( $row, 'deliverable' ),
				'priority'        => self::priority( $get( $row, 'priority' ) ),
				'predecessors'    => self::predecessors( $get( $row, 'predecessors' ) ),
				'work_front'      => $get( $row, 'work_front' ),
				'owner'           => $get( $row, 'owner' ),
			);
		}

		return empty( $rows ) ? new WP_Error( 'empty', __( 'El archivo no tiene actividades con nombre.', 'gestion-de-proyectos' ) ) : $rows;
	}

	/**
	 * Resuelve jerarquía, predecesoras, frentes y responsables y prepara la vista previa.
	 *
	 * @param array<int,array<string,mixed>> $rows       Filas normalizadas.
	 * @param int                            $project_id Proyecto.
	 * @return array{rows:array<int,array<string,mixed>>,warnings:string[],summary:array<string,int>}
	 */
	public static function prepare( array $rows, int $project_id ): array {
		$warnings = array();
		$by_ref   = array();
		$by_code  = array();
		foreach ( $rows as $i => $row ) {
			$by_ref[ (string) $row['ref'] ] = $i;
			if ( '' !== (string) $row['code'] && ! isset( $by_code[ (string) $row['code'] ] ) ) {
				$by_code[ (string) $row['code'] ] = (string) $row['ref'];
			}
		}

		// Jerarquía: por código jerárquico (1.2.3), por nivel de esquema o plana.
		$hierarchical = count( array_filter( $rows, static fn( array $r ): bool => '' !== $r['code'] && false !== strpos( (string) $r['code'], '.' ) ) ) > 0;
		$has_levels   = count( array_filter( $rows, static fn( array $r ): bool => null !== $r['level'] ) ) === count( $rows );
		$min_level    = $has_levels ? min( array_map( static fn( array $r ): int => (int) $r['level'], $rows ) ) : 0;
		$stack        = array();
		foreach ( $rows as $i => &$row ) {
			$row['parent_ref'] = null;
			if ( $hierarchical && '' !== $row['code'] ) {
				$parts = explode( '.', (string) $row['code'] );
				while ( count( $parts ) > 1 ) {
					array_pop( $parts );
					$candidate = implode( '.', $parts );
					if ( isset( $by_code[ $candidate ] ) && $by_code[ $candidate ] !== (string) $row['ref'] ) {
						$row['parent_ref'] = $by_code[ $candidate ];
						break;
					}
				}
			} elseif ( $has_levels ) {
				// Niveles normalizados a partir de 1 (Project y el CSV propio numeran distinto).
				$level             = (int) $row['level'] - $min_level + 1;
				$stack             = array_slice( $stack, 0, max( 0, $level - 1 ) );
				$row['parent_ref'] = $level > 1 && ! empty( $stack ) ? (string) end( $stack ) : null;
				$stack[]           = (string) $row['ref'];
			}
		}
		unset( $row );

		// Quien tiene hijos es resumen; los resúmenes declarados sin hijos se conservan
		// como fases vacías; quien dura cero sin hijos es hito.
		$has_children = array();
		foreach ( $rows as $row ) {
			if ( null !== $row['parent_ref'] ) {
				$has_children[ $row['parent_ref'] ] = true;
			}
		}
		$fronts = array();
		foreach ( Catalogs::items( Catalogs::WORK_FRONT, $project_id ) as $item ) {
			$fronts[ $item['slug'] ]                                   = $item['slug'];
			$fronts[ self::normalize_header( (string) $item['label'] ) ] = $item['slug'];
		}
		$unknown_fronts = array();
		$unknown_owners = array();
		$counts         = array( 'summary' => 0, 'activity' => 0, 'milestone' => 0, 'dependencies' => 0 );

		// Actividades ya existentes en el proyecto, por código: una predecesora
		// que no esté en el archivo puede apuntar a ellas.
		$existing = array();
		foreach ( ActivityRepository::for_project( $project_id ) as $a ) {
			if ( 'summary' !== $a['kind'] && '' !== (string) $a['code'] ) {
				$existing[ (string) $a['code'] ] = (int) $a['id'];
			}
		}

		foreach ( $rows as $i => &$row ) {
			if ( isset( $has_children[ $row['ref'] ] ) ) {
				$row['kind'] = 'summary';
			}
			if ( 'activity' === $row['kind'] && 0 === (int) $row['duration'] ) {
				$row['kind'] = 'milestone';
			}
			if ( 'activity' === $row['kind'] && (int) $row['duration'] < 1 ) {
				$row['duration'] = 1;
			}
			++$counts[ $row['kind'] ];

			// Frente.
			$row['work_front_slug'] = '';
			if ( '' !== $row['work_front'] ) {
				$key = self::normalize_header( (string) $row['work_front'] );
				$row['work_front_slug'] = $fronts[ $key ] ?? $fronts[ $row['work_front'] ] ?? sanitize_key( str_replace( '-', '_', sanitize_title( (string) $row['work_front'] ) ) );
				if ( ! isset( $fronts[ $key ] ) && ! isset( $fronts[ $row['work_front'] ] ) ) {
					$unknown_fronts[ $row['work_front_slug'] ] = $row['work_front'];
				}
			}

			// Responsable: nombre, usuario o correo.
			$row['owner_id'] = 0;
			if ( '' !== $row['owner'] ) {
				$user = get_user_by( 'email', $row['owner'] ) ?: get_user_by( 'login', $row['owner'] );
				if ( ! $user ) {
					$found = get_users( array( 'search' => '*' . $row['owner'] . '*', 'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ), 'number' => 2 ) );
					$user  = 1 === count( $found ) ? $found[0] : null;
				}
				if ( $user ) {
					$row['owner_id'] = (int) $user->ID;
				} else {
					$unknown_owners[ $row['owner'] ] = true;
				}
			}

			// Predecesoras por referencia (código o fila) o por posición numérica.
			$resolved = array();
			foreach ( $row['predecessors'] as $p ) {
				$ref = (string) $p['ref'];
				if ( isset( $by_ref[ $ref ] ) ) {
					$resolved[] = array( 'ref' => $ref, 'type' => $p['type'], 'lag' => $p['lag'] );
				} elseif ( isset( $by_code[ $ref ] ) ) {
					$resolved[] = array( 'ref' => $by_code[ $ref ], 'type' => $p['type'], 'lag' => $p['lag'] );
				} elseif ( isset( $existing[ $ref ] ) ) {
					$resolved[] = array( 'ref' => $ref, 'existing_id' => $existing[ $ref ], 'type' => $p['type'], 'lag' => $p['lag'] );
				} elseif ( ctype_digit( $ref ) && isset( $rows[ (int) $ref - 1 ] ) ) {
					$resolved[] = array( 'ref' => (string) $rows[ (int) $ref - 1 ]['ref'], 'type' => $p['type'], 'lag' => $p['lag'] );
				} else {
					/* translators: 1: nombre de la fila, 2: referencia de la predecesora. */
					$warnings[] = sprintf( __( 'Fila "%1$s": predecesora "%2$s" no encontrada; se omite.', 'gestion-de-proyectos' ), $row['name'], $ref );
				}
			}
			$row['predecessors'] = $resolved;
			$counts['dependencies'] += count( $resolved );

			// Sin predecesoras pero con fecha de inicio del archivo: se respeta como "no empezar antes de".
			if ( 'summary' !== $row['kind'] && empty( $resolved ) && 'asap' === $row['constraint_type'] && ! empty( $row['start'] ) && empty( $row['actual_start'] ) ) {
				$row['constraint_type'] = 'snet';
				$row['constraint_date'] = $row['start'];
			}
			if ( 'asap' !== $row['constraint_type'] && empty( $row['constraint_date'] ) ) {
				$row['constraint_type'] = 'asap';
			}
		}
		unset( $row );

		if ( ! empty( $unknown_fronts ) ) {
			/* translators: lista de frentes. */
			$warnings[] = sprintf( __( 'Frentes no catalogados (se crearán como claves nuevas; puede añadirlos al catálogo): %s.', 'gestion-de-proyectos' ), implode( ', ', array_values( $unknown_fronts ) ) );
		}
		if ( ! empty( $unknown_owners ) ) {
			/* translators: lista de responsables. */
			$warnings[] = sprintf( __( 'Responsables sin usuario en el sitio (quedan sin responsable): %s.', 'gestion-de-proyectos' ), implode( ', ', array_keys( $unknown_owners ) ) );
		}

		return array( 'rows' => array_values( $rows ), 'warnings' => $warnings, 'summary' => $counts );
	}

	/**
	 * Cabecera normalizada: minúsculas, sin tildes ni espacios sobrantes.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function normalize_header( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = strtr( $text, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u' ) );
		$text = preg_replace( '/[^a-z0-9%_ ]+/', ' ', $text );

		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * Indica si una cabecera normalizada empieza por alguno de los sinónimos
	 * seguido de un calificativo ("duracion dias habiles", "inicio programado").
	 *
	 * @param string   $header   Cabecera normalizada.
	 * @param string[] $synonyms Sinónimos.
	 * @return bool
	 */
	private static function starts_with_any( string $header, array $synonyms ): bool {
		foreach ( $synonyms as $s ) {
			if ( strlen( $s ) >= 3 && 0 === strpos( $header, $s . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tipo a partir de un texto libre.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function kind( string $text ): string {
		$t = self::normalize_header( $text );
		if ( in_array( $t, array( 'resumen', 'summary', 'fase', 'paquete', 'grupo', 'etapa' ), true ) ) {
			return 'summary';
		}
		if ( in_array( $t, array( 'hito', 'milestone' ), true ) ) {
			return 'milestone';
		}

		return 'activity';
	}

	/**
	 * Duración en días hábiles a partir de "5", "5 d", "2 sem", "1.5w", "40 h".
	 *
	 * @param string $text Texto.
	 * @return int
	 */
	private static function duration( string $text ): int {
		$t = strtolower( trim( $text ) );
		if ( '' === $t ) {
			return 1;
		}
		if ( ! preg_match( '/^([\d.,]+)\s*([a-z]*)/', $t, $m ) ) {
			return 1;
		}
		$value = (float) str_replace( ',', '.', $m[1] );
		$unit  = $m[2];
		if ( in_array( $unit, array( 'w', 'wk', 'wks', 'sem', 'semana', 'semanas', 'week', 'weeks' ), true ) ) {
			$value *= 5;
		} elseif ( in_array( $unit, array( 'h', 'hr', 'hrs', 'hora', 'horas', 'hour', 'hours' ), true ) ) {
			$value /= 8;
		} elseif ( in_array( $unit, array( 'm', 'mes', 'meses', 'mo', 'mon', 'month', 'months' ), true ) ) {
			$value *= 20;
		}

		return max( 0, (int) ceil( $value ) );
	}

	/**
	 * Estado a partir de un texto.
	 *
	 * @param string $text Texto.
	 * @return string|null
	 */
	private static function status( string $text ): ?string {
		$t = self::normalize_header( $text );
		$map = array(
			'pendiente'  => 'pendiente',
			'en curso'   => 'en_curso',
			'en_curso'   => 'en_curso',
			'iniciada'   => 'en_curso',
			'terminada'  => 'terminada',
			'completada' => 'terminada',
			'finalizada' => 'terminada',
			'suspendida' => 'suspendida',
			'cancelada'  => 'cancelada',
		);

		return $map[ $t ] ?? null;
	}

	/**
	 * Restricción a partir de un texto (clave interna o nombre de Project).
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function constraint( string $text ): string {
		$t = self::normalize_header( $text );
		$map = array(
			'snet' => 'snet', 'snlt' => 'snlt', 'fnet' => 'fnet', 'fnlt' => 'fnlt', 'mso' => 'mso', 'mfo' => 'mfo',
			'no empezar antes de' => 'snet', 'no empezar despues de' => 'snlt', 'no terminar antes de' => 'fnet', 'no terminar despues de' => 'fnlt', 'debe empezar el' => 'mso', 'debe terminar el' => 'mfo',
			'start no earlier than' => 'snet', 'start no later than' => 'snlt', 'finish no earlier than' => 'fnet', 'finish no later than' => 'fnlt', 'must start on' => 'mso', 'must finish on' => 'mfo',
		);

		return $map[ $t ] ?? 'asap';
	}

	/**
	 * Prioridad 1 a 3 a partir de un texto.
	 *
	 * @param string $text Texto.
	 * @return int
	 */
	private static function priority( string $text ): int {
		$t = self::normalize_header( $text );
		if ( in_array( $t, array( '1', 'alta', 'high' ), true ) ) {
			return 1;
		}
		if ( in_array( $t, array( '3', 'baja', 'low' ), true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Fecha en varios formatos (AAAA-MM-DD, DD-MM-AAAA, DD/MM/AAAA).
	 *
	 * @param string $text Texto.
	 * @return string|null
	 */
	private static function date( string $text ): ?string {
		$t = trim( $text );
		if ( '' === $t ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $t, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] ) : null;
		}
		if ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $t, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ? sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ) : null;
		}

		return null;
	}

	/**
	 * Lista de predecesoras en notación compacta ("1.2FS+3, 1.3SS-1, 4").
	 *
	 * @param string $text Texto.
	 * @return array<int,array{ref:string,type:string,lag:int}>
	 */
	private static function predecessors( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[;,]+/', $text ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^([\w.\-]+?)\s*(FS|SS|FF|SF|FC|CC|FF|CF)?\s*([+-]\s*\d+)?\s*[a-z]*$/i', $part, $m ) ) {
				$type = strtoupper( $m[2] ?? '' );
				$type = array( 'FC' => 'FS', 'CC' => 'SS', 'CF' => 'SF' )[ $type ] ?? $type;
				$out[] = array(
					'ref'  => $m[1],
					'type' => '' === $type ? 'FS' : $type,
					'lag'  => isset( $m[3] ) ? (int) str_replace( ' ', '', $m[3] ) : 0,
				);
			}
		}

		return $out;
	}
}
