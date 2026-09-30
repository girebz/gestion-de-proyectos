<?php
/**
 * Eventos de calendario del proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use DateTimeImmutable;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reúne los eventos fechados de un proyecto (hitos, inicios y términos de
 * actividad, término contractual) y deja que los demás módulos añadan los
 * suyos (vencimientos de documentos, compras, reuniones, ensayos) mediante el
 * filtro gdp_calendar_events. Alimenta la vista de calendario, la agenda y el
 * canal iCalendar de suscripción.
 */
final class EventsService {

	/**
	 * Eventos entre dos fechas (inclusive), ordenados por fecha.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $from       Desde (Y-m-d).
	 * @param string $to         Hasta (Y-m-d).
	 * @return array<int,array{date:string,type:string,label:string,title:string,activity_id:int,status:string,url:string,critical:bool,end_date:string|null}>
	 */
	public static function between( int $project_id, string $from, string $to ): array {
		$events  = array();
		$result  = ScheduleService::recalculate( $project_id );
		$project = ProjectRepository::find( $project_id );

		foreach ( $result['activities'] as $a ) {
			if ( 'summary' === $a['kind'] ) {
				continue;
			}
			$done = in_array( $a['status'], array( 'terminada', 'cancelada' ), true );
			if ( 'milestone' === $a['kind'] ) {
				if ( $a['end_date'] && $a['end_date'] >= $from && $a['end_date'] <= $to ) {
					$events[] = self::event( $a['end_date'], 'milestone', __( 'Hito', 'gestion-de-proyectos' ), $a, $done );
				}
				continue;
			}
			if ( $a['start_date'] && $a['start_date'] >= $from && $a['start_date'] <= $to ) {
				$events[] = self::event( $a['start_date'], 'start', __( 'Inicio', 'gestion-de-proyectos' ), $a, $done );
			}
			if ( $a['end_date'] && $a['end_date'] >= $from && $a['end_date'] <= $to ) {
				$events[] = self::event( $a['end_date'], 'end', __( 'Término', 'gestion-de-proyectos' ), $a, $done );
			}
		}

		if ( $project && ! empty( $project['end_date'] ) && $project['end_date'] >= $from && $project['end_date'] <= $to ) {
			$events[] = array(
				'date'        => (string) $project['end_date'],
				'type'        => 'deadline',
				'label'       => __( 'Término contractual', 'gestion-de-proyectos' ),
				'title'       => sprintf( __( 'Término contractual de %s', 'gestion-de-proyectos' ), $project['code'] ),
				'activity_id' => 0,
				'status'      => '',
				'url'         => '',
				'critical'    => true,
				'end_date'    => null,
			);
		}

		/**
		 * Permite a otros módulos añadir eventos al calendario del proyecto.
		 *
		 * Cada evento: date, type, label, title, activity_id (0 si no aplica), status, url, critical, end_date.
		 *
		 * @param array<int,array<string,mixed>> $events     Eventos.
		 * @param int                            $project_id Proyecto.
		 * @param string                         $from       Desde.
		 * @param string                         $to         Hasta.
		 */
		$events = (array) apply_filters( 'gdp_calendar_events', $events, $project_id, $from, $to );

		usort( $events, static fn( array $a, array $b ): int => array( $a['date'], $a['type'], $a['title'] ) <=> array( $b['date'], $b['type'], $b['title'] ) );

		return array_values( $events );
	}

	/**
	 * Evento de actividad.
	 *
	 * @param string              $date  Fecha.
	 * @param string              $type  Tipo.
	 * @param string              $label Rótulo del tipo.
	 * @param array<string,mixed> $a     Actividad.
	 * @param bool                $done  Terminada o cancelada.
	 * @return array<string,mixed>
	 */
	private static function event( string $date, string $type, string $label, array $a, bool $done ): array {
		return array(
			'date'        => $date,
			'type'        => $type,
			'label'       => $label,
			'title'       => $a['code'] . ' ' . $a['name'],
			'activity_id' => $a['id'],
			'status'      => $done ? 'done' : ( $date < current_time( 'Y-m-d' ) ? 'overdue' : 'open' ),
			'url'         => '',
			'critical'    => (bool) $a['is_critical'],
			'end_date'    => 'start' === $type ? $a['end_date'] : null,
		);
	}

	/**
	 * Canal iCalendar con todos los eventos del proyecto (para suscripción).
	 *
	 * @param int $project_id Proyecto.
	 * @return string
	 */
	public static function to_ics( int $project_id ): string {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return '';
		}
		$events = self::between( $project_id, '1970-01-01', '2100-12-31' );
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$lines  = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Gestion de Proyectos//' . GDP_VERSION . '//ES',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( $project['code'] . ' · ' . $project['name'] ),
			'X-PUBLISHED-TTL:PT6H',
			'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
		);
		$stamp = gmdate( 'Ymd\THis\Z' );
		foreach ( $events as $i => $e ) {
			$start = (string) $e['date'];
			$end   = ( new DateTimeImmutable( $start ) )->modify( '+1 day' )->format( 'Ymd' );
			$mark  = 'milestone' === $e['type'] ? '◆ ' : ( 'deadline' === $e['type'] ? '■ ' : '' );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = sprintf( 'UID:gdp-%d-%s-%d-%s@%s', $project_id, $e['type'], (int) $e['activity_id'], str_replace( '-', '', $start ), $host );
			$lines[] = 'DTSTAMP:' . $stamp;
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $start );
			$lines[] = 'DTEND;VALUE=DATE:' . $end;
			$lines[] = 'SUMMARY:' . self::escape( $mark . $e['label'] . ': ' . $e['title'] );
			$lines[] = 'DESCRIPTION:' . self::escape( $project['code'] . ( $e['critical'] ? ' · ruta crítica' : '' ) . ( 'done' === $e['status'] ? ' · terminada' : '' ) );
			$lines[] = 'CATEGORIES:' . self::escape( $e['label'] );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Clave secreta del canal de suscripción del proyecto (se crea si no existe).
	 *
	 * @param int  $project_id Proyecto.
	 * @param bool $regenerate Generar una nueva clave (invalida la anterior).
	 * @return string
	 */
	public static function feed_key( int $project_id, bool $regenerate = false ): string {
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return '';
		}
		$settings = $project['settings'];
		if ( $regenerate || empty( $settings['ics_key'] ) ) {
			$settings['ics_key'] = wp_generate_password( 32, false, false );
			ProjectRepository::update( $project_id, array( 'settings' => $settings ), null );
		}

		return (string) $settings['ics_key'];
	}

	/**
	 * URL pública del canal de suscripción.
	 *
	 * @param int $project_id Proyecto.
	 * @return string
	 */
	public static function feed_url( int $project_id ): string {
		$key = self::feed_key( $project_id );

		return add_query_arg( array( 'action' => 'gdp_ics', 'project_id' => $project_id, 'key' => $key ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * Escapa texto iCalendar.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function escape( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), wp_strip_all_tags( $text ) );
	}
}
