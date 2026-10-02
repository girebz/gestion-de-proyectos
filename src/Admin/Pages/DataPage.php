<?php
/**
 * Pantalla de datos: exportación, importación, respaldos y diccionario.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Options;
use GDP\Core\Roles;
use GDP\Core\Storage;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Data\BackupService;
use GDP\Modules\Data\DataDictionary;
use GDP\Modules\Data\DataHandler;
use GDP\Modules\Data\DataSchema;
use GDP\Modules\Data\Exporter;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Exportar (JSON, CSV, XLSX, ZIP con adjuntos; por módulo; anonimizada;
 * con diccionario), importar (JSON o ZIP con vista previa, aplicación
 * parcial y reversión), respaldos (crear, descargar, restaurar, eliminar,
 * programación semanal) y diccionario de datos.
 */
final class DataPage extends Page {

	public const SLUG  = 'data';
	public const VIEWS = array( 'export', 'import', 'proposal', 'backups', 'dictionary' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Menú padre.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		add_submenu_page( $parent, __( 'Datos', 'gestion-de-proyectos' ), __( 'Datos', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores de formularios.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		foreach ( array( 'data_export', 'data_dictionary', 'data_upload', 'data_confirm', 'backup_create', 'backup_delete', 'backup_restore', 'backup_settings' ) as $action ) {
			add_action( 'admin_post_gdp_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	/**
	 * Estilos.
	 *
	 * @param string $hook Pantalla.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, Admin::SLUG . '-' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'gdp-planning', GDP_URL . 'assets/css/planning.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_style( 'gdp-documents', GDP_URL . 'assets/css/documents.css', array( 'gdp-admin' ), GDP_VERSION );
	}

	/**
	 * URL de la pantalla.
	 *
	 * @param array<string,mixed> $args Parámetros.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return Admin::url( self::SLUG, $args );
	}

	/**
	 * Tarjeta en la ficha del proyecto.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	public static function project_card( array $p ): void {
		$project_id = (int) $p['id'];
		if ( ! Access::can( 'data.export', $project_id ) ) {
			return;
		}
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Datos', 'gestion-de-proyectos' ); ?></h2>
			<p><?php esc_html_e( 'Exportación completa del proyecto en formatos abiertos, con diccionario de datos; importación con vista previa y reversión.', 'gestion-de-proyectos' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( array( 'project_id' => $project_id ) ) ); ?>"><?php esc_html_e( 'Exportar', 'gestion-de-proyectos' ); ?></a>
				<?php if ( Access::can( 'data.import', $project_id ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( array( 'project_id' => $project_id, 'view' => 'import' ) ) ); ?>"><?php esc_html_e( 'Importar', 'gestion-de-proyectos' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Punto de entrada.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'export';
		if ( in_array( $view, array( 'backups' ), true ) && ! Access::is_manager() ) {
			$view = 'export';
		}
		$project = PlanningPage::current_project();
		if ( $project ) {
			update_user_meta( get_current_user_id(), 'gdp_planning_project', (int) $project['id'] );
		}
		switch ( $view ) {
			case 'import':
				self::render_import( $project );
				break;
			case 'proposal':
				self::render_proposal();
				break;
			case 'backups':
				self::render_backups();
				break;
			case 'dictionary':
				self::render_dictionary();
				break;
			default:
				self::render_export( $project );
		}
	}

	/**
	 * Encabezado con selector de proyecto y pestañas.
	 *
	 * @param array<string,mixed>|null $project Proyecto.
	 * @param string                   $view    Vista.
	 * @param string                   $title   Título.
	 * @return void
	 */
	private static function header( ?array $project, string $view, string $title ): void {
		$projects = ProjectRepository::all( Access::visible_project_ids() );
		self::open( $title, $project ? sprintf( '%s · %s', $project['code'], $project['name'] ) : '' );
		$tabs = array(
			'export'     => __( 'Exportar', 'gestion-de-proyectos' ),
			'import'     => __( 'Importar', 'gestion-de-proyectos' ),
			'dictionary' => __( 'Diccionario', 'gestion-de-proyectos' ),
		);
		if ( Access::is_manager() ) {
			$tabs['backups'] = __( 'Respaldos', 'gestion-de-proyectos' );
		}
		?>
		<div class="gdp-planning-bar">
			<?php if ( ! empty( $projects ) ) : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( in_array( $view, array( 'export', 'import' ), true ) ? $view : 'export' ); ?>">
				<select name="project_id" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Proyecto', 'gestion-de-proyectos' ); ?>">
					<?php foreach ( $projects as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], (int) ( $project['id'] ?? 0 ) ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php endif; ?>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $view || ( 'proposal' === $view && 'import' === $slug ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'view' => $slug, 'project_id' => (int) ( $project['id'] ?? 0 ) ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Exportación.
	 *
	 * @param array<string,mixed>|null $project Proyecto.
	 * @return void
	 */
	private static function render_export( ?array $project ): void {
		self::header( $project, 'export', __( 'Exportar datos', 'gestion-de-proyectos' ) );
		if ( ! $project ) {
			echo '<p>' . esc_html__( 'No hay proyectos visibles.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'data.export', $project_id ) ) {
			echo '<p>' . esc_html__( 'Sin permiso para exportar este proyecto.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$labels  = DataSchema::module_labels();
		$allowed = DataSchema::exportable_modules( array_keys( $labels ), $project_id );
		?>
		<div class="gdp-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form">
				<?php wp_nonce_field( 'gdp_data_export_' . $project_id ); ?>
				<input type="hidden" name="action" value="gdp_data_export">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<h2><?php esc_html_e( 'Exportación del proyecto', 'gestion-de-proyectos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Todas las tablas del proyecto con sus referencias traducidas a códigos y nombres, más el diccionario de datos. Los identificadores internos se reasignan al importar; los registros se reconocen por su código.', 'gestion-de-proyectos' ); ?></p>
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Formato', 'gestion-de-proyectos' ); ?></th><td>
						<label><input type="radio" name="format" value="json" checked> JSON <span class="description"><?php esc_html_e( '(importable; un solo archivo)', 'gestion-de-proyectos' ); ?></span></label><br>
						<label><input type="radio" name="format" value="zip"> ZIP <span class="description"><?php esc_html_e( '(JSON, diccionario y adjuntos; importable con archivos)', 'gestion-de-proyectos' ); ?></span></label><br>
						<label><input type="radio" name="format" value="xlsx"> XLSX <span class="description"><?php esc_html_e( '(una hoja por tabla y hoja de diccionario)', 'gestion-de-proyectos' ); ?></span></label><br>
						<label><input type="radio" name="format" value="csv"> CSV <span class="description"><?php esc_html_e( '(ZIP con un archivo por tabla y el diccionario)', 'gestion-de-proyectos' ); ?></span></label>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Módulos', 'gestion-de-proyectos' ); ?></th><td>
						<?php foreach ( $labels as $slug => $label ) : ?>
							<?php $can_export = in_array( $slug, $allowed, true ); ?>
							<label class="gdp-check"><input type="checkbox" name="modules[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( 'audit' !== $slug && $can_export ); ?> <?php disabled( 'core' === $slug || ! $can_export ); ?>> <?php echo esc_html( $label ); ?>
								<?php if ( ! $can_export ) : ?>
									<span class="description"><?php esc_html_e( '(sin permiso para exportarlo)', 'gestion-de-proyectos' ); ?></span>
								<?php endif; ?>
							</label><br>
						<?php endforeach; ?>
						<input type="hidden" name="modules[]" value="core">
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Opciones', 'gestion-de-proyectos' ); ?></th><td>
						<label class="gdp-check"><input type="checkbox" name="dictionary" value="1" checked> <?php esc_html_e( 'Incluir el diccionario de datos', 'gestion-de-proyectos' ); ?></label><br>
						<label class="gdp-check"><input type="checkbox" name="anonymize" value="1"> <?php esc_html_e( 'Anonimizada: sin usuarios, nombres de contacto, correos, bitácora ni montos individuales', 'gestion-de-proyectos' ); ?></label>
					</td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Descargar exportación', 'gestion-de-proyectos' ); ?></button></p>
			</form>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Qué contiene', 'gestion-de-proyectos' ); ?></h2>
				<table class="widefat striped gdp-table gdp-small">
					<thead><tr><th><?php esc_html_e( 'Módulo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tablas', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( DataSchema::modules() as $slug => $tables ) : ?>
						<tr><td><?php echo esc_html( $labels[ $slug ] ?? $slug ); ?></td><td><code><?php echo esc_html( implode( ', ', $tables ) ); ?></code></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Los tokens del conector y las operaciones pendientes nunca se exportan; la clave de la suscripción iCalendar se omite de los ajustes del proyecto.', 'gestion-de-proyectos' ); ?></p>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Importación: subida de archivo y propuesta.
	 *
	 * @param array<string,mixed>|null $project Proyecto.
	 * @return void
	 */
	private static function render_import( ?array $project ): void {
		self::header( $project, 'import', __( 'Importar datos', 'gestion-de-proyectos' ) );
		$can_new    = Access::is_manager();
		$can_update = $project && Access::can( 'data.import', (int) $project['id'] );
		if ( ! $can_new && ! $can_update ) {
			echo '<p>' . esc_html__( 'Sin permiso para importar datos.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$pending = array_values( array_filter( OperationManager::find_by_status( 'proposed', null, 20 ), static fn( array $o ): bool => 'data' === $o['handler'] ) );
		?>
		<div class="gdp-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form" enctype="multipart/form-data">
				<?php wp_nonce_field( 'gdp_data_upload' ); ?>
				<input type="hidden" name="action" value="gdp_data_upload">
				<h2><?php esc_html_e( 'Archivo exportado por el plugin', 'gestion-de-proyectos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'JSON o ZIP en el formato gestion-de-proyectos/export. Nada se escribe hasta que confirme la vista previa; la importación completa se puede revertir desde Operaciones.', 'gestion-de-proyectos' ); ?></p>
				<table class="form-table">
					<tr><th scope="row"><label for="gdp-import-file"><?php esc_html_e( 'Archivo', 'gestion-de-proyectos' ); ?></label></th><td><input type="file" id="gdp-import-file" name="file" accept=".json,.zip,application/json,application/zip" required></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Destino', 'gestion-de-proyectos' ); ?></th><td>
						<?php if ( $can_new ) : ?>
							<label><input type="radio" name="mode" value="new" checked> <?php esc_html_e( 'Crear un proyecto nuevo', 'gestion-de-proyectos' ); ?></label>
							<input type="text" name="project_code" placeholder="<?php esc_attr_e( 'código (opcional, si el del archivo ya existe)', 'gestion-de-proyectos' ); ?>" class="regular-text"><br>
						<?php endif; ?>
						<?php if ( $can_update ) : ?>
							<label><input type="radio" name="mode" value="update" <?php checked( ! $can_new ); ?>>
								<?php
								/* translators: código del proyecto. */
								echo esc_html( sprintf( __( 'Actualizar el proyecto %s (los registros se reconocen por su código; lo modificado en el sitio después de la exportación se marca como conflicto)', 'gestion-de-proyectos' ), $project['code'] ) );
								?>
							</label>
							<input type="hidden" name="project_id" value="<?php echo (int) $project['id']; ?>">
						<?php endif; ?>
					</td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Analizar y proponer', 'gestion-de-proyectos' ); ?></button></p>
			</form>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Propuestas pendientes', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $pending ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'No hay importaciones pendientes de confirmar.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul>
					<?php foreach ( $pending as $op ) : ?>
						<li><a href="<?php echo esc_url( self::url( array( 'view' => 'proposal', 'op' => (int) $op['id'] ) ) ); ?>">#<?php echo (int) $op['id']; ?> <?php echo esc_html( (string) $op['summary'] ); ?></a> <span class="gdp-muted"><?php echo esc_html( self::date( (string) $op['created_at'] ) ); ?></span></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Cómo se reconoce cada registro', 'gestion-de-proyectos' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Proyecto por código; actividades, compras, reuniones y acuerdos por código; documentos por tipo y número; proveedores, calendarios y líneas base por nombre; cotizaciones por número; vínculos por sus extremos. Los usuarios se buscan por nombre de usuario y luego por correo; los que no existan quedan sin asignar.', 'gestion-de-proyectos' ); ?></p>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Vista previa de una propuesta de importación o restauración.
	 *
	 * @return void
	 */
	private static function render_proposal(): void {
		$op_id = isset( $_GET['op'] ) ? (int) $_GET['op'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$op    = $op_id > 0 ? OperationManager::get( $op_id ) : null;
		self::header( PlanningPage::current_project(), 'proposal', __( 'Vista previa de la importación', 'gestion-de-proyectos' ) );
		if ( ! $op || 'data' !== $op['handler'] ) {
			echo '<p>' . esc_html__( 'La propuesta no existe.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$preview = (array) $op['preview'];
		$payload = (array) $op['payload'];
		$plan    = (array) ( $preview['plan'] ?? array() );
		$labels  = DataDictionary::build();
		?>
		<div class="gdp-card gdp-card--wide">
			<h2><?php echo esc_html( (string) ( $preview['summary'] ?? '' ) ); ?></h2>
			<p class="gdp-muted">
				<?php
				/* translators: 1: número de operación, 2: estado, 3: usuario. */
				echo esc_html( sprintf( __( 'Operación #%1$d · %2$s · %3$s', 'gestion-de-proyectos' ), (int) $op['id'], (string) $op['status'], \GDP\Modules\Planning\ScheduleService::user_name( (int) $op['user_id'] ) ) );
				?>
			</p>
			<?php foreach ( (array) ( $preview['conflicts'] ?? array() ) as $c ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( (string) $c ); ?></p></div>
			<?php endforeach; ?>
			<?php foreach ( (array) ( $preview['warnings'] ?? array() ) as $w ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( (string) $w ); ?></p></div>
			<?php endforeach; ?>
			<?php if ( 'import' === $op['action'] && ! empty( $plan['tables'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gdp_data_confirm_' . (int) $op['id'] ); ?>
				<input type="hidden" name="action" value="gdp_data_confirm">
				<input type="hidden" name="op" value="<?php echo (int) $op['id']; ?>">
				<table class="widefat striped gdp-table gdp-small">
					<thead><tr><th></th><th><?php esc_html_e( 'Tabla', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Nuevos', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Actualizados', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Sin cambios', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Omitidos', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Conflictos', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ejemplos', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( (array) $plan['tables'] as $table => $t ) : ?>
						<tr>
							<td><?php if ( 'projects' !== $table ) : ?><input type="checkbox" name="tables[]" value="<?php echo esc_attr( (string) $table ); ?>" <?php checked( ! empty( $t['selected'] ) ); ?>><?php endif; ?></td>
							<td><strong><?php echo esc_html( (string) ( $labels[ $table ]['label'] ?? $table ) ); ?></strong><br><code><?php echo esc_html( (string) $table ); ?></code></td>
							<td class="gdp-num"><?php echo (int) $t['create']; ?></td>
							<td class="gdp-num"><?php echo (int) $t['update']; ?></td>
							<td class="gdp-num"><?php echo (int) $t['unchanged']; ?></td>
							<td class="gdp-num"><?php echo (int) $t['skipped']; ?></td>
							<td class="gdp-num <?php echo (int) $t['conflicts'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $t['conflicts']; ?></td>
							<td class="gdp-small">
								<?php foreach ( (array) ( $t['samples'] ?? array() ) as $s ) : ?>
									<div><?php echo esc_html( ( 'update' === $s['op'] ? '↻ ' : '+ ' ) . $s['label'] ); ?><?php if ( ! empty( $s['changes'] ) ) : ?> <span class="gdp-muted"><?php echo esc_html( implode( '; ', array_map( static fn( string $k, array $v ): string => $k . ': ' . $v[0] . ' → ' . $v[1], array_keys( (array) $s['changes'] ), array_values( (array) $s['changes'] ) ) ) ); ?></span><?php endif; ?></div>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Desmarque las tablas que no quiera cargar: la propuesta se vuelve a calcular solo con las marcadas (aplicación parcial). La importación completa se revierte desde Operaciones.', 'gestion-de-proyectos' ); ?></p>
				<p>
					<?php if ( 'proposed' === $op['status'] ) : ?>
						<button type="submit" class="button button-primary" <?php disabled( ! empty( $preview['conflicts'] ) ); ?>><?php esc_html_e( 'Confirmar importación', 'gestion-de-proyectos' ); ?></button>
						<button type="submit" name="recalculate" value="1" class="button"><?php esc_html_e( 'Recalcular con las tablas marcadas', 'gestion-de-proyectos' ); ?></button>
						<button type="submit" name="cancel" value="1" class="button"><?php esc_html_e( 'Descartar', 'gestion-de-proyectos' ); ?></button>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( Admin::url( 'operations', array( 'id' => (int) $op['id'] ) ) ); ?>"><?php esc_html_e( 'Ver en Operaciones', 'gestion-de-proyectos' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
			<?php elseif ( 'restore_backup' === $op['action'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gdp_data_confirm_' . (int) $op['id'] ); ?>
				<input type="hidden" name="action" value="gdp_data_confirm">
				<input type="hidden" name="op" value="<?php echo (int) $op['id']; ?>">
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Respaldo', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( (string) ( $payload['backup'] ?? '' ) ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Filas ahora / en el respaldo', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) ( $preview['changes']['rows']['before'] ?? 0 ); ?> / <?php echo (int) ( $preview['changes']['rows']['after'] ?? 0 ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Proyectos del respaldo', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) ( $preview['changes']['projects']['after'] ?? '' ) ); ?></td></tr>
				</table>
				<p>
					<?php if ( 'proposed' === $op['status'] ) : ?>
						<button type="submit" class="button button-primary gdp-button-danger"><?php esc_html_e( 'Restaurar (reemplaza todos los datos)', 'gestion-de-proyectos' ); ?></button>
						<button type="submit" name="cancel" value="1" class="button"><?php esc_html_e( 'Descartar', 'gestion-de-proyectos' ); ?></button>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( Admin::url( 'operations', array( 'id' => (int) $op['id'] ) ) ); ?>"><?php esc_html_e( 'Ver en Operaciones', 'gestion-de-proyectos' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Respaldos.
	 *
	 * @return void
	 */
	private static function render_backups(): void {
		self::require_manager();
		self::header( PlanningPage::current_project(), 'backups', __( 'Respaldos', 'gestion-de-proyectos' ) );
		$backups     = BackupService::all();
		$status      = Storage::status();
		$next_weekly = \GDP\Core\Cron::status()['next_weekly'] ?? 0;
		?>
		<div class="gdp-grid">
			<div class="gdp-card gdp-card--wide">
				<h2><?php esc_html_e( 'Respaldos del sitio', 'gestion-de-proyectos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Cada respaldo es un ZIP en el directorio privado con todas las tablas (datos.json y datos.sql), el diccionario y los adjuntos. Restaurar reemplaza todos los datos del plugin tras crear un respaldo de seguridad; la restauración se revierte desde Operaciones.', 'gestion-de-proyectos' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
					<?php wp_nonce_field( 'gdp_backup_create' ); ?>
					<input type="hidden" name="action" value="gdp_backup_create">
					<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota (opcional)', 'gestion-de-proyectos' ); ?>" class="regular-text">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Crear respaldo ahora', 'gestion-de-proyectos' ); ?></button>
				</form>
				<?php if ( empty( $backups ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Todavía no hay respaldos.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
				<table class="widefat striped gdp-table gdp-small">
					<thead><tr><th><?php esc_html_e( 'Archivo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Origen', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Proyectos', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Filas', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Adjuntos', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Tamaño', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $backups as $b ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $b['name'] ); ?></code></td>
							<td><?php echo esc_html( self::date( str_replace( array( 'T', 'Z', '+00:00' ), array( ' ', '', '' ), (string) $b['created_at'] ) ) ); ?></td>
							<td><?php echo esc_html( self::trigger_label( (string) ( $b['trigger'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) ( $b['projects'] ?? array() ) ) ); ?></td>
							<td class="gdp-num"><?php echo (int) ( $b['rows'] ?? 0 ); ?></td>
							<td class="gdp-num"><?php echo (int) ( $b['attachments'] ?? 0 ); ?></td>
							<td class="gdp-num"><?php echo esc_html( size_format( (int) $b['size'] ) ); ?></td>
							<td><?php echo esc_html( (string) ( $b['note'] ?? '' ) ); ?></td>
							<td class="gdp-small">
								<a href="<?php echo esc_url( Storage::url( BackupService::DIR . '/' . $b['name'] ) ); ?>"><?php esc_html_e( 'descargar', 'gestion-de-proyectos' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
									<?php wp_nonce_field( 'gdp_backup_restore_' . $b['name'] ); ?>
									<input type="hidden" name="action" value="gdp_backup_restore">
									<input type="hidden" name="backup" value="<?php echo esc_attr( (string) $b['name'] ); ?>">
									<button type="submit" class="button-link"><?php esc_html_e( 'restaurar', 'gestion-de-proyectos' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
									<?php wp_nonce_field( 'gdp_backup_delete_' . $b['name'] ); ?>
									<input type="hidden" name="action" value="gdp_backup_delete">
									<input type="hidden" name="backup" value="<?php echo esc_attr( (string) $b['name'] ); ?>">
									<button type="submit" class="button-link gdp-button-link-danger"><?php esc_html_e( 'eliminar', 'gestion-de-proyectos' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form gdp-card--wide">
				<?php wp_nonce_field( 'gdp_backup_settings' ); ?>
				<input type="hidden" name="action" value="gdp_backup_settings">
				<h2><?php esc_html_e( 'Respaldos automáticos', 'gestion-de-proyectos' ); ?></h2>
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Semanal', 'gestion-de-proyectos' ); ?></th><td><label><input type="checkbox" name="backups_enabled" value="1" <?php checked( (bool) Options::get( 'backups_enabled', false ) ); ?>> <?php esc_html_e( 'Crear un respaldo cada semana con la tarea programada del plugin', 'gestion-de-proyectos' ); ?></label></td></tr>
					<tr><th scope="row"><label for="gdp-backups-keep"><?php esc_html_e( 'Conservar', 'gestion-de-proyectos' ); ?></label></th><td><input type="number" id="gdp-backups-keep" name="backups_keep" min="1" max="52" value="<?php echo (int) Options::get( 'backups_keep', 8 ); ?>" class="small-text"> <?php esc_html_e( 'respaldos automáticos (los manuales no se borran solos)', 'gestion-de-proyectos' ); ?></td></tr>
					<tr><th scope="row"><label for="gdp-backups-copy"><?php esc_html_e( 'Copia externa', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" id="gdp-backups-copy" name="backups_copy_dir" value="<?php echo esc_attr( (string) Options::get( 'backups_copy_dir', '' ) ); ?>" class="large-text" placeholder="/ruta/en/el/servidor/fuera/del/sitio"><p class="description"><?php esc_html_e( 'Carpeta del servidor (idealmente fuera del directorio web o en un disco montado) a la que se copia cada respaldo. Para otros destinos use la acción gdp_backup_created.', 'gestion-de-proyectos' ); ?></p></td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button></p>
			</form>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Directorio privado', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Ruta', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( Storage::private_dir() ); ?></code> <?php echo ! empty( $status['writable'] ) ? '' : '<span class="gdp-text-danger">' . esc_html__( '(sin permiso de escritura)', 'gestion-de-proyectos' ) . '</span>'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Respaldos', 'gestion-de-proyectos' ); ?></th><td><?php echo count( $backups ); ?> · <?php echo esc_html( size_format( array_sum( array_map( static fn( array $b ): int => (int) $b['size'], $backups ) ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Próxima tarea semanal', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $next_weekly ? self::date( gmdate( 'Y-m-d H:i:s', (int) $next_weekly ) ) : __( 'sin programar', 'gestion-de-proyectos' ) ); ?></td></tr>
				</table>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Diccionario de datos.
	 *
	 * @return void
	 */
	private static function render_dictionary(): void {
		self::header( PlanningPage::current_project(), 'dictionary', __( 'Diccionario de datos', 'gestion-de-proyectos' ) );
		$dictionary = DataDictionary::build();
		$modules    = DataSchema::module_labels();
		?>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_data_dictionary&format=csv' ), 'gdp_data_dictionary' ) ); ?>"><?php esc_html_e( 'Descargar CSV', 'gestion-de-proyectos' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_data_dictionary&format=json' ), 'gdp_data_dictionary' ) ); ?>"><?php esc_html_e( 'Descargar JSON', 'gestion-de-proyectos' ); ?></a>
			<span class="description">
				<?php
				/* translators: 1: versión del esquema, 2: número de tablas. */
				echo esc_html( sprintf( __( 'Esquema %1$s · %2$d tablas', 'gestion-de-proyectos' ), GDP_DB_VERSION, count( $dictionary ) ) );
				?>
			</span>
		</p>
		<?php foreach ( $dictionary as $table => $def ) : ?>
			<div class="gdp-card gdp-card--wide">
				<h2><?php echo esc_html( $def['label'] ); ?> <code><?php echo esc_html( (string) $table ); ?></code> <span class="gdp-muted gdp-small"><?php echo esc_html( $modules[ $def['module'] ] ?? $def['module'] ); ?></span></h2>
				<p class="description"><?php echo esc_html( $def['description'] ); ?></p>
				<table class="widefat striped gdp-table gdp-small">
					<thead><tr><th><?php esc_html_e( 'Campo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Unidad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Significado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Referencia', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $def['fields'] as $f ) : ?>
						<tr><td><code><?php echo esc_html( $f['name'] ); ?></code></td><td><?php echo esc_html( $f['type'] ); ?> <span class="gdp-muted"><?php echo esc_html( $f['sql_type'] ); ?></span></td><td><?php echo esc_html( $f['unit'] ); ?></td><td><?php echo esc_html( $f['description'] ); ?></td><td><?php echo esc_html( $f['references'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
		<?php
		self::close();
	}

	/**
	 * Etiqueta del origen de un respaldo.
	 *
	 * @param string $trigger Origen.
	 * @return string
	 */
	private static function trigger_label( string $trigger ): string {
		$labels = array(
			'manual'     => __( 'Manual', 'gestion-de-proyectos' ),
			'programado' => __( 'Programado', 'gestion-de-proyectos' ),
			'seguridad'  => __( 'Seguridad (antes de restaurar)', 'gestion-de-proyectos' ),
		);

		return $labels[ $trigger ] ?? $trigger;
	}

	/**
	 * Descarga la exportación.
	 *
	 * @return void
	 */
	public static function handle_data_export(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'gdp_data_export_' . $project_id );
		if ( ! Access::can( 'data.export', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para exportar este proyecto.', 'gestion-de-proyectos' ), 403 );
		}
		$format   = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( (string) $_POST['format'] ) ) : 'json';
		$modules  = isset( $_POST['modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$modules  = DataSchema::exportable_modules( Exporter::modules_from( $modules, false ), $project_id );
		$options  = array( 'modules' => $modules, 'anonymize' => ! empty( $_POST['anonymize'] ), 'dictionary' => ! empty( $_POST['dictionary'] ), 'attachments' => 'zip' === $format );
		$document = Exporter::project( $project_id, $options );
		if ( is_wp_error( $document ) ) {
			wp_die( esc_html( $document->get_error_message() ) );
		}
		$base = sanitize_file_name( 'exportacion-' . $document['scope']['project_code'] . '-' . gmdate( 'Ymd-His' ) );
		switch ( $format ) {
			case 'zip':
				$binary = Exporter::to_zip( $document, true );
				$name   = $base . '.zip';
				$mime   = 'application/zip';
				break;
			case 'xlsx':
				$binary = Exporter::to_xlsx( $document );
				$name   = $base . '.xlsx';
				$mime   = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				break;
			case 'csv':
				$binary = Exporter::to_csv_zip( $document );
				$name   = $base . '-csv.zip';
				$mime   = 'application/zip';
				break;
			default:
				$binary = Exporter::to_json( $document );
				$name   = $base . '.json';
				$mime   = 'application/json';
		}
		if ( is_wp_error( $binary ) ) {
			wp_die( esc_html( $binary->get_error_message() ) );
		}
		Audit::log( 'project', $project_id, 'export', $project_id, sprintf( 'Exportación %s (%s)%s', $format, implode( ', ', (array) $document['scope']['modules'] ), ! empty( $options['anonymize'] ) ? ' anonimizada' : '' ) );
		self::send( $name, $mime, $binary );
	}

	/**
	 * Descarga el diccionario.
	 *
	 * @return void
	 */
	public static function handle_data_dictionary(): void {
		check_admin_referer( 'gdp_data_dictionary' );
		self::require_access();
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'csv';
		if ( 'json' === $format ) {
			self::send( 'diccionario-de-datos.json', 'application/json', (string) wp_json_encode( array( 'schema_version' => (int) GDP_DB_VERSION, 'tables' => DataDictionary::build() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		}
		self::send( 'diccionario-de-datos.csv', 'text/csv; charset=UTF-8', DataDictionary::to_csv() );
	}

	/**
	 * Sube el archivo y propone la importación.
	 *
	 * @return void
	 */
	public static function handle_data_upload(): void {
		check_admin_referer( 'gdp_data_upload' );
		$mode       = isset( $_POST['mode'] ) && 'update' === $_POST['mode'] ? 'update' : 'new';
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$back       = self::url( array( 'view' => 'import', 'project_id' => $project_id ) );
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Admin::redirect_with_notice( $back, __( 'Suba un archivo JSON o ZIP.', 'gestion-de-proyectos' ), 'error' );
		}
		$staged = DataHandler::stage( (string) $_FILES['file']['tmp_name'], (string) $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $staged ) ) {
			Admin::redirect_with_notice( $back, $staged->get_error_message(), 'error' );
		}
		$payload = array( 'file' => $staged, 'mode' => $mode, 'project_code' => isset( $_POST['project_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['project_code'] ) ) : '' );
		$op      = OperationManager::propose( 'data', 'import', $payload, 'update' === $mode ? $project_id : 0, 'admin' );
		if ( is_wp_error( $op ) ) {
			Admin::redirect_with_notice( $back, $op->get_error_message(), 'error' );
		}
		wp_safe_redirect( self::url( array( 'view' => 'proposal', 'op' => (int) $op['operation_id'], 'project_id' => $project_id ) ) );
		exit;
	}

	/**
	 * Confirma, recalcula o descarta una propuesta.
	 *
	 * @return void
	 */
	public static function handle_data_confirm(): void {
		$op_id = isset( $_POST['op'] ) ? (int) $_POST['op'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'gdp_data_confirm_' . $op_id );
		$op   = OperationManager::get( $op_id );
		$back = self::url( array( 'view' => 'proposal', 'op' => $op_id ) );
		if ( ! $op || 'data' !== $op['handler'] ) {
			Admin::redirect_with_notice( self::url( array( 'view' => 'import' ) ), __( 'La propuesta no existe.', 'gestion-de-proyectos' ), 'error' );
		}
		if ( ! empty( $_POST['cancel'] ) ) {
			OperationManager::cancel( $op_id );
			Admin::redirect_with_notice( self::url( array( 'view' => 'import', 'project_id' => (int) $op['project_id'] ) ), __( 'Propuesta descartada.', 'gestion-de-proyectos' ), 'info' );
		}
		if ( ! empty( $_POST['recalculate'] ) && 'import' === $op['action'] ) {
			$tables  = isset( $_POST['tables'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['tables'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$payload = (array) $op['payload'];
			$payload['tables'] = $tables;
			OperationManager::cancel( $op_id );
			$again = OperationManager::propose( 'data', 'import', $payload, (int) $op['project_id'], 'admin' );
			if ( is_wp_error( $again ) ) {
				Admin::redirect_with_notice( self::url( array( 'view' => 'import' ) ), $again->get_error_message(), 'error' );
			}
			wp_safe_redirect( self::url( array( 'view' => 'proposal', 'op' => (int) $again['operation_id'] ) ) );
			exit;
		}
		$result = OperationManager::confirm( $op_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		if ( 'restore_backup' === $op['action'] ) {
			/* translators: número de filas. */
			Admin::redirect_with_notice( self::url( array( 'view' => 'backups' ) ), sprintf( __( 'Respaldo restaurado: %d filas cargadas. La restauración se puede revertir desde Operaciones.', 'gestion-de-proyectos' ), (int) ( $result['result']['rows'] ?? 0 ) ) );
		}
		$project_id = (int) ( $result['result']['project_id'] ?? 0 );
		/* translators: 1: registros creados, 2: registros actualizados. */
		Admin::redirect_with_notice( $project_id > 0 ? Admin::url( 'projects', array( 'action' => 'view', 'id' => $project_id ) ) : self::url( array( 'view' => 'import' ) ), sprintf( __( 'Importación aplicada: %1$d registros creados y %2$d actualizados. Se puede revertir desde Operaciones.', 'gestion-de-proyectos' ), array_sum( (array) ( $result['result']['created'] ?? array() ) ), array_sum( (array) ( $result['result']['updated'] ?? array() ) ) ) );
	}

	/**
	 * Crea un respaldo.
	 *
	 * @return void
	 */
	public static function handle_backup_create(): void {
		check_admin_referer( 'gdp_backup_create' );
		self::require_manager();
		$meta = BackupService::create( isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '', 'manual' );
		if ( is_wp_error( $meta ) ) {
			Admin::redirect_with_notice( self::url( array( 'view' => 'backups' ) ), $meta->get_error_message(), 'error' );
		}
		/* translators: 1: nombre del archivo, 2: filas, 3: adjuntos. */
		Admin::redirect_with_notice( self::url( array( 'view' => 'backups' ) ), sprintf( __( 'Respaldo creado: %1$s (%2$d filas, %3$d adjuntos).', 'gestion-de-proyectos' ), $meta['name'], (int) $meta['rows'], (int) $meta['attachments'] ) );
	}

	/**
	 * Elimina un respaldo.
	 *
	 * @return void
	 */
	public static function handle_backup_delete(): void {
		$name = isset( $_POST['backup'] ) ? basename( sanitize_text_field( wp_unslash( (string) $_POST['backup'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'gdp_backup_delete_' . $name );
		self::require_manager();
		$ok = BackupService::delete( $name );
		Admin::redirect_with_notice( self::url( array( 'view' => 'backups' ) ), is_wp_error( $ok ) ? $ok->get_error_message() : __( 'Respaldo eliminado.', 'gestion-de-proyectos' ), is_wp_error( $ok ) ? 'error' : 'success' );
	}

	/**
	 * Propone la restauración de un respaldo.
	 *
	 * @return void
	 */
	public static function handle_backup_restore(): void {
		$name = isset( $_POST['backup'] ) ? basename( sanitize_text_field( wp_unslash( (string) $_POST['backup'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'gdp_backup_restore_' . $name );
		self::require_manager();
		$op = OperationManager::propose( 'data', 'restore_backup', array( 'backup' => $name ), 0, 'admin' );
		if ( is_wp_error( $op ) ) {
			Admin::redirect_with_notice( self::url( array( 'view' => 'backups' ) ), $op->get_error_message(), 'error' );
		}
		wp_safe_redirect( self::url( array( 'view' => 'proposal', 'op' => (int) $op['operation_id'] ) ) );
		exit;
	}

	/**
	 * Guarda la configuración de respaldos.
	 *
	 * @return void
	 */
	public static function handle_backup_settings(): void {
		check_admin_referer( 'gdp_backup_settings' );
		self::require_manager();
		$dir = isset( $_POST['backups_copy_dir'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['backups_copy_dir'] ) ) : '';
		Options::update(
			array(
				'backups_enabled'  => ! empty( $_POST['backups_enabled'] ),
				'backups_keep'     => max( 1, min( 52, isset( $_POST['backups_keep'] ) ? (int) $_POST['backups_keep'] : 8 ) ),
				'backups_copy_dir' => $dir,
			)
		);
		$notice = __( 'Configuración guardada.', 'gestion-de-proyectos' );
		if ( '' !== $dir && ( ! is_dir( $dir ) || ! is_writable( $dir ) ) ) {
			$notice .= ' ' . __( 'La carpeta de copia externa no existe o no admite escritura; se guardó igualmente.', 'gestion-de-proyectos' );
		}
		Admin::redirect_with_notice( self::url( array( 'view' => 'backups' ) ), $notice );
	}

	/**
	 * Envía un archivo al navegador.
	 *
	 * @param string $name   Nombre.
	 * @param string $mime   Tipo.
	 * @param string $binary Contenido.
	 * @return void
	 */
	private static function send( string $name, string $mime, string $binary ): void {
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $binary ) );
		echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
