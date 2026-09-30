<?php
/**
 * Editor de catálogos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Catalogs;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Frentes de trabajo, partidas, tipos de documento y demás vocabularios,
 * globales (solo administradores) o propios de un proyecto (quien puede
 * editar el proyecto). Una entrada de proyecto con la misma clave sustituye
 * a la global.
 */
final class CatalogsPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_catalog_item', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_gdp_delete_catalog_item', array( self::class, 'handle_delete' ) );
	}

	/**
	 * Pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$labels     = Catalogs::labels();
		$catalog    = isset( $_GET['catalog'] ) ? sanitize_key( wp_unslash( (string) $_GET['catalog'] ) ) : Catalogs::WORK_FRONT; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$catalog    = isset( $labels[ $catalog ] ) ? $catalog : Catalogs::WORK_FRONT;
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		if ( $project_id > 0 && ! Access::can( 'project.view', $project_id ) ) {
			$project_id = 0;
		}
		if ( 0 === $project_id && ! Access::is_manager() && ! empty( $projects ) ) {
			$project_id = (int) $projects[0]['id'];
		}
		$can_edit = $project_id > 0 ? Access::can( 'project.edit', $project_id ) : Access::is_manager();
		$items    = Catalogs::all_items( $catalog, $project_id );
		$global   = $project_id > 0 ? Catalogs::all_items( $catalog, 0 ) : array();
		$back     = Admin::url( 'catalogs', array( 'catalog' => $catalog, 'project_id' => $project_id ) );

		self::open( __( 'Catálogos', 'gestion-de-proyectos' ), __( 'Vocabulario configurable: los proyectos heredan las entradas globales y pueden añadir o sustituir las suyas.', 'gestion-de-proyectos' ) );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-catalogs' ); ?>">
			<label for="gdp-cat-catalog"><?php esc_html_e( 'Catálogo', 'gestion-de-proyectos' ); ?></label>
			<select name="catalog" id="gdp-cat-catalog" onchange="this.form.submit()">
				<?php foreach ( $labels as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $catalog ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="gdp-cat-project"><?php esc_html_e( 'Ámbito', 'gestion-de-proyectos' ); ?></label>
			<select name="project_id" id="gdp-cat-project" onchange="this.form.submit()">
				<?php if ( Access::is_manager() ) : ?>
					<option value="0" <?php selected( 0, $project_id ); ?>><?php esc_html_e( 'Global (todos los proyectos)', 'gestion-de-proyectos' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $projects as $p ) : ?>
					<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php echo esc_html( $labels[ $catalog ] ); ?> <span class="gdp-muted">(<?php echo $project_id > 0 ? esc_html__( 'del proyecto', 'gestion-de-proyectos' ) : esc_html__( 'globales', 'gestion-de-proyectos' ); ?>)</span></h2>
				<?php if ( empty( $items ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin entradas propias.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Orden', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Clave', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Activa', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<?php if ( $can_edit ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="gdp-cat-<?php echo (int) $item['id']; ?>">
									<?php wp_nonce_field( 'gdp_save_catalog_item' ); ?>
									<input type="hidden" name="action" value="gdp_save_catalog_item">
									<input type="hidden" name="catalog" value="<?php echo esc_attr( $catalog ); ?>">
									<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
									<input type="hidden" name="slug" value="<?php echo esc_attr( $item['slug'] ); ?>">
								</form>
								<td><input form="gdp-cat-<?php echo (int) $item['id']; ?>" type="number" name="sort_order" value="<?php echo (int) $item['sort_order']; ?>" class="small-text"></td>
								<td><code><?php echo esc_html( $item['slug'] ); ?></code></td>
								<td><input form="gdp-cat-<?php echo (int) $item['id']; ?>" type="text" name="label" value="<?php echo esc_attr( $item['label'] ); ?>" class="regular-text"></td>
								<td><input form="gdp-cat-<?php echo (int) $item['id']; ?>" type="text" name="description" value="<?php echo esc_attr( (string) $item['description'] ); ?>" class="regular-text"></td>
								<td><input form="gdp-cat-<?php echo (int) $item['id']; ?>" type="checkbox" name="active" value="1" <?php checked( $item['active'] ); ?>></td>
								<td class="gdp-actions">
									<button form="gdp-cat-<?php echo (int) $item['id']; ?>" type="submit" class="button button-small"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
										<?php wp_nonce_field( 'gdp_delete_catalog_item_' . $item['id'] ); ?>
										<input type="hidden" name="action" value="gdp_delete_catalog_item">
										<input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
										<input type="hidden" name="catalog" value="<?php echo esc_attr( $catalog ); ?>">
										<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
										<button type="submit" class="button-link gdp-link-danger"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
									</form>
								</td>
								<?php else : ?>
								<td><?php echo (int) $item['sort_order']; ?></td>
								<td><code><?php echo esc_html( $item['slug'] ); ?></code></td>
								<td><?php echo esc_html( $item['label'] ); ?></td>
								<td><?php echo esc_html( (string) $item['description'] ); ?></td>
								<td><?php echo $item['active'] ? '✓' : ''; ?></td>
								<td></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $can_edit ) : ?>
				<h3><?php esc_html_e( 'Nueva entrada', 'gestion-de-proyectos' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
					<?php wp_nonce_field( 'gdp_save_catalog_item' ); ?>
					<input type="hidden" name="action" value="gdp_save_catalog_item">
					<input type="hidden" name="catalog" value="<?php echo esc_attr( $catalog ); ?>">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<input type="text" name="label" required placeholder="<?php esc_attr_e( 'Nombre', 'gestion-de-proyectos' ); ?>" class="regular-text">
					<input type="text" name="slug" placeholder="<?php esc_attr_e( 'Clave (opcional)', 'gestion-de-proyectos' ); ?>">
					<input type="number" name="sort_order" value="<?php echo count( $items ) + 1; ?>" class="small-text" aria-label="<?php esc_attr_e( 'Orden', 'gestion-de-proyectos' ); ?>">
					<input type="hidden" name="active" value="1">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Añadir', 'gestion-de-proyectos' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'La clave es el identificador estable que usan las exportaciones y el conector; se genera del nombre si se deja vacía y no debe cambiarse después.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $project_id > 0 ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Entradas globales heredadas', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $global ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'No hay entradas globales para este catálogo.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul class="gdp-list">
						<?php foreach ( $global as $item ) : ?>
							<li><code><?php echo esc_html( $item['slug'] ); ?></code> <?php echo esc_html( $item['label'] ); ?><?php echo $item['active'] ? '' : ' <span class="gdp-muted">(' . esc_html__( 'inactiva', 'gestion-de-proyectos' ) . ')</span>'; ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="description"><?php esc_html_e( 'Para sustituir una entrada global en este proyecto, cree una con la misma clave.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Comprueba permisos sobre un ámbito.
	 *
	 * @param int $project_id Ámbito.
	 * @return void
	 */
	private static function guard( int $project_id ): void {
		$allowed = $project_id > 0 ? Access::can( 'project.edit', $project_id ) : Access::is_manager();
		if ( ! $allowed ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
	}

	/**
	 * Crea o actualiza una entrada.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		check_admin_referer( 'gdp_save_catalog_item' );
		$catalog    = isset( $_POST['catalog'] ) ? sanitize_key( wp_unslash( (string) $_POST['catalog'] ) ) : '';
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		self::guard( $project_id );

		$result = Catalogs::save_item(
			$catalog,
			$project_id,
			isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slug'] ) ) : '',
			isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '',
			isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['description'] ) ) : '',
			isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			! empty( $_POST['active'] )
		);
		$back = Admin::url( 'catalogs', array( 'catalog' => $catalog, 'project_id' => $project_id ) );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Entrada guardada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una entrada.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_catalog_item_' . $id );
		$catalog    = isset( $_POST['catalog'] ) ? sanitize_key( wp_unslash( (string) $_POST['catalog'] ) ) : '';
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		self::guard( $project_id );

		Catalogs::delete_item( $id );
		Admin::redirect_with_notice( Admin::url( 'catalogs', array( 'catalog' => $catalog, 'project_id' => $project_id ) ), __( 'Entrada eliminada. Los registros que la usaban conservan la clave.', 'gestion-de-proyectos' ) );
	}
}
