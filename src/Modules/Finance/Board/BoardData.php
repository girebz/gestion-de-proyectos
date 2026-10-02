<?php
/**
 * Datos del tablero de finanzas del sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

use GDP\Modules\Documents\DocumentRepository;
use GDP\Modules\Finance\Assistant;
use GDP\Modules\Finance\CashPlanRepository;
use GDP\Modules\Finance\EventRepository;
use GDP\Modules\Finance\FinanceService;
use GDP\Modules\Finance\GuaranteeRepository;
use GDP\Modules\Finance\ItemRepository;
use GDP\Modules\Finance\Logic\BoardMetrics;
use GDP\Modules\Finance\ModificationRepository;
use GDP\Modules\Finance\PaymentRepository;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Modules\Finance\RenditionRepository;
use GDP\Modules\Procurement\PurchaseRepository;
use GDP\Modules\Procurement\SupplierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reúne una sola vez, para una petición, todo lo que muestran las pestañas:
 * el estado de cuentas, los pagos con su proveedor, compra y documentos de
 * respaldo, las rendiciones, las modificaciones y garantías, la curva de
 * caja, las celdas de la línea de tiempo de rendiciones, las alertas y las
 * acciones del asistente.
 */
final class BoardData {

	/**
	 * Arma los datos del tablero.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return array<string,mixed>
	 */
	public static function build( array $project ): array {
		$project_id = (int) $project['id'];
		$status     = FinanceService::status( $project_id );
		$profile    = Profiles::get( (string) $status['profile'] );
		$payments   = PaymentRepository::list( $project_id );
		$effective  = PaymentRepository::effective_installments( $project_id );
		$today      = (string) $status['today'];
		$current    = substr( $today, 0, 7 );

		$suppliers = array();
		$purchases = array();
		$documents = array();
		$paid      = array(
			'fondo'      => array(),
			'pecuniario' => array(),
		);
		$by_status = array();
		foreach ( $payments as $p ) {
			$sid = (int) $p['supplier_id'];
			if ( $sid > 0 && ! array_key_exists( $sid, $suppliers ) ) {
				$suppliers[ $sid ] = SupplierRepository::find( $sid );
			}
			$pid = (int) $p['purchase_id'];
			if ( $pid > 0 && ! array_key_exists( $pid, $purchases ) ) {
				$purchases[ $pid ] = PurchaseRepository::find( $pid );
			}
			foreach ( (array) $p['support'] as $s ) {
				$did = (int) ( $s['document_id'] ?? 0 );
				if ( $did > 0 && ! array_key_exists( $did, $documents ) ) {
					$documents[ $did ] = DocumentRepository::find( $did );
				}
			}
			$source = in_array( $p['source'], PaymentRepository::SOURCES, true ) ? (string) $p['source'] : 'fondo';
			if ( in_array( $p['status'], PaymentRepository::PAID, true ) ) {
				$date = (string) ( $p['paid_at'] ?? '' );
				$date = '' !== $date ? $date : (string) ( $p['doc_date'] ?? '' );
				$date = '' !== $date ? $date : (string) ( $p['executed_at'] ?? '' );
				if ( '' !== $date ) {
					$month                     = substr( $date, 0, 7 );
					$paid[ $source ][ $month ] = (float) ( $paid[ $source ][ $month ] ?? 0 ) + (float) $p['amount'];
				}
			}
			$key = $source . ':' . (string) $p['status'];
			if ( ! isset( $by_status[ $key ] ) ) {
				$by_status[ $key ] = array(
					'source' => $source,
					'status' => (string) $p['status'],
					'count'  => 0,
					'amount' => 0.0,
				);
			}
			++$by_status[ $key ]['count'];
			$by_status[ $key ]['amount'] += (float) $p['amount'];
		}
		ksort( $paid['fondo'] );

		$received = array();
		foreach ( (array) $status['installments'] as $i ) {
			if ( ! empty( $i['is_received'] ) ) {
				$on                 = (string) ( $i['received_on'] ?? '' );
				$month              = '' !== $on ? substr( $on, 0, 7 ) : $current;
				$received[ $month ] = (float) ( $received[ $month ] ?? 0 ) + (float) $i['amount'];
			}
		}

		$agreement  = (array) $status['agreement'];
		$plan_rows  = is_array( $status['cash_plan'] ) ? (array) $status['cash_plan']['rows'] : array();
		$candidates = array( substr( (string) ( $agreement['start_date'] ?? '' ), 0, 7 ), $current );
		$ends       = array( substr( (string) ( $agreement['end_date'] ?? '' ), 0, 7 ), $current );
		foreach ( $plan_rows as $r ) {
			$candidates[] = (string) $r['period'];
			$ends[]       = (string) $r['period'];
		}
		foreach ( array_keys( $paid['fondo'] ) as $m ) {
			$candidates[] = (string) $m;
		}
		$candidates = array_filter( $candidates, static fn( string $m ): bool => 1 === preg_match( '/^\d{4}-\d{2}$/', $m ) );
		$ends       = array_filter( $ends, static fn( string $m ): bool => 1 === preg_match( '/^\d{4}-\d{2}$/', $m ) );
		$months     = $candidates && $ends ? BoardMetrics::months( min( $candidates ), max( $ends ) ) : array();
		$curve      = BoardMetrics::cash_curve( $months, $paid['fondo'], $received, $plan_rows, $current );

		$labels = array();
		foreach ( $profile->rendition_statuses() as $slug => $s ) {
			$labels[ $slug ] = (string) $s['label'];
		}
		$guarantees = GuaranteeRepository::all( $project_id );

		return array(
			'project'       => $project,
			'project_id'    => $project_id,
			'status'        => $status,
			'profile'       => $profile,
			'today'         => $today,
			'current'       => $current,
			'payments'      => $payments,
			'effective'     => $effective,
			'suppliers'     => $suppliers,
			'purchases'     => $purchases,
			'documents'     => $documents,
			'items_labels'  => ItemRepository::labels( $project_id ),
			'items_rows'    => ItemRepository::by_slug( $project_id ),
			'by_status'     => array_values( $by_status ),
			'paid_by_month' => $paid,
			'received'      => $received,
			'curve'         => $curve,
			'renditions'    => RenditionRepository::all( $project_id ),
			'cells'         => BoardMetrics::rendition_cells( (array) $status['renditions'], $labels ),
			'modifications' => ModificationRepository::all( $project_id ),
			'guarantees'    => $guarantees,
			'plans'         => CashPlanRepository::all( $project_id ),
			'alerts'        => BoardMetrics::alerts( $status, $guarantees ),
			'actions'       => array_map(
				static function ( array $a ): array {
					$a['title']  = BoardMetrics::display_text( (string) $a['title'] );
					$a['detail'] = BoardMetrics::display_text( (string) $a['detail'] );
					return $a;
				},
				Assistant::actions( $project_id, $status )
			),
			'elapsed'       => BoardMetrics::elapsed( (string) ( $agreement['start_date'] ?? '' ), (string) ( $agreement['end_date'] ?? '' ), $today ),
			'usage'         => array(
				'fondo'      => BoardMetrics::usage( (float) $status['sources']['fondo']['total'], (float) $status['sources']['fondo']['paid'], (float) $status['sources']['fondo']['committed'], (float) $status['sources']['fondo']['received'] ),
				'pecuniario' => BoardMetrics::usage( (float) $status['sources']['pecuniario']['total'], (float) $status['sources']['pecuniario']['paid'], (float) $status['sources']['pecuniario']['committed'], (float) $status['sources']['pecuniario']['received'] ),
			),
		);
	}

	/**
	 * Eventos declarados de una rendición (para el detalle de la pestaña Rendiciones).
	 *
	 * @param int $rendition_id Rendición.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rendition_events( int $rendition_id ): array {
		return EventRepository::for_entity( 'rendition', $rendition_id );
	}

	/**
	 * Nombre de un proveedor ('' si no hay).
	 *
	 * @param array<string,mixed> $data       Datos del tablero.
	 * @param int                 $supplier_id Proveedor.
	 * @return string
	 */
	public static function supplier_name( array $data, int $supplier_id ): string {
		$s = $data['suppliers'][ $supplier_id ] ?? null;

		return is_array( $s ) ? (string) $s['name'] : '';
	}

	/**
	 * Etiqueta de un documento de respaldo ("número asunto" o "#id").
	 *
	 * @param array<string,mixed> $data        Datos del tablero.
	 * @param int                 $document_id Documento.
	 * @return string
	 */
	public static function document_label( array $data, int $document_id ): string {
		$d = $data['documents'][ $document_id ] ?? null;
		if ( ! is_array( $d ) ) {
			return '#' . $document_id;
		}
		$label = trim( (string) $d['number'] );

		return '' !== $label ? $label : wp_trim_words( (string) $d['subject'], 8 );
	}
}
