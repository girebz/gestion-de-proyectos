<?php
/**
 * Motor de programación (método de la ruta crítica).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Planning;

/**
 * Calcula fechas tempranas y tardías, holguras y ruta crítica sobre un
 * calendario laboral. No depende de WordPress.
 *
 * Convenciones (véase la decisión de arquitectura 0011):
 * - El tiempo se mide en fronteras de días hábiles: la frontera k es el
 *   inicio del día hábil k (equivalentemente, el final del día k-1). El día
 *   hábil 0 es el primer día laborable igual o posterior al inicio del proyecto.
 * - Una actividad de duración d que comienza en la frontera S ocupa los días
 *   S ... S+d-1 y termina en la frontera F = S + d. Su fecha de inicio es el
 *   día S y su fecha de término el día F-1.
 * - Un hito es un evento de duración cero en la frontera F, que se muestra
 *   con la fecha del día F-1 ("se alcanza al terminar ese día"). Por tanto,
 *   un hito fin a inicio tras una actividad que termina el día X se fecha el
 *   mismo día X, y una actividad fin a inicio tras un hito fechado el día X
 *   comienza el día X+1.
 * - Los adelantos y retrasos de las dependencias se expresan en días hábiles.
 * - Las restricciones de fecha y las fechas reales se respetan aunque las
 *   dependencias exijan otra cosa; la discrepancia se informa como conflicto.
 */
final class Scheduler {

	public const TYPES       = array( 'FS', 'SS', 'FF', 'SF' );
	public const CONSTRAINTS = array( 'asap', 'snet', 'snlt', 'fnet', 'fnlt', 'mso', 'mfo' );
	public const KINDS       = array( 'summary', 'activity', 'milestone' );

	/**
	 * Calendario laboral.
	 *
	 * @var WorkCalendar
	 */
	private WorkCalendar $calendar;

	/**
	 * Primer día hábil del proyecto (día 0).
	 *
	 * @var string
	 */
	private string $day0;

	/**
	 * Término contractual (opcional).
	 *
	 * @var string|null
	 */
	private ?string $project_end;

	/**
	 * Caché de conversión índice => fecha.
	 *
	 * @var array<int,string>
	 */
	private array $day_cache = array();

	/**
	 * Constructor.
	 *
	 * @param WorkCalendar $calendar      Calendario.
	 * @param string       $project_start Inicio del proyecto (Y-m-d).
	 * @param string|null  $project_end   Término contractual (Y-m-d) o null.
	 */
	public function __construct( WorkCalendar $calendar, string $project_start, ?string $project_end = null ) {
		$this->calendar    = $calendar;
		$this->day0        = $calendar->next_working( $project_start );
		$this->project_end = $project_end ? WorkCalendar::normalize( $project_end ) : null;
	}

	/**
	 * Índice de día hábil de una fecha (0 = inicio del proyecto; negativo si es anterior).
	 *
	 * @param string $date Fecha.
	 * @return int
	 */
	public function index( string $date ): int {
		return $this->calendar->working_days_between( $this->day0, $date );
	}

	/**
	 * Fecha del día hábil de índice k.
	 *
	 * @param int $k Índice.
	 * @return string
	 */
	public function day( int $k ): string {
		if ( ! isset( $this->day_cache[ $k ] ) ) {
			$this->day_cache[ $k ] = $this->calendar->add_working_days( $this->day0, $k );
		}

		return $this->day_cache[ $k ];
	}

	/**
	 * Duración en días hábiles de un intervalo cerrado de fechas.
	 *
	 * @param string $start Inicio.
	 * @param string $end   Término.
	 * @return int
	 */
	public function duration_between( string $start, string $end ): int {
		return $this->calendar->count_working_days( $start, $end );
	}

