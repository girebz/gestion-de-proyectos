<?php
/**
 * Alertas e informe semanal.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Core\Access;
use GDP\Core\Spreadsheet;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\ProjectXml;
use GDP\Modules\Planning\Views\AlertsView;
use GDP\Modules\Planning\Views\ReportView;
use GDP\Modules\Planning\Views\WorkloadView;
use GDP\Modules\Planning\WeeklyReport;

defined( 'ABSPATH' ) || exit;

/**
 * Alertas de plazo, carga de trabajo e informe semanal (vistas compartidas
 * con los códigos cortos del sitio) y las exportaciones del informe (LaTeX,
 * CSV, Excel, XML de Microsoft Project, JSON, iCalendar).
 */
final class PlanningReportPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_export_report', array( self::class, 'handle_export' ) );
	}

	/**
	 * Formatos de exportación del cronograma completo (fuera del informe).
	 *
	 * @return array<string,string> formato => etiqueta.
	 */
	public static function schedule_formats(): array {
		$formats = array(
			'csv'   => 'CSV',
			'mspdi' => __( 'Project (XML)', 'gestion-de-proyectos' ),
			'tex'   => 'LaTeX',
			'ics'   => 'iCalendar',
		);
		if ( Spreadsheet::available() ) {
			$formats = array( 'xlsx' => 'Excel' ) + $formats;
		}

		return $formats;
	}

	/**
	 * Enlace firmado de descarga.
	 *
	 * @param int         $project_id Proyecto.
	 * @param string      $format     Formato.
	 * @param string|null $week       Lunes de la semana del informe (opcional).
	 * @return string
	 */
	public static function export_url( int $project_id, string $format, ?string $week = null ): string {
		$args = array(
			'action'     => 'gdp_export_report',
			'project_id' => $project_id,
			'format'     => $format,
		);
		if ( $week ) {
			$args['week'] = $week;
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'gdp_export_report_' . $project_id );
	}

	/**
	 * Alertas.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render_alerts( array $project ): void {
		$project_id = (int) $project['id'];
		PlanningPage::header( $project, 'alerts', __( 'Alertas de plazo', 'gestion-de-proyectos' ) );
		AlertsView::render( $project_id, PlanningPage::context( $project_id, 'alerts' ) );
		self::close();
	}

	/**
	 * Informe semanal.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render_report( array $project ): void {
		$project_id = (int) $project['id'];
		PlanningPage::header( $project, 'report', __( 'Informe semanal', 'gestion-de-proyectos' ) );
		ReportView::render( $project_id, PlanningPage::context( $project_id, 'report' ) );
		self::close();
	}

	/**
	 * Carga de trabajo por persona y semana.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render_workload( array $project ): void {
		$project_id = (int) $project['id'];
		PlanningPage::header( $project, 'workload', __( 'Carga de trabajo', 'gestion-de-proyectos' ) );
		WorkloadView::render( $project_id, PlanningPage::context( $project_id, 'workload' ) );
		self::close();
	}

	/**
	 * Descarga del informe.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		check_admin_referer( 'gdp_export_report_' . $project_id );

		$project = ProjectRepository::find( $project_id );
		if ( ! $project || ! Access::can( 'planning.view', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'json';
		$week   = isset( $_GET['week'] ) ? ActivityRepository::normalize_date( sanitize_text_field( wp_unslash( (string) $_GET['week'] ) ) ) : null;
		$report = WeeklyReport::build( $project_id, $week ? $week : null );
		$base   = sanitize_file_name( sprintf( 'informe-semanal-%s-%s', $project['code'], $report['week']['iso'] ) );

		switch ( $format ) {
			case 'tex':
				$body = WeeklyReport::to_latex( $report );
				$type = 'application/x-tex; charset=UTF-8';
				$ext  = 'tex';
				break;
			case 'csv':
				$body = WeeklyReport::to_csv( $report );
				$type = 'text/csv; charset=UTF-8';
				$ext  = 'csv';
				$base = sanitize_file_name( sprintf( 'cronograma-%s-%s', $project['code'], $report['week']['from'] ) );
				break;
			case 'ics':
				$body = WeeklyReport::to_ics( $report );
				$type = 'text/calendar; charset=UTF-8';
				$ext  = 'ics';
				$base = sanitize_file_name( sprintf( 'cronograma-%s', $project['code'] ) );
				break;
			case 'xlsx':
				$body = WeeklyReport::to_xlsx( $report, $project_id );
				if ( is_wp_error( $body ) ) {
					wp_die( esc_html( $body->get_error_message() ), 500 );
				}
				$type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				$ext  = 'xlsx';
				$base = sanitize_file_name( sprintf( 'cronograma-%s-%s', $project['code'], $report['week']['from'] ) );
				break;
			case 'mspdi':
				$body = ProjectXml::export( $project_id );
				$type = 'application/xml; charset=UTF-8';
				$ext  = 'xml';
				$base = sanitize_file_name( sprintf( 'cronograma-%s', $project['code'] ) );
				break;
			default:
				$body = (string) wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
				$type = 'application/json; charset=UTF-8';
				$ext  = 'json';
		}

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $base . '.' . $ext . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga de archivo generado.
		exit;
	}
}
