<?php
/**
 * Movimientos del centro de costo (cartola).
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Core\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Los recursos no tienen cuenta exclusiva: ingresan a la cuenta de la
 * universidad y se controlan en un centro de costo cuyo detalle entrega la
 * Dirección de Finanzas. El módulo importa esos movimientos, empareja cada
 * uno con un pago o una transferencia y deja los demás por aclarar.
 */
final class LedgerRepository extends Repository {

	protected const TABLE  = 'finance_ledger';
	protected const ENTITY = 'finance_ledger';
	protected const CASTS  = array( 'entry_date' => 'date', 'debit' => 'float', 'credit' => 'float', 'payment_id' => 'int', 'installment_no' => 'int' );

	public const KINDS    = array( 'pago', 'transferencia', 'aporte', 'reintegro', 'cargo_bancario', 'otro' );
	public const STATUSES = array( 'conciliado', 'por_aclarar', 'excluido' );

	/**
	 * Etiquetas.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function labels(): array {
		return array(
			'kinds'    => array( 'pago' => __( 'Pago', 'gestion-de-proyectos' ), 'transferencia' => __( 'Transferencia del otorgante', 'gestion-de-proyectos' ), 'aporte' => __( 'Aporte pecuniario', 'gestion-de-proyectos' ), 'reintegro' => __( 'Reintegro al otorgante', 'gestion-de-proyectos' ), 'cargo_bancario' => __( 'Cargo bancario (no financiable)', 'gestion-de-proyectos' ), 'otro' => __( 'Otro', 'gestion-de-proyectos' ) ),
			'statuses' => array( 'conciliado' => __( 'Conciliado', 'gestion-de-proyectos' ), 'por_aclarar' => __( 'Por aclarar', 'gestion-de-proyectos' ), 'excluido' => __( 'Excluido', 'gestion-de-proyectos' ) ),
		);
	}

	/**
	 * Movimientos del proyecto.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $source     Fuente (vacío = todas).
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $project_id, string $source = '' ): array {
		return '' !== $source ? self::for_project( $project_id, 'entry_date ASC, id ASC', array( 'source = %s' ), array( $source ) ) : self::for_project( $project_id, 'entry_date ASC, id ASC' );
	}

	/**
	 * Saldo de la cartola de una fuente (abonos menos cargos, sin los excluidos) y totales por tipo.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $source     Fuente.
	 * @return array{balance:float,returned:float,bank_charges:float,unmatched:int,count:int}
	 */
	public static function summary( int $project_id, string $source = 'fondo' ): array {
		$balance   = 0.0;
		$returned  = 0.0;
		$charges   = 0.0;
		$unmatched = 0;
		$count     = 0;
		foreach ( self::all( $project_id, $source ) as $e ) {
			if ( 'excluido' === $e['status'] ) {
				continue;
			}
			++$count;
			$balance += (float) $e['credit'] - (float) $e['debit'];
			if ( 'reintegro' === $e['kind'] ) {
				$returned += (float) $e['debit'];
			}
			if ( 'cargo_bancario' === $e['kind'] ) {
				$charges += (float) $e['debit'];
			}
			if ( 'por_aclarar' === $e['status'] ) {
				++$unmatched;
			}
		}

		return array( 'balance' => round( $balance, 2 ), 'returned' => round( $returned, 2 ), 'bank_charges' => round( $charges, 2 ), 'unmatched' => $unmatched, 'count' => $count );
	}

