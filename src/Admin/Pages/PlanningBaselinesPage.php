<?php
/**
 * Líneas base del cronograma.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Modules\Planning\BaselineRepository;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Fijar, comparar y administrar líneas base.
 */
final class PlanningBaselinesPage extends Page {

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_create_baseline', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_gdp_delete_baseline', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_gdp_baseline_current', array( self::class, 'handle_current' ) );
	}

	/**
	 * Pantalla.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	public static function render( array $project ): void {
		$project_id = (int) $project['id'];
		$can_manage = Access::can( 'planning.baseline', $project_id );
		$baselines  = BaselineRepository::for_project( $project_id );
		$current    = BaselineRepository::current( $project_id );
		$selected   = isset( $_GET['baseline_id'] ) ? (int) $_GET['baseline_id'] : ( $current ? $current['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$baseline   = $selected > 0 ? BaselineRepository::find( $selected ) : null;
		if ( $baseline && $baseline['project_id'] !== $project_id ) {
			$baseline = null;
		}
		$labels = array(
			'atrasada'   => __( 'Atrasada', 'gestion-de-proyectos' ),
			'adelantada' => __( 'Adelantada', 'gestion-de-proyectos' ),
			'en_plazo'   => __( 'En plazo', 'gestion-de-proyectos' ),
			'nueva'      => __( 'Nueva', 'gestion-de-proyectos' ),
			'sin_fechas' => __( 'Sin fechas', 'gestion-de-proyectos' ),
		);

		PlanningPage::header( $project, 'baselines', __( 'Líneas base', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Líneas base guardadas', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $baselines ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Aún no hay líneas base. Fije una cuando el cronograma esté aprobado: contra ella se medirán los atrasos.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Autor', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $baselines as $b ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( PlanningPage::url( $project_id, 'baselines', array( 'baseline_id' => $b['id'] ) ) ); ?>"><?php echo esc_html( $b['name'] ); ?></a>
									<?php if ( $b['is_current'] ) : ?><span class="gdp-badge gdp-badge--ok"><?php esc_html_e( 'vigente', 'gestion-de-proyectos' ); ?></span><?php endif; ?>
									<?php if ( $b['description'] ) : ?><div class="gdp-muted gdp-small"><?php echo esc_html( $b['description'] ); ?></div><?php endif; ?>
								</td>
								<td><?php echo esc_html( self::date( $b['created_at'] ) ); ?></td>
								<td><?php echo esc_html( ScheduleService::user_name( $b['created_by'] ) ); ?></td>
								<td class="gdp-actions">
									<?php if ( $can_manage && ! $b['is_current'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
										<?php wp_nonce_field( 'gdp_baseline_current_' . $b['id'] ); ?>
										<input type="hidden" name="action" value="gdp_baseline_current">
										<input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
										<button type="submit" class="button-link"><?php esc_html_e( 'Hacer vigente', 'gestion-de-proyectos' ); ?></button>
									</form>
									<?php endif; ?>
									<?php if ( $can_manage ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
										<?php wp_nonce_field( 'gdp_delete_baseline_' . $b['id'] ); ?>
										<input type="hidden" name="action" value="gdp_delete_baseline">
										<input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
										<button type="submit" class="button-link gdp-link-danger"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
									</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $can_manage ) : ?>
				<h3><?php esc_html_e( 'Fijar una nueva línea base', 'gestion-de-proyectos' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gdp_create_baseline_' . $project_id ); ?>
					<input type="hidden" name="action" value="gdp_create_baseline">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<p><input type="text" name="name" class="regular-text" placeholder="<?php echo esc_attr( sprintf( __( 'Línea base %s', 'gestion-de-proyectos' ), current_time( 'Y-m-d' ) ) ); ?>"></p>
					<p><textarea name="description" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Motivo (aprobación del cronograma, reprogramación autorizada…)', 'gestion-de-proyectos' ); ?>"></textarea></p>
					<p><label class="gdp-check"><input type="checkbox" name="make_current" value="1" checked> <?php esc_html_e( 'Usarla como línea base vigente', 'gestion-de-proyectos' ); ?></label></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Fijar línea base', 'gestion-de-proyectos' ); ?></button></p>
				</form>
				<?php endif; ?>
			</div>

			<?php if ( $baseline ) : ?>
			<?php
			ScheduleService::recalculate( $project_id );
			$compare = BaselineRepository::compare( $project_id, $baseline['id'] );
			?>
			<div class="gdp-card">
				<h2><?php printf( esc_html__( 'Comparación con "%s"', 'gestion-de-proyectos' ), esc_html( $baseline['name'] ) ); ?></h2>
				<p class="gdp-planning-summary">
					<span class="gdp-text-danger"><strong><?php echo (int) $compare['summary']['delayed']; ?></strong> <?php esc_html_e( 'atrasadas', 'gestion-de-proyectos' ); ?></span>
					<span class="gdp-text-ok"><strong><?php echo (int) $compare['summary']['advanced']; ?></strong> <?php esc_html_e( 'adelantadas', 'gestion-de-proyectos' ); ?></span>
					<span><strong><?php echo (int) $compare['summary']['new']; ?></strong> <?php esc_html_e( 'nuevas', 'gestion-de-proyectos' ); ?></span>
					<span><strong><?php echo (int) $compare['summary']['removed']; ?></strong> <?php esc_html_e( 'eliminadas', 'gestion-de-proyectos' ); ?></span>
				</p>
				<table class="widefat striped gdp-table gdp-small">
					<thead><tr><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Término base', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Término actual', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Desvío', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $compare['rows'] as $r ) : ?>
						<tr class="<?php echo 'summary' === $r['kind'] ? 'gdp-wbs__row--summary' : ''; ?>">
							<td><code><?php echo esc_html( $r['code'] ); ?></code></td>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo esc_html( $r['baseline']['end_date'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $r['current']['end_date'] ?? '—' ); ?></td>
							<td class="gdp-num <?php echo null !== $r['variance'] && $r['variance'] > 0 ? 'gdp-text-danger' : ( null !== $r['variance'] && $r['variance'] < 0 ? 'gdp-text-ok' : '' ); ?>"><?php echo null === $r['variance'] ? '' : sprintf( '%+d', (int) $r['variance'] ); ?></td>
							<td><?php echo self::badge( 'atrasada' === $r['status'] ? 'fail' : ( 'en_plazo' === $r['status'] || 'adelantada' === $r['status'] ? 'ok' : 'planned' ), $labels[ $r['status'] ] ?? $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $compare['removed'] as $r ) : ?>
						<tr><td><code><?php echo esc_html( $r['code'] ); ?></code></td><td colspan="4"><?php echo esc_html( $r['name'] ); ?></td><td><?php echo self::badge( 'cancelled', __( 'Eliminada', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Crea una línea base.
	 *
	 * @return void
	 */
	public static function handle_create(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_create_baseline_' . $project_id );
		if ( ! Access::can( 'planning.baseline', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		ScheduleService::recalculate( $project_id );
		$result = BaselineRepository::create(
			$project_id,
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
			isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
			! empty( $_POST['make_current'] )
		);
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( PlanningPage::url( $project_id, 'baselines' ), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( PlanningPage::url( $project_id, 'baselines', array( 'baseline_id' => $result ) ), __( 'Línea base fijada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una línea base.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_baseline_' . $id );
		$baseline = BaselineRepository::find( $id );
		if ( ! $baseline || ! Access::can( 'planning.baseline', $baseline['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$result = OperationManager::execute( 'activity', 'delete_baseline', array( 'baseline_id' => $id ), $baseline['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( PlanningPage::url( $baseline['project_id'], 'baselines', array( 'baseline_id' => $id ) ), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( PlanningPage::url( $baseline['project_id'], 'baselines' ), sprintf( __( 'Línea base eliminada. Puede restaurarla desde la <a href="%s">papelera</a>.', 'gestion-de-proyectos' ), esc_url( Admin::url( 'trash', array( 'project_id' => $baseline['project_id'] ) ) ) ) );
	}

	/**
	 * Marca la línea base vigente.
	 *
	 * @return void
	 */
	public static function handle_current(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_baseline_current_' . $id );
		$baseline = BaselineRepository::find( $id );
		if ( ! $baseline || ! Access::can( 'planning.baseline', $baseline['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		BaselineRepository::set_current( $id );
		Admin::redirect_with_notice( PlanningPage::url( $baseline['project_id'], 'baselines', array( 'baseline_id' => $id ) ), __( 'Línea base vigente actualizada.', 'gestion-de-proyectos' ) );
	}
}
