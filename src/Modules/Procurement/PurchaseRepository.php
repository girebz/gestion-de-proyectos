<?php
/**
 * Repositorio de compras.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Core\Audit;
use GDP\Core\Catalogs;
use GDP\Core\Schema;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\Numbering;
use GDP\Modules\Planning\ActivityRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada compra recorre las etapas del catálogo (solicitud de cotización,
 * cotización recibida, seguimiento, elección, solicitud interna, orden de
 * compra, factura, pago) con fecha, responsable y nota por etapa. Los montos
 * se guardan en la moneda de origen y convertidos a pesos con el valor de la
 * unidad de fomento aplicable; comprometido y ejecutado se derivan de la etapa.
 */
final class PurchaseRepository {

	public const STATUSES   = array( 'abierta', 'cerrada', 'anulada' );
	public const CURRENCIES = array( 'CLP', 'UF', 'USD' );
	public const COMMITTED  = array( 'orden_compra', 'factura', 'pago' );
	public const EXECUTED   = array( 'pago' );

	/**
	 * Etiquetas de estado.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'abierta' => __( 'Abierta', 'gestion-de-proyectos' ),
			'cerrada' => __( 'Cerrada', 'gestion-de-proyectos' ),
			'anulada' => __( 'Anulada', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etapas del catálogo en orden.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,string> slug => etiqueta.
	 */
	public static function stages( int $project_id ): array {
		$stages = array();
		foreach ( Catalogs::items( Catalogs::PROCUREMENT_STAGE, $project_id ) as $item ) {
			$stages[ (string) $item['slug'] ] = (string) $item['label'];
		}

		return $stages;
	}

	/**
	 * Posición de una etapa en el ciclo (0 = primera).
	 *
	 * @param string $stage      Etapa.
	 * @param int    $project_id Proyecto.
	 * @return int
	 */
	public static function stage_index( string $stage, int $project_id ): int {
		$index = array_search( $stage, array_keys( self::stages( $project_id ) ), true );

		return false === $index ? -1 : (int) $index;
	}

	/**
	 * Partidas presupuestarias del catálogo.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,string>
	 */
	public static function budget_lines( int $project_id ): array {
		$lines = array();
		foreach ( Catalogs::items( Catalogs::BUDGET_LINE, $project_id ) as $item ) {
			$lines[ (string) $item['slug'] ] = (string) $item['label'];
		}

		return $lines;
	}

	/**
	 * Una compra.
	 *
	 * @param int $id Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'purchases' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Compras de un proyecto con filtros.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $filters    stage, status, budget_line, supplier_id, activity_id, open (bool), search, limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_project( int $project_id, array $filters = array() ): array {
		global $wpdb;

		$table = Schema::table( 'purchases' );
		$where = array( 'project_id = %d' );
		$args  = array( $project_id );
		foreach ( array( 'stage', 'status', 'budget_line' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[] = "{$field} = %s";
				$args[]  = (string) $filters[ $field ];
			}
		}
		foreach ( array( 'supplier_id', 'activity_id' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[] = "{$field} = %d";
				$args[]  = (int) $filters[ $field ];
			}
		}
		if ( ! empty( $filters['open'] ) ) {
			$where[] = "status = 'abierta'";
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(title LIKE %s OR code LIKE %s OR description LIKE %s OR order_number LIKE %s OR invoice_number LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$limit = isset( $filters['limit'] ) ? max( 1, min( 1000, (int) $filters['limit'] ) ) : 500;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit}", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Resumen por etapa y alertas de seguimiento.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{total:int,open:int,by_stage:array<string,int>,awaiting_decision:int,quotes_pending:int}
	 */
	public static function stats( int $project_id ): array {
		$by      = array();
		$open    = 0;
		$total   = 0;
		$waiting = 0;
		foreach ( self::for_project( $project_id, array( 'limit' => 1000 ) ) as $p ) {
			++$total;
			$by[ $p['stage'] ] = ( $by[ $p['stage'] ] ?? 0 ) + 1;
			if ( 'abierta' === $p['status'] ) {
				++$open;
				if ( self::awaiting_decision( $p ) ) {
					++$waiting;
				}
			}
		}

		return array(
			'total'             => $total,
			'open'              => $open,
			'by_stage'          => $by,
			'awaiting_decision' => $waiting,
			'quotes_pending'    => count( QuoteRepository::pending_requests( $project_id ) ),
		);
	}

