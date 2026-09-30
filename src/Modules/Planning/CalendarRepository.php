<?php
/**
 * Repositorio de calendarios laborales.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Planning\ChileHolidays;
use GDP\Planning\WorkCalendar;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada proyecto programa con su calendario por defecto; si no tiene uno, con
 * el calendario global por defecto (project_id = 0); y si tampoco existe, con
 * la semana de lunes a viernes sin feriados.
 */
final class CalendarRepository {

	/**
	 * Un calendario.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'calendars' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Calendarios de un proyecto (o globales con project_id = 0).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'calendars' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY is_default DESC, id ASC", $project_id ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Calendario efectivo de un proyecto (registro), o null si se usa el implícito.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function effective( int $project_id ): ?array {
		foreach ( self::for_project( $project_id ) as $c ) {
			if ( $c['is_default'] ) {
				return $c;
			}
		}
		foreach ( self::for_project( 0 ) as $c ) {
			if ( $c['is_default'] ) {
				return $c;
			}
		}

		return null;
	}

	/**
	 * Construye el calendario laboral de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return WorkCalendar
	 */
	public static function build( int $project_id ): WorkCalendar {
		$record = self::effective( $project_id );
		if ( ! $record ) {
			return new WorkCalendar();
		}

		$exceptions = array();
		foreach ( self::exceptions( $record['id'] ) as $e ) {
			$exceptions[ $e['exception_date'] ] = $e['working'];
		}

		try {
			return new WorkCalendar( $record['weekdays'], $exceptions );
		} catch ( \InvalidArgumentException $e ) {
			return new WorkCalendar();
		}
	}