	/**
	 * Valida un movimiento.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $data ) {
		$errors = new WP_Error();
		$clean  = self::clean( $data, array( 'source' => 'key', 'entry_date' => 'date', 'reference' => 'text', 'description' => 'text', 'debit' => 'money', 'credit' => 'money', 'kind' => 'key', 'payment_id' => 'int', 'installment_no' => 'int', 'status' => 'key', 'batch' => 'text' ), $errors );
		if ( isset( $clean['kind'] ) && ! in_array( $clean['kind'], self::KINDS, true ) ) {
			$errors->add( 'kind', __( 'Tipo de movimiento no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['status'] ) && ! in_array( $clean['status'], self::STATUSES, true ) ) {
			$errors->add( 'status', __( 'Estado de conciliación no válido.', 'gestion-de-proyectos' ) );
		}
		if ( isset( $clean['source'] ) && ! in_array( $clean['source'], PaymentRepository::SOURCES, true ) ) {
			$errors->add( 'source', __( 'La fuente debe ser fondo o pecuniario.', 'gestion-de-proyectos' ) );
		}

		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Registra un movimiento.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $data       Datos.
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		$clean = self::validate( array_merge( array( 'source' => 'fondo', 'kind' => 'otro', 'status' => 'por_aclarar', 'debit' => 0, 'credit' => 0 ), $data ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['entry_date'] ) ) {
			return new WP_Error( 'entry_date', __( 'Indique la fecha del movimiento.', 'gestion-de-proyectos' ) );
		}
		$clean['project_id'] = $project_id;
		$clean['created_at'] = current_time( 'mysql', true );

		return self::insert( $clean );
	}

	/**
	 * Importa movimientos desde filas ya mapeadas (fecha, referencia, glosa, cargo, abono) y
	 * los empareja automáticamente con pagos por monto y fecha cercana.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param string                         $source     Fuente.
	 * @param array<int,array<string,mixed>> $rows       Filas.
	 * @param string                         $batch      Identificador del lote.
	 * @return array{imported:int,matched:int,errors:string[]}
	 */
	public static function import( int $project_id, string $source, array $rows, string $batch ): array {
		$payments = PaymentRepository::paid( $project_id, $source );
		$by_id    = array();
		foreach ( $payments as $p ) {
			$by_id[ (int) $p['id'] ] = $p;
		}
		$used = array();
		foreach ( self::all( $project_id, $source ) as $e ) {
			if ( $e['payment_id'] <= 0 ) {
				continue;
			}
			$used[ $e['payment_id'] ] = true;
			// Un cargo emparejado con un grupo de pagos del mismo egreso apunta al primero; el resto del egreso también está usado.
			$first = $by_id[ $e['payment_id'] ] ?? null;
			if ( $first && abs( (float) $first['amount'] - (float) $e['debit'] ) > 0.5 && '' !== trim( (string) $first['egress_number'] ) ) {
				foreach ( $payments as $p ) {
					if ( trim( (string) $p['egress_number'] ) === trim( (string) $first['egress_number'] ) ) {
						$used[ (int) $p['id'] ] = true;
					}
				}
			}
		}
		$amounts  = InstallmentRepository::all( $project_id );
		$imported = 0;
		$matched  = 0;
		$errors   = array();
		foreach ( $rows as $index => $r ) {
			$data = array( 'source' => $source, 'entry_date' => $r['entry_date'] ?? '', 'reference' => $r['reference'] ?? '', 'description' => $r['description'] ?? '', 'debit' => $r['debit'] ?? 0, 'credit' => $r['credit'] ?? 0, 'batch' => $batch, 'kind' => 'otro', 'status' => 'por_aclarar' );
			$debit  = (float) ( self::number( $data['debit'] ) ?? 0 );
			$credit = (float) ( self::number( $data['credit'] ) ?? 0 );
			if ( $debit > 0 ) {
				$match = self::match_debit( $payments, $used, $debit, (string) $data['reference'], (string) $data['entry_date'] );
				if ( $match ) {
					$data['kind']       = 'pago';
					$data['payment_id'] = $match[0];
					$data['status']     = 'conciliado';
					foreach ( $match as $payment_id ) {
						$used[ $payment_id ] = true;
					}
				} elseif ( preg_match( '/comisi[oó]n|mantenci[oó]n|cargo bancario|impuesto|intereses/iu', (string) $data['description'] ) ) {
					$data['kind'] = 'cargo_bancario';
				}
			} elseif ( $credit > 0 && 'fondo' === $source ) {
				foreach ( $amounts as $i ) {
					if ( abs( (float) $i['amount'] - $credit ) <= 0.5 ) {
						$data['kind']           = 'transferencia';
						$data['installment_no'] = $i['number'];
						$data['status']         = 'conciliado';
						break;
					}
				}
			} elseif ( $credit > 0 ) {
				foreach ( $amounts as $i ) {
					if ( abs( (float) $i['cash_amount'] - $credit ) <= 0.5 ) {
						$data['kind']           = 'aporte';
						$data['installment_no'] = $i['number'];
						$data['status']         = 'conciliado';
						break;
					}
				}
			}
			$id = self::create( $project_id, $data );
			if ( is_wp_error( $id ) ) {
				$errors[] = sprintf( 'Fila %d: %s', (int) $index + 1, $id->get_error_message() );
				continue;
			}
			++$imported;
			if ( 'conciliado' === $data['status'] ) {
				++$matched;
			}
		}

		return array( 'imported' => $imported, 'matched' => $matched, 'errors' => $errors );
	}

