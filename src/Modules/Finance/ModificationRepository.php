<?php
/**
 * Modificaciones del convenio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reitemizaciones, redistribuciones dentro de un ítem, incorporaciones o
 * cambios de personal, prórrogas y reprogramaciones de cuotas, con estado y
 * acto que las aprueba. Al aprobarse una reitemización, el asignado de los
 * ítems se ajusta según su detalle.
 */
final class ModificationRepository extends Repository {

	protected const TABLE  = 'finance_modifications';
	protected const ENTITY = 'finance_modification';
	protected const CASTS  = array( 'requested_at' => 'date', 'approved_at' => 'date', 'document_id' => 'int', 'details' => 'json' );

	public const KINDS    = array( 'reitemizacion', 'redistribucion', 'personal', 'prorroga', 'reprogramacion', 'otra' );
	public const STATUSES = array( 'solicitada', 'aprobada', 'rechazada' );

	/**
	 * Etiquetas.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function labels(): array {
		return array(
			'kinds'    => array( 'reitemizacion' => __( 'Reitemización', 'gestion-de-proyectos' ), 'redistribucion' => __( 'Redistribución dentro de un ítem', 'gestion-de-proyectos' ), 'personal' => __( 'Incorporación o cambio de personal', 'gestion-de-proyectos' ), 'prorroga' => __( 'Prórroga', 'gestion-de-proyectos' ), 'reprogramacion' => __( 'Reprogramación de cuotas', 'gestion-de-proyectos' ), 'otra' => __( 'Otra', 'gestion-de-proyectos' ) ),
			'statuses' => array( 'solicitada' => __( 'Solicitada', 'gestion-de-proyectos' ), 'aprobada' => __( 'Aprobada', 'gestion-de-proyectos' ), 'rechazada' => __( 'Rechazada', 'gestion-de-proyectos' ) ),
		);
	}

	/**
	 * Modificaciones del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id ): array {
		return self::for_project( $project_id, 'id DESC' );
	}

	/**
	 * Reitemizaciones usadas (solicitadas o aprobadas).
	 *
	 * @param int $project_id Proyecto.
	 * @return int
	 */
	public static function reitemizations_used( int $project_id ): int {
		$n = 0;
		foreach ( self::all( $project_id ) as $m ) {
			if ( 'reitemizacion' === $m['kind'] && 'rechazada' !== $m['status'] ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Valida los datos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data ) {
		$errors = new WP_Error();
		$clean  = self::clean( $data, array( 'kind' => 'key', 'status' => 'key', 'requested_at' => 'date', 'approved_at' => 'date', 'act_number' => 'text', 'document_id' => 'int', 'details' => 'json', 'notes' => 'textarea' ), $errors );
		if ( isset( $clean['kind'] ) && ! in_array( $clean['kind'], self::KINDS, true ) ) {
			$errors->add( 'kind', __( 'Tipo de modificación no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['status'] ) && ! in_array( $clean['status'], self::STATUSES, true ) ) {
			$errors->add( 'status', __( 'Estado no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['details'] ) ) {
			$details = array();
			foreach ( $clean['details'] as $d ) {
				if ( ! is_array( $d ) ) {
					continue;
				}
				$details[] = array( 'item' => sanitize_key( (string) ( $d['item'] ?? '' ) ), 'source' => sanitize_key( (string) ( $d['source'] ?? 'fondo' ) ), 'delta' => round( (float) ( self::number( $d['delta'] ?? 0 ) ?? 0 ), 2 ), 'note' => sanitize_text_field( (string) ( $d['note'] ?? '' ) ) );
			}
			$clean['details'] = $details;
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una modificación.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		$clean = self::validate( array_merge( array( 'kind' => 'reitemizacion', 'status' => 'solicitada', 'details' => array() ), $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( 'reitemizacion' === $clean['kind'] ) {
			$sum = 0.0;
			foreach ( $clean['details'] as $d ) {
				$sum += (float) $d['delta'];
			}
			if ( abs( $sum ) > 0.5 ) {
				return new WP_Error( 'details', __( 'Una reitemización traslada montos entre ítems: la suma de los cambios debe ser cero.', 'gestion-de-proyectos' ) );
			}
		}
		$clean['project_id'] = $project_id;
		$id                  = self::insert( $clean, sprintf( 'Modificación registrada: %s (%s)', $clean['kind'], $clean['status'] ) );
		if ( ! is_wp_error( $id ) && 'aprobada' === $clean['status'] ) {
			self::apply_details( $project_id, $clean );
		}

		return $id;
	}

	/**
	 * Actualiza una modificación; al pasar a aprobada aplica su detalle a los ítems.
	 *
	 * @param int                 $id               Modificación.
	 * @param array<string,mixed> $data             Datos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La modificación no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$result = self::update_row( $id, $clean, $expected_version, sprintf( 'Modificación %d actualizada', $id ) );
		if ( ! is_wp_error( $result ) && 'aprobada' === ( $clean['status'] ?? '' ) && 'aprobada' !== $current['status'] ) {
			self::apply_details( $current['project_id'], $result );
		}

		return $result;
	}

	/**
	 * Aplica los cambios de asignado de una reitemización aprobada.
	 *
	 * @param int                 $project_id   Proyecto.
	 * @param array<string,mixed> $modification Modificación.
	 * @return void
	 */
	private static function apply_details( int $project_id, array $modification ): void {
		if ( 'reitemizacion' !== $modification['kind'] ) {
			return;
		}
		$items = ItemRepository::by_slug( $project_id );
		foreach ( $modification['details'] as $d ) {
			if ( empty( $d['item'] ) || ! isset( $items[ $d['item'] ] ) ) {
				continue;
			}
			$column = 'pecuniario' === $d['source'] ? 'assigned_cash' : ( 'no_pecuniario' === $d['source'] ? 'assigned_inkind' : 'assigned_fund' );
			ItemRepository::save( $project_id, array( 'slug' => $d['item'], $column => (float) $items[ $d['item'] ][ $column ] + (float) $d['delta'] ) );
		}
	}

	/**
	 * Elimina una modificación no aprobada.
	 *
	 * @param int $id Modificación.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La modificación no existe.', 'gestion-de-proyectos' ) );
		}
		if ( 'aprobada' === $current['status'] ) {
			return new WP_Error( 'approved', __( 'Una modificación aprobada no se elimina; registre otra que la revierta.', 'gestion-de-proyectos' ) );
		}

		return self::delete_row( $id, __( 'Modificación eliminada', 'gestion-de-proyectos' ) );
	}
}