	/**
	 * Indica si una compra lleva demasiado tiempo con cotizaciones recibidas sin decidir.
	 *
	 * @param array<string,mixed> $p Compra.
	 * @return bool
	 */
	public static function awaiting_decision( array $p ): bool {
		if ( 'abierta' !== $p['status'] || ! in_array( $p['stage'], array( 'cotizacion_recibida', 'seguimiento' ), true ) ) {
			return false;
		}
		$last = self::last_stage_date( (int) $p['id'] );
		$days = (int) ProcurementSettings::get( (int) $p['project_id'], 'decision_days' );

		return null !== $last && UfMath::age_days( $last, current_time( 'Y-m-d' ) ) > $days;
	}

	/**
	 * Fecha del último cambio de etapa.
	 *
	 * @param int $purchase_id Compra.
	 * @return string|null
	 */
	public static function last_stage_date( int $purchase_id ): ?string {
		$history = self::stage_history( $purchase_id );
		$last    = end( $history );

		return $last ? (string) $last['stage_date'] : null;
	}

	/**
	 * Historial de etapas, de la más antigua a la más reciente.
	 *
	 * @param int $purchase_id Compra.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stage_history( int $purchase_id ): array {
		global $wpdb;

		$table = Schema::table( 'purchase_stages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE purchase_id = %d ORDER BY id ASC", $purchase_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$user  = get_userdata( (int) $r['user_id'] );
			$out[] = array(
				'id'          => (int) $r['id'],
				'stage'       => (string) $r['stage'],
				'stage_date'  => (string) $r['stage_date'],
				'user_id'     => (int) $r['user_id'],
				'user'        => $user ? $user->display_name : '',
				'document_id' => (int) $r['document_id'],
				'note'        => (string) $r['note'],
				'created_at'  => (string) $r['created_at'],
			);
		}

		return $out;
	}

	/**
	 * Valida y normaliza los campos.
	 *
	 * @param array<string,mixed> $data       Datos.
	 * @param int                 $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data, int $project_id ) {
		$clean  = array();
		$errors = new WP_Error();

		if ( array_key_exists( 'title', $data ) ) {
			$clean['title'] = sanitize_text_field( (string) $data['title'] );
			if ( '' === $clean['title'] ) {
				$errors->add( 'title', __( 'El título de la compra es obligatorio.', 'gestion-de-proyectos' ) );
			}
		}
		foreach ( array( 'description', 'notes' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = wp_kses_post( (string) $data[ $field ] );
			}
		}
		foreach ( array( 'code', 'order_number', 'invoice_number' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}
		if ( array_key_exists( 'budget_line', $data ) ) {
			$line = sanitize_key( (string) $data['budget_line'] );
			if ( '' !== $line && ! isset( self::budget_lines( $project_id )[ $line ] ) ) {
				$errors->add( 'budget_line', __( 'La partida no está en el catálogo del proyecto.', 'gestion-de-proyectos' ) );
			} else {
				$clean['budget_line'] = $line;
			}
		}
		if ( array_key_exists( 'stage', $data ) ) {
			$stage = sanitize_key( (string) $data['stage'] );
			if ( ! isset( self::stages( $project_id )[ $stage ] ) ) {
				$errors->add( 'stage', __( 'La etapa no está en el catálogo.', 'gestion-de-proyectos' ) );
			} else {
				$clean['stage'] = $stage;
			}
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				$errors->add( 'status', __( 'El estado no es válido.', 'gestion-de-proyectos' ) );
			} else {
				$clean['status'] = $status;
			}
		}
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
		if ( array_key_exists( 'owner_id', $data ) ) {
			$owner = (int) $data['owner_id'];
			if ( $owner > 0 && ! get_userdata( $owner ) ) {
				$errors->add( 'owner_id', __( 'El responsable no existe.', 'gestion-de-proyectos' ) );
			} else {
				$clean['owner_id'] = max( 0, $owner );
			}
		}
		if ( array_key_exists( 'activity_id', $data ) ) {
			$activity_id = (int) $data['activity_id'];
			if ( $activity_id > 0 ) {
				$activity = ActivityRepository::find( $activity_id );
				if ( ! $activity || $activity['project_id'] !== $project_id ) {
					$errors->add( 'activity_id', __( 'La actividad no existe en este proyecto.', 'gestion-de-proyectos' ) );
				}
			}
			$clean['activity_id'] = max( 0, $activity_id );
		}
		if ( array_key_exists( 'currency', $data ) ) {
			$currency = strtoupper( sanitize_text_field( (string) $data['currency'] ) );
			if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
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
				$value = self::number( $data[ $field ] );
				if ( null === $value || $value < 0 ) {
					$errors->add( $field, __( 'Monto no válido.', 'gestion-de-proyectos' ) );
				} else {
					$clean[ $field ] = $value;
				}
			}
		}
		foreach ( array( 'order_date', 'invoice_date', 'paid_at', 'expected_at', 'received_at', 'uf_date' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$date = ActivityRepository::normalize_date( $data[ $field ] );
				if ( false === $date ) {
					$errors->add( $field, __( 'Fecha no válida (use AAAA-MM-DD).', 'gestion-de-proyectos' ) );
				} else {
					$clean[ $field ] = $date;
				}
			}
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una compra con código correlativo.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$stages = array_keys( self::stages( $project_id ) );
		$data   = array_merge( array( 'stage' => $stages[0] ?? 'solicitud_cotizacion', 'status' => 'abierta', 'currency' => 'CLP', 'tax_rate' => 19.0 ), $data );
		$clean  = self::validate( $data, $project_id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['title'] ) ) {
			return new WP_Error( 'required', __( 'El título de la compra es obligatorio.', 'gestion-de-proyectos' ) );
		}
		if ( empty( $clean['code'] ) ) {
			$next              = self::next_code( $project_id );
			$clean['seq_no']   = $next['sequence'];
			$clean['code']     = $next['number'];
		}
		$clean = self::with_amounts( $clean, $project_id );

		$now                 = current_time( 'mysql', true );
		$clean['project_id'] = $project_id;
		$clean['version']    = 1;
		$clean['created_by'] = get_current_user_id();
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'purchases' ), $clean ) ) {
			return new WP_Error( 'db', __( 'No se pudo guardar la compra.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		self::add_stage_row( $id, (string) $clean['stage'], current_time( 'Y-m-d' ), '', 0 );
		Audit::log( 'purchase', $id, 'create', $project_id, sprintf( 'Compra creada: %s %s', $clean['code'], $clean['title'] ), null, self::find( $id ) );

		return $id;
	}

	/**
	 * Actualiza una compra con control optimista de versión.
	 *
	 * @param int                 $id               Compra.
	 * @param array<string,mixed> $data             Campos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La compra no existe.', 'gestion-de-proyectos' ) );
		}
		if ( null !== $expected_version && $expected_version !== $current['version'] ) {
			return new WP_Error( 'version_conflict', sprintf( 'La compra cambió (versión %d, se esperaba %d). Vuelva a leerla antes de modificarla.', $current['version'], $expected_version ) );
		}
		$clean = self::validate( $data, $current['project_id'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean ) ) {
			return $current;
		}
		if ( isset( $clean['stage'] ) && $clean['stage'] !== $current['stage'] ) {
			self::add_stage_row( $id, $clean['stage'], current_time( 'Y-m-d' ), '', 0 );
		}
		$merged = array_merge( $current, $clean );
		$clean  = array_merge( $clean, array_intersect_key( self::with_amounts( $merged, $current['project_id'] ), array_flip( array( 'amount_total', 'amount_clp', 'uf_rate', 'uf_date' ) ) ) );
		$clean['version']    = $current['version'] + 1;
		$clean['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( Schema::table( 'purchases' ), $clean, array( 'id' => $id ) ) ) {
			return new WP_Error( 'db', __( 'No se pudo actualizar la compra.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		$updated = self::find( $id );
		Audit::log( 'purchase', $id, 'update', $current['project_id'], sprintf( 'Compra actualizada: %s', $updated['title'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Cambia la etapa registrando fecha, nota y documento; fija las fechas de orden, factura y pago.
	 *
	 * @param int    $id          Compra.
	 * @param string $stage       Etapa.
	 * @param string $date        Fecha de la etapa.
	 * @param string $note        Nota.
	 * @param int    $document_id Documento asociado (orden, factura).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_stage( int $id, string $stage, string $date, string $note = '', int $document_id = 0 ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La compra no existe.', 'gestion-de-proyectos' ) );
		}
		if ( ! isset( self::stages( $current['project_id'] )[ $stage ] ) ) {
			return new WP_Error( 'stage', __( 'La etapa no está en el catálogo.', 'gestion-de-proyectos' ) );
		}
		$data = array( 'stage' => $stage );
		if ( 'orden_compra' === $stage && empty( $current['order_date'] ) ) {
			$data['order_date'] = $date;
		}
		if ( 'factura' === $stage && empty( $current['invoice_date'] ) ) {
			$data['invoice_date'] = $date;
		}
		if ( 'pago' === $stage && empty( $current['paid_at'] ) ) {
			$data['paid_at'] = $date;
		}
		$merged = array_merge( $current, $data );
		$data   = array_merge( $data, array_intersect_key( self::with_amounts( $merged, $current['project_id'] ), array_flip( array( 'amount_total', 'amount_clp', 'uf_rate', 'uf_date' ) ) ) );
		$data['version']    = $current['version'] + 1;
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'purchases' ), $data, array( 'id' => $id ) );
		self::add_stage_row( $id, $stage, $date, $note, $document_id );
		$updated = self::find( $id );
		Audit::log( 'purchase', $id, 'stage', $current['project_id'], sprintf( 'Compra %s pasa a %s', $current['code'], self::stages( $current['project_id'] )[ $stage ] ), $current, $updated );

		return $updated;
	}

	/**
	 * Marca la aprobación formal de la compra.
	 *
	 * @param int $id Compra.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function approve( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La compra no existe.', 'gestion-de-proyectos' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'purchases' ), array( 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql', true ), 'version' => $current['version'] + 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		$updated = self::find( $id );
		Audit::log( 'purchase', $id, 'approve', $current['project_id'], sprintf( 'Compra aprobada: %s', $current['code'] ), $current, $updated );

		return $updated;
	}

	/**
	 * Elimina una compra con sus cotizaciones e historial.
	 *
	 * @param int $id Compra.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La compra no existe.', 'gestion-de-proyectos' ) );
		}
		QuoteRepository::delete_for_purchase( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'purchase_stages' ), array( 'purchase_id' => $id ) );
		\GDP\Modules\Documents\LinkRepository::delete_for_entity( 'purchase', $id );
		\GDP\Modules\Documents\ExternalRefRepository::delete_for_entity( 'purchase', $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'purchases' ), array( 'id' => $id ) );
		Audit::log( 'purchase', $id, 'delete', $current['project_id'], sprintf( 'Compra eliminada: %s %s', $current['code'], $current['title'] ), $current, null );

		return true;
	}

	/**
	 * Instantánea completa para revertir una eliminación.
	 *
	 * @param int $id Compra.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $id ): ?array {
		global $wpdb;

		$purchase = self::find( $id );
		if ( ! $purchase ) {
			return null;
		}
		$table = Schema::table( 'purchase_stages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE purchase_id = %d ORDER BY id ASC", $id ), ARRAY_A );

		return array(
			'purchase' => $purchase,
			'stages'   => is_array( $stages ) ? $stages : array(),
			'quotes'   => QuoteRepository::snapshot_for_purchase( $id ),
			'links'    => \GDP\Modules\Documents\LinkRepository::rows_for_entity( 'purchase', $id ),
			'refs'     => \GDP\Modules\Documents\ExternalRefRepository::for_entity( 'purchase', $id ),
		);
	}

	/**
	 * Restaura una compra desde su instantánea.
	 *
	 * @param array<string,mixed> $snapshot Instantánea.
	 * @return bool|WP_Error
	 */
	public static function restore( array $snapshot ) {
		global $wpdb;

		$p = $snapshot['purchase'] ?? null;
		if ( ! is_array( $p ) || empty( $p['id'] ) ) {
			return new WP_Error( 'snapshot', __( 'La instantánea de la compra está incompleta.', 'gestion-de-proyectos' ) );
		}
		if ( self::find( (int) $p['id'] ) ) {
			return new WP_Error( 'exists', __( 'La compra ya existe; no se puede restaurar sobre ella.', 'gestion-de-proyectos' ) );
		}
		$row = array_intersect_key( $p, array_flip( self::COLUMNS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->insert( Schema::table( 'purchases' ), $row ) ) {
			return new WP_Error( 'db', __( 'No se pudo restaurar la compra.', 'gestion-de-proyectos' ), $wpdb->last_error );
		}
		foreach ( (array) ( $snapshot['stages'] ?? array() ) as $s ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( Schema::table( 'purchase_stages' ), array_intersect_key( (array) $s, array_flip( array( 'id', 'purchase_id', 'stage', 'stage_date', 'user_id', 'document_id', 'note', 'created_at' ) ) ) );
		}
		QuoteRepository::restore( (array) ( $snapshot['quotes'] ?? array() ) );
		\GDP\Modules\Documents\LinkRepository::restore( (array) ( $snapshot['links'] ?? array() ) );
		\GDP\Modules\Documents\ExternalRefRepository::restore( (array) ( $snapshot['refs'] ?? array() ) );
		Audit::log( 'purchase', (int) $p['id'], 'restore', (int) $p['project_id'], sprintf( 'Compra restaurada: %s %s', $p['code'], $p['title'] ), null, self::find( (int) $p['id'] ) );

		return true;
	}

	public const COLUMNS = array( 'id', 'project_id', 'seq_no', 'code', 'title', 'description', 'budget_line', 'supplier_id', 'stage', 'status', 'owner_id', 'activity_id', 'chosen_quote_id', 'currency', 'amount_net', 'tax_rate', 'amount_total', 'amount_clp', 'uf_rate', 'uf_date', 'approved_by', 'approved_at', 'order_number', 'order_date', 'invoice_number', 'invoice_date', 'paid_at', 'expected_at', 'received_at', 'notes', 'version', 'created_by', 'created_at', 'updated_at' );

	/**
	 * Siguiente código correlativo.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{sequence:int,number:string}
	 */
	public static function next_code( int $project_id ): array {
		global $wpdb;

		$project = ProjectRepository::find( $project_id );
		$pattern = (string) ProcurementSettings::get( $project_id, 'pattern' );
		$table   = Schema::table( 'purchases' );
		$year    = (int) current_time( 'Y' );
		if ( Numbering::yearly( $pattern ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) FROM {$table} WHERE project_id = %d AND created_at >= %s", $project_id, $year . '-01-01 00:00:00' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq_no), 0) FROM {$table} WHERE project_id = %d", $project_id ) );
		}
		$sequence = $max + 1;

