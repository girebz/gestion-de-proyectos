<?php
/**
 * Ajustes del módulo de adquisiciones por proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Domain\Projects\ProjectRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Patrón de código de compra, días para decidir con cotizaciones recibidas,
 * antigüedad máxima de una cotización en unidades de fomento al emitir la
 * orden y tolerancia del valor implícito, guardados en settings.procurement
 * del proyecto.
 */
final class ProcurementSettings {

	/**
	 * Valores por defecto.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'pattern'        => '{PREFIJO}-{NNN}',
			'decision_days'  => 10,
			'quote_age_days' => 60,
			'uf_tolerance'   => 1.0,
			'request_days'   => 7,
		);
	}

	/**
	 * Todos los ajustes de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>
	 */
	public static function all( int $project_id ): array {
		$project = ProjectRepository::find( $project_id );
		$stored  = is_array( $project['settings']['procurement'] ?? null ) ? $project['settings']['procurement'] : array();

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Un ajuste.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $key        Clave.
	 * @return mixed
	 */
	public static function get( int $project_id, string $key ) {
		return self::all( $project_id )[ $key ] ?? ( self::defaults()[ $key ] ?? null );
	}

	/**
	 * Guarda los ajustes.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $values     Valores.
	 * @return bool|WP_Error
	 */
	public static function save( int $project_id, array $values ) {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
		}
		$settings                = is_array( $project['settings'] ) ? $project['settings'] : array();
		$current                 = self::all( $project_id );
		$current['pattern']      = '' === trim( (string) ( $values['pattern'] ?? '' ) ) ? self::defaults()['pattern'] : sanitize_text_field( (string) $values['pattern'] );
		$current['decision_days'] = max( 1, min( 365, (int) ( $values['decision_days'] ?? $current['decision_days'] ) ) );
		$current['quote_age_days'] = max( 1, min( 365, (int) ( $values['quote_age_days'] ?? $current['quote_age_days'] ) ) );
		$current['request_days']  = max( 1, min( 365, (int) ( $values['request_days'] ?? $current['request_days'] ) ) );
		$current['uf_tolerance']  = max( 0.0, min( 50.0, (float) str_replace( ',', '.', (string) ( $values['uf_tolerance'] ?? $current['uf_tolerance'] ) ) ) );
		$settings['procurement'] = $current;
		$result                  = ProjectRepository::update( $project_id, array( 'settings' => $settings ), null );

		return is_wp_error( $result ) ? $result : true;
	}
}
