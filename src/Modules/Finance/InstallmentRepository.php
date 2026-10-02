<?php
/**
 * Cuotas del convenio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cada cuota registra su monto y ventana del programa de desembolso, el
 * informe que la habilita, el aporte pecuniario asociado y las fechas de
 * solicitud, transferencia, ingreso a caja, comprobante de ingreso y
 * aceptación en la plataforma.
 */
final class InstallmentRepository extends Repository {

	protected const TABLE  = 'finance_installments';
	protected const ENTITY = 'finance_installment';
	protected const CASTS  = array(
		'number'           => 'int',
		'amount'           => 'float',
		'share_pct'        => 'float',
		'window_from'      => 'date',
		'window_to'        => 'date',
		'report_no'        => 'int',
		'cash_amount'      => 'float',
		'cash_received_at' => 'date',
		'requested_at'     => 'date',
		'transferred_at'   => 'date',
		'received_at'      => 'date',
		'receipt_sent_at'  => 'date',
		'document_id'      => 'int',
	);

	public const PLATFORM_STATUSES = array( 'pendiente', 'enviada', 'aceptada', 'rechazada' );

	/**
	 * Cuotas del proyecto en orden.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id ): array {
		return self::for_project( $project_id, 'number ASC' );
	}

	/**
	 * Cuota por número.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $number     Número.
	 * @return array<string,mixed>|null
	 */
	public static function by_number( int $project_id, int $number ): ?array {
		foreach ( self::all( $project_id ) as $i ) {
			if ( $i['number'] === $number ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Montos por número de cuota.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,float>
	 */
	public static function amounts( int $project_id ): array {
		$out = array();
		foreach ( self::all( $project_id ) as $i ) {
			$out[ $i['number'] ] = (float) $i['amount'];
		}

		return $out;
	}

	/**
	 * Indica si la cuota ya ingresó a caja.
	 *
	 * @param array<string,mixed> $i Cuota.
	 * @return bool
	 */
	public static function is_received( array $i ): bool {
		return ! empty( $i['received_at'] ) || ! empty( $i['transferred_at'] );
	}

	/**
	 * Fecha efectiva de ingreso.
	 *
	 * @param array<string,mixed> $i Cuota.
	 * @return string|null
	 */
	public static function received_on( array $i ): ?string {
		return $i['received_at'] ?? $i['transferred_at'] ?? null;
	}

	/**
	 * Valida los datos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data ) {
		$errors = new WP_Error();
		$clean  = self::clean(
			$data,
			array(
				'number'           => 'int',
				'label'            => 'text',
				'amount'           => 'money',
				'share_pct'        => 'money',
				'window_from'      => 'date',
				'window_to'        => 'date',
				'report_no'        => 'int',
				'cash_amount'      => 'money',
				'cash_received_at' => 'date',
				'cash_receipt'     => 'text',
				'requested_at'     => 'date',
				'transferred_at'   => 'date',
				'received_at'      => 'date',
				'receipt_number'   => 'text',
				'receipt_sent_at'  => 'date',
				'platform_status'  => 'key',
				'document_id'      => 'int',
				'notes'            => 'textarea',
			),
			$errors
		);
		if ( isset( $clean['number'] ) && $clean['number'] <= 0 ) {
			$errors->add( 'number', __( 'El número de cuota debe ser mayor que cero.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['amount'] ) && $clean['amount'] < 0 ) {
			$errors->add( 'amount', __( 'El monto no puede ser negativo.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['platform_status'] ) && ! in_array( $clean['platform_status'], self::PLATFORM_STATUSES, true ) ) {
			$errors->add( 'platform_status', __( 'Estado de la transferencia no válido.', 'gestion-de-proyectos' ) );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una cuota.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		$clean = self::validate( array_merge( array( 'platform_status' => 'pendiente' ), $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['number'] ) ) {
			$numbers         = array_keys( self::amounts( $project_id ) );
			$clean['number'] = empty( $numbers ) ? 1 : max( $numbers ) + 1;
		}
		if ( self::by_number( $project_id, (int) $clean['number'] ) ) {
			return new WP_Error( 'number', __( 'Ya existe una cuota con ese número.', 'gestion-de-proyectos' ) );
		}
		if ( empty( $clean['label'] ) ) {
			$clean['label'] = sprintf( 'Cuota %d', $clean['number'] );
		}
		$clean['project_id'] = $project_id;

		return self::insert( $clean, sprintf( 'Cuota %d registrada: %s', $clean['number'], number_format( (float) ( $clean['amount'] ?? 0 ), 0, ',', '.' ) ) );
	}

	/**
	 * Actualiza una cuota.
	 *
	 * @param int                 $id               Cuota.
	 * @param array<string,mixed> $data             Datos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La cuota no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( isset( $clean['number'] ) && $clean['number'] !== $current['number'] && self::by_number( $current['project_id'], (int) $clean['number'] ) ) {
			return new WP_Error( 'number', __( 'Ya existe una cuota con ese número.', 'gestion-de-proyectos' ) );
		}

		return self::update_row( $id, $clean, $expected_version, sprintf( 'Cuota %d actualizada', $current['number'] ) );
	}

	/**
	 * Elimina una cuota.
	 *
	 * @param int $id Cuota.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$current = self::find( $id );

		return $current ? self::delete_row( $id, sprintf( 'Cuota %d eliminada', $current['number'] ) ) : false;
	}
}
