<?php
/**
 * Calendario laboral.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Planning;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Aritmética de días hábiles. No depende de WordPress: se prueba en aislamiento.
 *
 * Un calendario se define por los días de la semana laborables (1 = lunes,
 * 7 = domingo) y por excepciones puntuales: fechas no laborables (feriados)
 * o laborables por excepción (por ejemplo, un sábado de recuperación).
 */
final class WorkCalendar {

	/**
	 * Días de la semana laborables (clave = número ISO, valor = true).
	 *
	 * @var array<int,bool>
	 */
	private array $weekdays;

	/**
	 * Excepciones: fecha => true (laborable) | false (no laborable).
	 *
	 * @var array<string,bool>
	 */
	private array $exceptions;

	/**
	 * Constructor.
	 *
	 * @param int[]              $weekdays   Días laborables (1 = lunes ... 7 = domingo).
	 * @param array<string,bool> $exceptions Excepciones por fecha (Y-m-d).
	 */
	public function __construct( array $weekdays = array( 1, 2, 3, 4, 5 ), array $exceptions = array() ) {
		$this->weekdays = array();
		foreach ( $weekdays as $d ) {
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) {
				$this->weekdays[ $d ] = true;
			}
		}

		if ( empty( $this->weekdays ) ) {
			throw new InvalidArgumentException( 'El calendario necesita al menos un día laborable.' );
		}

		$this->exceptions = array();
		foreach ( $exceptions as $date => $working ) {
			$this->exceptions[ self::normalize( (string) $date ) ] = (bool) $working;
		}
	}

	/**
	 * Días laborables de la semana.
	 *
	 * @return int[]
	 */
	public function weekdays(): array {
		$days = array_keys( $this->weekdays );
		sort( $days );

		return $days;
	}

	/**
	 * Excepciones.
	 *
	 * @return array<string,bool>
	 */
	public function exceptions(): array {
		return $this->exceptions;
	}

	/**
	 * Indica si una fecha es laborable.
	 *
	 * @param string $date Fecha (Y-m-d).
	 * @return bool
	 */
	public function is_working( string $date ): bool {
		$date = self::normalize( $date );

		if ( array_key_exists( $date, $this->exceptions ) ) {
			return $this->exceptions[ $date ];
		}

		$weekday = (int) ( new DateTimeImmutable( $date ) )->format( 'N' );

		return isset( $this->weekdays[ $weekday ] );
	}

	/**
	 * Primer día laborable igual o posterior a la fecha.
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	public function next_working( string $date ): string {
		$cursor = new DateTimeImmutable( self::normalize( $date ) );
		for ( $i = 0; $i < 400; $i++ ) {
			$candidate = $cursor->format( 'Y-m-d' );
			if ( $this->is_working( $candidate ) ) {
				return $candidate;
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		throw new InvalidArgumentException( 'No hay días laborables en el horizonte de búsqueda.' );
	}

	/**
	 * Último día laborable igual o anterior a la fecha.
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	public function previous_working( string $date ): string {
		$cursor = new DateTimeImmutable( self::normalize( $date ) );
		for ( $i = 0; $i < 400; $i++ ) {
			$candidate = $cursor->format( 'Y-m-d' );
			if ( $this->is_working( $candidate ) ) {
				return $candidate;
			}
			$cursor = $cursor->modify( '-1 day' );
		}

		throw new InvalidArgumentException( 'No hay días laborables en el horizonte de búsqueda.' );
	}

	/**
	 * Desplaza una fecha laborable n días hábiles (n puede ser negativo).
	 *
	 * Si la fecha de partida no es laborable, se toma el siguiente día hábil
	 * (o el anterior, para desplazamientos negativos) como origen.
	 *
	 * @param string $date Fecha de origen.
	 * @param int    $n    Días hábiles a desplazar.
	 * @return string
	 */
	public function add_working_days( string $date, int $n ): string {
		$origin = $n >= 0 ? $this->next_working( $date ) : $this->previous_working( $date );
		$cursor = new DateTimeImmutable( $origin );
		$step   = $n >= 0 ? '+1 day' : '-1 day';
		$left   = abs( $n );

		while ( $left > 0 ) {
			$cursor = $cursor->modify( $step );
			if ( $this->is_working( $cursor->format( 'Y-m-d' ) ) ) {
				--$left;
			}
		}

		return $cursor->format( 'Y-m-d' );
	}

	/**
	 * Días hábiles entre dos fechas: cuántos desplazamientos de add_working_days
	 * llevan de $from a $to (negativo si $to es anterior). Ambas se normalizan
	 * al día hábil igual o siguiente.
	 *
	 * @param string $from Origen.
	 * @param string $to   Destino.
	 * @return int
	 */
	public function working_days_between( string $from, string $to ): int {
		$a = $this->next_working( $from );
		$b = $this->next_working( $to );

		if ( $a === $b ) {
			return 0;
		}

		$sign  = $a < $b ? 1 : -1;
		$start = new DateTimeImmutable( $sign > 0 ? $a : $b );
		$end   = new DateTimeImmutable( $sign > 0 ? $b : $a );
		$count = 0;

		for ( $cursor = $start->modify( '+1 day' ); $cursor <= $end; $cursor = $cursor->modify( '+1 day' ) ) {
			if ( $this->is_working( $cursor->format( 'Y-m-d' ) ) ) {
				++$count;
			}
		}

		return $sign * $count;
	}

	/**
	 * Número de días hábiles del intervalo cerrado [$from, $to].
	 *
	 * @param string $from Inicio.
	 * @param string $to   Término.
	 * @return int
	 */
	public function count_working_days( string $from, string $to ): int {
		$a = self::normalize( $from );
		$b = self::normalize( $to );
		if ( $b < $a ) {
			return 0;
		}

		$count = 0;
		for ( $cursor = new DateTimeImmutable( $a ); $cursor->format( 'Y-m-d' ) <= $b; $cursor = $cursor->modify( '+1 day' ) ) {
			if ( $this->is_working( $cursor->format( 'Y-m-d' ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Devuelve un calendario con excepciones añadidas.
	 *
	 * @param array<string,bool> $exceptions Excepciones adicionales.
	 * @return WorkCalendar
	 */
	public function with_exceptions( array $exceptions ): WorkCalendar {
		return new self( $this->weekdays(), array_merge( $this->exceptions, $exceptions ) );
	}

	/**
	 * Normaliza y valida una fecha Y-m-d.
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	public static function normalize( string $date ): string {
		$date = trim( $date );
		$dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidArgumentException( sprintf( 'Fecha no válida: %s', $date ) );
		}

		return $date;
	}
}
