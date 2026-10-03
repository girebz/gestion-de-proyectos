<?php
/**
 * Exportaciones del módulo de finanzas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Core\Access;
use GDP\Core\Spreadsheet;
use GDP\Core\Workbook;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Finance\Board\BoardData;
use GDP\Modules\Finance\Logic\BulkLoad;
use GDP\Modules\Finance\Logic\WorkbookSheets;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Modules\Planning\ScheduleService;
use GDP\Modules\Procurement\SupplierRepository;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Libro Excel con todo el estado financiero, planilla y ZIP de carga
 * masiva, programación de caja en el formato de la Dirección de
 * Investigación, expediente de una rendición, carta y carátula de gasto cero
 * y ficha de giro de una cuota. Las vistas imprimibles se generan como HTML
 * para guardarlas en PDF desde el navegador.
 */
final class FinanceExport {

	/**
	 * Columnas de la planilla de carga (clave => encabezado), con el filtro del perfil.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,string>
	 */
	public static function bulk_columns( int $project_id ): array {
		/**
		 * Permite sustituir las columnas de la Planilla de Carga por las del formato oficial.
		 *
		 * @param array<string,string> $columns    Columnas por omisión.
		 * @param int                  $project_id Proyecto.
		 */
		return (array) apply_filters( 'gdp_finance_bulk_columns', BulkLoad::default_columns(), $project_id );
	}

	/**
	 * Pagos de una rendición en la forma de la carga masiva.
	 *
	 * @param array<string,mixed> $rendition Rendición.
	 * @return array<int,array<string,mixed>>
	 */
	public static function bulk_payments( array $rendition ): array {
		$payments  = PaymentRepository::list( (int) $rendition['project_id'], array( 'rendition_id' => (int) $rendition['id'] ) );
		$suppliers = array();
		foreach ( $payments as $p ) {
			if ( $p['supplier_id'] > 0 && ! isset( $suppliers[ $p['supplier_id'] ] ) ) {
				$s = SupplierRepository::find( (int) $p['supplier_id'] );
				if ( $s ) {
					$suppliers[ $p['supplier_id'] ] = $s;
				}
			}
		}
		usort( $payments, static fn( array $a, array $b ): int => array( $a['folio'] > 0 ? 0 : 1, $a['folio'], $a['id'] ) <=> array( $b['folio'] > 0 ? 0 : 1, $b['folio'], $b['id'] ) );

		return Assistant::bulk_payments( $payments, $suppliers, ItemRepository::by_slug( (int) $rendition['project_id'] ) );
	}

	/**
	 * Planilla de carga en XLSX (o CSV si no hay extensión zip).
	 *
	 * @param array<string,mixed> $rendition Rendición.
	 * @return array{content:string,filename:string,mime:string}|WP_Error
	 */
	public static function bulk_sheet( array $rendition ) {
		$columns = self::bulk_columns( (int) $rendition['project_id'] );
		$rows    = array( array_values( $columns ) );
		foreach ( BulkLoad::rows( self::bulk_payments( $rendition ) ) as $r ) {
			$line = array();
			foreach ( array_keys( $columns ) as $key ) {
				$line[] = $r[ $key ] ?? '';
			}
			$rows[] = $line;
		}
		$base = 'planilla-carga-' . $rendition['period'];
		if ( Spreadsheet::available() ) {
			$content = Spreadsheet::write( array( 'Planilla de Carga' => $rows ) );
			if ( is_wp_error( $content ) ) {
				return $content;
			}

			return array( 'content' => $content, 'filename' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		}

		return array( 'content' => self::csv( $rows ), 'filename' => $base . '.csv', 'mime' => 'text/csv; charset=utf-8' );
	}

	/**
	 * ZIP de respaldos: una carpeta por folio con CE y T.
	 *
	 * @param array<string,mixed> $rendition Rendición.
	 * @return array{path:string,filename:string,problems:string[]}|WP_Error
	 */
	public static function bulk_zip( array $rendition ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP.', 'gestion-de-proyectos' ) );
		}
		$payments = self::bulk_payments( $rendition );
		$folders  = BulkLoad::folders( $payments );
		$name     = strtoupper( Assistant::month_abbr( (string) $rendition['period'] ) ) . '-' . substr( (string) $rendition['period'], 0, 4 );
		$tmp      = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdp-carga' ) : (string) tempnam( get_temp_dir(), 'gdp-carga' );
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', __( 'No se pudo crear el archivo ZIP.', 'gestion-de-proyectos' ) );
		}
		foreach ( $folders as $folio => $dirs ) {
			$zip->addEmptyDir( $folio . '/CE' );
			$zip->addEmptyDir( $folio . '/T' );
			foreach ( $dirs['CE'] as $file ) {
				$zip->addFile( $file, $folio . '/CE/' . basename( $file ) );
			}
			foreach ( $dirs['T'] as $index => $file ) {
				$zip->addFile( $file, $folio . '/T/' . ( $index + 1 ) . '-' . basename( $file ) );
			}
		}
		$zip->close();

