<?php
/**
 * Condiciones de giro de una cuota.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * La cuota k+1 se gira cuando se cumplen todas las condiciones (G1) a (G7):
 * rendición del 100 % de lo transferido o garantía por la fracción no
 * rendida; informe de avance de productos aprobado; aporte pecuniario de la
 * cuota enterado y acreditado; carta de solicitud; sin rendiciones exigibles
 * atrasadas; comprobantes de ingreso enviados; ventana del programa de
 * desembolso.
 */
final class InstallmentConditions {

	/**
	 * Evalúa las condiciones.
	 *
	 * @param array<string,mixed> $ctx Contexto (ver código).
	 * @return array<int,array{key:string,label:string,ok:bool|null,detail:string}>
	 */
	public static function evaluate( array $ctx ): array {
		$next      = (int) ( $ctx['next'] ?? 0 );
		$target    = (float) ( $ctx['target'] ?? 0 );
		$rendered  = (float) ( $ctx['rendered'] ?? 0 );
		$approved  = (float) ( $ctx['approved'] ?? 0 );
		$criterion = (string) ( $ctx['criterion'] ?? 'ambos' );
		$guarantee = (float) ( $ctx['guarantee'] ?? 0 );
		$out       = array();

		$render_gap  = max( $target - $rendered, 0.0 );
		$approve_gap = max( $target - $approved, 0.0 );
		$gap         = 'presentado' === $criterion ? $render_gap : ( 'aprobado' === $criterion ? $approve_gap : max( $render_gap, $approve_gap ) );
		$ok1         = $gap <= 0.5 || $guarantee >= $gap - 0.5;
		$detail1     = sprintf( 'Transferido hasta la cuota %d: %s; rendido %s; aprobado %s.', $next - 1, self::money( $target ), self::money( $rendered ), self::money( $approved ) );
		if ( $gap > 0.5 ) {
			$detail1 .= sprintf( ' Falta por rendir %s y por aprobar %s (criterio: %s).', self::money( $render_gap ), self::money( $approve_gap ), $criterion );
			$detail1 .= $guarantee > 0 ? sprintf( ' Garantía vigente por %s.', self::money( $guarantee ) ) : ' Sin garantía.';
		}
		$out[] = array( 'key' => 'G1', 'label' => 'Rendición de lo transferido (o garantía por la fracción no rendida)', 'ok' => $ok1, 'detail' => $detail1 );

		$report = $ctx['report_approved'] ?? null;
		$out[]  = array(
			'key'    => 'G2',
			'label'  => sprintf( 'Informe de avance de productos N.º %d aprobado', (int) ( $ctx['report_no'] ?? max( $next - 1, 1 ) ) ),
			'ok'     => null === $report ? null : (bool) $report,
			'detail' => null === $report ? 'Sin registro del informe: declare su aprobación con el oficio correspondiente.' : ( $report ? 'Informe aprobado por la contraparte.' : 'Informe pendiente o no aprobado.' ),
		);

		$cash_amount   = (float) ( $ctx['cash_amount'] ?? 0 );
		$cash_received = ! empty( $ctx['cash_received'] );
		$out[]         = array(
			'key'    => 'G3',
			'label'  => sprintf( 'Aporte pecuniario de la cuota %d enterado y acreditado', $next ),
			'ok'     => $cash_amount <= 0.5 ? true : $cash_received,
			'detail' => $cash_amount <= 0.5 ? 'La cuota no exige aporte pecuniario.' : ( $cash_received ? sprintf( 'Aporte de %s acreditado el %s.', self::money( $cash_amount ), (string) ( $ctx['cash_received_at'] ?? '' ) ) : sprintf( 'Falta enterar y acreditar %s con comprobante de ingreso.', self::money( $cash_amount ) ) ),
		);

		$letter = $ctx['request_letter'] ?? null;
		$out[]  = array(
			'key'    => 'G4',
			'label'  => 'Carta de solicitud de la transferencia, con el comprobante del aporte',
			'ok'     => null === $letter ? null : (bool) $letter,
			'detail' => null === $letter ? 'La carta se prepara cuando las demás condiciones se cumplen.' : ( $letter ? 'Carta registrada.' : 'Carta pendiente.' ),
		);

		$overdue = isset( $ctx['overdue_renditions'] ) && is_array( $ctx['overdue_renditions'] ) ? $ctx['overdue_renditions'] : array();
		$out[]   = array(
			'key'    => 'G5',
			'label'  => 'Sin rendiciones exigibles atrasadas (en este y en los demás proyectos de la institución)',
			'ok'     => empty( $overdue ),
			'detail' => empty( $overdue ) ? 'Todas las rendiciones exigibles están presentadas.' : 'Rendiciones atrasadas: ' . implode( ', ', array_map( 'strval', $overdue ) ) . '. Una sola bloquea todo giro.',
		);

		$receipts = isset( $ctx['receipts_pending'] ) && is_array( $ctx['receipts_pending'] ) ? $ctx['receipts_pending'] : array();
		$out[]    = array(
			'key'    => 'G6',
			'label'  => 'Comprobantes de ingreso de las transferencias anteriores enviados',
			'ok'     => empty( $receipts ),
			'detail' => empty( $receipts ) ? 'Cada transferencia recibida tiene su comprobante de ingreso enviado.' : 'Faltan los comprobantes de ingreso de las cuotas: ' . implode( ', ', array_map( 'strval', $receipts ) ) . '.',
		);

		$window = isset( $ctx['window'] ) && is_array( $ctx['window'] ) ? $ctx['window'] : array( '', '' );
		$today  = (string) ( $ctx['today'] ?? '' );
		$from   = (string) ( $window[0] ?? '' );
		$to     = (string) ( $window[1] ?? '' );
		if ( '' === $from && '' === $to ) {
			$ok7     = null;
			$detail7 = 'La cuota no tiene ventana registrada en el programa de desembolso.';
		} else {
			$ok7     = ( '' === $from || $today >= $from ) && ( '' === $to || $today <= $to );
			$detail7 = sprintf( 'Ventana del convenio: %s a %s.%s', $from, $to, $ok7 ? '' : ( '' !== $from && $today < $from ? ' Todavía no se abre.' : ' Ya cerró: requiere reprogramación.' ) );
		}
		$out[] = array( 'key' => 'G7', 'label' => 'Ventana del programa de desembolso', 'ok' => $ok7, 'detail' => $detail7 );

		return $out;
	}

	/**
	 * Indica si todas las condiciones evaluables se cumplen.
	 *
	 * @param array<int,array{ok:bool|null}> $conditions Condiciones.
	 * @return bool
	 */
	public static function all_met( array $conditions ): bool {
		foreach ( $conditions as $c ) {
			if ( false === $c['ok'] ) {
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
