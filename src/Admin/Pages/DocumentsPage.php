<?php
/**
 * Pantallas del control documental.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Roles;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\DocumentRepository;
use GDP\Modules\Documents\DocumentsTools;
use GDP\Modules\Documents\ExternalRefRepository;
use GDP\Modules\Documents\LetterTemplate;
use GDP\Modules\Documents\LinkRepository;
use GDP\Modules\Documents\Numbering;
use GDP\Modules\Documents\VersionRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lista con filtros, formulario, ficha del documento (versiones, vínculos,
 * referencias externas, historial) y descargas de borradores de carta.
 */
final class DocumentsPage extends Page {

	public const SLUG  = 'documents';
	public const VIEWS = array( 'list', 'edit', 'show' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Slug del menú principal.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		add_submenu_page( $parent, __( 'Documentos', 'gestion-de-proyectos' ), __( 'Documentos', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores de formularios.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_document', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_gdp_delete_document', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_gdp_document_status', array( self::class, 'handle_status' ) );
		add_action( 'admin_post_gdp_document_upload', array( self::class, 'handle_upload' ) );
		add_action( 'admin_post_gdp_document_link', array( self::class, 'handle_link' ) );
		add_action( 'admin_post_gdp_document_unlink', array( self::class, 'handle_unlink' ) );
		add_action( 'admin_post_gdp_document_ref', array( self::class, 'handle_ref' ) );
		add_action( 'admin_post_gdp_document_unref', array( self::class, 'handle_unref' ) );
		add_action( 'admin_post_gdp_document_template', array( self::class, 'handle_template' ) );
		add_action( 'admin_post_gdp_document_pattern', array( self::class, 'handle_pattern' ) );
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
	 * URL de una vista del módulo.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $args       Parámetros (view, id, filtros).
	 * @return string
	 */
	public static function url( int $project_id, array $args = array() ): string {
		return Admin::url( self::SLUG, array_merge( array( 'project_id' => $project_id ), $args ) );
	}

	/**
	 * Enrutador.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$project = PlanningPage::current_project();
		if ( ! $project ) {
			self::open( __( 'Documentos', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'No hay proyectos visibles. Cree uno o pida que lo añadan como miembro.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'documents.view', $project_id ) ) {
			self::open( __( 'Documentos', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'Sin permiso para ver los documentos de este proyecto.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		update_user_meta( get_current_user_id(), 'gdp_planning_project', $project_id );

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		switch ( in_array( $view, self::VIEWS, true ) ? $view : 'list' ) {
			case 'edit':
				self::render_form( $project );
				break;
			case 'show':
				self::render_show( $project );
				break;
			default:
				self::render_list( $project );
		}
	}

	/**
	 * Tarjeta en la ficha del proyecto.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	public static function project_card( array $p ): void {
		$project_id = (int) $p['id'];
		if ( ! Access::can( 'documents.view', $project_id ) ) {
			return;
		}
		$stats = DocumentRepository::stats( $project_id );
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Documentos', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Registrados', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['total']; ?></td></tr>
				<tr><th><?php esc_html_e( 'Abiertos', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['open']; ?></td></tr>
				<tr><th><?php esc_html_e( 'Respuesta vencida', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $stats['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $stats['overdue']; ?></td></tr>
				<tr><th><?php esc_html_e( 'Vencen en 7 días', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['due_soon']; ?></td></tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Ver documentos', 'gestion-de-proyectos' ); ?></a>
				<?php if ( Access::can( 'documents.edit', $project_id ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nuevo documento', 'gestion-de-proyectos' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Cabecera con selector de proyecto.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $title   Título.
	 * @return void
	 */
	private static function header( array $project, string $title ): void {
		$project_id = (int) $project['id'];
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		self::open( $title, sprintf( '%s · %s', $project['code'], $project['name'] ) );
		?>
		<div class="gdp-planning-bar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<label for="gdp-documents-project" class="screen-reader-text"><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></label>
				<select name="project_id" id="gdp-documents-project" onchange="this.form.submit()">
					<?php foreach ( $projects as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<a class="nav-tab nav-tab-active" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Documentos', 'gestion-de-proyectos' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( PlanningPage::url( $project_id, 'calendar' ) ); ?>"><?php esc_html_e( 'Calendario', 'gestion-de-proyectos' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'view', 'id' => $project_id ) ) ); ?>"><?php esc_html_e( 'Ficha del proyecto', 'gestion-de-proyectos' ); ?></a>
			</nav>
		</div>
		<?php
	}

	/**
	 * Lista con filtros.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_list( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'documents.edit', $project_id );
		$types      = DocumentRepository::types( $project_id );
		$labels     = DocumentRepository::status_labels();
		$filters    = array(
			'type'      => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'direction' => isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( (string) $_GET['direction'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'overdue'   => ! empty( $_GET['overdue'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'open'      => ! empty( $_GET['open'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$rows       = DocumentRepository::for_project( $project_id, array_filter( $filters ) );
		$stats      = DocumentRepository::stats( $project_id );
		$today      = current_time( 'Y-m-d' );
		$pattern    = (string) ( $project['settings']['documents']['pattern'] ?? Numbering::DEFAULT_PATTERN );

		self::header( $project, __( 'Documentos', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-summary">
			<span><strong><?php echo (int) $stats['total']; ?></strong> <?php esc_html_e( 'documentos', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $stats['open']; ?></strong> <?php esc_html_e( 'abiertos', 'gestion-de-proyectos' ); ?></span>
			<span class="<?php echo $stats['overdue'] > 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo (int) $stats['overdue']; ?></strong> <?php esc_html_e( 'con respuesta vencida', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo (int) $stats['due_soon']; ?></strong> <?php esc_html_e( 'vencen en 7 días', 'gestion-de-proyectos' ); ?></span>
		</div>

		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nuevo documento', 'gestion-de-proyectos' ); ?></a>
				<?php foreach ( array( 'carta', 'oficio' ) as $quick ) : ?>
					<?php if ( isset( $types[ $quick ] ) ) : ?>
						<a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => 0, 'type' => $quick ) ) ); ?>"><?php echo esc_html( $types[ $quick ]['label'] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<select name="type">
					<option value=""><?php esc_html_e( 'Todos los tipos', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $types as $slug => $t ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['type'], $slug ); ?>><?php echo esc_html( $t['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'Todos los estados', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $labels as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="direction">
					<option value=""><?php esc_html_e( 'Enviados y recibidos', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( DocumentRepository::direction_labels() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['direction'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="overdue" value="1" <?php checked( $filters['overdue'] ); ?>> <?php esc_html_e( 'Respuesta vencida', 'gestion-de-proyectos' ); ?></label>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
			</form>
		</div>

		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php echo 0 === $stats['total'] ? esc_html__( 'El proyecto aún no tiene documentos. Registre la primera carta, oficio o contrato.', 'gestion-de-proyectos' ) : esc_html__( 'Ningún documento coincide con el filtro.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<table class="widefat striped gdp-table gdp-documents">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Número', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Asunto', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'De / Para', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Respuesta', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th>
					<th class="gdp-num"><?php esc_html_e( 'Arch.', 'gestion-de-proyectos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $d ) : ?>
				<?php $overdue = DocumentsTools::is_overdue( $d ); ?>
				<tr class="<?php echo $overdue ? 'gdp-documents__overdue' : ''; ?>">
					<td><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $d['id'] ) ) ); ?>"><code><?php echo esc_html( '' !== $d['number'] ? $d['number'] : '#' . $d['id'] ); ?></code></a></td>
					<td><?php echo esc_html( (string) $d['doc_date'] ); ?></td>
					<td><?php echo esc_html( $types[ $d['type'] ]['label'] ?? $d['type'] ); ?><br><span class="gdp-muted gdp-small"><?php echo esc_html( 'in' === $d['direction'] ? __( 'recibido', 'gestion-de-proyectos' ) : ( 'out' === $d['direction'] ? __( 'enviado', 'gestion-de-proyectos' ) : __( 'interno', 'gestion-de-proyectos' ) ) ); ?></span></td>
					<td><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $d['id'] ) ) ); ?>"><?php echo esc_html( $d['subject'] ); ?></a></td>
					<td><?php echo esc_html( 'in' === $d['direction'] ? $d['sender'] : $d['recipient'] ); ?></td>
					<td><?php echo self::badge( $d['status'], $labels[ $d['status'] ] ?? $d['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="<?php echo $overdue ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( self::response_text( $d, $overdue, false ) ); ?></td>
					<td><?php echo esc_html( ScheduleService::user_name( $d['owner_id'] ) ); ?></td>
					<td class="gdp-num"><?php echo (int) $d['current_version']; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( Access::can( 'project.edit', $project_id ) ) : ?>
			<div class="gdp-card gdp-card--compact">
				<h3><?php esc_html_e( 'Numeración correlativa', 'gestion-de-proyectos' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
					<?php wp_nonce_field( 'gdp_document_pattern_' . $project_id ); ?>
					<input type="hidden" name="action" value="gdp_document_pattern">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<label for="gdp-doc-pattern"><?php esc_html_e( 'Patrón', 'gestion-de-proyectos' ); ?></label>
					<input type="text" id="gdp-doc-pattern" name="pattern" class="regular-text" value="<?php echo esc_attr( $pattern ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
					<span class="gdp-muted gdp-small"><?php esc_html_e( 'Marcadores: {PREFIJO} (prefijo del tipo), {NNN} (correlativo con ceros), {N}, {AAAA}, {AA}, {PROYECTO}. Con año en el patrón, el correlativo se reinicia cada año. Ejemplo:', 'gestion-de-proyectos' ); ?> <code><?php echo esc_html( Numbering::format( $pattern, 'CARTA', 12, (int) substr( $today, 0, 4 ), (string) $project['code'] ) ); ?></code></span>
				</form>
			</div>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Formulario de creación o edición.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_form( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'documents.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para editar documentos.', 'gestion-de-proyectos' ), 403 );
		}
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$types  = DocumentRepository::types( $project_id );
		$labels = DocumentRepository::status_labels();
		$d      = $id > 0 ? DocumentRepository::find( $id ) : null;
		if ( $id > 0 && ( ! $d || $d['project_id'] !== $project_id ) ) {
			wp_die( esc_html__( 'El documento no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$preset = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$d      = $d ?? array(
			'id'           => 0,
			'type'         => isset( $types[ $preset ] ) ? $preset : ( isset( $types['carta'] ) ? 'carta' : (string) array_key_first( $types ) ),
			'direction'    => '',
			'number'       => '',
			'doc_date'     => current_time( 'Y-m-d' ),
			'sender'       => '',
			'recipient'    => '',
			'subject'      => '',
			'body'         => '',
			'status'       => 'borrador',
			'response_due' => null,
			'responded_at' => null,
			'owner_id'     => get_current_user_id(),
			'activity_id'  => 0,
			'notes'        => '',
			'version'      => 0,
		);
		$activities = \GDP\Modules\Planning\ActivityRepository::for_project( $project_id );
		$users      = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name' ) );

		self::header( $project, $id > 0 ? __( 'Editar documento', 'gestion-de-proyectos' ) : __( 'Nuevo documento', 'gestion-de-proyectos' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form" enctype="multipart/form-data">
			<?php wp_nonce_field( 'gdp_save_document_' . $id ); ?>
			<input type="hidden" name="action" value="gdp_save_document">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="expected_version" value="<?php echo (int) $d['version']; ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gdp-doc-type"><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select name="type" id="gdp-doc-type">
							<?php foreach ( $types as $slug => $t ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $d['type'], $slug ); ?>><?php echo esc_html( $t['label'] . ( $t['numbered'] ? ' · ' . __( 'numerado', 'gestion-de-proyectos' ) : '' ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="direction" aria-label="<?php esc_attr_e( 'Sentido', 'gestion-de-proyectos' ); ?>">
							<option value=""><?php esc_html_e( 'Sentido según el tipo', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( DocumentRepository::direction_labels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $d['direction'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-number"><?php esc_html_e( 'Número', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-doc-number" name="number" class="regular-text" value="<?php echo esc_attr( (string) $d['number'] ); ?>" placeholder="<?php esc_attr_e( 'Automático en los tipos numerados', 'gestion-de-proyectos' ); ?>"> <span class="description"><?php esc_html_e( 'Déjelo vacío para asignar el correlativo; en los documentos recibidos, escriba el número del emisor.', 'gestion-de-proyectos' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-date"><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="date" id="gdp-doc-date" name="doc_date" value="<?php echo esc_attr( (string) $d['doc_date'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-subject"><?php esc_html_e( 'Asunto', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-doc-subject" name="subject" class="large-text" required value="<?php echo esc_attr( (string) $d['subject'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-sender"><?php esc_html_e( 'Emisor', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-doc-sender" name="sender" class="regular-text" value="<?php echo esc_attr( (string) $d['sender'] ); ?>"> <span class="description"><?php esc_html_e( 'Quien firma o envía (vacío = el proyecto).', 'gestion-de-proyectos' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-recipient"><?php esc_html_e( 'Destinatario', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-doc-recipient" name="recipient" class="regular-text" value="<?php echo esc_attr( (string) $d['recipient'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-body"><?php esc_html_e( 'Cuerpo o resumen', 'gestion-de-proyectos' ); ?></label></th>
					<td><textarea id="gdp-doc-body" name="body" rows="8" class="large-text"><?php echo esc_textarea( (string) $d['body'] ); ?></textarea> <span class="description"><?php esc_html_e( 'En las cartas, el texto que se vuelca en el borrador LaTeX o Word; en los documentos recibidos, un resumen.', 'gestion-de-proyectos' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-status"><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select name="status" id="gdp-doc-status">
							<?php foreach ( $labels as $slug => $label ) : ?>
								<?php if ( 'aprobado' === $slug && ! Access::can( 'documents.approve', $project_id ) && 'aprobado' !== $d['status'] ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $d['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-due"><?php esc_html_e( 'Respuesta esperada hasta', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="date" id="gdp-doc-due" name="response_due" value="<?php echo esc_attr( (string) $d['response_due'] ); ?>"> <label><?php esc_html_e( 'Respondido el', 'gestion-de-proyectos' ); ?> <input type="date" name="responded_at" value="<?php echo esc_attr( (string) $d['responded_at'] ); ?>"></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-owner"><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select name="owner_id" id="gdp-doc-owner">
							<option value="0"><?php esc_html_e( 'Sin responsable', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $users as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $d['owner_id'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-activity"><?php esc_html_e( 'Actividad relacionada', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select name="activity_id" id="gdp-doc-activity">
							<option value="0"><?php esc_html_e( 'Ninguna', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $activities as $a ) : ?>
								<option value="<?php echo (int) $a['id']; ?>" <?php selected( (int) $d['activity_id'], (int) $a['id'] ); ?>><?php echo esc_html( $a['code'] . ' ' . $a['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-doc-notes"><?php esc_html_e( 'Notas internas', 'gestion-de-proyectos' ); ?></label></th>
					<td><textarea id="gdp-doc-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $d['notes'] ); ?></textarea></td>
				</tr>
				<?php if ( 0 === $id ) : ?>
				<tr>
					<th scope="row"><label for="gdp-doc-file"><?php esc_html_e( 'Archivo', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="file" id="gdp-doc-file" name="file" accept="<?php echo esc_attr( '.' . implode( ',.', VersionRepository::EXTENSIONS ) ); ?>"> <span class="description"><?php esc_html_e( 'Opcional; queda como versión 1. Podrá subir nuevas versiones desde la ficha.', 'gestion-de-proyectos' ); ?></span></td>
				</tr>
				<?php endif; ?>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $id > 0 ? esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ) : esc_html__( 'Registrar documento', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( $id > 0 ? self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ) : self::url( $project_id ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a>
			</p>
		</form>
		<?php
		self::close();
	}

	/**
	 * Ficha del documento.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_show( array $project ): void {
		$project_id = (int) $project['id'];
		$id         = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$d          = DocumentRepository::find( $id );
		if ( ! $d || $d['project_id'] !== $project_id ) {
			wp_die( esc_html__( 'El documento no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$can_edit    = Access::can( 'documents.edit', $project_id );
		$can_approve = Access::can( 'documents.approve', $project_id );
		$types       = DocumentRepository::types( $project_id );
		$labels      = DocumentRepository::status_labels();
		$versions    = VersionRepository::for_document( $id );
		$links       = LinkRepository::for_entity( 'document', $id );
		$refs        = ExternalRefRepository::for_entity( 'document', $id );
		$entities    = LinkRepository::entities();
		$overdue     = DocumentsTools::is_overdue( $d );
		$history     = array_values( array_filter( Audit::recent( 200, $project_id ), static fn( array $h ): bool => 'document' === $h['entity_type'] && (int) $h['entity_id'] === $id ) );
		$activity    = $d['activity_id'] > 0 ? \GDP\Modules\Planning\ActivityRepository::find( $d['activity_id'] ) : null;

		self::header( $project, trim( $d['number'] . ' ' . $d['subject'] ) );
		?>
		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Editar', 'gestion-de-proyectos' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
					<?php wp_nonce_field( 'gdp_document_status_' . $id ); ?>
					<input type="hidden" name="action" value="gdp_document_status">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<select name="status" aria-label="<?php esc_attr_e( 'Nuevo estado', 'gestion-de-proyectos' ); ?>">
						<?php foreach ( $labels as $slug => $label ) : ?>
							<?php if ( 'aprobado' === $slug && ! $can_approve ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $d['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Cambiar estado', 'gestion-de-proyectos' ); ?></button>
				</form>
			<?php endif; ?>
			<span class="gdp-export-links">
				<?php esc_html_e( 'Borrador de carta:', 'gestion-de-proyectos' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_document_template&id=' . $id . '&format=tex' ), 'gdp_document_template_' . $id ) ); ?>">LaTeX</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_document_template&id=' . $id . '&format=docx' ), 'gdp_document_template_' . $id ) ); ?>">Word</a>
			</span>
			<?php if ( $can_edit ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form gdp-documents__delete" data-confirm="1">
					<?php wp_nonce_field( 'gdp_delete_document_' . $id ); ?>
					<input type="hidden" name="action" value="gdp_delete_document">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Datos', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Número', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( '' !== $d['number'] ? $d['number'] : '—' ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( ( $types[ $d['type'] ]['label'] ?? $d['type'] ) . ' · ' . ( DocumentRepository::direction_labels()[ $d['direction'] ] ?? $d['direction'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $d['doc_date'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Emisor', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $d['sender'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Destinatario', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $d['recipient'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><td><?php echo self::badge( $d['status'], $labels[ $d['status'] ] ?? $d['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<tr><th><?php esc_html_e( 'Respuesta', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $overdue ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( self::response_text( $d, $overdue, true ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( ScheduleService::user_name( $d['owner_id'] ) ); ?></td></tr>
					<?php if ( $activity ) : ?>
						<tr><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><td><a href="<?php echo esc_url( PlanningPage::url( $project_id, 'edit', array( 'id' => $activity['id'] ) ) ); ?>"><?php echo esc_html( $activity['code'] . ' ' . $activity['name'] ); ?></a></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'Versión del registro', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $d['version']; ?></td></tr>
				</table>
				<?php if ( '' !== $d['body'] ) : ?>
					<h3><?php esc_html_e( 'Cuerpo o resumen', 'gestion-de-proyectos' ); ?></h3>
					<div class="gdp-documents__body"><?php echo wp_kses_post( wpautop( $d['body'] ) ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $d['notes'] ) : ?>
					<h3><?php esc_html_e( 'Notas internas', 'gestion-de-proyectos' ); ?></h3>
					<div class="gdp-documents__body gdp-muted"><?php echo wp_kses_post( wpautop( $d['notes'] ) ); ?></div>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Versiones de archivo', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $versions ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin archivo adjunto.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th><?php esc_html_e( 'Versión', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Archivo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Subido por', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_reverse( $versions ) as $v ) : ?>
							<tr>
								<td><?php echo (int) $v['version_no']; ?><?php echo $v['version_no'] === $d['current_version'] ? ' ' . esc_html__( '(vigente)', 'gestion-de-proyectos' ) : ''; ?></td>
								<td><a href="<?php echo esc_url( VersionRepository::download_url( $v ) ); ?>"><?php echo esc_html( $v['filename'] ); ?></a> <span class="gdp-muted"><?php echo esc_html( size_format( (int) $v['size'] ) ); ?></span><br><code class="gdp-small"><?php echo esc_html( substr( $v['sha256'], 0, 16 ) ); ?>…</code></td>
								<td><?php echo esc_html( $v['note'] ); ?></td>
								<td><?php echo esc_html( $v['uploaded_name'] ); ?></td>
								<td><?php echo esc_html( self::date( $v['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="gdp-form-row">
						<?php wp_nonce_field( 'gdp_document_upload_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_document_upload">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="file" name="file" required accept="<?php echo esc_attr( '.' . implode( ',.', VersionRepository::EXTENSIONS ) ); ?>">
						<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota de la versión', 'gestion-de-proyectos' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Subir nueva versión', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Vínculos', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $links ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin vínculos con otros documentos o actividades.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul class="gdp-list">
					<?php foreach ( $links as $l ) : ?>
						<li>
							<?php echo esc_html( $l['relation_label'] ); ?>
							<?php echo esc_html( $l['entity']['label'] ); ?>
							<?php if ( '' !== $l['entity']['url'] ) : ?>
								<a href="<?php echo esc_url( $l['entity']['url'] ); ?>"><?php echo esc_html( trim( $l['entity']['code'] . ' ' . $l['entity']['title'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( trim( $l['entity']['code'] . ' ' . $l['entity']['title'] ) ); ?>
							<?php endif; ?>
							<?php if ( '' !== $l['note'] ) : ?><span class="gdp-muted">(<?php echo esc_html( $l['note'] ); ?>)</span><?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
									<?php wp_nonce_field( 'gdp_document_unlink_' . $l['id'] ); ?>
									<input type="hidden" name="action" value="gdp_document_unlink">
									<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
									<input type="hidden" name="link_id" value="<?php echo (int) $l['id']; ?>">
									<button type="submit" class="button-link gdp-button-link-danger"><?php esc_html_e( 'quitar', 'gestion-de-proyectos' ); ?></button>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
						<?php wp_nonce_field( 'gdp_document_link_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_document_link">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<select name="relation">
							<?php foreach ( LinkRepository::relation_labels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="target" required>
							<option value=""><?php esc_html_e( 'Elija el destino', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $entities as $type => $def ) : ?>
								<?php if ( empty( $def['search'] ) || ! is_callable( $def['search'] ) ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<optgroup label="<?php echo esc_attr( ucfirst( (string) $def['label'] ) ); ?>">
									<?php foreach ( (array) call_user_func( $def['search'], $project_id, '' ) as $opt ) : ?>
										<?php if ( 'document' === $type && (int) $opt['id'] === $id ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<option value="<?php echo esc_attr( $type . ':' . (int) $opt['id'] ); ?>"><?php echo esc_html( trim( $opt['code'] . ' ' . $opt['title'] ) ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota', 'gestion-de-proyectos' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Vincular', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Referencias externas', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $refs ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin referencias en sistemas institucionales.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th><?php esc_html_e( 'Sistema', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Número', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Actualizado', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $refs as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r['system_name'] ); ?></td>
								<td><?php if ( '' !== $r['url'] ) : ?><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $r['ref_number'] ? $r['ref_number'] : __( 'enlace', 'gestion-de-proyectos' ) ); ?></a><?php else : ?><?php echo esc_html( $r['ref_number'] ); ?><?php endif; ?></td>
								<td><?php echo esc_html( $r['ref_status'] ); ?></td>
								<td><?php echo esc_html( self::date( $r['updated_at'] ) ); ?></td>
								<td>
									<?php if ( $can_edit ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
											<?php wp_nonce_field( 'gdp_document_unref_' . $r['id'] ); ?>
											<input type="hidden" name="action" value="gdp_document_unref">
											<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
											<input type="hidden" name="ref_id" value="<?php echo (int) $r['id']; ?>">
											<button type="submit" class="button-link gdp-button-link-danger"><?php esc_html_e( 'quitar', 'gestion-de-proyectos' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
						<?php wp_nonce_field( 'gdp_document_ref_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_document_ref">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="text" name="system" required placeholder="<?php esc_attr_e( 'Sistema', 'gestion-de-proyectos' ); ?>">
						<input type="text" name="number" placeholder="<?php esc_attr_e( 'Número', 'gestion-de-proyectos' ); ?>">
						<input type="text" name="status" placeholder="<?php esc_attr_e( 'Estado', 'gestion-de-proyectos' ); ?>">
						<input type="url" name="url" placeholder="https://">
						<button type="submit" class="button"><?php esc_html_e( 'Guardar referencia', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Historial', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $history ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin entradas en la bitácora.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul class="gdp-list gdp-small">
					<?php foreach ( $history as $h ) : ?>
						<li><span class="gdp-muted"><?php echo esc_html( self::date( (string) $h['created_at'] ) ); ?></span> · <?php echo esc_html( ScheduleService::user_name( (int) $h['user_id'] ) ); ?> · <?php echo esc_html( (string) $h['summary'] ); ?> <span class="gdp-muted">(<?php echo esc_html( (string) $h['channel'] ); ?>)</span></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Texto del estado de la respuesta de un documento.
	 *
	 * @param array<string,mixed> $d       Documento.
	 * @param bool                $overdue Si está vencida.
	 * @param bool                $long    Forma larga (ficha) o corta (lista).
	 * @return string
	 */
	private static function response_text( array $d, bool $overdue, bool $long ): string {
		if ( $d['responded_at'] ) {
			/* translators: fecha de respuesta. */
			return sprintf( $long ? __( 'respondido el %s', 'gestion-de-proyectos' ) : __( 'respondido %s', 'gestion-de-proyectos' ), $d['responded_at'] );
		}
		if ( ! $d['response_due'] ) {
			return $long ? __( 'sin plazo', 'gestion-de-proyectos' ) : '';
		}
		if ( $overdue ) {
			/* translators: fecha límite de respuesta. */
			return sprintf( $long ? __( 'vencida el %s', 'gestion-de-proyectos' ) : __( 'vencida %s', 'gestion-de-proyectos' ), $d['response_due'] );
		}

		/* translators: fecha límite de respuesta. */
		return sprintf( $long ? __( 'esperada hasta el %s', 'gestion-de-proyectos' ) : __( 'hasta %s', 'gestion-de-proyectos' ), $d['response_due'] );
	}

	/**
	 * Guarda (crea o actualiza) mediante la capa de operaciones.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_save_document_' . $id );
		if ( ! Access::can( 'documents.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$data = array();
		foreach ( array( 'type', 'direction', 'number', 'doc_date', 'sender', 'recipient', 'subject', 'status', 'response_due', 'responded_at' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		foreach ( array( 'body', 'notes' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_kses_post( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		$data['owner_id']    = isset( $_POST['owner_id'] ) ? (int) $_POST['owner_id'] : 0;
		$data['activity_id'] = isset( $_POST['activity_id'] ) ? (int) $_POST['activity_id'] : 0;
		if ( isset( $data['status'] ) && 'aprobado' === $data['status'] && ! Access::can( 'documents.approve', $project_id ) ) {
			$current = $id > 0 ? DocumentRepository::find( $id ) : null;
			if ( ! $current || 'aprobado' !== $current['status'] ) {
				Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'edit', 'id' => $id ) ), __( 'La aprobación está reservada al director del proyecto.', 'gestion-de-proyectos' ), 'error' );
			}
		}

		if ( 0 === $id ) {
			$result = OperationManager::execute( 'document', 'create', array( 'data' => $data ), $project_id );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ), implode( ' ', $result->get_error_messages() ), 'error' );
			}
			$new_id = (int) ( $result['result']['document_id'] ?? 0 );
			if ( ! empty( $_FILES['file']['tmp_name'] ) && $new_id > 0 ) {
				$document = DocumentRepository::find( $new_id );
				$version  = VersionRepository::store_upload( $document, $_FILES['file'], __( 'Versión inicial', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_wp_error( $version ) ) {
					/* translators: mensaje de error. */
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => $new_id ) ), sprintf( __( 'Documento registrado, pero el archivo no se guardó: %s', 'gestion-de-proyectos' ), $version->get_error_message() ), 'warning' );
				}
			}
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => $new_id ) ), __( 'Documento registrado.', 'gestion-de-proyectos' ) );
		}

		$expected = isset( $_POST['expected_version'] ) ? (int) $_POST['expected_version'] : null;
		$result   = OperationManager::execute( 'document', 'update', array( 'document_id' => $id, 'data' => $data, 'expected_version' => $expected ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'edit', 'id' => $id ) ), implode( ' ', $result->get_error_messages() ), 'error' );
		}
		Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ), __( 'Documento actualizado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina (operación reversible).
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_document_' . $id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$result = OperationManager::execute( 'document', 'delete', array( 'document_id' => $id ), $d['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) ), $result->get_error_message(), 'error' );
		}
		/* translators: URL de la papelera. */
		Admin::redirect_with_notice( self::url( $d['project_id'] ), sprintf( __( 'Documento eliminado. Puede restaurarlo desde la <a href="%s">papelera</a>.', 'gestion-de-proyectos' ), esc_url( Admin::url( 'trash', array( 'project_id' => $d['project_id'] ) ) ) ) );
	}

	/**
	 * Cambio de estado.
	 *
	 * @return void
	 */
	public static function handle_status(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_document_status_' . $id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
		$result = OperationManager::execute( 'document', 'set_status', array( 'document_id' => $id, 'status' => $status ), $d['project_id'] );
		$back   = self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Estado actualizado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Nueva versión de archivo.
	 *
	 * @return void
	 */
	public static function handle_upload(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_document_upload_' . $id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back    = self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$version = VersionRepository::store_upload( $d, isset( $_FILES['file'] ) ? $_FILES['file'] : array(), isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $version ) ) {
			Admin::redirect_with_notice( $back, $version->get_error_message(), 'error' );
		}
		/* translators: número de versión. */
		Admin::redirect_with_notice( $back, sprintf( __( 'Versión %d guardada.', 'gestion-de-proyectos' ), (int) $version['version_no'] ) );
	}

	/**
	 * Crea un vínculo.
	 *
	 * @return void
	 */
	public static function handle_link(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_document_link_' . $id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target'] ) ) : '';
		$parts  = explode( ':', $target, 2 );
		$result = OperationManager::execute(
			'document',
			'link',
			array(
				'document_id' => $id,
				'to_type'     => sanitize_key( $parts[0] ?? '' ),
				'to_id'       => (int) ( $parts[1] ?? 0 ),
				'relation'    => isset( $_POST['relation'] ) ? sanitize_key( wp_unslash( (string) $_POST['relation'] ) ) : 'refers_to',
				'note'        => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '',
			),
			$d['project_id']
		);
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Vínculo creado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Quita un vínculo.
	 *
	 * @return void
	 */
	public static function handle_unlink(): void {
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$link_id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;
		check_admin_referer( 'gdp_document_unlink_' . $link_id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute( 'document', 'unlink', array( 'link_id' => $link_id ), $d['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Vínculo eliminado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Fija una referencia externa.
	 *
	 * @return void
	 */
	public static function handle_ref(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_document_ref_' . $id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute(
			'document',
			'set_external_ref',
			array(
				'document_id' => $id,
				'system'      => isset( $_POST['system'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['system'] ) ) : '',
				'number'      => isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['number'] ) ) : '',
				'status'      => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : '',
				'url'         => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '',
			),
			$d['project_id']
		);
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Referencia guardada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Quita una referencia externa.
	 *
	 * @return void
	 */
	public static function handle_unref(): void {
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$ref_id = isset( $_POST['ref_id'] ) ? (int) $_POST['ref_id'] : 0;
		check_admin_referer( 'gdp_document_unref_' . $ref_id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.edit', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $d['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute( 'document', 'remove_external_ref', array( 'ref_id' => $ref_id ), $d['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Referencia eliminada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Descarga del borrador de carta.
	 *
	 * @return void
	 */
	public static function handle_template(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'gdp_document_template_' . $id );
		$d = DocumentRepository::find( $id );
		if ( ! $d || ! Access::can( 'documents.view', $d['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'tex';
		$base   = sanitize_file_name( 'carta-' . ( '' !== $d['number'] ? $d['number'] : 'documento-' . $id ) );
		if ( 'docx' === $format ) {
			$body = LetterTemplate::docx( $d );
			if ( is_wp_error( $body ) ) {
				wp_die( esc_html( $body->get_error_message() ), 500 );
			}
			$type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$ext  = 'docx';
		} else {
			$body = LetterTemplate::latex( $d );
			$type = 'application/x-tex; charset=UTF-8';
			$ext  = 'tex';
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $base . '.' . $ext . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga de archivo generado.
		exit;
	}

	/**
	 * Guarda el patrón de numeración del proyecto.
	 *
	 * @return void
	 */
	public static function handle_pattern(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_document_pattern_' . $project_id );
		$project = ProjectRepository::find( $project_id );
		if ( ! $project || ! Access::can( 'project.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$pattern  = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pattern'] ) ) : '';
		$settings = is_array( $project['settings'] ) ? $project['settings'] : array();
		$settings['documents']            = is_array( $settings['documents'] ?? null ) ? $settings['documents'] : array();
		$settings['documents']['pattern'] = '' === $pattern ? Numbering::DEFAULT_PATTERN : $pattern;
		$result                           = ProjectRepository::update( $project_id, array( 'settings' => $settings ), null );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $project_id ), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( self::url( $project_id ), __( 'Patrón de numeración guardado.', 'gestion-de-proyectos' ) );
	}
}
