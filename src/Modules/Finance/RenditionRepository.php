<?php
/**
 * Rendiciones por período y fuente.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Finance\Logic\Deadlines;
use GDP\Modules\Finance\Profiles\Profiles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Una rendición agrupa los pagos de un período y una fuente. Su estado
 * recorre un tramo interno (preparada, enviada a la universidad), uno del
 * ejecutor en la plataforma (en carga, en autenticación, en firma interna)
 * y uno del otorgante (rendida, en revisión, aprobada, aprobada
 * parcialmente, devuelta). Cada cambio se declara con fecha y queda como
 * evento.
 */
final class RenditionRepository extends Repository {

	protected const TABLE  = 'finance_renditions';
	protected const ENTITY = 'finance_rendition';
	protected const CASTS  = array(
		'internal_due'       => 'date',
		'platform_due'       => 'date',
		'fix_due'            => 'date',
		'sent_internal_at'   => 'date',
		'loaded_at'          => 'date',
		'sent_at'            => 'date',
		'approved_at'        => 'date',
		'returned_at'        => 'date',
		'report_document_id' => 'int',
		'letter_document_id' => 'int',
		'created_by'         => 'int',
	);

	public const KINDS      = array( 'mensual', 'sin_movimiento', 'regularizacion', 'final' );
	public const SUBMITTED  = array( 'rendida', 'en_revision', 'aprobada', 'aprobada_parcial', 'devuelta' );
	public const CLOSED     = array( 'aprobada' );

