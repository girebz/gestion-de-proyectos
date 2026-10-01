<?php
/**
 * Pantallas de adquisiciones y presupuesto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Roles;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\DocumentRepository;
use GDP\Modules\Documents\LinkRepository;
use GDP\Modules\Procurement\BudgetService;
use GDP\Modules\Procurement\ProcurementChecks;
use GDP\Modules\Procurement\ProcurementExport;
use GDP\Modules\Procurement\ProcurementSettings;
use GDP\Modules\Procurement\PurchaseRepository;
use GDP\Modules\Procurement\QuoteRepository;
use GDP\Modules\Procurement\SupplierRepository;
use GDP\Modules\Procurement\UfService;
use GDP\Modules\Planning\ScheduleService;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Compras (lista, formulario, ficha con cotizaciones, etapas y comprobaciones
 * de la orden), proveedores y presupuesto por partida con valores de la
 * unidad de fomento.
 */
final class ProcurementPage extends Page {

	public const SLUG  = 'procurement';
	public const VIEWS = array( 'list', 'edit', 'show', 'suppliers', 'budget' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Slug del menú principal.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		add_submenu_page( $parent, __( 'Compras', 'gestion-de-proyectos' ), __( 'Compras', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores de formularios.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		foreach ( array( 'save_purchase', 'delete_purchase', 'purchase_stage', 'approve_purchase', 'save_quote', 'delete_quote', 'choose_quote', 'quote_items', 'save_supplier', 'delete_supplier', 'save_budget', 'uf_rate', 'procurement_settings', 'purchase_link', 'purchase_unlink' ) as $action ) {
			add_action( 'admin_post_gdp_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_action( 'admin_post_gdp_export_procurement', array( self::class, 'handle_export' ) );
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
	 * URL de una vista.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $args       Parámetros.
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
			self::open( __( 'Compras', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'No hay proyectos visibles.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'procurement.view', $project_id ) ) {
			self::open( __( 'Compras', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'Sin permiso para ver las compras de este proyecto.', 'gestion-de-proyectos' ) . '</p>';
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
			case 'suppliers':
				self::render_suppliers( $project );
				break;
			case 'budget':
				self::render_budget( $project );
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
		if ( ! Access::can( 'procurement.view', $project_id ) ) {
			return;
		}
		$stats   = PurchaseRepository::stats( $project_id );
		$amounts = Access::can( 'procurement.view_amounts', $project_id );
		$budget  = $amounts ? BudgetService::summary( $project_id ) : null;
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Compras y presupuesto', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Compras abiertas', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $stats['open']; ?> <?php esc_html_e( 'de', 'gestion-de-proyectos' ); ?> <?php echo (int) $stats['total']; ?></td></tr>
				<tr><th><?php esc_html_e( 'Esperan decisión', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $stats['awaiting_decision'] > 0 ? 'gdp-text-danger' : ''; ?>"><?php echo (int) $stats['awaiting_decision']; ?></td></tr>
				<?php if ( $budget ) : ?>
					<tr><th><?php esc_html_e( 'Comprometido', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::clp( $budget['totals']['committed'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Ejecutado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::clp( $budget['totals']['executed'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Saldo asignado', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $budget['totals']['balance'] < 0 ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( self::clp( $budget['totals']['balance'] ) ); ?></td></tr>
				<?php endif; ?>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Compras', 'gestion-de-proyectos' ); ?></a>
				<?php if ( $amounts ) : ?><a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'budget' ) ) ); ?>"><?php esc_html_e( 'Presupuesto', 'gestion-de-proyectos' ); ?></a><?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Cabecera.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $view    Vista activa.
	 * @param string              $title   Título.
	 * @return void
	 */
	private static function header( array $project, string $view, string $title ): void {
		$project_id = (int) $project['id'];
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		$tabs       = array( 'list' => __( 'Compras', 'gestion-de-proyectos' ), 'suppliers' => __( 'Proveedores', 'gestion-de-proyectos' ) );
		if ( Access::can( 'procurement.view_amounts', $project_id ) ) {
			$tabs['budget'] = __( 'Presupuesto', 'gestion-de-proyectos' );
		}
		self::open( $title, sprintf( '%s · %s', $project['code'], $project['name'] ) );
		?>
		<div class="gdp-planning-bar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( in_array( $view, array( 'list', 'suppliers', 'budget' ), true ) ? $view : 'list' ); ?>">
				<select name="project_id" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Proyecto', 'gestion-de-proyectos' ); ?>">
					<?php foreach ( $projects as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $view || ( 'list' === $slug && in_array( $view, array( 'edit', 'show' ), true ) ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $project_id, array( 'view' => $slug ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}

	/**
	 * Formato de pesos.
	 *
	 * @param float|null $amount Monto.
	 * @return string
	 */
	private static function clp( ?float $amount ): string {
		return null === $amount ? '—' : '$ ' . number_format( $amount, 0, ',', '.' );
	}

	/**
	 * Monto en su moneda.
	 *
	 * @param float|null $amount   Monto.
	 * @param string     $currency Moneda.
	 * @return string
	 */
	private static function amount( ?float $amount, string $currency ): string {
		if ( null === $amount ) {
			return '—';
		}

		return 'CLP' === $currency ? self::clp( $amount ) : number_format( $amount, 2, ',', '.' ) . ' ' . $currency;
	}

	/**
	 * Lista de compras.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_list( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'procurement.edit', $project_id );
		$amounts    = Access::can( 'procurement.view_amounts', $project_id );
		$stages     = PurchaseRepository::stages( $project_id );
		$lines      = PurchaseRepository::budget_lines( $project_id );
		$filters    = array(
			'stage'       => isset( $_GET['stage'] ) ? sanitize_key( wp_unslash( (string) $_GET['stage'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'      => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'budget_line' => isset( $_GET['budget_line'] ) ? sanitize_key( wp_unslash( (string) $_GET['budget_line'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$rows       = PurchaseRepository::for_project( $project_id, array_filter( $filters ) );
		$stats      = PurchaseRepository::stats( $project_id );
		$pending    = QuoteRepository::pending_requests( $project_id );

		self::header( $project, 'list', __( 'Compras', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-summary">
			<span><strong><?php echo (int) $stats['open']; ?></strong> <?php esc_html_e( 'compras abiertas', 'gestion-de-proyectos' ); ?></span>
			<span class="<?php echo $stats['awaiting_decision'] > 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo (int) $stats['awaiting_decision']; ?></strong> <?php esc_html_e( 'esperan nuestra decisión', 'gestion-de-proyectos' ); ?></span>
			<span class="<?php echo count( $pending ) > 0 ? 'gdp-text-danger' : ''; ?>"><strong><?php echo count( $pending ); ?></strong> <?php esc_html_e( 'cotizaciones sin respuesta del proveedor', 'gestion-de-proyectos' ); ?></span>
			<?php foreach ( array( 'orden_compra', 'pago' ) as $slug ) : ?>
				<span><strong><?php echo (int) ( $stats['by_stage'][ $slug ] ?? 0 ); ?></strong> <?php echo esc_html( mb_strtolower( $stages[ $slug ] ?? $slug ) ); ?></span>
			<?php endforeach; ?>
		</div>
		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nueva compra', 'gestion-de-proyectos' ); ?></a>
			<?php endif; ?>
			<?php if ( $amounts ) : ?>
				<span class="gdp-export-links">
					<?php esc_html_e( 'Rendición:', 'gestion-de-proyectos' ); ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_export_procurement&project_id=' . $project_id . '&format=xlsx' ), 'gdp_export_procurement_' . $project_id ) ); ?>">Excel</a>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gdp_export_procurement&project_id=' . $project_id . '&format=csv' ), 'gdp_export_procurement_' . $project_id ) ); ?>">CSV</a>
				</span>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form gdp-planning-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<select name="stage">
					<option value=""><?php esc_html_e( 'Todas las etapas', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $stages as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['stage'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'Todos los estados', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( PurchaseRepository::status_labels() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="budget_line">
					<option value=""><?php esc_html_e( 'Todas las partidas', 'gestion-de-proyectos' ); ?></option>
					<?php foreach ( $lines as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['budget_line'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'gestion-de-proyectos' ); ?></button>
			</form>
		</div>
		<?php if ( empty( $rows ) ) : ?>
			<p class="gdp-muted"><?php echo 0 === $stats['total'] ? esc_html__( 'El proyecto aún no tiene compras.', 'gestion-de-proyectos' ) : esc_html__( 'Ninguna compra coincide con el filtro.', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
		<table class="widefat striped gdp-table">
			<thead><tr>
				<th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th>
				<th><?php esc_html_e( 'Compra', 'gestion-de-proyectos' ); ?></th>
				<th><?php esc_html_e( 'Partida', 'gestion-de-proyectos' ); ?></th>
				<th><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></th>
				<th><?php esc_html_e( 'Etapa', 'gestion-de-proyectos' ); ?></th>
				<?php if ( $amounts ) : ?><th class="gdp-num"><?php esc_html_e( 'Total', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pesos', 'gestion-de-proyectos' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th>
				<th><?php esc_html_e( 'Avisos', 'gestion-de-proyectos' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $p ) : ?>
				<?php $supplier = $p['supplier_id'] > 0 ? SupplierRepository::find( $p['supplier_id'] ) : null; ?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $p['id'] ) ) ); ?>"><code><?php echo esc_html( $p['code'] ); ?></code></a></td>
					<td><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $p['id'] ) ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a><?php echo 'abierta' !== $p['status'] ? ' ' . self::badge( $p['status'], PurchaseRepository::status_labels()[ $p['status'] ] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( $lines[ $p['budget_line'] ] ?? $p['budget_line'] ); ?></td>
					<td><?php echo esc_html( $supplier ? $supplier['name'] : '' ); ?></td>
					<td><?php echo esc_html( $stages[ $p['stage'] ] ?? $p['stage'] ); ?></td>
					<?php if ( $amounts ) : ?>
						<td class="gdp-num"><?php echo esc_html( self::amount( $p['amount_total'], $p['currency'] ) ); ?></td>
						<td class="gdp-num"><?php echo esc_html( self::clp( $p['amount_clp'] ) ); ?></td>
					<?php endif; ?>
					<td><?php echo esc_html( ScheduleService::user_name( $p['owner_id'] ) ); ?></td>
					<td class="gdp-small"><?php echo PurchaseRepository::awaiting_decision( $p ) ? '<span class="gdp-text-danger">' . esc_html__( 'espera decisión', 'gestion-de-proyectos' ) . '</span>' : ''; ?><?php echo $p['committed'] && ! $p['approved_at'] ? '<span class="gdp-muted">' . esc_html__( 'sin aprobación', 'gestion-de-proyectos' ) . '</span>' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Formulario de compra.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_form( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'procurement.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para editar compras.', 'gestion-de-proyectos' ), 403 );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$p  = $id > 0 ? PurchaseRepository::find( $id ) : null;
		if ( $id > 0 && ( ! $p || $p['project_id'] !== $project_id ) ) {
			wp_die( esc_html__( 'La compra no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$p          = $p ?? array( 'id' => 0, 'title' => '', 'description' => '', 'budget_line' => '', 'supplier_id' => 0, 'owner_id' => get_current_user_id(), 'activity_id' => 0, 'currency' => 'CLP', 'amount_net' => null, 'tax_rate' => 19.0, 'expected_at' => null, 'notes' => '', 'version' => 0, 'order_number' => '', 'invoice_number' => '' );
		$lines      = PurchaseRepository::budget_lines( $project_id );
		$suppliers  = SupplierRepository::for_project( $project_id, true );
		$activities = \GDP\Modules\Planning\ActivityRepository::for_project( $project_id );
		$users      = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name' ) );
		$amounts    = Access::can( 'procurement.view_amounts', $project_id );

		self::header( $project, 'edit', $id > 0 ? __( 'Editar compra', 'gestion-de-proyectos' ) : __( 'Nueva compra', 'gestion-de-proyectos' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form">
			<?php wp_nonce_field( 'gdp_save_purchase_' . $id ); ?>
			<input type="hidden" name="action" value="gdp_save_purchase">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="expected_version" value="<?php echo (int) $p['version']; ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gdp-pur-title"><?php esc_html_e( 'Título', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" id="gdp-pur-title" name="title" class="large-text" required value="<?php echo esc_attr( (string) $p['title'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="gdp-pur-desc"><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-pur-desc" name="description" rows="4" class="large-text"><?php echo esc_textarea( (string) $p['description'] ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="gdp-pur-line"><?php esc_html_e( 'Partida', 'gestion-de-proyectos' ); ?></label></th><td>
					<select name="budget_line" id="gdp-pur-line">
						<option value=""><?php esc_html_e( 'Sin partida', 'gestion-de-proyectos' ); ?></option>
						<?php foreach ( $lines as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $p['budget_line'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th scope="row"><label for="gdp-pur-supplier"><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></label></th><td>
					<select name="supplier_id" id="gdp-pur-supplier">
						<option value="0"><?php esc_html_e( 'Por definir (según cotizaciones)', 'gestion-de-proyectos' ); ?></option>
						<?php foreach ( $suppliers as $s ) : ?><option value="<?php echo (int) $s['id']; ?>" <?php selected( (int) $p['supplier_id'], (int) $s['id'] ); ?>><?php echo esc_html( $s['name'] ); ?></option><?php endforeach; ?>
					</select>
					<a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'suppliers' ) ) ); ?>"><?php esc_html_e( 'Gestionar proveedores', 'gestion-de-proyectos' ); ?></a>
				</td></tr>
				<?php if ( $amounts ) : ?>
				<tr><th scope="row"><label for="gdp-pur-net"><?php esc_html_e( 'Monto neto', 'gestion-de-proyectos' ); ?></label></th><td>
					<input type="text" id="gdp-pur-net" name="amount_net" class="regular-text" value="<?php echo esc_attr( null === $p['amount_net'] ? '' : rtrim( rtrim( number_format( (float) $p['amount_net'], 4, ',', '' ), '0' ), ',' ) ); ?>" placeholder="<?php esc_attr_e( 'Vacío si se tomará de la cotización elegida', 'gestion-de-proyectos' ); ?>">
					<select name="currency" aria-label="<?php esc_attr_e( 'Moneda', 'gestion-de-proyectos' ); ?>">
						<?php foreach ( PurchaseRepository::CURRENCIES as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>" <?php selected( $p['currency'], $c ); ?>><?php echo esc_html( $c ); ?></option><?php endforeach; ?>
					</select>
					<label><?php esc_html_e( 'Impuesto %', 'gestion-de-proyectos' ); ?> <input type="text" name="tax_rate" class="small-text" value="<?php echo esc_attr( rtrim( rtrim( number_format( (float) $p['tax_rate'], 2, ',', '' ), '0' ), ',' ) ); ?>"></label>
				</td></tr>
				<?php endif; ?>
				<tr><th scope="row"><label for="gdp-pur-expected"><?php esc_html_e( 'Entrega esperada', 'gestion-de-proyectos' ); ?></label></th><td><input type="date" id="gdp-pur-expected" name="expected_at" value="<?php echo esc_attr( (string) $p['expected_at'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="gdp-pur-owner"><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></label></th><td>
					<select name="owner_id" id="gdp-pur-owner">
						<option value="0"><?php esc_html_e( 'Sin responsable', 'gestion-de-proyectos' ); ?></option>
						<?php foreach ( $users as $u ) : ?><option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $p['owner_id'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th scope="row"><label for="gdp-pur-activity"><?php esc_html_e( 'Actividad relacionada', 'gestion-de-proyectos' ); ?></label></th><td>
					<select name="activity_id" id="gdp-pur-activity">
						<option value="0"><?php esc_html_e( 'Ninguna', 'gestion-de-proyectos' ); ?></option>
						<?php foreach ( $activities as $a ) : ?><option value="<?php echo (int) $a['id']; ?>" <?php selected( (int) $p['activity_id'], (int) $a['id'] ); ?>><?php echo esc_html( $a['code'] . ' ' . $a['name'] ); ?></option><?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th scope="row"><label for="gdp-pur-notes"><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-pur-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $p['notes'] ); ?></textarea></td></tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $id > 0 ? esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ) : esc_html__( 'Crear compra', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( $id > 0 ? self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ) : self::url( $project_id ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a>
			</p>
		</form>
		<?php
		self::close();
	}

	/**
	 * Ficha de la compra.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_show( array $project ): void {
		$project_id = (int) $project['id'];
		$id         = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$p          = PurchaseRepository::find( $id );
		if ( ! $p || $p['project_id'] !== $project_id ) {
			wp_die( esc_html__( 'La compra no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		$can_edit    = Access::can( 'procurement.edit', $project_id );
		$can_approve = Access::can( 'procurement.approve', $project_id );
		$amounts     = Access::can( 'procurement.view_amounts', $project_id );
		$stages      = PurchaseRepository::stages( $project_id );
		$lines       = PurchaseRepository::budget_lines( $project_id );
		$supplier    = $p['supplier_id'] > 0 ? SupplierRepository::find( $p['supplier_id'] ) : null;
		$suppliers   = SupplierRepository::for_project( $project_id, true );
		$quotes      = QuoteRepository::for_purchase( $id );
		$history     = PurchaseRepository::stage_history( $id );
		$links       = LinkRepository::for_entity( 'purchase', $id );
		$documents   = DocumentRepository::for_project( $project_id, array( 'limit' => 300 ) );
		$check       = $amounts && 'abierta' === $p['status'] && ! $p['committed'] ? ProcurementChecks::order( $p, current_time( 'Y-m-d' ) ) : null;
		$editing_q   = isset( $_GET['quote'] ) ? (int) $_GET['quote'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$next_stage  = null;
		$idx         = PurchaseRepository::stage_index( $p['stage'], $project_id );
		$keys        = array_keys( $stages );
		if ( isset( $keys[ $idx + 1 ] ) ) {
			$next_stage = $keys[ $idx + 1 ];
		}

		self::header( $project, 'show', $p['code'] . ' ' . $p['title'] );
		?>
		<div class="gdp-planning-toolbar">
			<?php if ( $can_edit ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'edit', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Editar', 'gestion-de-proyectos' ); ?></a>
				<?php if ( $can_approve && 'abierta' === $p['status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
						<?php wp_nonce_field( 'gdp_approve_purchase_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_approve_purchase">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<button type="submit" class="button"><?php echo $p['approved_at'] ? esc_html__( 'Renovar aprobación', 'gestion-de-proyectos' ) : esc_html__( 'Aprobar compra', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
					<?php wp_nonce_field( 'gdp_delete_purchase_' . $id ); ?>
					<input type="hidden" name="action" value="gdp_delete_purchase">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Datos', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( $p['code'] ); ?></code> <?php echo self::badge( $p['status'], PurchaseRepository::status_labels()[ $p['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<tr><th><?php esc_html_e( 'Etapa', 'gestion-de-proyectos' ); ?></th><td><strong><?php echo esc_html( $stages[ $p['stage'] ] ?? $p['stage'] ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Partida', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $lines[ $p['budget_line'] ] ?? ( '' !== $p['budget_line'] ? $p['budget_line'] : '—' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $supplier ? $supplier['name'] : '—' ); ?></td></tr>
					<?php if ( $amounts ) : ?>
						<tr><th><?php esc_html_e( 'Neto', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::amount( $p['amount_net'], $p['currency'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Total con impuesto', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::amount( $p['amount_total'], $p['currency'] ) ); ?><?php if ( 'CLP' !== $p['currency'] && null !== $p['amount_clp'] ) : ?> · <?php echo esc_html( self::clp( $p['amount_clp'] ) ); ?><?php if ( $p['uf_rate'] ) : ?> <span class="gdp-muted">(<?php echo esc_html( sprintf( 'UF %s del %s', number_format( (float) $p['uf_rate'], 2, ',', '.' ), (string) $p['uf_date'] ) ); ?>)</span><?php endif; ?><?php endif; ?></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'Aprobación', 'gestion-de-proyectos' ); ?></th><td><?php echo $p['approved_at'] ? esc_html( sprintf( '%s · %s', ScheduleService::user_name( $p['approved_by'] ), self::date( (string) $p['approved_at'] ) ) ) : '<span class="gdp-muted">' . esc_html__( 'pendiente', 'gestion-de-proyectos' ) . '</span>'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Orden', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( trim( $p['order_number'] . ' ' . (string) $p['order_date'] ) ?: '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Factura', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( trim( $p['invoice_number'] . ' ' . (string) $p['invoice_date'] ) ?: '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Pago', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $p['paid_at'] ?: '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Entrega esperada', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $p['expected_at'] ?: '—' ); ?><?php echo $p['received_at'] ? ' · ' . esc_html( sprintf( /* translators: fecha de recepción. */ __( 'recibida el %s', 'gestion-de-proyectos' ), $p['received_at'] ) ) : ''; ?></td></tr>
					<tr><th><?php esc_html_e( 'Responsable', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( ScheduleService::user_name( $p['owner_id'] ) ); ?></td></tr>
				</table>
				<?php if ( '' !== $p['description'] ) : ?><div class="gdp-documents__body"><?php echo wp_kses_post( wpautop( $p['description'] ) ); ?></div><?php endif; ?>
				<?php if ( $check && ( ! empty( $check['warnings'] ) || ! empty( $check['conflicts'] ) ) ) : ?>
					<h3><?php esc_html_e( 'Antes de emitir la orden', 'gestion-de-proyectos' ); ?></h3>
					<ul class="gdp-list gdp-small">
						<?php foreach ( $check['conflicts'] as $c ) : ?><li class="gdp-text-danger"><?php echo esc_html( $c ); ?></li><?php endforeach; ?>
						<?php foreach ( $check['warnings'] as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Etapas', 'gestion-de-proyectos' ); ?></h2>
				<ol class="gdp-list gdp-small">
				<?php foreach ( $history as $h ) : ?>
					<li><strong><?php echo esc_html( $stages[ $h['stage'] ] ?? $h['stage'] ); ?></strong> · <?php echo esc_html( $h['stage_date'] ); ?> · <?php echo esc_html( $h['user'] ); ?><?php echo '' !== $h['note'] ? ' · ' . esc_html( $h['note'] ) : ''; ?><?php if ( $h['document_id'] > 0 ) : ?> · <a href="<?php echo esc_url( DocumentsPage::url( $project_id, array( 'view' => 'show', 'id' => $h['document_id'] ) ) ); ?>"><?php esc_html_e( 'documento', 'gestion-de-proyectos' ); ?></a><?php endif; ?></li>
				<?php endforeach; ?>
				</ol>
				<?php if ( $can_edit && 'anulada' !== $p['status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
						<?php wp_nonce_field( 'gdp_purchase_stage_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_purchase_stage">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<select name="stage" aria-label="<?php esc_attr_e( 'Etapa', 'gestion-de-proyectos' ); ?>">
							<?php foreach ( $stages as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $next_stage, $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
						</select>
						<input type="date" name="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
						<input type="text" name="number" placeholder="<?php esc_attr_e( 'N.º de orden o factura', 'gestion-de-proyectos' ); ?>">
						<select name="document_id" aria-label="<?php esc_attr_e( 'Documento', 'gestion-de-proyectos' ); ?>">
							<option value="0"><?php esc_html_e( 'Sin documento', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( $documents as $d ) : ?><option value="<?php echo (int) $d['id']; ?>"><?php echo esc_html( trim( $d['number'] . ' ' . $d['subject'] ) ); ?></option><?php endforeach; ?>
						</select>
						<input type="text" name="note" placeholder="<?php esc_attr_e( 'Nota', 'gestion-de-proyectos' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Registrar etapa', 'gestion-de-proyectos' ); ?></button>
					</form>
					<p class="gdp-muted gdp-small"><?php esc_html_e( 'Al pasar a orden de compra se comprueban la antigüedad de la cotización en unidades de fomento y el valor implícito frente al oficial; una discrepancia detiene el cambio hasta revisarla.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<h3><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
						<?php wp_nonce_field( 'gdp_save_purchase_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_save_purchase">
						<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="only_status" value="1">
						<select name="status">
							<?php foreach ( PurchaseRepository::status_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $p['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Cambiar estado', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="gdp-card gdp-card--wide">
				<h2><?php esc_html_e( 'Cotizaciones', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $quotes ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin cotizaciones registradas.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'N.º', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Solicitada', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Válida hasta', 'gestion-de-proyectos' ); ?></th><?php if ( $amounts ) : ?><th class="gdp-num"><?php esc_html_e( 'Neto', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Total', 'gestion-de-proyectos' ); ?></th><?php endif; ?><th><?php esc_html_e( 'Ítems', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $quotes as $q ) : ?>
							<tr class="<?php echo $q['id'] === $p['chosen_quote_id'] ? 'gdp-quote--chosen' : ''; ?>">
								<td><?php echo esc_html( $q['supplier'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $q['quote_number'] ); ?></td>
								<td><?php echo self::badge( 'elegida' === $q['status'] ? 'ok' : ( 'descartada' === $q['status'] ? 'cerrado' : $q['status'] ), QuoteRepository::status_labels()[ $q['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( (string) $q['requested_at'] ); ?></td>
								<td><?php echo esc_html( (string) $q['quote_date'] ); ?></td>
								<td><?php echo esc_html( (string) $q['valid_until'] ); ?></td>
								<?php if ( $amounts ) : ?>
									<td class="gdp-num"><?php echo esc_html( self::amount( $q['amount_net'], $q['currency'] ) ); ?></td>
									<td class="gdp-num"><?php echo esc_html( self::amount( $q['amount_total'], $q['currency'] ) ); ?></td>
								<?php endif; ?>
								<td><?php echo count( $q['items'] ); ?> <?php if ( $amounts && ! empty( $q['items'] ) ) : ?><span class="gdp-muted">(<?php echo count( array_filter( $q['items'], static fn( array $i ): bool => $i['selected'] ) ); ?> <?php esc_html_e( 'seleccionados', 'gestion-de-proyectos' ); ?>)</span><?php endif; ?></td>
								<td class="gdp-small">
									<?php if ( $can_edit ) : ?>
										<a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $id, 'quote' => $q['id'] ) ) ); ?>#gdp-quote-form"><?php esc_html_e( 'editar', 'gestion-de-proyectos' ); ?></a>
										<?php if ( 'elegida' !== $q['status'] && 'abierta' === $p['status'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
												<?php wp_nonce_field( 'gdp_choose_quote_' . $q['id'] ); ?>
												<input type="hidden" name="action" value="gdp_choose_quote">
												<input type="hidden" name="quote_id" value="<?php echo (int) $q['id']; ?>">
												<button type="submit" class="button-link"><?php esc_html_e( 'elegir', 'gestion-de-proyectos' ); ?></button>
											</form>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
											<?php wp_nonce_field( 'gdp_delete_quote_' . $q['id'] ); ?>
											<input type="hidden" name="action" value="gdp_delete_quote">
											<input type="hidden" name="quote_id" value="<?php echo (int) $q['id']; ?>">
											<button type="submit" class="button-link gdp-button-link-danger"><?php esc_html_e( 'quitar', 'gestion-de-proyectos' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $amounts ) : ?>
						<?php self::render_comparator( $quotes ); ?>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<?php $q = $editing_q > 0 ? QuoteRepository::find( $editing_q ) : null; ?>
					<?php $q = $q && $q['purchase_id'] === $id ? $q : null; ?>
					<h3 id="gdp-quote-form"><?php echo $q ? esc_html__( 'Editar cotización', 'gestion-de-proyectos' ) : esc_html__( 'Registrar cotización', 'gestion-de-proyectos' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-quote-form">
						<?php wp_nonce_field( 'gdp_save_quote_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_save_quote">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="quote_id" value="<?php echo $q ? (int) $q['id'] : 0; ?>">
						<div class="gdp-form-row">
							<select name="supplier_id" aria-label="<?php esc_attr_e( 'Proveedor', 'gestion-de-proyectos' ); ?>">
								<option value="0"><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></option>
								<?php foreach ( $suppliers as $s ) : ?><option value="<?php echo (int) $s['id']; ?>" <?php selected( $q ? (int) $q['supplier_id'] : 0, (int) $s['id'] ); ?>><?php echo esc_html( $s['name'] ); ?></option><?php endforeach; ?>
							</select>
							<input type="text" name="quote_number" placeholder="<?php esc_attr_e( 'N.º de cotización', 'gestion-de-proyectos' ); ?>" value="<?php echo esc_attr( $q ? (string) $q['quote_number'] : '' ); ?>">
							<select name="status" aria-label="<?php esc_attr_e( 'Estado', 'gestion-de-proyectos' ); ?>">
								<?php foreach ( QuoteRepository::status_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $q ? $q['status'] : 'recibida', $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
							</select>
							<label><?php esc_html_e( 'Solicitada', 'gestion-de-proyectos' ); ?> <input type="date" name="requested_at" value="<?php echo esc_attr( $q ? (string) $q['requested_at'] : current_time( 'Y-m-d' ) ); ?>"></label>
							<label><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?> <input type="date" name="quote_date" value="<?php echo esc_attr( $q ? (string) $q['quote_date'] : '' ); ?>"></label>
							<label><?php esc_html_e( 'Válida hasta', 'gestion-de-proyectos' ); ?> <input type="date" name="valid_until" value="<?php echo esc_attr( $q ? (string) $q['valid_until'] : '' ); ?>"></label>
						</div>
						<?php if ( $amounts ) : ?>
						<div class="gdp-form-row">
							<select name="currency" aria-label="<?php esc_attr_e( 'Moneda', 'gestion-de-proyectos' ); ?>">
								<?php foreach ( PurchaseRepository::CURRENCIES as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>" <?php selected( $q ? $q['currency'] : $p['currency'], $c ); ?>><?php echo esc_html( $c ); ?></option><?php endforeach; ?>
							</select>
							<label><?php esc_html_e( 'Neto', 'gestion-de-proyectos' ); ?> <input type="text" name="amount_net" value="<?php echo esc_attr( $q && null !== $q['amount_net'] ? rtrim( rtrim( number_format( (float) $q['amount_net'], 4, ',', '' ), '0' ), ',' ) : '' ); ?>" placeholder="<?php esc_attr_e( 'o calculado de los ítems', 'gestion-de-proyectos' ); ?>"></label>
							<label><?php esc_html_e( 'Impuesto %', 'gestion-de-proyectos' ); ?> <input type="text" name="tax_rate" class="small-text" value="<?php echo esc_attr( rtrim( rtrim( number_format( $q ? (float) $q['tax_rate'] : 19.0, 2, ',', '' ), '0' ), ',' ) ); ?>"></label>
							<select name="document_id" aria-label="<?php esc_attr_e( 'Documento', 'gestion-de-proyectos' ); ?>">
								<option value="0"><?php esc_html_e( 'Documento de la cotización', 'gestion-de-proyectos' ); ?></option>
								<?php foreach ( $documents as $d ) : ?><option value="<?php echo (int) $d['id']; ?>" <?php selected( $q ? (int) $q['document_id'] : 0, (int) $d['id'] ); ?>><?php echo esc_html( trim( $d['number'] . ' ' . $d['subject'] ) ); ?></option><?php endforeach; ?>
							</select>
						</div>
						<table class="widefat gdp-table gdp-small gdp-quote-items">
							<thead><tr><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Cantidad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Unidad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Precio unitario', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Contratar', 'gestion-de-proyectos' ); ?></th></tr></thead>
							<tbody>
							<?php $items = $q ? $q['items'] : array(); ?>
							<?php for ( $i = 0; $i < max( 6, count( $items ) + 2 ); $i++ ) : ?>
								<?php $it = $items[ $i ] ?? array( 'description' => '', 'quantity' => 1.0, 'unit' => '', 'unit_price' => 0.0, 'selected' => true ); ?>
								<tr>
									<td><input type="text" name="items[<?php echo (int) $i; ?>][description]" class="large-text" value="<?php echo esc_attr( (string) $it['description'] ); ?>"></td>
									<td><input type="text" name="items[<?php echo (int) $i; ?>][quantity]" class="small-text" value="<?php echo esc_attr( rtrim( rtrim( number_format( (float) $it['quantity'], 3, ',', '' ), '0' ), ',' ) ); ?>"></td>
									<td><input type="text" name="items[<?php echo (int) $i; ?>][unit]" class="small-text" value="<?php echo esc_attr( (string) $it['unit'] ); ?>"></td>
									<td><input type="text" name="items[<?php echo (int) $i; ?>][unit_price]" class="regular-text" value="<?php echo esc_attr( '' === (string) $it['description'] ? '' : rtrim( rtrim( number_format( (float) $it['unit_price'], 4, ',', '' ), '0' ), ',' ) ); ?>"></td>
									<td><input type="checkbox" name="items[<?php echo (int) $i; ?>][selected]" value="1" <?php checked( ! empty( $it['selected'] ) ); ?>></td>
								</tr>
							<?php endfor; ?>
							</tbody>
						</table>
						<p class="gdp-muted gdp-small"><?php esc_html_e( 'Si indica ítems, el neto se calcula sumando los marcados para contratar; así una cotización amplia puede contratarse en parte.', 'gestion-de-proyectos' ); ?></p>
						<?php endif; ?>
						<textarea name="notes" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Notas', 'gestion-de-proyectos' ); ?>"><?php echo esc_textarea( $q ? (string) $q['notes'] : '' ); ?></textarea>
						<p><button type="submit" class="button button-primary"><?php echo $q ? esc_html__( 'Guardar cotización', 'gestion-de-proyectos' ) : esc_html__( 'Registrar cotización', 'gestion-de-proyectos' ); ?></button>
						<?php if ( $q ) : ?><a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a><?php endif; ?></p>
					</form>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Vínculos', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $links ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin vínculos con documentos o actividades.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<ul class="gdp-list">
					<?php foreach ( $links as $l ) : ?>
						<li><?php echo esc_html( $l['relation_label'] . ' ' . $l['entity']['label'] ); ?> <?php if ( '' !== $l['entity']['url'] ) : ?><a href="<?php echo esc_url( $l['entity']['url'] ); ?>"><?php echo esc_html( trim( $l['entity']['code'] . ' ' . $l['entity']['title'] ) ); ?></a><?php else : ?><?php echo esc_html( trim( $l['entity']['code'] . ' ' . $l['entity']['title'] ) ); ?><?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
									<?php wp_nonce_field( 'gdp_purchase_unlink_' . $l['id'] ); ?>
									<input type="hidden" name="action" value="gdp_purchase_unlink">
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
						<?php wp_nonce_field( 'gdp_purchase_link_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_purchase_link">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<select name="relation">
							<?php foreach ( LinkRepository::relation_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( 'supports', $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
						</select>
						<select name="target" required>
							<option value=""><?php esc_html_e( 'Elija el destino', 'gestion-de-proyectos' ); ?></option>
							<?php foreach ( LinkRepository::entities() as $type => $def ) : ?>
								<?php if ( 'purchase' === $type || empty( $def['search'] ) ) : ?><?php continue; ?><?php endif; ?>
								<optgroup label="<?php echo esc_attr( ucfirst( (string) $def['label'] ) ); ?>">
									<?php foreach ( (array) call_user_func( $def['search'], $project_id, '' ) as $opt ) : ?>
										<option value="<?php echo esc_attr( $type . ':' . (int) $opt['id'] ); ?>"><?php echo esc_html( trim( $opt['code'] . ' ' . $opt['title'] ) ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Vincular', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Comparador de cotizaciones por ítem.
	 *
	 * @param array<int,array<string,mixed>> $quotes Cotizaciones.
	 * @return void
	 */
	private static function render_comparator( array $quotes ): void {
		$with_items = array_values( array_filter( $quotes, static fn( array $q ): bool => ! empty( $q['items'] ) ) );
		if ( count( $with_items ) < 2 ) {
			return;
		}
		$rows = array();
		foreach ( $with_items as $q ) {
			foreach ( $q['items'] as $it ) {
				$key = mb_strtolower( trim( $it['description'] ) );
				$rows[ $key ]['label']               = $it['description'];
				$rows[ $key ]['by'][ $q['id'] ] = $it;
			}
		}
		?>
		<h3><?php esc_html_e( 'Comparación por ítem', 'gestion-de-proyectos' ); ?></h3>
		<table class="widefat striped gdp-table gdp-small">
			<thead><tr><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><?php foreach ( $with_items as $q ) : ?><th class="gdp-num"><?php echo esc_html( $q['supplier'] ?: '#' . $q['id'] ); ?> <span class="gdp-muted">(<?php echo esc_html( $q['currency'] ); ?>)</span></th><?php endforeach; ?></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$best = null;
				foreach ( $with_items as $q ) {
					if ( isset( $row['by'][ $q['id'] ] ) && ( null === $best || $row['by'][ $q['id'] ]['unit_price'] < $best ) ) {
						$best = $row['by'][ $q['id'] ]['unit_price'];
					}
				}
				?>
				<tr>
					<td><?php echo esc_html( $row['label'] ); ?></td>
					<?php foreach ( $with_items as $q ) : ?>
						<?php $it = $row['by'][ $q['id'] ] ?? null; ?>
						<td class="gdp-num <?php echo $it && $it['unit_price'] === $best ? 'gdp-text-ok' : ''; ?>"><?php echo $it ? esc_html( number_format( $it['unit_price'], 2, ',', '.' ) . ' × ' . rtrim( rtrim( number_format( $it['quantity'], 3, ',', '.' ), '0' ), ',' ) ) : '—'; ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><?php esc_html_e( 'Neto (ítems a contratar)', 'gestion-de-proyectos' ); ?></th>
				<?php foreach ( $with_items as $q ) : ?><th class="gdp-num"><?php echo esc_html( self::amount( $q['amount_net'], $q['currency'] ) ); ?></th><?php endforeach; ?>
			</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Proveedores.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_suppliers( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'procurement.edit', $project_id );
		$id         = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$s          = $id > 0 ? SupplierRepository::find( $id ) : null;
		$s          = $s && ( 0 === $s['project_id'] || $s['project_id'] === $project_id ) ? $s : null;
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows       = SupplierRepository::for_project( $project_id, false, $search );

		self::header( $project, 'suppliers', __( 'Proveedores', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-grid">
			<div class="gdp-card gdp-card--wide">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<input type="hidden" name="view" value="suppliers">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar', 'gestion-de-proyectos' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Buscar', 'gestion-de-proyectos' ); ?></button>
				</form>
				<?php if ( empty( $rows ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin proveedores.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Identificación tributaria', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Contacto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Categoría', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ámbito', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Compras', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr class="<?php echo $r['active'] ? '' : 'gdp-muted'; ?>">
							<td><?php echo esc_html( $r['name'] ); ?><?php echo $r['active'] ? '' : ' ' . self::badge( 'cerrado', __( 'inactivo', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( $r['tax_id'] ); ?></td>
							<td><?php echo esc_html( trim( $r['contact_name'] . ' ' . $r['email'] . ' ' . $r['phone'] ) ); ?></td>
							<td><?php echo esc_html( $r['category'] ); ?></td>
							<td><?php echo 0 === $r['project_id'] ? esc_html__( 'global', 'gestion-de-proyectos' ) : esc_html__( 'del proyecto', 'gestion-de-proyectos' ); ?></td>
							<td><a href="<?php echo esc_url( self::url( $project_id, array( 'supplier_id' => $r['id'] ) ) ); ?>"><?php echo count( PurchaseRepository::for_project( $project_id, array( 'supplier_id' => $r['id'] ) ) ); ?></a></td>
							<td class="gdp-small"><?php if ( $can_edit && ( $r['project_id'] > 0 || Access::is_manager() ) ) : ?><a href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'suppliers', 'id' => $r['id'] ) ) ); ?>"><?php esc_html_e( 'editar', 'gestion-de-proyectos' ); ?></a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			<?php if ( $can_edit ) : ?>
			<div class="gdp-card">
				<h2><?php echo $s ? esc_html__( 'Editar proveedor', 'gestion-de-proyectos' ) : esc_html__( 'Nuevo proveedor', 'gestion-de-proyectos' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gdp_save_supplier_' . ( $s ? (int) $s['id'] : 0 ) ); ?>
					<input type="hidden" name="action" value="gdp_save_supplier">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<input type="hidden" name="supplier_id" value="<?php echo $s ? (int) $s['id'] : 0; ?>">
					<p><label><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?><br><input type="text" name="name" class="large-text" required value="<?php echo esc_attr( $s ? (string) $s['name'] : '' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Identificación tributaria', 'gestion-de-proyectos' ); ?><br><input type="text" name="tax_id" class="regular-text" value="<?php echo esc_attr( $s ? (string) $s['tax_id'] : '' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Contacto', 'gestion-de-proyectos' ); ?><br><input type="text" name="contact_name" class="regular-text" value="<?php echo esc_attr( $s ? (string) $s['contact_name'] : '' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Correo', 'gestion-de-proyectos' ); ?><br><input type="email" name="email" class="regular-text" value="<?php echo esc_attr( $s ? (string) $s['email'] : '' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Teléfono', 'gestion-de-proyectos' ); ?><br><input type="text" name="phone" class="regular-text" value="<?php echo esc_attr( $s ? (string) $s['phone'] : '' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Dirección', 'gestion-de-proyectos' ); ?><br><input type="text" name="address" class="large-text" value="<?php echo esc_attr( $s ? (string) $s['address'] : '' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Categoría', 'gestion-de-proyectos' ); ?><br><input type="text" name="category" class="regular-text" value="<?php echo esc_attr( $s ? (string) $s['category'] : '' ); ?>" placeholder="<?php esc_attr_e( 'laboratorio, insumos, servicios…', 'gestion-de-proyectos' ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Notas', 'gestion-de-proyectos' ); ?><br><textarea name="notes" rows="3" class="large-text"><?php echo esc_textarea( $s ? (string) $s['notes'] : '' ); ?></textarea></label></p>
					<p><label><input type="checkbox" name="active" value="1" <?php checked( ! $s || $s['active'] ); ?>> <?php esc_html_e( 'Activo', 'gestion-de-proyectos' ); ?></label>
					<?php if ( Access::is_manager() && ! $s ) : ?> <label><input type="checkbox" name="global" value="1"> <?php esc_html_e( 'Global (todos los proyectos)', 'gestion-de-proyectos' ); ?></label><?php endif; ?></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
					<?php if ( $s ) : ?><a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'suppliers' ) ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a><?php endif; ?></p>
				</form>
				<?php if ( $s ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="1">
						<?php wp_nonce_field( 'gdp_delete_supplier_' . (int) $s['id'] ); ?>
						<input type="hidden" name="action" value="gdp_delete_supplier">
						<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
						<input type="hidden" name="supplier_id" value="<?php echo (int) $s['id']; ?>">
						<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Presupuesto por partida y valores de la unidad de fomento.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_budget( array $project ): void {
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'procurement.view_amounts', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para ver montos.', 'gestion-de-proyectos' ), 403 );
		}
		$can_edit = Access::can( 'procurement.edit', $project_id );
		$summary  = BudgetService::summary( $project_id );
		$settings = ProcurementSettings::all( $project_id );
		$latest   = UfService::latest();
		$rates    = UfService::between( gmdate( 'Y-m-d', strtotime( '-30 days' ) ), '9999-12-31' );
		$fetch    = get_option( 'gdp_uf_last_fetch', array() );

		self::header( $project, 'budget', __( 'Presupuesto', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-planning-summary">
			<span><strong><?php echo esc_html( self::clp( $summary['totals']['assigned'] ) ); ?></strong> <?php esc_html_e( 'asignado', 'gestion-de-proyectos' ); ?><?php if ( null !== $summary['project_budget'] ) : ?> <span class="gdp-muted">/ <?php echo esc_html( self::clp( $summary['project_budget'] ) ); ?> <?php esc_html_e( 'del proyecto', 'gestion-de-proyectos' ); ?></span><?php endif; ?></span>
			<span><strong><?php echo esc_html( self::clp( $summary['totals']['committed'] ) ); ?></strong> <?php esc_html_e( 'comprometido', 'gestion-de-proyectos' ); ?></span>
			<span><strong><?php echo esc_html( self::clp( $summary['totals']['executed'] ) ); ?></strong> <?php esc_html_e( 'ejecutado', 'gestion-de-proyectos' ); ?></span>
			<span class="<?php echo $summary['totals']['balance'] < 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><strong><?php echo esc_html( self::clp( $summary['totals']['balance'] ) ); ?></strong> <?php esc_html_e( 'saldo', 'gestion-de-proyectos' ); ?></span>
		</div>
		<div class="gdp-grid">
			<div class="gdp-card gdp-card--wide">
				<h2><?php esc_html_e( 'Partidas', 'gestion-de-proyectos' ); ?></h2>
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Partida', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Asignado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Comprometido', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Ejecutado', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Pendiente', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Saldo', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Compras', 'gestion-de-proyectos' ); ?></th><?php if ( $can_edit ) : ?><th></th><?php endif; ?></tr></thead>
					<tbody>
					<?php foreach ( $summary['lines'] as $l ) : ?>
						<tr>
							<td><?php echo esc_html( $l['label'] ); ?><?php echo '' !== $l['notes'] ? '<br><span class="gdp-muted gdp-small">' . esc_html( $l['notes'] ) . '</span>' : ''; ?></td>
							<td class="gdp-num"><?php echo esc_html( self::clp( $l['assigned'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::clp( $l['committed'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::clp( $l['executed'] ) ); ?></td>
							<td class="gdp-num"><?php echo esc_html( self::clp( $l['pending'] ) ); ?></td>
							<td class="gdp-num <?php echo $l['balance'] < 0 ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( self::clp( $l['balance'] ) ); ?></td>
							<td class="gdp-num"><a href="<?php echo esc_url( self::url( $project_id, array( 'budget_line' => $l['code'] ) ) ); ?>"><?php echo (int) $l['purchases']; ?></a></td>
							<?php if ( $can_edit && 'sin_partida' !== $l['code'] ) : ?>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form">
										<?php wp_nonce_field( 'gdp_save_budget_' . $project_id ); ?>
										<input type="hidden" name="action" value="gdp_save_budget">
										<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
										<input type="hidden" name="budget_line" value="<?php echo esc_attr( $l['code'] ); ?>">
										<input type="text" name="assigned" class="regular-text" value="<?php echo esc_attr( number_format( $l['assigned'], 0, ',', '.' ) ); ?>" aria-label="<?php esc_attr_e( 'Asignado', 'gestion-de-proyectos' ); ?>">
										<input type="text" name="notes" value="<?php echo esc_attr( $l['notes'] ); ?>" placeholder="<?php esc_attr_e( 'Nota', 'gestion-de-proyectos' ); ?>">
										<button type="submit" class="button"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
									</form>
								</td>
							<?php elseif ( $can_edit ) : ?><td></td><?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Las partidas se definen en Catálogos (partidas presupuestarias). Comprometido: compras con orden emitida, facturadas o pagadas. Ejecutado: pagadas. Pendiente: abiertas sin orden. Montos en pesos, convertidos con el valor de la unidad de fomento de la fecha de referencia.', 'gestion-de-proyectos' ); ?></p>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Unidad de fomento', 'gestion-de-proyectos' ); ?></h2>
				<p><?php if ( $latest ) : ?><strong><?php echo esc_html( number_format( $latest['value'], 2, ',', '.' ) ); ?></strong> <?php echo esc_html( sprintf( /* translators: fecha del valor. */ __( 'pesos el %s', 'gestion-de-proyectos' ), $latest['date'] ) ); ?> <span class="gdp-muted">(<?php echo esc_html( $latest['source'] ); ?>)</span><?php else : ?><span class="gdp-text-danger"><?php esc_html_e( 'Sin valores registrados.', 'gestion-de-proyectos' ); ?></span><?php endif; ?></p>
				<?php if ( ! empty( $fetch ) && empty( $fetch['ok'] ) ) : ?><p class="gdp-muted gdp-small"><?php echo esc_html( sprintf( /* translators: 1: fecha y hora, 2: mensaje. */ __( 'Última consulta automática (%1$s): %2$s', 'gestion-de-proyectos' ), (string) $fetch['at'], (string) $fetch['message'] ) ); ?></p><?php endif; ?>
				<?php if ( $can_edit ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
						<?php wp_nonce_field( 'gdp_uf_rate' ); ?>
						<input type="hidden" name="action" value="gdp_uf_rate">
						<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
						<input type="date" name="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
						<input type="text" name="value" placeholder="<?php esc_attr_e( 'Valor en pesos (vacío = consultar la fuente)', 'gestion-de-proyectos' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Guardar u obtener', 'gestion-de-proyectos' ); ?></button>
					</form>
					<p class="gdp-muted gdp-small"><?php esc_html_e( 'Sin valor, se consulta mindicador.cl (valores del Banco Central). La tarea diaria obtiene el valor de cada día automáticamente.', 'gestion-de-proyectos' ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $rates ) ) : ?>
					<table class="widefat striped gdp-table gdp-small">
						<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th class="gdp-num"><?php esc_html_e( 'Valor', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Origen', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody><?php foreach ( array_slice( $rates, 0, 12 ) as $r ) : ?><tr><td><?php echo esc_html( $r['date'] ); ?></td><td class="gdp-num"><?php echo esc_html( number_format( $r['value'], 2, ',', '.' ) ); ?></td><td><?php echo esc_html( $r['source'] ); ?></td></tr><?php endforeach; ?></tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( Access::can( 'project.edit', $project_id ) ) : ?>
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Reglas del módulo', 'gestion-de-proyectos' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'gdp_procurement_settings_' . $project_id ); ?>
					<input type="hidden" name="action" value="gdp_procurement_settings">
					<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
					<p><label><?php esc_html_e( 'Patrón del código de compra', 'gestion-de-proyectos' ); ?><br><input type="text" name="pattern" class="regular-text" value="<?php echo esc_attr( (string) $settings['pattern'] ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Días con cotizaciones recibidas sin decidir antes de avisar', 'gestion-de-proyectos' ); ?><br><input type="number" name="decision_days" class="small-text" min="1" max="365" value="<?php echo (int) $settings['decision_days']; ?>"></label></p>
					<p><label><?php esc_html_e( 'Días sin respuesta del proveedor a una solicitud antes de avisar', 'gestion-de-proyectos' ); ?><br><input type="number" name="request_days" class="small-text" min="1" max="365" value="<?php echo (int) $settings['request_days']; ?>"></label></p>
					<p><label><?php esc_html_e( 'Antigüedad máxima de una cotización en unidades de fomento al emitir la orden (días)', 'gestion-de-proyectos' ); ?><br><input type="number" name="quote_age_days" class="small-text" min="1" max="365" value="<?php echo (int) $settings['quote_age_days']; ?>"></label></p>
					<p><label><?php esc_html_e( 'Tolerancia del valor implícito frente al oficial (%)', 'gestion-de-proyectos' ); ?><br><input type="text" name="uf_tolerance" class="small-text" value="<?php echo esc_attr( str_replace( '.', ',', (string) $settings['uf_tolerance'] ) ); ?>"></label></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Guardar reglas', 'gestion-de-proyectos' ); ?></button></p>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
		self::close();
	}

	/**
	 * Guarda una compra (crea o actualiza).
	 *
	 * @return void
	 */
	public static function handle_save_purchase(): void {
		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_save_purchase_' . $id );
		if ( ! Access::can( 'procurement.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$data = array();
		if ( ! empty( $_POST['only_status'] ) ) {
			$data['status'] = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : 'abierta';
		} else {
			foreach ( array( 'title', 'budget_line', 'currency', 'amount_net', 'tax_rate', 'expected_at', 'order_number', 'invoice_number' ) as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					$data[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
				}
			}
			foreach ( array( 'description', 'notes' ) as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					$data[ $field ] = wp_kses_post( wp_unslash( (string) $_POST[ $field ] ) );
				}
			}
			foreach ( array( 'supplier_id', 'owner_id', 'activity_id' ) as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					$data[ $field ] = (int) $_POST[ $field ];
				}
			}
			if ( ! Access::can( 'procurement.view_amounts', $project_id ) ) {
				unset( $data['amount_net'], $data['currency'], $data['tax_rate'] );
			}
		}
		if ( 0 === $id ) {
			$result = OperationManager::execute( 'purchase', 'create', array( 'data' => $data ), $project_id );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'edit', 'id' => 0 ) ), implode( ' ', $result->get_error_messages() ), 'error' );
			}
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => (int) $result['result']['purchase_id'] ) ), __( 'Compra creada.', 'gestion-de-proyectos' ) );
		}
		$expected = isset( $_POST['expected_version'] ) && '' !== $_POST['expected_version'] && empty( $_POST['only_status'] ) ? (int) $_POST['expected_version'] : null;
		$result   = OperationManager::execute( 'purchase', 'update', array( 'purchase_id' => $id, 'data' => $data, 'expected_version' => $expected ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ), implode( ' ', $result->get_error_messages() ), 'error' );
		}
		Admin::redirect_with_notice( self::url( $project_id, array( 'view' => 'show', 'id' => $id ) ), __( 'Compra actualizada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una compra.
	 *
	 * @return void
	 */
	public static function handle_delete_purchase(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_purchase_' . $id );
		$p = PurchaseRepository::find( $id );
		if ( ! $p || ! Access::can( 'procurement.edit', $p['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$result = OperationManager::execute( 'purchase', 'delete', array( 'purchase_id' => $id ), $p['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( $p['project_id'], array( 'view' => 'show', 'id' => $id ) ), $result->get_error_message(), 'error' );
		}
		/* translators: URL de la papelera. */
		Admin::redirect_with_notice( self::url( $p['project_id'] ), sprintf( __( 'Compra eliminada. Puede restaurarla desde la <a href="%s">papelera</a>.', 'gestion-de-proyectos' ), esc_url( Admin::url( 'trash', array( 'project_id' => $p['project_id'] ) ) ) ) );
	}

	/**
	 * Cambio de etapa.
	 *
	 * @return void
	 */
	public static function handle_purchase_stage(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_purchase_stage_' . $id );
		$p = PurchaseRepository::find( $id );
		if ( ! $p || ! Access::can( 'procurement.edit', $p['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$stage   = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( (string) $_POST['stage'] ) ) : '';
		$number  = isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['number'] ) ) : '';
		$payload = array(
			'purchase_id' => $id,
			'stage'       => $stage,
			'date'        => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : current_time( 'Y-m-d' ),
			'note'        => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '',
			'document_id' => isset( $_POST['document_id'] ) ? (int) $_POST['document_id'] : 0,
		);
		if ( '' !== $number && 'orden_compra' === $stage ) {
			$payload['order_number'] = $number;
		} elseif ( '' !== $number && 'factura' === $stage ) {
			$payload['invoice_number'] = $number;
		}
		$back   = self::url( $p['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute( 'purchase', 'set_stage', $payload, $p['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		$warnings = (array) ( $result['preview']['warnings'] ?? array() );
		Admin::redirect_with_notice( $back, __( 'Etapa registrada.', 'gestion-de-proyectos' ) . ( $warnings ? ' ' . implode( ' ', array_map( 'esc_html', $warnings ) ) : '' ), $warnings ? 'warning' : 'success' );
	}

	/**
	 * Aprobación.
	 *
	 * @return void
	 */
	public static function handle_approve_purchase(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_approve_purchase_' . $id );
		$p = PurchaseRepository::find( $id );
		if ( ! $p || ! Access::can( 'procurement.approve', $p['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $p['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$result = OperationManager::execute( 'purchase', 'approve', array( 'purchase_id' => $id ), $p['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Compra aprobada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Registra o actualiza una cotización con ítems.
	 *
	 * @return void
	 */
	public static function handle_save_quote(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_save_quote_' . $id );
		$p = PurchaseRepository::find( $id );
		if ( ! $p || ! Access::can( 'procurement.edit', $p['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$quote_id = isset( $_POST['quote_id'] ) ? (int) $_POST['quote_id'] : 0;
		$data     = array();
		foreach ( array( 'quote_number', 'status', 'requested_at', 'quote_date', 'valid_until', 'currency', 'amount_net', 'tax_rate' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		$data['supplier_id'] = isset( $_POST['supplier_id'] ) ? (int) $_POST['supplier_id'] : 0;
		$data['document_id'] = isset( $_POST['document_id'] ) ? (int) $_POST['document_id'] : 0;
		$data['notes']       = isset( $_POST['notes'] ) ? wp_kses_post( wp_unslash( (string) $_POST['notes'] ) ) : '';
		$items               = array();
		if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			foreach ( wp_unslash( $_POST['items'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( ! is_array( $row ) || '' === trim( (string) ( $row['description'] ?? '' ) ) ) {
					continue;
				}
				$items[] = array(
					'description' => sanitize_text_field( (string) $row['description'] ),
					'quantity'    => sanitize_text_field( (string) ( $row['quantity'] ?? '1' ) ),
					'unit'        => sanitize_text_field( (string) ( $row['unit'] ?? '' ) ),
					'unit_price'  => sanitize_text_field( (string) ( $row['unit_price'] ?? '0' ) ),
					'selected'    => ! empty( $row['selected'] ),
				);
			}
		}
		if ( ! Access::can( 'procurement.view_amounts', $p['project_id'] ) ) {
			unset( $data['amount_net'], $data['currency'], $data['tax_rate'] );
			$items = array();
		}
		$back = self::url( $p['project_id'], array( 'view' => 'show', 'id' => $id ) );
		if ( $quote_id > 0 ) {
			$result = OperationManager::execute( 'purchase', 'update_quote', array( 'quote_id' => $quote_id, 'data' => $data, 'items' => $items ), $p['project_id'] );
		} else {
			$result = OperationManager::execute( 'purchase', 'add_quote', array( 'purchase_id' => $id, 'data' => $data, 'items' => $items ), $p['project_id'] );
		}
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, implode( ' ', $result->get_error_messages() ), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Cotización guardada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina una cotización.
	 *
	 * @return void
	 */
	public static function handle_delete_quote(): void {
		$quote_id = isset( $_POST['quote_id'] ) ? (int) $_POST['quote_id'] : 0;
		check_admin_referer( 'gdp_delete_quote_' . $quote_id );
		$q = QuoteRepository::find( $quote_id );
		if ( ! $q || ! Access::can( 'procurement.edit', $q['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $q['project_id'], array( 'view' => 'show', 'id' => $q['purchase_id'] ) );
		$result = OperationManager::execute( 'purchase', 'delete_quote', array( 'quote_id' => $quote_id ), $q['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Cotización eliminada.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elige una cotización.
	 *
	 * @return void
	 */
	public static function handle_choose_quote(): void {
		$quote_id = isset( $_POST['quote_id'] ) ? (int) $_POST['quote_id'] : 0;
		check_admin_referer( 'gdp_choose_quote_' . $quote_id );
		$q = QuoteRepository::find( $quote_id );
		if ( ! $q || ! Access::can( 'procurement.edit', $q['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $q['project_id'], array( 'view' => 'show', 'id' => $q['purchase_id'] ) );
		$result = OperationManager::execute( 'purchase', 'choose_quote', array( 'quote_id' => $quote_id ), $q['project_id'] );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		$warnings = (array) ( $result['preview']['warnings'] ?? array() );
		Admin::redirect_with_notice( $back, __( 'Cotización elegida.', 'gestion-de-proyectos' ) . ( $warnings ? ' ' . implode( ' ', array_map( 'esc_html', $warnings ) ) : '' ), $warnings ? 'warning' : 'success' );
	}

	/**
	 * Ítems de cotización (reservado para formularios parciales).
	 *
	 * @return void
	 */
	public static function handle_quote_items(): void {
		self::handle_save_quote();
	}

	/**
	 * Guarda un proveedor.
	 *
	 * @return void
	 */
	public static function handle_save_supplier(): void {
		$project_id  = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$supplier_id = isset( $_POST['supplier_id'] ) ? (int) $_POST['supplier_id'] : 0;
		check_admin_referer( 'gdp_save_supplier_' . $supplier_id );
		if ( ! Access::can( 'procurement.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$data = array();
		foreach ( array( 'name', 'tax_id', 'contact_name', 'email', 'phone', 'address', 'category' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		$data['notes']  = isset( $_POST['notes'] ) ? wp_kses_post( wp_unslash( (string) $_POST['notes'] ) ) : '';
		$data['active'] = ! empty( $_POST['active'] ) ? 1 : 0;
		$back           = self::url( $project_id, array( 'view' => 'suppliers' ) );
		if ( $supplier_id > 0 ) {
			$result = OperationManager::execute( 'purchase', 'update_supplier', array( 'supplier_id' => $supplier_id, 'data' => $data ), $project_id );
		} else {
			$result = OperationManager::execute( 'purchase', 'create_supplier', array( 'data' => $data, 'global' => ! empty( $_POST['global'] ) ), $project_id );
		}
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, implode( ' ', $result->get_error_messages() ), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Proveedor guardado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina un proveedor.
	 *
	 * @return void
	 */
	public static function handle_delete_supplier(): void {
		$project_id  = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$supplier_id = isset( $_POST['supplier_id'] ) ? (int) $_POST['supplier_id'] : 0;
		check_admin_referer( 'gdp_delete_supplier_' . $supplier_id );
		if ( ! Access::can( 'procurement.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $project_id, array( 'view' => 'suppliers' ) );
		$result = OperationManager::execute( 'purchase', 'delete_supplier', array( 'supplier_id' => $supplier_id ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Proveedor eliminado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Monto asignado de una partida.
	 *
	 * @return void
	 */
	public static function handle_save_budget(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_save_budget_' . $project_id );
		if ( ! Access::can( 'procurement.edit', $project_id ) || ! Access::can( 'procurement.view_amounts', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $project_id, array( 'view' => 'budget' ) );
		$result = OperationManager::execute(
			'purchase',
			'set_budget',
			array(
				'budget_line' => isset( $_POST['budget_line'] ) ? sanitize_key( wp_unslash( (string) $_POST['budget_line'] ) ) : '',
				'assigned'    => isset( $_POST['assigned'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['assigned'] ) ) : '0',
				'notes'       => isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
			),
			$project_id
		);
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		$warnings = (array) ( $result['preview']['warnings'] ?? array() );
		Admin::redirect_with_notice( $back, __( 'Partida guardada.', 'gestion-de-proyectos' ) . ( $warnings ? ' ' . implode( ' ', array_map( 'esc_html', $warnings ) ) : '' ), $warnings ? 'warning' : 'success' );
	}

	/**
	 * Valor de la unidad de fomento (manual u obtenido de la fuente).
	 *
	 * @return void
	 */
	public static function handle_uf_rate(): void {
		check_admin_referer( 'gdp_uf_rate' );
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		if ( ! Access::can( 'procurement.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back  = self::url( $project_id, array( 'view' => 'budget' ) );
		$date  = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : current_time( 'Y-m-d' );
		$value = isset( $_POST['value'] ) ? PurchaseRepository::number( sanitize_text_field( wp_unslash( (string) $_POST['value'] ) ) ) : null;
		$result = null === $value ? UfService::fetch( $date ) : UfService::set( $date, $value, 'manual' );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Valor de la unidad de fomento guardado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Reglas del módulo por proyecto.
	 *
	 * @return void
	 */
	public static function handle_procurement_settings(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_procurement_settings_' . $project_id );
		if ( ! Access::can( 'project.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$values = array();
		foreach ( array( 'pattern', 'decision_days', 'request_days', 'quote_age_days', 'uf_tolerance' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$values[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}
		$result = ProcurementSettings::save( $project_id, $values );
		$back   = self::url( $project_id, array( 'view' => 'budget' ) );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Reglas guardadas.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Vincula una compra con otra entidad.
	 *
	 * @return void
	 */
	public static function handle_purchase_link(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_purchase_link_' . $id );
		$p = PurchaseRepository::find( $id );
		if ( ! $p || ! Access::can( 'procurement.edit', $p['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $p['project_id'], array( 'view' => 'show', 'id' => $id ) );
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target'] ) ) : '';
		$parts  = explode( ':', $target, 2 );
		$result = LinkRepository::add( $p['project_id'], 'purchase', $id, sanitize_key( $parts[0] ?? '' ), (int) ( $parts[1] ?? 0 ), isset( $_POST['relation'] ) ? sanitize_key( wp_unslash( (string) $_POST['relation'] ) ) : 'supports' );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Vínculo creado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Quita un vínculo de una compra.
	 *
	 * @return void
	 */
	public static function handle_purchase_unlink(): void {
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$link_id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;
		check_admin_referer( 'gdp_purchase_unlink_' . $link_id );
		$p = PurchaseRepository::find( $id );
		if ( ! $p || ! Access::can( 'procurement.edit', $p['project_id'] ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$link = LinkRepository::find( $link_id );
		if ( $link && $link['project_id'] === $p['project_id'] ) {
			LinkRepository::remove( $link_id );
		}
		Admin::redirect_with_notice( self::url( $p['project_id'], array( 'view' => 'show', 'id' => $id ) ), __( 'Vínculo eliminado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Exportación para la rendición.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		check_admin_referer( 'gdp_export_procurement_' . $project_id );
		$project = ProjectRepository::find( $project_id );
		if ( ! $project || ! Access::can( 'procurement.view_amounts', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'csv';
		$base   = sanitize_file_name( sprintf( 'rendicion-%s-%s', $project['code'], current_time( 'Y-m-d' ) ) );
		if ( 'xlsx' === $format ) {
			$body = ProcurementExport::xlsx( $project_id );
			if ( is_wp_error( $body ) ) {
				wp_die( esc_html( $body->get_error_message() ), 500 );
			}
			$type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			$ext  = 'xlsx';
		} else {
			$body = ProcurementExport::csv( $project_id );
			$type = 'text/csv; charset=UTF-8';
			$ext  = 'csv';
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $base . '.' . $ext . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga de archivo generado.
		exit;
	}
}
