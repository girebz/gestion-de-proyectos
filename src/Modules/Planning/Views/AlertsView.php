<?php
/**
 * Vista de alertas de plazo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

use GDP\Modules\Planning\PlanningCron;
use GDP\Modules\Planning\ScheduleService;

defined( 'ABSPATH' ) || exit;

/**
 * Alertas calculadas sobre el cronograma vigente, con el resumen del último
 * recálculo programado.
 */
final class AlertsView {

	/**
	 * Etiquetas de los tipos de alerta.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			'overdue'           => __( 'Vencida', 'gestion-de-proyectos' ),
			'due_soon'          => __( 'Vence pronto', 'gestion-de-proyectos' ),
			'not_started'       => __( 'No iniciada', 'gestion-de-proyectos' ),
			'negative_float'    => __( 'Holgura negativa', 'gestion-de-proyectos' ),
			'conflict'          => __( 'Conflicto de fechas', 'gestion-de-proyectos' ),
			'deadline'          => __( 'Término contractual', 'gestion-de-proyectos' ),
			'overallocation'    => __( 'Sobreasignación', 'gestion-de-proyectos' ),
			'approval_required' => __( 'Exige aprobación', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Imprime la vista.
	 *
	 * @param int         $project_id Proyecto.
	 * @param ViewContext $ctx        Contexto.
	 * @return void
	 */
	public static function render( int $project_id, ViewContext $ctx ): void {
		$alerts = ScheduleService::alerts( $project_id );
		$stored = PlanningCron::stored_alerts( $project_id );
		$types  = self::types();
		?>
		<p class="gdp-muted"><?php printf( /* translators: fecha de hoy. */ esc_html__( 'Calculadas ahora sobre el cronograma vigente (hoy es %s). El recálculo diario guarda un resumen y, si las notificaciones están activas, avisa por correo a directores e ingenieros cuando hay alertas de severidad alta.', 'gestion-de-proyectos' ), esc_html( current_time( 'Y-m-d' ) ) ); ?>
			<?php if ( $stored ) : ?><br><?php printf( /* translators: 1: fecha y hora, 2: alertas altas, 3: alertas medias. */ esc_html__( 'Último recálculo programado: %1$s (%2$d altas, %3$d medias).', 'gestion-de-proyectos' ), esc_html( ViewContext::date( $stored['computed_at'] ) ), (int) $stored['high'], (int) $stored['medium'] ); ?><?php endif; ?>
		</p>
		<?php if ( empty( $alerts ) ) : ?>
			<div class="gdp-card"><p class="gdp-text-ok"><?php esc_html_e( 'Sin alertas: el cronograma está al día.', 'gestion-de-proyectos' ); ?></p></div>
		<?php else : ?>
			<div class="gdp-table-scroll">
			<table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Severidad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Detalle', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $alerts as $al ) : ?>
					<?php $edit = $ctx->edit_url( (int) $al['activity_id'] ); ?>
					<tr>
						<td><?php echo ViewContext::badge( 'high' === $al['severity'] ? 'fail' : 'warn', 'high' === $al['severity'] ? __( 'Alta', 'gestion-de-proyectos' ) : __( 'Media', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $types[ $al['type'] ] ?? $al['type'] ); ?></td>
						<td>
							<?php if ( '' !== $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( trim( $al['code'] . ' ' . $al['name'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( trim( $al['code'] . ' ' . $al['name'] ) ); ?>
							<?php endif; ?>
						</td>
						<td class="gdp-nowrap"><?php echo esc_html( (string) $al['end_date'] ); ?></td>
						<td><?php echo esc_html( $al['message'] ); ?></td>
						<td><?php echo esc_html( ScheduleService::user_name( (int) $al['owner_id'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		<?php
	}
}
