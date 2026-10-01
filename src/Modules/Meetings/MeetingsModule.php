<?php
/**
 * Módulo de reuniones y acuerdos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

use GDP\Admin\Pages\MeetingsPage;
use GDP\Core\Options;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\ModuleInterface;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Actas con asistentes, agenda, resumen y acuerdos; acuerdos con responsable,
 * plazo y estado, convertibles en actividades y revisados de reunión en
 * reunión; propuesta automática de acuerdos a partir de un texto; aviso
 * diario de acuerdos vencidos; reuniones y plazos en el calendario integrado.
 */
final class MeetingsModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'meetings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Reuniones y acuerdos', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Actas, acuerdos convertidos en tareas y seguimiento de cumplimiento.', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'gdp_register_operation_handlers', array( $this, 'register_handlers' ) );
		add_filter( 'gdp_connector_abilities', array( MeetingsTools::class, 'add_abilities' ) );
		add_filter( 'gdp_link_entities', array( $this, 'link_entities' ) );
		add_filter( 'gdp_calendar_events', array( $this, 'calendar_events' ), 10, 4 );
		add_action( 'gdp_daily_tasks', array( $this, 'daily' ) );
		add_action( 'gdp_project_deleted', array( $this, 'cleanup_project' ) );

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( MeetingsPage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( MeetingsPage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( MeetingsPage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( MeetingsPage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra el manejador de operaciones.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		OperationManager::register_handler( new MeetingHandler() );
	}

	/**
	 * Entidades enlazables: reunión y acuerdo.
	 *
	 * @param array<string,array<string,mixed>> $entities Entidades.
	 * @return array<string,array<string,mixed>>
	 */
	public function link_entities( array $entities ): array {
		$entities['meeting'] = array(
			'label'   => __( 'reunión', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$m = MeetingRepository::find( $id );
				if ( ! $m ) {
					return null;
				}
				return array( 'title' => $m['title'], 'code' => $m['code'], 'url' => MeetingsPage::url( $m['project_id'], array( 'view' => 'show', 'id' => $m['id'] ) ), 'project_id' => $m['project_id'] );
			},
			'search'  => static function ( int $project_id, string $text ): array {
				$out = array();
				foreach ( MeetingRepository::for_project( $project_id, array( 'search' => $text, 'limit' => 200 ) ) as $m ) {
					$out[] = array( 'id' => $m['id'], 'code' => $m['code'], 'title' => $m['title'] );
				}
				return $out;
			},
		);
		$entities['agreement'] = array(
			'label'   => __( 'acuerdo', 'gestion-de-proyectos' ),
			'resolve' => static function ( int $id ): ?array {
				$a = AgreementRepository::find( $id );
				if ( ! $a ) {
					return null;
				}
				return array( 'title' => mb_substr( $a['description'], 0, 80 ), 'code' => $a['code'], 'url' => MeetingsPage::url( $a['project_id'], array( 'view' => 'show', 'id' => $a['meeting_id'] ) ), 'project_id' => $a['project_id'] );
			},
		);

		return $entities;
	}

	/**
	 * Reuniones y plazos de acuerdos en el calendario integrado.
	 *
	 * @param array<int,array<string,mixed>> $events     Eventos.
	 * @param int                            $project_id Proyecto.
	 * @param string                         $from       Desde.
	 * @param string                         $to         Hasta.
	 * @return array<int,array<string,mixed>>
	 */
	public function calendar_events( array $events, int $project_id, string $from, string $to ): array {
		$today = current_time( 'Y-m-d' );
		foreach ( MeetingRepository::for_project( $project_id, array( 'from' => $from, 'to' => $to, 'limit' => 1000 ) ) as $m ) {
			if ( 'cancelada' === $m['status'] ) {
				continue;
			}
			$events[] = array(
				'date'        => $m['meeting_date'],
				'type'        => 'meeting',
				'label'       => __( 'Reunión', 'gestion-de-proyectos' ),
				'title'       => trim( $m['code'] . ' ' . $m['title'] . ( $m['start_time'] ? ' (' . $m['start_time'] . ')' : '' ) ),
				'activity_id' => $m['activity_id'],
				'status'      => $m['status'],
				'url'         => MeetingsPage::url( $project_id, array( 'view' => 'show', 'id' => $m['id'] ) ),
				'critical'    => false,
				'end_date'    => null,
			);
		}
		foreach ( AgreementRepository::for_project( $project_id, array( 'open' => true, 'limit' => 1000 ) ) as $a ) {
			if ( $a['due_date'] && $a['due_date'] >= $from && $a['due_date'] <= $to ) {
				$events[] = array(
					'date'        => $a['due_date'],
					'type'        => 'agreement_due',
					'label'       => __( 'Plazo de acuerdo', 'gestion-de-proyectos' ),
					'title'       => $a['code'] . ' ' . mb_substr( $a['description'], 0, 80 ) . ( '' !== $a['owner_name'] ? ' · ' . $a['owner_name'] : '' ),
					'activity_id' => $a['activity_id'],
					'status'      => $a['status'],
					'url'         => MeetingsPage::url( $project_id, array( 'view' => 'show', 'id' => $a['meeting_id'] ) ),
					'critical'    => $a['due_date'] < $today,
					'end_date'    => null,
				);
			}
		}

		return $events;
	}

	/**
	 * Revisión diaria: acuerdos vencidos por correo.
	 *
	 * @return void
	 */
	public function daily(): void {
		foreach ( ProjectRepository::all( null ) as $p ) {
			$project_id = (int) $p['id'];
			$overdue    = array_values( array_filter( AgreementRepository::for_project( $project_id, array( 'overdue' => true ) ), static fn( array $a ): bool => in_array( AgreementRepository::effective_status( $a ), AgreementRepository::OPEN, true ) ) );
			update_option( 'gdp_meetings_overdue_' . $project_id, array( 'checked_at' => current_time( 'mysql' ), 'count' => count( $overdue ) ), false );
			if ( empty( $overdue ) || ! Options::get( 'notifications_enabled', false ) ) {
				continue;
			}
			$emails = array();
			foreach ( MemberRepository::for_project( $project_id ) as $m ) {
				if ( in_array( $m['role'], array( 'director', 'ingeniero' ), true ) && ! empty( $m['email'] ) ) {
					$emails[] = (string) $m['email'];
				}
			}
			foreach ( $overdue as $a ) {
				if ( $a['owner_id'] > 0 ) {
					$user = get_userdata( $a['owner_id'] );
					if ( $user && $user->user_email ) {
						$emails[] = (string) $user->user_email;
					}
				}
			}
			/**
			 * Permite ajustar los destinatarios de los avisos de acuerdos vencidos.
			 *
			 * @param string[] $emails     Correos.
			 * @param int      $project_id Proyecto.
			 */
			$emails = array_values( array_unique( (array) apply_filters( 'gdp_meetings_recipients', $emails, $project_id ) ) );
			if ( empty( $emails ) ) {
				continue;
			}
			$rows = '';
			foreach ( $overdue as $a ) {
				$rows .= '<li><strong>' . esc_html( $a['code'] ) . '</strong> ' . esc_html( $a['description'] ) . ' · ' . esc_html( $a['owner_name'] ) . ' · ' . esc_html( (string) $a['due_date'] ) . '</li>';
			}
			/* translators: nombre del proyecto. */
			$body = '<p>' . esc_html( sprintf( __( 'Acuerdos de reunión vencidos en el proyecto %s:', 'gestion-de-proyectos' ), $p['name'] ) ) . '</p><ul>' . $rows . '</ul><p><a href="' . esc_url( MeetingsPage::url( $project_id, array( 'view' => 'agreements', 'overdue' => 1 ) ) ) . '">' . esc_html__( 'Ver acuerdos', 'gestion-de-proyectos' ) . '</a></p>';
			wp_mail( $emails, sprintf( '[%s] %s', $p['code'], __( 'Acuerdos vencidos', 'gestion-de-proyectos' ) ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}

	/**
	 * Elimina los datos del módulo cuando se elimina un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public function cleanup_project( int $project_id ): void {
		MeetingRepository::delete_for_project( $project_id );
	}
}
