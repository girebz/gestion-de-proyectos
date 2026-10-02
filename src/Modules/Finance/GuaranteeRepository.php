<?php
/**
 * Garantías.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Instrumentos de garantía: de fiel cumplimiento, por la fracción no
 * rendida de una cuota o por una prórroga, con monto, vigencia y estado.
 */
final class GuaranteeRepository extends Repository {

	protected const TABLE  = 'finance_guarantees';
	protected const ENTITY = 'finance_guarantee';
	protected const CASTS  = array( 'amount' => 'float', 'issued_at' => 'date', 'valid_until' => 'date', 'installment_no' => 'int', 'document_id' => 'int' );

	public const KINDS       = array( 'fiel_cumplimiento', 'fraccion_no_rendida', 'prorroga' );
	public const INSTRUMENTS = array( 'boleta_garantia', 'poliza', 'vale_vista', 'otro' );
	public const STATUSES    = array( 'vigente', 'devuelta', 'cobrada', 'vencida' );

	/**
	 * Etiquetas.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function labels(): array {
		return array(
			'kinds'       => array( 'fiel_cumplimiento' => __( 'Fiel cumplimiento', 'gestion-de-proyectos' ), 'fraccion_no_rendida' => __( 'Fracción no rendida de una cuota', 'gestion-de-proyectos' ), 'prorroga' => __( 'Prórroga', 'gestion-de-proyectos' ) ),
			'instruments' => array( 'boleta_garantia' => __( 'Boleta de garantía', 'gestion-de-proyectos' ), 'poliza' => __( 'Póliza', 'gestion-de-proyectos' ), 'vale_vista' => __( 'Vale vista', 'gestion-de-proyectos' ), 'otro' => __( 'Otro', 'gestion-de-proyectos' ) ),
			'statuses'    => array( 'vigente' => __( 'Vigente', 'gestion-de-proyectos' ), 'devuelta' => __( 'Devuelta', 'gestion-de-proyectos' ), 'cobrada' => __( 'Cobrada', 'gestion-de-proyectos' ), 'vencida' => __( 'Vencida', 'gestion-de-proyectos' ) ),
		);
	}

	/**
	 * Garantías del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id ): array {
		return self::for_project( $project_id, 'valid_until DESC, id DESC' );
	}

	/**
	 * Monto vigente que cubre la fracción no rendida de una cuota en una fecha.
	 *
	 * @param int    $project_id     Proyecto.
	 * @param int    $installment_no Cuota (0 = cualquiera).
	 * @param string $today          Fecha.
	 * @return float
	 */
	public static function covering( int $project_id, int $installment_no, string $today ): float {
		$sum = 0.0;
		foreach ( self::all( $project_id ) as $g ) {
			if ( 'vigente' !== $g['status'] || 'fraccion_no_rendida' !== $g['kind'] ) {
				continue;
			}
			if ( $g['valid_until'] && $g['valid_until'] < $today ) {
				continue;
			}
			if ( $installment_no > 0 && $g['installment_no'] > 0 && $g['installment_no'] !== $installment_no ) {
				continue;
			}
			$sum += (float) $g['amount'];
		}

		return round( $sum, 2 );
	}

	/**
	 * Valida los datos.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data ) {
		$errors = new WP_Error();
		$clean  = self::clean( $data, array( 'kind' => 'key', 'instrument' => 'key', 'number' => 'text', 'issuer' => 'text', 'amount' => 'money', 'issued_at' => 'date', 'valid_until' => 'date', 'installment_no' => 'int', 'document_id' => 'int', 'status' => 'key', 'notes' => 'textarea' ), $errors );
		if ( isset( $clean['kind'] ) && ! in_array( $clean['kind'], self::KINDS, true ) ) {
			$errors->add( 'kind', __( 'Tipo de garantía no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['instrument'] ) && ! in_array( $clean['instrument'], self::INSTRUMENTS, true ) ) {
			$errors->add( 'instrument', __( 'Instrumento no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['status'] ) && ! in_array( $clean['status'], self::STATUSES, true ) ) {
			$errors->add( 'status', __( 'Estado no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['amount'] ) && $clean['amount'] <= 0 ) {
			$errors->add( 'amount', __( 'El monto debe ser mayor que cero.', 'gestion-de-proyectos' ) );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una garantía.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		$clean = self::validate( array_merge( array( 'kind' => 'fiel_cumplimiento', 'instrument' => 'boleta_garantia', 'status' => 'vigente' ), $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['amount'] ) ) {
			return new WP_Error( 'amount', __( 'Indique el monto de la garantía.', 'gestion-de-proyectos' ) );
		}
		$clean['project_id'] = $project_id;

		return self::insert( $clean, sprintf( 'Garantía registrada: %s por %s', $clean['kind'], number_format( (float) $clean['amount'], 0, ',', '.' ) ) );
	}

	/**
	 * Actualiza una garantía.
	 *
	 * @param int                 $id               Garantía.
	 * @param array<string,mixed> $data             Datos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		return self::update_row( $id, $clean, $expected_version, __( 'Garantía actualizada', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una garantía.
	 *
	 * @param int $id Garantía.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return self::delete_row( $id, __( 'Garantía eliminada', 'gestion-de-proyectos' ) );
	}
}
