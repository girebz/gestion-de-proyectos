<?php
/**
 * Pantalla de finanzas y rendición de cuentas: enrutador y manejadores.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Roles;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Finance\CashPlanRepository;
use GDP\Modules\Finance\FinanceExport;
use GDP\Modules\Finance\FinanceService;
use GDP\Modules\Finance\InstallmentRepository;
use GDP\Modules\Finance\RenditionRepository;
use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Pestañas: estado de cuentas, asistente (acciones y hojas de ejecución),
 * rendiciones, pagos, cuotas, convenio, ítems y reglas, caja (programación
 * y conciliación) y garantías. Las escrituras pasan por la capa de
 * operaciones; las exportaciones (planilla y ZIP de carga masiva,
 * expediente, carta de gasto cero, ficha de giro, programación) se sirven
 * desde admin-post.
 */
final class FinancePage extends Page {

	public const SLUG  = 'finance';
	public const VIEWS = array( 'status', 'assistant', 'renditions', 'payments', 'installments', 'agreement', 'items', 'cash', 'guarantees' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Slug del menú principal.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		// Solo aparece para quien puede ver las finanzas de al menos un proyecto.
		if ( ! Access::can_anywhere( 'finance.view' ) ) {
			return;
		}
		add_submenu_page( $parent, __( 'Finanzas y rendición', 'gestion-de-proyectos' ), __( 'Finanzas', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_finance_op', array( self::class, 'handle_operation' ) );
		add_action( 'admin_post_gdp_finance_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_gdp_finance_ledger', array( self::class, 'handle_ledger_import' ) );
		add_action( 'admin_post_gdp_finance_plan', array( self::class, 'handle_plan' ) );
	}

	/**
	 * Estilos y script.
	 *
	 * @param string $hook Pantalla.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, Admin::SLUG . '-' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'gdp-planning', GDP_URL . 'assets/css/planning.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_style( 'gdp-finance', GDP_URL . 'assets/css/finance.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_script( 'gdp-finance', GDP_URL . 'assets/js/finance.js', array(), GDP_VERSION, true );
		wp_localize_script( 'gdp-finance', 'gdpFinance', array( 'copied' => __( 'Copiado', 'gestion-de-proyectos' ), 'copy' => __( 'Copiar', 'gestion-de-proyectos' ) ) );
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
	 * URL de una exportación.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $what       workbook, bulk_sheet, bulk_zip, zero_letter, expedient, cash_plan, installment_sheet.
	 * @param int    $id         Identificador de la entidad.
	 * @return string
	 */
	public static function export_url( int $project_id, string $what, int $id = 0 ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=gdp_finance_export&project_id=' . $project_id . '&what=' . $what . '&id=' . $id ), 'gdp_finance_export_' . $project_id );
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
			self::open( __( 'Finanzas y rendición', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'No hay proyectos visibles.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'finance.view', $project_id ) ) {
			self::open( __( 'Finanzas y rendición', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'Sin permiso para ver las finanzas de este proyecto.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		update_user_meta( get_current_user_id(), 'gdp_planning_project', $project_id );

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'status';
		FinanceViews::render( $project, $view );
	}

	/**
	 * Tarjeta en la ficha del proyecto.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	public static function project_card( array $p ): void {
		$project_id = (int) $p['id'];
		if ( ! Access::can( 'finance.view', $project_id ) ) {
			return;
		}
		$status = FinanceService::status( $project_id );
		$fund   = $status['sources']['fondo'];
		$next   = $status['next_installment'];
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Finanzas y rendición', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Transferido', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( FinanceViews::money( $fund['received'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Pagado / rendido / aprobado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( FinanceViews::money( $fund['paid'] ) . ' / ' . FinanceViews::money( $fund['rendered'] ) . ' / ' . FinanceViews::money( $fund['approved'] ) ); ?></td></tr>
				<?php if ( $next ) : ?>
					<tr><th><?php echo esc_html( sprintf( /* translators: número de la cuota. */ __( 'Brecha para la cuota %d', 'gestion-de-proyectos' ), $next['number'] ) ); ?></th><td class="<?php echo $next['gaps']['pay_gap'] > 0 ? 'gdp-text-danger' : 'gdp-text-ok'; ?>"><?php echo esc_html( FinanceViews::money( $next['gaps']['pay_gap'] ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Rendiciones atrasadas', 'gestion-de-proyectos' ); ?></th><td class="<?php echo $status['overdue_renditions'] ? 'gdp-text-danger' : ''; ?>"><?php echo count( $status['overdue_renditions'] ); ?></td></tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Estado de cuentas', 'gestion-de-proyectos' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'assistant' ) ) ); ?>"><?php esc_html_e( 'Asistente', 'gestion-de-proyectos' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Manejador genérico: ejecuta una acción del manejador de finanzas con los campos del formulario.
	 *
	 * @return void
	 */
	public static function handle_operation(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$action     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		check_admin_referer( 'gdp_finance_op_' . $project_id );
		if ( ! Access::can( 'finance.view', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : self::url( $project_id );
		if ( 0 !== strpos( $back, admin_url() ) ) {
			$back = self::url( $project_id );
		}
		$payload = array();
		foreach ( array( 'installment_id', 'item_id', 'payment_id', 'rendition_id', 'guarantee_id', 'plan_id', 'entry_id', 'modification_id', 'supplier_id', 'entity_id', 'document_id', 'expected_version' ) as $f ) {
			if ( isset( $_POST[ $f ] ) && '' !== $_POST[ $f ] ) {
				$payload[ $f ] = (int) $_POST[ $f ];
			}
		}
		foreach ( array( 'entity_type', 'guide', 'step', 'status', 'event_key', 'rule_key', 'source', 'batch', 'date' ) as $f ) {
			if ( isset( $_POST[ $f ] ) ) {
				$payload[ $f ] = sanitize_text_field( wp_unslash( (string) $_POST[ $f ] ) );
			}
		}
		if ( isset( $_POST['note'] ) ) {
			$payload['note'] = sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) );
		}
		if ( isset( $_POST['registered'] ) ) {
			$payload['registered'] = '1' === (string) $_POST['registered'];
		}
		if ( isset( $_POST['data'] ) && is_array( $_POST['data'] ) ) {
			$payload['data'] = self::sanitize_data( wp_unslash( $_POST['data'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se limpia campo a campo.
		}
		if ( isset( $_POST['support'] ) && is_array( $_POST['support'] ) ) {
			$support = array();
			foreach ( wp_unslash( $_POST['support'] ) as $s ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se limpia campo a campo.
				if ( is_array( $s ) && ! empty( $s['kind'] ) ) {
					$support[] = array( 'kind' => sanitize_key( (string) $s['kind'] ), 'document_id' => (int) ( $s['document_id'] ?? 0 ), 'note' => sanitize_text_field( (string) ( $s['note'] ?? '' ) ) );
				}
			}
			$payload['data']['support'] = $support;
		}
		if ( isset( $_POST['details'] ) && is_array( $_POST['details'] ) ) {
			$details = array();
			foreach ( wp_unslash( $_POST['details'] ) as $d ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se limpia campo a campo.
				if ( is_array( $d ) && ! empty( $d['item'] ) ) {
					$details[] = array( 'item' => sanitize_key( (string) $d['item'] ), 'source' => sanitize_key( (string) ( $d['source'] ?? 'fondo' ) ), 'delta' => sanitize_text_field( (string) ( $d['delta'] ?? '0' ) ), 'note' => sanitize_text_field( (string) ( $d['note'] ?? '' ) ) );
				}
			}
			$payload['data']['details'] = $details;
		}
		$result = OperationManager::execute( 'finance', $action, $payload, $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, implode( ' ', $result->get_error_messages() ), 'error' );
		}
		$warnings = isset( $result['preview']['warnings'] ) && is_array( $result['preview']['warnings'] ) ? $result['preview']['warnings'] : array();
		$message  = __( 'Cambio aplicado.', 'gestion-de-proyectos' );
		if ( 'create_payment' === $action && ! empty( $result['result']['payment_id'] ) ) {
			$back = self::url( $project_id, array( 'view' => 'payments', 'id' => (int) $result['result']['payment_id'] ) );
		}
		if ( 'create_rendition' === $action && ! empty( $result['result']['rendition_id'] ) ) {
			$back = self::url( $project_id, array( 'view' => 'renditions', 'id' => (int) $result['result']['rendition_id'] ) );
		}
		if ( 'create_installment' === $action && ! empty( $result['result']['installment_id'] ) ) {
			$back = self::url( $project_id, array( 'view' => 'installments' ) );
		}
		Admin::redirect_with_notice( $back, $warnings ? $message . ' ' . __( 'Advertencias:', 'gestion-de-proyectos' ) . ' ' . implode( ' ', array_slice( $warnings, 0, 6 ) ) : $message, $warnings ? 'warning' : 'success' );
	}

	/**
	 * Limpia los campos data[] del formulario (la validación fina la hacen los repositorios).
	 *
	 * @param array<string,mixed> $data Datos crudos.
	 * @return array<string,mixed>
	 */
	private static function sanitize_data( array $data ): array {
		$out = array();
		foreach ( $data as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( is_array( $v ) ) {
				$out[ $k ] = self::sanitize_data( $v );
			} else {
				$out[ $k ] = in_array( $k, array( 'notes', 'note', 'observation', 'description' ), true ) ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
			}
		}

		return $out;
	}

	/**
	 * Importación de movimientos del centro de costo pegados como texto (fecha;referencia;glosa;cargo;abono).
	 *
	 * @return void
	 */
	public static function handle_ledger_import(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_finance_ledger_' . $project_id );
		if ( ! Access::can( 'finance.reconcile', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back   = self::url( $project_id, array( 'view' => 'cash', 'tab' => 'ledger' ) );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( (string) $_POST['source'] ) ) : 'fondo';
		$text   = isset( $_POST['rows'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['rows'] ) ) : '';
		$rows   = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = str_getcsv( $line, false !== strpos( $line, ';' ) ? ';' : ( false !== strpos( $line, "\t" ) ? "\t" : ',' ), '"', '\\' );
			if ( count( $parts ) < 4 ) {
				continue;
			}
			$date = \GDP\Modules\Finance\Repository::date( self::normalize_date( (string) $parts[0] ) );
			if ( ! $date ) {
				continue;
			}
			$rows[] = array( 'entry_date' => $date, 'reference' => (string) $parts[1], 'description' => (string) $parts[2], 'debit' => (string) $parts[3], 'credit' => (string) ( $parts[4] ?? '0' ) );
		}
		if ( empty( $rows ) ) {
			Admin::redirect_with_notice( $back, __( 'No se reconoció ninguna fila: use fecha;referencia;glosa;cargo;abono, con la fecha como AAAA-MM-DD o DD/MM/AAAA.', 'gestion-de-proyectos' ), 'error' );
		}
		$result = OperationManager::execute( 'finance', 'import_ledger', array( 'source' => $source, 'rows' => $rows, 'batch' => current_time( 'Ymd-His' ) ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		$r = $result['result'];
		Admin::redirect_with_notice( $back, sprintf( /* translators: 1: movimientos importados, 2: emparejados. */ _n( 'Importado %1$d movimiento; %2$d emparejado automáticamente.', 'Importados %1$d movimientos; %2$d emparejados automáticamente.', (int) $r['imported'], 'gestion-de-proyectos' ), (int) $r['imported'], (int) $r['matched'] ) );
	}

	/**
	 * Guarda la programación de caja desde la grilla del formulario.
	 *
	 * @return void
	 */
	public static function handle_plan(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_finance_plan_' . $project_id );
		if ( ! Access::can( 'finance.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$back    = self::url( $project_id, array( 'view' => 'cash' ) );
		$plan_id = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
		$rows    = array();
		$periods = isset( $_POST['period'] ) && is_array( $_POST['period'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['period'] ) ) : array();
		foreach ( $periods as $i => $period ) {
			$period = trim( (string) $period );
			if ( '' === $period ) {
				continue;
			}
			$rows[] = array(
				'period'    => $period,
				'transfer'  => isset( $_POST['transfer'][ $i ] ) ? sanitize_text_field( wp_unslash( (string) $_POST['transfer'][ $i ] ) ) : '0',
				'spend'     => isset( $_POST['spend'][ $i ] ) ? sanitize_text_field( wp_unslash( (string) $_POST['spend'][ $i ] ) ) : '0',
				'cash'      => isset( $_POST['cash'][ $i ] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cash'][ $i ] ) ) : '0',
				'milestone' => isset( $_POST['milestone'][ $i ] ) ? sanitize_text_field( wp_unslash( (string) $_POST['milestone'][ $i ] ) ) : '',
			);
		}
		$data = array(
			'name'         => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
			'status'       => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : 'borrador',
			'submitted_at' => isset( $_POST['submitted_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['submitted_at'] ) ) : '',
			'notes'        => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
		);
		$result = OperationManager::execute( 'finance', 'save_cash_plan', array( 'plan_id' => $plan_id, 'data' => $data, 'rows' => $rows ), $project_id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, implode( ' ', $result->get_error_messages() ), 'error' );
		}
		$warnings = $result['preview']['warnings'] ?? array();
		Admin::redirect_with_notice( $back, $warnings ? __( 'Programación guardada. Controles que fallan:', 'gestion-de-proyectos' ) . ' ' . implode( ' ', $warnings ) : __( 'Programación guardada; los seis controles se cumplen o no son evaluables.', 'gestion-de-proyectos' ), $warnings ? 'warning' : 'success' );
	}

	/**
	 * Exportaciones.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		check_admin_referer( 'gdp_finance_export_' . $project_id );
		$project = ProjectRepository::find( $project_id );
		if ( ! $project || ! Access::can( 'finance.view', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( (string) $_GET['what'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( in_array( $what, array( 'bulk_sheet', 'bulk_zip', 'cash_plan', 'workbook' ), true ) && ! Access::can( 'finance.export', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso para exportar.', 'gestion-de-proyectos' ), 403 );
		}
		$rendition = in_array( $what, array( 'bulk_sheet', 'bulk_zip', 'zero_letter', 'expedient' ), true ) ? RenditionRepository::find( $id ) : null;
		if ( null !== $rendition && ( ! $rendition || $rendition['project_id'] !== $project_id ) ) {
			wp_die( esc_html__( 'La rendición no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
		}
		nocache_headers();
		switch ( $what ) {
			case 'workbook':
				self::send( FinanceExport::workbook( $project_id ) );
				break;
			case 'bulk_sheet':
				$file = FinanceExport::bulk_sheet( $rendition );
				self::send( $file );
				break;
			case 'bulk_zip':
				$zip = FinanceExport::bulk_zip( $rendition );
				if ( is_wp_error( $zip ) ) {
					wp_die( esc_html( $zip->get_error_message() ), 500 );
				}
				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $zip['filename'] ) . '"' );
				header( 'Content-Length: ' . (string) filesize( $zip['path'] ) );
				readfile( $zip['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- descarga de archivo generado.
				wp_delete_file( $zip['path'] );
				exit;
			case 'cash_plan':
				$plan = CashPlanRepository::find( $id );
				if ( ! $plan || $plan['project_id'] !== $project_id ) {
					wp_die( esc_html__( 'La programación no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
				}
				self::send( FinanceExport::cash_plan( $id ) );
				break;
			case 'expedient':
				header( 'Content-Type: text/html; charset=utf-8' );
				echo FinanceExport::expedient( $rendition ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
				exit;
			case 'zero_letter':
				header( 'Content-Type: text/html; charset=utf-8' );
				echo FinanceExport::zero_letter( $rendition ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
				exit;
			case 'installment_sheet':
				$installment = InstallmentRepository::find( $id );
				if ( ! $installment || $installment['project_id'] !== $project_id ) {
					wp_die( esc_html__( 'La cuota no existe en este proyecto.', 'gestion-de-proyectos' ), 404 );
				}
				$html = FinanceExport::installment_sheet( $id );
				if ( is_wp_error( $html ) ) {
					wp_die( esc_html( $html->get_error_message() ), 500 );
				}
				header( 'Content-Type: text/html; charset=utf-8' );
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado con escape interno.
				exit;
		}
		wp_die( esc_html__( 'Exportación desconocida.', 'gestion-de-proyectos' ), 400 );
	}

	/**
	 * Envía un archivo generado.
	 *
	 * @param array{content:string,filename:string,mime:string}|\WP_Error $file Archivo.
	 * @return void
	 */
	private static function send( $file ): void {
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ), 500 );
		}
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['filename'] ) . '"' );
		header( 'Content-Length: ' . strlen( $file['content'] ) );
		echo $file['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga de archivo generado.
		exit;
	}

	/**
	 * Convierte DD/MM/AAAA en AAAA-MM-DD.
	 *
	 * @param string $value Fecha.
	 * @return string
	 */
	private static function normalize_date( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $value, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
		}

		return substr( $value, 0, 10 );
	}
}
