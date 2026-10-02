<?php
/**
 * Convenio del proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Finance\Profiles\Profiles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Un convenio por proyecto: perfil, otorgante, fechas, montos por fuente,
 * datos del proyecto en la plataforma y de la cuenta donde se reciben los
 * recursos.
 */
final class AgreementRepository extends Repository {

	protected const TABLE  = 'finance_agreements';
	protected const ENTITY = 'finance_agreement';
	protected const CASTS  = array(
		'agreement_date'        => 'date',
		'approval_date'         => 'date',
		'start_date'            => 'date',
		'end_date'              => 'date',
		'platform_end_date'     => 'date',
		'platform_render_until' => 'date',
		'months'                => 'int',
		'fund_amount'           => 'float',
		'cash_amount'           => 'float',
		'inkind_amount'         => 'float',
		'guarantee_required'    => 'bool',
		'created_by'            => 'int',
	);

	/**
	 * Convenio del proyecto, o null si no se ha registrado.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function for_project_single( int $project_id ): ?array {
		$rows = self::for_project( $project_id, 'id ASC', array(), array(), 1 );

		return $rows[0] ?? null;
	}

	/**
	 * Convenio con valores por omisión cuando no existe.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function get( int $project_id ): array {
		$row = self::for_project_single( $project_id );
		if ( $row ) {
			return $row;
		}
		$profile = Profiles::get( '' );

		return array(
			'id'                    => 0,
			'project_id'            => $project_id,
			'profile'               => $profile->slug(),
			'funder'                => '',
			'program'               => '',
			'agreement_date'        => null,
			'approval_act'          => '',
			'approval_date'         => null,
			'start_date'            => null,
			'end_date'              => null,
			'months'                => 0,
			'fund_amount'           => 0.0,
			'cash_amount'           => 0.0,
			'inkind_amount'         => 0.0,
			'guarantee_required'    => false,
			'platform'              => 'SISREC',
			'platform_code'         => '',
			'platform_end_date'     => null,
			'platform_render_until' => null,
			'bank_account'          => '',
			'cost_center'           => '',
			'notes'                 => '',
			'version'               => 0,
		);
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
				'profile'               => 'key',
				'funder'                => 'text',
				'program'               => 'text',
				'agreement_date'        => 'date',
				'approval_act'          => 'text',
				'approval_date'         => 'date',
				'start_date'            => 'date',
				'end_date'              => 'date',
				'months'                => 'int',
				'fund_amount'           => 'money',
				'cash_amount'           => 'money',
				'inkind_amount'         => 'money',
				'guarantee_required'    => 'bool',
				'platform'              => 'text',
				'platform_code'         => 'text',
				'platform_end_date'     => 'date',
				'platform_render_until' => 'date',
				'bank_account'          => 'text',
				'cost_center'           => 'text',
				'notes'                 => 'textarea',
			),
			$errors
		);
		if ( isset( $clean['profile'] ) && ! isset( Profiles::all()[ $clean['profile'] ] ) ) {
			$errors->add( 'profile', __( 'El perfil de fondo no existe.', 'gestion-de-proyectos' ) );
		}
		if ( ! empty( $clean['start_date'] ) && ! empty( $clean['end_date'] ) && $clean['end_date'] < $clean['start_date'] ) {
			$errors->add( 'end_date', __( 'El término no puede ser anterior al inicio.', 'gestion-de-proyectos' ) );
		}
		foreach ( array( 'fund_amount', 'cash_amount', 'inkind_amount' ) as $f ) {
			if ( isset( $clean[ $f ] ) && $clean[ $f ] < 0 ) {
				$errors->add( $f, __( 'Los montos no pueden ser negativos.', 'gestion-de-proyectos' ) );
			}
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea o actualiza el convenio del proyecto.
	 *
	 * @param int                 $project_id       Proyecto.
	 * @param array<string,mixed> $data             Datos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( int $project_id, array $data, ?int $expected_version = null ) {
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$current = self::for_project_single( $project_id );
		if ( $current ) {
			return self::update_row( $current['id'], $clean, $expected_version, __( 'Convenio actualizado', 'gestion-de-proyectos' ) );
		}
		$clean['project_id'] = $project_id;
		if ( empty( $clean['profile'] ) ) {
			$clean['profile'] = Profiles::get( '' )->slug();
		}
		$id = self::insert( $clean, __( 'Convenio registrado', 'gestion-de-proyectos' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return self::find( $id );
	}

	/**
	 * Fecha desde la que un egreso es rendible: la mayor entre la total
	 * tramitación del acto y el inicio del plazo.
	 *
	 * @param array<string,mixed> $agreement Convenio.
	 * @return string
	 */
	public static function render_from( array $agreement ): string {
		return (string) max( (string) ( $agreement['approval_date'] ?? '' ), (string) ( $agreement['start_date'] ?? '' ) );
	}
}
