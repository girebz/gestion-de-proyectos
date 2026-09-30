<?php
/**
 * Papelera: eliminaciones que pueden restaurarse.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Toda eliminación de una entidad de módulo pasa por la capa de operaciones y
 * guarda una instantánea; esta pantalla las lista y permite restaurarlas. Lo
 * único que se borra de forma definitiva es un proyecto completo, que exige
 * escribir su código.
 */
final class TrashPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_restore', array( self::class, 'handle_restore' ) );
	}

	/**
	 * Pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		$rows       = OperationManager::trash( $project_id > 0 ? $project_id : null, 200 );
		$names      = array();
		foreach ( $projects as $p ) {
			$names[ (int) $p['id'] ] = $p['code'];
		}

		self::open( __( 'Papelera', 'gestion-de-proyectos' ), __( 'Eliminaciones con instantánea: se restauran con sus dependencias, asignaciones e historial.', 'gestion-de-proyectos' ) );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form" style="margin-bottom:12px">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-trash' ); ?>">
			<label for="gdp-trash-project" class="screen-reader-text"><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></label>
			<select name="project_id" id="gdp-trash-project" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos los proyectos', 'gestion-de-proyectos' ); ?></option>
				<?php foreach ( $projects as $p ) : ?>
					<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'La papelera está vacía.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
			<table class="widefat striped gdp-table">
				<thead><tr>
					<th>#</th>
					<th><?php esc_html_e( 'Eliminado', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Por', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Canal', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $op ) : ?>
					<?php
					$user       = get_userdata( (int) $op['user_id'] );
					$restorable = Access::is_manager() || ( $op['project_id'] > 0 && Access::can( 'operations.confirm', (int) $op['project_id'] ) );
					?>
					<tr>
						<td><a href="<?php echo esc_url( Admin::url( 'operations', array( 'id' => $op['id'] ) ) ); ?>">#<?php echo (int) $op['id']; ?></a></td>
						<td><?php echo esc_html( $op['summary'] ); ?> <code class="gdp-small"><?php echo esc_html( $op['handler'] . '.' . $op['action'] ); ?></code></td>
						<td><?php echo esc_html( $names[ (int) $op['project_id'] ] ?? (string) $op['project_id'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '#' . $op['user_id'] ); ?></td>
						<td><?php echo esc_html( $op['channel'] ); ?></td>
						<td><?php echo esc_html( self::date( $op['applied_at'] ) ); ?></td>
						<td>
							<?php if ( $restorable ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
								<?php wp_nonce_field( 'gdp_restore_' . $op['id'] ); ?>
								<input type="hidden" name="action" value="gdp_restore">
								<input type="hidden" name="operation_id" value="<?php echo (int) $op['id']; ?>">
								<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Restaurar', 'gestion-de-proyectos' ); ?></button>
							</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Restaura una eliminación (revierte la operación).
	 *
	 * @return void
	 */
	public static function handle_restore(): void {
		$id = isset( $_POST['operation_id'] ) ? (int) $_POST['operation_id'] : 0;
		check_admin_referer( 'gdp_restore_' . $id );
		self::require_access();

		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$back       = Admin::url( 'trash', $project_id > 0 ? array( 'project_id' => $project_id ) : array() );
		$result     = OperationManager::revert( $id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Restaurado. La operación queda como revertida en el registro de operaciones.', 'gestion-de-proyectos' ) );
	}
}
