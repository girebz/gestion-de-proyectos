<?php
/**
 * Ítems del convenio con asignado por fuente.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Finance\Profiles\Profiles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Los ítems del fondo, con su asignado vigente por fuente (tras las
 * reitemizaciones aprobadas), su proyección a la clasificación de la
 * plataforma y el enlace con la partida del módulo de adquisiciones.
 */
final class ItemRepository extends Repository {

	protected const TABLE  = 'finance_items';
	protected const ENTITY = 'finance_item';
	protected const CASTS  = array( 'assigned_fund' => 'float', 'assigned_cash' => 'float', 'assigned_inkind' => 'float', 'sort_order' => 'int' );

	/**
	 * Ítems del proyecto en orden.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id ): array {
		return self::for_project( $project_id, 'sort_order ASC, id ASC' );
	}

	/**
	 * Ítems indexados por slug.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,array<string,mixed>>
	 */
	public static function by_slug( int $project_id ): array {
		$out = array();
		foreach ( self::all( $project_id ) as $i ) {
			$out[ (string) $i['slug'] ] = $i;
		}

		return $out;
	}

	/**
	 * Etiquetas por slug.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,string>
	 */
	public static function labels( int $project_id ): array {
		$out = array();
		foreach ( self::all( $project_id ) as $i ) {
			$out[ (string) $i['slug'] ] = (string) $i['label'];
		}

		return $out;
	}

	/**
	 * Crea los ítems del perfil que falten, sin montos (idempotente).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $profile    Perfil.
	 * @return int Ítems creados.
	 */
	public static function seed( int $project_id, string $profile ): int {
		$existing = self::by_slug( $project_id );
		$created  = 0;
		$order    = 0;
		foreach ( Profiles::get( $profile )->items() as $slug => $item ) {
			++$order;
			if ( isset( $existing[ $slug ] ) ) {
				continue;
			}
			$id = self::insert(
				array(
					'project_id'        => $project_id,
					'slug'              => $slug,
					'label'             => $item['label'],
					'item_group'        => $item['group'],
					'platform_type'     => $item['platform_type'],
					'platform_subclass' => $item['platform_subclass'],
					'budget_line'       => $item['budget_line'],
					'cap_rule'          => $item['cap_rule'],
					'sort_order'        => $order,
				)
			);
			if ( ! is_wp_error( $id ) ) {
				++$created;
			}
		}

		return $created;
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
				'slug'              => 'key',
				'label'             => 'text',
				'item_group'        => 'key',
				'assigned_fund'     => 'money',
				'assigned_cash'     => 'money',
				'assigned_inkind'   => 'money',
				'platform_type'     => 'key',
				'platform_subclass' => 'text',
				'budget_line'       => 'key',
				'cap_rule'          => 'key',
				'sort_order'        => 'int',
				'notes'             => 'textarea',
			),
			$errors
		);
		foreach ( array( 'assigned_fund', 'assigned_cash', 'assigned_inkind' ) as $f ) {
			if ( isset( $clean[ $f ] ) && $clean[ $f ] < 0 ) {
				$errors->add( $f, __( 'El asignado no puede ser negativo.', 'gestion-de-proyectos' ) );
			}
		}
		if ( isset( $clean['platform_type'] ) && ! in_array( $clean['platform_type'], array( 'personal', 'operacion', 'inversion' ), true ) ) {
			$errors->add( 'platform_type', __( 'El tipo de gasto de la plataforma debe ser personal, operación o inversión.', 'gestion-de-proyectos' ) );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea o actualiza un ítem por slug.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos (con slug).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( int $project_id, array $data ) {
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['slug'] ) ) {
			return new WP_Error( 'slug', __( 'Indique el identificador del ítem.', 'gestion-de-proyectos' ) );
		}
		$existing = self::by_slug( $project_id )[ $clean['slug'] ] ?? null;
		if ( $existing ) {
			return self::update_row( $existing['id'], $clean, null, sprintf( 'Ítem %s actualizado', $clean['slug'] ) );
		}
		if ( empty( $clean['label'] ) ) {
			$clean['label'] = $clean['slug'];
		}
		$clean['project_id'] = $project_id;
		$id                  = self::insert( $clean, sprintf( 'Ítem %s creado', $clean['slug'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return self::find( $id );
	}

	/**
	 * Elimina un ítem.
	 *
	 * @param int $id Ítem.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$current = self::find( $id );

		return $current ? self::delete_row( $id, sprintf( 'Ítem %s eliminado', $current['slug'] ) ) : false;
	}

	/**
	 * Asignado de un ítem en una fuente.
	 *
	 * @param array<string,mixed> $item   Ítem.
	 * @param string              $source Fuente.
	 * @return float
	 */
	public static function assigned( array $item, string $source ): float {
		switch ( $source ) {
			case 'pecuniario':
				return (float) $item['assigned_cash'];
			case 'no_pecuniario':
				return (float) $item['assigned_inkind'];
			default:
				return (float) $item['assigned_fund'];
		}
	}
}
