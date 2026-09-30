<?php
/**
 * Feriados de Chile.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Planning;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Genera los feriados nacionales de Chile para un año, incluidos los
 * movibles: Semana Santa por el cómputo de Pascua; 29 de junio y 12 de
 * octubre por la regla de traslado al lunes (ley 19.973); 31 de octubre por
 * su regla propia (ley 20.299); el solsticio de invierno (ley 21.357); el
 * 17 o el 20 de septiembre cuando las Fiestas Patrias caen a mitad de semana
 * (ley 20.215) y el 2 de enero cuando el 1 cae en domingo (ley 20.983).
 *
 * No incluye feriados regionales ni los que se decretan cada año
 * (elecciones, censos), que el administrador añade como excepciones.
 */
final class ChileHolidays {

	/**
	 * Feriados de un año: fecha => nombre.
	 *
	 * @param int $year Año.
	 * @return array<string,string>
	 */
	public static function for_year( int $year ): array {
		$holidays = array(
			sprintf( '%d-01-01', $year ) => 'Año Nuevo',
			sprintf( '%d-05-01', $year ) => 'Día Nacional del Trabajo',
			sprintf( '%d-05-21', $year ) => 'Día de las Glorias Navales',
			sprintf( '%d-07-16', $year ) => 'Día de la Virgen del Carmen',
			sprintf( '%d-08-15', $year ) => 'Asunción de la Virgen',
			sprintf( '%d-09-18', $year ) => 'Independencia Nacional',
			sprintf( '%d-09-19', $year ) => 'Día de las Glorias del Ejército',
			sprintf( '%d-11-01', $year ) => 'Día de Todos los Santos',
			sprintf( '%d-12-08', $year ) => 'Inmaculada Concepción',
			sprintf( '%d-12-25', $year ) => 'Navidad',
		);

		// 1 de enero en domingo: el lunes 2 también es feriado.
		if ( 7 === (int) ( new DateTimeImmutable( sprintf( '%d-01-01', $year ) ) )->format( 'N' ) ) {
			$holidays[ sprintf( '%d-01-02', $year ) ] = 'Feriado adicional de Año Nuevo';
		}

		// Semana Santa.
		$easter = self::easter( $year );
		$holidays[ $easter->modify( '-2 days' )->format( 'Y-m-d' ) ] = 'Viernes Santo';
		$holidays[ $easter->modify( '-1 day' )->format( 'Y-m-d' ) ]  = 'Sábado Santo';

		// Día Nacional de los Pueblos Indígenas: solsticio de invierno.
		$holidays[ self::winter_solstice( $year ) ] = 'Día Nacional de los Pueblos Indígenas';

		// Feriados trasladables al lunes.
		$holidays[ self::monday_rule( sprintf( '%d-06-29', $year ) ) ] = 'San Pedro y San Pablo';
		$holidays[ self::monday_rule( sprintf( '%d-10-12', $year ) ) ] = 'Encuentro de Dos Mundos';

		// Fiestas Patrias a mitad de semana: puente del 17 o del 20.
		$weekday_18 = (int) ( new DateTimeImmutable( sprintf( '%d-09-18', $year ) ) )->format( 'N' );
		if ( 2 === $weekday_18 ) {
			$holidays[ sprintf( '%d-09-17', $year ) ] = 'Feriado adicional de Fiestas Patrias';
		} elseif ( 3 === $weekday_18 ) {
			$holidays[ sprintf( '%d-09-20', $year ) ] = 'Feriado adicional de Fiestas Patrias';
		}

		// Iglesias Evangélicas y Protestantes.
		$holidays[ self::evangelical_rule( $year ) ] = 'Día de las Iglesias Evangélicas y Protestantes';

		ksort( $holidays );

		return $holidays;
	}

	/**
	 * Feriados de un rango de años, como excepciones de calendario (no laborables).
	 *
	 * @param int $from Primer año.
	 * @param int $to   Último año.
	 * @return array<string,array{working:bool,label:string}>
	 */
	public static function exceptions_for_years( int $from, int $to ): array {
		$out = array();
		for ( $y = $from; $y <= $to; $y++ ) {
			foreach ( self::for_year( $y ) as $date => $label ) {
				$out[ $date ] = array(
					'working' => false,
					'label'   => $label,
				);
			}
		}

		return $out;
	}

	/**
	 * Domingo de Pascua (algoritmo de Meeus, Jones y Butcher).
	 *
	 * @param int $year Año.
	 * @return DateTimeImmutable
	 */
	public static function easter( int $year ): DateTimeImmutable {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		return new DateTimeImmutable( sprintf( '%d-%02d-%02d', $year, $month, $day ) );
	}

	/**
	 * Fecha del feriado del solsticio de invierno austral (20 o 21 de junio).
	 *
	 * El feriado corresponde al día en que ocurre el solsticio según la hora
	 * oficial de Chile continental (UTC-4 en junio). El instante se calcula
	 * con el algoritmo de Meeus (Astronomical Algorithms, capítulo 27), cuya
	 * precisión, de pocos minutos, basta para fijar el día. El año 2021, primero
	 * de vigencia de la ley 21.357, se celebró el 21 por disposición transitoria.
	 *
	 * @param int $year Año.
	 * @return string
	 */
	public static function winter_solstice( int $year ): string {
		if ( 2021 === $year ) {
			return '2021-06-21';
		}

		return self::june_solstice( $year )
			->setTimezone( new DateTimeZone( '-04:00' ) )
			->format( 'Y-m-d' );
	}

