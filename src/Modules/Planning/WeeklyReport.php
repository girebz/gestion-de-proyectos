<?php
/**
 * Informe semanal de avance.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use DateTimeImmutable;
use GDP\Core\Catalogs;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reúne, para una semana, el estado del cronograma por frentes de trabajo,
 * los avances registrados, lo terminado, lo iniciado, lo que viene, los
 * atrasos y la desviación respecto de la línea base. Se exporta en JSON (para
 * el conector), CSV, LaTeX (documento completo) e iCalendar (hitos).
 */
final class WeeklyReport {

	/**
	 * Construye los datos del informe.
	 *
	 * @param int         $project_id Proyecto.
	 * @param string|null $week_start Lunes de la semana (Y-m-d); por defecto, la semana en curso.
	 * @return array<string,mixed>|null Null si el proyecto no existe.
	 */
	public static function build( int $project_id, ?string $week_start = null ): ?array {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return null;
		}

		$monday = self::monday( $week_start ?? current_time( 'Y-m-d' ) );
		$sunday = $monday->modify( '+6 days' );
		$next   = $monday->modify( '+7 days' );
		$after  = $monday->modify( '+14 days' );
		$from   = $monday->format( 'Y-m-d' );
		$to     = $sunday->format( 'Y-m-d' );

		$schedule = ScheduleService::summary( $project_id, true );
		$leaves   = array_values( array_filter( $schedule['activities'], static fn( array $a ): bool => 'summary' !== $a['kind'] ) );
		$calendar = CalendarRepository::build( $project_id );

		// Avances registrados durante la semana (la tabla guarda UTC).
		$tz_from  = get_gmt_from_date( $from . ' 00:00:00' );
		$tz_to    = get_gmt_from_date( $next->format( 'Y-m-d' ) . ' 00:00:00' );
		$entries  = ProgressRepository::between( $project_id, $tz_from, $tz_to );
		$index    = array();
		foreach ( $schedule['activities'] as $a ) {
			$index[ $a['id'] ] = $a;
		}
		$progress = array();
		foreach ( $entries as $e ) {
			$a          = $index[ $e['activity_id'] ] ?? null;
			$progress[] = array(
				'activity_id'      => $e['activity_id'],
				'code'             => $a['code'] ?? '',
				'name'             => $a['name'] ?? sprintf( '#%d', $e['activity_id'] ),
				'work_front'       => $a['work_front'] ?? '',
				'previous_percent' => $e['previous_percent'],
				'percent'          => $e['percent'],
				'status'           => $e['status'],
				'note'             => $e['note'],
				'user'             => $e['display_name'],
				'reported_at'      => get_date_from_gmt( $e['reported_at'], 'Y-m-d H:i' ),
				'source'           => $e['source'],
			);
		}

		// Frentes de trabajo.
		$front_labels = array();
		foreach ( Catalogs::items( Catalogs::WORK_FRONT, $project_id ) as $item ) {
			$front_labels[ $item['slug'] ] = $item['label'];
		}
		$fronts = array();
		foreach ( $leaves as $a ) {
			$slug = '' !== $a['work_front'] ? $a['work_front'] : 'sin_frente';
			if ( ! isset( $fronts[ $slug ] ) ) {
				$fronts[ $slug ] = array(
					'slug'        => $slug,
					'label'       => $front_labels[ $slug ] ?? ( 'sin_frente' === $slug ? __( 'Sin frente asignado', 'gestion-de-proyectos' ) : $slug ),
					'activities'  => 0,
					'done'        => 0,
					'in_progress' => 0,
					'overdue'     => 0,
					'weight'      => 0,
					'earned'      => 0.0,
					'percent'     => 0,
					'critical'    => 0,
				);
			}
			$f = &$fronts[ $slug ];
			++$f['activities'];
			$w            = max( 1, $a['duration'] );
			$f['weight'] += $w;
			$f['earned'] += $w * $a['percent'];
			if ( 'terminada' === $a['status'] ) {
				++$f['done'];
			} elseif ( 'en_curso' === $a['status'] ) {
				++$f['in_progress'];
			}
			if ( $a['end_date'] && $a['end_date'] < $to && ! in_array( $a['status'], array( 'terminada', 'cancelada' ), true ) ) {
				++$f['overdue'];
			}
			if ( $a['is_critical'] ) {
				++$f['critical'];
			}
			unset( $f );
		}
		foreach ( $fronts as &$f ) {
			$f['percent'] = $f['weight'] > 0 ? (int) round( $f['earned'] / $f['weight'] ) : 0;
			unset( $f['earned'] );
		}
		unset( $f );
		usort( $fronts, static fn( array $a, array $b ): int => strcmp( $a['label'], $b['label'] ) );

