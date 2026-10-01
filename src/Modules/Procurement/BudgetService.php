<?php
/**
 * Presupuesto por partida.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Domain\Projects\ProjectRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Monto asignado por partida (tabla gdp_budget_lines) frente a lo
 * comprometido (compras con orden emitida) y lo ejecutado (compras pagadas),
 * en pesos convertidos. Las partidas vienen del catálogo del proyecto.
 */
final class BudgetService {

	/**
	 * Resumen por partida.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{lines:array<int,array<string,mixed>>,totals:array<string,float>,project_budget:float|null}
	 */
	public static function summary( int $project_id ): array {
		$labels   = PurchaseRepository::budget_lines( $project_id );
		$assigned = self::assigned( $project_id );
		$lines    = array();
		foreach ( $labels as $code => $label ) {
			$lines[ $code ] = array( 'code' => $code, 'label' => $label, 'assigned' => (float) ( $assigned[ $code ]['assigned_clp'] ?? 0 ), 'notes' => (string) ( $assigned[ $code ]['notes'] ?? '' ), 'committed' => 0.0, 'executed' => 0.0, 'pending' => 0.0, 'purchases' => 0 );
		}
		foreach ( PurchaseRepository::for_project( $project_id, array( 'limit' => 1000 ) ) as $p ) {
			if ( 'anulada' === $p['status'] ) {
				continue;
			}
			$code = '' !== $p['budget_line'] ? $p['budget_line'] : 'sin_partida';
			if ( ! isset( $lines[ $code ] ) ) {
				$lines[ $code ] = array( 'code' => $code, 'label' => 'sin_partida' === $code ? __( 'Sin partida', 'gestion-de-proyectos' ) : $code, 'assigned' => (float) ( $assigned[ $code ]['assigned_clp'] ?? 0 ), 'notes' => '', 'committed' => 0.0, 'executed' => 0.0, 'pending' => 0.0, 'purchases' => 0 );
			}
			$amount = (float) ( $p['amount_clp'] ?? 0 );
			++$lines[ $code ]['purchases'];
			if ( $p['committed'] ) {
				$lines[ $code ]['committed'] += $amount;
			} elseif ( 'abierta' === $p['status'] ) {
				$lines[ $code ]['pending'] += $amount;
			}
			if ( $p['executed'] ) {
				$lines[ $code ]['executed'] += $amount;
			}
		}
		$totals = array( 'assigned' => 0.0, 'committed' => 0.0, 'executed' => 0.0, 'pending' => 0.0, 'balance' => 0.0 );
		foreach ( $lines as &$line ) {
			$line['balance'] = $line['assigned'] - $line['committed'];
			foreach ( array( 'assigned', 'committed', 'executed', 'pending', 'balance' ) as $k ) {
				$totals[ $k ] += $line[ $k ];
			}
		}
		unset( $line );
		$project = ProjectRepository::find( $project_id );

		return array(
			'lines'          => array_values( $lines ),
			'totals'         => $totals,
			'project_budget' => isset( $project['budget_total'] ) && null !== $project['budget_total'] ? (float) $project['budget_total'] : null,
		);
	}

	/**
	 * Montos asignados por código de partida.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,array<string,mixed>>
	 */
	public static function assigned( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'budget_lines' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY sort_order ASC, id ASC", $project_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[ (string) $r['line_code'] ] = array( 'id' => (int) $r['id'], 'assigned_clp' => (float) $r['assigned_clp'], 'label' => (string) $r['label'], 'notes' => (string) $r['notes'] );
		}

		return $out;
	}

	/**
	 * Fija el monto asignado de una partida.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $code       Partida.
	 * @param float  $amount     Monto en pesos.
	 * @param string $notes      Nota.
	 * @return bool|WP_Error
	 */
	public static function set_assigned( int $project_id, string $code, float $amount, string $notes = '' ) {
		global $wpdb;

		$code = sanitize_key( $code );
		if ( '' === $code || $amount < 0 ) {
			return new WP_Error( 'budget', __( 'Indique una partida y un monto no negativo.', 'gestion-de-proyectos' ) );
		}
		$label  = PurchaseRepository::budget_lines( $project_id )[ $code ] ?? $code;
		$table  = Schema::table( 'budget_lines' );
		$before = self::assigned( $project_id )[ $code ] ?? null;
		$data   = array( 'label' => $label, 'assigned_clp' => round( $amount, 2 ), 'notes' => sanitize_text_field( $notes ), 'updated_at' => current_time( 'mysql', true ) );
		if ( $before ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->update( $table, $data, array( 'id' => $before['id'] ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert( $table, $data + array( 'project_id' => $project_id, 'line_code' => $code ) );
		}
		if ( false === $ok ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la partida.', 'gestion-de-proyectos' ) );
		}
		Audit::log( 'budget_line', 0, 'update', $project_id, sprintf( 'Partida %s: asignado %s', $label, number_format( $amount, 0, ',', '.' ) ), $before, $data );

		return true;
	}

	/**
	 * Elimina las partidas de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'budget_lines' ), array( 'project_id' => $project_id ), array( '%d' ) );
	}
}
