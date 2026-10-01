<?php
/**
 * Repositorio de cotizaciones e ítems.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Audit;
use GDP\Core\Schema;
use GDP\Modules\Planning\ActivityRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cotizaciones de una compra (solicitada, recibida, elegida, descartada) con
 * sus ítems, para compararlas por ítem y elegir la que se contrata; los ítems
 * marcados como seleccionados forman el subconjunto contratado.
 */
final class QuoteRepository {

	public const STATUSES = array( 'solicitada', 'recibida', 'elegida', 'descartada' );

	/**
	 * Etiquetas de estado.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'solicitada' => __( 'Solicitada', 'gestion-de-proyectos' ),
			'recibida'   => __( 'Recibida', 'gestion-de-proyectos' ),
			'elegida'    => __( 'Elegida', 'gestion-de-proyectos' ),
			'descartada' => __( 'Descartada', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Una cotización con sus ítems.
	 *
	 * @param int $id Cotización.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'quotes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Cotizaciones de una compra.
	 *
	 * @param int $purchase_id Compra.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_purchase( int $purchase_id ): array {
		global $wpdb;

		$table = Schema::table( 'quotes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE purchase_id = %d ORDER BY id ASC", $purchase_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Cotizaciones solicitadas sin respuesta del proveedor por más días de los configurados.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending_requests( int $project_id ): array {
		global $wpdb;

		$table = Schema::table( 'quotes' );
		$days  = (int) ProcurementSettings::get( $project_id, 'request_days' );
		$limit = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $days . ' days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND status = 'solicitada' AND requested_at IS NOT NULL AND requested_at <= %s ORDER BY requested_at ASC", $project_id, $limit ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Valida y normaliza.
	 *
	 * @param array<string,mixed> $data       Datos.
	 * @param int                 $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $project_id ) {
		$clean  = array();
		$errors = new WP_Error();
		if ( array_key_exists( 'supplier_id', $data ) ) {
			$supplier_id = (int) $data['supplier_id'];
			if ( $supplier_id > 0 ) {
				$supplier = SupplierRepository::find( $supplier_id );
				if ( ! $supplier || ( $supplier['project_id'] > 0 && $supplier['project_id'] !== $project_id ) ) {
					$errors->add( 'supplier_id', __( 'El proveedor no existe o pertenece a otro proyecto.', 'gestion-de-proyectos' ) );
				}
			}
			$clean['supplier_id'] = max( 0, $supplier_id );
		}
		if ( array_key_exists( 'quote_number', $data ) ) {
			$clean['quote_number'] = sanitize_text_field( (string) $data['quote_number'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				$errors->add( 'status', __( 'Estado de cotización no válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['status'] = $status;
			}
		}
		foreach ( array( 'requested_at', 'quote_date', 'valid_until' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$date = ActivityRepository::normalize_date( $data[ $field ] );
				if ( false === $date ) {
					$errors->add( $field, __( 'Fecha no válida (use AAAA-MM-DD).', 'gestion-de-proyectos' ) );
				} else {
					$clean[ $field ] = $date;
				}
			}
		}
		if ( array_key_exists( 'currency', $data ) ) {
			$currency = strtoupper( sanitize_text_field( (string) $data['currency'] ) );
			if ( ! in_array( $currency, PurchaseRepository::CURRENCIES, true ) ) {
				$errors->add( 'currency', __( 'Moneda no admitida (CLP, UF o USD).', 'gestion-de-proyectos' ) );
			} else {
				$clean['currency'] = $currency;
			}
		}
		foreach ( array( 'amount_net', 'tax_rate' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				if ( null === $data[ $field ] || '' === $data[ $field ] ) {
					$clean[ $field ] = 'tax_rate' === $field ? 19.0 : null;
					continue;
				}
				$value = PurchaseRepository::number( $data[ $field ] );
				if ( null === $value || $value < 0 ) {
					$errors->add( $field, __( 'Monto no válido.', 'gestion-de-proyectos' ) );
				} else {
					$clean[ $field ] = $value;
				}
			}
		}
		if ( array_key_exists( 'document_id', $data ) ) {
			$clean['document_id'] = max( 0, (int) $data['document_id'] );
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$clean['notes'] = wp_kses_post( (string) $data['notes'] );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una cotización, opcionalmente con ítems.
	 *
	 * @param array<string,mixed>            $purchase Compra.
	 * @param array<string,mixed>            $data     Datos.
	 * @param array<int,array<string,mixed>> $items    Ítems (description, quantity, unit, unit_price, selected).
	 * @return int|WP_Error
	 */
	public static function create( array $purchase, array $data, array $items = array() ) {
		global $wpdb;

		$data  = array_merge( array( 'status' => 'solicitada', 'currency' => $purchase['currency'] ?? 'CLP', 'tax_rate' => 19.0, 'requested_at' => current_time( 'Y-m-d' ) ), $data );
		$clean = self::validate( $data, (int) $purchase['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$clean = self::with_total( $clean );
		$now   = current_time( 'mysql', true );
		$clean['project_id']  = (int) $purchase['project_id'];
		$clean['purchase_id'] = (int) $purchase['id'];
		$clean['created_by']  = get_current_user_id();
		$clean['created_at']  = $now;
		$clean['updated_at']  = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'quotes' ), $clean ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la cotización.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! empty( $items ) ) {
			self::set_items( $id, $items );
		}
		Audit::log( 'quote', $id, 'create', (int) $purchase['project_id'], sprintf( 'Cotización registrada en %s', $purchase['code'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza una cotización.
	 *
	 * @param int                 $id   Cotización.
	 * @param array<string,mixed> $data Campos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La cotización no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( $data, $current['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean ) ) {
			return $current;
		}
		if ( isset( $clean['status'] ) && 'recibida' === $clean['status'] && empty( $current['quote_date'] ) && empty( $clean['quote_date'] ) ) {
			$clean['quote_date'] = current_time( 'Y-m-d' );
		}
		$clean = self::with_total( array_merge( array_intersect_key( $current, array_flip( array( 'amount_net', 'tax_rate' ) ) ), $clean ) );
		$clean['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( Schema::table( 'quotes' ), $clean, array( 'id' => $id ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar la cotización.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = self::find( $id );
		Audit::log( 'quote', $id, 'update', $current['project_id'], sprintf( 'Cotización actualizada (%s)', $updated['quote_number'] ? $updated['quote_number'] : '#' . $id ), $current, $updated );

		return $updated;
	}

	/**
	 * Reemplaza los ítems de una cotización y recalcula el neto a partir de ellos.
	 *
	 * @param int                            $id    Cotización.
	 * @param array<int,array<string,mixed>> $items Ítems.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_items( int $id, array $items ) {
		global $wpdb;

		$quote = self::find( $id );
		if ( ! $quote ) {
			return new WP_Error( 'not_found', __( 'La cotización no existe.', 'gestion-de-proyectos' ) );
		}
		$table = Schema::table( 'quote_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'quote_id' => $id ) );
		$net = 0.0;
		$n   = 0;
		foreach ( $items as $item ) {
			$description = sanitize_text_field( (string) ( $item['description'] ?? '' ) );
			if ( '' === $description ) {
				continue;
			}
			$quantity   = PurchaseRepository::number( $item['quantity'] ?? 1 ) ?? 1.0;
			$unit_price = PurchaseRepository::number( $item['unit_price'] ?? 0 ) ?? 0.0;
			$total      = round( $quantity * $unit_price, 4 );
			$selected   = ! isset( $item['selected'] ) || ! empty( $item['selected'] ) ? 1 : 0;
			if ( $selected ) {
				$net += $total;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'quote_id'    => $id,
					'sort_order'  => $n++,
					'description' => $description,
					'quantity'    => $quantity,
					'unit'        => sanitize_text_field( (string) ( $item['unit'] ?? '' ) ),
					'unit_price'  => $unit_price,
					'line_total'  => $total,
					'selected'    => $selected,
				)
			);
		}
		if ( $n > 0 ) {
			$data = self::with_total( array( 'amount_net' => round( $net, 4 ), 'tax_rate' => $quote['tax_rate'] ) );
			$data['updated_at'] = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Schema::table( 'quotes' ), $data, array( 'id' => $id ) );
		}

		return self::find( $id );
	}

	/**
	 * Ítems de una cotización.
	 *
	 * @param int $quote_id Cotización.
	 * @return array<int,array<string,mixed>>
	 */
	public static function items( int $quote_id ): array {
		global $wpdb;

		$table = Schema::table( 'quote_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE quote_id = %d ORDER BY sort_order ASC, id ASC", $quote_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array(
				'id'          => (int) $r['id'],
				'description' => (string) $r['description'],
				'quantity'    => (float) $r['quantity'],
				'unit'        => (string) $r['unit'],
				'unit_price'  => (float) $r['unit_price'],
				'line_total'  => (float) $r['line_total'],
				'selected'    => (bool) $r['selected'],
			);
		}

		return $out;
	}

	/**
	 * Elimina una cotización con sus ítems.
	 *
	 * @param int $id Cotización.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La cotización no existe.', 'gestion-de-proyectos' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'quote_items' ), array( 'quote_id' => $id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'quotes' ), array( 'id' => $id ) );
		Audit::log( 'quote', $id, 'delete', $current['project_id'], sprintf( 'Cotización eliminada (%s)', $current['quote_number'] ? $current['quote_number'] : '#' . $id ), $current, null );

		return true;
	}

	/**
	 * Elimina las cotizaciones de una compra.
	 *
	 * @param int $purchase_id Compra.
	 * @return void
	 */
	public static function delete_for_purchase( int $purchase_id ): void {
		global $wpdb;

		foreach ( self::for_purchase( $purchase_id ) as $q ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Schema::table( 'quote_items' ), array( 'quote_id' => $q['id'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'quotes' ), array( 'purchase_id' => $purchase_id ) );
	}

	/**
	 * Instantánea de las cotizaciones de una compra (con ítems).
	 *
	 * @param int $purchase_id Compra.
	 * @return array<int,array<string,mixed>>
	 */
	public static function snapshot_for_purchase( int $purchase_id ): array {
		global $wpdb;

		$out   = array();
		$items = Schema::table( 'quote_items' );
		foreach ( self::for_purchase( $purchase_id ) as $q ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$q['item_rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items} WHERE quote_id = %d ORDER BY sort_order ASC", $q['id'] ), ARRAY_A );
			$out[]          = $q;
		}

		return $out;
	}

	/**
	 * Reinserta cotizaciones e ítems.
	 *
	 * @param array<int,array<string,mixed>> $rows Instantánea.
	 * @return void
	 */
	public static function restore( array $rows ): void {
		global $wpdb;

		foreach ( $rows as $q ) {
			$q   = (array) $q;
			$row = array_intersect_key( $q, array_flip( array( 'id', 'project_id', 'purchase_id', 'supplier_id', 'quote_number', 'status', 'requested_at', 'quote_date', 'valid_until', 'currency', 'amount_net', 'tax_rate', 'amount_total', 'document_id', 'notes', 'created_by', 'created_at', 'updated_at' ) ) );
			if ( ! empty( $row['id'] ) && self::find( (int) $row['id'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( Schema::table( 'quotes' ), $row );
			foreach ( (array) ( $q['item_rows'] ?? array() ) as $item ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert( Schema::table( 'quote_items' ), array_intersect_key( (array) $item, array_flip( array( 'id', 'quote_id', 'sort_order', 'description', 'quantity', 'unit', 'unit_price', 'line_total', 'selected' ) ) ) );
			}
		}
	}

	/**
	 * Completa el total con impuesto.
	 *
	 * @param array<string,mixed> $data Datos con amount_net y tax_rate.
	 * @return array<string,mixed>
	 */
	private static function with_total( array $data ): array {
		if ( array_key_exists( 'amount_net', $data ) ) {
			$data['amount_total'] = null === $data['amount_net'] ? null : UfMath::with_tax( (float) $data['amount_net'], (float) ( $data['tax_rate'] ?? 19.0 ) );
		}

		return $data;
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'purchase_id', 'supplier_id', 'document_id', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'amount_net', 'tax_rate', 'amount_total' ) as $num ) {
			$row[ $num ] = null === $row[ $num ] ? null : (float) $row[ $num ];
		}
		foreach ( array( 'requested_at', 'quote_date', 'valid_until' ) as $date ) {
			$row[ $date ] = $row[ $date ] ? (string) $row[ $date ] : null;
		}
		$row['notes']    = (string) $row['notes'];
		$supplier        = $row['supplier_id'] > 0 ? SupplierRepository::find( $row['supplier_id'] ) : null;
		$row['supplier'] = $supplier ? $supplier['name'] : '';
		$row['items']    = self::items( $row['id'] );

		return $row;
	}
}