		$completed = array();
		$started   = array();
		$upcoming  = array();
		$milestones = array();
		$overdue   = array();
		$in_week   = array();
		foreach ( $leaves as $a ) {
			$row = array_intersect_key( $a, array_flip( array( 'id', 'code', 'name', 'kind', 'work_front', 'status', 'percent', 'start_date', 'end_date', 'actual_start', 'actual_finish', 'owner', 'is_critical', 'total_float' ) ) );
			if ( $a['actual_finish'] && $a['actual_finish'] >= $from && $a['actual_finish'] <= $to ) {
				$completed[] = $row;
			}
			if ( $a['actual_start'] && $a['actual_start'] >= $from && $a['actual_start'] <= $to && 'milestone' !== $a['kind'] ) {
				$started[] = $row;
			}
			$open = ! in_array( $a['status'], array( 'terminada', 'cancelada' ), true );
			if ( $open && $a['start_date'] && $a['start_date'] > $to && $a['start_date'] < $after->format( 'Y-m-d' ) ) {
				$upcoming[] = $row;
			}
			if ( $open && $a['end_date'] && $a['end_date'] < $from ) {
				$row['days_late'] = abs( $calendar->working_days_between( $a['end_date'], $from ) );
				$overdue[]        = $row;
			}
			if ( $open && $a['start_date'] && $a['end_date'] && $a['start_date'] <= $to && $a['end_date'] >= $from ) {
				$in_week[] = $row;
			}
			if ( 'milestone' === $a['kind'] && $a['end_date'] && $a['end_date'] >= $from && $a['end_date'] < $after->modify( '+14 days' )->format( 'Y-m-d' ) ) {
				$milestones[] = $row;
			}
		}
		usort( $overdue, static fn( array $a, array $b ): int => $b['days_late'] <=> $a['days_late'] );

		$baseline = BaselineRepository::current( $project_id );
		$variance = null;
		if ( $baseline ) {
			$compare  = BaselineRepository::compare( $project_id, $baseline['id'] );
			$delayed  = array_values( array_filter( $compare['rows'], static fn( array $r ): bool => 'atrasada' === $r['status'] && 'summary' !== $r['kind'] ) );
			usort( $delayed, static fn( array $a, array $b ): int => $b['variance'] <=> $a['variance'] );
			$variance = array(
				'baseline_id'   => $baseline['id'],
				'baseline_name' => $baseline['name'],
				'baseline_date' => get_date_from_gmt( $baseline['created_at'], 'Y-m-d' ),
				'summary'       => $compare['summary'],
				'top_delayed'   => array_slice( $delayed, 0, 10 ),
			);
		}

		$alerts = array_values( array_filter( ScheduleService::alerts( $project_id, $to ), static fn( array $al ): bool => 'high' === $al['severity'] ) );
		$curve  = ProgressCurve::build( $project_id, min( $to, current_time( 'Y-m-d' ) ) );