	/**
	 * Crea un calendario.
	 *
	 * @param int    $project_id Proyecto (0 = global).
	 * @param string $name       Nombre.
	 * @param int[]  $weekdays   Días laborables.
	 * @param bool   $is_default Por defecto.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, string $name, array $weekdays, bool $is_default = true ) {
		global $wpdb;

		$name     = sanitize_text_field( $name );
		$weekdays = self::clean_weekdays( $weekdays );
		if ( '' === $name ) {
			return new WP_Error( 'name', __( 'El calendario necesita un nombre.', 'gestion-de-proyectos' ) );
		}
		if ( empty( $weekdays ) ) {
			return new WP_Error( 'weekdays', __( 'Marque al menos un día laborable.', 'gestion-de-proyectos' ) );
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Schema::table( 'calendars' ),
			array(
				'project_id' => $project_id,
				'name'       => $name,
				'weekdays'   => implode( ',', $weekdays ),
				'is_default' => $is_default ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo crear el calendario.', 'gestion-de-proyectos' ) );
		}

		$id = (int) $wpdb->insert_id;
		if ( $is_default ) {
			self::set_default( $id );
		}

		Audit::log( 'calendar', $id, 'create', $project_id, sprintf( 'Calendario creado: %s', $name ) );

		return $id;
	}

	/**
	 * Actualiza nombre y días laborables.
	 *
	 * @param int    $id       Calendario.
	 * @param string $name     Nombre.
	 * @param int[]  $weekdays Días.
	 * @return bool|WP_Error
	 */
	public static function update( int $id, string $name, array $weekdays ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El calendario no existe.', 'gestion-de-proyectos' ) );
		}
		$name     = sanitize_text_field( $name );
		$weekdays = self::clean_weekdays( $weekdays );
		if ( '' === $name || empty( $weekdays ) ) {
			return new WP_Error( 'invalid', __( 'Indique un nombre y al menos un día laborable.', 'gestion-de-proyectos' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'calendars' ), array( 'name' => $name, 'weekdays' => implode( ',', $weekdays ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );

		Audit::log( 'calendar', $id, 'update', $current['project_id'], sprintf( 'Calendario actualizado: %s', $name ), $current, self::find( $id ) );

		return true;
	}

	/**
	 * Marca un calendario como el de su proyecto.
	 *
	 * @param int $id Calendario.
	 * @return void
	 */
	public static function set_default( int $id ): void {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return;
		}
		$table = Schema::table( 'calendars' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = CASE WHEN id = %d THEN 1 ELSE 0 END WHERE project_id = %d", $id, $current['project_id'] ) );
	}

	/**
	 * Elimina un calendario y sus excepciones.
	 *
	 * @param int $id Calendario.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'calendar_exceptions' ), array( 'calendar_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'calendars' ), array( 'id' => $id ), array( '%d' ) );

		Audit::log( 'calendar', $id, 'delete', $current['project_id'], sprintf( 'Calendario eliminado: %s', $current['name'] ), $current, null );

		return true;
	}

	/**
	 * Instantánea completa de un calendario (para eliminar de forma reversible).
	 *
	 * @param int $id Calendario.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $id ): ?array {
		$calendar = self::find( $id );
		if ( ! $calendar ) {
			return null;
		}

		return array(
			'calendar'   => $calendar,
			'exceptions' => self::exceptions( $id ),
		);
	}

	/**
	 * Reinserta un calendario eliminado a partir de su instantánea (mismo identificador).
	 *
	 * @param array<string,mixed> $snapshot Instantánea de snapshot().
	 * @return bool
	 */
	public static function restore( array $snapshot ): bool {
		global $wpdb;

		$c = $snapshot['calendar'] ?? null;
		if ( ! is_array( $c ) || empty( $c['id'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			Schema::table( 'calendars' ),
			array(
				'id'         => (int) $c['id'],
				'project_id' => (int) $c['project_id'],
				'name'       => (string) $c['name'],
				'weekdays'   => implode( ',', array_map( 'intval', (array) $c['weekdays'] ) ),
				'is_default' => ! empty( $c['is_default'] ) ? 1 : 0,
				'created_at' => (string) $c['created_at'],
				'updated_at' => (string) $c['updated_at'],
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		foreach ( $snapshot['exceptions'] ?? array() as $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->replace(
				Schema::table( 'calendar_exceptions' ),
				array(
					'id'             => (int) $e['id'],
					'calendar_id'    => (int) $c['id'],
					'exception_date' => (string) $e['exception_date'],
					'working'        => ! empty( $e['working'] ) ? 1 : 0,
					'label'          => (string) $e['label'],
				),
				array( '%d', '%d', '%s', '%d', '%s' )
			);
		}
		if ( ! empty( $c['is_default'] ) ) {
			self::set_default( (int) $c['id'] );
		}

		return true;
	}

	/**
	 * Excepciones de un calendario.
	 *
	 * @param int $calendar_id Calendario.
	 * @return array<int,array<string,mixed>>
	 */
	public static function exceptions( int $calendar_id ): array {
		global $wpdb;

		$table = Schema::table( 'calendar_exceptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE calendar_id = %d ORDER BY exception_date ASC", $calendar_id ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				$row['id']          = (int) $row['id'];
				$row['calendar_id'] = (int) $row['calendar_id'];
				$row['working']     = ! empty( $row['working'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * Crea o actualiza una excepción.
	 *
	 * @param int    $calendar_id Calendario.
	 * @param string $date        Fecha.
	 * @param bool   $working     Laborable.
	 * @param string $label       Etiqueta.
	 * @return bool|WP_Error
	 */
	public static function set_exception( int $calendar_id, string $date, bool $working, string $label = '' ) {
		global $wpdb;

		$date = ActivityRepository::normalize_date( $date );
		if ( ! $date ) {
			return new WP_Error( 'date', __( 'Fecha no válida; use el formato AAAA-MM-DD.', 'gestion-de-proyectos' ) );
		}
		if ( ! self::find( $calendar_id ) ) {
			return new WP_Error( 'not_found', __( 'El calendario no existe.', 'gestion-de-proyectos' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			Schema::table( 'calendar_exceptions' ),
			array(
				'calendar_id'    => $calendar_id,
				'exception_date' => $date,
				'working'        => $working ? 1 : 0,
				'label'          => sanitize_text_field( $label ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		return true;
	}

	/**
	 * Elimina una excepción.
	 *
	 * @param int    $calendar_id Calendario.
	 * @param string $date        Fecha.
	 * @return void
	 */
	public static function remove_exception( int $calendar_id, string $date ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'calendar_exceptions' ), array( 'calendar_id' => $calendar_id, 'exception_date' => $date ), array( '%d', '%s' ) );
	}

	/**
	 * Carga los feriados de Chile de un rango de años como no laborables.
	 *
	 * @param int $calendar_id Calendario.
	 * @param int $from        Primer año.
	 * @param int $to          Último año.
	 * @return int Número de feriados cargados.
	 */
	public static function add_chile_holidays( int $calendar_id, int $from, int $to ): int {
		$count = 0;
		foreach ( ChileHolidays::exceptions_for_years( $from, $to ) as $date => $e ) {
			if ( true === self::set_exception( $calendar_id, $date, false, $e['label'] ) ) {
				++$count;
			}
		}

		$record = self::find( $calendar_id );
		Audit::log( 'calendar', $calendar_id, 'update', $record ? $record['project_id'] : 0, sprintf( 'Feriados de Chile %d a %d cargados (%d fechas)', $from, $to, $count ) );

		return $count;
	}

	/**
	 * Elimina los calendarios de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		foreach ( self::for_project( $project_id ) as $c ) {
			self::delete( $c['id'] );
		}
	}

	/**
	 * Convierte una fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['project_id'] = (int) $row['project_id'];
		$row['is_default'] = ! empty( $row['is_default'] );
		$row['weekdays']   = self::clean_weekdays( explode( ',', (string) $row['weekdays'] ) );

		return $row;
	}

	/**
	 * Normaliza una lista de días de la semana.
	 *
	 * @param array<int,mixed> $weekdays Días.
	 * @return int[]
	 */
	private static function clean_weekdays( array $weekdays ): array {
		$out = array();
		foreach ( $weekdays as $d ) {
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) {
				$out[ $d ] = $d;
			}
		}
		ksort( $out );

		return array_values( $out );
	}
}
