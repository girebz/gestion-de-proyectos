<?php
/**
 * Validaciones por pago.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones (V1) a (V8) de un pago antes de rendirlo, más la lista de
 * respaldos exigidos por el ítem. Cada hallazgo lleva una severidad:
 * "bloquea" impide enviar la rendición (los errores que SISREC no permite
 * corregir después), "advierte" se muestra sin impedir, "falta" señala un
 * respaldo obligatorio ausente.
 */
final class PaymentValidator {

	public const BLOCK = 'bloquea';
	public const WARN  = 'advierte';
	public const MISS  = 'falta';

	/**
	 * Evalúa un pago.
	 *
	 * @param array<string,mixed> $payment Pago (amount, doc_type, doc_date, paid_at, executed_at, egress_number, egress_document_id, item_slug, supplier, support, flags).
	 * @param array<string,mixed> $ctx     Contexto: start_date, end_date, platform_end_date, uf_value, invoice_threshold_uf, quotes_threshold,
	 *                                     tender_threshold_uf, items [slug => label], required_support [kind => label], supplier_registered,
	 *                                     boleta_sin_codigo (advertir|bloquear), today.
	 * @return array<int,array{code:string,severity:string,message:string}>
	 */
	public static function validate( array $payment, array $ctx ): array {
		$issues   = array();
		$amount   = (float) ( $payment['amount'] ?? 0 );
		$doc_type = (string) ( $payment['doc_type'] ?? '' );
		$doc_date = (string) ( $payment['doc_date'] ?? '' );
		$paid_at  = (string) ( $payment['paid_at'] ?? '' );
		$executed = (string) ( $payment['executed_at'] ?? $doc_date );
		$today    = (string) ( $ctx['today'] ?? gmdate( 'Y-m-d' ) );

		// V7: monto.
		if ( $amount <= 0 ) {
			$issues[] = array( 'code' => 'V7', 'severity' => self::BLOCK, 'message' => 'El monto debe ser mayor que cero, en pesos, impuesto incluido cuando no se recupera.' );
		}

		// V1: fechas dentro del plazo (la ejecución, no el egreso, fija el límite superior).
		$start = (string) ( $ctx['start_date'] ?? '' );
		$end   = (string) ( $ctx['end_date'] ?? '' );
		if ( '' !== $start && '' !== $paid_at && $paid_at < $start ) {
			$issues[] = array( 'code' => 'V1', 'severity' => self::BLOCK, 'message' => sprintf( 'El egreso (%s) es anterior al inicio del convenio (%s): no es rendible.', $paid_at, $start ) );
		}
		if ( '' !== $end && '' !== $executed && $executed > $end ) {
			$issues[] = array( 'code' => 'V1', 'severity' => self::BLOCK, 'message' => sprintf( 'La ejecución (%s) es posterior al término del plazo (%s).', $executed, $end ) );
		}
		$platform_end = (string) ( $ctx['platform_end_date'] ?? '' );
		if ( '' !== $platform_end && '' !== $paid_at && $paid_at > $platform_end ) {
			$issues[] = array( 'code' => 'V1', 'severity' => self::WARN, 'message' => sprintf( 'El egreso (%s) es posterior a la fecha de fin de actividades registrada en la plataforma (%s): pida al otorgante que la ajuste antes de rendir.', $paid_at, $platform_end ) );
		}
		if ( '' !== $doc_date && '' !== $paid_at && $paid_at < $doc_date ) {
			$issues[] = array( 'code' => 'V1', 'severity' => self::WARN, 'message' => 'El egreso es anterior a la fecha del documento; revise las fechas.' );
		}
		foreach ( array( 'doc_date' => $doc_date, 'paid_at' => $paid_at ) as $label => $value ) {
			if ( '' !== $value && $value > $today ) {
				$issues[] = array( 'code' => 'V1', 'severity' => self::BLOCK, 'message' => sprintf( 'La fecha %s está en el futuro.', 'doc_date' === $label ? 'del documento' : 'del egreso' ) );
			}
		}

		// V2: factura desde el umbral en unidades de fomento.
		$uf        = (float) ( $ctx['uf_value'] ?? 0 );
		$threshold = (float) ( $ctx['invoice_threshold_uf'] ?? 2 );
		if ( $uf > 0 && 'boleta' === $doc_type && $amount >= $threshold * $uf - 0.5 ) {
			$issues[] = array( 'code' => 'V2', 'severity' => self::BLOCK, 'message' => sprintf( 'El monto (%s) alcanza %s unidades de fomento (%s): se exige factura, no boleta.', self::money( $amount ), rtrim( rtrim( number_format( $threshold, 2, ',', '.' ), '0' ), ',' ), self::money( $threshold * $uf ) ) );
		}

		// V3: proveedor registrado en la plataforma.
		if ( array_key_exists( 'supplier_registered', $ctx ) && ! $ctx['supplier_registered'] && 'documento_extranjero' !== $doc_type ) {
			$issues[] = array( 'code' => 'V3', 'severity' => self::WARN, 'message' => 'El proveedor no está registrado en la plataforma; regístrelo antes de la carga (obligatorio para la carga masiva).' );
		}

		// V4: código del proyecto en el documento.
		if ( array_key_exists( 'has_project_code', $payment ) && ! $payment['has_project_code'] ) {
			$policy   = (string) ( $ctx['boleta_sin_codigo'] ?? 'advertir' );
			$issues[] = array( 'code' => 'V4', 'severity' => 'bloquear' === $policy ? self::BLOCK : self::WARN, 'message' => 'El documento no lleva el código ni el nombre del proyecto; el otorgante lo admite con detalle, la universidad no lo acepta en fondos por rendir.' );
		}

		// V5: comprobante de egreso.
		if ( '' !== $paid_at ) {
			if ( '' === (string) ( $payment['egress_number'] ?? '' ) ) {
				$issues[] = array( 'code' => 'V5', 'severity' => self::BLOCK, 'message' => 'Falta el número del comprobante de egreso: sin él la transacción no puede ingresarse.' );
			}
			if ( (int) ( $payment['egress_document_id'] ?? 0 ) <= 0 ) {
				$issues[] = array( 'code' => 'V5', 'severity' => self::MISS, 'message' => 'Falta el comprobante de egreso digitalizado (carpeta CE).' );
			}
		}

		// V6: ítem del perfil aprobado.
		$items = isset( $ctx['items'] ) && is_array( $ctx['items'] ) ? $ctx['items'] : array();
		$slug  = (string) ( $payment['item_slug'] ?? '' );
		if ( '' === $slug || ( ! empty( $items ) && ! isset( $items[ $slug ] ) ) ) {
			$issues[] = array( 'code' => 'V6', 'severity' => self::BLOCK, 'message' => 'El pago no está imputado a un ítem del convenio.' );
		}

		// V8: selección del proveedor según el monto.
		$support = self::support_kinds( $payment );
		$quotes  = (float) ( $ctx['quotes_threshold'] ?? 0 );
		$tender  = (float) ( $ctx['tender_threshold_uf'] ?? 0 );
		if ( 'personal' !== $slug && $amount > 0 ) {
			if ( $uf > 0 && $tender > 0 && $amount >= $tender * $uf && ! in_array( 'licitacion', $support, true ) ) {
				$issues[] = array( 'code' => 'V8', 'severity' => self::MISS, 'message' => sprintf( 'El monto supera %s unidades de fomento: se exige licitación.', number_format( $tender, 0, ',', '.' ) ) );
			} elseif ( $quotes > 0 && $amount >= $quotes && ! in_array( 'cotizaciones', $support, true ) && ! in_array( 'justificacion', $support, true ) ) {
				$issues[] = array( 'code' => 'V8', 'severity' => self::MISS, 'message' => sprintf( 'Desde %s se exigen cotizaciones evaluadas y contrato (o memorándum de justificación).', self::money( $quotes ) ) );
			} elseif ( $quotes > 0 && $amount < $quotes && 'administracion' !== $slug && ! in_array( 'cotizaciones', $support, true ) && ! in_array( 'justificacion', $support, true ) && ! in_array( 'fondo_por_rendir', $support, true ) ) {
				$issues[] = array( 'code' => 'V8', 'severity' => self::WARN, 'message' => 'Bajo el umbral se piden tres cotizaciones o un memorándum de justificación (compras menores: fondo por rendir).' );
			}
		}

		// Respaldos exigidos por el ítem.
		$required = isset( $ctx['required_support'] ) && is_array( $ctx['required_support'] ) ? $ctx['required_support'] : array();
		foreach ( $required as $kind => $label ) {
			if ( ! in_array( (string) $kind, $support, true ) ) {
				$issues[] = array( 'code' => 'R', 'severity' => self::MISS, 'message' => sprintf( 'Falta el respaldo: %s.', (string) $label ) );
			}
		}

		return $issues;
	}

