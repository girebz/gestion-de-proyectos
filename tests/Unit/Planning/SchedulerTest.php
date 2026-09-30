<?php
/**
 * Pruebas del motor de programación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Planning;

use GDP\Planning\Scheduler;
use GDP\Planning\WorkCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Proyecto de referencia: inicio el lunes 5 de octubre de 2026, semana de
 * lunes a viernes sin feriados. Índices: 0 = 5 oct, 4 = 9 oct, 5 = 12 oct,
 * 9 = 16 oct, 10 = 19 oct, 14 = 23 oct.
 */
final class SchedulerTest extends TestCase {

	/**
	 * Motor de referencia.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Preparación.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->scheduler = new Scheduler( new WorkCalendar(), '2026-10-05' );
	}

	/**
	 * Actividad de prueba.
	 *
	 * @param int                  $id       Identificador.
	 * @param int                  $duration Duración.
	 * @param array<string,mixed>  $extra    Campos adicionales.
	 * @return array<string,mixed>
	 */
	private static function act( int $id, int $duration, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => $id,
				'parent_id' => 0,
				'kind'      => 'activity',
				'duration'  => $duration,
			),
			$extra
		);
	}

	/**
	 * Hito de prueba.
	 *
	 * @param int                 $id    Identificador.
	 * @param array<string,mixed> $extra Campos adicionales.
	 * @return array<string,mixed>
	 */
	private static function milestone( int $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => $id,
				'parent_id' => 0,
				'kind'      => 'milestone',
				'duration'  => 0,
			),
			$extra
		);
	}

	/**
	 * Dependencia de prueba.
	 *
	 * @param int    $pred Predecesora.
	 * @param int    $succ Sucesora.
	 * @param string $type Tipo.
	 * @param int    $lag  Retraso.
	 * @return array<string,mixed>
	 */
	private static function dep( int $pred, int $succ, string $type = 'FS', int $lag = 0 ): array {
		return array(
			'predecessor_id' => $pred,
			'successor_id'   => $succ,
			'type'           => $type,
			'lag'            => $lag,
		);
	}

	public function test_index_day_and_duration_helpers(): void {
		$this->assertSame( 0, $this->scheduler->index( '2026-10-05' ) );
		$this->assertSame( 5, $this->scheduler->index( '2026-10-12' ) );
		$this->assertSame( 5, $this->scheduler->index( '2026-10-10' ), 'el sábado se normaliza al lunes' );
		$this->assertSame( -1, $this->scheduler->index( '2026-10-02' ) );
		$this->assertSame( '2026-10-12', $this->scheduler->day( 5 ) );
		$this->assertSame( '2026-10-02', $this->scheduler->day( -1 ) );
		$this->assertSame( 5, $this->scheduler->duration_between( '2026-10-05', '2026-10-09' ) );
		$this->assertSame( 5, $this->scheduler->duration_between( '2026-10-05', '2026-10-10' ), 'el fin de semana no cuenta' );
		$this->assertSame( 0, $this->scheduler->duration_between( '2026-10-09', '2026-10-05' ) );
	}

	public function test_finish_to_start_chain_with_floats_and_critical_path(): void {
		$result = $this->scheduler->schedule(
			array( self::act( 1, 3 ), self::act( 2, 2 ), self::act( 3, 4 ), self::milestone( 4 ) ),
			array( self::dep( 1, 2 ), self::dep( 1, 3 ), self::dep( 2, 4 ), self::dep( 3, 4 ) )
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertNull( $result['cycle'] );
		$this->assertSame( array( 1, 2, 3, 4 ), $result['order'] );

		$n = $result['nodes'];
		$this->assertSame( array( 0, 3 ), array( $n[1]['s'], $n[1]['f'] ) );
		$this->assertSame( array( 3, 5 ), array( $n[2]['s'], $n[2]['f'] ) );
		$this->assertSame( array( 3, 7 ), array( $n[3]['s'], $n[3]['f'] ) );
		$this->assertSame( array( 7, 7 ), array( $n[4]['s'], $n[4]['f'] ) );

		$this->assertSame( '2026-10-05', $n[1]['start_date'] );
		$this->assertSame( '2026-10-07', $n[1]['end_date'] );
		$this->assertSame( '2026-10-08', $n[2]['start_date'] );
		$this->assertSame( '2026-10-09', $n[2]['end_date'] );
		$this->assertSame( '2026-10-08', $n[3]['start_date'] );
		$this->assertSame( '2026-10-13', $n[3]['end_date'] );
		$this->assertSame( '2026-10-13', $n[4]['start_date'], 'el hito se fecha el mismo día en que termina su predecesora' );
		$this->assertSame( '2026-10-13', $n[4]['end_date'] );

		$this->assertSame( 0, $n[1]['total_float'] );
		$this->assertSame( 2, $n[2]['total_float'] );
		$this->assertSame( 2, $n[2]['free_float'] );
		$this->assertSame( 0, $n[3]['total_float'] );
		$this->assertSame( 0, $n[4]['total_float'] );
		$this->assertTrue( $n[1]['critical'] );
		$this->assertFalse( $n[2]['critical'] );
		$this->assertTrue( $n[3]['critical'] );
		$this->assertTrue( $n[4]['critical'] );
		$this->assertSame( '2026-10-12', $n[2]['late_start'] );
		$this->assertSame( '2026-10-13', $n[2]['late_finish'] );

		$this->assertSame( 7, $result['project']['finish_boundary'] );
		$this->assertSame( '2026-10-05', $result['project']['start_date'] );
		$this->assertSame( '2026-10-13', $result['project']['finish_date'] );
		$this->assertNull( $result['project']['deadline_date'] );
	}

	public function test_start_to_start_with_lag(): void {
		$n = $this->scheduler->schedule(
			array( self::act( 1, 5 ), self::act( 2, 3 ) ),
			array( self::dep( 1, 2, 'SS', 2 ) )
		)['nodes'];

		$this->assertSame( array( 2, 5 ), array( $n[2]['s'], $n[2]['f'] ) );
		$this->assertSame( '2026-10-07', $n[2]['start_date'] );
		$this->assertSame( '2026-10-09', $n[2]['end_date'] );
		$this->assertSame( 0, $n[1]['total_float'] );
		$this->assertSame( 0, $n[2]['total_float'] );
		$this->assertSame( 0, $n[1]['free_float'] );
	}

	public function test_finish_to_finish_with_lag(): void {
		$n = $this->scheduler->schedule(
			array( self::act( 1, 5 ), self::act( 2, 2 ) ),
			array( self::dep( 1, 2, 'FF', 1 ) )
		)['nodes'];

		$this->assertSame( array( 4, 6 ), array( $n[2]['s'], $n[2]['f'] ) );
		$this->assertSame( '2026-10-09', $n[2]['start_date'] );
		$this->assertSame( '2026-10-12', $n[2]['end_date'] );
		$this->assertSame( 0, $n[1]['total_float'] );
		$this->assertSame( 0, $n[2]['total_float'] );
		$this->assertSame( 5, $n[1]['lf'] );
	}

	public function test_start_to_finish_with_lag(): void {
		$n = $this->scheduler->schedule(
			array( self::act( 1, 3 ), self::act( 2, 2 ) ),
			array( self::dep( 1, 2, 'SF', 4 ) )
		)['nodes'];

		$this->assertSame( array( 2, 4 ), array( $n[2]['s'], $n[2]['f'] ) );
		$this->assertSame( 0, $n[2]['free_float'] );
	}

	public function test_negative_lag_is_a_lead(): void {
		$n = $this->scheduler->schedule(
			array( self::act( 1, 5 ), self::act( 2, 3 ) ),
			array( self::dep( 1, 2, 'FS', -2 ) )
		)['nodes'];

		$this->assertSame( array( 3, 6 ), array( $n[2]['s'], $n[2]['f'] ) );
		$this->assertSame( '2026-10-08', $n[2]['start_date'] );
	}

	public function test_milestone_conventions(): void {
		$n = $this->scheduler->schedule(
			array( self::milestone( 1 ), self::act( 2, 2 ), self::act( 3, 2 ), self::milestone( 4 ) ),
			array( self::dep( 1, 2 ), self::dep( 3, 4 ) )
		)['nodes'];

		$this->assertSame( 1, $n[1]['f'], 'un hito sin predecesoras se alcanza al terminar el día 0' );
		$this->assertSame( '2026-10-05', $n[1]['start_date'] );
		$this->assertSame( '2026-10-06', $n[2]['start_date'], 'la actividad tras un hito empieza al día siguiente' );
		$this->assertSame( '2026-10-06', $n[3]['end_date'] );
		$this->assertSame( '2026-10-06', $n[4]['start_date'], 'el hito tras una actividad se fecha el día en que esta termina' );
		$this->assertSame( 0, $n[4]['duration'] );
	}

	public function test_start_no_earlier_than_and_finish_no_earlier_than(): void {
		$n = $this->scheduler->schedule(
			array(
				self::act( 1, 2, array( 'constraint_type' => 'snet', 'constraint_date' => '2026-10-08' ) ),
				self::act( 2, 2, array( 'constraint_type' => 'fnet', 'constraint_date' => '2026-10-09' ) ),
				self::milestone( 3, array( 'constraint_type' => 'snet', 'constraint_date' => '2026-10-14' ) ),
			),
			array()
		)['nodes'];

		$this->assertSame( '2026-10-08', $n[1]['start_date'] );
		$this->assertSame( '2026-10-09', $n[1]['end_date'] );
		$this->assertFalse( $n[1]['fixed'] );
		$this->assertSame( '2026-10-08', $n[2]['start_date'] );
		$this->assertSame( '2026-10-09', $n[2]['end_date'] );
		$this->assertSame( '2026-10-14', $n[3]['start_date'] );
		$this->assertSame( array(), $n[1]['conflicts'] );
	}

	public function test_mandatory_constraints_are_honored_and_conflicts_reported(): void {
		$n = $this->scheduler->schedule(
			array(
				self::act( 1, 5 ),
				self::act( 2, 2, array( 'constraint_type' => 'mso', 'constraint_date' => '2026-10-07' ) ),
				self::act( 3, 2, array( 'constraint_type' => 'mfo', 'constraint_date' => '2026-10-09' ) ),
				self::milestone( 4, array( 'constraint_type' => 'mfo', 'constraint_date' => '2026-10-16' ) ),
			),
			array( self::dep( 1, 2 ) )
		)['nodes'];

		$this->assertSame( 2, $n[2]['s'], 'la fecha obligatoria prevalece sobre la predecesora' );
		$this->assertTrue( $n[2]['fixed'] );
		$this->assertCount( 1, $n[2]['conflicts'] );
		$this->assertStringContainsString( '3 día(s)', $n[2]['conflicts'][0] );
		$this->assertSame( 0, $n[2]['total_float'] );
		$this->assertTrue( $n[2]['critical'] );

		$this->assertSame( '2026-10-08', $n[3]['start_date'] );
		$this->assertSame( '2026-10-09', $n[3]['end_date'] );
		$this->assertTrue( $n[3]['fixed'] );
		$this->assertSame( array(), $n[3]['conflicts'] );

		$this->assertSame( '2026-10-16', $n[4]['start_date'] );
		$this->assertSame( 10, $n[4]['f'] );
	}

	public function test_no_later_than_constraints_reduce_float(): void {
		$result = $this->scheduler->schedule(
			array(
				self::act( 1, 2, array( 'constraint_type' => 'snlt', 'constraint_date' => '2026-10-07' ) ),
				self::act( 2, 2, array( 'constraint_type' => 'fnlt', 'constraint_date' => '2026-10-08' ) ),
				self::act( 3, 2, array( 'constraint_type' => 'fnlt', 'constraint_date' => '2026-10-05' ) ),
				self::act( 9, 10 ),
			),
			array()
		);
		$n = $result['nodes'];

		$this->assertSame( 10, $result['project']['finish_boundary'] );
		$this->assertSame( 2, $n[1]['total_float'] );
		$this->assertSame( '2026-10-07', $n[1]['late_start'] );
		$this->assertSame( 2, $n[2]['total_float'] );
		$this->assertSame( '2026-10-08', $n[2]['late_finish'] );
		$this->assertSame( -1, $n[3]['total_float'] );
		$this->assertTrue( $n[3]['critical'] );
		$this->assertCount( 1, $n[3]['conflicts'] );
		$this->assertStringContainsString( 'holgura negativa', $n[3]['conflicts'][0] );
		$this->assertSame( 0, $n[9]['total_float'] );
	}

	public function test_actual_dates(): void {
		$n = $this->scheduler->schedule(
			array(
				self::act( 1, 3, array( 'actual_start' => '2026-10-06' ) ),
				self::act( 2, 3, array( 'actual_start' => '2026-10-06', 'actual_finish' => '2026-10-07' ) ),
				self::act( 3, 3, array( 'actual_finish' => '2026-10-09' ) ),
				self::act( 4, 3, array( 'actual_start' => '2026-10-09', 'actual_finish' => '2026-10-06' ) ),
				self::milestone( 5, array( 'actual_start' => '2026-10-08' ) ),
				self::act( 9, 10 ),
			),
			array()
		)['nodes'];

		// En curso: inicio fijo, término con margen.
		$this->assertSame( array( 1, 4 ), array( $n[1]['s'], $n[1]['f'] ) );
		$this->assertTrue( $n[1]['fixed'] );
		$this->assertSame( 6, $n[1]['total_float'] );
		$this->assertFalse( $n[1]['critical'] );
		$this->assertSame( '2026-10-06', $n[1]['late_start'] );

		// Terminada: duración real y sin margen.
		$this->assertSame( array( 1, 3 ), array( $n[2]['s'], $n[2]['f'] ) );
		$this->assertSame( 2, $n[2]['duration'] );
		$this->assertSame( 0, $n[2]['total_float'] );
		$this->assertFalse( $n[2]['critical'], 'terminada: sin holgura pero ya no es crítica' );

		// Solo término real: el inicio se deduce de la duración.
		$this->assertSame( '2026-10-07', $n[3]['start_date'] );
		$this->assertSame( '2026-10-09', $n[3]['end_date'] );

		// Término anterior al inicio: conflicto y duración nula.
		$this->assertSame( $n[4]['s'], $n[4]['f'] );
		$this->assertCount( 1, $n[4]['conflicts'] );

		// Hito alcanzado.
		$this->assertSame( '2026-10-08', $n[5]['start_date'] );
		$this->assertTrue( $n[5]['fixed'] );
	}

	public function test_actual_start_before_predecessor_is_a_conflict(): void {
		$n = $this->scheduler->schedule(
			array( self::act( 1, 5 ), self::act( 2, 2, array( 'actual_start' => '2026-10-07' ) ) ),
			array( self::dep( 1, 2 ) )
		)['nodes'];

		$this->assertSame( 2, $n[2]['s'] );
		$this->assertCount( 1, $n[2]['conflicts'] );
	}

	public function test_cycle_is_detected(): void {
		$result = $this->scheduler->schedule(
			array( self::act( 1, 1 ), self::act( 2, 1 ), self::act( 3, 1 ), self::act( 4, 1 ) ),
			array( self::dep( 1, 2 ), self::dep( 2, 3 ), self::dep( 3, 1 ), self::dep( 1, 4 ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 1, 2, 3, 4 ), $result['cycle'], 'todo lo que no pudo ordenarse se informa' );
		$this->assertSame( array(), $result['nodes'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'ciclo', $result['errors'][0] );
	}

	public function test_invalid_dependencies_are_reported_but_ignored(): void {
		$result = $this->scheduler->schedule(
			array(
				array( 'id' => 10, 'parent_id' => 0, 'kind' => 'summary' ),
				self::act( 1, 2, array( 'parent_id' => 10 ) ),
				self::act( 2, 2 ),
			),
			array(
				self::dep( 1, 1 ),
				self::dep( 10, 2 ),
				self::dep( 1, 2, 'XX' ),
				self::dep( 1, 99 ),
				self::dep( 1, 2 ),
				self::dep( 1, 2, 'SS', 1 ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertCount( 4, $result['errors'] );
		$this->assertSame( 2, $result['nodes'][2]['s'], 'la dependencia válida se aplica; la duplicada se descarta' );
	}

	public function test_summary_rollup_and_progress(): void {
		$result = $this->scheduler->schedule(
			array(
				array( 'id' => 100, 'parent_id' => 0, 'kind' => 'summary' ),
				array( 'id' => 10, 'parent_id' => 100, 'kind' => 'summary' ),
				array( 'id' => 20, 'parent_id' => 100, 'kind' => 'summary' ),
				self::act( 1, 3, array( 'parent_id' => 10, 'percent' => 100 ) ),
				self::act( 2, 2, array( 'parent_id' => 10, 'percent' => 50 ) ),
				self::act( 3, 4, array( 'parent_id' => 0, 'percent' => 0 ) ),
				array( 'id' => 30, 'parent_id' => 0, 'kind' => 'summary' ),
				self::milestone( 7, array( 'parent_id' => 30, 'percent' => 100 ) ),
				self::milestone( 8, array( 'parent_id' => 30, 'percent' => 0 ) ),
			),
			array( self::dep( 1, 2 ) )
		);
		$n = $result['nodes'];

		$this->assertTrue( $n[10]['is_summary'] );
		$this->assertSame( array( 0, 5 ), array( $n[10]['s'], $n[10]['f'] ) );
		$this->assertSame( 5, $n[10]['duration'] );
		$this->assertSame( '2026-10-05', $n[10]['start_date'] );
		$this->assertSame( '2026-10-09', $n[10]['end_date'] );
		$this->assertSame( 80, $n[10]['percent'], 'ponderado por duración: (3·100 + 2·50)/5' );
		$this->assertTrue( $n[10]['critical'] );
		$this->assertSame( 0, $n[10]['total_float'] );

		$this->assertSame( array( 0, 0 ), array( $n[20]['s'], $n[20]['f'] ), 'resumen vacío' );
		$this->assertSame( 0, $n[20]['percent'] );

		$this->assertSame( array( 0, 5 ), array( $n[100]['s'], $n[100]['f'] ), 'resumen anidado' );
		$this->assertSame( 80, $n[100]['percent'] );

		$this->assertSame( 100, $n[1]['percent'] );
		$this->assertFalse( $n[3]['critical'] );
		$this->assertSame( 1, $n[3]['total_float'] );

		$this->assertSame( 50, $n[30]['percent'], 'resumen de hitos: promedio simple' );
		$this->assertSame( 0, $n[30]['weight'] );
	}

	public function test_project_deadline_slack(): void {
		$activities = array( self::act( 1, 5 ) );

		$ok = ( new Scheduler( new WorkCalendar(), '2026-10-05', '2026-10-16' ) )->schedule( $activities, array() );
		$this->assertSame( '2026-10-16', $ok['project']['deadline_date'] );
		$this->assertSame( 5, $ok['project']['deadline_slack'] );

		$late = ( new Scheduler( new WorkCalendar(), '2026-10-05', '2026-10-07' ) )->schedule( $activities, array() );
		$this->assertSame( -2, $late['project']['deadline_slack'] );

		$weekend = ( new Scheduler( new WorkCalendar(), '2026-10-03', '2026-10-11' ) )->schedule( $activities, array() );
		$this->assertSame( '2026-10-05', $weekend['project']['start_date'], 'el inicio en fin de semana se mueve al lunes' );
		$this->assertSame( 0, $weekend['project']['deadline_slack'], 'el término en domingo cuenta como el viernes anterior' );
	}

	public function test_holidays_are_skipped(): void {
		$calendar  = new WorkCalendar( array( 1, 2, 3, 4, 5 ), array( '2026-10-12' => false ) );
		$scheduler = new Scheduler( $calendar, '2026-10-05' );
		$n         = $scheduler->schedule(
			array( self::act( 1, 5 ), self::act( 2, 1 ) ),
			array( self::dep( 1, 2 ) )
		)['nodes'];

		$this->assertSame( '2026-10-09', $n[1]['end_date'] );
		$this->assertSame( '2026-10-13', $n[2]['start_date'] );
		$this->assertSame( '2026-10-13', $n[2]['end_date'] );
	}

	public function test_empty_project(): void {
		$result = $this->scheduler->schedule( array(), array() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['nodes'] );
		$this->assertSame( 0, $result['project']['finish_boundary'] );
		$this->assertSame( '2026-10-05', $result['project']['finish_date'] );
	}

	public function test_percent_is_clamped(): void {
		$n = $this->scheduler->schedule(
			array( self::act( 1, 1, array( 'percent' => 250 ) ), self::act( 2, 1, array( 'percent' => -5 ) ) ),
			array()
		)['nodes'];
		$this->assertSame( 100, $n[1]['percent'] );
		$this->assertSame( 0, $n[2]['percent'] );
	}
}
