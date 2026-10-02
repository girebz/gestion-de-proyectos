<?php
/**
 * Asistente de rendición: acciones derivadas del estado y hojas de ejecución.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance;

use GDP\Admin\Pages\FinancePage;
use GDP\Modules\Finance\Logic\BulkLoad;
use GDP\Modules\Finance\Logic\Deadlines;
use GDP\Modules\Finance\Logic\PaymentValidator;
use GDP\Modules\Finance\Logic\Transliterator;
use GDP\Modules\Finance\Profiles\Profiles;
use GDP\Modules\Procurement\SupplierRepository;
use GDP\Modules\Documents\VersionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * El asistente no es un manual estático sino una función del estado: para
 * cada obligación vigente genera una acción con responsable, plazo y
 * procedimiento, y cada procedimiento se presenta como hoja de ejecución,
 * esto es, el producto de una guía del perfil por los datos del módulo,
 * con cada valor ya en el formato que la pantalla exige.
 */
final class Assistant {

	/**
	 * Acciones pendientes, ordenadas por severidad y vencimiento.
	 *
	 * @param int                      $project_id Proyecto.
	 * @param array<string,mixed>|null $status     Estado (se calcula si falta).
	 * @return array<int,array<string,mixed>>
	 */
	public static function actions( int $project_id, ?array $status = null ): array {
		$status   = $status ?? FinanceService::status( $project_id );
		$today    = (string) $status['today'];
		$calendar = Calendar::for_project( $project_id );
		$actions  = array();
		$add      = static function ( string $key, string $title, string $detail, ?string $due, string $severity, string $guide = '', string $entity_type = 'project', int $entity_id = 0, string $tab = 'assistant', array $args = array() ) use ( &$actions, $today, $calendar ): void {
			$actions[] = array(
				'key'         => $key,
				'title'       => $title,
				'detail'      => $detail,
				'due'         => $due,
				'days_left'   => $due ? Deadlines::working_days_left( $today, $due, $calendar ) : null,
				'severity'    => $severity,
				'guide'       => $guide,
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'tab'         => $tab,
				'args'        => $args,
			);
		};

		$agreement = $status['agreement'];
		if ( (float) $agreement['fund_amount'] <= 0 || empty( $agreement['start_date'] ) ) {
			$add( 'convenio', __( 'Registrar el convenio', 'gestion-de-proyectos' ), __( 'Sin monto del Fondo y fecha de inicio el módulo no puede calcular plazos ni brechas.', 'gestion-de-proyectos' ), null, 'alta', '', 'project', 0, 'agreement' );
		}
		if ( empty( $status['installments'] ) ) {
			$add( 'cuotas', __( 'Registrar las cuotas del programa de desembolso', 'gestion-de-proyectos' ), __( 'Monto, ventana (semestre), informe que la habilita y aporte pecuniario de cada cuota.', 'gestion-de-proyectos' ), null, 'alta', '', 'project', 0, 'installments' );
		}
		$assigned_total = 0.0;
		foreach ( $status['items'] as $i ) {
			$assigned_total += (float) ( $i['sources']['fondo']['assigned'] ?? 0 );
		}
		if ( $assigned_total <= 0 ) {
			$add( 'items', __( 'Fijar el asignado por ítem', 'gestion-de-proyectos' ), __( 'El disponible por ítem y los topes se calculan sobre el asignado vigente de cada fuente.', 'gestion-de-proyectos' ), null, 'media', '', 'project', 0, 'items' );
		}

		foreach ( $status['installments'] as $i ) {
			if ( $i['is_received'] && 'aceptada' !== $i['platform_status'] ) {
				/* translators: número de cuota. */
				$add( 'transferencia:' . $i['number'], sprintf( __( 'Aceptar la transferencia de la cuota %d y enviar el comprobante de ingreso', 'gestion-de-proyectos' ), $i['number'] ), sprintf( __( 'La transferencia debe aceptarse en la plataforma con el comprobante de ingreso; el plazo de 5 días hábiles corre desde la transferencia (%s).', 'gestion-de-proyectos' ), (string) ( $i['transferred_at'] ?? $i['received_at'] ) ), $i['receipt_due'], 'alta', 'aceptar_transferencia', 'installment', $i['id'], 'installments' );
			} elseif ( $i['is_received'] && empty( $i['receipt_sent_at'] ) ) {
				/* translators: número de cuota. */
				$add( 'comprobante:' . $i['number'], sprintf( __( 'Registrar el envío del comprobante de ingreso de la cuota %d', 'gestion-de-proyectos' ), $i['number'] ), __( 'La condición de giro (G6) exige los comprobantes de ingreso de todas las transferencias anteriores.', 'gestion-de-proyectos' ), $i['receipt_due'], 'media', 'aceptar_transferencia', 'installment', $i['id'], 'installments' );
			}
		}

		foreach ( $status['renditions'] as $t ) {
			$period = (string) $t['period'];
			$label  = self::month_label( $period );
			$r      = $t['rendition'];
			if ( $t['current'] ) {
				$incomplete = self::incomplete_payments( $project_id, $period, $status );
				if ( $incomplete > 0 ) {
					/* translators: 1: mes, 2: número de pagos. */
					$add( 'respaldos:' . $period, sprintf( __( 'Reunir los respaldos de %1$s (%2$d pagos incompletos)', 'gestion-de-proyectos' ), $label, $incomplete ), __( 'Cada pago del mes necesita su comprobante de egreso y los respaldos que exige su ítem antes del plazo interno.', 'gestion-de-proyectos' ), $t['internal_due'], 'media', 'respaldos_mensuales', 'rendition', $r ? $r['id'] : 0, 'renditions', array( 'period' => $period ) );
				}
				continue;
			}
			if ( ! $r ) {
				$kind = (string) $t['expected_kind'];
				/* translators: mes. */
				$title = 'mensual' === $kind ? sprintf( __( 'Crear y presentar la rendición de %s', 'gestion-de-proyectos' ), $label ) : sprintf( __( 'Presentar la rendición sin movimiento de %s', 'gestion-de-proyectos' ), $label );
				$detail = $t['overdue'] ? __( 'Rendición exigible atrasada: una sola bloquea todo giro (G5) y su omisión es causal de término anticipado.', 'gestion-de-proyectos' ) : sprintf( /* translators: 1: plazo interno, 2: plazo de la plataforma. */ __( 'Respaldos internos antes del %1$s; carga en la plataforma antes del %2$s.', 'gestion-de-proyectos' ), (string) $t['internal_due'], (string) $t['platform_due'] );
				$add( 'rendicion:' . $period, $title, $detail, $t['overdue'] ? $t['platform_due'] : ( $t['internal_due'] >= $today ? $t['internal_due'] : $t['platform_due'] ), $t['overdue'] ? 'alta' : 'media', 'mensual' === $kind ? 'respaldos_mensuales' : 'rendicion_sin_movimiento', 'rendition', 0, 'renditions', array( 'period' => $period, 'create' => $kind ) );
				continue;
			}
			switch ( $r['status'] ) {
				case 'preparada':
					/* translators: mes. */
					$add( 'rendicion:' . $period, sprintf( __( 'Completar y enviar a la universidad la rendición de %s', 'gestion-de-proyectos' ), $label ), __( 'Respaldos completos y expediente entregado a la Dirección de Investigación.', 'gestion-de-proyectos' ), $t['internal_due'] >= $today ? $t['internal_due'] : $t['platform_due'], $t['overdue'] ? 'alta' : 'media', 'sin_movimiento' === $r['kind'] ? 'rendicion_sin_movimiento' : 'respaldos_mensuales', 'rendition', $r['id'], 'renditions' );
					break;
				case 'enviada_universidad':
				case 'en_carga':
				case 'en_autenticacion':
				case 'en_firma_interna':
					/* translators: mes. */
					$add( 'rendicion:' . $period, sprintf( __( 'Cargar, autenticar, firmar y enviar la rendición de %s', 'gestion-de-proyectos' ), $label ), sprintf( __( 'Estado actual: %s. Declare cada estado en el plugin con su fecha y el documento que la plataforma entrega.', 'gestion-de-proyectos' ), Profiles::get( (string) $agreement['profile'] )->rendition_statuses()[ $r['status'] ]['label'] ?? $r['status'] ), $t['platform_due'], $t['overdue'] ? 'alta' : 'media', 'sin_movimiento' === $r['kind'] ? 'rendicion_sin_movimiento' : ( self::prefers_bulk( $project_id, $r ) ? 'rendicion_carga_masiva' : 'rendicion_mensual' ), 'rendition', $r['id'], 'renditions' );
					break;
				case 'rendida':
				case 'en_revision':
					/* translators: mes. */
					$add( 'rendicion:' . $period, sprintf( __( 'Esperar la revisión de la rendición de %s y declarar su resultado', 'gestion-de-proyectos' ), $label ), __( 'Cuando el otorgante apruebe, apruebe parcialmente o devuelva, regístrelo con la fecha y el motivo de cada observación.', 'gestion-de-proyectos' ), null, 'baja', '', 'rendition', $r['id'], 'renditions' );
					break;
				case 'devuelta':
					/* translators: mes. */
					$add( 'rendicion:' . $period, sprintf( __( 'Corregir la rendición devuelta de %s', 'gestion-de-proyectos' ), $label ), __( 'Plazo de subsanación de 15 días hábiles; lo no subsanado pasa a rechazado, se rebaja del ítem y se reintegra.', 'gestion-de-proyectos' ), $r['fix_due'], 'alta', 'corregir_devuelta', 'rendition', $r['id'], 'renditions' );
					break;
				case 'aprobada_parcial':
					/* translators: mes. */
					$add( 'rendicion:' . $period, sprintf( __( 'Regularizar las transacciones observadas de %s', 'gestion-de-proyectos' ), $label ), __( 'Las transacciones observadas se presentan de nuevo en una rendición de regularización.', 'gestion-de-proyectos' ), $r['fix_due'], 'media', 'regularizacion', 'rendition', $r['id'], 'renditions' );
					break;
			}
		}

		$unregistered = self::unregistered_suppliers( $project_id );
		if ( ! empty( $unregistered ) ) {
			$add( 'proveedores', sprintf( /* translators: número de proveedores. */ _n( 'Registrar en la plataforma %d proveedor con pagos por rendir', 'Registrar en la plataforma %d proveedores con pagos por rendir', count( $unregistered ), 'gestion-de-proyectos' ), count( $unregistered ) ), implode( ', ', array_map( static fn( array $s ): string => (string) $s['name'], $unregistered ) ), null, 'media', 'registrar_proveedor', 'supplier', (int) $unregistered[0]['id'], 'payments' );
		}

		$next = $status['next_installment'];
		if ( $next ) {
			$gaps = $next['gaps'];
			if ( $gaps['pay_gap'] > 0 ) {
				$detail = sprintf( /* translators: 1: monto por pagar, 2: monto por rendir, 3: número de la cuota. */ __( 'Faltan %1$s por pagar y %2$s por rendir de la cuota %3$d.', 'gestion-de-proyectos' ), self::money( $gaps['pay_gap'] ), self::money( $gaps['render_gap'] ), $next['number'] - 1 );
				if ( $next['latest_month'] ) {
					$detail .= ' ' . sprintf( /* translators: 1: mes, 2: fecha límite para rendir, 3: fecha límite para facturar. */ __( 'Último mes de pago útil: %1$s (rendir antes del %2$s; facturar antes del %3$s).', 'gestion-de-proyectos' ), self::month_label( $next['latest_month'] ), (string) $next['latest_render_due'], (string) $next['invoice_by'] );
				}
				if ( ! empty( $next['candidates'] ) ) {
					$detail .= ' ' . sprintf( /* translators: lista de pagos candidatos. */ __( 'Candidatos: %s.', 'gestion-de-proyectos' ), implode( ', ', array_map( static fn( array $c ): string => $c['code'] . ' ' . self::money( $c['amount'] ), $next['candidates'] ) ) );
				}
				if ( $next['candidates_remaining'] > 0 ) {
					$detail .= ' ' . sprintf( /* translators: 1: monto sin cubrir, 2: monto de la garantía. */ __( 'Los compromisos registrados no alcanzan: quedan %1$s sin cubrir; la alternativa es una garantía por %2$s.', 'gestion-de-proyectos' ), self::money( $next['candidates_remaining'] ), self::money( $gaps['guarantee'] ) );
				}
				/* translators: 1: monto, 2: número de cuota. */
				$add( 'brecha', sprintf( __( 'Pagar y rendir %1$s para habilitar la cuota %2$d', 'gestion-de-proyectos' ), self::money( $gaps['pay_gap'] ), $next['number'] ), $detail, $next['latest_month_end'], 'alta', 'solicitar_cuota', 'installment', self::installment_id( $status, $next['number'] ), 'installments' );
			} elseif ( ! $next['all_met'] ) {
				$pending = array();
				foreach ( $next['conditions'] as $c ) {
					if ( false === $c['ok'] ) {
						$pending[] = $c['key'] . ': ' . $c['detail'];
					}
				}
				/* translators: número de cuota. */
				$add( 'condiciones', sprintf( __( 'Cumplir las condiciones pendientes de la cuota %d', 'gestion-de-proyectos' ), $next['number'] ), implode( ' ', $pending ), $next['window'][1] ?? null, 'alta', 'solicitar_cuota', 'installment', self::installment_id( $status, $next['number'] ), 'installments' );
			} else {
				/* translators: número de cuota. */
				$add( 'solicitar', sprintf( __( 'Solicitar la cuota %d', 'gestion-de-proyectos' ), $next['number'] ), __( 'Todas las condiciones evaluables se cumplen; remita la carta con el comprobante del aporte y el informe aprobado.', 'gestion-de-proyectos' ), $next['window'][1] ?? null, 'alta', 'solicitar_cuota', 'installment', self::installment_id( $status, $next['number'] ), 'installments' );
			}
		}

		foreach ( GuaranteeRepository::all( $project_id ) as $g ) {
			if ( 'vigente' === $g['status'] && $g['valid_until'] && $g['valid_until'] <= gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) ) ) {
				/* translators: 1: tipo de garantía, 2: fecha. */
				$add( 'garantia:' . $g['id'], sprintf( __( 'Renovar la garantía (%1$s) que vence el %2$s', 'gestion-de-proyectos' ), GuaranteeRepository::labels()['kinds'][ $g['kind'] ] ?? $g['kind'], (string) $g['valid_until'] ), self::money( (float) $g['amount'] ), $g['valid_until'], 'alta', '', 'guarantee', $g['id'], 'guarantees' );
			}
		}

		if ( ! $status['cash_plan'] ) {
			$add( 'caja', __( 'Registrar la programación de caja', 'gestion-de-proyectos' ), __( 'Transferencia solicitada, gasto programado y aporte por mes; el módulo aplica los seis controles.', 'gestion-de-proyectos' ), null, 'media', 'programacion_caja', 'project', 0, 'cash' );
		} else {
			$failing = array();
			foreach ( $status['cash_plan']['checks'] as $c ) {
				if ( false === $c['ok'] ) {
					$failing[] = $c['key'];
				}
			}
			if ( $failing ) {
				$add( 'caja', sprintf( /* translators: lista de controles. */ __( 'Revisar la programación de caja: fallan los controles %s', 'gestion-de-proyectos' ), implode( ', ', $failing ) ), __( 'Un plan que cumple las sumas pero no la caja ni la condición de giro no puede ocurrir tal como está.', 'gestion-de-proyectos' ), null, 'media', 'programacion_caja', 'project', 0, 'cash' );
			}
		}

		foreach ( $status['sources'] as $slug => $s ) {
			if ( ( null !== $s['difference'] && abs( $s['difference'] ) > 0.5 ) || $s['unmatched'] > 0 ) {
				$add( 'conciliacion:' . $slug, sprintf( /* translators: nombre de la fuente. */ __( 'Aclarar la conciliación de la fuente %s', 'gestion-de-proyectos' ), $s['label'] ), sprintf( /* translators: 1: diferencia, 2: cantidad de movimientos. */ _n( 'Diferencia %1$s; %2$d movimiento por aclarar.', 'Diferencia %1$s; %2$d movimientos por aclarar.', (int) $s['unmatched'], 'gestion-de-proyectos' ), null === $s['difference'] ? '—' : self::money( $s['difference'] ), (int) $s['unmatched'] ), null, 'media', '', 'project', 0, 'cash' );
			}
		}

		$order = array( 'alta' => 0, 'media' => 1, 'baja' => 2 );
		usort(
			$actions,
			static function ( array $a, array $b ) use ( $order ): int {
				return array( $order[ $a['severity'] ] ?? 9, null === $a['due'] ? 1 : 0, (string) $a['due'] ) <=> array( $order[ $b['severity'] ] ?? 9, null === $b['due'] ? 1 : 0, (string) $b['due'] );
			}
		);

		return $actions;
	}

	/**
	 * Hoja de ejecución: la guía con sus valores resueltos y sus pasos cumplidos.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $guide_key   Guía.
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @return array<string,mixed>|null
	 */
	public static function sheet( int $project_id, string $guide_key, string $entity_type, int $entity_id ): ?array {
		$agreement = AgreementRepository::get( $project_id );
		$profile   = Profiles::get( (string) $agreement['profile'] );
		$guide     = $profile->guides()[ $guide_key ] ?? null;
		if ( ! $guide ) {
			return null;
		}
		$ctx     = self::context( $project_id, $entity_type, $entity_id );
		$done    = $entity_id > 0 || 'project' === $entity_type ? EventRepository::steps_done( $entity_type, $entity_id, $guide_key ) : array();
		$missing = array();
		$labels  = array();
		$steps   = array();
		foreach ( $guide['steps'] as $step ) {
			$fields = array();
			foreach ( $step['fields'] ?? array() as $f ) {
				$before   = count( $missing );
				$value    = self::resolve( (string) $f['value'], $ctx, $missing );
				$fields[] = array( 'label' => $f['label'], 'value' => $value, 'file' => ! empty( $f['file'] ), 'copy' => empty( $f['file'] ) && '' !== $value );
				if ( count( $missing ) > $before ) {
					$labels[] = (string) $f['label'];
				}
			}
			$steps[] = array(
				'key'         => $step['key'],
				'actor'       => $step['actor'],
				'screen'      => $step['screen'],
				'instruction' => $step['instruction'],
				'fields'      => $fields,
				'check'       => isset( $step['check'] ) ? self::resolve( (string) $step['check'], $ctx, $missing ) : '',
				'produces'    => $step['produces'] ?? '',
				'review'      => ! empty( $step['review'] ),
				'per_payment' => ! empty( $step['per_payment'] ),
				'payments'    => ! empty( $step['per_payment'] ) ? ( $ctx['_payment_sheets'] ?? array() ) : array(),
				'done'        => $done[ $step['key'] ] ?? null,
			);
		}

		return array(
			'key'           => $guide_key,
			'label'         => $guide['label'],
			'source'        => $guide['source'],
			'actors'        => $guide['actors'],
			'entity_type'   => $entity_type,
			'entity_id'     => $entity_id,
			'steps'         => $steps,
			'missing'       => array_values( array_unique( $missing ) ),
			'missing_labels' => array_values( array_unique( $labels ) ),
			'result'        => $guide['result'] ?? array(),
			'links'         => $profile->links(),
		);
	}

	/**
	 * Contexto de datos para resolver una hoja.
	 *
	 * @param int    $project_id  Proyecto.
	 * @param string $entity_type Entidad.
	 * @param int    $entity_id   Identificador.
	 * @return array<string,mixed>
	 */
	public static function context( int $project_id, string $entity_type, int $entity_id ): array {
		$project   = \GDP\Domain\Projects\ProjectRepository::find( $project_id );
		$status    = FinanceService::status( $project_id );
		$agreement = $status['agreement'];
		$profile   = Profiles::get( (string) $agreement['profile'] );
		$ctx       = array(
			'proyecto' => array( 'name' => $project ? $project['name'] : '', 'code' => $project ? $project['code'] : '', 'platform_code' => $agreement['platform_code'] ),
			'convenio' => $agreement,
			'hoy'      => $status['today'],
			'estado'   => array( 'closing_difference' => $status['closing_difference'] ),
		);
		if ( 'installment' === $entity_type && $entity_id > 0 ) {
			foreach ( $status['installments'] as $i ) {
				if ( $i['id'] === $entity_id ) {
					$i['document'] = self::document_url( (int) $i['document_id'] );
					$i['window']   = trim( (string) $i['window_from'] . ' a ' . (string) $i['window_to'], ' a' );
					$next          = $status['next_installment'];
					if ( $next && $next['number'] === $i['number'] ) {
						$met = array();
						$pending = array();
						foreach ( $next['conditions'] as $c ) {
							if ( true === $c['ok'] ) {
								$met[] = $c['key'];
							} elseif ( false === $c['ok'] ) {
								$pending[] = $c['key'] . ' (' . $c['detail'] . ')';
							}
						}
						$i['conditions_met']     = implode( ', ', $met );
						$i['conditions_pending'] = $pending ? implode( '; ', $pending ) : __( 'ninguna', 'gestion-de-proyectos' );
					}
					$ctx['cuota'] = $i;
				}
			}
		}
		if ( 'rendition' === $entity_type ) {
			$r = $entity_id > 0 ? RenditionRepository::find( $entity_id ) : null;
			if ( $r ) {
				$payments  = PaymentRepository::list( $project_id, array( 'rendition_id' => $r['id'] ) );
				$suppliers = array();
				$items     = ItemRepository::by_slug( $project_id );
				$sheets    = array();
				$total     = 0.0;
				$incomplete = 0;
				$observed  = array();
				$unreg     = array();
				$originals = array();
				foreach ( $payments as $p ) {
					$total += (float) $p['amount'];
					$supplier = $p['supplier_id'] > 0 ? ( $suppliers[ $p['supplier_id'] ] ?? SupplierRepository::find( (int) $p['supplier_id'] ) ) : null;
					if ( $supplier ) {
						$suppliers[ $p['supplier_id'] ] = $supplier;
						if ( ! FinanceService::supplier_registered( $supplier ) ) {
							$unreg[ $supplier['id'] ] = $supplier['name'];
						}
					}
					$issues = FinanceService::validate_payment( $p, $status );
					if ( ! PaymentValidator::complete( $issues ) ) {
						++$incomplete;
					}
					if ( in_array( $p['status'], array( 'observado', 'corregido' ), true ) ) {
						$observed[] = $p['code'] . ( $p['observation'] ? ': ' . $p['observation'] : '' );
					}
					$originals[] = sprintf( '%s %s (%s)', $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'], $p['doc_number'], self::money( (float) $p['amount'] ) );
					$sheets[]    = self::payment_sheet( $p, $supplier, $items[ $p['item_slug'] ] ?? null, $profile, $status );
				}
				$bulk_problems = BulkLoad::problems( self::bulk_payments( $payments, $suppliers, $items ), InstallmentRepository::amounts( $project_id ) );
				$ctx['rendicion'] = $r + array(
					'payments_count'         => count( $payments ),
					'total'                  => $total,
					'incomplete_count'       => $incomplete,
					'observed_count'         => count( $observed ),
					'observed_list'          => $observed ? implode( '; ', $observed ) : __( 'ninguna', 'gestion-de-proyectos' ),
					'unregistered_suppliers' => $unreg ? implode( ', ', $unreg ) : __( 'ninguno', 'gestion-de-proyectos' ),
					'originals'              => $originals ? implode( '; ', $originals ) : '—',
					'summary'                => sprintf( /* translators: 1: número de transacciones, 2: monto total, 3: mes. */ __( '%1$d transacciones por %2$s, período %3$s', 'gestion-de-proyectos' ), count( $payments ), self::money( $total ), self::month_label( (string) $r['period'] ) ),
					'bulk_sheet'             => FinancePage::export_url( $project_id, 'bulk_sheet', $r['id'] ),
					'bulk_zip'               => FinancePage::export_url( $project_id, 'bulk_zip', $r['id'] ),
					'bulk_zip_name'          => strtoupper( self::month_abbr( (string) $r['period'] ) ) . '-' . substr( (string) $r['period'], 0, 4 ) . '.zip',
					'bulk_problems'          => $bulk_problems ? implode( ' ', $bulk_problems ) : __( 'ninguno', 'gestion-de-proyectos' ),
					'zero_letter'            => FinancePage::export_url( $project_id, 'zero_letter', $r['id'] ),
					'expedient'              => FinancePage::export_url( $project_id, 'expedient', $r['id'] ),
				);
				$ctx['_payment_sheets'] = $sheets;
			} else {
				$ctx['rendicion'] = array( 'period' => '', 'payments_count' => 0, 'total' => 0 );
			}
		}
		if ( 'supplier' === $entity_type && $entity_id > 0 ) {
			$s = SupplierRepository::find( $entity_id );
			if ( $s ) {
				$body             = (int) Transliterator::tax_id_body( (string) $s['tax_id'] );
				$s['kind']        = $body > 0 && $body < 50000000 ? __( 'Persona natural', 'gestion-de-proyectos' ) : __( 'Persona jurídica', 'gestion-de-proyectos' );
				$ctx['proveedor'] = $s;
			}
		}
		if ( $status['cash_plan'] ) {
			$checks = array();
			foreach ( $status['cash_plan']['checks'] as $c ) {
				$checks[] = $c['key'] . ' ' . ( null === $c['ok'] ? '—' : ( $c['ok'] ? 'ok' : 'falla' ) );
			}
			$ctx['plan'] = array( 'checks' => implode( ', ', $checks ), 'sheet' => FinancePage::export_url( $project_id, 'cash_plan', (int) $status['cash_plan']['plan']['id'] ) );
		} else {
			$ctx['plan'] = array( 'checks' => __( 'sin programación', 'gestion-de-proyectos' ), 'sheet' => '' );
		}

		return $ctx;
	}

	/**
	 * Hoja del paso "Documento" de la plataforma para un pago: campos en el
	 * orden de la pantalla, con el valor ya formateado.
	 *
	 * @param array<string,mixed>      $p        Pago.
	 * @param array<string,mixed>|null $supplier Proveedor.
	 * @param array<string,mixed>|null $item     Ítem.
	 * @param Profiles\Profile         $profile  Perfil.
	 * @param array<string,mixed>      $status   Estado.
	 * @return array<string,mixed>
	 */
	public static function payment_sheet( array $p, ?array $supplier, ?array $item, $profile, array $status ): array {
		$effective = PaymentRepository::effective_installments( (int) $p['project_id'] );
		$support   = array();
		foreach ( $p['support'] as $s ) {
			$support[] = ( $profile->support_kinds()[ $s['kind'] ] ?? $s['kind'] ) . ( ! empty( $s['document_id'] ) ? ' (' . self::document_url( (int) $s['document_id'] ) . ')' : '' );
		}
		$fields = array(
			array( 'label' => __( 'Comprobante de egreso: número', 'gestion-de-proyectos' ), 'value' => (string) $p['egress_number'], 'hint' => __( 'primero el egreso', 'gestion-de-proyectos' ) ),
			array( 'label' => __( 'Comprobante de egreso: fecha', 'gestion-de-proyectos' ), 'value' => BulkLoad::date( (string) $p['paid_at'] ) ),
			array( 'label' => __( 'Comprobante de egreso: archivo', 'gestion-de-proyectos' ), 'value' => self::document_url( (int) $p['egress_document_id'] ), 'file' => true, 'hint' => __( 'descargar y subir', 'gestion-de-proyectos' ) ),
			array( 'label' => __( 'Proveedor (rol sin puntos ni dígito)', 'gestion-de-proyectos' ), 'value' => 'documento_extranjero' === $p['doc_type'] ? (string) ( $supplier['name'] ?? '' ) : Transliterator::tax_id_body( (string) ( $supplier['tax_id'] ?? '' ) ), 'hint' => 'documento_extranjero' === $p['doc_type'] ? __( 'proveedor extranjero: nombre', 'gestion-de-proyectos' ) : __( 'auricular, buscar', 'gestion-de-proyectos' ) ),
			array( 'label' => __( 'Tipo de documento', 'gestion-de-proyectos' ), 'value' => $profile->doc_types()[ $p['doc_type'] ] ?? $p['doc_type'] ),
			array( 'label' => __( 'Número del documento', 'gestion-de-proyectos' ), 'value' => (string) $p['doc_number'] ),
			array( 'label' => __( 'Fecha del documento', 'gestion-de-proyectos' ), 'value' => BulkLoad::date( (string) $p['doc_date'] ) ),
			array( 'label' => __( 'Monto', 'gestion-de-proyectos' ), 'value' => (string) (int) round( (float) $p['amount'] ), 'hint' => __( 'sin separadores', 'gestion-de-proyectos' ) ),
			array( 'label' => __( 'Tipo de gasto; subclasificación', 'gestion-de-proyectos' ), 'value' => ( $profile->platform_types()[ $item['platform_type'] ?? 'operacion' ] ?? 'Operación' ) . '; ' . (string) ( $item['platform_subclass'] ?? '' ), 'hint' => __( 'proyección del perfil', 'gestion-de-proyectos' ) ),
			array( 'label' => __( 'Transferencia', 'gestion-de-proyectos' ), 'value' => (string) ( $effective[ $p['id'] ] ?? max( 1, (int) $p['installment_no'] ) ), 'hint' => __( 'cuota con saldo', 'gestion-de-proyectos' ) ),
			array( 'label' => __( 'Respaldos', 'gestion-de-proyectos' ), 'value' => $support ? implode( '; ', $support ) : '—', 'hint' => __( 'carpeta T', 'gestion-de-proyectos' ) ),
		);
		$issues = FinanceService::validate_payment( $p, $status );

		return array( 'id' => $p['id'], 'code' => $p['code'], 'description' => $p['description'], 'fields' => $fields, 'issues' => $issues, 'blocks' => PaymentValidator::blocks( $issues ) );
	}

	/**
	 * Pagos en la forma que espera la lógica de carga masiva.
	 *
	 * @param array<int,array<string,mixed>>    $payments  Pagos.
	 * @param array<int,array<string,mixed>>    $suppliers Proveedores por id.
	 * @param array<string,array<string,mixed>> $items     Ítems por slug.
	 * @return array<int,array<string,mixed>>
	 */
	public static function bulk_payments( array $payments, array $suppliers, array $items ): array {
		$out       = array();
		$effective = ! empty( $payments ) ? PaymentRepository::effective_installments( (int) $payments[0]['project_id'] ) : array();
		foreach ( $payments as $p ) {
			$supplier = $p['supplier_id'] > 0 ? ( $suppliers[ $p['supplier_id'] ] ?? SupplierRepository::find( (int) $p['supplier_id'] ) ) : null;
			$files    = array();
			foreach ( $p['support'] as $s ) {
				$path = self::document_path( (int) ( $s['document_id'] ?? 0 ) );
				if ( $path ) {
					$files[] = $path;
				}
			}
			$out[] = array(
				'id'                  => $p['id'],
				'supplier_name'       => (string) ( $supplier['name'] ?? '' ),
				'supplier_tax_id'     => (string) ( $supplier['tax_id'] ?? '' ),
				'supplier_registered' => $supplier ? FinanceService::supplier_registered( $supplier ) : false,
				'doc_type'            => $p['doc_type'],
				'doc_number'          => $p['doc_number'],
				'doc_date'            => (string) $p['doc_date'],
				'amount'              => (float) $p['amount'],
				'installment_no'      => $effective[ $p['id'] ] ?? max( 1, (int) $p['installment_no'] ),
				'platform_type'       => (string) ( $items[ $p['item_slug'] ]['platform_type'] ?? 'operacion' ),
				'platform_subclass'   => (string) ( $items[ $p['item_slug'] ]['platform_subclass'] ?? '' ),
				'description'         => $p['description'],
				'egress_number'       => $p['egress_number'],
				'paid_at'             => (string) $p['paid_at'],
				'egress_file'         => self::document_path( (int) $p['egress_document_id'] ),
				'support_files'       => $files,
			);
		}

		return $out;
	}

	/**
	 * Resuelve los marcadores {clave.campo|formato} con el contexto.
	 *
	 * @param string              $template Plantilla.
	 * @param array<string,mixed> $ctx      Contexto.
	 * @param string[]            $missing  Acumulador de datos ausentes.
	 * @return string
	 */
	public static function resolve( string $template, array $ctx, array &$missing ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\.([a-z_]+)(?:\|([a-z_]+))?\}/',
			static function ( array $m ) use ( $ctx, &$missing ): string {
				$value = $ctx[ $m[1] ][ $m[2] ] ?? null;
				if ( null === $value || '' === $value ) {
					$missing[] = $m[1] . '.' . $m[2];
					return '';
				}
				return self::format( $value, $m[3] ?? '' );
			},
			$template
		);
	}

	/**
	 * Aplica un formato.
	 *
	 * @param mixed  $value  Valor.
	 * @param string $format Formato.
	 * @return string
	 */
	public static function format( $value, string $format ): string {
		switch ( $format ) {
			case 'money':
				return (string) (int) round( (float) $value );
			case 'money_pretty':
				return self::money( (float) $value );
			case 'fecha':
				return BulkLoad::date( (string) $value );
			case 'mes':
				return self::month_label( (string) $value );
			case 'rut':
				return Transliterator::tax_id_body( (string) $value );
			case 'rut_pretty':
				return Transliterator::tax_id_pretty( (string) $value );
			case 'ascii':
				return Transliterator::ascii( (string) $value );
			default:
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}
	}

	/**
	 * Pagos pagados del período con respaldos incompletos.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param string              $period     AAAA-MM.
	 * @param array<string,mixed> $status     Estado.
	 * @return int
	 */
	private static function incomplete_payments( int $project_id, string $period, array $status ): int {
		$n = 0;
		foreach ( PaymentRepository::list( $project_id, array( 'period' => $period, 'statuses' => array( 'pagado' ) ) ) as $p ) {
			if ( ! PaymentValidator::complete( FinanceService::validate_payment( $p, $status ) ) ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Proveedores de pagos por rendir que no están registrados en la plataforma.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function unregistered_suppliers( int $project_id ): array {
		$out = array();
		foreach ( PaymentRepository::list( $project_id, array( 'statuses' => array( 'pagado', 'devengado', 'comprometido' ) ) ) as $p ) {
			if ( $p['supplier_id'] <= 0 || isset( $out[ $p['supplier_id'] ] ) || 'documento_extranjero' === $p['doc_type'] ) {
				continue;
			}
			$s = SupplierRepository::find( (int) $p['supplier_id'] );
			if ( $s && ! FinanceService::supplier_registered( $s ) ) {
				$out[ $p['supplier_id'] ] = $s;
			}
		}

		return array_values( $out );
	}

	/**
	 * Indica si la rendición conviene cargarla masivamente (varias transacciones).
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $r          Rendición.
	 * @return bool
	 */
	private static function prefers_bulk( int $project_id, array $r ): bool {
		return count( PaymentRepository::list( $project_id, array( 'rendition_id' => $r['id'] ) ) ) >= 5;
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

	/**
	 * URL de descarga de la versión vigente de un documento.
	 *
	 * @param int $document_id Documento.
	 * @return string
	 */
	public static function document_url( int $document_id ): string {
		if ( $document_id <= 0 ) {
			return '';
		}
		$versions = VersionRepository::for_document( $document_id );
		$last     = end( $versions );

		return $last ? VersionRepository::download_url( $last ) : '';
	}

	/**
	 * Ruta en disco de la versión vigente de un documento.
	 *
	 * @param int $document_id Documento.
	 * @return string|null
	 */
	public static function document_path( int $document_id ): ?string {
		if ( $document_id <= 0 ) {
			return null;
		}
		$versions = VersionRepository::for_document( $document_id );
		$last     = end( $versions );
		if ( ! $last ) {
			return null;
		}
		$path = trailingslashit( \GDP\Core\Storage::private_dir() ) . (string) $last['path'];

		return is_readable( $path ) ? $path : null;
	}

	/**
	 * Nombre del mes en español.
	 *
	 * @param string $period AAAA-MM.
	 * @return string
	 */
	public static function month_label( string $period ): string {
		$months = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$m      = (int) substr( $period, 5, 2 );

		return ( $months[ $m - 1 ] ?? $period ) . ' ' . substr( $period, 0, 4 );
	}

	/**
	 * Abreviatura del mes.
	 *
	 * @param string $period AAAA-MM.
	 * @return string
	 */
	public static function month_abbr( string $period ): string {
		$months = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );

		return $months[ (int) substr( $period, 5, 2 ) - 1 ] ?? 'mes';
	}

	/**
	 * Formato de pesos.
	 *
	 * @param float $amount Monto.
	 * @return string
	 */
	public static function money( float $amount ): string {
		return '$' . number_format( $amount, 0, ',', '.' );
	}
}
