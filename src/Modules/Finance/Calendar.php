<?php
/**
 * Calendario laboral del módulo de finanzas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Planning\CalendarRepository;
use GDP\Planning\ChileHolidays;
use GDP\Planning\WorkCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Los plazos de rendición se cuentan en días hábiles con el calendario del
 * proyecto y los feriados de Chile; las excepciones declaradas en el
 * calendario del proyecto prevalecen sobre los feriados generados.
 */
final class Calendar {

	/**
	 * Calendario del proyecto con feriados de Chile.
	 *
	 * @param int $project_id Proyecto.
	 * @return WorkCalendar
	 */
	public static function for_project( int $project_id ): WorkCalendar {
		static $cache = array();
		if ( isset( $cache[ $project_id ] ) ) {
			return $cache[ $project_id ];
		}
		$base     = CalendarRepository::build( $project_id );
		$year     = (int) current_time( 'Y' );
		$holidays = array();
		foreach ( ChileHolidays::exceptions_for_years( $year - 2, $year + 3 ) as $date => $e ) {
			$holidays[ $date ] = (bool) $e['working'];
		}
		try {
			$cache[ $project_id ] = new WorkCalendar( $base->weekdays(), array_merge( $holidays, $base->exceptions() ) );
		} catch ( \InvalidArgumentException $e ) {
			$cache[ $project_id ] = new WorkCalendar( array( 1, 2, 3, 4, 5 ), $holidays );
		}

		return $cache[ $project_id ];
	}
}
