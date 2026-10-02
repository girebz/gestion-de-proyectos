<?php
/**
 * Programación de caja.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Un plan tiene una fila por mes con la transferencia solicitada, el gasto
 * programado, el aporte pecuniario a enterar y el hito que lo respalda. El
 * plan vigente es el último enviado o marcado como vigente.
 */
final class CashPlanRepository extends Repository {

	protected const TABLE  = 'finance_cash_plans';
	protected const ENTITY = 'finance_cash_plan';
	protected const CASTS  = array( 'submitted_at' => 'date', 'created_by' => 'int' );

	public const STATUSES = array( 'borrador', 'enviada', 'vigente', 'reemplazada' );

	/**
	 * Planes del proyecto, del más reciente al más antiguo.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id ): array {
		return self::for_project( $project_id, 'id DESC' );
	}

	/**
	 * Plan vigente (o el más reciente).
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function current( int $project_id ): ?array {
		$plans = self::all( $project_id );
		foreach ( $plans as $p ) {
			if ( 'vigente' === $p['status'] ) {
				return $p;
			}
		}
		foreach ( $plans as $p ) {
			if ( 'enviada' === $p['status'] ) {
				return $p;
			}
		}

		return $plans[0] ?? null;
	}

	/**
	 * Filas de un plan, por período.
	 *
	 * @param int $plan_id Plan.
	 * @return array<int,array{period:string,transfer:float,spend:float,cash:float,milestone:string}>
	 */
	public static function rows( int $plan_id ): array {
		global $wpdb;

		$table = Schema::table( 'finance_cash_plan_rows' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE plan_id = %d ORDER BY period ASC", $plan_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array( 'period' => (string) $r['period'], 'transfer' => (float) $r['transfer_planned'], 'spend' => (float) $r['spend_planned'], 'cash' => (float) $r['cash_planned'], 'milestone' => (string) $r['milestone'] );
		}

		return $out;
	}

	/**
	 * Crea un plan con sus filas.
	 *
	 * @param int                                   $project_id Proyecto.
	 * @param array<string,mixed>                   $data       name, status, submitted_at, notes.
	 * @param array<int,array<string,mixed>>        $rows       Filas: period, transfer, spend, cash, milestone.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data, array $rows ) {
		$errors = new WP_Error();
		$clean  = self::clean( array_merge( array( 'status' => 'borrador' ), $data ), array( 'name' => 'text', 'status' => 'key', 'submitted_at' => 'date', 'notes' => 'textarea' ), $errors );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		if ( ! in_array( $clean['status'], self::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'Estado del plan no válido.', 'gestion-de-proyectos' ) );
		}
		$clean_rows = self::clean_rows( $rows );
		if ( is_wp_error( $clean_rows ) ) {
			return $clean_rows;
		}
		if ( empty( $clean['name'] ) ) {
			$clean['name'] = sprintf( 'Programación de caja %s', current_time( 'Y-m-d' ) );
		}
		$clean['project_id'] = $project_id;
		$id                  = self::insert( $clean, sprintf( 'Programación de caja creada: %s (%d meses)', $clean['name'], count( $clean_rows ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::replace_rows( $id, $clean_rows );
		if ( 'vigente' === $clean['status'] ) {
			self::supersede_others( $project_id, $id );
		}

		return $id;
	}

	/**
	 * Actualiza un plan y, si se indican, sus filas.
	 *
	 * @param int                                 $id               Plan.
	 * @param array<string,mixed>                 $data             Datos.
	 * @param array<int,array<string,mixed>>|null $rows             Filas (null = no cambian).
	 * @param int|null                            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?array $rows = null, ?int $expected_version = null ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El plan no existe.', 'gestion-de-proyectos' ) );
		}
		$errors = new WP_Error();
		$clean  = self::clean( $data, array( 'name' => 'text', 'status' => 'key', 'submitted_at' => 'date', 'notes' => 'textarea' ), $errors );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		if ( isset( $clean['status'] ) && ! in_array( $clean['status'], self::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'Estado del plan no válido.', 'gestion-de-proyectos' ) );
		}
		if ( null !== $rows ) {
			$clean_rows = self::clean_rows( $rows );
			if ( is_wp_error( $clean_rows ) ) {
				return $clean_rows;
			}
			self::replace_rows( $id, $clean_rows );
		}
		$result = self::update_row( $id, $clean, $expected_version, sprintf( 'Programación de caja actualizada: %s', $current['name'] ) );
		if ( ! is_wp_error( $result ) && 'vigente' === ( $clean['status'] ?? '' ) ) {
			self::supersede_others( $current['project_id'], $id );
		}

		return $result;
	}

	/**
	 * Elimina un plan con sus filas.
	 *
	 * @param int $id Plan.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'finance_cash_plan_rows' ), array( 'plan_id' => $id ), array( '%d' ) );

		return self::delete_row( $id, __( 'Programación de caja eliminada', 'gestion-de-proyectos' ) );
	}

	/**
	 * Limpia las filas.
	 *
	 * @param array<int,array<string,mixed>> $rows Filas.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function clean_rows( array $rows ) {
		$out  = array();
		$seen = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$period = trim( (string) ( $r['period'] ?? '' ) );
			if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $period ) ) {
				return new WP_Error( 'period', sprintf( 'Período no válido en la programación: "%s".', $period ) );
			}
			if ( isset( $seen[ $period ] ) ) {
				return new WP_Error( 'period', sprintf( 'El período %s se repite.', $period ) );
			}
			$seen[ $period ] = true;
			// Una celda vacía del formulario vale cero; un texto que no es monto es un error.
			$amount          = static fn( $v ): ?float => '' === trim( (string) $v ) ? 0.0 : self::number( $v );
			$transfer        = $amount( $r['transfer'] ?? 0 );
			$spend           = $amount( $r['spend'] ?? 0 );
			$cash            = $amount( $r['cash'] ?? 0 );
			if ( null === $transfer || null === $spend || null === $cash ) {
				return new WP_Error( 'amount', sprintf( 'Monto no válido en el período %s.', $period ) );
			}
			$out[] = array( 'period' => $period, 'transfer_planned' => round( $transfer, 2 ), 'spend_planned' => round( $spend, 2 ), 'cash_planned' => round( $cash, 2 ), 'milestone' => sanitize_text_field( (string) ( $r['milestone'] ?? '' ) ) );
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['period'], $b['period'] ) );

		return $out;
	}

	/**
	 * Sustituye las filas de un plan.
	 *
	 * @param int                            $plan_id Plan.
	 * @param array<int,array<string,mixed>> $rows    Filas limpias.
	 * @return void
	 */
	private static function replace_rows( int $plan_id, array $rows ): void {
		global $wpdb;

		$table = Schema::table( 'finance_cash_plan_rows' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'plan_id' => $plan_id ), array( '%d' ) );
		foreach ( $rows as $r ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $r + array( 'plan_id' => $plan_id ) );
		}
	}

	/**
	 * Marca como reemplazados los demás planes vigentes.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $keep_id    Plan que se conserva.
	 * @return void
	 */
	private static function supersede_others( int $project_id, int $keep_id ): void {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'reemplazada' WHERE project_id = %d AND id <> %d AND status = 'vigente'", $project_id, $keep_id ) );
	}
}