		return array( 'sequence' => $sequence, 'number' => Numbering::format( $pattern, 'COMPRA', $sequence, $year, (string) ( $project['code'] ?? '' ) ) );
	}

	/**
	 * Completa total y pesos a partir del neto, el impuesto, la moneda y la fecha de referencia.
	 *
	 * @param array<string,mixed> $row        Campos (pueden ser parciales).
	 * @param int                 $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function with_amounts( array $row, int $project_id ): array {
		$net = isset( $row['amount_net'] ) && null !== $row['amount_net'] ? (float) $row['amount_net'] : null;
		if ( null === $net ) {
			$row['amount_total'] = null;
			$row['amount_clp']   = null;
			return $row;
		}
		$tax                 = isset( $row['tax_rate'] ) ? (float) $row['tax_rate'] : 19.0;
		$row['amount_total'] = UfMath::with_tax( $net, $tax );
		$currency            = (string) ( $row['currency'] ?? 'CLP' );
		$date                = (string) ( $row['uf_date'] ?? $row['order_date'] ?? current_time( 'Y-m-d' ) );
		$conv                = UfService::to_clp( (float) $row['amount_total'], $currency, $date ? $date : current_time( 'Y-m-d' ) );
		$row['amount_clp']   = $conv['clp'];
		if ( 'UF' === $currency ) {
			$row['uf_rate'] = $conv['rate'];
			$row['uf_date'] = $conv['rate_date'];
		} else {
			$row['uf_rate'] = null;
			$row['uf_date'] = null;
		}

		return $row;
	}

	/**
	 * Inserta una fila de historial de etapa.
	 *
	 * @param int    $purchase_id Compra.
	 * @param string $stage       Etapa.
	 * @param string $date        Fecha.
	 * @param string $note        Nota.
	 * @param int    $document_id Documento.
	 * @return void
	 */
	private static function add_stage_row( int $purchase_id, string $stage, string $date, string $note, int $document_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( 'purchase_stages' ),
			array(
				'purchase_id' => $purchase_id,
				'stage'       => $stage,
				'stage_date'  => $date,
				'user_id'     => get_current_user_id(),
				'document_id' => $document_id,
				'note'        => sanitize_text_field( $note ),
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Elimina las compras de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function delete_for_project( int $project_id ): void {
		foreach ( self::for_project( $project_id, array( 'limit' => 1000 ) ) as $p ) {
			self::delete( (int) $p['id'] );
		}
	}

	/**
	 * Número decimal desde texto (admite coma decimal y separadores de miles).
	 *
	 * @param mixed $value Valor.
	 * @return float|null
	 */
	public static function number( $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return null;
		}
		$text = str_replace( array( ' ', '$' ), '', $text );
		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $text ) ) {
			$text = str_replace( '.', '', $text );
		}
		$text = str_replace( ',', '.', $text );

		return is_numeric( $text ) ? (float) $text : null;
	}

	/**
	 * Tipos de fila.
	 *
	 * @param array<string,mixed> $row Fila.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'project_id', 'seq_no', 'supplier_id', 'owner_id', 'activity_id', 'chosen_quote_id', 'approved_by', 'version', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'amount_net', 'tax_rate', 'amount_total', 'amount_clp', 'uf_rate' ) as $num ) {
			$row[ $num ] = null === $row[ $num ] ? null : (float) $row[ $num ];
		}
		foreach ( array( 'order_date', 'invoice_date', 'paid_at', 'expected_at', 'received_at', 'uf_date', 'approved_at' ) as $date ) {
			$row[ $date ] = $row[ $date ] ? (string) $row[ $date ] : null;
		}
		$row['description'] = (string) $row['description'];
		$row['notes']       = (string) $row['notes'];
		$row['committed']   = 'anulada' !== $row['status'] && in_array( $row['stage'], self::COMMITTED, true );
		$row['executed']    = 'anulada' !== $row['status'] && in_array( $row['stage'], self::EXECUTED, true );

		return $row;
	}
}
