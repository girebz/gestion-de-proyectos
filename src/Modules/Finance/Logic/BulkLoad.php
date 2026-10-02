<?php
/**
 * Estructura de la carga masiva de transacciones.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * La carga masiva de SISREC consta de una planilla con una fila por
 * transacción (folio correlativo de 1 a n, número de la transferencia con
 * cuyos recursos se pagó, proveedor, documento, monto, tipo de gasto) y un
 * archivo ZIP con una carpeta por folio que contiene las subcarpetas CE
 * (comprobante de egreso) y T (respaldos del gasto). Esta clase produce las
 * filas y la estructura de carpetas; la escritura del ZIP corresponde a la
 * capa que accede a los archivos.
 *
 * Los nombres y el orden de las columnas de la Planilla de Carga oficial
 * dependen del formato publicado por la plataforma; el perfil puede
 * sustituirlos con el filtro gdp_finance_bulk_columns.
 */
final class BulkLoad {

	/**
	 * Columnas por omisión: clave => encabezado.
	 *
	 * @return array<string,string>
	 */
	public static function default_columns(): array {
		return array(
			'folio'           => 'Folio',
			'transfer_no'     => 'Numero Transferencia',
			'tax_id'          => 'Rut Proveedor',
			'supplier'        => 'Nombre Proveedor',
			'doc_type'        => 'Tipo Documento',
			'doc_number'      => 'Numero Documento',
			'doc_date'        => 'Fecha Documento',
			'amount'          => 'Monto',
			'expense_type'    => 'Tipo Gasto',
			'subclass'        => 'Subclasificacion',
			'description'     => 'Descripcion',
			'egress_number'   => 'Numero Comprobante Egreso',
			'egress_date'     => 'Fecha Comprobante Egreso',
		);
	}

	/**
	 * Etiquetas de tipo de documento como las entiende la plataforma.
	 *
	 * @return array<string,string>
	 */
	public static function doc_type_labels(): array {
		return array(
			'factura'              => 'Factura',
			'factura_exenta'       => 'Factura Exenta',
			'boleta'               => 'Boleta',
			'boleta_honorarios'    => 'Boleta de Honorarios',
			'documento_extranjero' => 'Documento Extranjero',
			'otro'                 => 'Otro',
		);
	}

