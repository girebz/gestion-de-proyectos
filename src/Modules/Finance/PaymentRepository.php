<?php
/**
 * Pagos: la unidad de rendición.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Finance\Logic\Imputation;
use GDP\Modules\Procurement\PurchaseRepository;
use GDP\Modules\Procurement\SupplierRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Un compromiso (orden, contrato, fondo por rendir) puede tener varios
 * pagos, y un comprobante de egreso puede pagar varios documentos: cada
 * documento es un pago, con fuente, ítem, monto, fechas de ejecución y de
 * egreso, cuota imputada, estado de rendición y respaldos.
 */
final class PaymentRepository extends Repository {

	protected const TABLE  = 'finance_payments';
	protected const ENTITY = 'finance_payment';
	protected const CASTS  = array(
		'seq_no'             => 'int',
		'purchase_id'        => 'int',
		'supplier_id'        => 'int',
		'executed_at'        => 'date',
		'paid_at'            => 'date',
		'egress_document_id' => 'int',
		'doc_date'           => 'date',
		'amount'             => 'float',
		'installment_no'     => 'int',
		'rendition_id'       => 'int',
		'folio'              => 'int',
		'observed_at'        => 'date',
		'support'            => 'json',
		'created_by'         => 'int',
	);

	public const STATUSES   = array( 'comprometido', 'devengado', 'pagado', 'rendido', 'aprobado', 'observado', 'corregido', 'rechazado' );
	public const PAID       = array( 'pagado', 'rendido', 'aprobado', 'observado', 'corregido', 'rechazado' );
	public const RENDERED   = array( 'rendido', 'aprobado', 'observado', 'corregido' );
	public const APPROVED   = array( 'aprobado' );
	public const COMMITTED  = array( 'comprometido', 'devengado' );
	public const SOURCES    = array( 'fondo', 'pecuniario' );
	public const DOC_TYPES  = array( 'factura', 'factura_exenta', 'boleta', 'boleta_honorarios', 'documento_extranjero', 'otro' );

	/**
	 * Pagos de un proyecto con filtros.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $filters    source, status, statuses (lista), item_slug, period (AAAA-MM del egreso), rendition_id, purchase_id, supplier_id, search, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( int $project_id, array $filters = array() ): array {
		global $wpdb;

		$where = array();
		$args  = array();
		foreach ( array( 'source', 'status', 'item_slug' ) as $f ) {
			if ( ! empty( $filters[ $f ] ) ) {
				$where[] = "{$f} = %s";
				$args[]  = (string) $filters[ $f ];
			}
		}
		if ( ! empty( $filters['statuses'] ) && is_array( $filters['statuses'] ) ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $filters['statuses'] ), '%s' ) ) . ')';
			foreach ( $filters['statuses'] as $s ) {
				$args[] = (string) $s;
			}
		}
		foreach ( array( 'rendition_id', 'purchase_id', 'supplier_id' ) as $f ) {
			if ( isset( $filters[ $f ] ) && (int) $filters[ $f ] > 0 ) {
				$where[] = "{$f} = %d";
				$args[]  = (int) $filters[ $f ];
			}
		}
		if ( ! empty( $filters['period'] ) ) {
			$where[] = 'paid_at LIKE %s';
			$args[]  = $wpdb->esc_like( substr( (string) $filters['period'], 0, 7 ) ) . '%';
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(code LIKE %s OR description LIKE %s OR doc_number LIKE %s OR egress_number LIKE %s OR commitment LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}

		return self::for_project( $project_id, 'COALESCE(paid_at, doc_date, executed_at) ASC, id ASC', $where, $args, (int) ( $filters['limit'] ?? 5000 ) );
	}

	/**
	 * Pagos pagados con cargo a una fuente, en orden cronológico.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $source     Fuente.
	 * @return array<int,array<string,mixed>>
	 */
	public static function paid( int $project_id, string $source = 'fondo' ): array {
		return self::list( $project_id, array( 'source' => $source, 'statuses' => self::PAID ) );
	}