		return array(
			'generated_at' => current_time( 'c' ),
			'week'         => array(
				'iso'    => $monday->format( 'o-\WW' ),
				'number' => (int) $monday->format( 'W' ),
				'year'   => (int) $monday->format( 'o' ),
				'from'   => $from,
				'to'     => $to,
			),
			'project'      => array(
				'id'               => $project['id'],
				'code'             => $project['code'],
				'name'             => $project['name'],
				'short_name'       => $project['short_name'],
				'funder'           => $project['funder'],
				'funding_code'     => $project['funding_code'],
				'executing_entity' => $project['executing_entity'],
				'status'           => $project['status'],
				'start_date'       => $project['start_date'],
				'end_date'         => $project['end_date'],
			),
			'schedule'     => $schedule['project'],
			'stats'        => $schedule['stats'],
			'fronts'       => $fronts,
			'progress'     => $progress,
			'completed'    => $completed,
			'started'      => $started,
			'in_week'      => $in_week,
			'upcoming'     => $upcoming,
			'milestones'   => $milestones,
			'overdue'      => $overdue,
			'variance'     => $variance,
			'alerts'       => $alerts,
			'curve'        => $curve,
			'activities'   => $schedule['activities'],
		);
	}

	/**
	 * Lunes de la semana de una fecha.
	 *
	 * @param string $date Fecha.
	 * @return DateTimeImmutable
	 */
	public static function monday( string $date ): DateTimeImmutable {
		$dt = new DateTimeImmutable( $date );
		$n  = (int) $dt->format( 'N' );

		return $dt->modify( '-' . ( $n - 1 ) . ' days' );
	}

	/**
	 * Exportación CSV (una fila por actividad con las columnas del cronograma).
	 *
	 * @param array<string,mixed> $report Informe.
	 * @return string
	 */
	public static function to_csv( array $report ): string {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array( 'codigo', 'nivel', 'nombre', 'tipo', 'frente', 'estado', 'prioridad', 'duracion_dias_habiles', 'avance', 'inicio', 'termino', 'inicio_tardio', 'termino_tardio', 'holgura_total', 'holgura_libre', 'critica', 'restriccion', 'fecha_restriccion', 'inicio_real', 'termino_real', 'responsable', 'predecesoras', 'entregable' ), ';', '"', '\\' );
		foreach ( $report['activities'] as $a ) {
			fputcsv(
				$handle,
				array(
					$a['code'],
					$a['level'],
					$a['name'],
					$a['kind'],
					$a['work_front'],
					$a['status'],
					$a['priority'],
					$a['duration'],
					$a['percent'],
					$a['start_date'],
					$a['end_date'],
					$a['late_start'],
					$a['late_finish'],
					$a['total_float'],
					$a['free_float'],
					$a['is_critical'] ? 1 : 0,
					$a['constraint_type'],
					$a['constraint_date'],
					$a['actual_start'],
					$a['actual_finish'],
					$a['owner'],
					$a['predecessors'] ?? '',
					$a['deliverable'],
				),
				';',
				'"',
				'\\'
			);
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Exportación iCalendar de hitos y términos de actividad.
	 *
	 * @param array<string,mixed> $report Informe.
	 * @return string
	 */
	public static function to_ics( array $report ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Gestion de Proyectos//' . GDP_VERSION . '//ES',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::ics_escape( $report['project']['name'] ),
		);
		$stamp = gmdate( 'Ymd\THis\Z' );
		foreach ( $report['activities'] as $a ) {
			if ( 'summary' === $a['kind'] || ! $a['end_date'] ) {
				continue;
			}
			$is_milestone = 'milestone' === $a['kind'];
			$start        = $is_milestone ? $a['end_date'] : $a['start_date'];
			$end          = ( new DateTimeImmutable( $a['end_date'] ) )->modify( '+1 day' )->format( 'Ymd' );
			$lines[]      = 'BEGIN:VEVENT';
			$lines[]      = sprintf( 'UID:gdp-%d-activity-%d@%s', $report['project']['id'], $a['id'], wp_parse_url( home_url(), PHP_URL_HOST ) );
			$lines[]      = 'DTSTAMP:' . $stamp;
			$lines[]      = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', (string) $start );
			$lines[]      = 'DTEND;VALUE=DATE:' . $end;
			$lines[]      = 'SUMMARY:' . self::ics_escape( sprintf( '%s%s %s', $is_milestone ? '◆ ' : '', $a['code'], $a['name'] ) );
			$lines[]      = 'DESCRIPTION:' . self::ics_escape( sprintf( '%s. Avance %d%%. %s', $report['project']['code'], $a['percent'], $a['deliverable'] ) );
			$lines[]      = 'CATEGORIES:' . self::ics_escape( $is_milestone ? 'Hito' : 'Actividad' );
			$lines[]      = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Documento LaTeX completo del informe semanal.
	 *
	 * @param array<string,mixed> $report Informe.
	 * @return string
	 */
	public static function to_latex( array $report ): string {
		$p     = $report['project'];
		$w     = $report['week'];
		$st    = $report['stats'];
		$sched = $report['schedule'];
		$e     = array( self::class, 'tex' );

		$out   = array();
		$out[] = '% Informe semanal generado por Gestión de Proyectos ' . GDP_VERSION . ' el ' . $report['generated_at'];
		$out[] = '\documentclass[11pt,a4paper]{article}';
		$out[] = '\usepackage[utf8]{inputenc}';
		$out[] = '\usepackage[T1]{fontenc}';
		$out[] = '\usepackage[spanish,es-noquoting,es-tabla]{babel}';
		$out[] = '\usepackage[margin=2.2cm]{geometry}';
		$out[] = '\usepackage{helvet}';
		$out[] = '\renewcommand{\familydefault}{\sfdefault}';
		$out[] = '\usepackage{longtable,booktabs,array}';
		$out[] = '\usepackage[most]{tcolorbox}';
		$out[] = '\usepackage{siunitx}';
		$out[] = '\sisetup{output-decimal-marker={,},group-separator={.},group-minimum-digits=4}';
		$out[] = '\usepackage{pgfgantt}';
		$out[] = '\usepackage{pgfplots}';
		$out[] = '\pgfplotsset{compat=1.17}';
		$out[] = '\usepackage{xcolor}';
		$out[] = '\usepackage{hyperref}';
		$out[] = '\hypersetup{hidelinks}';
		$out[] = '\tcbset{sharp corners,breakable,colback=gray!5,colframe=gray!60,fonttitle=\bfseries,title after break={(continuación)}}';
		$out[] = '\newcommand{\continuacion}{\multicolumn{1}{l}{\footnotesize\itshape (continuación)}}';
		$out[] = '\setlength{\parindent}{0pt}';
		$out[] = '\setlength{\parskip}{6pt}';
		$out[] = '';
		$out[] = '\begin{document}';
		$out[] = '';
		$out[] = '\begin{center}';
		$out[] = '{\Large\bfseries Informe semanal de avance}\\\\[4pt]';
		$out[] = '{\large ' . $e( $p['name'] ) . '}\\\\[2pt]';
		$out[] = 'Semana ' . (int) $w['number'] . ' de ' . (int) $w['year'] . ' (' . $e( self::human_date( $w['from'] ) ) . ' al ' . $e( self::human_date( $w['to'] ) ) . ')';
		$out[] = '\end{center}';
		$out[] = '';
		$out[] = '\begin{tcolorbox}[title=Identificación]';
		$out[] = '\begin{tabular}{@{}p{4cm}p{10.4cm}@{}}';
		$out[] = 'Código & ' . $e( $p['code'] ) . '\\\\';
		if ( $p['funder'] ) {
			$out[] = 'Entidad financiadora & ' . $e( $p['funder'] ) . ( $p['funding_code'] ? ' (' . $e( $p['funding_code'] ) . ')' : '' ) . '\\\\';
		}
		if ( $p['executing_entity'] ) {
			$out[] = 'Entidad ejecutora & ' . $e( $p['executing_entity'] ) . '\\\\';
		}
		$out[] = 'Periodo contractual & ' . $e( $p['start_date'] ? self::human_date( $p['start_date'] ) : 'sin definir' ) . ' al ' . $e( $p['end_date'] ? self::human_date( $p['end_date'] ) : 'sin definir' ) . '\\\\';
		$out[] = 'Término programado & ' . $e( $sched && $sched['finish_date'] ? self::human_date( $sched['finish_date'] ) : 'sin actividades' ) . ( $sched && null !== $sched['deadline_slack'] ? ' (holgura respecto del término contractual: ' . (int) $sched['deadline_slack'] . ' días hábiles)' : '' ) . '\\\\';
		$out[] = 'Avance global & \SI{' . (int) $st['percent'] . '}{\percent}\\\\';
		$out[] = 'Actividades & ' . (int) $st['leaves'] . ' (' . (int) $st['by_status']['terminada'] . ' terminadas, ' . (int) $st['by_status']['en_curso'] . ' en curso, ' . (int) $st['by_status']['pendiente'] . ' pendientes, ' . (int) $st['overdue'] . ' vencidas)\\\\';
		$out[] = 'Actividades críticas & ' . (int) $st['critical'] . '\\\\';
		$out[] = '\end{tabular}';
		$out[] = '\end{tcolorbox}';
		$out[] = '';

		// Frentes.
		$out[] = '\section*{Avance por frente de trabajo}';
		$out[] = '\begin{longtable}{@{}p{5.6cm}rrrrr@{}}';
		$out[] = '\toprule';
		$out[] = 'Frente & Actividades & Terminadas & En curso & Vencidas & Avance\\\\';
		$out[] = '\midrule';
		$out[] = '\endfirsthead';
		$out[] = '\toprule';
		$out[] = 'Frente & Actividades & Terminadas & En curso & Vencidas & Avance\\\\';
		$out[] = '\midrule';
		$out[] = '\endhead';
		$out[] = '\midrule \multicolumn{6}{r}{\footnotesize\itshape continúa en la página siguiente}\\\\';
		$out[] = '\endfoot';
		$out[] = '\bottomrule';
		$out[] = '\endlastfoot';
		foreach ( $report['fronts'] as $f ) {
			$out[] = $e( $f['label'] ) . ' & ' . (int) $f['activities'] . ' & ' . (int) $f['done'] . ' & ' . (int) $f['in_progress'] . ' & ' . (int) $f['overdue'] . ' & \SI{' . (int) $f['percent'] . '}{\percent}\\\\';
		}
		if ( empty( $report['fronts'] ) ) {
			$out[] = '\multicolumn{6}{l}{\itshape Sin actividades registradas.}\\\\';
		}
		$out[] = '\end{longtable}';
		$out[] = '';

		// Curva S.
		$curve = $report['curve'] ?? array();
		if ( ! empty( $curve['points'] ) && count( $curve['points'] ) >= 2 ) {
			// Eje x por número de semana (pgfplots analiza mal los días 08 y 09 en fechas); rótulos explícitos.
			$planned = array();
			$actual  = array();
			$ticks   = array();
			$labels  = array();
			$n       = count( $curve['points'] );
			$step    = max( 1, (int) ceil( $n / 8 ) );
			foreach ( $curve['points'] as $i => $pt ) {
				$planned[] = sprintf( '(%d,%.1f)', $i, (float) $pt['planned'] );
				if ( null !== $pt['actual'] ) {
					$actual[] = sprintf( '(%d,%.1f)', $i, (float) $pt['actual'] );
				}
				if ( 0 === $i % $step || $i === $n - 1 ) {
					$ticks[]  = (string) $i;
					$labels[] = self::human_date( $pt['date'] );
				}
			}
			$out[] = '\section*{Curva S de avance}';
			$out[] = sprintf( 'Avance planificado a la fecha de corte: \SI{%.1f}{\percent}; avance real: \SI{%.1f}{\percent}; desviación: %s puntos%s.', (float) $curve['planned_today'], (float) $curve['actual_today'], number_format( (float) $curve['variance'], 1, ',', '' ), $curve['baseline'] ? ' (plan según la línea base ' . $e( $curve['baseline'] ) . ')' : ' (plan según el cronograma vigente, sin línea base)' );
			$out[] = '';
			$out[] = '\begin{center}';
			$out[] = '\begin{tikzpicture}';
			$out[] = '\begin{axis}[width=15cm,height=7cm,xmin=0,xmax=' . ( $n - 1 ) . ',xtick={' . implode( ',', $ticks ) . '},xticklabels={' . implode( ',', $labels ) . '},xticklabel style={rotate=45,anchor=north east,font=\scriptsize},ymin=0,ymax=100,ylabel={Avance (\%)},grid=major,legend pos=north west,legend style={font=\small}]';
			$out[] = '\addplot[gray,dashed,thick] coordinates {' . implode( ' ', $planned ) . '};';
			$out[] = '\addlegendentry{Planificado}';
			if ( count( $actual ) >= 2 ) {
				$out[] = '\addplot[black,thick] coordinates {' . implode( ' ', $actual ) . '};';
				$out[] = '\addlegendentry{Real}';
			}
			$out[] = '\end{axis}';
			$out[] = '\end{tikzpicture}';
			$out[] = '\end{center}';
			$out[] = '';
		}

		// Avances de la semana.
		$out[] = '\section*{Avances registrados en la semana}';
		if ( empty( $report['progress'] ) ) {
			$out[] = 'No se registraron avances durante la semana.';
		} else {
			$out[] = '\begin{longtable}{@{}p{1.4cm}p{6cm}rrp{5.4cm}@{}}';
			$out[] = '\toprule';
			$out[] = 'Código & Actividad & Antes & Ahora & Nota\\\\';
			$out[] = '\midrule';
			$out[] = '\endfirsthead';
			$out[] = '\toprule';
			$out[] = 'Código & Actividad & Antes & Ahora & Nota\\\\';
			$out[] = '\midrule';
			$out[] = '\endhead';
			$out[] = '\bottomrule';
			$out[] = '\endlastfoot';
			foreach ( $report['progress'] as $pr ) {
				$out[] = $e( $pr['code'] ) . ' & ' . $e( $pr['name'] ) . ' & \SI{' . (int) $pr['previous_percent'] . '}{\percent} & \SI{' . (int) $pr['percent'] . '}{\percent} & ' . $e( trim( $pr['note'] . ( $pr['user'] ? ' (' . $pr['user'] . ')' : '' ) ) ) . '\\\\';
			}
			$out[] = '\end{longtable}';
		}
		$out[] = '';

		foreach ( array(
			'completed' => 'Actividades e hitos terminados en la semana',
			'started'   => 'Actividades iniciadas en la semana',
			'in_week'   => 'Actividades en ejecución durante la semana',
			'upcoming'  => 'Actividades que comienzan la próxima semana',
			'milestones' => 'Hitos de las próximas cuatro semanas',
		) as $key => $title ) {
			$out[] = '\section*{' . $title . '}';
			if ( empty( $report[ $key ] ) ) {
				$out[] = 'Sin registros.';
				$out[] = '';
				continue;
			}
			$out[] = '\begin{longtable}{@{}p{1.4cm}p{6.8cm}p{2.5cm}p{2.5cm}r@{}}';
			$out[] = '\toprule';
			$out[] = 'Código & Actividad & Inicio & Término & Avance\\\\';
			$out[] = '\midrule';
			$out[] = '\endfirsthead';
			$out[] = '\toprule';
			$out[] = 'Código & Actividad & Inicio & Término & Avance\\\\';
			$out[] = '\midrule';
			$out[] = '\endhead';
			$out[] = '\bottomrule';
			$out[] = '\endlastfoot';
			foreach ( $report[ $key ] as $a ) {
				$out[] = $e( $a['code'] ) . ' & ' . $e( $a['name'] ) . ( $a['is_critical'] ? ' \textsuperscript{crítica}' : '' ) . ' & ' . $e( (string) ( 'milestone' === $a['kind'] ? '' : $a['start_date'] ) ) . ' & ' . $e( (string) $a['end_date'] ) . ' & \SI{' . (int) $a['percent'] . '}{\percent}\\\\';
			}
			$out[] = '\end{longtable}';
			$out[] = '';
		}

		// Atrasos.
		$out[] = '\section*{Actividades vencidas}';
		if ( empty( $report['overdue'] ) ) {
			$out[] = 'No hay actividades vencidas al cierre de la semana.';
		} else {
			$out[] = '\begin{longtable}{@{}p{1.4cm}p{7cm}p{2.5cm}rr@{}}';
			$out[] = '\toprule';
			$out[] = 'Código & Actividad & Término & Días de atraso & Avance\\\\';
			$out[] = '\midrule';
			$out[] = '\endfirsthead';
			$out[] = '\toprule';
			$out[] = 'Código & Actividad & Término & Días de atraso & Avance\\\\';
			$out[] = '\midrule';
			$out[] = '\endhead';
			$out[] = '\bottomrule';
			$out[] = '\endlastfoot';
			foreach ( $report['overdue'] as $a ) {
				$out[] = $e( $a['code'] ) . ' & ' . $e( $a['name'] ) . ' & ' . $e( (string) $a['end_date'] ) . ' & ' . (int) $a['days_late'] . ' & \SI{' . (int) $a['percent'] . '}{\percent}\\\\';
			}
			$out[] = '\end{longtable}';
		}
		$out[] = '';

		// Línea base.
		$out[] = '\section*{Desviación respecto de la línea base}';
		if ( ! $report['variance'] ) {
			$out[] = 'El proyecto no tiene una línea base vigente. Se recomienda fijarla al aprobar el cronograma.';
		} else {
			$v     = $report['variance'];
			$out[] = 'Línea base vigente: ' . $e( $v['baseline_name'] ) . ' (' . $e( self::human_date( $v['baseline_date'] ) ) . '). Actividades atrasadas respecto de ella: ' . (int) $v['summary']['delayed'] . '; adelantadas: ' . (int) $v['summary']['advanced'] . '; nuevas: ' . (int) $v['summary']['new'] . '; eliminadas: ' . (int) $v['summary']['removed'] . '.';
			if ( ! empty( $v['top_delayed'] ) ) {
				$out[] = '';
				$out[] = '\begin{longtable}{@{}p{1.4cm}p{6.2cm}p{2.7cm}p{2.7cm}r@{}}';
				$out[] = '\toprule';
				$out[] = 'Código & Actividad & Término base & Término actual & Días\\\\';
				$out[] = '\midrule';
				$out[] = '\endfirsthead';
				$out[] = '\toprule';
				$out[] = 'Código & Actividad & Término base & Término actual & Días\\\\';
				$out[] = '\midrule';
				$out[] = '\endhead';
				$out[] = '\bottomrule';
				$out[] = '\endlastfoot';
				foreach ( $v['top_delayed'] as $r ) {
					$out[] = $e( $r['code'] ) . ' & ' . $e( $r['name'] ) . ' & ' . $e( (string) $r['baseline']['end_date'] ) . ' & ' . $e( (string) $r['current']['end_date'] ) . ' & ' . (int) $r['variance'] . '\\\\';
				}
				$out[] = '\end{longtable}';
			}
		}
		$out[] = '';

		// Alertas.
		if ( ! empty( $report['alerts'] ) ) {
			$out[] = '\begin{tcolorbox}[title=Alertas de plazo,colframe=red!60!black,colback=red!3]';
			$out[] = '\begin{itemize}';
			foreach ( $report['alerts'] as $al ) {
				$out[] = '\item ' . $e( trim( $al['code'] . ' ' . $al['name'] ) ) . ': ' . $e( $al['message'] );
			}
			$out[] = '\end{itemize}';
			$out[] = '\end{tcolorbox}';
			$out[] = '';
		}

		// Carta Gantt de resúmenes e hitos de primer nivel.
		$gantt = self::gantt_rows( $report['activities'], $p['start_date'] ?? null, $sched );
		if ( $gantt ) {
			$out[] = '\section*{Carta Gantt resumida}';
			$out[] = '\noindent';
			$out[] = '\begin{ganttchart}[hgrid,vgrid={*{6}{draw=none},dotted},x unit=' . $gantt['x_unit'] . 'cm,y unit chart=0.46cm,y unit title=0.5cm,title label font=\scriptsize,bar label font=\scriptsize,milestone label font=\scriptsize,group label font=\scriptsize\bfseries,bar height=0.6,progress label text={},bar/.append style={fill=gray!45},bar incomplete/.append style={fill=gray!15},group/.append style={fill=black!60},milestone/.append style={fill=black,rounded corners=0pt},time slot format=isodate,canvas/.append style={draw=gray!40}]{' . $gantt['from'] . '}{' . $gantt['to'] . '}';
			$out[] = '\gantttitlecalendar{year, month=shortname} \\\\';
			foreach ( $gantt['rows'] as $r ) {
				$out[] = $r;
			}
			$out[] = '\end{ganttchart}';
			$out[] = '';
			$out[] = '{\footnotesize Cuadrícula semanal; se muestran las fases de primer nivel, sus actividades directas y los hitos. Las barras grises indican el avance registrado.}';
			$out[] = '';
		}

		$out[] = '\vfill';
		$out[] = '{\footnotesize Generado por Gestión de Proyectos el ' . $e( get_date_from_gmt( gmdate( 'Y-m-d H:i:s' ), 'd-m-Y H:i' ) ) . '. Las fechas se expresan en días hábiles del calendario del proyecto.}';
		$out[] = '\end{document}';

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Filas pgfgantt de resúmenes de primer nivel e hitos.
	 *
	 * @param array<int,array<string,mixed>> $activities Actividades.
	 * @param string|null                    $start      Inicio del proyecto.
	 * @param array<string,mixed>|null       $sched      Resumen del cronograma.
	 * @return array{from:string,to:string,rows:string[]}|null
	 */
	private static function gantt_rows( array $activities, ?string $start, ?array $sched ): ?array {
		$dated = array_values( array_filter( $activities, static fn( array $a ): bool => ! empty( $a['start_date'] ) && ! empty( $a['end_date'] ) ) );
		if ( empty( $dated ) ) {
			return null;
		}
		$min = $start ?? $dated[0]['start_date'];
		$max = $sched['finish_date'] ?? $dated[0]['end_date'];
		foreach ( $dated as $a ) {
			$min = min( $min, $a['start_date'] );
			$max = max( $max, $a['end_date'] );
		}
		// El eje se dibuja por meses completos.
		$from = ( new DateTimeImmutable( $min ) )->modify( 'first day of this month' )->format( 'Y-m-d' );
		$to   = ( new DateTimeImmutable( $max ) )->modify( 'last day of this month' )->format( 'Y-m-d' );

		$rows  = array();
		$count = 0;
		foreach ( $dated as $a ) {
			if ( $a['level'] > 1 || $count >= 40 ) {
				continue;
			}
			$label = self::tex( mb_strimwidth( $a['code'] . ' ' . $a['name'], 0, 38, '…' ) );
			if ( 'summary' === $a['kind'] ) {
				$rows[] = '\ganttgroup{' . $label . '}{' . $a['start_date'] . '}{' . $a['end_date'] . '} \\\\';
			} elseif ( 'milestone' === $a['kind'] ) {
				$rows[] = '\ganttmilestone{' . $label . '}{' . $a['end_date'] . '} \\\\';
			} else {
				$rows[] = '\ganttbar[progress=' . (int) $a['percent'] . ']{' . $label . '}{' . $a['start_date'] . '}{' . $a['end_date'] . '} \\\\';
			}
			++$count;
		}
		if ( empty( $rows ) ) {
			return null;
		}
		// La última fila no lleva salto.
		$rows[ count( $rows ) - 1 ] = rtrim( $rows[ count( $rows ) - 1 ], ' \\' );

		// Ancho útil de 10,5 cm para el gráfico (las etiquetas ocupan el resto).
		$days   = max( 1, ( new DateTimeImmutable( $to ) )->diff( new DateTimeImmutable( $from ) )->days + 1 );
		$x_unit = number_format( min( 0.25, 10.5 / $days ), 4, '.', '' );

		return array( 'from' => $from, 'to' => $to, 'rows' => $rows, 'x_unit' => $x_unit );
	}

	/**
	 * Escapa texto para LaTeX.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function tex( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$map  = array(
			'\\' => '\textbackslash{}',
			'&'  => '\&',
			'%'  => '\%',
			'$'  => '\$',
			'#'  => '\#',
			'_'  => '\_',
			'{'  => '\{',
			'}'  => '\}',
			'~'  => '\textasciitilde{}',
			'^'  => '\textasciicircum{}',
			'—'  => '-',
			'–'  => '-',
		);

		return strtr( $text, $map );
	}

	/**
	 * Escapa texto para iCalendar.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function ics_escape( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), wp_strip_all_tags( $text ) );
	}

	/**
	 * Fecha legible (d-m-Y).
	 *
	 * @param string $date Fecha Y-m-d.
	 * @return string
	 */
	public static function human_date( string $date ): string {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return $dt ? $dt->format( 'd-m-Y' ) : $date;
	}
}
