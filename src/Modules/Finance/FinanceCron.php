<?php
/**
 * Tarea diaria y eventos de calendario del módulo de finanzas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Admin\Pages\FinancePage;
use GDP\Core\Access;
use GDP\Core\Options;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cada día guarda un resumen de las acciones del asistente por proyecto y,
 * si las notificaciones están activas, avisa por correo de las de severidad
 * alta a quienes pueden ver finanzas. Los plazos de rendición, el último
 * mes de pago útil y los vencimientos de garantías se publican como
 * eventos del calendario integrado.
 */
final class FinanceCron {

	/**
	 * Tarea diaria.
	 *
	 * @return void
	 */
	public static function daily(): void {
		foreach ( ProjectRepository::all( null ) as $p ) {
			$project_id = (int) $p['id'];
			$agreement  = AgreementRepository::for_project_single( $project_id );
			if ( ! $agreement ) {
				continue;
			}
			$actions = Assistant::actions( $project_id );
			$high    = array_values( array_filter( $actions, static fn( array $a ): bool => 'alta' === $a['severity'] ) );
			update_option( 'gdp_finance_alerts_' . $project_id, array( 'checked_at' => current_time( 'mysql' ), 'total' => count( $actions ), 'high' => count( $high ) ), false );
			if ( empty( $high ) || ! Options::get( 'notifications_enabled', false ) ) {
				continue;
			}
			$recipients = self::recipients( $project_id );
			if ( empty( $recipients ) ) {
				continue;
			}
			$body = '<p>' . esc_html__( 'Acciones de rendición de cuentas con severidad alta:', 'gestion-de-proyectos' ) . '</p><ul>';
			foreach ( $high as $a ) {
				$body .= '<li><strong>' . esc_html( $a['title'] ) . '</strong>' . ( $a['due'] ? ' (' . esc_html( $a['due'] ) . ')' : '' ) . '<br>' . esc_html( $a['detail'] ) . '</li>';
			}
			$body .= '</ul><p><a href="' . esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant' ) ) ) . '">' . esc_html__( 'Abrir el asistente', 'gestion-de-proyectos' ) . '</a></p>';
			wp_mail( $recipients, sprintf( '[%s] %s', $p['code'], __( 'Rendición de cuentas: acciones pendientes', 'gestion-de-proyectos' ) ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}

	/**
	 * Resumen guardado por la tarea diaria.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function stored_alerts( int $project_id ): ?array {
		$stored = get_option( 'gdp_finance_alerts_' . $project_id );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Eventos de calendario: plazos de rendición, último mes de pago útil, vencimientos de garantías y ventanas de cuotas.
	 *
	 * @param array<int,array<string,mixed>> $events     Eventos.
	 * @param int                            $project_id Proyecto.
	 * @param string                         $from       Desde.
	 * @param string                         $to         Hasta.
	 * @return array<int,array<string,mixed>>
	 */
	public static function calendar_events( array $events, int $project_id, string $from, string $to ): array {
		if ( ! AgreementRepository::for_project_single( $project_id ) || ! Access::can( 'finance.view', $project_id ) ) {
			return $events;
		}
		$status = FinanceService::status( $project_id );
		$today  = (string) $status['today'];
		$add    = static function ( string $date, string $type, string $label, string $title, string $url, bool $critical ) use ( &$events, $from, $to ): void {
			if ( '' === $date || $date < $from || $date > $to ) {
				return;
			}
			$events[] = array( 'date' => $date, 'type' => $type, 'label' => $label, 'title' => $title, 'activity_id' => 0, 'status' => '', 'url' => $url, 'critical' => $critical, 'end_date' => null );
		};
		foreach ( $status['renditions'] as $t ) {
			if ( $t['submitted'] ) {
				continue;
			}
			$label = Assistant::month_label( (string) $t['period'] );
			$url   = FinancePage::url( $project_id, array( 'view' => 'renditions' ) );
			$add( (string) $t['internal_due'], 'finance_internal_due', __( 'Respaldos a la universidad', 'gestion-de-proyectos' ), sprintf( /* translators: mes de la rendición. */ __( 'Rendición de %s: respaldos internos', 'gestion-de-proyectos' ), $label ), $url, $t['internal_due'] < $today );
			$add( (string) $t['platform_due'], 'finance_platform_due', __( 'Carga en la plataforma', 'gestion-de-proyectos' ), sprintf( /* translators: mes de la rendición. */ __( 'Rendición de %s: carga en la plataforma', 'gestion-de-proyectos' ), $label ), $url, $t['platform_due'] < $today );
			if ( $t['rendition'] && $t['rendition']['fix_due'] ) {
				$add( (string) $t['rendition']['fix_due'], 'finance_fix_due', __( 'Subsanación', 'gestion-de-proyectos' ), sprintf( /* translators: mes de la rendición. */ __( 'Rendición de %s: plazo de subsanación', 'gestion-de-proyectos' ), $label ), $url, $t['rendition']['fix_due'] < $today );
			}
		}
		$next = $status['next_installment'];
		if ( $next ) {
			if ( $next['latest_month_end'] ) {
				$add( (string) $next['latest_month_end'], 'finance_latest_payment', __( 'Último pago útil', 'gestion-de-proyectos' ), sprintf( /* translators: 1: número de la cuota, 2: monto de la brecha. */ __( 'Cuota %1$d: último día para pagar la brecha (%2$s)', 'gestion-de-proyectos' ), $next['number'], Assistant::money( $next['gaps']['pay_gap'] ) ), FinancePage::url( $project_id ), false );
			}
			if ( ! empty( $next['window'][1] ) ) {
				$add( (string) $next['window'][1], 'finance_window', __( 'Cierre de ventana de cuota', 'gestion-de-proyectos' ), sprintf( /* translators: número de la cuota. */ __( 'Cuota %d: cierra la ventana del programa de desembolso', 'gestion-de-proyectos' ), $next['number'] ), FinancePage::url( $project_id, array( 'view' => 'installments' ) ), false );
			}
		}
		foreach ( $status['installments'] as $i ) {
			if ( $i['is_received'] && empty( $i['receipt_sent_at'] ) && $i['receipt_due'] ) {
				$add( (string) $i['receipt_due'], 'finance_receipt', __( 'Comprobante de ingreso', 'gestion-de-proyectos' ), sprintf( /* translators: número de la cuota. */ __( 'Cuota %d: enviar el comprobante de ingreso', 'gestion-de-proyectos' ), $i['number'] ), FinancePage::url( $project_id, array( 'view' => 'installments' ) ), $i['receipt_due'] < $today );
			}
		}
		foreach ( GuaranteeRepository::all( $project_id ) as $g ) {
			if ( 'vigente' === $g['status'] && $g['valid_until'] ) {
				$add( (string) $g['valid_until'], 'finance_guarantee', __( 'Vence garantía', 'gestion-de-proyectos' ), sprintf( /* translators: 1: número del instrumento, 2: monto. */ __( 'Garantía %1$s por %2$s', 'gestion-de-proyectos' ), $g['number'], Assistant::money( (float) $g['amount'] ) ), FinancePage::url( $project_id, array( 'view' => 'guarantees' ) ), $g['valid_until'] < $today );
			}
		}

		return $events;
	}

	/**
	 * Correos de quienes pueden ver finanzas (directores e ingenieros por omisión).
	 *
	 * @param int $project_id Proyecto.
	 * @return string[]
	 */
	public static function recipients( int $project_id ): array {
		$emails = array();
		foreach ( MemberRepository::for_project( $project_id ) as $m ) {
			if ( ! empty( $m['email'] ) && Access::can( 'finance.view', $project_id, (int) $m['user_id'] ) ) {
				$emails[] = (string) $m['email'];
			}
		}

		/**
		 * Permite ajustar los destinatarios de los avisos de finanzas.
		 *
		 * @param string[] $emails     Correos.
		 * @param int      $project_id Proyecto.
		 */
		return array_values( array_unique( (array) apply_filters( 'gdp_finance_recipients', $emails, $project_id ) ) );
	}
}
