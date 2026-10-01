<?php
/**
 * Tareas programadas, avisos y eventos del módulo de adquisiciones.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Procurement;

use GDP\Admin\Pages\ProcurementPage;
use GDP\Core\Options;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cada día obtiene el valor de la unidad de fomento, revisa las compras con
 * cotizaciones recibidas sin decidir y las cotizaciones solicitadas sin
 * respuesta, y avisa por correo si las notificaciones están activas. Aporta
 * al calendario integrado las entregas esperadas y los vencimientos de
 * cotización.
 */
final class ProcurementCron {

	/**
	 * Revisión diaria.
	 *
	 * @return void
	 */
	public static function daily(): void {
		UfService::ensure_today();
		foreach ( ProjectRepository::all( null ) as $p ) {
			$project_id = (int) $p['id'];
			$waiting    = array_values( array_filter( PurchaseRepository::for_project( $project_id, array( 'open' => true, 'limit' => 1000 ) ), array( PurchaseRepository::class, 'awaiting_decision' ) ) );
			$pending    = QuoteRepository::pending_requests( $project_id );
			update_option( 'gdp_procurement_alerts_' . $project_id, array( 'checked_at' => current_time( 'mysql' ), 'awaiting_decision' => count( $waiting ), 'quotes_without_reply' => count( $pending ) ), false );
			if ( ( empty( $waiting ) && empty( $pending ) ) || ! Options::get( 'notifications_enabled', false ) ) {
				continue;
			}
			$recipients = self::recipients( $project_id );
			if ( empty( $recipients ) ) {
				continue;
			}
			$body = '';
			if ( ! empty( $waiting ) ) {
				$body .= '<p>' . esc_html__( 'Compras con cotizaciones recibidas que esperan nuestra decisión:', 'gestion-de-proyectos' ) . '</p><ul>';
				foreach ( $waiting as $w ) {
					$body .= '<li><strong>' . esc_html( $w['code'] ) . '</strong> ' . esc_html( $w['title'] ) . '</li>';
				}
				$body .= '</ul>';
			}
			if ( ! empty( $pending ) ) {
				$body .= '<p>' . esc_html__( 'Cotizaciones solicitadas sin respuesta del proveedor:', 'gestion-de-proyectos' ) . '</p><ul>';
				foreach ( $pending as $q ) {
					/* translators: 1: proveedor, 2: fecha de solicitud. */
					$body .= '<li>' . esc_html( sprintf( __( '%1$s (solicitada el %2$s)', 'gestion-de-proyectos' ), $q['supplier'] ? $q['supplier'] : '#' . $q['id'], (string) $q['requested_at'] ) ) . '</li>';
				}
				$body .= '</ul>';
			}
			$body .= '<p><a href="' . esc_url( ProcurementPage::url( $project_id ) ) . '">' . esc_html__( 'Ver compras', 'gestion-de-proyectos' ) . '</a></p>';
			wp_mail( $recipients, sprintf( '[%s] %s', $p['code'], __( 'Compras pendientes de decisión', 'gestion-de-proyectos' ) ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}

	/**
	 * Eventos del calendario integrado: entregas esperadas y vencimientos de cotización.
	 *
	 * @param array<int,array<string,mixed>> $events     Eventos.
	 * @param int                            $project_id Proyecto.
	 * @param string                         $from       Desde.
	 * @param string                         $to         Hasta.
	 * @return array<int,array<string,mixed>>
	 */
	public static function calendar_events( array $events, int $project_id, string $from, string $to ): array {
		$today = current_time( 'Y-m-d' );
		foreach ( PurchaseRepository::for_project( $project_id, array( 'open' => true, 'limit' => 1000 ) ) as $p ) {
			if ( $p['expected_at'] && $p['expected_at'] >= $from && $p['expected_at'] <= $to && ! $p['received_at'] ) {
				$events[] = array(
					'date'        => $p['expected_at'],
					'type'        => 'purchase_expected',
					'label'       => __( 'Entrega esperada', 'gestion-de-proyectos' ),
					'title'       => $p['code'] . ' ' . $p['title'],
					'activity_id' => $p['activity_id'],
					'status'      => $p['stage'],
					'url'         => ProcurementPage::url( $project_id, array( 'view' => 'show', 'id' => $p['id'] ) ),
					'critical'    => $p['expected_at'] < $today,
					'end_date'    => null,
				);
			}
			foreach ( QuoteRepository::for_purchase( $p['id'] ) as $q ) {
				if ( $q['valid_until'] && $q['valid_until'] >= $from && $q['valid_until'] <= $to && in_array( $q['status'], array( 'recibida', 'elegida' ), true ) ) {
					$events[] = array(
						'date'        => $q['valid_until'],
						'type'        => 'quote_expires',
						'label'       => __( 'Vence cotización', 'gestion-de-proyectos' ),
						'title'       => $p['code'] . ' · ' . $q['supplier'],
						'activity_id' => $p['activity_id'],
						'status'      => $q['status'],
						'url'         => ProcurementPage::url( $project_id, array( 'view' => 'show', 'id' => $p['id'] ) ),
						'critical'    => $q['valid_until'] < $today,
						'end_date'    => null,
					);
				}
			}
		}

		return $events;
	}

	/**
	 * Correos de directores e ingenieros.
	 *
	 * @param int $project_id Proyecto.
	 * @return string[]
	 */
	public static function recipients( int $project_id ): array {
		$emails = array();
		foreach ( MemberRepository::for_project( $project_id ) as $m ) {
			if ( in_array( $m['role'], array( 'director', 'ingeniero' ), true ) && ! empty( $m['email'] ) ) {
				$emails[] = (string) $m['email'];
			}
		}

		/**
		 * Permite ajustar los destinatarios de los avisos de compras.
		 *
		 * @param string[] $emails     Correos.
		 * @param int      $project_id Proyecto.
		 */
		return array_values( array_unique( (array) apply_filters( 'gdp_procurement_recipients', $emails, $project_id ) ) );
	}
}
