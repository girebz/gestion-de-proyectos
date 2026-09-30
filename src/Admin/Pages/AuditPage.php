<?php
/**
 * Pantalla de bitácora.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Domain\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Bitácora de auditoría, global para administradores y por proyecto para quien tenga audit.view.
 */
final class AuditPage extends Page {

	/**
	 * Renderiza la pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$limit      = isset( $_GET['limit'] ) ? max( 10, min( 500, (int) $_GET['limit'] ) ) : 100; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$projects = ProjectRepository::all( Access::visible_project_ids() );
		$allowed  = array();
		foreach ( $projects as $p ) {
			if ( Access::can( 'audit.view', (int) $p['id'] ) ) {
				$allowed[ (int) $p['id'] ] = $p;
			}
		}

		if ( $project_id > 0 && ! isset( $allowed[ $project_id ] ) ) {
			wp_die( esc_html__( 'Sin permiso para ver la bitácora de ese proyecto.', 'gestion-de-proyectos' ), 403 );
		}

		if ( 0 === $project_id && ! Access::is_manager() ) {
			// Sin proyecto elegido, un usuario no administrador ve el primero permitido.
			$project_id = empty( $allowed ) ? -1 : (int) array_key_first( $allowed );
		}

		$rows = -1 === $project_id ? array() : Audit::recent( $limit, 0 === $project_id ? null : $project_id );

		self::open( __( 'Bitácora', 'gestion-de-proyectos' ), __( 'Registro de solo anexado: quién cambió qué, cuándo y por qué canal.', 'gestion-de-proyectos' ) );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-form-row">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-audit' ); ?>">
			<label for="gdp-audit-project"><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></label>
			<select name="project_id" id="gdp-audit-project">
				<?php if ( Access::is_manager() ) : ?>
					<option value="0" <?php selected( 0, $project_id ); ?>><?php esc_html_e( 'Todos', 'gestion-de-proyectos' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $allowed as $pid => $p ) : ?>
					<option value="<?php echo (int) $pid; ?>" <?php selected( $pid, $project_id ); ?>><?php echo esc_html( $p['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="gdp-audit-limit"><?php esc_html_e( 'Filas', 'gestion-de-proyectos' ); ?></label>
			<input type="number" id="gdp-audit-limit" name="limit" min="10" max="500" value="<?php echo (int) $limit; ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
		</form>

		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'Sin entradas.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
			<table class="widefat striped gdp-table">
				<thead><tr>
					<th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Usuario', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Canal', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Entidad', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Acción', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Resumen', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Operación', 'gestion-de-proyectos' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : $user = get_userdata( (int) $row['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( self::date( $row['created_at'] ) ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : ( (int) $row['user_id'] > 0 ? '#' . $row['user_id'] : __( 'sistema', 'gestion-de-proyectos' ) ) ); ?></td>
						<td><span class="gdp-badge gdp-badge--channel"><?php echo esc_html( $row['channel'] ); ?></span></td>
						<td><code><?php echo esc_html( $row['entity_type'] . ( (int) $row['entity_id'] > 0 ? ' #' . $row['entity_id'] : '' ) ); ?></code></td>
						<td><?php echo esc_html( $row['action'] ); ?></td>
						<td><?php echo esc_html( $row['summary'] ); ?></td>
						<td><?php echo (int) $row['operation_id'] > 0 ? '<a href="' . esc_url( Admin::url( 'operations', array( 'id' => $row['operation_id'] ) ) ) . '">#' . (int) $row['operation_id'] . '</a>' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
		self::close();
	}
}
