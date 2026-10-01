<?php
/**
 * Tareas programadas y avisos del control documental.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Admin\Pages\DocumentsPage;
use GDP\Core\Options;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Cada día revisa los documentos con plazo de respuesta vencido o por vencer
 * y, si las notificaciones están activas, avisa por correo a directores e
 * ingenieros del proyecto. También aporta los vencimientos al calendario
 * integrado mediante el filtro gdp_calendar_events.
 */
final class DocumentsCron {

	/**
	 * Revisión diaria.
	 *
	 * @return void
	 */
	public static function daily(): void {
		foreach ( ProjectRepository::all( null ) as $p ) {
			$pending = DocumentRepository::pending_responses( (int) $p['id'], 3 );
			update_option( 'gdp_documents_pending_' . (int) $p['id'], array( 'checked_at' => current_time( 'mysql' ), 'count' => count( $pending ) ), false );
			if ( empty( $pending ) || ! Options::get( 'notifications_enabled', false ) ) {
				continue;
			}
			$recipients = self::recipients( (int) $p['id'] );
			if ( empty( $recipients ) ) {
				continue;
			}
			$today = current_time( 'Y-m-d' );
			$rows  = '';
			foreach ( $pending as $d ) {
				$rows .= sprintf(
					'<li><strong>%s</strong> %s (%s) · %s %s</li>',
					esc_html( $d['number'] ? $d['number'] : '' ),
					esc_html( $d['subject'] ),
					esc_html( $d['recipient'] ? $d['recipient'] : $d['sender'] ),
					$d['response_due'] < $today ? esc_html__( 'vencido el', 'gestion-de-proyectos' ) : esc_html__( 'vence el', 'gestion-de-proyectos' ),
					esc_html( (string) $d['response_due'] )
				);
			}
			$body = sprintf(
				'<p>%s</p><ul>%s</ul><p><a href="%s">%s</a></p>',
				/* translators: nombre del proyecto. */
				esc_html( sprintf( __( 'Documentos del proyecto %s con respuesta vencida o que vence en los próximos tres días:', 'gestion-de-proyectos' ), $p['name'] ) ),
				$rows,
				esc_url( DocumentsPage::url( (int) $p['id'], array( 'overdue' => 1 ) ) ),
				esc_html__( 'Ver documentos', 'gestion-de-proyectos' )
			);
			wp_mail( $recipients, sprintf( '[%s] %s', $p['code'], __( 'Respuestas documentales pendientes', 'gestion-de-proyectos' ) ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}

	/**
	 * Eventos del calendario integrado: plazos de respuesta y fechas de documento.
	 *
	 * @param array<int,array<string,mixed>> $events     Eventos.
	 * @param int                            $project_id Proyecto.
	 * @param string                         $from       Desde.
	 * @param string                         $to         Hasta.
	 * @return array<int,array<string,mixed>>
	 */
	public static function calendar_events( array $events, int $project_id, string $from, string $to ): array {
		$today = current_time( 'Y-m-d' );
		foreach ( DocumentRepository::for_project( $project_id, array( 'limit' => 1000 ) ) as $d ) {
			$title = trim( $d['number'] . ' ' . $d['subject'] );
			if ( $d['response_due'] && $d['response_due'] >= $from && $d['response_due'] <= $to && in_array( $d['status'], array( 'enviado', 'recibido', 'borrador' ), true ) ) {
				$events[] = array(
					'date'        => $d['response_due'],
					'type'        => 'document_due',
					'label'       => __( 'Respuesta documental', 'gestion-de-proyectos' ),
					'title'       => $title,
					'activity_id' => 0,
					'status'      => $d['status'],
					'url'         => DocumentsPage::url( $project_id, array( 'view' => 'show', 'id' => $d['id'] ) ),
					'critical'    => $d['response_due'] < $today,
					'end_date'    => null,
				);
			}
			if ( $d['doc_date'] && $d['doc_date'] >= $from && $d['doc_date'] <= $to && 'anulado' !== $d['status'] ) {
				$events[] = array(
					'date'        => $d['doc_date'],
					'type'        => 'document',
					'label'       => __( 'Documento', 'gestion-de-proyectos' ),
					'title'       => $title,
					'activity_id' => 0,
					'status'      => $d['status'],
					'url'         => DocumentsPage::url( $project_id, array( 'view' => 'show', 'id' => $d['id'] ) ),
					'critical'    => false,
					'end_date'    => null,
				);
			}
		}

		return $events;
	}

	/**
	 * Correos de directores e ingenieros del proyecto.
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
		 * Permite ajustar los destinatarios de los avisos documentales.
		 *
		 * @param string[] $emails     Correos.
		 * @param int      $project_id Proyecto.
		 */
		return array_values( array_unique( (array) apply_filters( 'gdp_documents_recipients', $emails, $project_id ) ) );
	}
}
