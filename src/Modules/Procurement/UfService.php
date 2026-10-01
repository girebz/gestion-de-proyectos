<?php
/**
 * Valores diarios de la unidad de fomento.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Options;
use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda localmente el valor oficial de la unidad de fomento por día, lo
 * obtiene de una fuente pública (mindicador.cl, que publica los valores del
 * Banco Central) o se carga a mano, y resuelve el valor aplicable a una fecha
 * (el del día o, si falta, el último anterior conocido).
 */
final class UfService {

	public const SOURCE_URL = 'https://mindicador.cl/api/uf/%s';

	/**
	 * Valor aplicable a una fecha.
	 *
	 * @param string $date Fecha (Y-m-d).
	 * @return array{date:string,value:float,source:string,exact:bool}|null
	 */
	public static function rate_for( string $date ): ?array {
		global $wpdb;

		$table = Schema::table( 'uf_rates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE rate_date <= %s ORDER BY rate_date DESC LIMIT 1", $date ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}

		return array(
			'date'   => (string) $row['rate_date'],
			'value'  => (float) $row['value_clp'],
			'source' => (string) $row['source'],
			'exact'  => (string) $row['rate_date'] === $date,
		);
	}

	/**
	 * Último valor conocido.
	 *
	 * @return array{date:string,value:float,source:string,exact:bool}|null
	 */
	public static function latest(): ?array {
		return self::rate_for( '9999-12-31' );
	}

	/**
	 * Valores entre dos fechas.
	 *
	 * @param string $from Desde.
	 * @param string $to   Hasta.
	 * @return array<int,array{date:string,value:float,source:string}>
	 */
	public static function between( string $from, string $to ): array {
		global $wpdb;

		$table = Schema::table( 'uf_rates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE rate_date >= %s AND rate_date <= %s ORDER BY rate_date DESC", $from, $to ), ARRAY_A );

		return array_map( static fn( array $r ): array => array( 'date' => (string) $r['rate_date'], 'value' => (float) $r['value_clp'], 'source' => (string) $r['source'] ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Guarda o reemplaza el valor de un día.
	 *
	 * @param string $date   Fecha.
	 * @param float  $value  Valor en pesos.
	 * @param string $source Origen (manual, mindicador).
	 * @return bool|WP_Error
	 */
	public static function set( string $date, float $value, string $source = 'manual' ) {
		global $wpdb;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $value <= 0 ) {
			return new WP_Error( 'uf', __( 'Indique una fecha válida y un valor positivo.', 'gestion-de-proyectos' ) );
		}
		$table = Schema::table( 'uf_rates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (rate_date, value_clp, source, fetched_at) VALUES (%s, %f, %s, %s)", $date, $value, sanitize_key( $source ), current_time( 'mysql', true ) ) );

		return false === $ok ? new WP_Error( 'db', __( 'No se pudo guardar el valor.', 'gestion-de-proyectos' ) ) : true;
	}

	/**
	 * Obtiene de la fuente pública el valor de un día y lo guarda.
	 *
	 * @param string $date Fecha (Y-m-d).
	 * @return array{date:string,value:float}|WP_Error
	 */
	public static function fetch( string $date ) {
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt ) {
			return new WP_Error( 'date', __( 'Fecha no válida.', 'gestion-de-proyectos' ) );
		}
		$response = wp_remote_get( sprintf( self::SOURCE_URL, $dt->format( 'd-m-Y' ) ), array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) ) {
			/* translators: mensaje de error. */
			return new WP_Error( 'network', sprintf( __( 'No se pudo consultar la fuente del valor de la unidad de fomento: %s', 'gestion-de-proyectos' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			/* translators: código HTTP. */
			return new WP_Error( 'source', sprintf( __( 'La fuente respondió con el código %d.', 'gestion-de-proyectos' ), $code ) );
		}
		$serie = $data['serie'] ?? array();
		if ( empty( $serie ) || ! isset( $serie[0]['valor'] ) ) {
			return new WP_Error( 'empty', __( 'La fuente no tiene valor para esa fecha (fin de semana o fecha futura).', 'gestion-de-proyectos' ) );
		}
		$value   = (float) $serie[0]['valor'];
		$of_date = substr( (string) ( $serie[0]['fecha'] ?? $date ), 0, 10 );
		$saved   = self::set( $of_date, $value, 'mindicador' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array( 'date' => $of_date, 'value' => $value );
	}

	/**
	 * Asegura el valor de hoy (tarea diaria); si la fuente falla, lo deja anotado.
	 *
	 * @return void
	 */
	public static function ensure_today(): void {
		$today = current_time( 'Y-m-d' );
		$rate  = self::rate_for( $today );
		if ( $rate && $rate['exact'] ) {
			return;
		}
		$result = self::fetch( $today );
		update_option( 'gdp_uf_last_fetch', array( 'at' => current_time( 'mysql' ), 'ok' => ! is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : '' ), false );
	}

	/**
	 * Convierte un monto a pesos con el valor aplicable a una fecha.
	 *
	 * @param float  $amount   Monto.
	 * @param string $currency Moneda (CLP, UF, USD).
	 * @param string $date     Fecha de referencia.
	 * @return array{clp:float|null,rate:float|null,rate_date:string|null,warning:string}
	 */
	public static function to_clp( float $amount, string $currency, string $date ): array {
		if ( 'CLP' === $currency ) {
			return array( 'clp' => round( $amount, 0 ), 'rate' => null, 'rate_date' => null, 'warning' => '' );
		}
		if ( 'UF' === $currency ) {
			$rate = self::rate_for( $date );
			if ( ! $rate ) {
				return array( 'clp' => null, 'rate' => null, 'rate_date' => null, 'warning' => __( 'No hay valor de la unidad de fomento registrado; cargue uno en Presupuesto.', 'gestion-de-proyectos' ) );
			}

			return array(
				'clp'       => UfMath::to_clp( $amount, $rate['value'] ),
				'rate'      => $rate['value'],
				'rate_date' => $rate['date'],
				/* translators: fecha del valor usado. */
				'warning'   => $rate['exact'] ? '' : sprintf( __( 'Se usó el valor de la unidad de fomento del %s (no hay valor para la fecha indicada).', 'gestion-de-proyectos' ), $rate['date'] ),
			);
		}
		$usd = (float) Options::get( 'usd_rate', 0 );
		if ( $usd <= 0 ) {
			return array( 'clp' => null, 'rate' => null, 'rate_date' => null, 'warning' => __( 'Fije el tipo de cambio del dólar en Ajustes para convertir montos en dólares.', 'gestion-de-proyectos' ) );
		}

		return array( 'clp' => round( $amount * $usd, 0 ), 'rate' => $usd, 'rate_date' => null, 'warning' => '' );
	}
}