	/**
	 * Programa el conjunto de actividades.
	 *
	 * Cada actividad: id, parent_id, kind (summary|activity|milestone), duration,
	 * constraint_type, constraint_date, actual_start, actual_finish, percent.
	 * Cada dependencia: predecessor_id, successor_id, type (FS|SS|FF|SF), lag.
	 *
	 * @param array<int,array<string,mixed>> $activities   Actividades.
	 * @param array<int,array<string,mixed>> $dependencies Dependencias.
	 * @return array<string,mixed> Resultado (ok, errors, cycle, project, nodes).
	 */
	public function schedule( array $activities, array $dependencies ): array {
		$errors = array();
		$nodes  = array();
		$byid   = array();

		foreach ( $activities as $a ) {
			$id          = (int) $a['id'];
			$byid[ $id ] = $a;
		}

		// Nodos programables: actividades e hitos (los resúmenes se calculan por agregación).
		foreach ( $byid as $id => $a ) {
			$kind = (string) ( $a['kind'] ?? 'activity' );
			if ( 'summary' === $kind ) {
				continue;
			}
			$duration = 'milestone' === $kind ? 0 : max( 1, (int) ( $a['duration'] ?? 1 ) );

			$actual_start  = ! empty( $a['actual_start'] ) ? (string) $a['actual_start'] : null;
			$actual_finish = ! empty( $a['actual_finish'] ) ? (string) $a['actual_finish'] : null;
			if ( 'milestone' === $kind ) {
				// Un hito se alcanza en un instante: cualquiera de las dos fechas reales lo fija.
				$actual_finish = $actual_finish ?? $actual_start;
				$actual_start  = null;
			}

			$nodes[ $id ] = array(
				'id'              => $id,
				'kind'            => $kind,
				'd'               => $duration,
				'percent'         => max( 0, min( 100, (int) ( $a['percent'] ?? 0 ) ) ),
				'constraint_type' => (string) ( $a['constraint_type'] ?? 'asap' ),
				'constraint_date' => ! empty( $a['constraint_date'] ) ? (string) $a['constraint_date'] : null,
				'actual_start'    => $actual_start,
				'actual_finish'   => $actual_finish,
				'preds'           => array(),
				'succs'           => array(),
				'conflicts'       => array(),
			);
		}

		// Dependencias válidas.
		$seen = array();
		foreach ( $dependencies as $dep ) {
			$p    = (int) $dep['predecessor_id'];
			$s    = (int) $dep['successor_id'];
			$type = strtoupper( (string) ( $dep['type'] ?? 'FS' ) );
			$lag  = (int) ( $dep['lag'] ?? 0 );

			if ( $p === $s ) {
				$errors[] = sprintf( 'La actividad %d no puede depender de sí misma.', $p );
				continue;
			}
			if ( ! isset( $nodes[ $p ] ) || ! isset( $nodes[ $s ] ) ) {
				$errors[] = sprintf( 'Dependencia %d → %d: ambas deben ser actividades o hitos existentes (no resúmenes).', $p, $s );
				continue;
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$errors[] = sprintf( 'Dependencia %d → %d: tipo desconocido %s.', $p, $s, $type );
				continue;
			}
			if ( isset( $seen[ $p . '-' . $s ] ) ) {
				continue;
			}
			$seen[ $p . '-' . $s ] = true;

			$nodes[ $p ]['succs'][] = array( 'id' => $s, 'type' => $type, 'lag' => $lag );
			$nodes[ $s ]['preds'][] = array( 'id' => $p, 'type' => $type, 'lag' => $lag );
		}

		// Orden topológico (Kahn).
		$indeg = array();
		foreach ( $nodes as $id => $n ) {
			$indeg[ $id ] = count( $n['preds'] );
		}
		$queue = array();
		foreach ( $indeg as $id => $deg ) {
			if ( 0 === $deg ) {
				$queue[] = $id;
			}
		}
		sort( $queue );
		$order = array();
		while ( ! empty( $queue ) ) {
			$id      = array_shift( $queue );
			$order[] = $id;
			foreach ( $nodes[ $id ]['succs'] as $edge ) {
				--$indeg[ $edge['id'] ];
				if ( 0 === $indeg[ $edge['id'] ] ) {
					$queue[] = $edge['id'];
				}
			}
		}

		$cycle = null;
		if ( count( $order ) < count( $nodes ) ) {
			$cycle    = array_values( array_diff( array_keys( $nodes ), $order ) );
			$errors[] = sprintf( 'Hay un ciclo de dependencias entre las actividades: %s.', implode( ', ', $cycle ) );

			return array(
				'ok'      => false,
				'errors'  => $errors,
				'cycle'   => $cycle,
				'project' => null,
				'nodes'   => array(),
			);
		}

		// Pase hacia adelante.
		foreach ( $order as $id ) {
			$n     = &$nodes[ $id ];
			$d     = $n['d'];
			$lower = 'milestone' === $n['kind'] ? 1 : 0;

			foreach ( $n['preds'] as $edge ) {
				$p = $nodes[ $edge['id'] ];
				switch ( $edge['type'] ) {
					case 'FS':
						$cand = $p['f'] + $edge['lag'];
						break;
					case 'SS':
						$cand = $p['s'] + $edge['lag'];
						break;
					case 'FF':
						$cand = $p['f'] + $edge['lag'] - $d;
						break;
					default: // SF.
						$cand = $p['s'] + $edge['lag'] - $d;
				}
				$lower = max( $lower, $cand );
			}

			$forced_s = null;
			$forced_f = null;

			$ctype = $n['constraint_type'];
			$cdate = $n['constraint_date'];
			if ( $cdate && 'asap' !== $ctype ) {
				switch ( $ctype ) {
					case 'snet':
						$lower = max( $lower, $this->start_boundary( $cdate, $n['kind'] ) );
						break;
					case 'fnet':
						$lower = max( $lower, $this->finish_boundary( $cdate ) - $d );
						break;
					case 'mso':
						$forced_s = $this->start_boundary( $cdate, $n['kind'] );
						break;
					case 'mfo':
						$forced_s = $this->finish_boundary( $cdate ) - $d;
						break;
				}
			}

			if ( $n['actual_start'] ) {
				$forced_s = $this->index( $n['actual_start'] );
			}
			if ( $n['actual_finish'] ) {
				$forced_f = $this->finish_boundary( $n['actual_finish'] );
				if ( null === $forced_s ) {
					$forced_s = $forced_f - $d;
				}
			}

			if ( null !== $forced_s && $forced_s < $lower ) {
				$n['conflicts'][] = sprintf(
					'La fecha fijada (restricción o fecha real) es %d día(s) hábil(es) anterior a lo que exigen sus predecesoras o el calendario.',
					$lower - $forced_s
				);
			}

			$n['s'] = null !== $forced_s ? $forced_s : $lower;
			$n['f'] = null !== $forced_f ? $forced_f : $n['s'] + $d;
			if ( $n['f'] < $n['s'] ) {
				$n['conflicts'][] = 'La fecha real de término es anterior a la de inicio.';
				$n['f'] = $n['s'];
			}

			// Inicio fijado: fecha real de inicio o restricción de fecha obligatoria.
			// Término fijado: fecha real de término o restricción obligatoria (que fija ambos extremos).
			$mandatory          = null !== $cdate && in_array( $ctype, array( 'mso', 'mfo' ), true );
			$n['fixed_start']   = null !== $forced_s;
			$n['fixed_finish']  = null !== $forced_f || $mandatory;
			$n['fixed']         = $n['fixed_start'] || $n['fixed_finish'];
			unset( $n );
		}

		$finish = 0;
		foreach ( $nodes as $n ) {
			$finish = max( $finish, $n['f'] );
		}

		// Pase hacia atrás.
		foreach ( array_reverse( $order ) as $id ) {
			$n = &$nodes[ $id ];
			$d = $n['f'] - $n['s'];

			$upper = $finish;
			foreach ( $n['succs'] as $edge ) {
				$s = $nodes[ $edge['id'] ];
				switch ( $edge['type'] ) {
					case 'FS':
						$cand = $s['ls'] - $edge['lag'];
						break;
					case 'SS':
						$cand = $s['ls'] - $edge['lag'] + $d;
						break;
					case 'FF':
						$cand = $s['lf'] - $edge['lag'];
						break;
					default: // SF.
						$cand = $s['lf'] - $edge['lag'] + $d;
				}
				$upper = min( $upper, $cand );
			}

			$ctype = $n['constraint_type'];
			$cdate = $n['constraint_date'];
			if ( $cdate ) {
				if ( 'snlt' === $ctype ) {
					$upper = min( $upper, $this->start_boundary( $cdate, $n['kind'] ) + $d );
				} elseif ( 'fnlt' === $ctype ) {
					$upper = min( $upper, $this->finish_boundary( $cdate ) );
				}
			}

			if ( $n['fixed_finish'] ) {
				// Terminada o con fecha obligatoria: no hay margen en ninguno de los dos extremos.
				$n['lf'] = $n['f'];
				$n['ls'] = $n['s'];
			} elseif ( $n['fixed_start'] ) {
				// En curso: el inicio ya ocurrió; el término conserva el margen que le dejan sus sucesoras.
				$n['lf'] = $upper;
				$n['ls'] = $n['s'];
			} else {
				$n['lf'] = $upper;
				$n['ls'] = $upper - $d;
			}
			unset( $n );
		}

		// Holguras y fechas.
		$out = array();
		foreach ( $nodes as $id => $n ) {
			$d  = $n['f'] - $n['s'];
			$tf = $n['lf'] - $n['f'];

			$ff = $finish - $n['f'];
			foreach ( $n['succs'] as $edge ) {
				$s = $nodes[ $edge['id'] ];
				switch ( $edge['type'] ) {
					case 'FS':
						$slack = $s['s'] - ( $n['f'] + $edge['lag'] );
						break;
					case 'SS':
						$slack = $s['s'] - ( $n['s'] + $edge['lag'] );
						break;
					case 'FF':
						$slack = $s['f'] - ( $n['f'] + $edge['lag'] );
						break;
					default:
						$slack = $s['f'] - ( $n['s'] + $edge['lag'] );
				}
				$ff = min( $ff, $slack );
			}

			$is_milestone = 'milestone' === $n['kind'];
			$conflicts    = $n['conflicts'];
			if ( $tf < 0 ) {
				$conflicts[] = sprintf(
					'La programación temprana supera la fecha tardía admisible: holgura negativa de %d día(s) hábil(es).',
					-$tf
				);
			}

			$out[ $id ] = array(
				'id'          => $id,
				'kind'        => $n['kind'],
				's'           => $n['s'],
				'f'           => $n['f'],
				'ls'          => $n['ls'],
				'lf'          => $n['lf'],
				'duration'    => $d,
				'percent'     => $n['percent'],
				'start_date'  => $is_milestone ? $this->day( $n['f'] - 1 ) : $this->day( $n['s'] ),
				'end_date'    => $this->day( max( $n['f'] - 1, $is_milestone ? $n['f'] - 1 : $n['s'] ) ),
				'late_start'  => $is_milestone ? $this->day( $n['lf'] - 1 ) : $this->day( $n['ls'] ),
				'late_finish' => $this->day( max( $n['lf'] - 1, $is_milestone ? $n['lf'] - 1 : $n['ls'] ) ),
				'total_float' => $tf,
				'free_float'  => $ff,
				// Una actividad terminada ya no condiciona el término: no se marca crítica.
				'critical'    => $tf <= 0 && null === $n['actual_finish'],
				'fixed'       => $n['fixed'],
				'conflicts'   => $conflicts,
				'is_summary'  => false,
			);
		}

		// Resúmenes por agregación (de abajo hacia arriba).
		$children = array();
		foreach ( $byid as $id => $a ) {
			$children[ (int) ( $a['parent_id'] ?? 0 ) ][] = $id;
		}
		$summaries = array_keys( array_filter( $byid, static fn( $a ) => 'summary' === ( $a['kind'] ?? 'activity' ) ) );
		// Un resumen se resuelve cuando todos sus hijos resúmenes están resueltos: iteramos hasta el punto fijo.
		$pending = $summaries;
		$guard   = 0;
		while ( ! empty( $pending ) && $guard++ < 1000 ) {
			$next = array();
			foreach ( $pending as $sid ) {
				$kids = $children[ $sid ] ?? array();
				$wait = false;
				foreach ( $kids as $kid ) {
					if ( 'summary' === ( $byid[ $kid ]['kind'] ?? 'activity' ) && ! isset( $out[ $kid ] ) ) {
						$wait = true;
						break;
					}
				}
				if ( $wait ) {
					$next[] = $sid;
					continue;
				}
				$out[ $sid ] = $this->rollup( $sid, $kids, $out );
			}
			$pending = $next;
		}

		$deadline_date  = null;
		$deadline_slack = null;
		if ( $this->project_end ) {
			$deadline_date  = $this->project_end;
			$deadline_slack = $this->finish_boundary( $this->project_end ) - $finish;
		}

		return array(
			'ok'      => empty( $errors ),
			'errors'  => $errors,
			'cycle'   => $cycle,
			'order'   => $order,
			'project' => array(
				'start_date'      => $this->day0,
				'finish_boundary' => $finish,
				'finish_date'     => $this->day( max( $finish - 1, 0 ) ),
				'deadline_date'   => $deadline_date,
				'deadline_slack'  => $deadline_slack,
			),
			'nodes'   => $out,
		);
	}