	/**
	 * Pagos que explican un cargo, en orden de confianza: un pago con el mismo
	 * número de egreso y monto; varios pagos del mismo egreso cuya suma es el
	 * cargo (la universidad suele pagar varias boletas con un solo egreso); o
	 * un pago del mismo monto con fecha de pago a quince días o menos.
	 *
	 * @param array<int,array<string,mixed>> $payments  Pagos pagados de la fuente.
	 * @param array<int,bool>                $used      Pagos ya emparejados.
	 * @param float                          $debit     Cargo.
	 * @param string                         $reference Referencia del movimiento.
	 * @param string                         $date      Fecha del movimiento.
	 * @return int[] Pagos emparejados (el primero queda como referencia del movimiento); vacío si no hay.
	 */
	public static function match_debit( array $payments, array $used, float $debit, string $reference, string $date ): array {
		$reference = trim( $reference );
		$group     = array();
		$sum       = 0.0;
		if ( '' !== $reference ) {
			foreach ( $payments as $p ) {
				if ( isset( $used[ $p['id'] ] ) || trim( (string) $p['egress_number'] ) !== $reference ) {
					continue;
				}
				if ( abs( (float) $p['amount'] - $debit ) <= 0.5 ) {
					return array( (int) $p['id'] );
				}
				$group[] = (int) $p['id'];
				$sum    += (float) $p['amount'];
			}
			if ( count( $group ) > 1 && abs( $sum - $debit ) <= 0.5 ) {
				return $group;
			}
		}
		foreach ( $payments as $p ) {
			if ( isset( $used[ $p['id'] ] ) || abs( (float) $p['amount'] - $debit ) > 0.5 ) {
				continue;
			}
			if ( $p['paid_at'] && '' !== $date && abs( strtotime( (string) $p['paid_at'] ) - strtotime( $date ) ) > 15 * 86400 ) {
				continue;
			}
			return array( (int) $p['id'] );
		}

		return array();
	}

	/**
	 * Actualiza un movimiento (emparejamiento manual, tipo, estado).
	 *
	 * @param int                 $id   Movimiento.
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( int $id, array $data ) {
		$clean = self::validate( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		return self::update_row( $id, $clean );
	}

	/**
	 * Elimina un movimiento.
	 *
	 * @param int $id Movimiento.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return self::delete_row( $id );
	}

	/**
	 * Elimina un lote completo.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $batch      Lote.
	 * @return int
	 */
	public static function delete_batch( int $project_id, string $batch ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->delete( Schema::table( 'finance_ledger' ), array( 'project_id' => $project_id, 'batch' => $batch ), array( '%d', '%s' ) );
	}
}