		return array( 'path' => $tmp, 'filename' => $name . '.zip', 'problems' => BulkLoad::problems( $payments, InstallmentRepository::amounts( (int) $rendition['project_id'] ) ) );
	}

	/**
	 * Libro Excel con todo el estado financiero del proyecto: una hoja por
	 * materia, con montos, fechas y porcentajes como valores numéricos y los
	 * totales, disponibles y acumulados como fórmulas.
	 *
	 * @param int $project_id Proyecto.
	 * @return array{content:string,filename:string,mime:string}|WP_Error
	 */
	public static function workbook( int $project_id ) {
		if ( ! Workbook::available() ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para generar el libro Excel.', 'gestion-de-proyectos' ) );
		}
		$project = ProjectRepository::find( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ) );
		}
		$data    = self::workbook_data( $project );
		$line    = trim( (string) $project['code'] . ' ' . (string) $project['name'] );
		$content = Workbook::write(
			WorkbookSheets::build( $data ),
			array(
				/* translators: proyecto. */
				'title'   => sprintf( __( 'Estado financiero de %s', 'gestion-de-proyectos' ), $line ),
				/* translators: fecha. */
				'subject' => sprintf( __( 'Estado al %s', 'gestion-de-proyectos' ), (string) $data['today'] ),
				'creator' => 'Gestión de Proyectos',
			)
		);
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$code = sanitize_title( '' !== (string) $project['code'] ? (string) $project['code'] : (string) $project['name'] );

		return array(
			'content'  => $content,
			'filename' => 'estado-financiero-' . ( '' !== $code ? $code . '-' : '' ) . (string) $data['today'] . '.xlsx',
			'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);
	}

	/**
	 * Datos del libro: los del tablero más los estados y pasos con su autor,
	 * la cartola, los hallazgos de cada pago y el registro de los proveedores
	 * en la plataforma.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return array<string,mixed>
	 */
	public static function workbook_data( array $project ): array {
		$project_id = (int) $project['id'];
		$data       = BoardData::build( $project );
		$status     = (array) $data['status'];

		$issues = array();
		foreach ( (array) $data['payments'] as $p ) {
			$issues[ (int) $p['id'] ] = FinanceService::validate_payment( $p, $status );
		}
		$registered = array();
		foreach ( (array) $data['suppliers'] as $id => $s ) {
			$registered[ (int) $id ] = is_array( $s ) && FinanceService::supplier_registered( $s );
		}
		$users  = array();
		$events = array();
		foreach ( EventRepository::for_project( $project_id, 'event_date ASC, id ASC' ) as $e ) {
			$uid = (int) $e['user_id'];
			if ( ! isset( $users[ $uid ] ) ) {
				$users[ $uid ] = ScheduleService::user_name( $uid );
			}
			$e['user'] = $users[ $uid ];
			$events[]  = $e;
		}

		$data['issues']     = $issues;
		$data['registered'] = $registered;
		$data['events']     = $events;
		$data['ledger']     = LedgerRepository::all( $project_id );
		$data['docs']       = Access::can( 'documents.view', $project_id );
		$data['labels']     = array(
			'ledger'        => LedgerRepository::labels(),
			'guarantees'    => GuaranteeRepository::labels(),
			'modifications' => ModificationRepository::labels(),
		);

		return $data;
	}

	/**
	 * Programación de caja en el formato de la Dirección de Investigación, con una hoja de controles.
	 *
	 * @param int $plan_id Plan.
	 * @return array{content:string,filename:string,mime:string}|WP_Error
	 */
	public static function cash_plan( int $plan_id ) {
		$plan = CashPlanRepository::find( $plan_id );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'La programación no existe.', 'gestion-de-proyectos' ) );
		}
		$status  = FinanceService::status( (int) $plan['project_id'] );
		$project = ProjectRepository::find( (int) $plan['project_id'] );
		$rows    = array( array( 'Mes', 'Transferencia solicitada al Gobierno Regional', 'Gasto programado', 'Aporte pecuniario a enterar', 'Hito que lo respalda', 'Gasto acumulado', 'Caja del Fondo al cierre' ) );
		$plan_rows = CashPlanRepository::rows( $plan_id );
		$base      = FinanceService::plan_baseline( (int) $plan['project_id'], $plan_rows );
		$cum       = $base['spent'];
		$cash      = $base['transferred'] - $base['spent'];
		$sum_t   = 0.0;
		$sum_s   = 0.0;
		$sum_c   = 0.0;
		foreach ( $plan_rows as $r ) {
			$cum   += $r['spend'];
			$cash  += $r['transfer'] - $r['spend'];
			$sum_t += $r['transfer'];
			$sum_s += $r['spend'];
			$sum_c += $r['cash'];
			$rows[] = array( Assistant::month_label( $r['period'] ), $r['transfer'], $r['spend'], $r['cash'], $r['milestone'], round( $cum, 2 ), round( $cash, 2 ) );
		}
		$rows[] = array( 'Total', $sum_t, $sum_s, $sum_c, '', '', '' );
		$first  = '' !== $base['first'] ? Assistant::month_label( $base['first'] ) : '';
		$rows[] = array( 'Saldo por transferir al inicio de ' . $first, round( (float) $status['agreement']['fund_amount'] - $base['transferred'], 2 ), '', '', '', '', '' );
		$rows[] = array( 'Saldo no ejecutado más saldo por transferir al inicio de ' . $first, round( (float) $status['agreement']['fund_amount'] - $base['spent'], 2 ), '', '', '', '', '' );
		$rows[] = array( 'Pagado con cargo al Fondo antes de ' . $first, $base['spent'], '', '', '', '', '' );
		$checks = array( array( 'Control', 'Descripción', 'Resultado', 'Detalle' ) );
		$rule   = static fn( string $key, string $fallback = '' ): string => (string) ( $status['rules'][ $key ]['value'] ?? $fallback );
		foreach ( FinanceService::plan_checks( (int) $plan['project_id'], $plan_rows, $status['agreement'], Calendar::for_project( (int) $plan['project_id'] ), $rule ) as $c ) {
			$checks[] = array( $c['key'], $c['label'], null === $c['ok'] ? 'no evaluable' : ( $c['ok'] ? 'cumple' : 'falla' ), $c['detail'] );
		}
		$header = array( array( 'Proyecto', $project ? $project['name'] : '' ), array( 'Código', (string) $status['agreement']['platform_code'] ), array( 'Programación', $plan['name'] ), array( 'Estado', $plan['status'] ), array( 'Fecha', $status['today'] ) );
		$base   = 'programacion-caja-' . sanitize_title( (string) $plan['name'] );
		if ( Spreadsheet::available() ) {
			$content = Spreadsheet::write( array( 'Programación' => array_merge( $header, array( array( '' ) ), $rows ), 'Controles' => $checks ) );
			if ( is_wp_error( $content ) ) {
				return $content;
			}

			return array( 'content' => $content, 'filename' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		}

		return array( 'content' => self::csv( array_merge( $header, $rows, array( array( '' ) ), $checks ) ), 'filename' => $base . '.csv', 'mime' => 'text/csv; charset=utf-8' );
	}

	/**
	 * Expediente imprimible de una rendición: carátula, detalle, respaldos y estados.
	 *
	 * @param array<string,mixed> $rendition Rendición.
	 * @return string HTML completo.
	 */
	public static function expedient( array $rendition ): string {
		$project_id = (int) $rendition['project_id'];
		$project    = ProjectRepository::find( $project_id );
		$status     = FinanceService::status( $project_id );
		$profile    = Profiles::get( (string) $status['agreement']['profile'] );
		$payments   = PaymentRepository::list( $project_id, array( 'rendition_id' => (int) $rendition['id'] ) );
		$items      = ItemRepository::by_slug( $project_id );
		$by_item    = array();
		$total      = 0.0;
		foreach ( $payments as $p ) {
			$by_item[ $p['item_slug'] ] = (float) ( $by_item[ $p['item_slug'] ] ?? 0 ) + (float) $p['amount'];
			$total                     += (float) $p['amount'];
		}
		$statuses = $profile->rendition_statuses();
		ob_start();
		self::print_head( sprintf( '%s · Rendición %s', $project ? $project['code'] : '', $rendition['period'] ) );
		?>
		<h1><?php echo esc_html( sprintf( /* translators: 1: mes, 2: fuente. */ __( 'Rendición de cuentas %1$s · %2$s', 'gestion-de-proyectos' ), Assistant::month_label( (string) $rendition['period'] ), $profile->sources()[ $rendition['source'] ] ?? $rendition['source'] ) ); ?></h1>
		<table class="facts">
			<tr><th><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $project ? $project['name'] : '' ); ?></td><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $status['agreement']['platform_code'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Otorgante', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $status['agreement']['funder'] ); ?></td><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $rendition['kind'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $statuses[ $rendition['status'] ]['label'] ?? $rendition['status'] ); ?></td><th><?php esc_html_e( 'Plazos', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: plazo interno, 2: plazo de la plataforma. */ __( 'interno %1$s · plataforma %2$s', 'gestion-de-proyectos' ), (string) $rendition['internal_due'], (string) $rendition['platform_due'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Total rendido', 'gestion-de-proyectos' ); ?></th><td><strong><?php echo esc_html( Assistant::money( $total ) ); ?></strong></td><th><?php esc_html_e( 'Transacciones', 'gestion-de-proyectos' ); ?></th><td><?php echo count( $payments ); ?></td></tr>
		</table>
		<h2><?php esc_html_e( 'Carátula: gasto del período por ítem y saldo disponible', 'gestion-de-proyectos' ); ?></h2>
		<table class="grid">
			<thead><tr><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th class="num"><?php esc_html_e( 'Asignado', 'gestion-de-proyectos' ); ?></th><th class="num"><?php esc_html_e( 'Rendido en el período', 'gestion-de-proyectos' ); ?></th><th class="num"><?php esc_html_e( 'Pagado acumulado', 'gestion-de-proyectos' ); ?></th><th class="num"><?php esc_html_e( 'Disponible', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $status['items'] as $i ) : ?>
				<?php $src = $i['sources'][ $rendition['source'] ] ?? $i['sources']['fondo']; ?>
				<tr><td><?php echo esc_html( $i['label'] ); ?></td><td class="num"><?php echo esc_html( Assistant::money( $src['assigned'] ) ); ?></td><td class="num"><?php echo esc_html( Assistant::money( (float) ( $by_item[ $i['slug'] ] ?? 0 ) ) ); ?></td><td class="num"><?php echo esc_html( Assistant::money( $src['paid'] ) ); ?></td><td class="num"><?php echo esc_html( Assistant::money( $src['available'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h2><?php esc_html_e( 'Detalle de transacciones', 'gestion-de-proyectos' ); ?></h2>
		<table class="grid">
			<thead><tr><th>#</th><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Egreso', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Proveedor', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Documento', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Ítem', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Cuota', 'gestion-de-proyectos' ); ?></th><th class="num"><?php esc_html_e( 'Monto', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Respaldos', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php $effective = PaymentRepository::effective_installments( $project_id ); ?>
			<?php foreach ( $payments as $n => $p ) : ?>
				<?php $supplier = $p['supplier_id'] > 0 ? SupplierRepository::find( (int) $p['supplier_id'] ) : null; ?>
				<?php $kinds = array_map( static fn( array $s ): string => $profile->support_kinds()[ $s['kind'] ] ?? $s['kind'], $p['support'] ); ?>
				<tr>
					<td><?php echo (int) ( $p['folio'] > 0 ? $p['folio'] : $n + 1 ); ?></td>
					<td><?php echo esc_html( $p['code'] ); ?></td>
					<td><?php echo esc_html( $p['egress_number'] . ' ' . BulkLoad::date( (string) $p['paid_at'] ) ); ?></td>
					<td><?php echo esc_html( $supplier ? $supplier['name'] : '' ); ?></td>
					<td><?php echo esc_html( ( $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'] ) . ' ' . $p['doc_number'] . ' ' . BulkLoad::date( (string) $p['doc_date'] ) ); ?></td>
					<td><?php echo esc_html( $items[ $p['item_slug'] ]['label'] ?? $p['item_slug'] ); ?></td>
					<td><?php echo (int) ( $effective[ $p['id'] ] ?? $p['installment_no'] ); ?></td>
					<td class="num"><?php echo esc_html( Assistant::money( (float) $p['amount'] ) ); ?></td>
					<td><?php echo esc_html( $profile->payment_statuses()[ $p['status'] ] ?? $p['status'] ); ?><?php echo $p['observation'] ? '<br><small>' . esc_html( $p['observation'] ) . '</small>' : ''; ?></td>
					<td><small><?php echo esc_html( implode( ', ', $kinds ) ); ?></small></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h2><?php esc_html_e( 'Estados declarados', 'gestion-de-proyectos' ); ?></h2>
		<table class="grid">
			<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Quién', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( EventRepository::for_entity( 'rendition', (int) $rendition['id'], 'estado' ) as $e ) : ?>
				<tr><td><?php echo esc_html( (string) $e['event_date'] ); ?></td><td><?php echo esc_html( $statuses[ $e['event_key'] ]['label'] ?? $e['event_key'] ); ?></td><td><?php echo esc_html( $e['user'] ); ?></td><td><?php echo esc_html( (string) $e['note'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="foot"><?php echo esc_html( sprintf( /* translators: fecha y hora. */ __( 'Generado por Gestión de Proyectos el %s.', 'gestion-de-proyectos' ), $status['today'] ) ); ?></p>
		<?php
		self::print_foot();

		return (string) ob_get_clean();
	}

	/**
	 * Carta conductora y carátula de gasto cero de un período.
	 *
	 * @param array<string,mixed> $rendition Rendición.
	 * @return string HTML completo.
	 */
	public static function zero_letter( array $rendition ): string {
		$project_id = (int) $rendition['project_id'];
		$project    = ProjectRepository::find( $project_id );
		$status     = FinanceService::status( $project_id );
		$month      = Assistant::month_label( (string) $rendition['period'] );
		ob_start();
		self::print_head( sprintf( '%s · Gasto cero %s', $project ? $project['code'] : '', $rendition['period'] ) );
		?>
		<?php $city = trim( (string) ( $status['rules']['ciudad_cartas']['value'] ?? '' ) ); ?>
		<p class="right"><?php echo esc_html( '' !== $city ? $city . ', ' . BulkLoad::date( $status['today'] ) : BulkLoad::date( $status['today'] ) ); ?></p>
		<p><strong><?php esc_html_e( 'Señores', 'gestion-de-proyectos' ); ?><br><?php echo esc_html( (string) $status['agreement']['funder'] ); ?><br><?php esc_html_e( 'Presente', 'gestion-de-proyectos' ); ?></strong></p>
		<p><strong><?php echo esc_html( sprintf( /* translators: 1: mes, 2: nombre del proyecto, 3: código del proyecto en la plataforma. */ __( 'Ref.: rendición de cuentas sin movimiento, período %1$s, proyecto "%2$s" (%3$s).', 'gestion-de-proyectos' ), $month, $project ? $project['name'] : '', (string) $status['agreement']['platform_code'] ) ); ?></strong></p>
		<p><?php echo esc_html( sprintf( /* translators: 1: acto que aprueba el convenio, 2: mes. */ __( 'De nuestra consideración: en cumplimiento de las bases del concurso y del convenio de transferencia aprobado por %1$s, informamos que durante %2$s el proyecto no registró egresos con cargo a los recursos transferidos, por lo que se presenta la rendición de cuentas del período con gasto cero.', 'gestion-de-proyectos' ), (string) $status['agreement']['approval_act'], $month ) ); ?></p>
		<p><?php esc_html_e( 'Justificación técnica de la inactividad del período:', 'gestion-de-proyectos' ); ?> <?php echo esc_html( '' !== (string) $rendition['notes'] ? (string) $rendition['notes'] : __( '[complete la justificación en las notas de la rendición]', 'gestion-de-proyectos' ) ); ?></p>
		<p><?php esc_html_e( 'Sin otro particular, saluda atentamente,', 'gestion-de-proyectos' ); ?></p>
		<p class="sign"><?php esc_html_e( 'Director del proyecto', 'gestion-de-proyectos' ); ?><br><?php echo esc_html( (string) ( $project['executing_entity'] ?? '' ) ); ?></p>
		<div class="break"></div>
		<h1><?php esc_html_e( 'Carátula de rendición de cuentas', 'gestion-de-proyectos' ); ?></h1>
		<table class="facts">
			<tr><th><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $project ? $project['name'] : '' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( (string) $status['agreement']['platform_code'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Período', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $month ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Transferido a la fecha', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( Assistant::money( $status['sources']['fondo']['received'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Rendido acumulado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( Assistant::money( $status['sources']['fondo']['rendered'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Gasto del período', 'gestion-de-proyectos' ); ?></th><td><strong>$0</strong></td></tr>
			<tr><th><?php esc_html_e( 'Saldo disponible', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( Assistant::money( $status['sources']['fondo']['balance'] ) ); ?></td></tr>
		</table>
		<?php
		self::print_foot();

		return (string) ob_get_clean();
	}

	/**
	 * Ficha de giro de una cuota: condiciones con su evidencia.
	 *
	 * @param int $installment_id Cuota.
	 * @return string|WP_Error HTML completo.
	 */
	public static function installment_sheet( int $installment_id ) {
		$installment = InstallmentRepository::find( $installment_id );
		if ( ! $installment ) {
			return new WP_Error( 'not_found', __( 'La cuota no existe.', 'gestion-de-proyectos' ) );
		}
		$project_id = (int) $installment['project_id'];
		$project    = ProjectRepository::find( $project_id );
		$status     = FinanceService::status( $project_id );
		$next       = $status['next_installment'];
		$events     = EventRepository::for_entity( 'installment', $installment_id, 'estado' );
		ob_start();
		self::print_head( sprintf( '%s · Ficha de giro cuota %d', $project ? $project['code'] : '', $installment['number'] ) );
		?>
		<h1><?php echo esc_html( sprintf( /* translators: 1: número de la cuota, 2: monto. */ __( 'Ficha de giro · cuota %1$d por %2$s', 'gestion-de-proyectos' ), $installment['number'], Assistant::money( (float) $installment['amount'] ) ) ); ?></h1>
		<table class="facts">
			<tr><th><?php esc_html_e( 'Proyecto', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $project ? $project['name'] : '' ); ?></td><th><?php esc_html_e( 'Ventana', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( trim( (string) $installment['window_from'] . ' a ' . (string) $installment['window_to'], ' a' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Transferido hasta la cuota anterior', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( Assistant::money( $status['sources']['fondo']['received'] ) ); ?></td><th><?php esc_html_e( 'Pagado / rendido / aprobado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( Assistant::money( $status['sources']['fondo']['paid'] ) . ' / ' . Assistant::money( $status['sources']['fondo']['rendered'] ) . ' / ' . Assistant::money( $status['sources']['fondo']['approved'] ) ); ?></td></tr>
		</table>
		<?php if ( $next && $next['number'] === $installment['number'] ) : ?>
			<h2><?php esc_html_e( 'Condiciones de giro', 'gestion-de-proyectos' ); ?></h2>
			<table class="grid">
				<thead><tr><th></th><th><?php esc_html_e( 'Condición', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Cumple', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Detalle y evidencia', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $next['conditions'] as $c ) : ?>
					<tr><td><?php echo esc_html( $c['key'] ); ?></td><td><?php echo esc_html( $c['label'] ); ?></td><td><?php echo esc_html( null === $c['ok'] ? __( 'sin registro', 'gestion-de-proyectos' ) : ( $c['ok'] ? __( 'sí', 'gestion-de-proyectos' ) : __( 'no', 'gestion-de-proyectos' ) ) ); ?></td><td><?php echo esc_html( $c['detail'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php echo esc_html( sprintf( /* translators: 1: brecha de pago, 2: brecha de rendición, 3: mes. */ __( 'Brecha de pago %1$s; brecha de rendición %2$s; último mes de pago útil %3$s.', 'gestion-de-proyectos' ), Assistant::money( $next['gaps']['pay_gap'] ), Assistant::money( $next['gaps']['render_gap'] ), $next['latest_month'] ? Assistant::month_label( $next['latest_month'] ) : '—' ) ); ?></p>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Eventos registrados', 'gestion-de-proyectos' ); ?></h2>
		<table class="grid">
			<thead><tr><th><?php esc_html_e( 'Fecha', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Evento', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Quién', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nota', 'gestion-de-proyectos' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $events as $e ) : ?>
				<tr><td><?php echo esc_html( (string) $e['event_date'] ); ?></td><td><?php echo esc_html( $e['event_key'] ); ?></td><td><?php echo esc_html( $e['user'] ); ?></td><td><?php echo esc_html( (string) $e['note'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="foot"><?php echo esc_html( sprintf( /* translators: fecha y hora. */ __( 'Generado por Gestión de Proyectos el %s.', 'gestion-de-proyectos' ), $status['today'] ) ); ?></p>
		<?php
		self::print_foot();

		return (string) ob_get_clean();
	}

	/**
	 * CSV con separador punto y coma.
	 *
	 * @param array<int,array<int,mixed>> $rows Filas.
	 * @return string
	 */
	private static function csv( array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( static fn( $v ) => is_float( $v ) ? str_replace( '.', ',', (string) $v ) : (string) $v, $row ), ';', '"', '\\' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Cabecera de una vista imprimible.
	 *
	 * @param string $title Título.
	 * @return void
	 */
	private static function print_head( string $title ): void {
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $title ); ?></title>
<style>
body { font-family: Georgia, "Times New Roman", serif; color: #1d1d1b; margin: 2cm; font-size: 12pt; line-height: 1.4; }
h1 { font-size: 16pt; margin: 0 0 12pt; }
h2 { font-size: 13pt; margin: 18pt 0 6pt; }
table { border-collapse: collapse; width: 100%; margin-bottom: 12pt; }
.facts th, .facts td { text-align: left; padding: 4pt 8pt; border-bottom: 1px solid #ccc; vertical-align: top; }
.facts th { width: 22%; font-weight: 600; }
.grid th, .grid td { border: 1px solid #999; padding: 3pt 6pt; font-size: 10pt; vertical-align: top; }
.grid th { background: #eee; }
.num { text-align: right; white-space: nowrap; }
.right { text-align: right; }
.sign { margin-top: 48pt; }
.foot { font-size: 9pt; color: #666; margin-top: 24pt; }
.break { page-break-after: always; }
@media print { body { margin: 1.5cm; } .noprint { display: none; } }
</style>
</head>
<body>
<p class="noprint"><button onclick="window.print()"><?php esc_html_e( 'Imprimir o guardar en PDF', 'gestion-de-proyectos' ); ?></button></p>
		<?php
	}

	/**
	 * Pie de una vista imprimible.
	 *
	 * @return void
	 */
	private static function print_foot(): void {
		echo "</body>\n</html>\n";
	}
}
