<?php
/**
 * Pruebas del calendario laboral.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Planning;

use GDP\Planning\WorkCalendar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Calendario de referencia: lunes a viernes, con el lunes 12 de octubre de
 * 2026 como feriado y el sábado 17 de octubre de 2026 como día de recuperación.
 */
final class WorkCalendarTest extends TestCase {

	/**
	 * Calendario de referencia.
	 *
	 * @var WorkCalendar
	 */
	private WorkCalendar $calendar;

	/**
	 * Preparación.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calendar = new WorkCalendar(
			array( 1, 2, 3, 4, 5 ),
			array(
				'2026-10-12' => false,
				'2026-10-17' => true,
			)
		);
	}

	public function test_weekdays_are_normalized_and_sorted(): void {
		$calendar = new WorkCalendar( array( 5, '1', 3, 9, 0, 3 ) );
		$this->assertSame( array( 1, 3, 5 ), $calendar->weekdays() );
	}

	public function test_calendar_requires_a_working_weekday(): void {
		$this->expectException( InvalidArgumentException::class );
		new WorkCalendar( array( 0, 8 ) );
	}

	public function test_is_working_honors_weekdays_and_exceptions(): void {
		$this->assertTrue( $this->calendar->is_working( '2026-10-05' ), 'lunes' );
		$this->assertTrue( $this->calendar->is_working( '2026-10-09' ), 'viernes' );
		$this->assertFalse( $this->calendar->is_working( '2026-10-10' ), 'sábado' );
		$this->assertFalse( $this->calendar->is_working( '2026-10-11' ), 'domingo' );
		$this->assertFalse( $this->calendar->is_working( '2026-10-12' ), 'feriado en lunes' );
		$this->assertTrue( $this->calendar->is_working( '2026-10-17' ), 'sábado de recuperación' );
	}

	public function test_six_day_week(): void {
		$calendar = new WorkCalendar( array( 1, 2, 3, 4, 5, 6 ) );
		$this->assertTrue( $calendar->is_working( '2026-10-10' ) );
		$this->assertFalse( $calendar->is_working( '2026-10-11' ) );
	}

	public function test_next_and_previous_working(): void {
		$this->assertSame( '2026-10-05', $this->calendar->next_working( '2026-10-05' ) );
		$this->assertSame( '2026-10-13', $this->calendar->next_working( '2026-10-10' ) );
		$this->assertSame( '2026-10-13', $this->calendar->next_working( '2026-10-12' ) );
		$this->assertSame( '2026-10-09', $this->calendar->previous_working( '2026-10-12' ) );
		$this->assertSame( '2026-10-09', $this->calendar->previous_working( '2026-10-11' ) );
		$this->assertSame( '2026-10-17', $this->calendar->previous_working( '2026-10-18' ) );
	}

	public function test_add_working_days(): void {
		$this->assertSame( '2026-10-13', $this->calendar->add_working_days( '2026-10-09', 1 ) );
		$this->assertSame( '2026-10-09', $this->calendar->add_working_days( '2026-10-13', -1 ) );
		$this->assertSame( '2026-10-13', $this->calendar->add_working_days( '2026-10-10', 0 ), 'origen no laborable: siguiente hábil' );
		$this->assertSame( '2026-10-17', $this->calendar->add_working_days( '2026-10-16', 1 ), 'sábado de recuperación cuenta' );
		$this->assertSame( '2026-10-13', $this->calendar->add_working_days( '2026-10-05', 5 ) );
		$this->assertSame( '2026-10-05', $this->calendar->add_working_days( '2026-10-13', -5 ) );
		$this->assertSame( '2026-10-02', $this->calendar->add_working_days( '2026-10-05', -1 ) );
	}

	public function test_working_days_between_is_signed(): void {
		$this->assertSame( 5, $this->calendar->working_days_between( '2026-10-05', '2026-10-13' ) );
		$this->assertSame( -5, $this->calendar->working_days_between( '2026-10-13', '2026-10-05' ) );
		$this->assertSame( 0, $this->calendar->working_days_between( '2026-10-05', '2026-10-05' ) );
		$this->assertSame( 0, $this->calendar->working_days_between( '2026-10-10', '2026-10-13' ), 'ambos extremos se normalizan al siguiente hábil' );
		$this->assertSame( 1, $this->calendar->working_days_between( '2026-10-16', '2026-10-17' ) );
	}

	public function test_count_working_days_is_inclusive(): void {
		$this->assertSame( 10, $this->calendar->count_working_days( '2026-10-05', '2026-10-18' ) );
		$this->assertSame( 1, $this->calendar->count_working_days( '2026-10-05', '2026-10-05' ) );
		$this->assertSame( 0, $this->calendar->count_working_days( '2026-10-10', '2026-10-11' ) );
		$this->assertSame( 0, $this->calendar->count_working_days( '2026-10-09', '2026-10-05' ), 'intervalo invertido' );
	}

	public function test_with_exceptions_returns_a_new_calendar(): void {
		$other = $this->calendar->with_exceptions( array( '2026-10-13' => false ) );
		$this->assertFalse( $other->is_working( '2026-10-13' ) );
		$this->assertTrue( $this->calendar->is_working( '2026-10-13' ), 'el original no cambia' );
		$this->assertCount( 3, $other->exceptions() );
	}

	public function test_normalize_accepts_only_canonical_dates(): void {
		$this->assertSame( '2026-02-01', WorkCalendar::normalize( ' 2026-02-01 ' ) );
		$this->assertSame( '2024-02-29', WorkCalendar::normalize( '2024-02-29' ) );

		foreach ( array( '2026-13-01', '2026-2-1', '2026-02-30', '01/02/2026', '', 'hoy' ) as $bad ) {
			try {
				WorkCalendar::normalize( $bad );
				$this->fail( sprintf( 'Se aceptó la fecha no válida "%s".', $bad ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'Fecha no válida', $e->getMessage() );
			}
		}
	}

	public function test_exceptions_with_invalid_dates_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new WorkCalendar( array( 1 ), array( '2026-99-99' => false ) );
	}
}
