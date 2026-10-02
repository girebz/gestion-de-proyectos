<?php
/**
 * Vista de carga de trabajo por persona y semana.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

use GDP\Modules\Planning\ActivityRepository;
use GDP\Modules\Planning\WeeklyReport;
use GDP\Modules\Planning\WorkloadService;

defined( 'ABSPATH' ) || exit;

/**
 * Porcentaje de dedicación por persona y semana, con navegación por periodo.
 */
final class WorkloadView {

	/**
	 * Imprime la vista.
	 *
	 * @param int         $project_id Proyecto.
	 * @param ViewContext $ctx        Contexto.
	 * @return void
	 */
	public static function render( int $project_id, ViewContext $ctx ): void {
		$from       = ActivityRepository::normalize_date( $ctx->get( 'from', '' ) );
		$weeks      = '' !== $ctx->get( 'weeks', '' ) ? max( 4, min( 78, (int) $ctx->get( 'weeks' ) ) ) : 26;
		$data       = WorkloadService::compute( $project_id, $from ? $from : null, $weeks );
		$monday     = WeeklyReport::monday( $data['weeks'][0]['start'] );
		$today_week = WeeklyReport::monday( current_time( 'Y-m-d' ) )->format( 'Y-m-d' );
		?>
		<div class="gdp-planning-toolbar">
			<?php echo $ctx->form_start(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<a class="button" href="<?php echo esc_url( $ctx->url( array( 'from' => $monday->modify( '-' . ( 7 * $weeks ) . ' days' )->format( 'Y-m-d' ), 'weeks' => $weeks ) ) ); ?>" aria-label="<?php esc_attr_e( 'Periodo anterior', 'gestion-de-proyectos' ); ?>">&larr;</a>
				<input type="date" name="<?php echo esc_attr( $ctx->param( 'from' ) ); ?>" value="<?php echo esc_attr( $data['weeks'][0]['start'] ); ?>">
				<select name="<?php echo esc_attr( $ctx->param( 'weeks' ) ); ?>">
					<?php foreach ( array( 8, 13, 26, 52 ) as $n ) : ?>
						<option value="<?php echo (int) $n; ?>" <?php selected( $n, $weeks ); ?>><?php echo esc_html( sprintf( /* translators: número de semanas. */ __( '%d semanas', 'gestion-de-proyectos' ), $n ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Ver', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( $ctx->url( array( 'from' => $monday->modify( '+' . ( 7 * $weeks ) . ' days' )->format( 'Y-m-d' ), 'weeks' => $weeks ) ) ); ?>" aria-label="<?php esc_attr_e( 'Periodo siguiente', 'gestion-de-proyectos' ); ?>">&rarr;</a>
			</form>
			<span class="gdp-muted gdp-small"><?php esc_html_e( 'Porcentaje de dedicación por semana: responsables al 100 % salvo asignación propia, participantes por su dedicación, prorrateado por los días hábiles de cada actividad en la semana. Más de 100 % es sobreasignación.', 'gestion-de-proyectos' ); ?></span>
		</div>
		<?php if ( empty( $data['people'] ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'No hay actividades abiertas con responsable o asignaciones en este periodo.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<div class="gdp-workload-wrap">
			<table class="widefat gdp-table gdp-workload">
				<thead>
					<tr>
						<th class="gdp-workload__person"><?php esc_html_e( 'Persona', 'gestion-de-proyectos' ); ?></th>
						<?php foreach ( $data['weeks'] as $w ) : ?>
							<th class="gdp-num <?php echo $w['start'] === $today_week ? 'gdp-workload__today' : ''; ?>" title="<?php echo esc_attr( $w['iso'] ); ?>"><?php echo esc_html( $w['label'] ); ?></th>
						<?php endforeach; ?>
						<th class="gdp-num"><?php esc_html_e( 'Máx.', 'gestion-de-proyectos' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $data['people'] as $p ) : ?>
					<tr>
						<td class="gdp-workload__person"><?php echo esc_html( $p['name'] ); ?><?php echo $p['overallocated'] > 0 ? ' <span class="gdp-badge gdp-badge--fail">' . esc_html( sprintf( /* translators: número de semanas con sobreasignación. */ __( '%d sem. >100 %%', 'gestion-de-proyectos' ), (int) $p['overallocated'] ) ) . '</span>' : ''; ?></td>
						<?php foreach ( $data['weeks'] as $i => $w ) : ?>
							<?php
							$cell  = $p['cells'][ $i ] ?? null;
							$pct   = $cell ? (int) $cell['percent'] : 0;
							$level = $pct > 100 ? 'over' : ( $pct >= 80 ? 'high' : ( $pct > 0 ? 'some' : 'none' ) );
							$title = $cell ? implode( "\n", array_map( static fn( array $x ): string => sprintf( '%s %s (%d %%)', $x['code'], $x['name'], $x['percent'] ), $cell['activities'] ) ) : '';
							?>
							<td class="gdp-num gdp-workload__cell gdp-workload__cell--<?php echo esc_attr( $level ); ?>" title="<?php echo esc_attr( $title ); ?>"><?php echo $pct > 0 ? (int) $pct : ''; ?></td>
						<?php endforeach; ?>
						<td class="gdp-num"><strong><?php echo (int) $p['max']; ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
	}
}