	/**
	 * Estados válidos.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array_keys( Profiles::get( '' )->rendition_statuses() );
	}

	/**
	 * Rendiciones del proyecto, por período.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $source     Fuente (vacío = todas).
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id, string $source = '' ): array {
		return '' !== $source ? self::for_project( $project_id, 'period ASC, id ASC', array( 'source = %s' ), array( $source ) ) : self::for_project( $project_id, 'period ASC, id ASC' );
	}

	/**
	 * Rendición de un período, fuente y tipo.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $period     AAAA-MM.
	 * @param string $source     Fuente.
	 * @param string $kind       Tipo (vacío = mensual o sin movimiento).
	 * @return array<string,mixed>|null
	 */
	public static function for_period( int $project_id, string $period, string $source = 'fondo', string $kind = '' ): ?array {
		foreach ( self::all( $project_id, $source ) as $r ) {
			if ( $r['period'] !== $period ) {
				continue;
			}
			if ( '' === $kind && in_array( $r['kind'], array( 'mensual', 'sin_movimiento' ), true ) ) {
				return $r;
			}
			if ( $r['kind'] === $kind ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * Plazos de un período según las reglas del proyecto.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $period     AAAA-MM.
	 * @return array{internal:string,platform:string}
	 */
	public static function due_dates( int $project_id, string $period ): array {
		$profile  = AgreementRepository::get( $project_id )['profile'];
		$internal = (int) RulesRepository::value( $project_id, $profile, 'plazo_respaldo_interno', '8' );
		$platform = (int) RulesRepository::value( $project_id, $profile, 'plazo_sisrec', '15' );

		return Deadlines::rendition_due( $period, max( 1, $internal ), max( 1, $platform ), Calendar::for_project( $project_id ) );
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
				'source'             => 'key',
				'period'             => 'period',
				'kind'               => 'key',
				'status'             => 'key',
				'internal_due'       => 'date',
				'platform_due'       => 'date',
				'fix_due'            => 'date',
				'sent_internal_at'   => 'date',
				'loaded_at'          => 'date',
				'sent_at'            => 'date',
				'approved_at'        => 'date',
				'returned_at'        => 'date',
				'report_document_id' => 'int',
				'letter_document_id' => 'int',
				'notes'              => 'textarea',
			),
			$errors
		);
		if ( isset( $clean['source'] ) && ! in_array( $clean['source'], PaymentRepository::SOURCES, true ) ) {
			$errors->add( 'source', __( 'La fuente debe ser fondo o pecuniario.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['kind'] ) && ! in_array( $clean['kind'], self::KINDS, true ) ) {
			$errors->add( 'kind', __( 'Tipo de rendición no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['status'] ) && ! in_array( $clean['status'], self::statuses(), true ) ) {
			$errors->add( 'status', __( 'Estado de rendición no válido.', 'gestion-de-proyectos' ) );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Crea una rendición (o devuelve la existente del período).
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       period, source, kind, notes.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		$clean = self::validate( array_merge( array( 'source' => 'fondo', 'kind' => 'mensual', 'status' => 'preparada' ), $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['period'] ) ) {
			return new WP_Error( 'period', __( 'Indique el período (AAAA-MM).', 'gestion-de-proyectos' ) );
		}
		$existing = self::for_period( $project_id, $clean['period'], $clean['source'], in_array( $clean['kind'], array( 'mensual', 'sin_movimiento' ), true ) ? '' : $clean['kind'] );
		if ( $existing ) {
			return $existing['id'];
		}
		if ( empty( $clean['internal_due'] ) || empty( $clean['platform_due'] ) ) {
			$due                   = self::due_dates( $project_id, $clean['period'] );
			$clean['internal_due'] = $clean['internal_due'] ?? $due['internal'];
			$clean['platform_due'] = $clean['platform_due'] ?? $due['platform'];
		}
		$clean['project_id'] = $project_id;

		return self::insert( $clean, sprintf( 'Rendición %s (%s) creada', $clean['period'], $clean['kind'] ) );
	}

	/**
	 * Actualiza una rendición.
	 *
	 * @param int                 $id               Rendición.
	 * @param array<string,mixed> $data             Datos.
	 * @param int|null            $expected_version Versión esperada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data, ?int $expected_version = null ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La rendición no existe.', 'gestion-de-proyectos' ) );
		}
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		return self::update_row( $id, $clean, $expected_version, sprintf( 'Rendición %s actualizada', $current['period'] ) );
	}

	/**
	 * Declara un estado con fecha, nota y documento; actualiza las fechas de
	 * la rendición y los estados de sus pagos, y registra el evento.
	 *
	 * @param int    $id          Rendición.
	 * @param string $status      Estado.
	 * @param string $date        Fecha.
	 * @param string $note        Nota.
	 * @param int    $document_id Documento (informe firmado, constancia).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function declare( int $id, string $status, string $date, string $note = '', int $document_id = 0 ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La rendición no existe.', 'gestion-de-proyectos' ) );
		}
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new WP_Error( 'status', __( 'Estado de rendición no válido.', 'gestion-de-proyectos' ) );
		}
		$date = self::date( $date );
		if ( ! $date ) {
			return new WP_Error( 'date', __( 'Fecha no válida.', 'gestion-de-proyectos' ) );
		}
		$data = array( 'status' => $status );
		switch ( $status ) {
			case 'enviada_universidad':
				$data['sent_internal_at'] = $date;
				break;
			case 'en_carga':
				$data['loaded_at'] = $date;
				break;
			case 'rendida':
				$data['sent_at'] = $date;
				break;
			case 'aprobada':
			case 'aprobada_parcial':
				$data['approved_at'] = $date;
				break;
			case 'devuelta':
				$data['returned_at'] = $date;
				$profile             = AgreementRepository::get( $current['project_id'] )['profile'];
				$days                = (int) RulesRepository::value( $current['project_id'], $profile, 'plazo_subsanacion', '15' );
				$data['fix_due']     = Calendar::for_project( $current['project_id'] )->add_working_days( $date, max( 1, $days ) );
				break;
		}
		if ( $document_id > 0 && in_array( $status, array( 'rendida', 'aprobada', 'aprobada_parcial' ), true ) ) {
			$data['report_document_id'] = $document_id;
		}
		$result = self::update_row( $id, $data, null, sprintf( 'Rendición %s: %s', $current['period'], $status ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		EventRepository::log( $current['project_id'], 'rendition', $id, 'estado', $status, $date, $note, $document_id );
		self::cascade( $id, $status, $date );

		return $result;
	}

	/**
	 * Propaga el estado de la rendición a sus pagos.
	 *
	 * @param int    $id     Rendición.
	 * @param string $status Estado.
	 * @param string $date   Fecha.
	 * @return void
	 */
	private static function cascade( int $id, string $status, string $date ): void {
		$current = self::find( $id );
		if ( ! $current ) {
			return;
		}
		foreach ( self::cascade_targets( $id, $status ) as $payment_id => $target ) {
			PaymentRepository::set_status( $payment_id, $target, $date, sprintf( 'Rendición %s: %s', $current['period'], $status ) );
		}
	}

	/**
	 * Pagos cuyo estado cambia al declarar un estado de la rendición:
	 * rendida lleva los pagados y corregidos a rendido; aprobada o aprobada
	 * parcialmente lleva los rendidos y corregidos a aprobado; aprobada,
	 * además, los observados.
	 *
	 * @param int    $id     Rendición.
	 * @param string $status Estado declarado.
	 * @return array<int,string> Pago => estado nuevo.
	 */
	public static function cascade_targets( int $id, string $status ): array {
		$current = self::find( $id );
		if ( ! $current ) {
			return array();
		}
		$out = array();
		foreach ( PaymentRepository::list( $current['project_id'], array( 'rendition_id' => $id ) ) as $p ) {
			$target = null;
			if ( 'rendida' === $status && in_array( $p['status'], array( 'pagado', 'corregido' ), true ) ) {
				$target = 'rendido';
			} elseif ( in_array( $status, array( 'aprobada', 'aprobada_parcial' ), true ) && in_array( $p['status'], array( 'rendido', 'corregido' ), true ) ) {
				$target = 'aprobado';
			} elseif ( 'aprobada' === $status && 'observado' === $p['status'] ) {
				$target = 'aprobado';
			}
			if ( null !== $target ) {
				$out[ (int) $p['id'] ] = $target;
			}
		}

		return $out;
	}

	/**
	 * Asigna a la rendición los pagos pagados del período y la fuente que aún no tienen rendición.
	 *
	 * @param int $id Rendición.
	 * @return int Pagos asignados.
	 */
	public static function collect_payments( int $id ): int {
		$current = self::find( $id );
		if ( ! $current || ! in_array( $current['status'], array( 'preparada', 'enviada_universidad', 'en_carga' ), true ) ) {
			return 0;
		}
		$count = 0;
		$folio = 0;
		foreach ( PaymentRepository::list( $current['project_id'], array( 'rendition_id' => $id ) ) as $p ) {
			$folio = max( $folio, (int) $p['folio'] );
		}
		foreach ( PaymentRepository::list( $current['project_id'], array( 'source' => $current['source'], 'period' => $current['period'], 'status' => 'pagado' ) ) as $p ) {
			if ( $p['rendition_id'] > 0 ) {
				continue;
			}
			++$folio;
			$result = PaymentRepository::update( $p['id'], array( 'rendition_id' => $id, 'folio' => $folio ) );
			if ( ! is_wp_error( $result ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Elimina una rendición que no se ha presentado; desvincula sus pagos.
	 *
	 * @param int $id Rendición.
	 * @return bool|WP_Error
	 */
	public static function delete( int $id ) {
		$current = self::find( $id );
		if ( ! $current ) {
			return new WP_Error( 'not_found', __( 'La rendición no existe.', 'gestion-de-proyectos' ) );
		}
		if ( in_array( $current['status'], self::SUBMITTED, true ) ) {
			return new WP_Error( 'submitted', __( 'Una rendición presentada no se elimina.', 'gestion-de-proyectos' ) );
		}
		foreach ( PaymentRepository::list( $current['project_id'], array( 'rendition_id' => $id ) ) as $p ) {
			PaymentRepository::update( $p['id'], array( 'rendition_id' => 0, 'folio' => 0 ) );
		}

		return self::delete_row( $id, sprintf( 'Rendición %s eliminada', $current['period'] ) );
	}

	/**
	 * Rendiciones exigibles atrasadas: períodos vencidos sin rendición presentada.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $today      Hoy.
	 * @param string $source     Fuente.
	 * @return string[] Períodos.
	 */
	public static function overdue( int $project_id, string $today, string $source = 'fondo' ): array {
		$agreement = AgreementRepository::get( $project_id );
		$start     = (string) ( $agreement['start_date'] ?? '' );
		if ( '' === $start ) {
			return array();
		}
		$by_period = array();
		foreach ( self::all( $project_id, $source ) as $r ) {
			if ( in_array( $r['kind'], array( 'mensual', 'sin_movimiento' ), true ) ) {
				$by_period[ $r['period'] ] = $r;
			}
		}
		$out  = array();
		$last = Deadlines::add_months( substr( $today, 0, 7 ), -1 );
		$end  = (string) ( $agreement['end_date'] ?? '' );
		if ( '' !== $end && substr( $end, 0, 7 ) < $last ) {
			$last = substr( $end, 0, 7 );
		}
		foreach ( Deadlines::months_between( substr( $start, 0, 7 ), $last ) as $period ) {
			$due = isset( $by_period[ $period ] ) && $by_period[ $period ]['platform_due'] ? $by_period[ $period ]['platform_due'] : self::due_dates( $project_id, $period )['platform'];
			if ( $due >= $today ) {
				continue;
			}
			if ( ! isset( $by_period[ $period ] ) || ! in_array( $by_period[ $period ]['status'], self::SUBMITTED, true ) ) {
				$out[] = $period;
			}
		}

		return $out;
	}
}
