<?php
/**
 * Intercambio con Microsoft Project (formato XML MSPDI).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Spreadsheet;
use GDP\Domain\Projects\ProjectRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exporta el cronograma como XML de Microsoft Project (que Project, ProjectLibre
 * y otros abren directamente) e interpreta archivos del mismo formato para
 * importarlos. Duraciones en días hábiles de ocho horas; dependencias con
 * los tipos y retrasos de Project (retraso en décimas de minuto).
 */
final class ProjectXml {

	private const MINUTES_PER_DAY = 480;

	/**
	 * XML MSPDI del cronograma actual.
	 *
	 * @param int $project_id Proyecto.
	 * @return string
	 */
	public static function export( int $project_id ): string {
		$project  = ProjectRepository::find( $project_id );
		$result   = ScheduleService::recalculate( $project_id );
		$deps     = DependencyRepository::for_project( $project_id );
		$by_succ  = array();
		foreach ( $deps as $d ) {
			$by_succ[ $d['successor_id'] ][] = $d;
		}
		$types = array( 'FF' => 0, 'FS' => 1, 'SF' => 2, 'SS' => 3 );
		$cons  = array( 'asap' => 0, 'mso' => 2, 'mfo' => 3, 'snet' => 4, 'snlt' => 5, 'fnet' => 6, 'fnlt' => 7 );
		$x     = static fn( string $t ): string => htmlspecialchars( $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$dt    = static fn( ?string $d, bool $end = false ): string => $d ? $d . ( $end ? 'T17:00:00' : 'T08:00:00' ) : '';

		$out   = array();
		$out[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$out[] = '<Project xmlns="http://schemas.microsoft.com/project">';
		$out[] = '<SaveVersion>14</SaveVersion>';
		$out[] = '<Name>' . $x( (string) ( $project['code'] ?? 'proyecto' ) ) . '</Name>';
		$out[] = '<Title>' . $x( (string) ( $project['name'] ?? '' ) ) . '</Title>';
		$out[] = '<Author>' . $x( get_bloginfo( 'name' ) ) . '</Author>';
		$out[] = '<CreationDate>' . gmdate( 'Y-m-d\TH:i:s' ) . '</CreationDate>';
		$out[] = '<ScheduleFromStart>1</ScheduleFromStart>';
		$out[] = '<StartDate>' . $dt( $result['project']['start_date'] ?? null ) . '</StartDate>';
		$out[] = '<FinishDate>' . $dt( $result['project']['finish_date'] ?? null, true ) . '</FinishDate>';
		$out[] = '<MinutesPerDay>' . self::MINUTES_PER_DAY . '</MinutesPerDay>';
		$out[] = '<MinutesPerWeek>' . ( self::MINUTES_PER_DAY * 5 ) . '</MinutesPerWeek>';
		$out[] = '<DaysPerMonth>20</DaysPerMonth>';
		$out[] = '<DurationFormat>7</DurationFormat>';
		$out[] = '<CalendarUID>1</CalendarUID>';
		$out[] = '<Calendars><Calendar><UID>1</UID><Name>Estándar</Name><IsBaseCalendar>1</IsBaseCalendar><WeekDays>';
		$weekdays = array_flip( CalendarRepository::build( $project_id )->weekdays() );
		for ( $day = 1; $day <= 7; $day++ ) {
			// Project numera domingo = 1 ... sábado = 7.
			$iso     = 1 === $day ? 7 : $day - 1;
			$working = isset( $weekdays[ $iso ] );
			$out[]   = '<WeekDay><DayType>' . $day . '</DayType><DayWorking>' . ( $working ? 1 : 0 ) . '</DayWorking>' . ( $working ? '<WorkingTimes><WorkingTime><FromTime>08:00:00</FromTime><ToTime>12:00:00</ToTime></WorkingTime><WorkingTime><FromTime>13:00:00</FromTime><ToTime>17:00:00</ToTime></WorkingTime></WorkingTimes>' : '' ) . '</WeekDay>';
		}
		$out[] = '</WeekDays></Calendar></Calendars>';
		$out[] = '<Tasks>';
		$out[] = '<Task><UID>0</UID><ID>0</ID><Name>' . $x( (string) ( $project['name'] ?? '' ) ) . '</Name><Type>1</Type><IsNull>0</IsNull><OutlineNumber>0</OutlineNumber><OutlineLevel>0</OutlineLevel><Summary>1</Summary><Start>' . $dt( $result['project']['start_date'] ?? null ) . '</Start><Finish>' . $dt( $result['project']['finish_date'] ?? null, true ) . '</Finish></Task>';
		$n = 0;
		foreach ( $result['activities'] as $a ) {
			++$n;
			$is_summary   = 'summary' === $a['kind'];
			$is_milestone = 'milestone' === $a['kind'];
			$hours        = $is_milestone ? 0 : max( 0, (int) $a['duration'] ) * 8;
			$out[]        = '<Task>';
			$out[]        = '<UID>' . (int) $a['id'] . '</UID><ID>' . $n . '</ID>';
			$out[]        = '<Name>' . $x( $a['name'] ) . '</Name>';
			$out[]        = '<Type>1</Type><IsNull>0</IsNull>';
			$out[]        = '<WBS>' . $x( $a['code'] ) . '</WBS><OutlineNumber>' . $x( $a['code'] ) . '</OutlineNumber><OutlineLevel>' . ( (int) $a['level'] + 1 ) . '</OutlineLevel>';
			$out[]        = '<Start>' . $dt( $a['start_date'] ) . '</Start><Finish>' . $dt( $a['end_date'], true ) . '</Finish>';
			$out[]        = '<Duration>PT' . $hours . 'H0M0S</Duration><DurationFormat>7</DurationFormat>';
			$out[]        = '<Milestone>' . ( $is_milestone ? 1 : 0 ) . '</Milestone><Summary>' . ( $is_summary ? 1 : 0 ) . '</Summary><Critical>' . ( $a['is_critical'] ? 1 : 0 ) . '</Critical>';
			$out[]        = '<PercentComplete>' . (int) $a['percent'] . '</PercentComplete>';
			if ( ! $is_summary ) {
				$out[] = '<ConstraintType>' . ( $cons[ $a['constraint_type'] ] ?? 0 ) . '</ConstraintType>';
				if ( $a['constraint_date'] && 'asap' !== $a['constraint_type'] ) {
					$out[] = '<ConstraintDate>' . $dt( $a['constraint_date'] ) . '</ConstraintDate>';
				}
				if ( $a['actual_start'] ) {
					$out[] = '<ActualStart>' . $dt( $a['actual_start'] ) . '</ActualStart>';
				}
				if ( $a['actual_finish'] ) {
					$out[] = '<ActualFinish>' . $dt( $a['actual_finish'], true ) . '</ActualFinish>';
				}
			}
			if ( $a['deliverable'] ) {
				$out[] = '<Notes>' . $x( $a['deliverable'] ) . '</Notes>';
			}
			foreach ( $by_succ[ $a['id'] ] ?? array() as $d ) {
				$out[] = '<PredecessorLink><PredecessorUID>' . (int) $d['predecessor_id'] . '</PredecessorUID><Type>' . ( $types[ $d['type'] ] ?? 1 ) . '</Type><CrossProject>0</CrossProject><LinkLag>' . ( (int) $d['lag'] * self::MINUTES_PER_DAY * 10 ) . '</LinkLag><LagFormat>7</LagFormat></PredecessorLink>';
			}
			$out[] = '</Task>';
		}
		$out[] = '</Tasks>';
		$out[] = '</Project>';

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Interpreta un XML de Microsoft Project como filas normalizadas de importación.
	 *
	 * @param string $xml Contenido.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function parse( string $xml ) {
		$doc = Spreadsheet::parse( $xml );
		if ( ! $doc ) {
			return new WP_Error( 'xml', __( 'El archivo no es un XML válido de Microsoft Project.', 'gestion-de-proyectos' ) );
		}
		$doc->registerXPathNamespace( 'p', 'http://schemas.microsoft.com/project' );
		$tasks = $doc->xpath( '//p:Tasks/p:Task' );
		if ( empty( $tasks ) ) {
			$tasks = $doc->xpath( '//Tasks/Task' );
		}
		if ( empty( $tasks ) ) {
			return new WP_Error( 'empty', __( 'El XML no contiene tareas.', 'gestion-de-proyectos' ) );
		}
		$minutes_per_day = (int) ( $doc->MinutesPerDay ?? 0 );
		if ( $minutes_per_day <= 0 ) {
			$minutes_per_day = self::MINUTES_PER_DAY;
		}
		$types = array( 0 => 'FF', 1 => 'FS', 2 => 'SF', 3 => 'SS' );
		$cons  = array( 0 => 'asap', 1 => 'asap', 2 => 'mso', 3 => 'mfo', 4 => 'snet', 5 => 'snlt', 6 => 'fnet', 7 => 'fnlt' );
		$rows  = array();

		foreach ( $tasks as $t ) {
			if ( '1' === (string) $t->IsNull ) {
				continue;
			}
			$uid   = (string) $t->UID;
			$level = (int) $t->OutlineLevel;
			if ( '0' === $uid || 0 === $level ) {
				continue; // Tarea raíz del proyecto.
			}
			$name = trim( (string) $t->Name );
			if ( '' === $name ) {
				continue;
			}
			$minutes = self::duration_minutes( (string) $t->Duration );
			$days    = (int) ceil( $minutes / $minutes_per_day );
			$kind    = '1' === (string) $t->Summary ? 'summary' : ( '1' === (string) $t->Milestone || 0 === $minutes ? 'milestone' : 'activity' );
			$preds   = array();
			foreach ( $t->PredecessorLink as $link ) {
				$lag_tenths = (int) $link->LinkLag;
				$preds[]    = array(
					'ref'  => (string) $link->PredecessorUID,
					'type' => $types[ (int) $link->Type ] ?? 'FS',
					'lag'  => (int) round( $lag_tenths / 10 / $minutes_per_day ),
				);
			}
			$rows[] = array(
				'ref'             => $uid,
				'code'            => (string) ( $t->OutlineNumber ?? $t->WBS ?? '' ),
				'level'           => $level,
				'name'            => $name,
				'kind'            => $kind,
				'duration'        => 'summary' === $kind ? 0 : max( 'milestone' === $kind ? 0 : 1, $days ),
				'percent'         => (int) $t->PercentComplete,
				'constraint_type' => $cons[ (int) $t->ConstraintType ] ?? 'asap',
				'constraint_date' => self::date( (string) $t->ConstraintDate ),
				'actual_start'    => self::date( (string) $t->ActualStart ),
				'actual_finish'   => self::date( (string) $t->ActualFinish ),
				'start'           => self::date( (string) $t->Start ),
				'finish'          => self::date( (string) $t->Finish ),
				'deliverable'     => trim( (string) $t->Notes ),
				'predecessors'    => $preds,
				'work_front'      => '',
				'owner'           => '',
			);
		}

		return $rows;
	}

	/**
	 * Minutos de una duración ISO de Project (PT8H0M0S).
	 *
	 * @param string $value Duración.
	 * @return int
	 */
	private static function duration_minutes( string $value ): int {
		if ( ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $value, $m ) ) {
			return 0;
		}

		return (int) ( $m[1] ?? 0 ) * 60 + (int) ( $m[2] ?? 0 );
	}

	/**
	 * Fecha Y-m-d de un valor fecha-hora de Project.
	 *
	 * @param string $value Valor.
	 * @return string|null
	 */
	private static function date( string $value ): ?string {
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) ? $m[1] : null;
	}
}
