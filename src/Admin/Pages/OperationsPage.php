<?php
/**
 * Pantalla de operaciones propuestas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Revisión, confirmación, cancelación y reversión de operaciones desde el panel.
 */
final class OperationsPage extends Page {

	/**
	 * Registra el manejador de acciones.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_operation', array( self::class, 'handle' ) );
	}

	/**
	 * Enrutador.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id > 0 ) {
			self::render_detail( $id );
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : OperationManager::STATUS_PROPOSED; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::render_list( $status );
	}

	/**
	 * Lista por estado.
	 *
	 * @param string $status Estado.
	 * @return void
	 */
	private static function render_list( string $status ): void {
		$statuses = array(
			OperationManager::STATUS_PROPOSED  => __( 'Propuestas', 'gestion-de-proyectos' ),
			OperationManager::STATUS_APPLIED   => __( 'Aplicadas', 'gestion-de-proyectos' ),
			OperationManager::STATUS_REVERTED  => __( 'Revertidas', 'gestion-de-proyectos' ),
			OperationManager::STATUS_CANCELLED => __( 'Canceladas', 'gestion-de-proyectos' ),
			OperationManager::STATUS_EXPIRED   => __( 'Caducadas', 'gestion-de-proyectos' ),
			OperationManager::STATUS_FAILED    => __( 'Fallidas', 'gestion-de-proyectos' ),
		);
		if ( ! isset( $statuses[ $status ] ) ) {
			$status = OperationManager::STATUS_PROPOSED;
		}

		$rows = OperationManager::find_by_status( $status, null, 100 );

		self::open( __( 'Operaciones', 'gestion-de-proyectos' ), __( 'Cambios propuestos por el conector o por importaciones, que se aplican solo tras su confirmación.', 'gestion-de-proyectos' ) );
		?>
		<ul class="subsubsub">
			<?php foreach ( $statuses as $slug => $label ) : ?>
				<li><a href="<?php echo esc_url( Admin::url( 'operations', array( 'status' => $slug ) ) ); ?>" class="<?php echo $slug === $status ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo array_key_last( $statuses ) === $slug ? '' : ' | '; ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="clear"></div>

		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'No hay operaciones en este estado.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
			<table class="widefat striped gdp-table">
				<thead><tr>
					<th>#</th>
					<th><?php esc_html_e( 'Resumen', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Manejador', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Canal', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Propuesta por', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Caduca', 'gestion-de-proyectos' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $op ) : $user = get_userdata( (int) $op['user_id'] ); ?>
					<tr>
						<td><a href="<?php echo esc_url( Admin::url( 'operations', array( 'id' => $op['id'] ) ) ); ?>">#<?php echo (int) $op['id']; ?></a></td>
						<td><?php echo esc_html( $op['summary'] ); ?></td>
						<td><code><?php echo esc_html( $op['handler'] . '.' . $op['action'] ); ?></code></td>
						<td><?php echo esc_html( $op['channel'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '#' . $op['user_id'] ); ?></td>
						<td><?php echo esc_html( self::date( $op['created_at'] ) ); ?></td>
						<td><?php echo esc_html( self::date( $op['expires_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
		self::close();
	}

	/**
	 * Detalle con vista previa y acciones.
	 *
	 * @param int $id Operación.
	 * @return void
	 */
	private static function render_detail( int $id ): void {
		$op = OperationManager::get( $id );
		if ( ! $op ) {
			wp_die( esc_html__( 'La operación no existe.', 'gestion-de-proyectos' ), 404 );
		}

		$project_id = (int) $op['project_id'];
		$visible    = Access::is_manager() || (int) $op['user_id'] === get_current_user_id() || ( $project_id > 0 && Access::can( 'project.view', $project_id ) );
		if ( ! $visible ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$can_confirm = Access::is_manager() || ( $project_id > 0 && Access::can( 'operations.confirm', $project_id ) ) || (int) $op['user_id'] === get_current_user_id();
		$user        = get_userdata( (int) $op['user_id'] );
		$preview     = is_array( $op['preview'] ) ? $op['preview'] : array();

		/* translators: número de la operación. */
		self::open( sprintf( __( 'Operación #%d', 'gestion-de-proyectos' ), $id ), (string) $op['summary'] );
		?>
		<p><a class="button" href="<?php echo esc_url( Admin::url( 'operations', array( 'status' => $op['status'] ) ) ); ?>">&larr; <?php esc_html_e( 'Volver', 'gestion-de-proyectos' ); ?></a></p>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Datos', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><td><?php echo self::badge( (string) $op['status'], (string) $op['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<tr><th><?php esc_html_e( 'Manejador y acción', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( $op['handler'] . '.' . $op['action'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></th><td><?php echo $project_id > 0 ? '<a href="' . esc_url( Admin::url( 'projects', array( 'action' => 'view', 'id' => $project_id ) ) ) . '">#' . (int) $project_id . '</a>' : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Canal', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $op['channel'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Propuesta por', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $user ? $user->display_name : '#' . $op['user_id'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Creada', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::date( $op['created_at'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Caduca', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::date( $op['expires_at'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Aplicada', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::date( $op['applied_at'] ) ); ?></td></tr>
					<?php if ( ! empty( $op['error'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Error', 'gestion-de-proyectos' ); ?></th><td class="gdp-text-danger"><?php echo esc_html( (string) $op['error'] ); ?></td></tr>
					<?php endif; ?>
				</table>

				<?php if ( $can_confirm && OperationManager::STATUS_PROPOSED === $op['status'] ) : ?>
					<div class="gdp-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
							<?php wp_nonce_field( 'gdp_operation_' . $id ); ?>
							<input type="hidden" name="action" value="gdp_operation">
							<input type="hidden" name="operation_id" value="<?php echo (int) $id; ?>">
							<input type="hidden" name="do" value="confirm">
							<button type="submit" class="button button-primary" <?php disabled( ! empty( $preview['conflicts'] ) ); ?>><?php esc_html_e( 'Confirmar y aplicar', 'gestion-de-proyectos' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
							<?php wp_nonce_field( 'gdp_operation_' . $id ); ?>
							<input type="hidden" name="action" value="gdp_operation">
							<input type="hidden" name="operation_id" value="<?php echo (int) $id; ?>">
							<input type="hidden" name="do" value="cancel">
							<button type="submit" class="button"><?php esc_html_e( 'Cancelar propuesta', 'gestion-de-proyectos' ); ?></button>
						</form>
					</div>
				<?php elseif ( $can_confirm && OperationManager::STATUS_APPLIED === $op['status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
						<?php wp_nonce_field( 'gdp_operation_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_operation">
						<input type="hidden" name="operation_id" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="do" value="revert">
						<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Revertir', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Vista previa', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( ! empty( $preview['conflicts'] ) ) : ?>
					<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Conflictos:', 'gestion-de-proyectos' ); ?></strong> <?php echo esc_html( implode( ' ', (array) $preview['conflicts'] ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( ! empty( $preview['warnings'] ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( implode( ' ', (array) $preview['warnings'] ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( ! empty( $preview['changes'] ) ) : ?>
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Campo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Antes', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Después', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( (array) $preview['changes'] as $field => $change ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $field ); ?></code></td>
								<td><?php echo esc_html( self::scalar( $change['before'] ?? null ) ); ?></td>
								<td><?php echo esc_html( self::scalar( $change['after'] ?? null ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin cambios de campos.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $op['result'] ) ) : ?>
					<h3><?php esc_html_e( 'Resultado', 'gestion-de-proyectos' ); ?></h3>
					<pre class="gdp-pre"><?php echo esc_html( (string) wp_json_encode( $op['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				<?php endif; ?>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Confirma, cancela o revierte.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$id = isset( $_POST['operation_id'] ) ? (int) $_POST['operation_id'] : 0;
		check_admin_referer( 'gdp_operation_' . $id );
		self::require_access();

		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$url = Admin::url( 'operations', array( 'id' => $id ) );

		switch ( $do ) {
			case 'confirm':
				$result  = OperationManager::confirm( $id );
				$message = __( 'Operación aplicada.', 'gestion-de-proyectos' );
				break;
			case 'cancel':
				$result  = OperationManager::cancel( $id );
				$message = __( 'Operación cancelada.', 'gestion-de-proyectos' );
				break;
			case 'revert':
				$result  = OperationManager::revert( $id );
				$message = __( 'Operación revertida.', 'gestion-de-proyectos' );
				break;
			default:
				wp_die( esc_html__( 'Acción no válida.', 'gestion-de-proyectos' ), 400 );
		}

		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $url, implode( ' ', $result->get_error_messages() ), 'error' );
		}

		Admin::redirect_with_notice( $url, $message );
	}

	/**
	 * Representación breve de un valor para la tabla de cambios.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private static function scalar( $value ): string {
		if ( null === $value ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		}

		return (string) $value;
	}
}
