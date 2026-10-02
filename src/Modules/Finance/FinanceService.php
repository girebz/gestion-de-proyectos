<?php
/**
 * Estado de cuentas del proyecto.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Modules\Finance\Logic\CashPlanChecks;
use GDP\Modules\Finance\Logic\Deadlines;
use GDP\Modules\Finance\Logic\GapCalculator;
use GDP\Modules\Finance\Logic\Imputation;
use GDP\Modules\Finance\Logic\InstallmentConditions;
use GDP\Modules\Finance\Logic\PaymentValidator;
use GDP\Modules\Finance\Logic\Reconciliation;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Modules\Documents\ExternalRefRepository;
use GDP\Modules\Procurement\SupplierRepository;
use GDP\Modules\Procurement\UfService;

defined( 'ABSPATH' ) || exit;

/**
 * Reúne, a una fecha, todo lo que el tablero y el asistente necesitan: por
 * fuente, lo transferido, pagado, rendido, aprobado, observado y rechazado;
 * el saldo de caja y su conciliación; el avance por cuota; el disponible por
 * ítem; las brechas y condiciones de la cuota siguiente; la línea de tiempo
 * de las rendiciones; la programación de caja frente a lo real; y la
 * diferencia de cierre.
 */
final class FinanceService {

	/**
	 * Estado completo.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $today      Fecha de referencia (por omisión, hoy).
	 * @return array<string,mixed>
	 */
	public static function status( int $project_id, string $today = '' ): array {
		$today     = '' !== $today ? $today : current_time( 'Y-m-d' );
		$agreement = AgreementRepository::get( $project_id );
		$profile   = Profiles::get( (string) $agreement['profile'] );
		$rules     = RulesRepository::effective( $project_id, $profile->slug(), $today );
		$rule      = static fn( string $key, string $fallback = '' ): string => (string) ( $rules[ $key ]['value'] ?? $fallback );
		$calendar  = Calendar::for_project( $project_id );

		$installments = InstallmentRepository::all( $project_id );
		$amounts      = array();
		$transferred  = 0.0;
		$contributed  = 0.0;
		$received     = array();
		$receipts     = array();
		foreach ( $installments as $i ) {
			$amounts[ $i['number'] ] = (float) $i['amount'];
			if ( InstallmentRepository::is_received( $i ) ) {
				$transferred += (float) $i['amount'];
				$received[]   = $i['number'];
				if ( empty( $i['receipt_sent_at'] ) ) {
					$receipts[] = $i['number'];
				}
			}
			if ( ! empty( $i['cash_received_at'] ) ) {
				$contributed += (float) $i['cash_amount'];
			}
		}
		$totals = PaymentRepository::totals( $project_id );
		$ledger = array( 'fondo' => LedgerRepository::summary( $project_id, 'fondo' ), 'pecuniario' => LedgerRepository::summary( $project_id, 'pecuniario' ) );

		$fund    = $totals['fondo'];
		$cash    = $totals['pecuniario'];
		$returned = $ledger['fondo']['returned'];
		$sources = array(
			'fondo'      => $fund + array(
				'label'          => $profile->sources()['fondo'],
				'total'          => (float) $agreement['fund_amount'],
				'received'       => round( $transferred, 2 ),
				'to_receive'     => round( (float) $agreement['fund_amount'] - $transferred, 2 ),
				'returned'       => $returned,
				'balance'        => Reconciliation::balance( $transferred, 0.0, $fund['paid'], 0.0, $returned ),
				'ledger_balance' => $ledger['fondo']['balance'],
				'ledger_count'   => $ledger['fondo']['count'],
				'unmatched'      => $ledger['fondo']['unmatched'],
				'non_negative'   => Reconciliation::non_negative( $transferred, $fund['paid'] ),
			),
			'pecuniario' => $cash + array(
				'label'          => $profile->sources()['pecuniario'],
				'total'          => (float) $agreement['cash_amount'],
				'received'       => round( $contributed, 2 ),
				'to_receive'     => round( (float) $agreement['cash_amount'] - $contributed, 2 ),
				'returned'       => 0.0,
				'balance'        => Reconciliation::balance( 0.0, $contributed, 0.0, $cash['paid'], 0.0 ),
				'ledger_balance' => $ledger['pecuniario']['balance'],
				'ledger_count'   => $ledger['pecuniario']['count'],
				'unmatched'      => $ledger['pecuniario']['unmatched'],
				'non_negative'   => Reconciliation::non_negative( $contributed, $cash['paid'] ),
			),
		);
		foreach ( $sources as &$s ) {
			$s['difference'] = $s['ledger_count'] > 0 ? Reconciliation::difference( $s['ledger_balance'], $s['balance'] ) : null;
		}
		unset( $s );

		// Avance por cuota (imputación cronológica o declarada).
		$effective = PaymentRepository::effective_installments( $project_id );
		$declared  = array( 'paid' => array(), 'rendered' => array(), 'approved' => array() );
		foreach ( PaymentRepository::paid( $project_id, 'fondo' ) as $p ) {
			$k = $effective[ $p['id'] ] ?? 0;
			$declared['paid'][ $k ] = (float) ( $declared['paid'][ $k ] ?? 0 ) + (float) $p['amount'];
			if ( in_array( $p['status'], PaymentRepository::RENDERED, true ) ) {
				$declared['rendered'][ $k ] = (float) ( $declared['rendered'][ $k ] ?? 0 ) + (float) $p['amount'];
			}
			if ( 'aprobado' === $p['status'] ) {
				$declared['approved'][ $k ] = (float) ( $declared['approved'][ $k ] ?? 0 ) + (float) $p['amount'];
			}
		}
		$rows = array();
		foreach ( $installments as $i ) {
			$k      = $i['number'];
			$rows[] = $i + array(
				'is_received'  => InstallmentRepository::is_received( $i ),
				'received_on'  => InstallmentRepository::received_on( $i ),
				'paid'         => round( (float) ( $declared['paid'][ $k ] ?? 0 ), 2 ),
				'rendered'     => round( (float) ( $declared['rendered'][ $k ] ?? 0 ), 2 ),
				'approved'     => round( (float) ( $declared['approved'][ $k ] ?? 0 ), 2 ),
				'excess'       => Imputation::excess( array( $k => (float) ( $declared['rendered'][ $k ] ?? 0 ) ), $amounts )[ $k ] ?? 0.0,
				'receipt_due'  => $i['transferred_at'] ? $calendar->add_working_days( (string) $i['transferred_at'], max( 1, (int) $rule( 'plazo_comprobante_ingreso', '5' ) ) ) : null,
			);
		}

		// Disponible por ítem.
		$by_item = PaymentRepository::totals_by_item( $project_id );
		$items   = array();
		foreach ( ItemRepository::all( $project_id ) as $item ) {
			$row = array( 'slug' => $item['slug'], 'label' => $item['label'], 'group' => $item['item_group'], 'platform_type' => $item['platform_type'], 'platform_subclass' => $item['platform_subclass'], 'cap_rule' => $item['cap_rule'], 'sources' => array() );
			foreach ( array( 'fondo', 'pecuniario' ) as $src ) {
				$t = $by_item[ $item['slug'] ][ $src ] ?? array( 'paid' => 0.0, 'committed' => 0.0, 'rejected' => 0.0, 'rendered' => 0.0 );
				$m = ItemRepository::assigned( $item, $src );
				$row['sources'][ $src ] = array(
					'assigned'  => $m,
					'paid'      => round( $t['paid'], 2 ),
					'committed' => round( $t['committed'], 2 ),
					'rejected'  => round( $t['rejected'], 2 ),
					'rendered'  => round( $t['rendered'], 2 ),
					'available' => Reconciliation::available( $m, $t['paid'], $t['committed'], $t['rejected'] ),
					'pct'       => $m > 0 ? round( 100 * $t['paid'] / $m, 1 ) : null,
				);
			}
			$row['cap'] = self::cap_check( $item, $rule, $agreement, $row['sources']['fondo']['paid'] + $row['sources']['fondo']['committed'] );
			$items[]    = $row;
		}
		if ( isset( $by_item['sin_item'] ) ) {
			$items[] = array( 'slug' => 'sin_item', 'label' => __( 'Sin ítem', 'gestion-de-proyectos' ), 'group' => '', 'platform_type' => '', 'platform_subclass' => '', 'cap_rule' => '', 'sources' => array( 'fondo' => array( 'assigned' => 0.0, 'paid' => round( $by_item['sin_item']['fondo']['paid'] ?? 0, 2 ), 'committed' => round( $by_item['sin_item']['fondo']['committed'] ?? 0, 2 ), 'rejected' => 0.0, 'rendered' => 0.0, 'available' => 0.0, 'pct' => null ), 'pecuniario' => array( 'assigned' => 0.0, 'paid' => round( $by_item['sin_item']['pecuniario']['paid'] ?? 0, 2 ), 'committed' => round( $by_item['sin_item']['pecuniario']['committed'] ?? 0, 2 ), 'rejected' => 0.0, 'rendered' => 0.0, 'available' => 0.0, 'pct' => null ) ), 'cap' => null );
		}

		// Cuota siguiente.
		$k_last = empty( $received ) ? 0 : max( $received );
		$next   = InstallmentRepository::by_number( $project_id, $k_last + 1 );
		$next_info = null;
		if ( $next ) {
			$gaps      = GapCalculator::gaps( $amounts, $k_last, $fund['paid'], $fund['rendered'], $fund['approved'] );
			$criterion = $rule( 'condicion_giro', 'ambos' );
			$report    = EventRepository::for_entity( 'installment', $next['id'], 'estado' );
			$report_ok = null;
			foreach ( $report as $e ) {
				if ( 'informe_aprobado' === $e['event_key'] ) {
					$report_ok = true;
					break;
				}
			}
			$conditions = InstallmentConditions::evaluate(
				array(
					'next'               => $next['number'],
					'target'             => $gaps['target'],
					'rendered'           => $fund['rendered'],
					'approved'           => $fund['approved'],
					'criterion'          => $criterion,
					'guarantee'          => GuaranteeRepository::covering( $project_id, $next['number'], $today ),
					'report_approved'    => $report_ok,
					'report_no'          => $next['report_no'] > 0 ? $next['report_no'] : $k_last,
					'cash_amount'        => (float) $next['cash_amount'],
					'cash_received'      => ! empty( $next['cash_received_at'] ),
					'cash_received_at'   => $next['cash_received_at'],
					'request_letter'     => $next['requested_at'] ? true : null,
					'overdue_renditions' => RenditionRepository::overdue( $project_id, $today ),
					'receipts_pending'   => $receipts,
					'window'             => array( (string) $next['window_from'], (string) $next['window_to'] ),
					'today'              => $today,
				)
			);
			$target_date = $rule( 'fecha_limite_giro', '' );
			if ( '' === $target_date ) {
				$target_date = $next['window_to'] ? (string) $next['window_to'] : Deadlines::month_end( Deadlines::add_months( substr( $today, 0, 7 ), 3 ) );
			}
			$platform_day = max( 1, (int) $rule( 'plazo_sisrec', '15' ) );
			$latest       = Deadlines::latest_payment_month( $target_date, (int) $rule( 'delta_revision', '15' ), (int) $rule( 'delta_solicitud', '10' ), $platform_day, $calendar, substr( $today, 0, 7 ) );
			$candidates   = self::candidates( $project_id, $items, $gaps['pay_gap'] );
			$next_info    = array(
				'number'              => $next['number'],
				'amount'              => (float) $next['amount'],
				'cash_amount'         => (float) $next['cash_amount'],
				'window'              => array( $next['window_from'], $next['window_to'] ),
				'target_date'         => $target_date,
				'gaps'                => $gaps,
				'criterion'           => $criterion,
				'conditions'          => $conditions,
				'all_met'             => InstallmentConditions::all_met( $conditions ),
				'latest_month'        => $latest,
				'latest_month_end'    => $latest ? Deadlines::month_end( $latest ) : null,
				'latest_render_due'   => $latest ? Deadlines::rho( $latest, $platform_day, $calendar ) : null,
				'invoice_by'          => $latest ? gmdate( 'Y-m-d', (int) strtotime( Deadlines::month_end( $latest ) . ' -' . max( 0, (int) $rule( 'plazo_pago_factura', '30' ) ) . ' days' ) ) : null,
				'invoice_days'        => max( 0, (int) $rule( 'plazo_pago_factura', '30' ) ),
				'candidates'          => $candidates['selected'],
				'candidates_covered'  => $candidates['covered'],
				'candidates_remaining' => $candidates['remaining'],
			);
		}

		// Línea de tiempo de rendiciones.
		$timeline = self::timeline( $project_id, $agreement, $today, $calendar, $rule );

		// Programación de caja vigente frente a lo real.
		$plan      = CashPlanRepository::current( $project_id );
		$plan_info = null;
		if ( $plan ) {
			$plan_rows = CashPlanRepository::rows( $plan['id'] );
			$plan_info = array( 'plan' => $plan, 'rows' => $plan_rows, 'checks' => self::plan_checks( $project_id, $plan_rows, $agreement, $calendar, $rule ), 'baseline' => self::plan_baseline( $project_id, $plan_rows ), 'actual' => PaymentRepository::paid_by_period( $project_id, 'fondo' ) );
		}

		return array(
			'today'              => $today,
			'agreement'          => $agreement,
			'profile'            => $profile->slug(),
			'profile_label'      => $profile->label(),
			'rules'              => $rules,
			'sources'            => $sources,
			'installments'       => $rows,
			'last_received'      => $k_last,
			'items'              => $items,
			'next_installment'   => $next_info,
			'renditions'         => $timeline,
			'overdue_renditions' => RenditionRepository::overdue( $project_id, $today ),
			'cash_plan'          => $plan_info,
			'closing_difference' => Reconciliation::closing_difference( $transferred, $fund['approved'], $returned ),
			'reitemizations'     => array( 'used' => ModificationRepository::reitemizations_used( $project_id ), 'max' => (int) $rule( 'reitemizaciones_max', '3' ) ),
			'uf_value'           => UfService::latest(),
		);
	}