	/**
	 * Tipos de respaldo presentes en el pago.
	 *
	 * @param array<string,mixed> $payment Pago.
	 * @return string[]
	 */
	public static function support_kinds( array $payment ): array {
		$kinds = array();
		foreach ( isset( $payment['support'] ) && is_array( $payment['support'] ) ? $payment['support'] : array() as $s ) {
			if ( is_array( $s ) && ! empty( $s['kind'] ) ) {
				$kinds[] = (string) $s['kind'];
			} elseif ( is_string( $s ) ) {
				$kinds[] = $s;
			}
		}

		return array_values( array_unique( $kinds ) );
	}

	/**
	 * Indica si algún hallazgo bloquea.
	 *
	 * @param array<int,array{severity:string}> $issues Hallazgos.
	 * @return bool
	 */
	public static function blocks( array $issues ): bool {
		foreach ( $issues as $i ) {
			if ( self::BLOCK === $i['severity'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Indica si los respaldos están completos (sin hallazgos "falta").
	 *
	 * @param array<int,array{severity:string}> $issues Hallazgos.
	 * @return bool
	 */
	public static function complete( array $issues ): bool {
		foreach ( $issues as $i ) {
			if ( self::MISS === $i['severity'] || self::BLOCK === $i['severity'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Formato de pesos sin decimales.
	 *
	 * @param float $amount Monto.
	 * @return string
	 */
	private static function money( float $amount ): string {
		return '$' . number_format( $amount, 0, ',', '.' );
	}
}
