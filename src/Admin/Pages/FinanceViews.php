<?php
/**
 * Vistas de la pantalla de finanzas y rendición de cuentas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Workbook;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\DocumentRepository;
use GDP\Modules\Finance\AgreementRepository;
use GDP\Modules\Finance\Assistant;
use GDP\Modules\Finance\Calendar;
use GDP\Modules\Finance\CashPlanRepository;
use GDP\Modules\Finance\EventRepository;
use GDP\Modules\Finance\FinanceService;
use GDP\Modules\Finance\GuaranteeRepository;
use GDP\Modules\Finance\InstallmentRepository;
use GDP\Modules\Finance\ItemRepository;
use GDP\Modules\Finance\LedgerRepository;
use GDP\Modules\Finance\Logic\PaymentValidator;
use GDP\Modules\Finance\ModificationRepository;
use GDP\Modules\Finance\PaymentRepository;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Modules\Finance\RenditionRepository;
use GDP\Modules\Procurement\PurchaseRepository;
use GDP\Modules\Procurement\SupplierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Dibuja cada pestaña. Las escrituras se envían al manejador genérico de la
 * pantalla (gdp_finance_op) con el nombre de la acción y los campos data[].
 */
final class FinanceViews extends Page {

	/**
	 * Entrada.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $view    Vista.
	 * @return void
	 */
	public static function render( array $project, string $view ): void {
		$project_id = (int) $project['id'];
		$status     = FinanceService::status( $project_id );
		$titles     = array(
			'status'       => __( 'Estado de cuentas', 'gestion-de-proyectos' ),
			'assistant'    => __( 'Asistente de rendición', 'gestion-de-proyectos' ),
			'renditions'   => __( 'Rendiciones', 'gestion-de-proyectos' ),
			'payments'     => __( 'Pagos', 'gestion-de-proyectos' ),
			'installments' => __( 'Cuotas', 'gestion-de-proyectos' ),
			'agreement'    => __( 'Convenio', 'gestion-de-proyectos' ),
			'items'        => __( 'Ítems y reglas', 'gestion-de-proyectos' ),
			'cash'         => __( 'Caja', 'gestion-de-proyectos' ),
			'guarantees'   => __( 'Garantías', 'gestion-de-proyectos' ),
		);
		self::header( $project, $view, $titles );
		$method = 'view_' . $view;
		self::$method( $project, $status );
		self::close();
	}

