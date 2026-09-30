<?php
/**
 * Importación de cronogramas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Spreadsheet;
use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\ScheduleImporter;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Subida de CSV, XLSX o XML de Microsoft Project, vista previa con
 * advertencias y confirmación; la importación es una sola operación
 * reversible desde la papelera.
 */
final class PlanningImportPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_import_upload', array( self::class, 'handle_upload' ) );
		add_action( 'admin_post_gdp_import_confirm', array( self::class, 'handle_confirm' ) );
	}

	/**
	 * Pantalla.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para editar la planificación.', 'gestion-de-proyectos' ), 403 );
		}
		$token   = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pending = '' !== $token ? get_transient( 'gdp_import_' . $token ) : null;
		$summaries = array_values( array_filter( ActivityRepository::for_project( $project_id ), static fn( array $a ): bool => 'summary' === $a['kind'] ) );

		PlanningPage::header( $project, 'import', __( 'Importar cronograma', 'gestion-de-proyectos' ) );

		if ( is_array( $pending ) && (int) $pending['project_id'] === $project_id ) {
			$prepared = $pending['prepared'];
			?>
			<div class="gdp-card">
				<h2><?php echo esc_html( sprintf( /* translators: nombre del archivo. */ __( 'Vista previa de %s', 'gestion-de-proyectos' ), $pending['filename'] ) ); ?></h2>
				<p class="gdp-planning-summary">
					<span><strong><?php echo count( $prepared['rows'] ); ?></strong> <?php esc_html_e( 'filas', 'gestion-de-proyectos' ); ?></span>
					<span><strong><?php echo (int) $prepared['summary']['summary']; ?></strong> <?php esc_html_e( 'resúmenes', 'gestion-de-proyectos' ); ?></span>
					<span><strong><?php echo (int) $prepared['summary']['activity']; ?></strong> <?php esc_html_e( 'actividades', 'gestion-de-proyectos' ); ?></span>
					<span><strong><?php echo (int) $prepared['summary']['milestone']; ?></strong> <?php esc_html_e( 'hitos', 'gestion-de-proyectos' ); ?></span>
					<span><strong><?php echo (int) $prepared['summary']['dependencies']; ?></strong> <?php esc_html_e( 'dependencias', 'gestion-de-proyectos' ); ?></span>
				</p>
				<?php if ( ! empty( $prepared['warnings'] ) ) : ?>
					<div class="notice notice-warning inline"><ul><?php foreach ( $prepared['warnings'] as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
				<table class="widefat striped gdp-table gdp-small">
					<thead><tr><th><?php esc_html_e( 'Ref.', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Dentro de', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Dur.', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Predecesoras', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Restricción', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Frente', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><th class="gdp-num">%</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $prepared['rows'], 0, 300 ) as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $row['ref'] ); ?></code></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( ActivityRepository::kind_labels()[ $row['kind'] ] ?? $row['kind'] ); ?></td>
							<td><code><?php echo esc_html( (string) ( $row['parent_ref'] ?? '' ) ); ?></code></td>
							<td class="gdp-num"><?php echo 'milestone' === $row['kind'] ? '◆' : ( 'summary' === $row['kind'] ? '' : (int) $row['duration'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( static fn( array $p ): string => $p['ref'] . ( 'FS' === $p['type'] ? '' : $p['type'] ) . ( $p['lag'] ? sprintf( '%+d', $p['lag'] ) : '' ), $row['predecessors'] ) ) ); ?></td>
							<td><?php echo esc_html( 'asap' === $row['constraint_type'] ? '' : $row['constraint_type'] . ' ' . $row['constraint_date'] ); ?></td>
							<td><?php echo esc_html( (string) $row['work_front'] ); ?></td>
							<td><?php echo esc_html( $row['owner_id'] > 0 ? (string) $row['owner'] : ( '' !== (string) $row['owner'] ? $row['owner'] . ' (?)' : '' ) ); ?></td>
							<td class="gdp-num"><?php echo (int) $row['percent']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $prepared['rows'] ) > 300 ) : ?><p class="gdp-muted"><?php echo esc_html( sprintf( /* translators: número total de filas. */ __( 'Se muestran 300 de %d filas.', 'gestion-de-proyectos' ), count( $prepared['rows'] ) ) ); ?></p><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
					<?php wp_nonce_field( 'gdp_import_confirm_' . $token ); ?>
					<input type="hidden" name="action" value="gdp_import_confirm">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<label for="gdp-import-container"><?php esc_html_e( 'Colocar las filas de primer nivel dentro de', 'gestion-de-proyectos' ); ?></label>
					<select name="container_id" id="gdp-import-container">
						<option value="0"><?php esc_html_e( 'Primer nivel del proyecto', 'gestion-de-proyectos' ); ?></option>
						<?php foreach ( $summaries as $s ) : ?>
							<option value="<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s['code'] . ' ' . $s['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Importar', 'gestion-de-proyectos' ); ?></button>
					<a class="button" href="<?php echo esc_url( PlanningPage::url( $project_id, 'import' ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a>
				</form>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'La importación añade filas (no reemplaza). Queda registrada como una operación y puede revertirse completa desde la página de operaciones.', 'gestion-de-proyectos' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG . '-operations&project_id=' . $project_id ) ); ?>"><?php esc_html_e( 'Ver operaciones', 'gestion-de-proyectos' ); ?></a></p>
			</div>
			<?php
			self::close();
			return;
		}
		?>
		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Subir archivo', 'gestion-de-proyectos' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'gdp_import_upload_' . $project_id ); ?>
					<input type="hidden" name="action" value="gdp_import_upload">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<p><input type="file" name="file" accept=".csv,.txt,.xlsx,.xml" required></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Analizar', 'gestion-de-proyectos' ); ?></button></p>
				</form>
				<?php if ( ! Spreadsheet::available() ) : ?>
					<p class="gdp-text-danger"><?php esc_html_e( 'El servidor no tiene la extensión zip de PHP: los XLSX no podrán leerse; use CSV.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Formatos admitidos', 'gestion-de-proyectos' ); ?></h2>
				<ul class="gdp-list">
					<li><strong>CSV o XLSX</strong>: <?php esc_html_e( 'una fila por actividad; se reconocen las columnas Código (jerárquico, por ejemplo 1.2.3) o Nivel, Nombre, Tipo (resumen, actividad, hito), Duración (días hábiles; admite "2 sem", "40 h"), Predecesoras (notación 1.2FS+3 con códigos o números de fila), Frente, Responsable (nombre, usuario o correo), Avance, Estado, Inicio, Término, Restricción, Fecha de restricción, Inicio real, Término real, Entregable y Prioridad. La exportación CSV del propio plugin se reimporta tal cual.', 'gestion-de-proyectos' ); ?></li>
					<li><strong>XML de Microsoft Project</strong>: <?php esc_html_e( 'archivo guardado como XML desde Project o ProjectLibre; se importan jerarquía, duraciones, hitos, predecesoras con tipo y retraso, restricciones y avance.', 'gestion-de-proyectos' ); ?></li>
					<li><?php esc_html_e( 'Las filas sin predecesoras pero con fecha de inicio reciben la restricción "no empezar antes de" esa fecha, de modo que el cronograma respete las fechas del archivo.', 'gestion-de-proyectos' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Analiza el archivo subido y guarda la vista previa.
	 *
	 * @return void
	 */
	public static function handle_upload(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_import_upload_' . $project_id );
		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back = PlanningPage::url( $project_id, 'import' );
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Admin::redirect_with_notice( $back, __( 'No se recibió ningún archivo.', 'gestion-de-proyectos' ), 'error' );
		}
		$filename = sanitize_file_name( (string) $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rows     = ScheduleImporter::parse_file( (string) $_FILES['file']['tmp_name'], $filename ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $rows ) ) {
			Admin::redirect_with_notice( $back, $rows->get_error_message(), 'error' );
		}
		$prepared = ScheduleImporter::prepare( $rows, $project_id );
		$token    = substr( md5( wp_rand() . microtime() ), 0, 16 );
		set_transient( 'gdp_import_' . $token, array( 'project_id' => $project_id, 'filename' => $filename, 'prepared' => $prepared, 'user_id' => get_current_user_id() ), HOUR_IN_SECONDS );

		wp_safe_redirect( PlanningPage::url( $project_id, 'import', array( 'token' => $token ) ) );
		exit;
	}

	/**
	 * Ejecuta la importación como operación reversible.
	 *
	 * @return void
	 */
	public static function handle_confirm(): void {
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( (string) $_POST['token'] ) ) : '';
		check_admin_referer( 'gdp_import_confirm_' . $token );
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		if ( ! Access::can( 'planning.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$pending = get_transient( 'gdp_import_' . $token );
		if ( ! is_array( $pending ) || (int) $pending['project_id'] !== $project_id ) {
			Admin::redirect_with_notice( PlanningPage::url( $project_id, 'import' ), __( 'La vista previa caducó; vuelva a subir el archivo.', 'gestion-de-proyectos' ), 'error' );
		}
		$result = OperationManager::execute(
			'activity',
			'import',
			array(
				'rows'         => $pending['prepared']['rows'],
				'warnings'     => $pending['prepared']['warnings'],
				'container_id' => isset( $_POST['container_id'] ) ? (int) $_POST['container_id'] : 0,
				'source'       => $pending['filename'],
			),
			$project_id,
			'import'
		);
		delete_transient( 'gdp_import_' . $token );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( PlanningPage::url( $project_id, 'import' ), $result->get_error_message(), 'error' );
		}
		$skipped = $result['result']['skipped_dependencies'] ?? array();
		/* translators: número de actividades. */
		$message = sprintf( __( 'Importadas %d actividades.', 'gestion-de-proyectos' ), (int) ( $result['result']['count'] ?? 0 ) );
		if ( ! empty( $skipped ) ) {
			/* translators: lista de dependencias omitidas. */
			$message .= ' ' . sprintf( __( 'Dependencias omitidas: %s', 'gestion-de-proyectos' ), implode( '; ', $skipped ) );
		}
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'list' ), $message, empty( $skipped ) ? 'success' : 'warning' );
	}
}
