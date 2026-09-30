<?php
/**
 * Panel de inicio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Core\Audit;
use GDP\Core\Catalogs;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Resumen de proyectos visibles, operaciones pendientes y actividad reciente.
 */
final class DashboardPage extends Page {

	/**
	 * Renderiza la pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$projects  = ProjectRepository::all( Access::visible_project_ids() );
		$pending   = OperationManager::find_by_status( OperationManager::STATUS_PROPOSED, null, 10 );
		$recent    = Access::is_manager() ? Audit::recent( 10 ) : array();
		$is_setup  = ProjectRepository::count() === 0;

		self::open( __( 'Panel', 'gestion-de-proyectos' ), __( 'Estado general de los proyectos y de las operaciones pendientes.', 'gestion-de-proyectos' ) );

		if ( $is_setup && Access::is_manager() ) :
			?>
			<div class="gdp-card gdp-card--accent">
				<h2><?php esc_html_e( 'Primeros pasos', 'gestion-de-proyectos' ); ?></h2>
				<ol class="gdp-steps">
					<li><a href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Cree el primer proyecto', 'gestion-de-proyectos' ); ?></a> <?php esc_html_e( 'con su código, financiador, plazo y presupuesto.', 'gestion-de-proyectos' ); ?></li>
					<li><?php esc_html_e( 'Asigne al equipo con su perfil en el proyecto (director, ingeniero, investigador, apoyo, observador).', 'gestion-de-proyectos' ); ?></li>
					<li><a href="<?php echo esc_url( Admin::url( 'connector' ) ); ?>"><?php esc_html_e( 'Configure el conector', 'gestion-de-proyectos' ); ?></a> <?php esc_html_e( 'siguiendo el asistente paso a paso.', 'gestion-de-proyectos' ); ?></li>
				</ol>
			</div>
			<?php
		endif;
		?>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Proyectos', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $projects ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'No hay proyectos visibles para su usuario.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th>
								<th><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th>
								<th><?php esc_html_e( 'Plazo', 'gestion-de-proyectos' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $projects as $p ) : ?>
							<tr>
								<td><code><?php echo esc_html( $p['code'] ); ?></code></td>
								<td><a href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'view', 'id' => $p['id'] ) ) ); ?>"><?php echo esc_html( $p['name'] ); ?></a></td>
								<td><?php echo self::badge( (string) $p['status'], Catalogs::label( Catalogs::PROJECT_STATUS, (string) $p['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( self::period( $p ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Operaciones pendientes de confirmación', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $pending ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'No hay propuestas pendientes.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul class="gdp-list">
					<?php foreach ( $pending as $op ) : ?>
						<li>
							<a href="<?php echo esc_url( Admin::url( 'operations', array( 'id' => $op['id'] ) ) ); ?>">#<?php echo (int) $op['id']; ?></a>
							<?php echo esc_html( $op['summary'] ); ?>
							<span class="gdp-muted">(<?php echo esc_html( $op['channel'] ); ?>, <?php echo esc_html( self::date( $op['created_at'] ) ); ?>)</span>
						</li>
					<?php endforeach; ?>
					</ul>
					<p><a class="button" href="<?php echo esc_url( Admin::url( 'operations' ) ); ?>"><?php esc_html_e( 'Ver todas', 'gestion-de-proyectos' ); ?></a></p>
				<?php endif; ?>
			</div>

			<?php if ( Access::is_manager() ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Actividad reciente', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $recent ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin actividad registrada.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul class="gdp-list">
					<?php foreach ( $recent as $row ) : ?>
						<li>
							<span class="gdp-muted"><?php echo esc_html( self::date( $row['created_at'] ) ); ?></span>
							<?php echo esc_html( $row['summary'] ? $row['summary'] : $row['entity_type'] . ' ' . $row['action'] ); ?>
							<span class="gdp-badge gdp-badge--channel"><?php echo esc_html( $row['channel'] ); ?></span>
						</li>
					<?php endforeach; ?>
					</ul>
					<p><a class="button" href="<?php echo esc_url( Admin::url( 'audit' ) ); ?>"><?php esc_html_e( 'Ver bitácora', 'gestion-de-proyectos' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Conector', 'gestion-de-proyectos' ); ?></h2>
				<p>
					<?php
					if ( Connector::adapter_active() ) {
						esc_html_e( 'Adaptador MCP activo. El punto de entrada del conector es:', 'gestion-de-proyectos' );
						echo ' <code>' . esc_html( Connector::endpoint_url() ) . '</code>';
					} else {
						esc_html_e( 'El adaptador MCP no está activo; el asistente de integración indica cómo instalarlo.', 'gestion-de-proyectos' );
					}
					?>
				</p>
				<p><a class="button button-primary" href="<?php echo esc_url( Admin::url( 'connector' ) ); ?>"><?php esc_html_e( 'Abrir el asistente de integración', 'gestion-de-proyectos' ); ?></a></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Plazo del proyecto en texto.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return string
	 */
	private static function period( array $p ): string {
		if ( empty( $p['start_date'] ) && empty( $p['end_date'] ) ) {
			return '—';
		}

		return sprintf( '%s → %s', $p['start_date'] ? $p['start_date'] : '?', $p['end_date'] ? $p['end_date'] : '?' );
	}
}