	/**
	 * Filas de la planilla, con folios consecutivos desde 1 en el orden recibido.
	 *
	 * @param array<int,array<string,mixed>> $payments Pagos con supplier_name, supplier_tax_id, doc_type, doc_number, doc_date,
	 *                                                  amount, installment_no, platform_type, platform_subclass, description, egress_number, paid_at.
	 * @return array<int,array<string,string>> Filas con las claves de default_columns().
	 */
	public static function rows( array $payments ): array {
		$rows  = array();
		$folio = 0;
		$types = self::doc_type_labels();
		foreach ( $payments as $p ) {
			++$folio;
			$rows[] = array(
				'folio'         => (string) $folio,
				'transfer_no'   => (string) max( 1, (int) ( $p['installment_no'] ?? 1 ) ),
				'tax_id'        => 'documento_extranjero' === (string) ( $p['doc_type'] ?? '' ) ? '' : Transliterator::tax_id_pretty( (string) ( $p['supplier_tax_id'] ?? '' ) ),
				'supplier'      => Transliterator::ascii( (string) ( $p['supplier_name'] ?? '' ) ),
				'doc_type'      => $types[ (string) ( $p['doc_type'] ?? 'otro' ) ] ?? 'Otro',
				'doc_number'    => Transliterator::ascii( (string) ( $p['doc_number'] ?? '' ) ),
				'doc_date'      => self::date( (string) ( $p['doc_date'] ?? '' ) ),
				'amount'        => (string) (int) round( (float) ( $p['amount'] ?? 0 ) ),
				'expense_type'  => ucfirst( (string) ( $p['platform_type'] ?? 'operacion' ) ),
				'subclass'      => Transliterator::ascii( (string) ( $p['platform_subclass'] ?? '' ) ),
				'description'   => Transliterator::ascii( (string) ( $p['description'] ?? '' ) ),
				'egress_number' => Transliterator::ascii( (string) ( $p['egress_number'] ?? '' ) ),
				'egress_date'   => self::date( (string) ( $p['paid_at'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * Estructura de carpetas del ZIP: folio => [CE => archivos, T => archivos].
	 *
	 * El manual de carga masiva dice que si un comprobante de egreso respalda
	 * todas las transacciones, basta con subirlo en la primera carpeta CE.
	 * Cuando un egreso respalda solo algunas, el archivo se copia en la
	 * carpeta CE de cada folio que respalda, para que ninguna transacción
	 * aparezca sin comprobante en el detalle de la carga. El archivo de un
	 * egreso puede venir en cualquiera de los pagos que comparten su número.
	 *
	 * @param array<int,array<string,mixed>> $payments Pagos con egress_file (ruta o null), support_files (lista de rutas), egress_number.
	 * @return array<int,array{CE:array<int,string>,T:array<int,string>}>
	 */
	public static function folders( array $payments ): array {
		$files      = self::egress_files( $payments );
		$single_all = self::single_egress( $payments );
		$out        = array();
		$folio      = 0;
		foreach ( $payments as $p ) {
			++$folio;
			$number = trim( (string) ( $p['egress_number'] ?? '' ) );
			$file   = ! empty( $p['egress_file'] ) ? (string) $p['egress_file'] : ( '' !== $number ? ( $files[ $number ] ?? '' ) : '' );
			$out[ $folio ] = array(
				'CE' => '' !== $file && ! ( $single_all && $folio > 1 ) ? array( $file ) : array(),
				'T'  => array_values( array_map( 'strval', isset( $p['support_files'] ) && is_array( $p['support_files'] ) ? $p['support_files'] : array() ) ),
			);
		}

		return $out;
	}

	/**
	 * Primer archivo disponible por número de egreso.
	 *
	 * @param array<int,array<string,mixed>> $payments Pagos.
	 * @return array<string,string>
	 */
	public static function egress_files( array $payments ): array {
		$files = array();
		foreach ( $payments as $p ) {
			$number = trim( (string) ( $p['egress_number'] ?? '' ) );
			if ( '' !== $number && ! empty( $p['egress_file'] ) && ! isset( $files[ $number ] ) ) {
				$files[ $number ] = (string) $p['egress_file'];
			}
		}

		return $files;
	}

	/**
	 * Indica si un mismo egreso respalda todas las transacciones de la carga.
	 *
	 * @param array<int,array<string,mixed>> $payments Pagos.
	 * @return bool
	 */
	public static function single_egress( array $payments ): bool {
		if ( count( $payments ) < 2 ) {
			return false;
		}
		$numbers = array();
		foreach ( $payments as $p ) {
			$numbers[] = trim( (string) ( $p['egress_number'] ?? '' ) );
		}

		return '' !== $numbers[0] && 1 === count( array_unique( $numbers ) );
	}

	/**
	 * Validaciones previas a la carga.
	 *
	 * @param array<int,array<string,mixed>> $payments     Pagos (con supplier_registered, installment_no, egress_number, egress_file, support_files, doc_type).
	 * @param array<int,float>               $amounts      Montos de las cuotas.
	 * @param array<int,float>               $already      Ya rendido por cuota (sin esta carga).
	 * @return string[] Problemas encontrados.
	 */
	public static function problems( array $payments, array $amounts, array $already = array() ): array {
		$problems = array();
		$by_inst  = $already;
		$files    = self::egress_files( $payments );
		$folio    = 0;
		foreach ( $payments as $p ) {
			++$folio;
			$doc_type = (string) ( $p['doc_type'] ?? '' );
			if ( array_key_exists( 'supplier_registered', $p ) && ! $p['supplier_registered'] && 'documento_extranjero' !== $doc_type ) {
				$problems[] = sprintf( 'Folio %d: el proveedor no está registrado en la plataforma.', $folio );
			}
			$k = (int) ( $p['installment_no'] ?? 0 );
			if ( $k <= 0 ) {
				$problems[] = sprintf( 'Folio %d: sin número de transferencia.', $folio );
			} else {
				$by_inst[ $k ] = (float) ( $by_inst[ $k ] ?? 0 ) + (float) ( $p['amount'] ?? 0 );
			}
			$number = trim( (string) ( $p['egress_number'] ?? '' ) );
			if ( '' === $number ) {
				$problems[] = sprintf( 'Folio %d: sin número de comprobante de egreso.', $folio );
			} elseif ( empty( $p['egress_file'] ) && ! isset( $files[ $number ] ) ) {
				$problems[] = sprintf( 'Folio %d: falta el archivo del comprobante de egreso %s (carpeta CE).', $folio, $number );
			}
			if ( empty( $p['support_files'] ) ) {
				$problems[] = sprintf( 'Folio %d: sin respaldos del gasto (carpeta T).', $folio );
			}
		}
		foreach ( Imputation::excess( $by_inst, $amounts ) as $k => $excess ) {
			$problems[] = sprintf( 'La cuota %d quedaría rendida por %s más de lo transferido.', $k, '$' . number_format( $excess, 0, ',', '.' ) );
		}

		return $problems;
	}

	/**
	 * Fecha en formato día/mes/año.
	 *
	 * @param string $date Fecha AAAA-MM-DD.
	 * @return string
	 */
	public static function date( string $date ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $date, $m ) ) {
			return '';
		}

		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}
}