	/**
	 * Valida los datos.
	 *
	 * @param array<string,mixed> $data       Datos.
	 * @param int                 $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $project_id ) {
		$errors = new WP_Error();
		$clean  = self::clean(
			$data,
			array(
				'code'               => 'text',
				'purchase_id'        => 'int',
				'supplier_id'        => 'int',
				'source'             => 'key',
				'item_slug'          => 'key',
				'description'        => 'text',
				'commitment'         => 'text',
				'executed_at'        => 'date',
				'paid_at'            => 'date',
				'egress_number'      => 'text',
				'egress_document_id' => 'int',
				'doc_type'           => 'key',
				'doc_number'         => 'text',
				'doc_date'           => 'date',
				'amount'             => 'money',
				'installment_no'     => 'int',
				'status'             => 'key',
				'rendition_id'       => 'int',
				'folio'              => 'int',
				'observation'        => 'textarea',
				'observed_at'        => 'date',
				'support'            => 'json',
				'notes'              => 'textarea',
			),
			$errors
		);
		if ( isset( $clean['source'] ) && ! in_array( $clean['source'], self::SOURCES, true ) ) {
			$errors->add( 'source', __( 'La fuente debe ser fondo o pecuniario.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['status'] ) && ! in_array( $clean['status'], self::STATUSES, true ) ) {
			$errors->add( 'status', __( 'Estado del pago no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['doc_type'] ) && ! in_array( $clean['doc_type'], self::DOC_TYPES, true ) ) {
			$errors->add( 'doc_type', __( 'Tipo de documento no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['amount'] ) && $clean['amount'] < 0 ) {
			$errors->add( 'amount', __( 'El monto no puede ser negativo.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['item_slug'] ) && '' !== $clean['item_slug'] && ! isset( ItemRepository::by_slug( $project_id )[ $clean['item_slug'] ] ) ) {
			$errors->add( 'item_slug', __( 'El ítem no existe en el convenio del proyecto.', 'gestion-de-proyectos' ) );
		}
		if ( ! empty( $clean['purchase_id'] ) ) {
			$purchase = PurchaseRepository::find( (int) $clean['purchase_id'] );
			if ( ! $purchase || $purchase['project_id'] !== $project_id ) {
				$errors->add( 'purchase_id', __( 'La compra no existe en este proyecto.', 'gestion-de-proyectos' ) );
			}
		}
		if ( ! empty( $clean['supplier_id'] ) ) {
			$supplier = SupplierRepository::find( (int) $clean['supplier_id'] );
			if ( ! $supplier || ( $supplier['project_id'] > 0 && $supplier['project_id'] !== $project_id ) ) {
				$errors->add( 'supplier_id', __( 'El proveedor no existe o pertenece a otro proyecto.', 'gestion-de-proyectos' ) );
			}
		}
		if ( isset( $clean['support'] ) ) {
			$support = array();
			foreach ( $clean['support'] as $s ) {
				if ( is_string( $s ) ) {
					$s = array( 'kind' => $s );
				}
				if ( ! is_array( $s ) || empty( $s['kind'] ) ) {
					continue;
				}
				$support[] = array( 'kind' => sanitize_key( (string) $s['kind'] ), 'document_id' => (int) ( $s['document_id'] ?? 0 ), 'note' => sanitize_text_field( (string) ( $s['note'] ?? '' ) ) );
			}
			$clean['support'] = $support;
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea un pago.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		$clean = self::validate( array_merge( array( 'source' => 'fondo', 'status' => 'comprometido', 'doc_type' => 'factura', 'support' => array() ), $data ), $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['description'] ) ) {
			return new WP_Error( 'required', __( 'Indique la descripción del pago.', 'gestion-de-proyectos' ) );
		}
		if ( ! empty( $clean['paid_at'] ) && in_array( $clean['status'], self::COMMITTED, true ) ) {
			$clean['status'] = 'pagado';
		}
		if ( empty( $clean['code'] ) ) {
			$next            = self::next_code( $project_id );
			$clean['seq_no'] = $next['sequence'];
			$clean['code']   = $next['number'];
		}
		$clean['project_id'] = $project_id;

		return self::insert( $clean, sprintf( 'Pago registrado: %s %s (%s)', $clean['code'], $clean['description'], number_format( (float) ( $clean['amount'] ?? 0 ), 0, ',', '.' ) ) );
	}

	/**
	 * Actualiza un pago.
	 *
	 * @param int                 $id               Pago.
	 * @param array<string,mixed> $data             Datos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El pago no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( $data, $current['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( ! empty( $clean['paid_at'] ) && in_array( $clean['status'] ?? $current['status'], self::COMMITTED, true ) ) {
			$clean['status'] = 'pagado';
		}

		return self::update_row( $id, $clean, $expected_version, sprintf( 'Pago actualizado: %s', $current['code'] ) );
	}

	/**
	 * Cambia el estado de un pago y registra el evento.
	 *
	 * @param int    $id          Pago.
	 * @param string $status      Estado.
	 * @param string $date        Fecha del evento.
	 * @param string $note        Nota (motivo de la observación).
	 * @param int    $document_id Documento de respaldo del evento.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_status( int $id, string $status, string $date, string $note = '', int $document_id = 0 ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El pago no existe.', 'gestion-de-proyectos' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'Estado del pago no válido.', 'gestion-de-proyectos' ) );
		}
		$data = array( 'status' => $status );
		if ( 'pagado' === $status && empty( $current['paid_at'] ) ) {
			$data['paid_at'] = $date;
		}
		if ( 'observado' === $status ) {
			$data['observation'] = $note;
			$data['observed_at'] = $date;
		}
		$result = self::update_row( $id, $data, null, sprintf( 'Pago %s: %s', $current['code'], $status ) );
		if ( ! is_wp_error( $result ) ) {
			EventRepository::log( $current['project_id'], 'payment', $id, 'estado', $status, $date, $note, $document_id );
		}

		return $result;
	}

	/**
	 * Elimina un pago.
	 *
	 * @param int $id Pago.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'El pago no existe.', 'gestion-de-proyectos' ) );
		}
		if ( in_array( $current['status'], array( 'rendido', 'aprobado', 'observado', 'corregido' ), true ) ) {
			return new WP_Error( 'rendered', __( 'Un pago rendido no se elimina: la plataforma solo admite corregirlo o solicitar su eliminación.', 'gestion-de-proyectos' ) );
		}

		return self::delete_row( $id, sprintf( 'Pago eliminado: %s %s', $current['code'], $current['description'] ) );
	}

	/**
	 * Siguiente código correlativo.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{sequence:int,number:string}
	 */
	public static function next_code( int $project_id ): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(seq_no) FROM {$table} WHERE project_id = %d", $project_id ) );