	/**
	 * Cabecera con selector de proyecto y pestañas.
	 *
	 * @param array<string,mixed>  $project Proyecto.
	 * @param string               $view    Vista activa.
	 * @param array<string,string> $titles  Títulos.
	 * @return void
	 */
	private static function header( array $project, string $view, array $titles ): void {
		$project_id = (int) $project['id'];
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		self::open( $titles[ $view ] ?? __( 'Finanzas', 'gestion-de-proyectos' ), sprintf( '%s · %s', $project['code'], $project['name'] ) );
		?>
		<div class="gdp-planning-bar">
			<div class="gdp-fin-bar-start">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . FinancePage::SLUG ); ?>">
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
					<select name="project_id" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Proyecto', 'gestion-de-proyectos' ); ?>">
						<?php foreach ( $projects as $p ) : ?>
							<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
				<?php if ( Workbook::available() && Access::can( 'finance.export', $project_id ) ) : ?>
					<a class="button gdp-fin-excel" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'workbook' ) ); ?>" title="<?php esc_attr_e( 'Libro Excel con todo el estado financiero: resumen, alertas, acciones, cuotas, ítems, pagos, proveedores, rendiciones, caja, cartola, convenio, modificaciones, garantías y reglas.', 'gestion-de-proyectos' ); ?>"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span> <?php esc_html_e( 'Exportar todo a Excel', 'gestion-de-proyectos' ); ?></a>
				<?php endif; ?>
			</div>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<?php foreach ( $titles as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => $slug ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Formato de pesos.
	 *
	 * @param float|null $amount   Monto.
	 * @param string     $currency Moneda (siempre pesos en este módulo).
	 * @return string
	 */
	public static function money( ?float $amount, string $currency = 'CLP' ): string {
		return null === $amount ? '—' : '$ ' . number_format( $amount, 0, ',', '.' );
	}

	/**
	 * Semáforo.
	 *
	 * @param bool|null $ok    Estado.
	 * @param string    $label Texto.
	 * @return string HTML.
	 */
	private static function light( ?bool $ok, string $label = '' ): string {
		$class = null === $ok ? 'gray' : ( $ok ? 'green' : 'red' );
		$text  = '' !== $label ? $label : ( null === $ok ? __( 'sin registro', 'gestion-de-proyectos' ) : ( $ok ? __( 'cumple', 'gestion-de-proyectos' ) : __( 'no cumple', 'gestion-de-proyectos' ) ) );

		return '<span class="gdp-light gdp-light--' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Abre un formulario de operación.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param string              $op         Acción.
	 * @param array<string,mixed> $hidden     Campos ocultos.
	 * @param string              $back       URL de retorno.
	 * @param string              $class      Clase CSS.
	 * @return void
	 */
	private static function form_open( int $project_id, string $op, array $hidden = array(), string $back = '', string $class = 'gdp-inline-form', string $id = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="<?php echo esc_attr( $class ); ?>" <?php echo '' !== $id ? 'id="' . esc_attr( $id ) . '"' : ''; ?>>
			<?php wp_nonce_field( 'gdp_finance_op_' . $project_id ); ?>
			<input type="hidden" name="action" value="gdp_finance_op">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
			<input type="hidden" name="back" value="<?php echo esc_url( '' !== $back ? $back : FinancePage::url( $project_id ) ); ?>">
			<?php foreach ( $hidden as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
		<?php
	}

	/**
	 * Formulario separado de la tabla (los campos de una fila lo referencian con el atributo form).
	 *
	 * @param int                 $project_id Proyecto.
	 * @param string              $op         Acción.
	 * @param array<string,mixed> $hidden     Campos ocultos.
	 * @param string              $back       URL de retorno.
	 * @param string              $id         Identificador del formulario.
	 * @return void
	 */
	private static function detached_form( int $project_id, string $op, array $hidden, string $back, string $id ): void {
		self::form_open( $project_id, $op, $hidden, $back, 'gdp-detached-form', $id );
		echo '</form>';
	}

	/**
	 * Cierra un formulario.
	 *
	 * @return void
	 */
	private static function form_close(): void {
		echo '</form>';
	}

	/**
	 * Opciones de documentos del proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @param int $selected   Seleccionado.
	 * @return string HTML de opciones.
	 */
	private static function document_options( int $project_id, int $selected = 0 ): string {
		$html = '<option value="0">' . esc_html__( 'Sin documento', 'gestion-de-proyectos' ) . '</option>';
		foreach ( DocumentRepository::for_project( $project_id, array( 'limit' => 500 ) ) as $d ) {
			$html .= '<option value="' . (int) $d['id'] . '" ' . selected( $selected, (int) $d['id'], false ) . '>' . esc_html( trim( (string) $d['number'] . ' ' . $d['subject'] ) ) . '</option>';
		}

		return $html;
	}

	/**
	 * Estado de cuentas.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_status( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$next       = $status['next_installment'];
		$profile    = Profiles::get( $status['profile'] );
		?>
		<p class="gdp-muted"><?php echo esc_html( sprintf( /* translators: 1: perfil de fondo, 2: fecha de evaluación. */ __( 'Perfil: %1$s. Evaluado al %2$s. Las cifras del Fondo y del aporte pecuniario se muestran siempre por separado.', 'gestion-de-proyectos' ), $status['profile_label'], $status['today'] ) ); ?></p>
		<div class="gdp-grid gdp-finance-sources">
			<?php foreach ( $status['sources'] as $slug => $s ) : ?>
				<div class="gdp-card gdp-source gdp-source--<?php echo esc_attr( $slug ); ?>">
					<h2><?php echo esc_html( $s['label'] ); ?></h2>
					<table class="gdp-facts">
						<tr><th><?php echo 'fondo' === $slug ? esc_html__( 'Transferido', 'gestion-de-proyectos' ) : esc_html__( 'Enterado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['received'] ) ); ?> <span class="gdp-muted gdp-small"><?php echo esc_html( sprintf( /* translators: monto total de la fuente. */ __( 'de %s', 'gestion-de-proyectos' ), self::money( $s['total'] ) ) ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Pagado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['paid'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Rendido', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['rendered'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Aprobado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['approved'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Observado / rechazado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['observed'] ) . ' / ' . self::money( $s['rejected'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Comprometido no pagado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['committed'] ) ); ?><?php echo $s['accrued'] > 0 ? ' <span class="gdp-muted gdp-small">(' . esc_html( sprintf( /* translators: monto devengado. */ __( 'devengado %s', 'gestion-de-proyectos' ), self::money( $s['accrued'] ) ) ) . ')</span>' : ''; ?></td></tr>
						<tr><th><?php esc_html_e( 'Saldo de caja calculado', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $s['non_negative'] ? '' : 'gdp-text-danger'; ?>"><strong><?php echo esc_html( self::money( $s['balance'] ) ); ?></strong><?php echo $s['non_negative'] ? '' : ' ' . esc_html__( '(caja negativa: pagado más que recibido)', 'gestion-de-proyectos' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Cartola y diferencia', 'gestion-de-proyectos' ); ?></th><td><?php echo $s['ledger_count'] > 0 ? esc_html( self::money( $s['ledger_balance'] ) ) . ' · ' . ( abs( (float) $s['difference'] ) > 0.5 ? '<span class="gdp-text-danger">Δ ' . esc_html( self::money( $s['difference'] ) ) . '</span>' : '<span class="gdp-text-ok">Δ 0</span>' ) : '<span class="gdp-muted">' . esc_html__( 'sin movimientos importados', 'gestion-de-proyectos' ) . '</span>'; ?></td></tr>
						<?php if ( 'fondo' === $slug ) : ?>
							<tr><th><?php esc_html_e( 'Reintegrado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['returned'] ) ); ?></td></tr>
						<?php endif; ?>
					</table>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $next ) : ?>
			<div class="gdp-card gdp-next">
				<h2><?php echo esc_html( sprintf( /* translators: 1: número de la cuota, 2: monto. */ __( 'Cuota %1$d (%2$s): ¿cuánto falta?', 'gestion-de-proyectos' ), $next['number'], self::money( $next['amount'] ) ) ); ?></h2>
				<div class="gdp-grid">
					<div>
						<table class="gdp-facts">
							<tr><th><?php esc_html_e( 'Falta pagar', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $next['gaps']['pay_gap'] > 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><strong><?php echo esc_html( self::money( $next['gaps']['pay_gap'] ) ); ?></strong></td></tr>
							<tr><th><?php esc_html_e( 'Falta rendir', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $next['gaps']['render_gap'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Falta aprobar', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $next['gaps']['approve_gap'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Garantía alternativa', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $next['gaps']['guarantee'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Fecha objetivo del giro', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $next['target_date'] ); ?> <span class="gdp-muted gdp-small"><?php esc_html_e( '(regla fecha_limite_giro o fin de la ventana)', 'gestion-de-proyectos' ); ?></span></td></tr>
							<tr><th><?php esc_html_e( 'Último mes de pago útil', 'gestion-de-proyectos' ); ?></th><td><?php echo $next['latest_month'] ? esc_html( Assistant::month_label( $next['latest_month'] ) ) . ' <span class="gdp-muted gdp-small">' . esc_html( sprintf( /* translators: 1: fecha límite para rendir, 2: fecha límite para facturar. */ __( '(rendir antes del %1$s; facturar antes del %2$s)', 'gestion-de-proyectos' ), (string) $next['latest_render_due'], (string) $next['invoice_by'] ) ) . '</span>' . ( $next['invoice_by'] && $next['invoice_by'] < $status['today'] ? ' <span class="gdp-text-danger gdp-small">' . esc_html( sprintf( /* translators: días de plazo para pagar una factura. */ __( 'El plazo para facturas nuevas ya pasó: con %d días de pago, solo alcanzan las facturas ya recibidas.', 'gestion-de-proyectos' ), (int) $next['invoice_days'] ) ) . '</span>' : '' ) : '<span class="gdp-text-danger">' . esc_html__( 'ningún mes alcanza la fecha objetivo', 'gestion-de-proyectos' ) . '</span>'; ?></td></tr>
						</table>
						<?php if ( ! empty( $next['candidates'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Compromisos que cierran la brecha:', 'gestion-de-proyectos' ); ?></strong></p>
							<ul class="gdp-list">
								<?php foreach ( $next['candidates'] as $c ) : ?>
									<li><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'payments', 'id' => $c['id'] ) ) ); ?>"><?php echo esc_html( $c['code'] ); ?></a> <?php echo esc_html( $c['description'] . ' · ' . self::money( $c['amount'] ) . ' · ' . $c['status'] ); ?><?php echo $c['item_ok'] ? '' : ' <span class="gdp-text-danger">' . esc_html__( '(ítem sin disponible)', 'gestion-de-proyectos' ) . '</span>'; ?></li>
								<?php endforeach; ?>
							</ul>
							<?php if ( $next['candidates_remaining'] > 0 ) : ?>
								<p class="gdp-text-danger"><?php echo esc_html( sprintf( /* translators: monto sin cubrir. */ __( 'Los compromisos registrados no alcanzan: quedan %s sin cubrir.', 'gestion-de-proyectos' ), self::money( $next['candidates_remaining'] ) ) ); ?></p>
							<?php endif; ?>
						<?php elseif ( $next['gaps']['pay_gap'] > 0 ) : ?>
							<p class="gdp-muted"><?php esc_html_e( 'No hay compromisos registrados (comprometidos o devengados) con cargo al Fondo; regístrelos en Pagos para que el módulo proponga con qué cubrir la brecha.', 'gestion-de-proyectos' ); ?></p>
						<?php endif; ?>
					</div>
					<div>
						<table class="widefat striped gdp-table gdp-conditions">
							<thead><tr><th></th><th><?php esc_html_e( 'Condición de giro', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
							<tbody>
							<?php foreach ( $next['conditions'] as $c ) : ?>
								<tr><td><code><?php echo esc_html( $c['key'] ); ?></code></td><td><?php echo esc_html( $c['label'] ); ?><br><span class="gdp-muted gdp-small"><?php echo esc_html( $c['detail'] ); ?></span></td><td><?php echo self::light( $c['ok'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p><a class="button" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'installment_sheet', self::installment_id( $status, $next['number'] ) ) ); ?>" target="_blank"><?php esc_html_e( 'Ficha de giro (imprimible)', 'gestion-de-proyectos' ); ?></a> <a class="button" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => 'solicitar_cuota', 'entity_type' => 'installment', 'entity_id' => self::installment_id( $status, $next['number'] ) ) ) ); ?>"><?php esc_html_e( 'Hoja de ejecución', 'gestion-de-proyectos' ); ?></a></p>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="gdp-card">
			<h2><?php esc_html_e( 'Avance por cuota', 'gestion-de-proyectos' ); ?></h2>
			<?php if ( empty( $status['installments'] ) ) : ?>
				<p class="gdp-muted"><?php esc_html_e( 'Sin cuotas registradas.', 'gestion-de-proyectos' ); ?> <a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'installments' ) ) ); ?>"><?php esc_html_e( 'Registrar cuotas', 'gestion-de-proyectos' ); ?></a></p>
			<?php else : ?>
				<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
					<thead><tr><th>#</th><th><?php esc_html_e( 'Monto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ventana', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Recibida', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plataforma', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pagado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Rendido', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Aprobado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $status['installments'] as $i ) : ?>
						<tr>
							<td><?php echo (int) $i['number']; ?></td>
							<td><?php echo esc_html( self::money( (float) $i['amount'] ) ); ?></td>
							<td class="gdp-small"><?php echo esc_html( trim( (string) $i['window_from'] . ' a ' . (string) $i['window_to'], ' a' ) ); ?></td>
							<td><?php echo $i['is_received'] ? esc_html( (string) $i['received_on'] ) : '<span class="gdp-muted">' . esc_html__( 'pendiente', 'gestion-de-proyectos' ) . '</span>'; ?></td>
							<td><?php echo esc_html( $profile->transfer_statuses()[ $i['platform_status'] ] ?? $i['platform_status'] ); ?><?php echo $i['is_received'] && empty( $i['receipt_sent_at'] ) ? '<br><span class="gdp-text-danger gdp-small">' . esc_html__( 'comprobante de ingreso sin enviar', 'gestion-de-proyectos' ) . '</span>' : ''; ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $i['paid'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $i['rendered'] ) ); ?><?php echo $i['excess'] > 0 ? '<br><span class="gdp-text-danger gdp-small">' . esc_html( sprintf( /* translators: monto del exceso. */ __( 'excede en %s', 'gestion-de-proyectos' ), self::money( $i['excess'] ) ) ) . '</span>' : ''; ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $i['approved'] ) ); ?></td>
							<td class="gdp-small"><?php echo esc_html( self::money( (float) $i['cash_amount'] ) ); ?> · <?php echo $i['cash_received_at'] ? esc_html( sprintf( /* translators: fecha de acreditación. */ __( 'acreditado el %s', 'gestion-de-proyectos' ), (string) $i['cash_received_at'] ) ) : '<span class="gdp-muted">' . esc_html__( 'por acreditar', 'gestion-de-proyectos' ) . '</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<p class="gdp-muted gdp-small"><?php echo esc_html( sprintf( /* translators: 1: criterio de imputación, 2: diferencia de cierre, 3: reitemizaciones usadas, 4: máximo permitido. */ __( 'Imputación de pagos a cuotas: %1$s (regla imputacion_cuotas). Diferencia de cierre (transferido − aprobado − reintegrado): %2$s. Reitemizaciones usadas: %3$d de %4$d.', 'gestion-de-proyectos' ), (string) ( $status['rules']['imputacion_cuotas']['value'] ?? 'cronologica' ), self::money( $status['closing_difference'] ), $status['reitemizations']['used'], $status['reitemizations']['max'] ) ); ?></p>
			<?php endif; ?>
		</div>

		<div class="gdp-card">
			<h2><?php esc_html_e( 'Disponible por ítem', 'gestion-de-proyectos' ); ?></h2>
			<?php if ( empty( $status['items'] ) ) : ?>
				<p class="gdp-muted"><?php esc_html_e( 'Sin ítems.', 'gestion-de-proyectos' ); ?> <a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'items' ) ) ); ?>"><?php esc_html_e( 'Crear los ítems del perfil', 'gestion-de-proyectos' ); ?></a></p>
			<?php else : ?>
				<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th colspan="4" class="gdp-source-head gdp-source-head--fondo"><?php esc_html_e( 'Fondo', 'gestion-de-proyectos' ); ?></th><th colspan="3" class="gdp-source-head gdp-source-head--pecuniario"><?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tope', 'gestion-de-proyectos' ); ?></th></tr>
					<tr><th></th><th class="gdp-num"><?php esc_html_e( 'Asignado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pagado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Comprometido', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Disponible', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Asignado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pagado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Disponible', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $status['items'] as $i ) : ?>
						<?php $f = $i['sources']['fondo']; $c = $i['sources']['pecuniario']; ?>
						<tr>
							<td><?php echo esc_html( $i['label'] ); ?><br><span class="gdp-muted gdp-small"><?php echo esc_html( $i['platform_type'] . ( $i['platform_subclass'] ? ' · ' . $i['platform_subclass'] : '' ) ); ?></span></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $f['assigned'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $f['paid'] ) ); ?><?php echo null !== $f['pct'] ? '<br><span class="gdp-muted gdp-small">' . esc_html( $f['pct'] ) . ' %</span>' : ''; ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $f['committed'] ) ); ?></td>
							<td class="gdp-num <?php echo $f['available'] < 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo esc_html( self::money( $f['available'] ) ); ?></strong></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $c['assigned'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( $c['paid'] ) ); ?></td>
							<td class="gdp-num <?php echo $c['available'] < 0 ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( self::money( $c['available'] ) ); ?></td>
							<td class="gdp-small"><?php echo $i['cap'] ? self::light( $i['cap']['ok'], sprintf( '≤ %s (%s)', self::money( $i['cap']['limit'] ), $i['cap']['base'] ) ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</div>

		<div class="gdp-card">
			<h2><?php esc_html_e( 'Rendiciones', 'gestion-de-proyectos' ); ?></h2>
			<?php self::timeline_table( $project_id, $status, true ); ?>
		</div>
		<?php
	}

	/**
	 * Tabla de la línea de tiempo de rendiciones.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $status     Estado.
	 * @param bool                $compact    Solo los últimos meses.
	 * @return void
	 */
	private static function timeline_table( int $project_id, array $status, bool $compact ): void {
		$rows = $status['renditions'];
		if ( empty( $rows ) ) {
			echo '<p class="gdp-muted">' . esc_html__( 'La línea de tiempo se calcula desde la fecha de inicio del convenio.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		if ( $compact ) {
			$rows = array_slice( $rows, -6 );
		}
		$profile  = Profiles::get( $status['profile'] );
		$statuses = $profile->rendition_statuses();
		$can_edit = Access::can( 'finance.edit', $project_id );
		?>
		<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
			<thead><tr><th><?php esc_html_e( 'Período', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pagado (Fondo)', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plazo interno', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plazo plataforma', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Días hábiles', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $t ) : ?>
				<?php $r = $t['rendition']; ?>
				<tr class="<?php echo $t['overdue'] ? 'gdp-row-danger' : ( $t['current'] ? 'gdp-row-current' : '' ); ?>">
					<td><strong><?php echo esc_html( Assistant::month_label( $t['period'] ) ); ?></strong><?php echo $t['current'] ? ' <span class="gdp-muted gdp-small">' . esc_html__( '(mes en curso)', 'gestion-de-proyectos' ) . '</span>' : ''; ?></td>
					<td class="gdp-num"><?php echo esc_html( self::money( $t['amount'] ) ); ?></td>
					<td><?php echo $r ? esc_html( $statuses[ $r['status'] ]['label'] ?? $r['status'] ) . ' <span class="gdp-muted gdp-small">(' . esc_html( $r['kind'] ) . ')</span>' : '<span class="gdp-muted">' . esc_html( 'mensual' === $t['expected_kind'] ? __( 'sin crear (con gasto)', 'gestion-de-proyectos' ) : __( 'sin crear (sin movimiento)', 'gestion-de-proyectos' ) ) . '</span>'; ?><?php echo $t['overdue'] ? '<br><span class="gdp-text-danger gdp-small">' . esc_html__( 'exigible atrasada', 'gestion-de-proyectos' ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( (string) $t['internal_due'] ); ?></td>
					<td><?php echo esc_html( (string) $t['platform_due'] ); ?></td>
					<td class="<?php echo $t['days_left'] < 0 && ! $t['submitted'] ? 'gdp-text-danger' : ''; ?>"><?php echo $t['submitted'] ? '—' : (int) $t['days_left']; ?></td>
					<td>
						<?php if ( $r ) : ?>
							<a class="button button-small" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'renditions', 'id' => $r['id'] ) ) ); ?>"><?php esc_html_e( 'Abrir', 'gestion-de-proyectos' ); ?></a>
						<?php elseif ( $t['current'] ) : ?>
							<span class="gdp-muted gdp-small"><?php esc_html_e( 'se prepara al cierre del mes', 'gestion-de-proyectos' ); ?></span>
						<?php elseif ( $can_edit ) : ?>
							<?php self::form_open( $project_id, 'create_rendition', array( 'data[period]' => $t['period'], 'data[source]' => 'fondo', 'data[kind]' => $t['expected_kind'] ), FinancePage::url( $project_id, array( 'view' => 'renditions' ) ) ); ?>
								<button type="submit" class="button button-small"><?php echo esc_html( 'mensual' === $t['expected_kind'] ? __( 'Crear rendición', 'gestion-de-proyectos' ) : __( 'Crear sin movimiento', 'gestion-de-proyectos' ) ); ?></button>
							<?php self::form_close(); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php
	}

	/**
	 * Asistente.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_assistant( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$guide      = isset( $_GET['guide'] ) ? sanitize_key( wp_unslash( (string) $_GET['guide'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $guide ) {
			$entity_type = isset( $_GET['entity_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['entity_type'] ) ) : 'project'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$entity_id   = isset( $_GET['entity_id'] ) ? (int) $_GET['entity_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::sheet( $project_id, $guide, $entity_type, $entity_id );
			return;
		}
		$actions = Assistant::actions( $project_id, $status );
		$profile = Profiles::get( $status['profile'] );
		?>
		<p class="gdp-muted"><?php esc_html_e( 'Acciones derivadas del estado, ordenadas por severidad y vencimiento. Cada una abre una hoja de ejecución: los pasos con la pantalla de la plataforma, la indicación breve y cada valor ya en el formato que la pantalla pide, para copiar.', 'gestion-de-proyectos' ); ?></p>
		<?php if ( empty( $actions ) ) : ?>
			<div class="gdp-card"><p class="gdp-text-ok"><?php esc_html_e( 'Sin acciones pendientes.', 'gestion-de-proyectos' ); ?></p></div>
		<?php else : ?>
			<div class="gdp-actions">
			<?php foreach ( $actions as $a ) : ?>
				<div class="gdp-card gdp-action gdp-action--<?php echo esc_attr( $a['severity'] ); ?>">
					<div class="gdp-action__head">
						<span class="gdp-badge gdp-badge--<?php echo esc_attr( $a['severity'] ); ?>"><?php echo esc_html( $a['severity'] ); ?></span>
						<h3><?php echo esc_html( $a['title'] ); ?></h3>
						<?php if ( $a['due'] ) : ?>
							<span class="gdp-action__due <?php echo null !== $a['days_left'] && $a['days_left'] < 0 ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( sprintf( /* translators: 1: fecha de vencimiento, 2: días hábiles restantes. */ __( 'vence el %1$s (%2$d días hábiles)', 'gestion-de-proyectos' ), $a['due'], (int) $a['days_left'] ) ); ?></span>
						<?php endif; ?>
					</div>
					<p><?php echo esc_html( $a['detail'] ); ?></p>
					<p>
						<?php if ( '' !== $a['guide'] ) : ?>
							<a class="button button-primary button-small" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => $a['guide'], 'entity_type' => $a['entity_type'], 'entity_id' => $a['entity_id'] ) + $a['args'] ) ); ?>"><?php esc_html_e( 'Hoja de ejecución', 'gestion-de-proyectos' ); ?></a>
						<?php endif; ?>
						<a class="button button-small" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => $a['tab'] ) + ( 'rendition' === $a['entity_type'] && $a['entity_id'] > 0 ? array( 'id' => $a['entity_id'] ) : array() ) ) ); ?>"><?php esc_html_e( 'Ir a la pestaña', 'gestion-de-proyectos' ); ?></a>
					</p>
				</div>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Guías del perfil', 'gestion-de-proyectos' ); ?></h2>
			<ul class="gdp-list">
				<?php foreach ( $profile->guides() as $key => $g ) : ?>
					<li><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => $key, 'entity_type' => $g['entity'], 'entity_id' => 0 ) ) ); ?>"><?php echo esc_html( $g['label'] ); ?></a> <span class="gdp-muted gdp-small"><?php echo esc_html( sprintf( /* translators: 1: número de pasos, 2: fuente de la guía. */ __( '%1$d pasos · %2$s', 'gestion-de-proyectos' ), count( $g['steps'] ), $g['source'] ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'Enlaces públicos del perfil:', 'gestion-de-proyectos' ); ?>
				<?php foreach ( $profile->links() as $l ) : ?>
					<a href="<?php echo esc_url( $l['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $l['label'] ); ?></a> (<?php echo esc_html( sprintf( /* translators: fecha de verificación. */ __( 'verificado el %s', 'gestion-de-proyectos' ), $l['checked'] ) ); ?>)
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Hoja de ejecución.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $guide       Guía.
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @return void
	 */
	private static function sheet( int $project_id, string $guide, string $entity_type, int $entity_id ): void {
		$sheet = Assistant::sheet( $project_id, $guide, $entity_type, $entity_id );
		if ( ! $sheet ) {
			echo '<p>' . esc_html__( 'La guía no existe en el perfil.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		$has_entity = 'project' === $entity_type || $entity_id > 0;
		$can_edit   = Access::can( 'finance.edit', $project_id ) && $has_entity;
		$back       = FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => $guide, 'entity_type' => $entity_type, 'entity_id' => $entity_id ) );
		$today      = current_time( 'Y-m-d' );
		?>
		<p><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant' ) ) ); ?>">&larr; <?php esc_html_e( 'Acciones', 'gestion-de-proyectos' ); ?></a></p>
		<div class="gdp-sheet">
			<h2><?php echo esc_html( $sheet['label'] ); ?></h2>
			<p class="gdp-muted gdp-small"><?php echo esc_html( sprintf( /* translators: 1: fuente de la guía, 2: lista de actores. */ __( 'Fuente: %1$s. Actores: %2$s.', 'gestion-de-proyectos' ), $sheet['source'], implode( ', ', $sheet['actors'] ) ) ); ?></p>
			<?php if ( ! $has_entity ) : ?>
				<?php self::entity_picker( $project_id, $guide, $entity_type ); ?>
			<?php endif; ?>
			<?php if ( $has_entity && ! empty( $sheet['missing'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: lista de datos. */ __( 'La hoja se emite incompleta: faltan %s. Regístrelos en la pestaña correspondiente y vuelva a abrir la hoja.', 'gestion-de-proyectos' ), implode( ', ', $sheet['missing_labels'] ? $sheet['missing_labels'] : $sheet['missing'] ) ) ); ?></p></div>
			<?php endif; ?>
			<ol class="gdp-steps">
			<?php foreach ( $sheet['steps'] as $n => $step ) : ?>
				<li class="gdp-step <?php echo $step['done'] ? 'gdp-step--done' : ''; ?>">
					<div class="gdp-step__head">
						<span class="gdp-step__actor"><?php echo esc_html( $step['actor'] ); ?></span>
						<span class="gdp-step__screen"><?php echo esc_html( $step['screen'] ); ?></span>
					</div>
					<p class="gdp-step__instruction"><?php echo esc_html( $step['instruction'] ); ?></p>
					<?php if ( ! empty( $step['fields'] ) ) : ?>
						<table class="gdp-fields">
						<?php foreach ( $step['fields'] as $f ) : ?>
							<tr>
								<th><?php echo esc_html( $f['label'] ); ?></th>
								<td>
									<?php if ( $f['file'] && '' !== $f['value'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $f['value'] ); ?>"><?php esc_html_e( 'Descargar', 'gestion-de-proyectos' ); ?></a>
									<?php elseif ( '' === $f['value'] ) : ?>
										<span class="gdp-text-danger gdp-small"><?php esc_html_e( 'falta', 'gestion-de-proyectos' ); ?></span>
									<?php else : ?>
										<code class="gdp-copy-value"><?php echo esc_html( $f['value'] ); ?></code>
										<button type="button" class="button button-small gdp-copy" data-copy="<?php echo esc_attr( $f['value'] ); ?>"><?php esc_html_e( 'Copiar', 'gestion-de-proyectos' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</table>
					<?php endif; ?>
					<?php if ( $step['per_payment'] && ! empty( $step['payments'] ) ) : ?>
						<?php foreach ( $step['payments'] as $ps ) : ?>
							<details class="gdp-payment-sheet" <?php echo $ps['blocks'] ? 'open' : ''; ?>>
								<summary><strong><?php echo esc_html( $ps['code'] ); ?></strong> <?php echo esc_html( $ps['description'] ); ?><?php echo $ps['blocks'] ? ' <span class="gdp-text-danger">' . esc_html__( '(bloquea)', 'gestion-de-proyectos' ) . '</span>' : ''; ?></summary>
								<table class="gdp-fields">
								<?php foreach ( $ps['fields'] as $f ) : ?>
									<tr><th><?php echo esc_html( $f['label'] ); ?></th><td>
										<?php if ( ! empty( $f['file'] ) ) : ?>
											<?php echo '' !== $f['value'] ? '<a class="button button-small" href="' . esc_url( $f['value'] ) . '">' . esc_html__( 'Descargar', 'gestion-de-proyectos' ) . '</a>' : '<span class="gdp-text-danger gdp-small">' . esc_html__( 'falta', 'gestion-de-proyectos' ) . '</span>'; ?>
										<?php elseif ( '' === $f['value'] ) : ?>
											<span class="gdp-text-danger gdp-small"><?php esc_html_e( 'falta', 'gestion-de-proyectos' ); ?></span>
										<?php else : ?>
											<code class="gdp-copy-value"><?php echo esc_html( $f['value'] ); ?></code> <button type="button" class="button button-small gdp-copy" data-copy="<?php echo esc_attr( $f['value'] ); ?>"><?php esc_html_e( 'Copiar', 'gestion-de-proyectos' ); ?></button>
										<?php endif; ?>
										<?php echo ! empty( $f['hint'] ) ? ' <span class="gdp-muted gdp-small">' . esc_html( $f['hint'] ) . '</span>' : ''; ?>
									</td></tr>
								<?php endforeach; ?>
								</table>
								<?php if ( ! empty( $ps['issues'] ) ) : ?>
									<ul class="gdp-issues">
									<?php foreach ( $ps['issues'] as $i ) : ?>
										<li class="gdp-issue--<?php echo esc_attr( $i['severity'] ); ?>"><?php echo esc_html( $i['code'] . ': ' . $i['message'] ); ?></li>
									<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</details>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( '' !== $step['check'] ) : ?>
						<p class="gdp-step__check"><?php echo esc_html( $step['check'] ); ?></p>
					<?php endif; ?>
					<div class="gdp-step__foot">
						<?php if ( $step['done'] ) : ?>
							<span class="gdp-text-ok"><?php echo esc_html( sprintf( /* translators: 1: fecha, 2: nombre del usuario. */ __( 'Hecho el %1$s por %2$s', 'gestion-de-proyectos' ), (string) $step['done']['event_date'], $step['done']['user'] ) ); ?></span><?php echo $step['done']['note'] ? ' · ' . esc_html( (string) $step['done']['note'] ) : ''; ?>
							<?php if ( $can_edit ) : ?>
								<?php self::form_open( $project_id, 'undo_step', array( 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'guide' => $guide, 'step' => $step['key'] ), $back ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( 'Dejar pendiente', 'gestion-de-proyectos' ); ?></button>
								<?php self::form_close(); ?>
							<?php endif; ?>
						<?php elseif ( $can_edit ) : ?>
							<?php self::form_open( $project_id, 'mark_step', array( 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'guide' => $guide, 'step' => $step['key'] ), $back ); ?>
								<input type="date" name="date" value="<?php echo esc_attr( $today ); ?>">
								<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota o constancia', 'gestion-de-proyectos' ); ?>">
								<select name="document_id" aria-label="<?php esc_attr_e( 'Documento de evidencia', 'gestion-de-proyectos' ); ?>"><?php echo self::document_options( $project_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Marcar hecho', 'gestion-de-proyectos' ); ?></button>
							<?php self::form_close(); ?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
			</ol>
			<?php if ( ! empty( $sheet['result'] ) && 'rendition' === $entity_type && $entity_id > 0 && $can_edit && isset( $sheet['result']['status'] ) ) : ?>
				<div class="gdp-card">
					<h3><?php esc_html_e( 'Al terminar: declarar el estado resultante', 'gestion-de-proyectos' ); ?></h3>
					<?php self::form_open( $project_id, 'declare_rendition', array( 'rendition_id' => $entity_id, 'status' => $sheet['result']['status'] ), FinancePage::url( $project_id, array( 'view' => 'renditions', 'id' => $entity_id ) ) ); ?>
						<label><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?> <input type="date" name="date" value="<?php echo esc_attr( $today ); ?>"></label>
						<label><?php esc_html_e( 'Documento que lo prueba', 'gestion-de-proyectos' ); ?> <select name="document_id"><?php echo self::document_options( $project_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
						<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota', 'gestion-de-proyectos' ); ?>">
						<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: estado de la rendición. */ __( 'Declarar "%s"', 'gestion-de-proyectos' ), Profiles::get( '' )->rendition_statuses()[ $sheet['result']['status'] ]['label'] ?? $sheet['result']['status'] ) ); ?></button>
					<?php self::form_close(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Selector de la entidad de una hoja abierta sin ella (por ejemplo, desde
	 * la lista de guías): la hoja se completa con los datos de la entidad
	 * elegida y solo entonces admite marcar pasos.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $guide       Guía.
	 * @param string $entity_type Tipo de entidad que la guía necesita.
	 * @return void
	 */
	private static function entity_picker( int $project_id, string $guide, string $entity_type ): void {
		$options = array();
		$label   = '';
		switch ( $entity_type ) {
			case 'rendition':
				$label = __( 'Rendición', 'gestion-de-proyectos' );
				foreach ( array_reverse( RenditionRepository::all( $project_id ) ) as $r ) {
					$options[ $r['id'] ] = sprintf( '%s · %s · %s', Assistant::month_label( (string) $r['period'] ), $r['kind'], $r['status'] );
				}
				break;
			case 'installment':
				$label = __( 'Cuota', 'gestion-de-proyectos' );
				foreach ( InstallmentRepository::all( $project_id ) as $i ) {
					$options[ $i['id'] ] = sprintf( /* translators: 1: número de la cuota, 2: monto. */ __( 'Cuota %1$d · %2$s', 'gestion-de-proyectos' ), $i['number'], self::money( (float) $i['amount'] ) );
				}
				break;
			case 'supplier':
				$label = __( 'Proveedor', 'gestion-de-proyectos' );
				foreach ( SupplierRepository::for_project( $project_id, true ) as $sup ) {
					$options[ $sup['id'] ] = (string) $sup['name'] . ( ! empty( $sup['tax_id'] ) ? ' · ' . $sup['tax_id'] : '' );
				}
				break;
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-entity-picker">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . FinancePage::SLUG ); ?>">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="view" value="assistant">
			<input type="hidden" name="guide" value="<?php echo esc_attr( $guide ); ?>">
			<input type="hidden" name="entity_type" value="<?php echo esc_attr( $entity_type ); ?>">
			<?php if ( $options ) : ?>
				<label><?php echo esc_html( $label ); ?>
					<select name="entity_id">
						<?php foreach ( $options as $id => $text ) : ?>
							<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Completar la hoja con estos datos', 'gestion-de-proyectos' ); ?></button>
			<?php else : ?>
				<span class="gdp-muted"><?php esc_html_e( 'Todavía no hay registros de este tipo en el proyecto; la hoja muestra solo las indicaciones.', 'gestion-de-proyectos' ); ?></span>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Rendiciones.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_renditions( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$id         = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id > 0 ) {
			self::rendition_show( $project_id, $id, $status );
			return;
		}
		$can_edit = Access::can( 'finance.edit', $project_id );
		?>
		<p class="gdp-muted"><?php esc_html_e( 'Una rendición por mes desde el inicio del convenio: con gasto (mensual) o sin movimiento. Respaldos internos al 8.º día hábil del mes siguiente, carga en la plataforma al 15.º (reglas plazo_respaldo_interno y plazo_sisrec, con el calendario laboral y los feriados de Chile).', 'gestion-de-proyectos' ); ?></p>
		<div class="gdp-card">
			<?php self::timeline_table( $project_id, $status, false ); ?>
		</div>
		<?php if ( $can_edit ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Crear otra rendición', 'gestion-de-proyectos' ); ?></h2>
				<?php self::form_open( $project_id, 'create_rendition', array(), FinancePage::url( $project_id, array( 'view' => 'renditions' ) ) ); ?>
					<label><?php esc_html_e( 'Período', 'gestion-de-proyectos' ); ?> <input type="month" name="data[period]" required></label>
					<label><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?> <select name="data[source]"><option value="fondo"><?php esc_html_e( 'Fondo', 'gestion-de-proyectos' ); ?></option><option value="pecuniario"><?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></option></select></label>
					<label><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?> <select name="data[kind]"><?php foreach ( RenditionRepository::KINDS as $k ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $k ); ?></option><?php endforeach; ?></select></label>
					<button type="submit" class="button"><?php esc_html_e( 'Crear', 'gestion-de-proyectos' ); ?></button>
				<?php self::form_close(); ?>
			</div>
		<?php endif; ?>
		<?php
		$others = array_filter( RenditionRepository::all( $project_id ), static fn( array $r ): bool => 'fondo' !== $r['source'] || ! in_array( $r['kind'], array( 'mensual', 'sin_movimiento' ), true ) );
		if ( $others ) :
			?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Otras rendiciones (aporte pecuniario, regularización, final)', 'gestion-de-proyectos' ); ?></h2>
				<ul class="gdp-list">
				<?php foreach ( $others as $r ) : ?>
					<li><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'renditions', 'id' => $r['id'] ) ) ); ?>"><?php echo esc_html( $r['period'] . ' · ' . $r['source'] . ' · ' . $r['kind'] ); ?></a> <span class="gdp-muted gdp-small"><?php echo esc_html( $r['status'] ); ?></span></li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Ficha de una rendición.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param int                 $id         Rendición.
	 * @param array<string,mixed> $status     Estado.
	 * @return void
	 */
	private static function rendition_show( int $project_id, int $id, array $status ): void {
		$r = RenditionRepository::find( $id );
		if ( ! $r || $r['project_id'] !== $project_id ) {
			echo '<p>' . esc_html__( 'La rendición no existe en este proyecto.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		$profile  = Profiles::get( $status['profile'] );
		$statuses = $profile->rendition_statuses();
		$can_edit = Access::can( 'finance.edit', $project_id );
		$payments = PaymentRepository::list( $project_id, array( 'rendition_id' => $id ) );
		$back     = FinancePage::url( $project_id, array( 'view' => 'renditions', 'id' => $id ) );
		$total    = 0.0;
		$blocking = 0;
		$items    = ItemRepository::labels( $project_id );
		$guides   = array( 'respaldos_mensuales', count( $payments ) >= 5 ? 'rendicion_carga_masiva' : 'rendicion_mensual' );
		if ( 'sin_movimiento' === $r['kind'] ) {
			$guides = array( 'rendicion_sin_movimiento' );
		} elseif ( 'devuelta' === $r['status'] ) {
			$guides = array( 'corregir_devuelta' );
		} elseif ( 'aprobada_parcial' === $r['status'] || 'regularizacion' === $r['kind'] ) {
			$guides = array( 'regularizacion' );
		}
		?>
		<p><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'renditions' ) ) ); ?>">&larr; <?php esc_html_e( 'Rendiciones', 'gestion-de-proyectos' ); ?></a></p>
		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php echo esc_html( sprintf( /* translators: 1: mes, 2: fuente, 3: tipo de rendición. */ __( 'Rendición %1$s · %2$s · %3$s', 'gestion-de-proyectos' ), Assistant::month_label( (string) $r['period'] ), $profile->sources()[ $r['source'] ] ?? $r['source'], $r['kind'] ) ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><td><strong><?php echo esc_html( $statuses[ $r['status'] ]['label'] ?? $r['status'] ); ?></strong> <span class="gdp-muted gdp-small"><?php echo esc_html( $statuses[ $r['status'] ]['actor'] ?? '' ); ?></span></td></tr>
					<tr><th><?php esc_html_e( 'Plazo interno', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $r['internal_due'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Plazo plataforma', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $r['platform_due'] ); ?></td></tr>
					<?php if ( $r['fix_due'] ) : ?><tr><th><?php esc_html_e( 'Plazo de subsanación', 'gestion-de-proyectos' ); ?></th><td class="gdp-text-danger"><?php echo esc_html( (string) $r['fix_due'] ); ?></td></tr><?php endif; ?>
					<tr><th><?php esc_html_e( 'Fechas declaradas', 'gestion-de-proyectos' ); ?></th><td class="gdp-small"><?php echo esc_html( implode( ' · ', array_filter( array( $r['sent_internal_at'] ? 'universidad ' . $r['sent_internal_at'] : '', $r['loaded_at'] ? 'carga ' . $r['loaded_at'] : '', $r['sent_at'] ? 'enviada ' . $r['sent_at'] : '', $r['approved_at'] ? 'aprobada ' . $r['approved_at'] : '', $r['returned_at'] ? 'devuelta ' . $r['returned_at'] : '' ) ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $r['notes'] ); ?></td></tr>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'expedient', $id ) ); ?>" target="_blank"><?php esc_html_e( 'Expediente (imprimible)', 'gestion-de-proyectos' ); ?></a>
					<?php if ( 'sin_movimiento' === $r['kind'] ) : ?>
						<a class="button" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'zero_letter', $id ) ); ?>" target="_blank"><?php esc_html_e( 'Carta y carátula de gasto cero', 'gestion-de-proyectos' ); ?></a>
					<?php elseif ( Access::can( 'finance.export', $project_id ) ) : ?>
						<a class="button" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'bulk_sheet', $id ) ); ?>"><?php esc_html_e( 'Planilla de carga', 'gestion-de-proyectos' ); ?></a>
						<a class="button" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'bulk_zip', $id ) ); ?>"><?php esc_html_e( 'ZIP de respaldos', 'gestion-de-proyectos' ); ?></a>
					<?php endif; ?>
					<?php foreach ( $guides as $g ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => $g, 'entity_type' => 'rendition', 'entity_id' => $id ) ) ); ?>"><?php echo esc_html( $profile->guides()[ $g ]['label'] ?? $g ); ?></a>
					<?php endforeach; ?>
				</p>
			</div>
			<?php if ( $can_edit ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Declarar estado', 'gestion-de-proyectos' ); ?></h2>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'La plataforma no se puede leer desde aquí: cada estado se declara con fecha, nota y el documento que lo prueba (informe firmado, constancia). Al declarar "enviada a la universidad" o "rendida", los hallazgos que bloquean en los pagos impiden confirmar.', 'gestion-de-proyectos' ); ?></p>
				<?php self::form_open( $project_id, 'declare_rendition', array( 'rendition_id' => $id ), $back, 'gdp-stack-form' ); ?>
					<label><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?> <select name="status"><?php foreach ( $statuses as $slug => $s ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $r['status'] ); ?>><?php echo esc_html( $s['label'] ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?> <input type="date" name="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
					<label><?php esc_html_e( 'Documento', 'gestion-de-proyectos' ); ?> <select name="document_id"><?php echo self::document_options( $project_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
					<label><?php esc_html_e( 'Nota (motivo, observaciones)', 'gestion-de-proyectos' ); ?> <textarea name="note" rows="2"></textarea></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Declarar', 'gestion-de-proyectos' ); ?></button>
				<?php self::form_close(); ?>
				<hr>
				<?php self::form_open( $project_id, 'update_rendition', array( 'rendition_id' => $id, 'expected_version' => $r['version'] ), $back, 'gdp-stack-form' ); ?>
					<label><?php esc_html_e( 'Notas de la rendición (justificación de un mes sin gasto, vía de envío de la carta)', 'gestion-de-proyectos' ); ?> <textarea name="data[notes]" rows="2"><?php echo esc_textarea( (string) $r['notes'] ); ?></textarea></label>
					<label><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?> <select name="data[kind]"><?php foreach ( RenditionRepository::KINDS as $k ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $r['kind'] ); ?>><?php echo esc_html( $k ); ?></option><?php endforeach; ?></select></label>
					<button type="submit" class="button"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
				<?php self::form_close(); ?>
				<?php if ( ! in_array( $r['status'], RenditionRepository::SUBMITTED, true ) ) : ?>
					<p>
					<?php self::form_open( $project_id, 'collect_payments', array( 'rendition_id' => $id ), $back ); ?><button type="submit" class="button"><?php esc_html_e( 'Asignar los pagos pagados del período', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
					<?php self::form_open( $project_id, 'delete_rendition', array( 'rendition_id' => $id ), FinancePage::url( $project_id, array( 'view' => 'renditions' ) ) ); ?><button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar la rendición? Sus pagos quedan sin rendición.', 'gestion-de-proyectos' ) ); ?>');"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Pagos de la rendición', 'gestion-de-proyectos' ); ?></h2>
			<?php if ( empty( $payments ) ) : ?>
				<p class="gdp-muted"><?php esc_html_e( 'Sin pagos asignados.', 'gestion-de-proyectos' ); ?></p>
			<?php else : ?>
				<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
					<thead><tr><th>#</th><th><?php esc_html_e( 'Pago', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Egreso', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Documento', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Monto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Hallazgos', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $payments as $n => $p ) : ?>
						<?php $issues = FinanceService::validate_payment( $p, $status ); $total += (float) $p['amount']; $blocking += PaymentValidator::blocks( $issues ) ? 1 : 0; ?>
						<tr>
							<td><?php echo (int) ( $p['folio'] > 0 ? $p['folio'] : $n + 1 ); ?></td>
							<td><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'payments', 'id' => $p['id'] ) ) ); ?>"><?php echo esc_html( $p['code'] ); ?></a> <?php echo esc_html( $p['description'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( $p['egress_number'] . ' ' . (string) $p['paid_at'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( trim( ( $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'] ) . ' ' . $p['doc_number'] . ' ' . (string) $p['doc_date'] ) ); ?></td>
							<td><?php echo esc_html( $items[ $p['item_slug'] ] ?? $p['item_slug'] ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( (float) $p['amount'] ) ); ?></td>
							<td><?php echo esc_html( $profile->payment_statuses()[ $p['status'] ] ?? $p['status'] ); ?><?php echo $p['observation'] ? '<br><span class="gdp-small">' . esc_html( (string) $p['observation'] ) . '</span>' : ''; ?></td>
							<td class="gdp-small"><?php self::issues_list( $issues ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot><tr><th colspan="5"><?php esc_html_e( 'Total', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php echo esc_html( self::money( $total ) ); ?></th><th colspan="2"><?php echo $blocking > 0 ? '<span class="gdp-text-danger">' . esc_html( sprintf( /* translators: número de pagos. */ _n( '%d pago con hallazgos que bloquean', '%d pagos con hallazgos que bloquean', $blocking, 'gestion-de-proyectos' ), $blocking ) ) . '</span>' : '<span class="gdp-text-ok">' . esc_html__( 'Sin hallazgos que bloqueen', 'gestion-de-proyectos' ) . '</span>'; ?></th></tr></tfoot>
				</table></div>
			<?php endif; ?>
		</div>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Eventos declarados', 'gestion-de-proyectos' ); ?></h2>
			<?php self::events_table( EventRepository::for_entity( 'rendition', $id ) ); ?>
		</div>
		<?php
	}

	/**
	 * Lista de hallazgos.
	 *
	 * @param array<int,array{code:string,severity:string,message:string}> $issues Hallazgos.
	 * @return void
	 */
	private static function issues_list( array $issues ): void {
		if ( empty( $issues ) ) {
			echo '<span class="gdp-text-ok">' . esc_html__( 'completo', 'gestion-de-proyectos' ) . '</span>';
			return;
		}
		echo '<ul class="gdp-issues">';
		foreach ( $issues as $i ) {
			echo '<li class="gdp-issue--' . esc_attr( $i['severity'] ) . '">' . esc_html( $i['code'] . ': ' . $i['message'] ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Tabla de eventos.
	 *
	 * @param array<int,array<string,mixed>> $events Eventos.
	 * @return void
	 */
	private static function events_table( array $events ): void {
		if ( empty( $events ) ) {
			echo '<p class="gdp-muted">' . esc_html__( 'Sin eventos.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped gdp-table">
			<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Clave', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Quién', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Documento', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $events as $e ) : ?>
				<tr><td><?php echo esc_html( (string) $e['event_date'] ); ?></td><td><?php echo esc_html( $e['kind'] ); ?></td><td><code><?php echo esc_html( $e['event_key'] . ( $e['guide'] ? ' @ ' . $e['guide'] : '' ) ); ?></code></td><td><?php echo esc_html( $e['user'] ); ?></td><td><?php echo esc_html( (string) $e['note'] ); ?></td><td><?php echo $e['document_id'] > 0 ? '<a href="' . esc_url( Assistant::document_url( (int) $e['document_id'] ) ) . '">#' . (int) $e['document_id'] . '</a>' : ''; ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Pagos.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_payments( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'finance.edit', $project_id );
		$id         = isset( $_GET['id'] ) ? (int) $_GET['id'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id >= 0 ) {
			self::payment_form( $project_id, $id, $status );
			return;
		}
		$profile = Profiles::get( $status['profile'] );
		$filters = array(
			'source'    => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'item_slug' => isset( $_GET['item_slug'] ) ? sanitize_key( wp_unslash( (string) $_GET['item_slug'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'period'    => isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['period'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$rows      = PaymentRepository::list( $project_id, array_filter( $filters ) );
		$items     = ItemRepository::labels( $project_id );
		$effective = PaymentRepository::effective_installments( $project_id );
		$unreg     = Assistant::unregistered_suppliers( $project_id );
		?>
		<p class="gdp-muted"><?php esc_html_e( 'El pago es la unidad de rendición: un comprobante de egreso puede pagar varios documentos y cada documento es un pago. Las compras del módulo de adquisiciones se registran aquí como pagos cuando se pagan.', 'gestion-de-proyectos' ); ?></p>
		<?php if ( $unreg ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: lista de proveedores. */ __( 'Proveedores con pagos por rendir que no están registrados en la plataforma: %s.', 'gestion-de-proyectos' ), implode( ', ', array_map( static fn( array $s ): string => (string) $s['name'], $unreg ) ) ) ); ?></p></div>
		<?php endif; ?>
		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?><a class="button button-primary" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'payments', 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nuevo pago', 'gestion-de-proyectos' ); ?></a><?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . FinancePage::SLUG ); ?>"><input type="hidden" name="view" value="payments"><input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<select name="source"><option value=""><?php esc_html_e( 'Ambas fuentes', 'gestion-de-proyectos' ); ?></option><?php foreach ( $profile->sources() as $s => $l ) : ?><?php if ( 'no_pecuniario' === $s ) { continue; } ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['source'], $s ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<select name="status"><option value=""><?php esc_html_e( 'Todos los estados', 'gestion-de-proyectos' ); ?></option><?php foreach ( $profile->payment_statuses() as $s => $l ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['status'], $s ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<select name="item_slug"><option value=""><?php esc_html_e( 'Todos los ítems', 'gestion-de-proyectos' ); ?></option><?php foreach ( $items as $s => $l ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['item_slug'], $s ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<input type="month" name="period" value="<?php echo esc_attr( $filters['period'] ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
			</form>
		</div>
		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'Sin pagos.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
			<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Egreso', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Documento', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Monto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Cuota', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Hallazgos', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $p ) : ?>
					<tr class="gdp-source-row--<?php echo esc_attr( $p['source'] ); ?>">
						<td><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'payments', 'id' => $p['id'] ) ) ); ?>"><code><?php echo esc_html( $p['code'] ); ?></code></a></td>
						<td><?php echo esc_html( $p['description'] ); ?><?php echo $p['purchase_id'] > 0 ? ' <a class="gdp-small" href="' . esc_url( ProcurementPage::url( $project_id, array( 'view' => 'show', 'id' => $p['purchase_id'] ) ) ) . '">' . esc_html__( 'compra', 'gestion-de-proyectos' ) . '</a>' : ''; ?></td>
						<td><span class="gdp-source-chip gdp-source-chip--<?php echo esc_attr( $p['source'] ); ?>"><?php echo esc_html( $p['source'] ); ?></span></td>
						<td><?php echo esc_html( $items[ $p['item_slug'] ] ?? $p['item_slug'] ); ?></td>
						<td class="gdp-small"><?php echo esc_html( trim( $p['egress_number'] . ' ' . (string) $p['paid_at'] ) ); ?></td>
						<td class="gdp-small"><?php echo esc_html( trim( ( $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'] ) . ' ' . $p['doc_number'] . ' ' . (string) $p['doc_date'] ) ); ?></td>
						<td class="gdp-num"><?php echo esc_html( self::money( (float) $p['amount'] ) ); ?></td>
						<td><?php echo isset( $effective[ $p['id'] ] ) ? (int) $effective[ $p['id'] ] : ( $p['installment_no'] > 0 ? (int) $p['installment_no'] : '—' ); ?></td>
						<td><?php echo esc_html( $profile->payment_statuses()[ $p['status'] ] ?? $p['status'] ); ?></td>
						<td class="gdp-small"><?php self::issues_list( FinanceService::validate_payment( $p, $status ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Formulario y ficha de un pago.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param int                 $id         Pago (0 = nuevo).
	 * @param array<string,mixed> $status     Estado.
	 * @return void
	 */
	private static function payment_form( int $project_id, int $id, array $status ): void {
		$can_edit = Access::can( 'finance.edit', $project_id );
		$p        = $id > 0 ? PaymentRepository::find( $id ) : null;
		if ( $id > 0 && ( ! $p || $p['project_id'] !== $project_id ) ) {
			echo '<p>' . esc_html__( 'El pago no existe en este proyecto.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		if ( ! $can_edit && ! $p ) {
			echo '<p>' . esc_html__( 'Sin permiso para registrar pagos.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		$profile  = Profiles::get( $status['profile'] );
		$prefill  = array();
		$purchase = isset( $_GET['purchase_id'] ) ? PurchaseRepository::find( (int) $_GET['purchase_id'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $purchase && $purchase['project_id'] === $project_id ) {
			$prefill = array( 'purchase_id' => $purchase['id'], 'supplier_id' => $purchase['supplier_id'], 'description' => $purchase['title'], 'commitment' => $purchase['order_number'] ? 'Orden de compra ' . $purchase['order_number'] : '', 'amount' => $purchase['amount_clp'], 'doc_number' => $purchase['invoice_number'], 'doc_date' => $purchase['invoice_date'], 'paid_at' => $purchase['paid_at'], 'item_slug' => $purchase['budget_line'] );
		}
		$p         = $p ?? array_merge( array( 'id' => 0, 'purchase_id' => 0, 'supplier_id' => 0, 'source' => 'fondo', 'item_slug' => '', 'description' => '', 'commitment' => '', 'executed_at' => null, 'paid_at' => null, 'egress_number' => '', 'egress_document_id' => 0, 'doc_type' => 'factura', 'doc_number' => '', 'doc_date' => null, 'amount' => 0.0, 'installment_no' => 0, 'status' => 'comprometido', 'rendition_id' => 0, 'folio' => 0, 'observation' => '', 'support' => array(), 'notes' => '', 'version' => 0 ), $prefill );
		$items     = ItemRepository::all( $project_id );
		$suppliers = SupplierRepository::for_project( $project_id, true );
		$purchases = PurchaseRepository::for_project( $project_id, array( 'limit' => 1000 ) );
		$back      = FinancePage::url( $project_id, array( 'view' => 'payments', 'id' => $id ) );
		$issues    = $id > 0 ? FinanceService::validate_payment( $p, $status ) : array();
		$required  = $profile->support_requirements()[ $p['item_slug'] ] ?? array();
		$kinds     = $profile->support_kinds();
		$doc_opts  = self::document_options( $project_id );
		?>
		<p><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'payments' ) ) ); ?>">&larr; <?php esc_html_e( 'Pagos', 'gestion-de-proyectos' ); ?></a></p>
		<div class="gdp-grid">
			<div class="gdp-card gdp-card--form">
				<h2><?php echo $id > 0 ? esc_html( $p['code'] . ' · ' . $p['description'] ) : esc_html__( 'Nuevo pago', 'gestion-de-proyectos' ); ?></h2>
				<?php self::form_open( $project_id, $id > 0 ? 'update_payment' : 'create_payment', $id > 0 ? array( 'payment_id' => $id, 'expected_version' => $p['version'] ) : array(), $id > 0 ? $back : FinancePage::url( $project_id, array( 'view' => 'payments' ) ), 'gdp-stack-form' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" name="data[description]" class="large-text" required value="<?php echo esc_attr( (string) $p['description'] ); ?>"></td></tr>
						<tr><th><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?></th><td><label><input type="radio" name="data[source]" value="fondo" <?php checked( $p['source'], 'fondo' ); ?>> <?php esc_html_e( 'Fondo', 'gestion-de-proyectos' ); ?></label> &nbsp; <label><input type="radio" name="data[source]" value="pecuniario" <?php checked( $p['source'], 'pecuniario' ); ?>> <?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><td><select name="data[item_slug]"><option value=""><?php esc_html_e( 'Sin ítem', 'gestion-de-proyectos' ); ?></option><?php foreach ( $items as $i ) : ?><option value="<?php echo esc_attr( $i['slug'] ); ?>" <?php selected( $p['item_slug'], $i['slug'] ); ?>><?php echo esc_html( $i['label'] ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php esc_html_e( 'Compromiso', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[commitment]" class="regular-text" value="<?php echo esc_attr( (string) $p['commitment'] ); ?>" placeholder="<?php esc_attr_e( 'Orden, contrato, fondo por rendir', 'gestion-de-proyectos' ); ?>"> <select name="data[purchase_id]"><option value="0"><?php esc_html_e( 'Sin compra asociada', 'gestion-de-proyectos' ); ?></option><?php foreach ( $purchases as $pu ) : ?><option value="<?php echo (int) $pu['id']; ?>" <?php selected( (int) $p['purchase_id'], (int) $pu['id'] ); ?>><?php echo esc_html( $pu['code'] . ' ' . $pu['title'] ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></th><td><select name="data[supplier_id]"><option value="0"><?php esc_html_e( 'Sin proveedor', 'gestion-de-proyectos' ); ?></option><?php foreach ( $suppliers as $s ) : ?><option value="<?php echo (int) $s['id']; ?>" <?php selected( (int) $p['supplier_id'], (int) $s['id'] ); ?>><?php echo esc_html( $s['name'] . ( $s['tax_id'] ? ' (' . $s['tax_id'] . ')' : '' ) ); ?></option><?php endforeach; ?></select> <a href="<?php echo esc_url( ProcurementPage::url( $project_id, array( 'view' => 'suppliers' ) ) ); ?>"><?php esc_html_e( 'Gestionar proveedores', 'gestion-de-proyectos' ); ?></a></td></tr>
						<tr><th><?php esc_html_e( 'Documento de gasto', 'gestion-de-proyectos' ); ?></th><td><select name="data[doc_type]"><?php foreach ( $profile->doc_types() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p['doc_type'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select> <input type="text" name="data[doc_number]" placeholder="<?php esc_attr_e( 'Número', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( (string) $p['doc_number'] ); ?>"> <input type="date" name="data[doc_date]" value="<?php echo esc_attr( (string) $p['doc_date'] ); ?>"></td></tr>
						<tr><th><?php esc_html_e( 'Monto (pesos)', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[amount]" class="regular-text" value="<?php echo esc_attr( $p['amount'] > 0 ? number_format( (float) $p['amount'], 0, ',', '.' ) : '' ); ?>"></td></tr>
						<tr><th><?php esc_html_e( 'Fecha de ejecución', 'gestion-de-proyectos' ); ?></th><td><input type="date" name="data[executed_at]" value="<?php echo esc_attr( (string) $p['executed_at'] ); ?>"> <span class="description"><?php esc_html_e( 'Fija el plazo; vacía, se usa la del documento.', 'gestion-de-proyectos' ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Comprobante de egreso', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[egress_number]" placeholder="<?php esc_attr_e( 'Número', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( (string) $p['egress_number'] ); ?>"> <input type="date" name="data[paid_at]" value="<?php echo esc_attr( (string) $p['paid_at'] ); ?>"> <select name="data[egress_document_id]"><?php echo str_replace( 'value="' . (int) $p['egress_document_id'] . '"', 'value="' . (int) $p['egress_document_id'] . '" selected', $doc_opts ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select> <span class="description"><?php esc_html_e( 'La fecha del egreso fija el mes de rendición.', 'gestion-de-proyectos' ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Cuota declarada', 'gestion-de-proyectos' ); ?></th><td><input type="number" name="data[installment_no]" class="small-text" min="0" value="<?php echo (int) $p['installment_no']; ?>"> <span class="description"><?php esc_html_e( '0 = imputación cronológica.', 'gestion-de-proyectos' ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><td><select name="data[status]"><?php foreach ( $profile->payment_statuses() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><?php esc_html_e( 'Respaldos', 'gestion-de-proyectos' ); ?></th><td>
							<?php $present = array(); foreach ( $p['support'] as $s ) { $present[ $s['kind'] ] = $s; } ?>
							<table class="gdp-support">
							<?php $n = 0; foreach ( $kinds as $kind => $label ) : ?>
								<?php $row = $present[ $kind ] ?? null; $is_required = isset( $required[ $kind ] ); if ( ! $row && ! $is_required && ! in_array( $kind, array( 'factura', 'cotizaciones', 'justificacion', 'fondo_por_rendir', 'contrato', 'otro' ), true ) ) { continue; } ?>
								<tr>
									<td><label><input type="checkbox" name="support[<?php echo (int) $n; ?>][kind]" value="<?php echo esc_attr( $kind ); ?>" <?php checked( null !== $row ); ?>> <?php echo esc_html( $required[ $kind ] ?? $label ); ?><?php echo $is_required ? ' <span class="gdp-text-danger">*</span>' : ''; ?></label></td>
									<td><select name="support[<?php echo (int) $n; ?>][document_id]"><?php echo str_replace( 'value="' . (int) ( $row['document_id'] ?? 0 ) . '"', 'value="' . (int) ( $row['document_id'] ?? 0 ) . '" selected', $doc_opts ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
									<td><input type="text" name="support[<?php echo (int) $n; ?>][note]" placeholder="<?php esc_attr_e( 'Nota', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( (string) ( $row['note'] ?? '' ) ); ?>"></td>
								</tr>
								<?php ++$n; ?>
							<?php endforeach; ?>
							</table>
							<p class="description"><?php esc_html_e( 'Los respaldos marcados con * los exige el ítem. Los archivos se suben en el módulo de documentos y se enlazan aquí.', 'gestion-de-proyectos' ); ?> <a href="<?php echo esc_url( DocumentsPage::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Subir un documento', 'gestion-de-proyectos' ); ?></a></p>
						</td></tr>
						<tr><th><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?></th><td><textarea name="data[notes]" rows="2" class="large-text"><?php echo esc_textarea( (string) $p['notes'] ); ?></textarea></td></tr>
					</table>
					<?php if ( $can_edit ) : ?><p class="submit"><button type="submit" class="button button-primary"><?php echo $id > 0 ? esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ) : esc_html__( 'Registrar pago', 'gestion-de-proyectos' ); ?></button></p><?php endif; ?>
				<?php self::form_close(); ?>
			</div>
			<?php if ( $id > 0 ) : ?>
			<div>
				<div class="gdp-card">
					<h2><?php esc_html_e( 'Hallazgos', 'gestion-de-proyectos' ); ?></h2>
					<?php self::issues_list( $issues ); ?>
					<p class="gdp-muted gdp-small"><?php esc_html_e( '"bloquea" impide enviar la rendición; "falta" señala un respaldo obligatorio ausente; "advierte" se muestra sin impedir.', 'gestion-de-proyectos' ); ?></p>
				</div>
				<?php if ( $can_edit ) : ?>
				<div class="gdp-card">
					<h2><?php esc_html_e( 'Declarar estado del pago', 'gestion-de-proyectos' ); ?></h2>
					<?php self::form_open( $project_id, 'set_payment_status', array( 'payment_id' => $id ), $back, 'gdp-stack-form' ); ?>
						<label><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?> <select name="status"><?php foreach ( $profile->payment_statuses() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
						<label><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?> <input type="date" name="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
						<label><?php esc_html_e( 'Motivo (observación de la plataforma)', 'gestion-de-proyectos' ); ?> <textarea name="note" rows="2"></textarea></label>
						<button type="submit" class="button"><?php esc_html_e( 'Declarar', 'gestion-de-proyectos' ); ?></button>
					<?php self::form_close(); ?>
					<?php if ( ! in_array( $p['status'], PaymentRepository::RENDERED, true ) ) : ?>
						<?php self::form_open( $project_id, 'delete_payment', array( 'payment_id' => $id ), FinancePage::url( $project_id, array( 'view' => 'payments' ) ) ); ?><button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar el pago?', 'gestion-de-proyectos' ) ); ?>');"><?php esc_html_e( 'Eliminar pago', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<?php if ( $p['supplier_id'] > 0 && $can_edit ) : ?>
					<?php $supplier = SupplierRepository::find( (int) $p['supplier_id'] ); $registered = $supplier ? FinanceService::supplier_registered( $supplier ) : false; ?>
					<div class="gdp-card">
						<h2><?php esc_html_e( 'Proveedor en la plataforma', 'gestion-de-proyectos' ); ?></h2>
						<p><?php echo esc_html( $supplier ? $supplier['name'] : '' ); ?>: <?php echo $registered ? '<span class="gdp-text-ok">' . esc_html__( 'registrado', 'gestion-de-proyectos' ) . '</span>' : '<span class="gdp-text-danger">' . esc_html__( 'no registrado', 'gestion-de-proyectos' ) . '</span>'; ?></p>
						<?php self::form_open( $project_id, 'set_supplier_registered', array( 'supplier_id' => $p['supplier_id'], 'registered' => $registered ? '0' : '1' ), $back ); ?><button type="submit" class="button button-small"><?php echo $registered ? esc_html__( 'Marcar como no registrado', 'gestion-de-proyectos' ) : esc_html__( 'Marcar como registrado', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
						<a class="button button-small" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => 'registrar_proveedor', 'entity_type' => 'supplier', 'entity_id' => $p['supplier_id'] ) ) ); ?>"><?php esc_html_e( 'Hoja: registrar proveedor', 'gestion-de-proyectos' ); ?></a>
					</div>
				<?php endif; ?>
				<div class="gdp-card">
					<h2><?php esc_html_e( 'Eventos', 'gestion-de-proyectos' ); ?></h2>
					<?php self::events_table( EventRepository::for_entity( 'payment', $id ) ); ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Cuotas.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_installments( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$can_rules  = Access::can( 'finance.rules', $project_id );
		$can_edit   = Access::can( 'finance.edit', $project_id );
		$edit_id    = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile    = Profiles::get( $status['profile'] );
		$back       = FinancePage::url( $project_id, array( 'view' => 'installments' ) );
		?>
		<p class="gdp-muted"><?php esc_html_e( 'Cada cuota: monto, ventana del programa de desembolso, informe de avance que la habilita, aporte pecuniario asociado y las fechas de solicitud, transferencia, ingreso, comprobante y aceptación en la plataforma. Los hechos relevantes (informe aprobado, carta de solicitud, transferencia aceptada, comprobante enviado, aporte acreditado) se declaran con fecha y documento.', 'gestion-de-proyectos' ); ?></p>
		<?php if ( ! empty( $status['installments'] ) ) : ?>
			<div class="gdp-card">
				<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
					<thead><tr><th>#</th><th><?php esc_html_e( 'Monto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ventana', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Informe', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Solicitada', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Transferida / ingresada', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Comprobante', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plataforma', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $status['installments'] as $i ) : ?>
						<tr>
							<td><?php echo (int) $i['number']; ?></td>
							<td><?php echo esc_html( self::money( (float) $i['amount'] ) ); ?><?php echo $i['share_pct'] > 0 ? ' <span class="gdp-muted gdp-small">' . esc_html( (string) $i['share_pct'] ) . ' %</span>' : ''; ?></td>
							<td class="gdp-small"><?php echo esc_html( trim( (string) $i['window_from'] . ' a ' . (string) $i['window_to'], ' a' ) ); ?></td>
							<td><?php echo $i['report_no'] > 0 ? (int) $i['report_no'] : '—'; ?></td>
							<td><?php echo esc_html( (string) ( $i['requested_at'] ?? '—' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $i['transferred_at'] ?? '—' ) . ' / ' . (string) ( $i['received_at'] ?? '—' ) ); ?></td>
							<td class="gdp-small"><?php echo esc_html( trim( $i['receipt_number'] . ' ' . (string) ( $i['receipt_sent_at'] ?? '' ) ) ); ?><?php echo $i['is_received'] && empty( $i['receipt_sent_at'] ) ? '<br><span class="gdp-text-danger">' . esc_html( sprintf( /* translators: fecha límite. */ __( 'enviar antes del %s', 'gestion-de-proyectos' ), (string) $i['receipt_due'] ) ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( $profile->transfer_statuses()[ $i['platform_status'] ] ?? $i['platform_status'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( self::money( (float) $i['cash_amount'] ) . ( $i['cash_received_at'] ? ' · ' . $i['cash_received_at'] : '' ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'installments', 'edit' => $i['id'] ) ) ); ?>"><?php echo $can_rules ? esc_html__( 'Editar', 'gestion-de-proyectos' ) : esc_html__( 'Ver', 'gestion-de-proyectos' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'installment_sheet', $i['id'] ) ); ?>" target="_blank"><?php esc_html_e( 'Ficha', 'gestion-de-proyectos' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</div>
		<?php endif; ?>
		<?php
		if ( $edit_id >= 0 && ( $can_rules || $edit_id > 0 ) ) :
			$i = $edit_id > 0 ? InstallmentRepository::find( $edit_id ) : null;
			if ( $edit_id > 0 && ( ! $i || $i['project_id'] !== $project_id ) ) {
				echo '<p>' . esc_html__( 'La cuota no existe en este proyecto.', 'gestion-de-proyectos' ) . '</p>';
				return;
			}
			$i = $i ?? array( 'id' => 0, 'number' => count( $status['installments'] ) + 1, 'label' => '', 'amount' => 0.0, 'share_pct' => 0.0, 'window_from' => null, 'window_to' => null, 'report_no' => 0, 'cash_amount' => 0.0, 'cash_received_at' => null, 'cash_receipt' => '', 'requested_at' => null, 'transferred_at' => null, 'received_at' => null, 'receipt_number' => '', 'receipt_sent_at' => null, 'platform_status' => 'pendiente', 'document_id' => 0, 'notes' => '', 'version' => 0 );
			?>
			<div class="gdp-grid">
				<div class="gdp-card gdp-card--form">
					<h2><?php echo $edit_id > 0 ? esc_html( sprintf( /* translators: número de la cuota. */ __( 'Cuota %d', 'gestion-de-proyectos' ), $i['number'] ) ) : esc_html__( 'Nueva cuota', 'gestion-de-proyectos' ); ?></h2>
					<?php if ( $can_rules ) : ?>
					<?php self::form_open( $project_id, $edit_id > 0 ? 'update_installment' : 'create_installment', $edit_id > 0 ? array( 'installment_id' => $edit_id, 'expected_version' => $i['version'] ) : array(), $back, 'gdp-stack-form' ); ?>
						<table class="form-table" role="presentation">
							<tr><th><?php esc_html_e( 'Número', 'gestion-de-proyectos' ); ?></th><td><input type="number" name="data[number]" class="small-text" min="1" value="<?php echo (int) $i['number']; ?>"> <input type="text" name="data[label]" placeholder="<?php esc_attr_e( 'Etiqueta', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( (string) $i['label'] ); ?>"></td></tr>
							<tr><th><?php esc_html_e( 'Monto (pesos)', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[amount]" value="<?php echo esc_attr( number_format( (float) $i['amount'], 0, ',', '.' ) ); ?>"> <label><?php esc_html_e( '% del Fondo', 'gestion-de-proyectos' ); ?> <input type="text" name="data[share_pct]" class="small-text" value="<?php echo esc_attr( (string) $i['share_pct'] ); ?>"></label></td></tr>
							<tr><th><?php esc_html_e( 'Ventana del convenio', 'gestion-de-proyectos' ); ?></th><td><input type="date" name="data[window_from]" value="<?php echo esc_attr( (string) $i['window_from'] ); ?>"> <input type="date" name="data[window_to]" value="<?php echo esc_attr( (string) $i['window_to'] ); ?>"></td></tr>
							<tr><th><?php esc_html_e( 'Informe de avance que la habilita', 'gestion-de-proyectos' ); ?></th><td><input type="number" name="data[report_no]" class="small-text" min="0" value="<?php echo (int) $i['report_no']; ?>"></td></tr>
							<tr><th><?php esc_html_e( 'Aporte pecuniario asociado', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[cash_amount]" value="<?php echo esc_attr( number_format( (float) $i['cash_amount'], 0, ',', '.' ) ); ?>"> <input type="date" name="data[cash_received_at]" value="<?php echo esc_attr( (string) $i['cash_received_at'] ); ?>"> <input type="text" name="data[cash_receipt]" placeholder="<?php esc_attr_e( 'Comprobante', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( (string) $i['cash_receipt'] ); ?>"></td></tr>
							<tr><th><?php esc_html_e( 'Solicitada el', 'gestion-de-proyectos' ); ?></th><td><input type="date" name="data[requested_at]" value="<?php echo esc_attr( (string) $i['requested_at'] ); ?>"></td></tr>
							<tr><th><?php esc_html_e( 'Transferida / ingresada a caja', 'gestion-de-proyectos' ); ?></th><td><input type="date" name="data[transferred_at]" value="<?php echo esc_attr( (string) $i['transferred_at'] ); ?>"> <input type="date" name="data[received_at]" value="<?php echo esc_attr( (string) $i['received_at'] ); ?>"></td></tr>
							<tr><th><?php esc_html_e( 'Comprobante de ingreso', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[receipt_number]" placeholder="<?php esc_attr_e( 'Número', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( (string) $i['receipt_number'] ); ?>"> <input type="date" name="data[receipt_sent_at]" value="<?php echo esc_attr( (string) $i['receipt_sent_at'] ); ?>"> <select name="data[document_id]"><?php echo str_replace( 'value="' . (int) $i['document_id'] . '"', 'value="' . (int) $i['document_id'] . '" selected', self::document_options( $project_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td></tr>
							<tr><th><?php esc_html_e( 'Estado en la plataforma', 'gestion-de-proyectos' ); ?></th><td><select name="data[platform_status]"><?php foreach ( $profile->transfer_statuses() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $i['platform_status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
							<tr><th><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?></th><td><textarea name="data[notes]" rows="2" class="large-text"><?php echo esc_textarea( (string) $i['notes'] ); ?></textarea></td></tr>
						</table>
						<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button></p>
					<?php self::form_close(); ?>
					<?php if ( $edit_id > 0 ) : ?>
						<?php self::form_open( $project_id, 'delete_installment', array( 'installment_id' => $edit_id ), $back ); ?><button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar la cuota?', 'gestion-de-proyectos' ) ); ?>');"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
					<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php if ( $edit_id > 0 ) : ?>
				<div>
					<?php if ( $can_edit ) : ?>
					<div class="gdp-card">
						<h2><?php esc_html_e( 'Declarar un hecho', 'gestion-de-proyectos' ); ?></h2>
						<?php self::form_open( $project_id, 'installment_event', array( 'installment_id' => $edit_id ), FinancePage::url( $project_id, array( 'view' => 'installments', 'edit' => $edit_id ) ), 'gdp-stack-form' ); ?>
							<label><?php esc_html_e( 'Hecho', 'gestion-de-proyectos' ); ?> <select name="event_key">
								<option value="transferencia_aceptada"><?php esc_html_e( 'Transferencia aceptada en la plataforma (con comprobante de ingreso)', 'gestion-de-proyectos' ); ?></option>
								<option value="comprobante_enviado"><?php esc_html_e( 'Comprobante de ingreso enviado', 'gestion-de-proyectos' ); ?></option>
								<option value="informe_aprobado"><?php esc_html_e( 'Informe de avance aprobado por la contraparte', 'gestion-de-proyectos' ); ?></option>
								<option value="aporte_acreditado"><?php esc_html_e( 'Aporte pecuniario enterado y acreditado', 'gestion-de-proyectos' ); ?></option>
								<option value="carta_solicitud"><?php esc_html_e( 'Carta de solicitud remitida', 'gestion-de-proyectos' ); ?></option>
								<option value="otro"><?php esc_html_e( 'Otro', 'gestion-de-proyectos' ); ?></option>
							</select></label>
							<label><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?> <input type="date" name="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
							<label><?php esc_html_e( 'Número de comprobante o nota', 'gestion-de-proyectos' ); ?> <input type="text" name="note"></label>
							<label><?php esc_html_e( 'Documento', 'gestion-de-proyectos' ); ?> <select name="document_id"><?php echo self::document_options( $project_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Declarar', 'gestion-de-proyectos' ); ?></button>
						<?php self::form_close(); ?>
						<p><a class="button" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => 'aceptar_transferencia', 'entity_type' => 'installment', 'entity_id' => $edit_id ) ) ); ?>"><?php esc_html_e( 'Hoja: aceptar transferencia', 'gestion-de-proyectos' ); ?></a> <a class="button" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'assistant', 'guide' => 'solicitar_cuota', 'entity_type' => 'installment', 'entity_id' => $edit_id ) ) ); ?>"><?php esc_html_e( 'Hoja: solicitar cuota', 'gestion-de-proyectos' ); ?></a></p>
					</div>
					<?php endif; ?>
					<div class="gdp-card"><h2><?php esc_html_e( 'Eventos', 'gestion-de-proyectos' ); ?></h2><?php self::events_table( EventRepository::for_entity( 'installment', $edit_id ) ); ?></div>
				</div>
				<?php endif; ?>
			</div>
		<?php elseif ( $can_rules ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'installments', 'edit' => 0 ) ) ); ?>"><?php esc_html_e( 'Nueva cuota', 'gestion-de-proyectos' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Convenio.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_agreement( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$a          = $status['agreement'];
		$can_rules  = Access::can( 'finance.rules', $project_id );
		?>
		<div class="gdp-card gdp-card--form">
			<p class="gdp-muted"><?php esc_html_e( 'El convenio fija el perfil de fondo, las fuentes y sus montos, el plazo y los datos del proyecto en la plataforma. La fecha de inicio es la de la transferencia de la cuota 1; desde ella se cuenta la obligación de rendir cada mes.', 'gestion-de-proyectos' ); ?></p>
			<?php self::form_open( $project_id, 'save_agreement', array( 'expected_version' => $a['version'] ), FinancePage::url( $project_id, array( 'view' => 'agreement' ) ), 'gdp-stack-form' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Perfil de fondo', 'gestion-de-proyectos' ); ?></th><td><select name="data[profile]" <?php disabled( ! $can_rules ); ?>><?php foreach ( Profiles::labels() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $a['profile'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><?php esc_html_e( 'Otorgante y programa', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[funder]" class="regular-text" value="<?php echo esc_attr( (string) $a['funder'] ); ?>" placeholder="<?php esc_attr_e( 'Gobierno Regional de Coquimbo', 'gestion-de-proyectos' ); ?>"> <input type="text" name="data[program]" class="regular-text" value="<?php echo esc_attr( (string) $a['program'] ); ?>" placeholder="<?php esc_attr_e( 'Fondo Regional para la Productividad y el Desarrollo', 'gestion-de-proyectos' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Convenio y acto aprobatorio', 'gestion-de-proyectos' ); ?></th><td><input type="date" name="data[agreement_date]" value="<?php echo esc_attr( (string) $a['agreement_date'] ); ?>"> <input type="text" name="data[approval_act]" value="<?php echo esc_attr( (string) $a['approval_act'] ); ?>" placeholder="<?php esc_attr_e( 'Resolución exenta', 'gestion-de-proyectos' ); ?>"> <input type="date" name="data[approval_date]" value="<?php echo esc_attr( (string) $a['approval_date'] ); ?>"> <span class="description"><?php esc_html_e( 'fecha de total tramitación', 'gestion-de-proyectos' ); ?></span></td></tr>
					<tr><th><?php esc_html_e( 'Plazo de ejecución', 'gestion-de-proyectos' ); ?></th><td><input type="date" name="data[start_date]" value="<?php echo esc_attr( (string) $a['start_date'] ); ?>"> <input type="date" name="data[end_date]" value="<?php echo esc_attr( (string) $a['end_date'] ); ?>"> <label><?php esc_html_e( 'meses', 'gestion-de-proyectos' ); ?> <input type="number" name="data[months]" class="small-text" min="0" value="<?php echo (int) $a['months']; ?>"></label></td></tr>
					<tr><th><?php esc_html_e( 'Fondo (pesos)', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[fund_amount]" value="<?php echo esc_attr( number_format( (float) $a['fund_amount'], 0, ',', '.' ) ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Aporte pecuniario (pesos)', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[cash_amount]" value="<?php echo esc_attr( number_format( (float) $a['cash_amount'], 0, ',', '.' ) ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Aporte no pecuniario (pesos)', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[inkind_amount]" value="<?php echo esc_attr( number_format( (float) $a['inkind_amount'], 0, ',', '.' ) ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Garantía exigible', 'gestion-de-proyectos' ); ?></th><td><label><input type="checkbox" name="data[guarantee_required]" value="1" <?php checked( $a['guarantee_required'] ); ?>> <?php esc_html_e( 'El convenio exige garantía de fiel cumplimiento', 'gestion-de-proyectos' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Plataforma', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[platform]" value="<?php echo esc_attr( (string) $a['platform'] ); ?>"> <input type="text" name="data[platform_code]" value="<?php echo esc_attr( (string) $a['platform_code'] ); ?>" placeholder="<?php esc_attr_e( 'Código del proyecto', 'gestion-de-proyectos' ); ?>"> <label><?php esc_html_e( 'fin de actividades', 'gestion-de-proyectos' ); ?> <input type="date" name="data[platform_end_date]" value="<?php echo esc_attr( (string) $a['platform_end_date'] ); ?>"></label> <label><?php esc_html_e( 'fecha máxima para rendir', 'gestion-de-proyectos' ); ?> <input type="date" name="data[platform_render_until]" value="<?php echo esc_attr( (string) $a['platform_render_until'] ); ?>"></label></td></tr>
					<tr><th><?php esc_html_e( 'Cuenta y centro de costo', 'gestion-de-proyectos' ); ?></th><td><input type="text" name="data[bank_account]" class="regular-text" value="<?php echo esc_attr( (string) $a['bank_account'] ); ?>" placeholder="<?php esc_attr_e( 'Cuenta registrada en la plataforma', 'gestion-de-proyectos' ); ?>"> <input type="text" name="data[cost_center]" value="<?php echo esc_attr( (string) $a['cost_center'] ); ?>" placeholder="<?php esc_attr_e( 'Centro de costo', 'gestion-de-proyectos' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?></th><td><textarea name="data[notes]" rows="3" class="large-text"><?php echo esc_textarea( (string) $a['notes'] ); ?></textarea></td></tr>
				</table>
				<?php if ( $can_rules ) : ?><p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar convenio', 'gestion-de-proyectos' ); ?></button></p><?php endif; ?>
			<?php self::form_close(); ?>
		</div>
		<?php
	}

	/**
	 * Ítems, reglas y modificaciones.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_items( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$can_rules  = Access::can( 'finance.rules', $project_id );
		$back       = FinancePage::url( $project_id, array( 'view' => 'items' ) );
		$items      = ItemRepository::all( $project_id );
		$profile    = Profiles::get( $status['profile'] );
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Ítems del convenio: asignado por fuente', 'gestion-de-proyectos' ); ?></h2>
			<?php if ( empty( $items ) && $can_rules ) : ?>
				<?php self::form_open( $project_id, 'seed_items', array(), $back ); ?><button type="submit" class="button button-primary"><?php esc_html_e( 'Crear los ítems del perfil', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
			<?php endif; ?>
			<?php if ( $items ) : ?>
				<?php $detached = array(); ?>
				<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Grupo', 'gestion-de-proyectos' ); ?></th><th class="gdp-source-head gdp-source-head--fondo"><?php esc_html_e( 'Fondo', 'gestion-de-proyectos' ); ?></th><th class="gdp-source-head gdp-source-head--pecuniario"><?php esc_html_e( 'Pecuniario', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'No pecuniario', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Plataforma', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Partida de compras', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $items as $i ) : ?>
						<tr>
							<?php if ( $can_rules ) : ?>
							<?php $fid = 'gdp-item-' . (int) $i['id']; $detached[] = array( 'save_item', array( 'data[slug]' => $i['slug'] ), $fid ); ?>
							<td><input type="text" name="data[label]" form="<?php echo esc_attr( $fid ); ?>" value="<?php echo esc_attr( $i['label'] ); ?>"><br><code><?php echo esc_html( $i['slug'] ); ?></code></td>
							<td><select name="data[item_group]" form="<?php echo esc_attr( $fid ); ?>"><option value="programa" <?php selected( $i['item_group'], 'programa' ); ?>><?php esc_html_e( 'programa', 'gestion-de-proyectos' ); ?></option><option value="administracion" <?php selected( $i['item_group'], 'administracion' ); ?>><?php esc_html_e( 'administración', 'gestion-de-proyectos' ); ?></option></select></td>
							<td><input type="text" name="data[assigned_fund]" form="<?php echo esc_attr( $fid ); ?>" class="gdp-amount" value="<?php echo esc_attr( number_format( (float) $i['assigned_fund'], 0, ',', '.' ) ); ?>"></td>
							<td><input type="text" name="data[assigned_cash]" form="<?php echo esc_attr( $fid ); ?>" class="gdp-amount" value="<?php echo esc_attr( number_format( (float) $i['assigned_cash'], 0, ',', '.' ) ); ?>"></td>
							<td><input type="text" name="data[assigned_inkind]" form="<?php echo esc_attr( $fid ); ?>" class="gdp-amount" value="<?php echo esc_attr( number_format( (float) $i['assigned_inkind'], 0, ',', '.' ) ); ?>"></td>
							<td><select name="data[platform_type]" form="<?php echo esc_attr( $fid ); ?>"><?php foreach ( $profile->platform_types() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $i['platform_type'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select> <input type="text" name="data[platform_subclass]" form="<?php echo esc_attr( $fid ); ?>" class="gdp-short" value="<?php echo esc_attr( $i['platform_subclass'] ); ?>"></td>
							<td><input type="text" name="data[budget_line]" form="<?php echo esc_attr( $fid ); ?>" class="gdp-short" value="<?php echo esc_attr( $i['budget_line'] ); ?>"> <input type="hidden" name="data[cap_rule]" form="<?php echo esc_attr( $fid ); ?>" value="<?php echo esc_attr( $i['cap_rule'] ); ?>"></td>
							<td><button type="submit" form="<?php echo esc_attr( $fid ); ?>" class="button button-small"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button></td>
							<?php else : ?>
							<td><?php echo esc_html( $i['label'] ); ?></td><td><?php echo esc_html( $i['item_group'] ); ?></td><td><?php echo esc_html( self::money( (float) $i['assigned_fund'] ) ); ?></td><td><?php echo esc_html( self::money( (float) $i['assigned_cash'] ) ); ?></td><td><?php echo esc_html( self::money( (float) $i['assigned_inkind'] ) ); ?></td><td><?php echo esc_html( $i['platform_type'] . ' · ' . $i['platform_subclass'] ); ?></td><td><?php echo esc_html( $i['budget_line'] ); ?></td><td></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php foreach ( $detached as $d ) { self::detached_form( $project_id, $d[0], $d[1], $back, $d[2] ); } ?>
				<?php if ( $can_rules ) : ?>
					<p><?php self::form_open( $project_id, 'seed_items', array(), $back ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Añadir los ítems del perfil que falten', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?></p>
					<?php self::form_open( $project_id, 'save_item', array(), $back ); ?>
						<input type="text" name="data[slug]" placeholder="<?php esc_attr_e( 'identificador', 'gestion-de-proyectos' ); ?>" required> <input type="text" name="data[label]" placeholder="<?php esc_attr_e( 'Nombre del ítem', 'gestion-de-proyectos' ); ?>"> <button type="submit" class="button button-small"><?php esc_html_e( 'Añadir ítem', 'gestion-de-proyectos' ); ?></button>
					<?php self::form_close(); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="gdp-card">
			<h2><?php esc_html_e( 'Registro de reglas', 'gestion-de-proyectos' ); ?></h2>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'Cada regla lleva su valor, su fuente y su capa: las del convenio (fijadas aquí) prevalecen sobre las del perfil. Vigencia opcional.', 'gestion-de-proyectos' ); ?></p>
			<?php $detached = array(); ?>
			<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Regla', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Valor', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Capa', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $status['rules'] as $key => $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['label'] ); ?><br><code><?php echo esc_html( $key ); ?></code></td>
						<?php if ( $can_rules ) : ?>
							<?php $fid = 'gdp-rule-' . sanitize_html_class( $key ); $detached[] = array( 'set_rule', array( 'data[rule_key]' => $key ), $fid ); ?>
							<td><input type="text" name="data[value]" form="<?php echo esc_attr( $fid ); ?>" value="<?php echo esc_attr( (string) $r['value'] ); ?>"></td>
							<td><input type="text" name="data[source]" form="<?php echo esc_attr( $fid ); ?>" value="<?php echo esc_attr( (string) $r['source'] ); ?>" class="regular-text"></td>
							<td><?php echo esc_html( $r['layer'] ); ?><?php echo $r['valid_from'] || $r['valid_to'] ? '<br><span class="gdp-small">' . esc_html( (string) $r['valid_from'] . ' a ' . (string) $r['valid_to'] ) . '</span>' : ''; ?></td>
							<td><button type="submit" form="<?php echo esc_attr( $fid ); ?>" class="button button-small"><?php esc_html_e( 'Fijar', 'gestion-de-proyectos' ); ?></button>
							<?php if ( 'convenio' === $r['layer'] ) : ?><?php self::form_open( $project_id, 'reset_rule', array( 'rule_key' => $key ), $back ); ?><button type="submit" class="button-link"><?php esc_html_e( 'Volver al perfil', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?><?php endif; ?></td>
						<?php else : ?>
							<td><?php echo esc_html( (string) $r['value'] ); ?></td><td><?php echo esc_html( (string) $r['source'] ); ?></td><td><?php echo esc_html( $r['layer'] ); ?></td><td></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php foreach ( $detached as $d ) { self::detached_form( $project_id, $d[0], $d[1], $back, $d[2] ); } ?>
		</div>

		<div class="gdp-card">
			<h2><?php esc_html_e( 'Modificaciones del convenio', 'gestion-de-proyectos' ); ?></h2>
			<?php $mods = ModificationRepository::all( $project_id ); $labels = ModificationRepository::labels(); ?>
			<?php if ( $mods ) : ?>
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Solicitada / aprobada', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Acto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Detalle', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $mods as $m ) : ?>
						<tr>
							<td><?php echo esc_html( $labels['kinds'][ $m['kind'] ] ?? $m['kind'] ); ?></td>
							<td><?php echo esc_html( $labels['statuses'][ $m['status'] ] ?? $m['status'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( (string) $m['requested_at'] . ' / ' . (string) $m['approved_at'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( $m['act_number'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( implode( '; ', array_map( static fn( array $d ): string => sprintf( '%s %s %+d', $d['item'], $d['source'], (int) $d['delta'] ), $m['details'] ) ) . ( $m['notes'] ? ' · ' . $m['notes'] : '' ) ); ?></td>
							<td><?php if ( $can_rules && 'aprobada' !== $m['status'] ) : ?><?php self::form_open( $project_id, 'update_modification', array( 'modification_id' => $m['id'], 'data[status]' => 'aprobada', 'data[approved_at]' => current_time( 'Y-m-d' ) ), $back ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Marcar aprobada', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?> <?php self::form_open( $project_id, 'delete_modification', array( 'modification_id' => $m['id'] ), $back ); ?><button type="submit" class="button-link"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $can_rules ) : ?>
				<h3><?php esc_html_e( 'Registrar una modificación', 'gestion-de-proyectos' ); ?></h3>
				<?php self::form_open( $project_id, 'create_modification', array(), $back, 'gdp-stack-form' ); ?>
					<label><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?> <select name="data[kind]"><?php foreach ( $labels['kinds'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?> <select name="data[status]"><?php foreach ( $labels['statuses'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Solicitada el', 'gestion-de-proyectos' ); ?> <input type="date" name="data[requested_at]" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
					<label><?php esc_html_e( 'Acto aprobatorio', 'gestion-de-proyectos' ); ?> <input type="text" name="data[act_number]"></label>
					<p class="description"><?php esc_html_e( 'Detalle de una reitemización: ítem, fuente y cambio (positivo o negativo); la suma debe ser cero. Al aprobarse, los asignados se ajustan.', 'gestion-de-proyectos' ); ?></p>
					<?php for ( $k = 0; $k < 4; $k++ ) : ?>
						<div class="gdp-form-row gdp-detail-row">
							<select name="details[<?php echo (int) $k; ?>][item]"><option value=""><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></option><?php foreach ( $items as $i ) : ?><option value="<?php echo esc_attr( $i['slug'] ); ?>"><?php echo esc_html( $i['label'] ); ?></option><?php endforeach; ?></select>
							<select name="details[<?php echo (int) $k; ?>][source]"><option value="fondo"><?php esc_html_e( 'Fondo', 'gestion-de-proyectos' ); ?></option><option value="pecuniario"><?php esc_html_e( 'Pecuniario', 'gestion-de-proyectos' ); ?></option></select>
							<input type="text" name="details[<?php echo (int) $k; ?>][delta]" placeholder="<?php esc_attr_e( '+ o − pesos', 'gestion-de-proyectos' ); ?>">
						</div>
					<?php endfor; ?>
					<label><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?> <textarea name="data[notes]" rows="2"></textarea></label>
					<button type="submit" class="button"><?php esc_html_e( 'Registrar', 'gestion-de-proyectos' ); ?></button>
				<?php self::form_close(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Caja: programación y conciliación.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_cash( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'finance.edit', $project_id );
		$can_rec    = Access::can( 'finance.reconcile', $project_id );
		$tab        = isset( $_GET['tab'] ) && 'ledger' === $_GET['tab'] ? 'ledger' : 'plan'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back       = FinancePage::url( $project_id, array( 'view' => 'cash', 'tab' => $tab ) );
		?>
		<p>
			<a class="button <?php echo 'plan' === $tab ? 'button-primary' : ''; ?>" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'cash' ) ) ); ?>"><?php esc_html_e( 'Programación de caja', 'gestion-de-proyectos' ); ?></a>
			<a class="button <?php echo 'ledger' === $tab ? 'button-primary' : ''; ?>" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'cash', 'tab' => 'ledger' ) ) ); ?>"><?php esc_html_e( 'Conciliación (centro de costo)', 'gestion-de-proyectos' ); ?></a>
		</p>
		<?php if ( 'ledger' === $tab ) : ?>
			<?php self::ledger( $project_id, $status, $can_rec, $back ); ?>
			<?php return; ?>
		<?php endif; ?>
		<?php
		$plan_id = isset( $_GET['plan_id'] ) ? (int) $_GET['plan_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan    = $plan_id > 0 ? CashPlanRepository::find( $plan_id ) : ( $plan_id < 0 ? null : ( $status['cash_plan'] ? $status['cash_plan']['plan'] : null ) );
		if ( $plan && $plan['project_id'] !== $project_id ) {
			$plan = null;
		}
		$rows     = $plan ? CashPlanRepository::rows( (int) $plan['id'] ) : array();
		$rule     = static fn( string $key, string $fallback = '' ): string => (string) ( $status['rules'][ $key ]['value'] ?? $fallback );
		$checks   = $plan && $rows ? FinanceService::plan_checks( $project_id, $rows, $status['agreement'], Calendar::for_project( $project_id ), $rule ) : array();
		$baseline = $plan && $rows ? FinanceService::plan_baseline( $project_id, $rows ) : null;
		$actual = PaymentRepository::paid_by_period( $project_id, 'fondo' );
		if ( ! $rows ) {
			$from = substr( (string) $status['today'], 0, 7 );
			for ( $k = 0; $k < 12; $k++ ) {
				$rows[] = array( 'period' => \GDP\Modules\Finance\Logic\Deadlines::add_months( $from, $k ), 'transfer' => 0.0, 'spend' => 0.0, 'cash' => 0.0, 'milestone' => '' );
			}
		}
		?>
		<div class="gdp-card">
			<h2><?php echo $plan ? esc_html( $plan['name'] . ' (' . $plan['status'] . ')' ) : esc_html__( 'Nueva programación de caja', 'gestion-de-proyectos' ); ?></h2>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'Por mes: transferencia solicitada al Gobierno Regional, gasto programado y aporte pecuniario a enterar. Los controles C1 y C2 son los del formato de la Dirección de Investigación; C3 a C6 comprueban que el plan pueda ocurrir (caja no negativa, condición de giro, ventana del convenio, ítems).', 'gestion-de-proyectos' ); ?></p>
			<?php if ( $baseline && '' !== $baseline['first'] ) : ?>
				<p class="gdp-small"><?php echo esc_html( sprintf( /* translators: 1: mes, 2: pagado, 3: transferido, 4: cuotas recibidas. */ __( 'Línea base al inicio de %1$s: pagado con cargo al Fondo %2$s; transferido %3$s (%4$s). Los controles parten de esa base para no contar dos veces el gasto real de los meses programados.', 'gestion-de-proyectos' ), Assistant::month_label( $baseline['first'] ), self::money( $baseline['spent'] ), self::money( $baseline['transferred'] ), $baseline['received'] ? sprintf( /* translators: lista de cuotas. */ _n( 'cuota %s', 'cuotas %s', count( $baseline['received'] ), 'gestion-de-proyectos' ), implode( ', ', $baseline['received'] ) ) : __( 'ninguna cuota', 'gestion-de-proyectos' ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( $checks ) : ?>
				<table class="widefat striped gdp-table gdp-conditions">
					<tbody>
					<?php foreach ( $checks as $c ) : ?>
						<tr><td><code><?php echo esc_html( $c['key'] ); ?></code></td><td><?php echo esc_html( $c['label'] ); ?><br><span class="gdp-muted gdp-small"><?php echo esc_html( $c['detail'] ); ?></span></td><td><?php echo self::light( $c['ok'], null === $c['ok'] ? __( 'no evaluable', 'gestion-de-proyectos' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-stack-form">
				<?php wp_nonce_field( 'gdp_finance_plan_' . $project_id ); ?>
				<input type="hidden" name="action" value="gdp_finance_plan"><input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>"><input type="hidden" name="plan_id" value="<?php echo $plan ? (int) $plan['id'] : 0; ?>">
				<div class="gdp-inline-form">
					<label><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?> <input type="text" name="name" value="<?php echo esc_attr( $plan ? $plan['name'] : '' ); ?>"></label>
					<label><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?> <select name="status"><?php foreach ( CashPlanRepository::STATUSES as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $plan ? $plan['status'] : 'borrador', $s ); ?>><?php echo esc_html( $s ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Enviada el', 'gestion-de-proyectos' ); ?> <input type="date" name="submitted_at" value="<?php echo esc_attr( $plan ? (string) $plan['submitted_at'] : '' ); ?>"></label>
				</div>
				<div class="gdp-table-scroll"><table class="widefat gdp-table gdp-plan-grid">
					<thead><tr><th><?php esc_html_e( 'Mes', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Transferencia solicitada', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Gasto programado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Hito', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pagado real', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_merge( $rows, array_fill( 0, 3, array( 'period' => '', 'transfer' => 0.0, 'spend' => 0.0, 'cash' => 0.0, 'milestone' => '' ) ) ) as $k => $r ) : ?>
						<tr>
							<td><input type="month" name="period[<?php echo (int) $k; ?>]" value="<?php echo esc_attr( $r['period'] ); ?>" <?php disabled( ! $can_edit ); ?>></td>
							<td><input type="text" name="transfer[<?php echo (int) $k; ?>]" class="gdp-amount" value="<?php echo esc_attr( $r['transfer'] > 0 ? number_format( $r['transfer'], 0, ',', '.' ) : '' ); ?>" <?php disabled( ! $can_edit ); ?>></td>
							<td><input type="text" name="spend[<?php echo (int) $k; ?>]" class="gdp-amount" value="<?php echo esc_attr( $r['spend'] > 0 ? number_format( $r['spend'], 0, ',', '.' ) : '' ); ?>" <?php disabled( ! $can_edit ); ?>></td>
							<td><input type="text" name="cash[<?php echo (int) $k; ?>]" class="gdp-amount" value="<?php echo esc_attr( $r['cash'] > 0 ? number_format( $r['cash'], 0, ',', '.' ) : '' ); ?>" <?php disabled( ! $can_edit ); ?>></td>
							<td><input type="text" name="milestone[<?php echo (int) $k; ?>]" value="<?php echo esc_attr( $r['milestone'] ); ?>" <?php disabled( ! $can_edit ); ?>></td>
							<td class="gdp-num"><?php echo '' !== $r['period'] && isset( $actual[ $r['period'] ] ) ? esc_html( self::money( $actual[ $r['period'] ] ) ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<label><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?> <textarea name="notes" rows="2"><?php echo esc_textarea( $plan ? (string) $plan['notes'] : '' ); ?></textarea></label>
				<?php if ( $can_edit ) : ?><p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar programación', 'gestion-de-proyectos' ); ?></button>
				<?php if ( $plan && Access::can( 'finance.export', $project_id ) ) : ?><a class="button" href="<?php echo esc_url( FinancePage::export_url( $project_id, 'cash_plan', (int) $plan['id'] ) ); ?>"><?php esc_html_e( 'Descargar en el formato de la Dirección de Investigación', 'gestion-de-proyectos' ); ?></a><?php endif; ?>
				<a class="button" href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'cash', 'plan_id' => -1 ) ) ); ?>"><?php esc_html_e( 'Nueva programación', 'gestion-de-proyectos' ); ?></a></p><?php endif; ?>
			</form>
		</div>
		<?php $plans = CashPlanRepository::all( $project_id ); ?>
		<?php if ( count( $plans ) > 1 ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Programaciones anteriores', 'gestion-de-proyectos' ); ?></h2>
				<ul class="gdp-list">
				<?php foreach ( $plans as $pl ) : ?>
					<li><a href="<?php echo esc_url( FinancePage::url( $project_id, array( 'view' => 'cash', 'plan_id' => $pl['id'] ) ) ); ?>"><?php echo esc_html( $pl['name'] ); ?></a> <span class="gdp-muted gdp-small"><?php echo esc_html( $pl['status'] . ( $pl['submitted_at'] ? ' · ' . $pl['submitted_at'] : '' ) ); ?></span></li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Conciliación.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $status     Estado.
	 * @param bool                $can_rec    Puede conciliar.
	 * @param string              $back       Retorno.
	 * @return void
	 */
	private static function ledger( int $project_id, array $status, bool $can_rec, string $back ): void {
		$labels = LedgerRepository::labels();
		?>
		<div class="gdp-grid">
			<?php foreach ( $status['sources'] as $slug => $s ) : ?>
				<div class="gdp-card gdp-source gdp-source--<?php echo esc_attr( $slug ); ?>">
					<h2><?php echo esc_html( $s['label'] ); ?></h2>
					<table class="gdp-facts">
						<tr><th><?php esc_html_e( 'Saldo calculado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::money( $s['balance'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Saldo de la cartola', 'gestion-de-proyectos' ); ?></th><td><?php echo $s['ledger_count'] > 0 ? esc_html( self::money( $s['ledger_balance'] ) ) : '—'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Diferencia', 'gestion-de-proyectos' ); ?></th><td class="<?php echo null !== $s['difference'] && abs( (float) $s['difference'] ) > 0.5 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><?php echo null === $s['difference'] ? '—' : esc_html( self::money( $s['difference'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Por aclarar', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $s['unmatched']; ?></td></tr>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( $can_rec ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Importar movimientos del centro de costo', 'gestion-de-proyectos' ); ?></h2>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Pegue las filas de la planilla de Finanzas, una por línea: fecha;referencia;glosa;cargo;abono (separadas por punto y coma, tabulador o coma; fecha AAAA-MM-DD o DD/MM/AAAA). Cada cargo se empareja con un pago del mismo monto y fecha cercana; cada abono, con una cuota o un aporte del mismo monto. El resto queda por aclarar.', 'gestion-de-proyectos' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-stack-form">
					<?php wp_nonce_field( 'gdp_finance_ledger_' . $project_id ); ?>
					<input type="hidden" name="action" value="gdp_finance_ledger"><input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<label><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?> <select name="source"><option value="fondo"><?php esc_html_e( 'Fondo', 'gestion-de-proyectos' ); ?></option><option value="pecuniario"><?php esc_html_e( 'Aporte pecuniario', 'gestion-de-proyectos' ); ?></option></select></label>
					<textarea name="rows" rows="8" class="large-text" placeholder="2026-09-30;4512;Pago boleta 45 J. Pena;300000;0"></textarea>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Importar', 'gestion-de-proyectos' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Movimientos', 'gestion-de-proyectos' ); ?></h2>
			<?php $entries = LedgerRepository::all( $project_id ); ?>
			<?php if ( empty( $entries ) ) : ?>
				<p class="gdp-muted"><?php esc_html_e( 'Sin movimientos importados.', 'gestion-de-proyectos' ); ?></p>
			<?php else : ?>
				<?php $detached = array(); $payments = array(); foreach ( array_merge( PaymentRepository::paid( $project_id, 'fondo' ), PaymentRepository::paid( $project_id, 'pecuniario' ) ) as $p ) { $payments[ $p['id'] ] = $p; } ?>
				<div class="gdp-table-scroll"><table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Referencia', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Glosa', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Cargo', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Abono', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Emparejado con', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr class="<?php echo 'por_aclarar' === $e['status'] ? 'gdp-row-danger' : ''; ?>">
							<td><?php echo esc_html( (string) $e['entry_date'] ); ?></td>
							<td><span class="gdp-source-chip gdp-source-chip--<?php echo esc_attr( $e['source'] ); ?>"><?php echo esc_html( $e['source'] ); ?></span></td>
							<td class="gdp-small"><?php echo esc_html( $e['reference'] ); ?></td>
							<td class="gdp-small"><?php echo esc_html( $e['description'] ); ?></td>
							<td class="gdp-num"><?php echo $e['debit'] > 0 ? esc_html( self::money( (float) $e['debit'] ) ) : ''; ?></td>
							<td class="gdp-num"><?php echo $e['credit'] > 0 ? esc_html( self::money( (float) $e['credit'] ) ) : ''; ?></td>
							<?php if ( $can_rec ) : ?>
								<?php $fid = 'gdp-ledger-' . (int) $e['id']; $detached[] = array( 'update_ledger', array( 'entry_id' => $e['id'] ), $fid ); ?>
								<td><select name="data[kind]" form="<?php echo esc_attr( $fid ); ?>"><?php foreach ( $labels['kinds'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e['kind'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
								<td><select name="data[payment_id]" form="<?php echo esc_attr( $fid ); ?>"><option value="0">—</option><?php foreach ( $payments as $p ) : ?><option value="<?php echo (int) $p['id']; ?>" <?php selected( $e['payment_id'], $p['id'] ); ?>><?php echo esc_html( $p['code'] . ' ' . self::money( (float) $p['amount'] ) ); ?></option><?php endforeach; ?></select> <input type="number" name="data[installment_no]" form="<?php echo esc_attr( $fid ); ?>" class="small-text" min="0" value="<?php echo (int) $e['installment_no']; ?>" title="<?php esc_attr_e( 'Cuota', 'gestion-de-proyectos' ); ?>"></td>
								<td><select name="data[status]" form="<?php echo esc_attr( $fid ); ?>"><?php foreach ( $labels['statuses'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
								<td><button type="submit" form="<?php echo esc_attr( $fid ); ?>" class="button button-small"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button></td>
							<?php else : ?>
								<td><?php echo esc_html( $labels['kinds'][ $e['kind'] ] ?? $e['kind'] ); ?></td><td><?php echo $e['payment_id'] > 0 ? esc_html( $payments[ $e['payment_id'] ]['code'] ?? '#' . $e['payment_id'] ) : ( $e['installment_no'] > 0 ? esc_html( sprintf( /* translators: número de la cuota. */ __( 'cuota %d', 'gestion-de-proyectos' ), $e['installment_no'] ) ) : '' ); ?></td><td><?php echo esc_html( $labels['statuses'][ $e['status'] ] ?? $e['status'] ); ?></td><td></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php foreach ( $detached as $d ) { self::detached_form( $project_id, $d[0], $d[1], $back, $d[2] ); } ?>
				<?php if ( $can_rec ) : ?>
					<?php $batches = array_unique( array_map( static fn( array $e ): string => (string) $e['batch'], $entries ) ); ?>
					<p class="gdp-small"><?php esc_html_e( 'Eliminar un lote importado:', 'gestion-de-proyectos' ); ?>
					<?php foreach ( $batches as $b ) : ?>
						<?php if ( '' === $b ) { continue; } ?>
						<?php self::form_open( $project_id, 'delete_ledger_batch', array( 'batch' => $b ), $back ); ?><button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar el lote?', 'gestion-de-proyectos' ) ); ?>');"><?php echo esc_html( $b ); ?></button><?php self::form_close(); ?>
					<?php endforeach; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Garantías.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param array<string,mixed> $status  Estado.
	 * @return void
	 */
	private static function view_guarantees( array $project, array $status ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'finance.edit', $project_id );
		$labels     = GuaranteeRepository::labels();
		$back       = FinancePage::url( $project_id, array( 'view' => 'guarantees' ) );
		$rows       = GuaranteeRepository::all( $project_id );
		$rules      = $status['rules'];
		$fund       = (float) $status['agreement']['fund_amount'];
		?>
		<p class="gdp-muted"><?php echo esc_html( sprintf( /* translators: 1: porcentaje del Fondo, 2: monto, 3: días corridos. */ __( 'Garantía de fiel cumplimiento: %1$s %% del Fondo (%2$s), vigente hasta el término más %3$s días corridos. Una garantía por la fracción no rendida de una cuota sustituye a la rendición del 100 %% en la condición de giro (G1).', 'gestion-de-proyectos' ), (string) ( $rules['garantia_pct']['value'] ?? '10' ), self::money( $fund * (float) ( $rules['garantia_pct']['value'] ?? 10 ) / 100 ), (string) ( $rules['garantia_vigencia_extra']['value'] ?? '120' ) ) ); ?></p>
		<div class="gdp-card">
			<?php if ( empty( $rows ) ) : ?>
				<p class="gdp-muted"><?php esc_html_e( 'Sin garantías registradas.', 'gestion-de-proyectos' ); ?></p>
			<?php else : ?>
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Instrumento', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Emisor y número', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Monto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Vigencia', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Cuota', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $g ) : ?>
						<tr>
							<td><?php echo esc_html( $labels['kinds'][ $g['kind'] ] ?? $g['kind'] ); ?></td>
							<td><?php echo esc_html( $labels['instruments'][ $g['instrument'] ] ?? $g['instrument'] ); ?></td>
							<td><?php echo esc_html( trim( $g['issuer'] . ' ' . $g['number'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::money( (float) $g['amount'] ) ); ?></td>
							<td class="<?php echo $g['valid_until'] && $g['valid_until'] < $status['today'] && 'vigente' === $g['status'] ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( (string) $g['issued_at'] . ' a ' . (string) $g['valid_until'] ); ?></td>
							<td><?php echo $g['installment_no'] > 0 ? (int) $g['installment_no'] : '—'; ?></td>
							<td><?php echo esc_html( $labels['statuses'][ $g['status'] ] ?? $g['status'] ); ?></td>
							<td><?php if ( $can_edit ) : ?>
								<?php self::form_open( $project_id, 'update_guarantee', array( 'guarantee_id' => $g['id'] ), $back ); ?><select name="data[status]"><?php foreach ( $labels['statuses'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $g['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select> <button type="submit" class="button button-small"><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
								<?php self::form_open( $project_id, 'delete_guarantee', array( 'guarantee_id' => $g['id'] ), $back ); ?><button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar la garantía?', 'gestion-de-proyectos' ) ); ?>');"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button><?php self::form_close(); ?>
							<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php if ( $can_edit ) : ?>
			<div class="gdp-card gdp-card--form">
				<h2><?php esc_html_e( 'Registrar garantía', 'gestion-de-proyectos' ); ?></h2>
				<?php self::form_open( $project_id, 'create_guarantee', array(), $back, 'gdp-stack-form' ); ?>
					<div class="gdp-inline-form">
						<select name="data[kind]"><?php foreach ( $labels['kinds'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
						<select name="data[instrument]"><?php foreach ( $labels['instruments'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
						<input type="text" name="data[issuer]" placeholder="<?php esc_attr_e( 'Emisor', 'gestion-de-proyectos' ); ?>">
						<input type="text" name="data[number]" placeholder="<?php esc_attr_e( 'Número', 'gestion-de-proyectos' ); ?>">
						<input type="text" name="data[amount]" placeholder="<?php esc_attr_e( 'Monto', 'gestion-de-proyectos' ); ?>" required>
						<label><?php esc_html_e( 'Emitida', 'gestion-de-proyectos' ); ?> <input type="date" name="data[issued_at]"></label>
						<label><?php esc_html_e( 'Vigente hasta', 'gestion-de-proyectos' ); ?> <input type="date" name="data[valid_until]"></label>
						<label><?php esc_html_e( 'Cuota', 'gestion-de-proyectos' ); ?> <input type="number" name="data[installment_no]" class="small-text" min="0" value="0"></label>
						<select name="data[document_id]"><?php echo self::document_options( $project_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Registrar', 'gestion-de-proyectos' ); ?></button>
					</div>
				<?php self::form_close(); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Identificador de una cuota por número.
	 *
	 * @param array<string,mixed> $status Estado.
	 * @param int                 $number Número.
	 * @return int
	 */
	private static function installment_id( array $status, int $number ): int {
		foreach ( $status['installments'] as $i ) {
			if ( $i['number'] === $number ) {
				return (int) $i['id'];
			}
		}

		return 0;
	}
}
