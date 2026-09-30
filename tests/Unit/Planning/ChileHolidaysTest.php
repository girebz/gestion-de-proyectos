<?php
/**
 * Pruebas del generador de feriados de Chile.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Planning;

use GDP\Planning\ChileHolidays;
use PHPUnit\Framework\TestCase;

/**
 * Comprueba los feriados fijos y las reglas de traslado contra fechas conocidas.
 */
final class ChileHolidaysTest extends TestCase {

	public function test_easter_matches_known_dates(): void {
		$known = array(
			2000 => '2000-04-23',
			2019 => '2019-04-21',
			2024 => '2024-03-31',
			2025 => '2025-04-20',
			2026 => '2026-04-05',
			2027 => '2027-03-28',
			2028 => '2028-04-16',
			2030 => '2030-04-21',
			2038 => '2038-04-25',
		);
		foreach ( $known as $year => $date ) {
			$this->assertSame( $date, ChileHolidays::easter( $year )->format( 'Y-m-d' ), (string) $year );
		}
	}

	public function test_holy_week_days(): void {
		$holidays = ChileHolidays::for_year( 2026 );
		$this->assertSame( 'Viernes Santo', $holidays['2026-04-03'] );
		$this->assertSame( 'Sábado Santo', $holidays['2026-04-04'] );
		$this->assertArrayNotHasKey( '2026-04-05', $holidays, 'el domingo de Pascua no es feriado legal' );
	}

	public function test_fixed_holidays_of_2026(): void {
		$holidays = ChileHolidays::for_year( 2026 );
		foreach ( array( '01-01', '05-01', '05-21', '07-16', '08-15', '09-18', '09-19', '11-01', '12-08', '12-25' ) as $md ) {
			$this->assertArrayHasKey( '2026-' . $md, $holidays, $md );
		}
		$this->assertCount( 16, $holidays );
		$this->assertSame( array_keys( $holidays ), array_values( ( static function ( array $keys ) {
			sort( $keys );
			return $keys;
		} )( array_keys( $holidays ) ) ), 'ordenados por fecha' );
	}

	public function test_monday_rule(): void {
		$this->assertSame( '2027-06-28', ChileHolidays::monday_rule( '2027-06-29' ), 'martes pasa al lunes' );
		$this->assertSame( '2028-06-26', ChileHolidays::monday_rule( '2028-06-29' ), 'jueves pasa al lunes' );
		$this->assertSame( '2029-07-02', ChileHolidays::monday_rule( '2029-06-29' ), 'viernes pasa al lunes siguiente' );
		$this->assertSame( '2018-10-15', ChileHolidays::monday_rule( '2018-10-12' ), 'viernes pasa al lunes siguiente' );
		$this->assertSame( '2030-06-29', ChileHolidays::monday_rule( '2030-06-29' ), 'sábado no se mueve' );
		$this->assertSame( '2025-06-29', ChileHolidays::monday_rule( '2025-06-29' ), 'domingo no se mueve' );
		$this->assertSame( '2026-10-12', ChileHolidays::monday_rule( '2026-10-12' ), 'lunes no se mueve' );
	}

	public function test_monday_rule_is_applied_in_the_year_list(): void {
		$this->assertArrayHasKey( '2027-06-28', ChileHolidays::for_year( 2027 ) );
		$this->assertArrayNotHasKey( '2027-06-29', ChileHolidays::for_year( 2027 ) );
		$this->assertArrayHasKey( '2027-10-11', ChileHolidays::for_year( 2027 ) );
	}

	public function test_evangelical_churches_day(): void {
		$this->assertSame( '2023-10-27', ChileHolidays::evangelical_rule( 2023 ), 'martes: viernes anterior' );
		$this->assertSame( '2028-10-27', ChileHolidays::evangelical_rule( 2028 ), 'martes: viernes anterior' );
		$this->assertSame( '2018-11-02', ChileHolidays::evangelical_rule( 2018 ), 'miércoles: viernes siguiente' );
		$this->assertSame( '2029-11-02', ChileHolidays::evangelical_rule( 2029 ), 'miércoles: viernes siguiente' );
		$this->assertSame( '2026-10-31', ChileHolidays::evangelical_rule( 2026 ), 'sábado: sin cambio' );
	}

	public function test_winter_solstice_dates(): void {
		$known = array(
			2021 => '2021-06-21',
			2022 => '2022-06-21',
			2023 => '2023-06-21',
			2024 => '2024-06-20',
			2025 => '2025-06-20',
			2026 => '2026-06-21',
			2027 => '2027-06-21',
			2028 => '2028-06-20',
			2029 => '2029-06-20',
			2030 => '2030-06-21',
		);
		foreach ( $known as $year => $date ) {
			$this->assertSame( $date, ChileHolidays::winter_solstice( $year ), (string) $year );
		}
	}

	public function test_june_solstice_instant_is_accurate_to_minutes(): void {
		// Instantes de referencia en tiempo universal (tolerancia de cinco minutos).
		$known = array(
			2024 => '2024-06-20 20:51',
			2025 => '2025-06-21 02:42',
			2026 => '2026-06-21 08:24',
			2028 => '2028-06-20 20:02',
		);
		foreach ( $known as $year => $expected ) {
			$computed = ChileHolidays::june_solstice( $year )->getTimestamp();
			$ref      = ( new \DateTimeImmutable( $expected, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
			$this->assertLessThanOrEqual( 300, abs( $computed - $ref ), (string) $year );
		}
	}

	public function test_fiestas_patrias_bridge_days(): void {
		$this->assertArrayHasKey( '2024-09-20', ChileHolidays::for_year( 2024 ), '18 en miércoles: viernes 20' );
		$this->assertArrayHasKey( '2029-09-17', ChileHolidays::for_year( 2029 ), '18 en martes: lunes 17' );
		$this->assertArrayNotHasKey( '2026-09-17', ChileHolidays::for_year( 2026 ) );
		$this->assertArrayNotHasKey( '2026-09-20', ChileHolidays::for_year( 2026 ) );
	}

	public function test_new_year_on_sunday_adds_monday(): void {
		$this->assertArrayHasKey( '2023-01-02', ChileHolidays::for_year( 2023 ) );
		$this->assertArrayHasKey( '2034-01-02', ChileHolidays::for_year( 2034 ) );
		$this->assertArrayNotHasKey( '2026-01-02', ChileHolidays::for_year( 2026 ) );
	}

	public function test_exceptions_for_years(): void {
		$exceptions = ChileHolidays::exceptions_for_years( 2026, 2027 );
		$this->assertCount( count( ChileHolidays::for_year( 2026 ) ) + count( ChileHolidays::for_year( 2027 ) ), $exceptions );
		$this->assertSame( array( 'working' => false, 'label' => 'Navidad' ), $exceptions['2027-12-25'] );
		foreach ( $exceptions as $date => $exception ) {
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $date );
			$this->assertFalse( $exception['working'] );
		}
	}
}
