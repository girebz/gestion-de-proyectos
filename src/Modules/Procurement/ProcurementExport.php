<?php
/**
 * Exportación de compras para la rendición.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Spreadsheet;
use GDP\Modules\Planning\ScheduleService;

defined( 'ABSPATH' ) || exit;

/**
 * Planilla de rendición: una fila por compra con partida, proveedor,
 * documentos (orden, factura), fechas, montos netos, impuesto y total en
 * pesos, moneda de origen y valor de la unidad de fomento usado, más una hoja
 * de resumen por partida. Se entrega en CSV o XLSX.
 */
final class ProcurementExport {

	public const HEADER = array( 'codigo', 'titulo', 'partida', 'proveedor', 'rut_proveedor', 'etapa', 'estado', 'moneda', 'neto_origen', 'impuesto_pct', 'total_origen', 'valor_uf', 'fecha_uf', 'total_clp', 'numero_orden', 'fecha_orden', 'numero_factura', 'fecha_factura', 'fecha_pago', 'aprobada_el', 'responsable', 'actividad', 'cotizacion_elegida', 'notas' );

	/**
	 * Filas de la rendición.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<int,mixed>> Cabecera incluida.
	 */
	public static function rows( int $project_id ): array {
		$stages = PurchaseRepository::stages( $project_id );
		$lines  = PurchaseRepository::budget_lines( $project_id );
		$rows   = array( self::HEADER );
		foreach ( PurchaseRepository::for_project( $project_id, array( 'limit' => 1000 ) ) as $p ) {
			$supplier = $p['supplier_id'] > 0 ? SupplierRepository::find( $p['supplier_id'] ) : null;
			$quote    = $p['chosen_quote_id'] > 0 ? QuoteRepository::find( $p['chosen_quote_id'] ) : null;
			$activity = $p['activity_id'] > 0 ? \GDP\Modules\Planning\ActivityRepository::find( $p['activity_id'] ) : null;
			$rows[]   = array(
				$p['code'],
				$p['title'],
				$lines[ $p['budget_line'] ] ?? $p['budget_line'],
				$supplier ? $supplier['name'] : '',
				$supplier ? $supplier['tax_id'] : '',
				$stages[ $p['stage'] ] ?? $p['stage'],
				PurchaseRepository::status_labels()[ $p['status'] ] ?? $p['status'],
				$p['currency'],
				null === $p['amount_net'] ? '' : (float) $p['amount_net'],
				(float) $p['tax_rate'],
				null === $p['amount_total'] ? '' : (float) $p['amount_total'],
				null === $p['uf_rate'] ? '' : (float) $p['uf_rate'],
				(string) $p['uf_date'],
				null === $p['amount_clp'] ? '' : (float) $p['amount_clp'],
				$p['order_number'],
				(string) $p['order_date'],
				$p['invoice_number'],
				(string) $p['invoice_date'],
				(string) $p['paid_at'],
				$p['approved_at'] ? substr( (string) $p['approved_at'], 0, 10 ) : '',
				ScheduleService::user_name( $p['owner_id'] ),
				$activity ? $activity['code'] . ' ' . $activity['name'] : '',
				$quote ? trim( $quote['quote_number'] . ' ' . $quote['supplier'] ) : '',
				wp_strip_all_tags( $p['notes'] ),
			);
		}

		return $rows;
	}

	/**
	 * CSV con separador punto y coma.
	 *
	 * @param int $project_id Proyecto.
	 * @return string
	 */
	public static function csv( int $project_id ): string {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		foreach ( self::rows( $project_id ) as $row ) {
			fputcsv( $handle, array_map( static fn( $v ) => is_float( $v ) ? str_replace( '.', ',', (string) $v ) : (string) $v, $row ), ';', '"', '\\' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Libro XLSX con las hojas Rendición y Presupuesto.
	 *
	 * @param int $project_id Proyecto.
	 * @return string|\WP_Error
	 */
	public static function xlsx( int $project_id ) {
		$budget = array( array( 'partida', 'etiqueta', 'asignado_clp', 'comprometido_clp', 'ejecutado_clp', 'pendiente_clp', 'saldo_clp', 'compras' ) );
		foreach ( BudgetService::summary( $project_id )['lines'] as $l ) {
			$budget[] = array( $l['code'], $l['label'], (float) $l['assigned'], (float) $l['committed'], (float) $l['executed'], (float) $l['pending'], (float) $l['balance'], (int) $l['purchases'] );
		}

		return Spreadsheet::write( array( 'Rendición' => self::rows( $project_id ), 'Presupuesto' => $budget ) );
	}
}
