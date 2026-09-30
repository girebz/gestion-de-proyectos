<?php
/**
 * Herramientas del conector para planificación y tiempo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Connector\Connector;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Operations\OperationManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura del cronograma, ruta crítica, alertas, informe semanal y líneas
 * base, y propuesta de cambios en dos tiempos a través de ActivityHandler.
 */
final class PlanningTools {

	/**
	 * Añade las herramientas del módulo al catálogo del conector.
	 *
	 * @param array<string,array<string,mixed>> $definitions Definiciones.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_abilities( array $definitions ): array {
		$read  = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
		$write = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false );
		$out   = array( 'type' => 'object', 'additionalProperties' => true );

		$project_props = array(
			'project_id' => array( 'type' => 'integer', 'description' => __( 'Identificador numérico del proyecto.', 'gestion-de-proyectos' ) ),
			'code'       => array( 'type' => 'string', 'description' => __( 'Código del proyecto (alternativa al identificador).', 'gestion-de-proyectos' ) ),
		);

		$definitions['get-schedule'] = array(
			'label'            => __( 'Obtener cronograma', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve el cronograma completo de un proyecto: actividades en orden de la estructura de desglose (código, nivel, tipo summary|activity|milestone, frente, estado, duración en días hábiles, avance, fechas tempranas y tardías, holguras, si es crítica, restricciones, fechas reales, responsable, predecesoras en notación compacta como "1.2FS+3", asignados, conflictos y versión), más el resumen del proyecto (término programado y holgura respecto del término contractual) y estadísticas. Acepta filtros por estado, tipo, frente, solo críticas y texto. Las fechas se calculan con el calendario laboral del proyecto.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'status'     => array( 'type' => 'string', 'enum' => ActivityRepository::STATUSES ),
					'kind'       => array( 'type' => 'string', 'enum' => ActivityRepository::KINDS ),
					'work_front' => array( 'type' => 'string' ),
					'critical'   => array( 'type' => 'boolean', 'description' => __( 'Solo actividades de la ruta crítica.', 'gestion-de-proyectos' ) ),
					'search'     => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_schedule' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-activity'] = array(
			'label'            => __( 'Obtener actividad', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve una actividad con todos sus campos, sus predecesoras y sucesoras (con tipo y retraso), las personas asignadas y el historial de avances. Incluye la versión, necesaria para proponer cambios sin conflictos.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => array( 'activity_id' => array( 'type' => 'integer' ) ),
				'required'             => array( 'activity_id' ),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_activity' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-critical-path'] = array(
			'label'            => __( 'Obtener ruta crítica', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve las actividades de la ruta crítica del proyecto (holgura total cero o negativa) en orden de inicio, con sus fechas, avance y conflictos, y el término programado del proyecto.', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $project_props, 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_critical_path' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-alerts'] = array(
			'label'            => __( 'Obtener alertas de plazo', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve las alertas de plazo del proyecto: actividades vencidas, que vencen pronto, que debieron empezar, con holgura negativa, con conflictos de fechas y el incumplimiento del término contractual. Cada alerta indica tipo, severidad (high|medium), días y mensaje.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'horizon' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 5, 'description' => __( 'Días hábiles de anticipación para "vence pronto".', 'gestion-de-proyectos' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_alerts' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['weekly-report'] = array(
			'label'            => __( 'Informe semanal', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los datos del informe semanal de un proyecto para la semana indicada (por defecto, la actual): avance por frente de trabajo, avances registrados, terminado, iniciado, en ejecución, próxima semana, hitos próximos, vencidas, desviación respecto de la línea base vigente, alertas, curva S (planificado frente a real, en "curve") y cambios respecto de la semana anterior (frentes que se abren o cierran, entradas y salidas de la ruta crítica, en "changes"). Con include_activities=true añade el cronograma completo. El panel exporta el mismo informe en LaTeX, CSV e iCalendar.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'week_start'         => array( 'type' => 'string', 'description' => __( 'Cualquier fecha de la semana (AAAA-MM-DD); se normaliza al lunes.', 'gestion-de-proyectos' ) ),
					'include_activities' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'weekly_report' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['list-baselines'] = array(
			'label'            => __( 'Listar líneas base', 'gestion-de-proyectos' ),
			'description'      => __( 'Lista las líneas base del proyecto (nombre, fecha, vigente). Con baseline_id devuelve además la comparación del cronograma actual con esa línea base: por actividad, fechas de la línea base y actuales y desviación en días hábiles.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => $project_props + array(
					'baseline_id' => array( 'type' => 'integer', 'description' => __( 'Línea base a comparar (opcional; 0 = la vigente).', 'gestion-de-proyectos' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'list_baselines' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['get-calendar'] = array(
			'label'            => __( 'Obtener calendario laboral', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve el calendario laboral efectivo del proyecto: días de la semana laborables (1 = lunes ... 7 = domingo) y excepciones (feriados y días laborables por excepción).', 'gestion-de-proyectos' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => $project_props, 'additionalProperties' => false ),
			'output_schema'    => $out,
			'execute_callback' => array( self::class, 'get_calendar' ),
			'meta'             => array( 'annotations' => $read ),
		);

		$definitions['propose-activity-change'] = array(
			'label'               => __( 'Proponer cambio en el cronograma', 'gestion-de-proyectos' ),
			'description'         => __( 'Propone un cambio en el cronograma de un proyecto. NO aplica nada: devuelve una vista previa (cambios, advertencias, conflictos) y un operation_id que debe confirmarse con confirm-operation. Acciones: create (data: name, kind summary|activity|milestone, parent_id, duration en días hábiles, work_front, status, priority 1..3, constraint_type asap|snet|snlt|fnet|fnlt|mso|mfo, constraint_date, owner_id, deliverable, budget_line, cost_planned, description, notes; opcionalmente predecessors), update (activity_id, data, expected_version), delete (activity_id; elimina también las contenidas), set_progress (activity_id, percent, status, actual_start, actual_finish, note), set_dependencies (activity_id, predecessors: lista de {predecessor_id, type FS|SS|FF|SF, lag}; sustituye la lista completa), move (activity_id, parent_id, sort_order), set_assignment (activity_id, user_id, role responsable|participante|revisor, allocation), remove_assignment (activity_id, user_id), create_baseline (name, description, make_current), delete_baseline (baseline_id), delete_calendar (calendar_id). Tras confirmar, el cronograma se recalcula. Toda eliminación guarda una instantánea y puede revertirse con revert-operation.', 'gestion-de-proyectos' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'action'           => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'delete', 'set_progress', 'set_dependencies', 'move', 'set_assignment', 'remove_assignment', 'create_baseline', 'delete_baseline', 'delete_calendar' ) ),
					'project_id'       => array( 'type' => 'integer' ),
					'activity_id'      => array( 'type' => 'integer' ),
					'data'             => array( 'type' => 'object', 'additionalProperties' => true, 'description' => __( 'Campos de la actividad (create, update).', 'gestion-de-proyectos' ) ),
					'expected_version' => array( 'type' => 'integer' ),
					'percent'          => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
					'status'           => array( 'type' => 'string', 'enum' => ActivityRepository::STATUSES ),
					'actual_start'     => array( 'type' => 'string' ),
					'actual_finish'    => array( 'type' => 'string' ),
					'note'             => array( 'type' => 'string', 'description' => __( 'Nota del avance (set_progress, update).', 'gestion-de-proyectos' ) ),
					'predecessors'     => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'predecessor_id' => array( 'type' => 'integer' ),
								'type'           => array( 'type' => 'string', 'enum' => array( 'FS', 'SS', 'FF', 'SF' ) ),
								'lag'            => array( 'type' => 'integer' ),
							),
							'required'             => array( 'predecessor_id' ),
							'additionalProperties' => false,
						),
					),
					'parent_id'        => array( 'type' => 'integer' ),
					'sort_order'       => array( 'type' => 'integer' ),
					'user_id'          => array( 'type' => 'integer' ),
					'role'             => array( 'type' => 'string', 'enum' => AssignmentRepository::ROLES ),
					'allocation'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					'name'             => array( 'type' => 'string', 'description' => __( 'Nombre de la línea base (create_baseline).', 'gestion-de-proyectos' ) ),
					'description'      => array( 'type' => 'string' ),
					'make_current'     => array( 'type' => 'boolean' ),
					'baseline_id'      => array( 'type' => 'integer', 'description' => __( 'Línea base (delete_baseline).', 'gestion-de-proyectos' ) ),
					'calendar_id'      => array( 'type' => 'integer', 'description' => __( 'Calendario (delete_calendar).', 'gestion-de-proyectos' ) ),
				),
				'required'             => array( 'action', 'project_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $out,
			'execute_callback'    => array( self::class, 'propose_activity_change' ),
			'permission_callback' => array( Connector::class, 'write_permission' ),
			'meta'                => array( 'annotations' => $write ),
		);

		return $definitions;
	}

	/**
	 * Cronograma.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_schedule( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$summary = ScheduleService::summary( (int) $project['id'], true );
		$filters = array_intersect_key( $input, array_flip( array( 'status', 'kind', 'work_front', 'critical', 'search' ) ) );
		if ( ! empty( $filters ) ) {
			$summary['activities'] = array_values(
				array_filter(
					$summary['activities'],
					static function ( array $a ) use ( $filters ): bool {
						foreach ( $filters as $key => $value ) {
							if ( 'critical' === $key ) {
								if ( $value && ! $a['is_critical'] ) {
									return false;
								}
							} elseif ( 'search' === $key ) {
								if ( false === mb_stripos( $a['code'] . ' ' . $a['name'] . ' ' . $a['deliverable'], (string) $value ) ) {
									return false;
								}
							} elseif ( (string) $a[ $key ] !== (string) $value ) {
								return false;
							}
						}
						return true;
					}
				)
			);
		}
		$summary['project_code'] = $project['code'];
		$summary['project_name'] = $project['name'];
		$summary['conventions']  = __( 'Duraciones y holguras en días hábiles. Un hito se fecha el día en que se alcanza (mismo día en que termina su predecesora fin a inicio). Las fechas programadas se recalculan a partir de dependencias, restricciones, fechas reales y calendario.', 'gestion-de-proyectos' );

		return $summary;
	}

	/**
	 * Actividad con relaciones.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_activity( array $input = array() ) {
		$activity = ActivityRepository::find( (int) ( $input['activity_id'] ?? 0 ) );
		if ( ! $activity ) {
			return new WP_Error( 'not_found', __( 'La actividad no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can( 'planning.view', $activity['project_id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver la planificación de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		ScheduleService::recalculate( $activity['project_id'] );
		$activity = ActivityRepository::find( $activity['id'] );
		$index    = array();
		foreach ( ActivityRepository::for_project( $activity['project_id'] ) as $a ) {
			$index[ $a['id'] ] = $a;
		}
		$relate = static function ( array $deps, string $side ) use ( $index ): array {
			$rows = array();
			foreach ( $deps as $d ) {
				$other  = $index[ $d[ $side ] ] ?? null;
				$rows[] = array(
					'activity_id' => $d[ $side ],
					'code'        => $other['code'] ?? '',
					'name'        => $other['name'] ?? '',
					'type'        => $d['type'],
					'lag'         => $d['lag'],
				);
			}
			return $rows;
		};

		$activity['owner']        = ScheduleService::user_name( $activity['owner_id'] );
		$activity['predecessors'] = $relate( DependencyRepository::predecessors( $activity['id'] ), 'predecessor_id' );
		$activity['successors']   = $relate( DependencyRepository::successors( $activity['id'] ), 'successor_id' );
		$activity['assignments']  = array_map( static fn( array $s ): array => array( 'user_id' => $s['user_id'], 'name' => $s['display_name'], 'role' => $s['role'], 'allocation' => $s['allocation'] ), AssignmentRepository::for_activity( $activity['id'] ) );
		$activity['history']      = array_map( static fn( array $h ): array => array( 'reported_at' => $h['reported_at'], 'user' => $h['display_name'], 'previous_percent' => $h['previous_percent'], 'percent' => $h['percent'], 'status' => $h['status'], 'note' => $h['note'], 'source' => $h['source'] ), ProgressRepository::for_activity( $activity['id'], 30 ) );
		$activity['children']     = array_map( static fn( array $c ): array => array( 'id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'percent' => $c['percent'] ), ActivityRepository::children( $activity['project_id'], $activity['id'] ) );
		unset( $activity['level'] );

		return $activity;
	}

	/**
	 * Ruta crítica.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_critical_path( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		return ScheduleService::critical_path( (int) $project['id'] );
	}

	/**
	 * Alertas.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_alerts( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$alerts = ScheduleService::alerts( (int) $project['id'], null, max( 1, min( 60, (int) ( $input['horizon'] ?? 5 ) ) ) );

		return array(
			'project_id' => (int) $project['id'],
			'today'      => current_time( 'Y-m-d' ),
			'count'      => count( $alerts ),
			'high'       => count( array_filter( $alerts, static fn( array $a ): bool => 'high' === $a['severity'] ) ),
			'alerts'     => $alerts,
		);
	}

	/**
	 * Informe semanal.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function weekly_report( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$week = isset( $input['week_start'] ) ? ActivityRepository::normalize_date( (string) $input['week_start'] ) : null;
		if ( false === $week ) {
			return new WP_Error( 'invalid_date', __( 'Fecha no válida; use AAAA-MM-DD.', 'gestion-de-proyectos' ) );
		}
		$report = WeeklyReport::build( (int) $project['id'], $week );
		if ( ! $report ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( empty( $input['include_activities'] ) ) {
			unset( $report['activities'] );
		}

		return $report;
	}

	/**
	 * Líneas base y comparación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_baselines( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$project_id = (int) $project['id'];
		$baselines  = array_map(
			static fn( array $b ): array => array(
				'id'          => $b['id'],
				'name'        => $b['name'],
				'description' => $b['description'],
				'is_current'  => $b['is_current'],
				'created_at'  => $b['created_at'],
				'created_by'  => ScheduleService::user_name( $b['created_by'] ),
			),
			BaselineRepository::for_project( $project_id )
		);

		$out = array( 'project_id' => $project_id, 'baselines' => $baselines );

		if ( array_key_exists( 'baseline_id', $input ) ) {
			$baseline_id = (int) $input['baseline_id'];
			if ( 0 === $baseline_id ) {
				$current     = BaselineRepository::current( $project_id );
				$baseline_id = $current ? $current['id'] : 0;
			}
			$baseline = $baseline_id > 0 ? BaselineRepository::find( $baseline_id ) : null;
			if ( ! $baseline || $baseline['project_id'] !== $project_id ) {
				return new WP_Error( 'not_found', __( 'La línea base no existe en este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
			}
			ScheduleService::recalculate( $project_id );
			$out['comparison'] = BaselineRepository::compare( $project_id, $baseline_id );
		}

		return $out;
	}

	/**
	 * Calendario laboral.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_calendar( array $input = array() ) {
		$project = self::project( $input );
		if ( is_wp_error( $project ) ) {
			return $project;
		}
		$record   = CalendarRepository::effective( (int) $project['id'] );
		$calendar = CalendarRepository::build( (int) $project['id'] );

		return array(
			'project_id' => (int) $project['id'],
			'calendar'   => $record ? array( 'id' => $record['id'], 'name' => $record['name'], 'scope' => $record['project_id'] > 0 ? 'project' : 'global' ) : array( 'id' => 0, 'name' => __( 'Implícito (lunes a viernes)', 'gestion-de-proyectos' ), 'scope' => 'implicit' ),
			'weekdays'   => $calendar->weekdays(),
			'exceptions' => $record ? array_map( static fn( array $e ): array => array( 'date' => $e['exception_date'], 'working' => $e['working'], 'label' => $e['label'] ), CalendarRepository::exceptions( $record['id'] ) ) : array(),
		);
	}

	/**
	 * Propuesta de cambio.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function propose_activity_change( array $input = array() ) {
		$action     = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$project_id = (int) ( $input['project_id'] ?? 0 );
		if ( $project_id <= 0 || ! ProjectRepository::find( $project_id ) ) {
			return new WP_Error( 'missing_project', __( 'Indique un project_id válido.', 'gestion-de-proyectos' ) );
		}

		$payload = array_intersect_key(
			$input,
			array_flip( array( 'activity_id', 'data', 'expected_version', 'percent', 'status', 'actual_start', 'actual_finish', 'note', 'predecessors', 'parent_id', 'sort_order', 'user_id', 'role', 'allocation', 'name', 'description', 'make_current', 'baseline_id', 'calendar_id' ) )
		);

		return OperationManager::propose( 'activity', $action, $payload, $project_id, 'connector' );
	}

	/**
	 * Resuelve el proyecto y comprueba el permiso de lectura de la planificación.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function project( array $input ) {
		$project = null;
		if ( ! empty( $input['project_id'] ) ) {
			$project = ProjectRepository::find( (int) $input['project_id'] );
		} elseif ( ! empty( $input['code'] ) ) {
			$project = ProjectRepository::find_by_code( sanitize_title( (string) $input['code'] ) );
		} else {
			return new WP_Error( 'missing_identifier', __( 'Indique project_id o code.', 'gestion-de-proyectos' ) );
		}
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'El proyecto no existe.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can( 'planning.view', (int) $project['id'] ) ) {
			return new WP_Error( 'forbidden', __( 'Sin permiso para ver la planificación de este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}

		return $project;
	}
}