		return array( 'sequence' => $max + 1, 'number' => sprintf( 'PG-%04d', $max + 1 ) );
	}

	/**
	 * Cuota efectiva de cada pago pagado con cargo al Fondo: la declarada o, si
	 * es cero, la cronológica.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,int> pago => número de cuota.
	 */
	public static function effective_installments( int $project_id ): array {
		$amounts = InstallmentRepository::amounts( $project_id );
		$out     = array();
		$before  = 0.0;
		foreach ( self::paid( $project_id, 'fondo' ) as $p ) {
			$out[ $p['id'] ] = $p['installment_no'] > 0 ? $p['installment_no'] : Imputation::installment_for( $before, $amounts );
			$before         += (float) $p['amount'];
		}

		return $out;
	}

	/**
	 * Totales por estado agregado y por fuente.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,array<string,float>> fuente => [paid, rendered, approved, observed, rejected, committed, accrued].
	 */
	public static function totals( int $project_id ): array {
		$out = array();
		foreach ( self::SOURCES as $s ) {
			$out[ $s ] = array( 'paid' => 0.0, 'rendered' => 0.0, 'approved' => 0.0, 'observed' => 0.0, 'rejected' => 0.0, 'committed' => 0.0, 'accrued' => 0.0, 'count' => 0.0 );
		}
		foreach ( self::list( $project_id ) as $p ) {
			$s = in_array( $p['source'], self::SOURCES, true ) ? $p['source'] : 'fondo';
			$a = (float) $p['amount'];
			++$out[ $s ]['count'];
			if ( in_array( $p['status'], self::PAID, true ) ) {
				$out[ $s ]['paid'] += $a;
			}
			if ( in_array( $p['status'], self::RENDERED, true ) ) {
				$out[ $s ]['rendered'] += $a;
			}
			if ( 'aprobado' === $p['status'] ) {
				$out[ $s ]['approved'] += $a;
			}
			if ( in_array( $p['status'], array( 'observado', 'corregido' ), true ) ) {
				$out[ $s ]['observed'] += $a;
			}
			if ( 'rechazado' === $p['status'] ) {
				$out[ $s ]['rejected'] += $a;
			}
			if ( in_array( $p['status'], self::COMMITTED, true ) ) {
				$out[ $s ]['committed'] += $a;
			}
			if ( 'devengado' === $p['status'] ) {
				$out[ $s ]['accrued'] += $a;
			}
		}
		foreach ( $out as &$row ) {
			foreach ( $row as &$v ) {
				$v = round( $v, 2 );
			}
		}

		return $out;
	}

	/**
	 * Totales por ítem y fuente.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,array<string,array<string,float>>> ítem => fuente => [paid, committed, rejected, rendered].
	 */
	public static function totals_by_item( int $project_id ): array {
		$out = array();
		foreach ( self::list( $project_id ) as $p ) {
			$slug = '' !== $p['item_slug'] ? (string) $p['item_slug'] : 'sin_item';
			$s    = in_array( $p['source'], self::SOURCES, true ) ? $p['source'] : 'fondo';
			if ( ! isset( $out[ $slug ][ $s ] ) ) {
				$out[ $slug ][ $s ] = array( 'paid' => 0.0, 'committed' => 0.0, 'rejected' => 0.0, 'rendered' => 0.0 );
			}
			$a = (float) $p['amount'];
			if ( in_array( $p['status'], self::PAID, true ) ) {
				$out[ $slug ][ $s ]['paid'] += $a;
			}
			if ( in_array( $p['status'], self::COMMITTED, true ) ) {
				$out[ $slug ][ $s ]['committed'] += $a;
			}
			if ( 'rechazado' === $p['status'] ) {
				$out[ $slug ][ $s ]['rejected'] += $a;
			}
			if ( in_array( $p['status'], self::RENDERED, true ) ) {
				$out[ $slug ][ $s ]['rendered'] += $a;
			}
		}

		return $out;
	}

	/**
	 * Pagado por mes del egreso y fuente.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $source     Fuente.
	 * @return array<string,float> AAAA-MM => monto.
	 */
	public static function paid_by_period( int $project_id, string $source = 'fondo' ): array {
		$out = array();
		foreach ( self::paid( $project_id, $source ) as $p ) {
			if ( empty( $p['paid_at'] ) ) {
				continue;
			}
			$period         = substr( (string) $p['paid_at'], 0, 7 );
			$out[ $period ] = round( (float) ( $out[ $period ] ?? 0 ) + (float) $p['amount'], 2 );
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Resumen breve de un pago.
	 *
	 * @param array<string,mixed> $p Pago.
	 * @return array<string,mixed>
	 */
	public static function brief( array $p ): array {
		return array_intersect_key( $p, array_flip( array( 'id', 'code', 'purchase_id', 'supplier_id', 'source', 'item_slug', 'description', 'commitment', 'executed_at', 'paid_at', 'egress_number', 'egress_document_id', 'doc_type', 'doc_number', 'doc_date', 'amount', 'installment_no', 'status', 'rendition_id', 'folio', 'observation', 'observed_at', 'support', 'version' ) ) );
	}
}