	/**
	 * Comprobación del tope de un ítem (personal, administración, cuota 1).
	 *
	 * @param array<string,mixed> $item      Ítem.
	 * @param callable            $rule      Lector de reglas.
	 * @param array<string,mixed> $agreement Convenio.
	 * @param float               $used      Pagado más comprometido con cargo al Fondo.
	 * @return array{limit:float,base:string,ok:bool}|null
	 */
	private static function cap_check( array $item, callable $rule, array $agreement, float $used ): ?array {
		if ( '' === $item['cap_rule'] ) {
			return null;
		}
		$pct = (float) $rule( $item['cap_rule'], '0' );
		if ( $pct <= 0 ) {
			return null;
		}
		$fund  = (float) $agreement['fund_amount'];
		$total = $fund + (float) $agreement['cash_amount'] + (float) $agreement['inkind_amount'];
		$base  = 'personal_max' === $item['cap_rule'] ? $total : $fund;
		$limit = round( $base * $pct / 100, 2 );

		return array( 'limit' => $limit, 'base' => 'personal_max' === $item['cap_rule'] ? 'costo total' : 'Fondo', 'ok' => $used <= $limit + 0.5 && (float) $item['assigned_fund'] <= $limit + 0.5 );
	}

	/**
	 * Compromisos candidatos a cerrar la brecha de pago: pagos comprometidos o
	 * devengados con cargo al Fondo, ordenados por disponibilidad del ítem,
	 * preparación (devengado antes que comprometido) y fecha.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param array<int,array<string,mixed>> $items      Ítems con disponible.
	 * @param float                          $gap        Brecha de pago.
	 * @return array{selected:array<int,array<string,mixed>>,covered:float,remaining:float}
	 */
	private static function candidates( int $project_id, array $items, float $gap ): array {
		if ( $gap <= 0 ) {
			return array( 'selected' => array(), 'covered' => 0.0, 'remaining' => 0.0 );
		}
		$available = array();
		foreach ( $items as $i ) {
			$available[ $i['slug'] ] = (float) ( $i['sources']['fondo']['available'] ?? 0 );
		}
		$candidates = array();
		foreach ( PaymentRepository::list( $project_id, array( 'source' => 'fondo', 'statuses' => PaymentRepository::COMMITTED ) ) as $p ) {
			$candidates[] = array(
				'id'          => $p['id'],
				'code'        => $p['code'],
				'description' => $p['description'],
				'item_slug'   => $p['item_slug'],
				'amount'      => (float) $p['amount'],
				'status'      => $p['status'],
				'date'        => $p['doc_date'] ?? $p['executed_at'] ?? '',
				'item_ok'     => ( $available[ $p['item_slug'] ] ?? 0 ) + (float) $p['amount'] >= 0,
			);
		}
		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return array( $a['item_ok'] ? 0 : 1, 'devengado' === $a['status'] ? 0 : 1, (string) $a['date'] ) <=> array( $b['item_ok'] ? 0 : 1, 'devengado' === $b['status'] ? 0 : 1, (string) $b['date'] );
			}
		);

		return GapCalculator::cover( $candidates, $gap );
	}

	/**
	 * Línea de tiempo de las rendiciones del Fondo desde el inicio del convenio hasta el mes anterior al actual.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $agreement  Convenio.
	 * @param string              $today      Hoy.
	 * @param \GDP\Planning\WorkCalendar $calendar Calendario.
	 * @param callable            $rule       Lector de reglas.
	 * @return array<int,array<string,mixed>>
	 */
	private static function timeline( int $project_id, array $agreement, string $today, $calendar, callable $rule ): array {
		$start = (string) ( $agreement['start_date'] ?? '' );
		if ( '' === $start ) {
			return array();
		}
		$renditions = array();
		foreach ( RenditionRepository::all( $project_id, 'fondo' ) as $r ) {
			$renditions[ $r['period'] ][] = $r;
		}
		$paid_by_period = PaymentRepository::paid_by_period( $project_id, 'fondo' );
		$last           = substr( $today, 0, 7 );
		$end            = (string) ( $agreement['end_date'] ?? '' );
		if ( '' !== $end && substr( $end, 0, 7 ) < $last ) {
			$last = substr( $end, 0, 7 );
		}
		$internal = max( 1, (int) $rule( 'plazo_respaldo_interno', '8' ) );
		$platform = max( 1, (int) $rule( 'plazo_sisrec', '15' ) );
		$out      = array();
		foreach ( Deadlines::months_between( substr( $start, 0, 7 ), $last ) as $period ) {
			$main = null;
			foreach ( $renditions[ $period ] ?? array() as $r ) {
				if ( in_array( $r['kind'], array( 'mensual', 'sin_movimiento' ), true ) ) {
					$main = $r;
					break;
				}
			}
			$due     = $main && $main['platform_due'] ? array( 'internal' => $main['internal_due'], 'platform' => $main['platform_due'] ) : Deadlines::rendition_due( $period, $internal, $platform, $calendar );
			$amount  = (float) ( $paid_by_period[ $period ] ?? 0 );
			$status  = $main ? $main['status'] : 'sin_crear';
			$current = $period === substr( $today, 0, 7 );
			$out[]   = array(
				'period'        => $period,
				'current'       => $current,
				'rendition'     => $main,
				'others'        => array_values( array_filter( $renditions[ $period ] ?? array(), static fn( array $r ): bool => ! in_array( $r['kind'], array( 'mensual', 'sin_movimiento' ), true ) ) ),
				'amount'        => $amount,
				'expected_kind' => $amount > 0 ? 'mensual' : 'sin_movimiento',
				'internal_due'  => $due['internal'],
				'platform_due'  => $due['platform'],
				'status'        => $status,
				'submitted'     => $main && in_array( $main['status'], RenditionRepository::SUBMITTED, true ),
				'overdue'       => ! $current && $due['platform'] < $today && ! ( $main && in_array( $main['status'], RenditionRepository::SUBMITTED, true ) ),
				'days_left'     => Deadlines::working_days_left( $today, $due['platform'], $calendar ),
			);
		}

		return $out;
	}

	/**
	 * Línea base de una programación de caja: lo pagado y lo transferido con
	 * cargo al Fondo antes del primer mes del plan. Un plan que parte en
	 * septiembre programa el gasto de septiembre; si la base incluyera los
	 * pagos reales de septiembre, ese mes se contaría dos veces, una como
	 * real y otra como programado. Los pagos sin fecha de pago y las cuotas
	 * recibidas sin fecha se consideran anteriores al plan.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param array<int,array<string,mixed>> $rows       Filas del plan.
	 * @return array{first:string,spent:float,transferred:float,received:int[],amounts:array<int,float>}
	 */
	public static function plan_baseline( int $project_id, array $rows ): array {
		$first = '';
		foreach ( $rows as $r ) {
			$period = (string) ( $r['period'] ?? '' );
			if ( '' !== $period && ( '' === $first || $period < $first ) ) {
				$first = $period;
			}
		}
		$amounts     = array();
		$received    = array();
		$transferred = 0.0;
		foreach ( InstallmentRepository::all( $project_id ) as $i ) {
			$amounts[ $i['number'] ] = (float) $i['amount'];
			if ( ! InstallmentRepository::is_received( $i ) ) {
				continue;
			}
			$on = (string) InstallmentRepository::received_on( $i );
			if ( '' === $first || '' === $on || substr( $on, 0, 7 ) < $first ) {
				$transferred += (float) $i['amount'];
				$received[]   = (int) $i['number'];
			}
		}
		$spent = 0.0;
		foreach ( PaymentRepository::paid( $project_id, 'fondo' ) as $p ) {
			$on = (string) ( $p['paid_at'] ?? '' );
			if ( '' === $first || '' === $on || substr( $on, 0, 7 ) < $first ) {
				$spent += (float) $p['amount'];
			}
		}

		return array( 'first' => $first, 'spent' => round( $spent, 2 ), 'transferred' => round( $transferred, 2 ), 'received' => $received, 'amounts' => $amounts );
	}

	/**
	 * Controles de una programación de caja, medidos desde su línea base.
	 *
	 * @param int                            $project_id Proyecto.
	 * @param array<int,array<string,mixed>> $rows       Filas del plan (period, transfer, spend, cash).
	 * @param array<string,mixed>            $agreement  Convenio.
	 * @param \GDP\Planning\WorkCalendar     $calendar   Calendario.
	 * @param callable                       $rule       Lector de reglas.
	 * @return array<int,array<string,mixed>>
	 */
	public static function plan_checks( int $project_id, array $rows, array $agreement, $calendar, callable $rule ): array {
		$base         = self::plan_baseline( $project_id, $rows );
		$platform_day = max( 1, (int) $rule( 'plazo_sisrec', '15' ) );
		$due          = array();
		$windows      = array();
		foreach ( $rows as $r ) {
			$due[ $r['period'] ] = Deadlines::rho( (string) $r['period'], $platform_day, $calendar );
		}
		foreach ( InstallmentRepository::all( $project_id ) as $i ) {
			$windows[ $i['number'] ] = array( (string) $i['window_from'], (string) $i['window_to'] );
		}
		$guarantees = array();
		foreach ( GuaranteeRepository::all( $project_id ) as $g ) {
			if ( 'vigente' === $g['status'] && 'fraccion_no_rendida' === $g['kind'] && $g['installment_no'] > 0 ) {
				$guarantees[ $g['installment_no'] ] = (float) ( $guarantees[ $g['installment_no'] ] ?? 0 ) + (float) $g['amount'];
			}
		}

		return CashPlanChecks::evaluate(
			$rows,
			array(
				'fund_total'  => (float) $agreement['fund_amount'],
				'transferred' => $base['transferred'],
				'spent'       => $base['spent'],
				'amounts'     => $base['amounts'],
				'received'    => $base['received'],
				'windows'     => $windows,
				'due'         => $due,
				'lag_days'    => (int) $rule( 'delta_revision', '15' ) + (int) $rule( 'delta_solicitud', '10' ),
				'guarantees'  => $guarantees,
			)
		);
	}

	/**
	 * Validación de un pago con el contexto del proyecto.
	 *
	 * @param array<string,mixed> $payment Pago.
	 * @param array<string,mixed> $status  Estado del proyecto (opcional, para no recalcular).
	 * @return array<int,array{code:string,severity:string,message:string}>
	 */
	public static function validate_payment( array $payment, ?array $status = null ): array {
		$project_id = (int) $payment['project_id'];
		$agreement  = $status ? $status['agreement'] : AgreementRepository::get( $project_id );
		$profile    = Profiles::get( (string) $agreement['profile'] );
		$rules      = $status ? $status['rules'] : RulesRepository::effective( $project_id, $profile->slug() );
		$rule       = static fn( string $key, string $fallback = '' ): string => (string) ( $rules[ $key ]['value'] ?? $fallback );
		$ctx        = array(
			'start_date'           => AgreementRepository::render_from( $agreement ),
			'end_date'             => (string) ( $agreement['end_date'] ?? '' ),
			'platform_end_date'    => (string) ( $agreement['platform_end_date'] ?? '' ),
			'uf_value'             => (float) ( UfService::latest()['value'] ?? 0 ),
			'invoice_threshold_uf' => (float) $rule( 'factura_umbral', '2' ),
			'quotes_threshold'     => (float) $rule( 'cotizaciones_umbral', '3000000' ),
			'tender_threshold_uf'  => (float) $rule( 'licitacion_umbral', '3000' ),
			'items'                => ItemRepository::labels( $project_id ),
			'required_support'     => $profile->support_requirements()[ (string) $payment['item_slug'] ] ?? array(),
			'boleta_sin_codigo'    => $rule( 'boleta_sin_codigo', 'advertir' ),
			'today'                => current_time( 'Y-m-d' ),
		);
		if ( $payment['supplier_id'] > 0 ) {
			$supplier                   = SupplierRepository::find( (int) $payment['supplier_id'] );
			$ctx['supplier_registered'] = $supplier ? self::supplier_registered( $supplier ) : false;
		}
		if ( 'personal' === $payment['item_slug'] && 'boleta_honorarios' === $payment['doc_type'] ) {
			unset( $ctx['required_support']['boleta'] );
			$payment['support'][] = array( 'kind' => 'boleta' );
		}

		return PaymentValidator::validate( $payment, $ctx );
	}

	/**
	 * Indica si un proveedor está marcado como registrado en la plataforma.
	 *
	 * @param array<string,mixed> $supplier Proveedor.
	 * @return bool
	 */
	public static function supplier_registered( array $supplier ): bool {
		foreach ( ExternalRefRepository::for_entity( 'supplier', (int) $supplier['id'] ) as $ref ) {
			if ( 0 === strcasecmp( (string) $ref['system_name'], 'SISREC' ) && 'registrado' === (string) $ref['ref_status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Marca (o desmarca) un proveedor como registrado en la plataforma.
	 *
	 * @param int  $project_id Proyecto.
	 * @param int  $supplier_id Proveedor.
	 * @param bool $registered  Registrado.
	 * @return void
	 */
	public static function set_supplier_registered( int $project_id, int $supplier_id, bool $registered ): void {
		ExternalRefRepository::set( $project_id, 'supplier', $supplier_id, 'SISREC', '', $registered ? 'registrado' : 'pendiente' );
	}
}