	/**
	 * Agregación de un resumen a partir de sus hijos.
	 *
	 * @param int                            $sid  Resumen.
	 * @param int[]                          $kids Hijos.
	 * @param array<int,array<string,mixed>> $out  Nodos resueltos.
	 * @return array<string,mixed>
	 */
	private function rollup( int $sid, array $kids, array $out ): array {
		$s        = null;
		$f        = null;
		$ls       = null;
		$lf       = null;
		$tf       = null;
		$critical = false;
		$weight   = 0;
		$progress = 0.0;
		$count    = 0;
		$plain    = 0.0;

		foreach ( $kids as $kid ) {
			if ( ! isset( $out[ $kid ] ) ) {
				continue;
			}
			$k        = $out[ $kid ];
			$s        = null === $s ? $k['s'] : min( $s, $k['s'] );
			$f        = null === $f ? $k['f'] : max( $f, $k['f'] );
			$ls       = null === $ls ? $k['ls'] : min( $ls, $k['ls'] );
			$lf       = null === $lf ? $k['lf'] : max( $lf, $k['lf'] );
			$tf       = null === $tf ? $k['total_float'] : min( $tf, $k['total_float'] );
			$critical = $critical || $k['critical'];
			// Peso: duración de las hojas (los hitos no pesan); los resúmenes aportan el peso acumulado de sus hojas.
			$w         = (int) ( $k['weight'] ?? $k['duration'] );
			$weight   += $w;
			$progress += $w * (float) ( $k['percent'] ?? 0 );
			if ( ! $k['is_summary'] || $w > 0 ) {
				++$count;
				$plain += (float) ( $k['percent'] ?? 0 );
			}
		}

		if ( null === $s ) {
			// Resumen vacío: un día simbólico en el inicio del proyecto.
			$s  = 0;
			$f  = 0;
			$ls = 0;
			$lf = 0;
			$tf = 0;
		}

		return array(
			'id'          => $sid,
			'kind'        => 'summary',
			's'           => $s,
			'f'           => $f,
			'ls'          => $ls,
			'lf'          => $lf,
			'duration'    => max( 0, $f - $s ),
			'start_date'  => $this->day( $s ),
			'end_date'    => $this->day( max( $f - 1, $s ) ),
			'late_start'  => $this->day( $ls ),
			'late_finish' => $this->day( max( $lf - 1, $ls ) ),
			'total_float' => (int) $tf,
			'free_float'  => (int) $tf,
			'critical'    => $critical,
			'fixed'       => false,
			'conflicts'   => array(),
			'is_summary'  => true,
			'weight'      => $weight,
			// Avance ponderado por duración; si nada pesa (solo hitos), promedio simple.
			'percent'     => $weight > 0 ? (int) round( $progress / $weight ) : ( $count > 0 ? (int) round( $plain / $count ) : 0 ),
		);
	}

	/**
	 * Frontera de inicio correspondiente a una fecha, según el tipo de nodo.
	 *
	 * @param string $date Fecha.
	 * @param string $kind Tipo.
	 * @return int
	 */
	private function start_boundary( string $date, string $kind ): int {
		return 'milestone' === $kind ? $this->index( $date ) + 1 : $this->index( $date );
	}

	/**
	 * Frontera de término (final del día) correspondiente a una fecha.
	 *
	 * @param string $date Fecha.
	 * @return int
	 */
	private function finish_boundary( string $date ): int {
		$normalized = $this->calendar->previous_working( $date );

		return $this->index( $normalized ) + 1;
	}
}
