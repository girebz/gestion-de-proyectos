<?php
/**
 * Manejador de operaciones del módulo de planificación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning;

use GDP\Core\Access;
use GDP\Operations\HandlerInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Escrituras sobre actividades, dependencias, avances, asignaciones y
 * líneas base en dos tiempos (proponer y confirmar), con vista previa y
 * reversión. Lo usan el conector y la importación; el panel escribe
 * directamente en los repositorios.
 */
final class ActivityHandler implements HandlerInterface {

	/**
	 * Campos que se muestran en la vista previa de una actividad.
	 *
	 * @var string[]
	 */
	private const PREVIEW_FIELDS = array( 'parent_id', 'name', 'kind', 'work_front', 'status', 'priority', 'duration', 'constraint_type', 'constraint_date', 'actual_start', 'actual_finish', 'percent', 'owner_id', 'deliverable', 'budget_line', 'cost_planned', 'description', 'notes' );

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'activity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function actions(): array {
		return array( 'create', 'update', 'delete', 'set_progress', 'set_dependencies', 'move', 'set_assignment', 'remove_assignment', 'create_baseline' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool {
		if ( $project_id <= 0 ) {
			return false;
		}
		if ( 'create_baseline' === $action ) {
			return Access::can( 'planning.baseline', $project_id, $user_id );
		}

		return Access::can( 'planning.edit', $project_id, $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$data = array_merge(
					array(
						'parent_id'       => 0,
						'kind'            => 'activity',
						'status'          => 'pendiente',
						'priority'        => 2,
						'duration'        => 1,
						'constraint_type' => 'asap',
						'percent'         => 0,
					),
					$data
				);
				$clean = ActivityRepository::validate( $data, $project_id );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				if ( empty( $clean['name'] ) ) {
					return new WP_Error( 'required', __( 'El nombre de la actividad es obligatorio.', 'gestion-de-proyectos' ) );
				}
				$out = array( 'data' => $clean );
				if ( isset( $payload['predecessors'] ) && is_array( $payload['predecessors'] ) ) {
					// Se validan tras crear la actividad (necesitan su identificador); aquí solo la forma.
					$out['predecessors'] = array_values( array_filter( $payload['predecessors'], 'is_array' ) );
				}
				return $out;

			case 'update':
			case 'set_progress':
				$activity = $this->activity( $payload, $project_id );
				if ( is_wp_error( $activity ) ) {
					return $activity;
				}
				// La validación debe ser idempotente: al confirmar se recibe el payload ya limpio (con "data").
				if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
					$data = $payload['data'];
				} elseif ( 'set_progress' === $action ) {
					$data = array_intersect_key( $payload, array_flip( array( 'percent', 'status', 'actual_start', 'actual_finish' ) ) );
				} else {
					$data = array();
				}
				if ( 'set_progress' === $action && 'summary' === $activity['kind'] ) {
					return new WP_Error( 'summary', __( 'El avance de un resumen se calcula a partir de sus actividades.', 'gestion-de-proyectos' ) );
				}
				if ( empty( $data ) ) {
					return new WP_Error( 'invalid_payload', __( 'Indique los campos a modificar.', 'gestion-de-proyectos' ) );
				}
				$clean = ActivityRepository::validate( $data, $project_id, $activity['id'] );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				return array(
					'activity_id'      => $activity['id'],
					'data'             => $clean,
					'note'             => isset( $payload['note'] ) ? sanitize_textarea_field( (string) $payload['note'] ) : '',
					'expected_version' => isset( $payload['expected_version'] ) ? (int) $payload['expected_version'] : null,
				);

			case 'delete':
				$activity = $this->activity( $payload, $project_id );
				if ( is_wp_error( $activity ) ) {
					return $activity;
				}
				return array( 'activity_id' => $activity['id'] );

			case 'set_dependencies':
				$activity = $this->activity( $payload, $project_id );
				if ( is_wp_error( $activity ) ) {
					return $activity;
				}
				$list = isset( $payload['predecessors'] ) && is_array( $payload['predecessors'] ) ? $payload['predecessors'] : array();
				$list = DependencyRepository::validate_list( $project_id, $activity['id'], $list );
				if ( is_wp_error( $list ) ) {
					return $list;
				}
				return array( 'activity_id' => $activity['id'], 'predecessors' => $list );

			case 'move':
				$activity = $this->activity( $payload, $project_id );
				if ( is_wp_error( $activity ) ) {
					return $activity;
				}
				$parent_id  = isset( $payload['parent_id'] ) ? (int) $payload['parent_id'] : $activity['parent_id'];
				$sort_order = isset( $payload['sort_order'] ) ? (int) $payload['sort_order'] : null;
				$check      = ActivityRepository::validate( array( 'parent_id' => $parent_id ), $project_id, $activity['id'] );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				return array( 'activity_id' => $activity['id'], 'parent_id' => $parent_id, 'sort_order' => $sort_order );

			case 'set_assignment':
			case 'remove_assignment':
				$activity = $this->activity( $payload, $project_id );
				if ( is_wp_error( $activity ) ) {
					return $activity;
				}
				$user_id = (int) ( $payload['user_id'] ?? 0 );
				if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
					return new WP_Error( 'invalid_user', __( 'El usuario indicado no existe.', 'gestion-de-proyectos' ) );
				}
				$role = sanitize_key( (string) ( $payload['role'] ?? 'participante' ) );
				if ( 'set_assignment' === $action && ! in_array( $role, AssignmentRepository::ROLES, true ) ) {
					return new WP_Error( 'invalid_role', __( 'Rol de asignación no válido (responsable, participante, revisor).', 'gestion-de-proyectos' ) );
				}
				return array(
					'activity_id' => $activity['id'],
					'user_id'     => $user_id,
					'role'        => $role,
					'allocation'  => max( 1, min( 100, (int) ( $payload['allocation'] ?? 100 ) ) ),
				);

			case 'create_baseline':
				return array(
					'name'         => sanitize_text_field( (string) ( $payload['name'] ?? '' ) ),
					'description'  => sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) ),
					'make_current' => ! isset( $payload['make_current'] ) || (bool) $payload['make_current'],
				);
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function preview( string $action, array $payload, int $project_id ): array {
		$preview = array(
			'summary'   => '',
			'changes'   => array(),
			'warnings'  => array(),
			'conflicts' => array(),
		);

		switch ( $action ) {
			case 'create':
				$preview['summary'] = sprintf( 'Crear %s "%s"', $this->kind_word( (string) $payload['data']['kind'] ), $payload['data']['name'] );
				foreach ( $payload['data'] as $field => $value ) {
					if ( in_array( $field, self::PREVIEW_FIELDS, true ) && null !== $value && '' !== $value ) {
						$preview['changes'][ $field ] = array( 'before' => null, 'after' => $value );
					}
				}
				if ( ! empty( $payload['predecessors'] ) ) {
					$preview['changes']['predecessors'] = array( 'before' => null, 'after' => $payload['predecessors'] );
				}
				break;

			case 'update':
			case 'set_progress':
				$current            = ActivityRepository::find( $payload['activity_id'] );
				$preview['summary'] = sprintf( 'set_progress' === $action ? 'Registrar avance en %s "%s"' : 'Actualizar %s "%s"', $this->kind_word( $current['kind'] ), $current['name'] );
				foreach ( $payload['data'] as $field => $value ) {
					$before = $current[ $field ] ?? null;
					if ( $before !== $value ) {
						$preview['changes'][ $field ] = array( 'before' => $before, 'after' => $value );
					}
				}
				if ( null !== $payload['expected_version'] && $current['version'] !== $payload['expected_version'] ) {
					$preview['conflicts'][] = sprintf( 'La actividad está en la versión %d y la propuesta se basó en la versión %d.', $current['version'], $payload['expected_version'] );
				}
				if ( empty( $preview['changes'] ) ) {
					$preview['warnings'][] = 'Ningún campo cambia respecto del estado actual.';
				}
				if ( isset( $payload['data']['kind'] ) && $payload['data']['kind'] !== $current['kind'] ) {
					$preview['warnings'][] = 'Cambiar el tipo altera la programación de la actividad y de sus sucesoras.';
				}
				break;

			case 'delete':
				$current            = ActivityRepository::find( $payload['activity_id'] );
				$descendants        = ActivityRepository::descendant_ids( $project_id, $current['id'] );
				$deps               = DependencyRepository::for_activity( $current['id'] );
				$preview['summary'] = sprintf( 'Eliminar %s "%s"', $this->kind_word( $current['kind'] ), $current['name'] );
				$preview['changes']['activity'] = array( 'before' => $current['code'] . ' ' . $current['name'], 'after' => null );
				if ( ! empty( $descendants ) ) {
					$preview['warnings'][] = sprintf( 'Se eliminarán también %d actividades contenidas.', count( $descendants ) );
				}
				if ( ! empty( $deps ) ) {
					$preview['warnings'][] = sprintf( 'Se eliminarán %d dependencias que la vinculan con otras actividades.', count( $deps ) );
				}
				if ( $current['percent'] > 0 ) {
					$preview['warnings'][] = 'La actividad registra avance; se perderá su historial.';
				}
				break;

			case 'set_dependencies':
				$current            = ActivityRepository::find( $payload['activity_id'] );
				$index              = array();
				foreach ( ActivityRepository::for_project( $project_id ) as $a ) {
					$index[ $a['id'] ] = $a;
				}
				$before             = DependencyRepository::notation( $current['id'], $index, DependencyRepository::for_project( $project_id ) );
				$after_list         = array();
				foreach ( $payload['predecessors'] as $p ) {
					$after_list[] = array(
						'predecessor_id' => $p['predecessor_id'],
						'successor_id'   => $current['id'],
						'type'           => $p['type'],
						'lag'            => $p['lag'],
					);
				}
				$after              = DependencyRepository::notation( $current['id'], $index, $after_list );
				$preview['summary'] = sprintf( 'Fijar las predecesoras de %s "%s": %s', $this->kind_word( $current['kind'] ), $current['name'], '' === $after ? 'ninguna' : $after );
				$preview['changes']['predecessors'] = array( 'before' => $before, 'after' => $after );
				if ( $before === $after ) {
					$preview['warnings'][] = 'Las predecesoras no cambian.';
				}
				break;

			case 'move':
				$current            = ActivityRepository::find( $payload['activity_id'] );
				$parent             = $payload['parent_id'] > 0 ? ActivityRepository::find( $payload['parent_id'] ) : null;
				$preview['summary'] = sprintf( 'Mover "%s" %s', $current['name'], $parent ? sprintf( 'dentro de "%s"', $parent['name'] ) : 'al primer nivel' );
				$preview['changes']['parent_id']  = array( 'before' => $current['parent_id'], 'after' => $payload['parent_id'] );
				$preview['changes']['sort_order'] = array( 'before' => $current['sort_order'], 'after' => $payload['sort_order'] );
				break;

			case 'set_assignment':
			case 'remove_assignment':
				$current            = ActivityRepository::find( $payload['activity_id'] );
				$user               = get_userdata( $payload['user_id'] );
				$existing           = null;
				foreach ( AssignmentRepository::for_activity( $current['id'] ) as $s ) {
					if ( $s['user_id'] === $payload['user_id'] ) {
						$existing = $s['role'];
					}
				}
				$preview['summary'] = 'set_assignment' === $action
					? sprintf( 'Asignar a %s como %s en "%s"', $user->display_name, $payload['role'], $current['name'] )
					: sprintf( 'Retirar a %s de "%s"', $user->display_name, $current['name'] );
				$preview['changes']['role'] = array( 'before' => $existing, 'after' => 'set_assignment' === $action ? $payload['role'] : null );
				if ( 'remove_assignment' === $action && null === $existing ) {
					$preview['warnings'][] = 'La persona no estaba asignada.';
				}
				break;

			case 'create_baseline':
				$count              = ActivityRepository::count( $project_id );
				$preview['summary'] = sprintf( 'Crear la línea base "%s" con %d actividades', '' !== $payload['name'] ? $payload['name'] : 'sin nombre', $count );
				$preview['changes']['baseline'] = array( 'before' => null, 'after' => $payload['name'] );
				if ( 0 === $count ) {
					$preview['conflicts'][] = 'El proyecto no tiene actividades.';
				}
				if ( $payload['make_current'] && BaselineRepository::current( $project_id ) ) {
					$preview['warnings'][] = sprintf( 'Reemplazará como vigente a "%s".', BaselineRepository::current( $project_id )['name'] );
				}
				break;
		}

		return $preview;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $action, array $payload, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$id = ActivityRepository::create( $project_id, $payload['data'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				if ( ! empty( $payload['predecessors'] ) ) {
					$list = DependencyRepository::validate_list( $project_id, $id, $payload['predecessors'] );
					if ( is_wp_error( $list ) ) {
						ActivityRepository::delete( $id );
						return $list;
					}
					DependencyRepository::replace_predecessors( $project_id, $id, $list );
				}
				ScheduleService::recalculate( $project_id );
				return array( 'before' => null, 'result' => array( 'activity_id' => $id, 'activity' => ActivityRepository::find( $id ) ) );

			case 'update':
			case 'set_progress':
				$before = ActivityRepository::find( $payload['activity_id'] );
				$data   = $payload['data'];
				if ( '' !== $payload['note'] ) {
					$data['progress_note'] = $payload['note'];
				}
				$updated = ActivityRepository::update( $payload['activity_id'], $data, $payload['expected_version'] );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				ScheduleService::recalculate( $project_id );
				return array( 'before' => $before, 'result' => array( 'activity_id' => $payload['activity_id'], 'activity' => ActivityRepository::find( $payload['activity_id'] ) ) );

			case 'delete':
				$snapshot = ActivityRepository::delete( $payload['activity_id'] );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				ScheduleService::recalculate( $project_id );
				return array( 'before' => $snapshot, 'result' => array( 'activity_id' => $payload['activity_id'], 'deleted' => count( $snapshot['activities'] ) ) );

			case 'set_dependencies':
				$before = DependencyRepository::predecessors( $payload['activity_id'] );
				DependencyRepository::replace_predecessors( $project_id, $payload['activity_id'], $payload['predecessors'] );
				ScheduleService::recalculate( $project_id );
				return array(
					'before' => array( 'predecessors' => $before ),
					'result' => array( 'activity_id' => $payload['activity_id'], 'predecessors' => DependencyRepository::predecessors( $payload['activity_id'] ) ),
				);

			case 'move':
				$before = ActivityRepository::find( $payload['activity_id'] );
				$moved  = ActivityRepository::move( $payload['activity_id'], $payload['parent_id'], $payload['sort_order'] );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
				ScheduleService::recalculate( $project_id );
				return array( 'before' => $before, 'result' => array( 'activity_id' => $payload['activity_id'], 'activity' => $moved ) );

			case 'set_assignment':
			case 'remove_assignment':
				$existing = null;
				foreach ( AssignmentRepository::for_activity( $payload['activity_id'] ) as $s ) {
					if ( $s['user_id'] === $payload['user_id'] ) {
						$existing = $s;
					}
				}
				if ( 'set_assignment' === $action ) {
					$ok = AssignmentRepository::set( $project_id, $payload['activity_id'], $payload['user_id'], $payload['role'], $payload['allocation'] );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
				} else {
					AssignmentRepository::remove( $payload['activity_id'], $payload['user_id'] );
				}
				return array(
					'before' => array( 'assignment' => $existing ),
					'result' => array(
						'activity_id' => $payload['activity_id'],
						'user_id'     => $payload['user_id'],
						'assignments' => AssignmentRepository::for_activity( $payload['activity_id'] ),
					),
				);

			case 'create_baseline':
				ScheduleService::recalculate( $project_id );
				$previous = BaselineRepository::current( $project_id );
				$id       = BaselineRepository::create( $project_id, $payload['name'], $payload['description'], $payload['make_current'] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return array(
					'before' => array( 'previous_current' => $previous ? $previous['id'] : null ),
					'result' => array( 'baseline_id' => $id, 'baseline' => BaselineRepository::find( $id ) ),
				);
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id ) {
		switch ( $action ) {
			case 'create':
				$id = (int) ( $result['activity_id'] ?? 0 );
				if ( $id <= 0 ) {
					return new WP_Error( 'nothing_to_revert', __( 'No hay actividad que revertir.', 'gestion-de-proyectos' ) );
				}
				$ok = ActivityRepository::delete( $id );
				ScheduleService::recalculate( $project_id );
				return is_wp_error( $ok ) ? $ok : true;

			case 'update':
			case 'set_progress':
			case 'move':
				if ( ! $before ) {
					return new WP_Error( 'nothing_to_revert', __( 'No se guardó el estado anterior.', 'gestion-de-proyectos' ) );
				}
				$fields = array_intersect_key( $before, array_flip( array_merge( self::PREVIEW_FIELDS, array( 'sort_order' ) ) ) );
				$ok     = ActivityRepository::update( (int) $before['id'], $fields, null );
				ScheduleService::recalculate( $project_id );
				return is_wp_error( $ok ) ? $ok : true;

			case 'delete':
				if ( ! $before || empty( $before['activities'] ) ) {
					return new WP_Error( 'nothing_to_revert', __( 'No se guardó la instantánea de lo eliminado.', 'gestion-de-proyectos' ) );
				}
				ActivityRepository::restore( $before );
				ScheduleService::recalculate( $project_id );
				return true;

			case 'set_dependencies':
				$activity_id = (int) ( $result['activity_id'] ?? 0 );
				$list        = array();
				foreach ( $before['predecessors'] ?? array() as $d ) {
					$list[] = array( 'predecessor_id' => (int) $d['predecessor_id'], 'type' => (string) $d['type'], 'lag' => (int) $d['lag'] );
				}
				DependencyRepository::replace_predecessors( $project_id, $activity_id, $list );
				ScheduleService::recalculate( $project_id );
				return true;

			case 'set_assignment':
			case 'remove_assignment':
				$activity_id = (int) ( $result['activity_id'] ?? 0 );
				$existing    = $before['assignment'] ?? null;
				$user_id     = (int) ( $existing['user_id'] ?? 0 );
				if ( $existing && $user_id > 0 ) {
					AssignmentRepository::set( $project_id, $activity_id, $user_id, (string) $existing['role'], (int) $existing['allocation'] );
				} elseif ( 'set_assignment' === $action ) {
					// No existía: se retira la asignación creada.
					$created = (int) ( $result['user_id'] ?? 0 );
					if ( $created > 0 ) {
						AssignmentRepository::remove( $activity_id, $created );
					}
				}
				return true;

			case 'create_baseline':
				$id = (int) ( $result['baseline_id'] ?? 0 );
				if ( $id > 0 ) {
					BaselineRepository::delete( $id );
				}
				$previous = (int) ( $before['previous_current'] ?? 0 );
				if ( $previous > 0 ) {
					BaselineRepository::set_current( $previous );
				}
				return true;
		}

		return new WP_Error( 'unknown_action', __( 'Acción no admitida.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Resuelve la actividad del payload dentro del proyecto.
	 *
	 * @param array $payload    Datos.
	 * @param int   $project_id Proyecto.
	 * @return array<string,mixed>|WP_Error
	 */
	private function activity( array $payload, int $project_id ) {
		$id       = (int) ( $payload['activity_id'] ?? 0 );
		$activity = $id > 0 ? ActivityRepository::find( $id ) : null;
		if ( ! $activity || $activity['project_id'] !== $project_id ) {
			return new WP_Error( 'not_found', __( 'La actividad no existe en este proyecto.', 'gestion-de-proyectos' ) );
		}

		return $activity;
	}

	/**
	 * Palabra para el tipo de nodo.
	 *
	 * @param string $kind Tipo.
	 * @return string
	 */
	private function kind_word( string $kind ): string {
		return array( 'summary' => 'el resumen', 'milestone' => 'el hito' )[ $kind ] ?? 'la actividad';
	}
}