	/**
	 * Instante del solsticio de junio en tiempo universal (Meeus, cap. 27).
	 *
	 * @param int $year Año.
	 * @return DateTimeImmutable
	 */
	public static function june_solstice( int $year ): DateTimeImmutable {
		$y    = ( $year - 2000 ) / 1000;
		$jde0 = 2451716.56767 + 365241.62603 * $y + 0.00325 * $y ** 2 + 0.00888 * $y ** 3 - 0.00030 * $y ** 4;
		$t    = ( $jde0 - 2451545.0 ) / 36525;
		$w    = deg2rad( 35999.373 * $t - 2.47 );
		$dl   = 1 + 0.0334 * cos( $w ) + 0.0007 * cos( 2 * $w );

		$terms = array(
			array( 485, 324.96, 1934.136 ),
			array( 203, 337.23, 32964.467 ),
			array( 199, 342.08, 20.186 ),
			array( 182, 27.85, 445267.112 ),
			array( 156, 73.14, 45036.886 ),
			array( 136, 171.52, 22518.443 ),
			array( 77, 222.54, 65928.934 ),
			array( 74, 296.72, 3034.906 ),
			array( 70, 243.58, 9037.513 ),
			array( 58, 119.81, 33718.147 ),
			array( 52, 297.17, 150.678 ),
			array( 50, 21.02, 2281.226 ),
			array( 45, 247.54, 29929.562 ),
			array( 44, 325.15, 31555.956 ),
			array( 29, 60.93, 4443.417 ),
			array( 18, 155.12, 67555.328 ),
			array( 17, 288.79, 4562.452 ),
			array( 16, 198.04, 62894.029 ),
			array( 14, 199.76, 31436.921 ),
			array( 12, 95.39, 14577.848 ),
			array( 12, 287.11, 31931.756 ),
			array( 12, 320.81, 34777.259 ),
			array( 9, 227.73, 1222.114 ),
			array( 8, 15.45, 16859.074 ),
		);

		$s = 0.0;
		foreach ( $terms as $term ) {
			$s += $term[0] * cos( deg2rad( $term[1] + $term[2] * $t ) );
		}

		// Tiempo terrestre; la diferencia con el tiempo universal (poco más de un minuto) se desprecia.
		$jde = $jde0 + 0.00001 * $s / $dl;

		// Conversión de día juliano a fecha civil (calendario gregoriano).
		$jd    = $jde + 0.5;
		$z     = (int) floor( $jd );
		$f     = $jd - $z;
		$alpha = (int) floor( ( $z - 1867216.25 ) / 36524.25 );
		$a     = $z + 1 + $alpha - intdiv( $alpha, 4 );
		$b     = $a + 1524;
		$c     = (int) floor( ( $b - 122.1 ) / 365.25 );
		$d     = (int) floor( 365.25 * $c );
		$e     = (int) floor( ( $b - $d ) / 30.6001 );
		$day   = $b - $d - (int) floor( 30.6001 * $e ) + $f;
		$month = $e < 14 ? $e - 1 : $e - 13;
		$yr    = $month > 2 ? $c - 4716 : $c - 4715;

		$day_int = (int) floor( $day );
		$seconds = (int) round( ( $day - $day_int ) * 86400 );

		return ( new DateTimeImmutable( sprintf( '%d-%02d-%02d 00:00:00', $yr, $month, $day_int ), new DateTimeZone( 'UTC' ) ) )
			->modify( sprintf( '+%d seconds', $seconds ) );
	}

	/**
	 * Regla de traslado al lunes (ley 19.973): martes, miércoles o jueves pasan
	 * al lunes de la misma semana; viernes pasa al lunes siguiente.
	 *
	 * @param string $date Fecha nominal.
	 * @return string
	 */
	public static function monday_rule( string $date ): string {
		$dt      = new DateTimeImmutable( $date );
		$weekday = (int) $dt->format( 'N' );

		if ( $weekday >= 2 && $weekday <= 4 ) {
			return $dt->modify( '-' . ( $weekday - 1 ) . ' days' )->format( 'Y-m-d' );
		}
		if ( 5 === $weekday ) {
			return $dt->modify( '+3 days' )->format( 'Y-m-d' );
		}

		return $date;
	}

	/**
	 * Regla del 31 de octubre (ley 20.299): martes pasa al viernes anterior;
	 * miércoles, al viernes siguiente.
	 *
	 * @param int $year Año.
	 * @return string
	 */
	public static function evangelical_rule( int $year ): string {
		$dt      = new DateTimeImmutable( sprintf( '%d-10-31', $year ) );
		$weekday = (int) $dt->format( 'N' );

		if ( 2 === $weekday ) {
			return $dt->modify( '-4 days' )->format( 'Y-m-d' );
		}
		if ( 3 === $weekday ) {
			return $dt->modify( '+2 days' )->format( 'Y-m-d' );
		}

		return $dt->format( 'Y-m-d' );
	}
}
