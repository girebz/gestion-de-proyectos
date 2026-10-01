<?php
/**
 * Diccionario de datos generado a partir del esquema.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Data;

use GDP\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Campos, tipos, unidades y significado de cada tabla del plugin. Los tipos
 * salen de las definiciones del esquema; el significado y las unidades, de
 * las descripciones declaradas aquí (con un texto genérico para las columnas
 * comunes). Las exportaciones lo incluyen para que los datos se entiendan
 * fuera del sitio.
 */
final class DataDictionary {

	/**
	 * Diccionario completo.
	 *
	 * @param string[]|null $tables Tablas a incluir (todas por defecto).
	 * @return array<string,array{label:string,description:string,module:string,fields:array<int,array{name:string,type:string,sql_type:string,unit:string,description:string,references:string}>}>
	 */
	public static function build( ?array $tables = null ): array {
		$columns = DataSchema::columns();
		$refs    = DataSchema::refs();
		$poly    = DataSchema::polymorphic();
		$meta    = self::tables();
		$out     = array();
		foreach ( DataSchema::order() as $table ) {
			if ( ! isset( $columns[ $table ] ) || ( null !== $tables && ! in_array( $table, $tables, true ) ) ) {
				continue;
			}
			$module = '';
			foreach ( DataSchema::modules() as $slug => $list ) {
				if ( in_array( $table, $list, true ) ) {
					$module = $slug;
				}
			}
			$fields = array();
			foreach ( $columns[ $table ] as $name => $sql_type ) {
				$info     = self::field( $table, $name );
				$ref      = $refs[ $table ][ $name ] ?? '';
				$poly_ref = '';
				foreach ( $poly[ $table ] ?? array() as $pair ) {
					if ( $pair[1] === $name ) {
						$poly_ref = __( 'entidad indicada por', 'gestion-de-proyectos' ) . ' ' . $pair[0];
					}
				}
				if ( in_array( $name, DataSchema::user_columns(), true ) && ! isset( $refs[ $table ][ $name ] ) ) {
					$ref = 'users';
				}
				$fields[] = array(
					'name'        => $name,
					'type'        => in_array( $name, DataSchema::json_columns()[ $table ] ?? array(), true ) ? 'json' : DataSchema::logical_type( $sql_type ),
					'sql_type'    => $sql_type,
					'unit'        => $info['unit'],
					'description' => $info['description'],
					'references'  => '' !== $poly_ref ? $poly_ref : $ref,
				);
			}
			$out[ $table ] = array(
				'label'       => $meta[ $table ]['label'] ?? $table,
				'description' => $meta[ $table ]['description'] ?? '',
				'module'      => $module,
				'fields'      => $fields,
			);
		}

		/**
		 * Permite completar el diccionario de datos de otros módulos.
		 *
		 * @param array $out Diccionario.
		 */
		return (array) apply_filters( 'gdp_data_dictionary', $out );
	}

	/**
	 * Diccionario como filas planas (tabla, campo, tipo, unidad, significado, referencia).
	 *
	 * @param string[]|null $tables Tablas.
	 * @return array<int,array<int,string>>
	 */
	public static function rows( ?array $tables = null ): array {
		$rows = array( array( __( 'Tabla', 'gestion-de-proyectos' ), __( 'Campo', 'gestion-de-proyectos' ), __( 'Tipo', 'gestion-de-proyectos' ), __( 'Tipo SQL', 'gestion-de-proyectos' ), __( 'Unidad', 'gestion-de-proyectos' ), __( 'Significado', 'gestion-de-proyectos' ), __( 'Referencia', 'gestion-de-proyectos' ) ) );
		foreach ( self::build( $tables ) as $table => $def ) {
			foreach ( $def['fields'] as $f ) {
				$rows[] = array( $table, $f['name'], $f['type'], $f['sql_type'], $f['unit'], $f['description'], $f['references'] );
			}
		}

		return $rows;
	}

	/**
	 * Diccionario en CSV.
	 *
	 * @param string[]|null $tables Tablas.
	 * @return string
	 */
	public static function to_csv( ?array $tables = null ): string {
		return Exporter::csv( self::rows( $tables ) );
	}

	/**
	 * Unidad y significado de un campo.
	 *
	 * @param string $table Tabla.
	 * @param string $name  Columna.
	 * @return array{unit:string,description:string}
	 */
	private static function field( string $table, string $name ): array {
		$specific = self::fields()[ $table ][ $name ] ?? null;
		if ( null !== $specific ) {
			return is_array( $specific ) ? array( 'unit' => (string) ( $specific[1] ?? '' ), 'description' => (string) $specific[0] ) : array( 'unit' => '', 'description' => (string) $specific );
		}
		$common = self::common()[ $name ] ?? null;
		if ( null !== $common ) {
			return is_array( $common ) ? array( 'unit' => (string) ( $common[1] ?? '' ), 'description' => (string) $common[0] ) : array( 'unit' => '', 'description' => (string) $common );
		}

		return array( 'unit' => '', 'description' => '' );
	}

	/**
	 * Descripciones de columnas comunes a varias tablas.
	 *
	 * @return array<string,string|array{0:string,1:string}>
	 */
	private static function common(): array {
		return array(
			'id'           => __( 'Identificador interno, autoincremental; no es estable entre sitios.', 'gestion-de-proyectos' ),
			'project_id'   => __( 'Proyecto al que pertenece el registro (0 = global del sitio).', 'gestion-de-proyectos' ),
			'version'      => __( 'Número de versión del registro para el control optimista; sube con cada modificación.', 'gestion-de-proyectos' ),
			'created_by'   => __( 'Usuario del sitio que creó el registro.', 'gestion-de-proyectos' ),
			'created_at'   => array( __( 'Fecha y hora de creación.', 'gestion-de-proyectos' ), 'UTC' ),
			'updated_at'   => array( __( 'Fecha y hora de la última modificación.', 'gestion-de-proyectos' ), 'UTC' ),
			'user_id'      => __( 'Usuario del sitio.', 'gestion-de-proyectos' ),
			'owner_id'     => __( 'Usuario responsable.', 'gestion-de-proyectos' ),
			'activity_id'  => __( 'Actividad del cronograma relacionada (0 = ninguna).', 'gestion-de-proyectos' ),
			'document_id'  => __( 'Documento del control documental relacionado (0 = ninguno).', 'gestion-de-proyectos' ),
			'supplier_id'  => __( 'Proveedor.', 'gestion-de-proyectos' ),
			'purchase_id'  => __( 'Compra.', 'gestion-de-proyectos' ),
			'meeting_id'   => __( 'Reunión.', 'gestion-de-proyectos' ),
			'quote_id'     => __( 'Cotización.', 'gestion-de-proyectos' ),
			'notes'        => __( 'Notas internas de texto libre.', 'gestion-de-proyectos' ),
			'description'  => __( 'Descripción de texto libre.', 'gestion-de-proyectos' ),
			'sort_order'   => __( 'Posición de ordenamiento.', 'gestion-de-proyectos' ),
			'status'       => __( 'Estado del registro (valores del módulo).', 'gestion-de-proyectos' ),
			'code'         => __( 'Código legible, único dentro de su ámbito y estable; es la referencia en exportaciones e importaciones.', 'gestion-de-proyectos' ),
			'name'         => __( 'Nombre.', 'gestion-de-proyectos' ),
			'seq_no'       => __( 'Correlativo con el que se formó el código.', 'gestion-de-proyectos' ),
			'currency'     => __( 'Moneda: CLP (pesos), UF (unidades de fomento) o USD (dólares).', 'gestion-de-proyectos' ),
			'amount_net'   => array( __( 'Monto neto en la moneda indicada.', 'gestion-de-proyectos' ), __( 'moneda del registro', 'gestion-de-proyectos' ) ),
			'tax_rate'     => array( __( 'Tasa de impuesto aplicada.', 'gestion-de-proyectos' ), '%' ),
			'amount_total' => array( __( 'Monto total con impuesto en la moneda indicada.', 'gestion-de-proyectos' ), __( 'moneda del registro', 'gestion-de-proyectos' ) ),
			'active'       => __( 'Registro vigente (1) o retirado (0).', 'gestion-de-proyectos' ),
			'label'        => __( 'Etiqueta legible.', 'gestion-de-proyectos' ),
			'percent'      => array( __( 'Avance.', 'gestion-de-proyectos' ), '%' ),
			'duration'     => array( __( 'Duración.', 'gestion-de-proyectos' ), __( 'días hábiles', 'gestion-de-proyectos' ) ),
			'cost_planned' => array( __( 'Costo planificado.', 'gestion-de-proyectos' ), 'CLP' ),
			'start_date'   => __( 'Fecha de inicio.', 'gestion-de-proyectos' ),
			'end_date'     => __( 'Fecha de término.', 'gestion-de-proyectos' ),
			'kind'         => __( 'Tipo o clase del registro (valores del módulo).', 'gestion-de-proyectos' ),
			'note'         => __( 'Nota breve.', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Etiqueta y descripción de cada tabla.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	private static function tables(): array {
		return array(
			'projects'            => array( 'label' => __( 'Proyectos', 'gestion-de-proyectos' ), 'description' => __( 'Ficha de cada proyecto: código, nombre, financiador, fechas, presupuesto y ajustes.', 'gestion-de-proyectos' ) ),
			'project_members'     => array( 'label' => __( 'Equipo', 'gestion-de-proyectos' ), 'description' => __( 'Usuarios del sitio que integran cada proyecto y su perfil.', 'gestion-de-proyectos' ) ),
			'catalog_items'       => array( 'label' => __( 'Catálogos', 'gestion-de-proyectos' ), 'description' => __( 'Valores configurables (frentes de trabajo, tipos de documento, etapas de compra, partidas), globales o por proyecto.', 'gestion-de-proyectos' ) ),
			'calendars'           => array( 'label' => __( 'Calendarios laborales', 'gestion-de-proyectos' ), 'description' => __( 'Días laborables de la semana por calendario.', 'gestion-de-proyectos' ) ),
			'calendar_exceptions' => array( 'label' => __( 'Excepciones de calendario', 'gestion-de-proyectos' ), 'description' => __( 'Feriados y días laborables excepcionales.', 'gestion-de-proyectos' ) ),
			'activities'          => array( 'label' => __( 'Actividades', 'gestion-de-proyectos' ), 'description' => __( 'Estructura de desglose y cronograma: resúmenes, actividades e hitos con fechas calculadas por ruta crítica.', 'gestion-de-proyectos' ) ),
			'dependencies'        => array( 'label' => __( 'Dependencias', 'gestion-de-proyectos' ), 'description' => __( 'Relaciones de precedencia entre actividades con tipo y desfase.', 'gestion-de-proyectos' ) ),
			'assignments'         => array( 'label' => __( 'Asignaciones', 'gestion-de-proyectos' ), 'description' => __( 'Personas asignadas a cada actividad con su dedicación.', 'gestion-de-proyectos' ) ),
			'progress'            => array( 'label' => __( 'Avances', 'gestion-de-proyectos' ), 'description' => __( 'Historial de avances registrados por actividad (solo se anexa).', 'gestion-de-proyectos' ) ),
			'baselines'           => array( 'label' => __( 'Líneas base', 'gestion-de-proyectos' ), 'description' => __( 'Instantáneas del cronograma aprobadas con motivo y fecha.', 'gestion-de-proyectos' ) ),
			'baseline_activities' => array( 'label' => __( 'Actividades de línea base', 'gestion-de-proyectos' ), 'description' => __( 'Fechas, duración y avance de cada actividad en una línea base.', 'gestion-de-proyectos' ) ),
			'schedule_snapshots'  => array( 'label' => __( 'Instantáneas semanales', 'gestion-de-proyectos' ), 'description' => __( 'Estado del cronograma al cierre de cada semana para el informe semanal.', 'gestion-de-proyectos' ) ),
			'suppliers'           => array( 'label' => __( 'Proveedores', 'gestion-de-proyectos' ), 'description' => __( 'Proveedores globales o del proyecto con contacto y categoría.', 'gestion-de-proyectos' ) ),
			'budget_lines'        => array( 'label' => __( 'Partidas presupuestarias', 'gestion-de-proyectos' ), 'description' => __( 'Monto asignado a cada partida del proyecto.', 'gestion-de-proyectos' ) ),
			'uf_rates'            => array( 'label' => __( 'Unidad de fomento', 'gestion-de-proyectos' ), 'description' => __( 'Valor diario de la unidad de fomento en pesos.', 'gestion-de-proyectos' ) ),
			'documents'           => array( 'label' => __( 'Documentos', 'gestion-de-proyectos' ), 'description' => __( 'Cartas, oficios, contratos, órdenes, cotizaciones, facturas, actas e informes con su numeración, estado y plazo de respuesta.', 'gestion-de-proyectos' ) ),
			'document_versions'   => array( 'label' => __( 'Versiones de archivo', 'gestion-de-proyectos' ), 'description' => __( 'Archivos adjuntos de cada documento, numerados, con huella y nota.', 'gestion-de-proyectos' ) ),
			'purchases'           => array( 'label' => __( 'Compras', 'gestion-de-proyectos' ), 'description' => __( 'Adquisiciones con partida, proveedor, etapa, montos y fechas de orden, factura y pago.', 'gestion-de-proyectos' ) ),
			'quotes'              => array( 'label' => __( 'Cotizaciones', 'gestion-de-proyectos' ), 'description' => __( 'Cotizaciones solicitadas y recibidas por compra.', 'gestion-de-proyectos' ) ),
			'quote_items'         => array( 'label' => __( 'Ítems de cotización', 'gestion-de-proyectos' ), 'description' => __( 'Líneas de cada cotización con cantidad, precio y si se contrata.', 'gestion-de-proyectos' ) ),
			'purchase_stages'     => array( 'label' => __( 'Etapas de compra', 'gestion-de-proyectos' ), 'description' => __( 'Historial del ciclo de cada compra.', 'gestion-de-proyectos' ) ),
			'meetings'            => array( 'label' => __( 'Reuniones', 'gestion-de-proyectos' ), 'description' => __( 'Actas de reunión con fecha, tipo, temas, resumen y transcripción.', 'gestion-de-proyectos' ) ),
			'meeting_attendees'   => array( 'label' => __( 'Asistentes', 'gestion-de-proyectos' ), 'description' => __( 'Asistentes a cada reunión, usuarios del sitio o externos.', 'gestion-de-proyectos' ) ),
			'agreements'          => array( 'label' => __( 'Acuerdos', 'gestion-de-proyectos' ), 'description' => __( 'Acuerdos tomados en reuniones con responsable, plazo, estado y seguimiento.', 'gestion-de-proyectos' ) ),
			'links'               => array( 'label' => __( 'Vínculos', 'gestion-de-proyectos' ), 'description' => __( 'Relaciones entre entidades de cualquier módulo.', 'gestion-de-proyectos' ) ),
			'external_refs'       => array( 'label' => __( 'Referencias externas', 'gestion-de-proyectos' ), 'description' => __( 'Número y estado de una entidad en sistemas institucionales.', 'gestion-de-proyectos' ) ),
			'audit_log'           => array( 'label' => __( 'Bitácora', 'gestion-de-proyectos' ), 'description' => __( 'Registro de auditoría de creaciones, modificaciones, eliminaciones y llamadas del conector.', 'gestion-de-proyectos' ) ),
		);
	}

	/**
	 * Descripciones específicas: tabla => columna => descripción o [descripción, unidad].
	 *
	 * @return array<string,array<string,string|array{0:string,1:string}>>
	 */
	private static function fields(): array {
		return array(
			'projects'            => array(
				'short_name'       => __( 'Nombre corto para menús y encabezados.', 'gestion-de-proyectos' ),
				'funder'           => __( 'Entidad financiadora.', 'gestion-de-proyectos' ),
				'funding_code'     => __( 'Código del proyecto en el financiador.', 'gestion-de-proyectos' ),
				'executing_entity' => __( 'Entidad ejecutora.', 'gestion-de-proyectos' ),
				'status'           => __( 'planificacion, ejecucion, suspendido, cierre, cerrado.', 'gestion-de-proyectos' ),
				'budget_total'     => array( __( 'Presupuesto total aprobado.', 'gestion-de-proyectos' ), __( 'moneda del proyecto', 'gestion-de-proyectos' ) ),
				'settings'         => __( 'Ajustes del proyecto (patrones de numeración, reglas de compras); las claves secretas no se exportan.', 'gestion-de-proyectos' ),
			),
			'project_members'     => array(
				'role' => __( 'Perfil en el proyecto: director, ingeniero, investigador, apoyo, observador.', 'gestion-de-proyectos' ),
			),
			'catalog_items'       => array(
				'catalog' => __( 'Catálogo al que pertenece el valor (work_front, document_type, purchase_stage, budget_line, ...).', 'gestion-de-proyectos' ),
				'slug'    => __( 'Clave estable del valor.', 'gestion-de-proyectos' ),
				'meta'    => __( 'Atributos adicionales del valor (sentido y numeración de un tipo de documento, por ejemplo).', 'gestion-de-proyectos' ),
			),
			'calendars'           => array(
				'weekdays'   => __( 'Días laborables como números ISO separados por coma (1 = lunes ... 7 = domingo).', 'gestion-de-proyectos' ),
				'is_default' => __( 'Calendario que usa el cronograma del proyecto.', 'gestion-de-proyectos' ),
			),
			'calendar_exceptions' => array(
				'calendar_id'    => __( 'Calendario.', 'gestion-de-proyectos' ),
				'exception_date' => __( 'Fecha de la excepción.', 'gestion-de-proyectos' ),
				'working'        => __( '1 si el día se trabaja pese a la regla semanal; 0 si es feriado.', 'gestion-de-proyectos' ),
				'label'          => __( 'Nombre del feriado o motivo.', 'gestion-de-proyectos' ),
			),
			'activities'          => array(
				'parent_id'          => __( 'Resumen que contiene la actividad (0 = primer nivel).', 'gestion-de-proyectos' ),
				'code'               => __( 'Código jerárquico (1.2.3) estable dentro del proyecto.', 'gestion-de-proyectos' ),
				'kind'               => __( 'summary (resumen), activity (actividad) o milestone (hito).', 'gestion-de-proyectos' ),
				'work_front'         => __( 'Frente de trabajo (valor del catálogo).', 'gestion-de-proyectos' ),
				'status'             => __( 'pendiente, en_curso, terminada, suspendida, cancelada.', 'gestion-de-proyectos' ),
				'priority'           => __( '1 alta, 2 normal, 3 baja.', 'gestion-de-proyectos' ),
				'duration'           => array( __( 'Duración planificada (0 en hitos).', 'gestion-de-proyectos' ), __( 'días hábiles', 'gestion-de-proyectos' ) ),
				'constraint_type'    => __( 'Restricción: asap, snet (no antes de), snlt (no después de), fnet, fnlt, mso (debe empezar el), mfo (debe terminar el).', 'gestion-de-proyectos' ),
				'constraint_date'    => __( 'Fecha de la restricción.', 'gestion-de-proyectos' ),
				'actual_start'       => __( 'Inicio real.', 'gestion-de-proyectos' ),
				'actual_finish'      => __( 'Término real.', 'gestion-de-proyectos' ),
				'deliverable'        => __( 'Entregable comprometido.', 'gestion-de-proyectos' ),
				'budget_line'        => __( 'Partida presupuestaria (valor del catálogo).', 'gestion-de-proyectos' ),
				'start_date'         => __( 'Inicio temprano calculado por el motor de programación.', 'gestion-de-proyectos' ),
				'end_date'           => __( 'Término temprano calculado.', 'gestion-de-proyectos' ),
				'late_start'         => __( 'Inicio tardío calculado.', 'gestion-de-proyectos' ),
				'late_finish'        => __( 'Término tardío calculado.', 'gestion-de-proyectos' ),
				'total_float'        => array( __( 'Holgura total calculada.', 'gestion-de-proyectos' ), __( 'días hábiles', 'gestion-de-proyectos' ) ),
				'free_float'         => array( __( 'Holgura libre calculada.', 'gestion-de-proyectos' ), __( 'días hábiles', 'gestion-de-proyectos' ) ),
				'is_critical'        => __( 'Pertenece a la ruta crítica (calculado).', 'gestion-de-proyectos' ),
				'schedule_conflicts' => __( 'Avisos del motor de programación (calculado).', 'gestion-de-proyectos' ),
			),
			'dependencies'        => array(
				'predecessor_type' => __( 'Tipo de la predecesora (activity).', 'gestion-de-proyectos' ),
				'predecessor_id'   => __( 'Predecesora.', 'gestion-de-proyectos' ),
				'successor_id'     => __( 'Sucesora.', 'gestion-de-proyectos' ),
				'type'             => __( 'FS fin a inicio, SS inicio a inicio, FF fin a fin, SF inicio a fin.', 'gestion-de-proyectos' ),
				'lag_days'         => array( __( 'Desfase (negativo = adelanto).', 'gestion-de-proyectos' ), __( 'días hábiles', 'gestion-de-proyectos' ) ),
			),
			'assignments'         => array(
				'role'       => __( 'Papel en la actividad.', 'gestion-de-proyectos' ),
				'allocation' => array( __( 'Dedicación.', 'gestion-de-proyectos' ), '%' ),
			),
			'progress'            => array(
				'reported_at'      => array( __( 'Fecha y hora del registro.', 'gestion-de-proyectos' ), 'UTC' ),
				'previous_percent' => array( __( 'Avance anterior.', 'gestion-de-proyectos' ), '%' ),
				'source'           => __( 'Origen del registro: admin, connector, import, cron.', 'gestion-de-proyectos' ),
			),
			'baselines'           => array(
				'is_current' => __( 'Línea base vigente.', 'gestion-de-proyectos' ),
			),
			'baseline_activities' => array(
				'baseline_id' => __( 'Línea base.', 'gestion-de-proyectos' ),
			),
			'schedule_snapshots'  => array(
				'week_start' => __( 'Lunes de la semana.', 'gestion-de-proyectos' ),
				'taken_at'   => array( __( 'Momento de la instantánea.', 'gestion-de-proyectos' ), 'UTC' ),
				'stats'      => __( 'Resumen estadístico.', 'gestion-de-proyectos' ),
				'data'       => __( 'Actividades con sus fechas y avances en esa semana.', 'gestion-de-proyectos' ),
			),
			'suppliers'           => array(
				'tax_id'   => __( 'Identificación tributaria.', 'gestion-de-proyectos' ),
				'category' => __( 'Rubro.', 'gestion-de-proyectos' ),
			),
			'budget_lines'        => array(
				'line_code'    => __( 'Código de la partida (valor del catálogo budget_line).', 'gestion-de-proyectos' ),
				'assigned_clp' => array( __( 'Monto asignado.', 'gestion-de-proyectos' ), 'CLP' ),
			),
			'uf_rates'            => array(
				'rate_date'  => __( 'Fecha del valor.', 'gestion-de-proyectos' ),
				'value_clp'  => array( __( 'Valor de una unidad de fomento.', 'gestion-de-proyectos' ), 'CLP' ),
				'source'     => __( 'manual o mindicador.', 'gestion-de-proyectos' ),
				'fetched_at' => array( __( 'Cuándo se obtuvo.', 'gestion-de-proyectos' ), 'UTC' ),
			),
			'documents'           => array(
				'type'            => __( 'Tipo de documento (valor del catálogo document_type).', 'gestion-de-proyectos' ),
				'direction'       => __( 'out (emitido), in (recibido) o internal.', 'gestion-de-proyectos' ),
				'doc_number'      => __( 'Número del documento, correlativo cuando el tipo se numera.', 'gestion-de-proyectos' ),
				'doc_date'        => __( 'Fecha del documento.', 'gestion-de-proyectos' ),
				'sender'          => __( 'Emisor.', 'gestion-de-proyectos' ),
				'recipient'       => __( 'Destinatario.', 'gestion-de-proyectos' ),
				'subject'         => __( 'Asunto.', 'gestion-de-proyectos' ),
				'body'            => __( 'Cuerpo o resumen.', 'gestion-de-proyectos' ),
				'status'          => __( 'borrador, enviado, recibido, respondido, aprobado, cerrado, anulado.', 'gestion-de-proyectos' ),
				'response_due'    => __( 'Plazo de respuesta.', 'gestion-de-proyectos' ),
				'responded_at'    => __( 'Fecha de respuesta.', 'gestion-de-proyectos' ),
				'current_version' => __( 'Número de la versión de archivo vigente.', 'gestion-de-proyectos' ),
			),
			'document_versions'   => array(
				'version_no' => __( 'Número de versión del archivo.', 'gestion-de-proyectos' ),
				'filename'   => __( 'Nombre original del archivo.', 'gestion-de-proyectos' ),
				'path'       => __( 'Ruta relativa dentro del directorio privado.', 'gestion-de-proyectos' ),
				'mime'       => __( 'Tipo de contenido.', 'gestion-de-proyectos' ),
				'byte_size'  => array( __( 'Tamaño.', 'gestion-de-proyectos' ), 'bytes' ),
				'note'       => __( 'Huella SHA-256 y nota de la versión.', 'gestion-de-proyectos' ),
			),
			'purchases'           => array(
				'title'           => __( 'Título de la compra.', 'gestion-de-proyectos' ),
				'budget_line'     => __( 'Partida presupuestaria.', 'gestion-de-proyectos' ),
				'stage'           => __( 'Etapa del ciclo (valor del catálogo purchase_stage).', 'gestion-de-proyectos' ),
				'status'          => __( 'abierta, cerrada, anulada.', 'gestion-de-proyectos' ),
				'chosen_quote_id' => __( 'Cotización elegida.', 'gestion-de-proyectos' ),
				'amount_clp'      => array( __( 'Total convertido a pesos con el valor de la unidad de fomento de la fecha de referencia.', 'gestion-de-proyectos' ), 'CLP' ),
				'uf_rate'         => array( __( 'Valor de la unidad de fomento usado en la conversión.', 'gestion-de-proyectos' ), 'CLP' ),
				'uf_date'         => __( 'Fecha de referencia de la conversión.', 'gestion-de-proyectos' ),
				'approved_by'     => __( 'Usuario que aprobó.', 'gestion-de-proyectos' ),
				'approved_at'     => array( __( 'Fecha y hora de aprobación.', 'gestion-de-proyectos' ), 'UTC' ),
				'order_number'    => __( 'Número de orden de compra.', 'gestion-de-proyectos' ),
				'order_date'      => __( 'Fecha de la orden.', 'gestion-de-proyectos' ),
				'invoice_number'  => __( 'Número de factura.', 'gestion-de-proyectos' ),
				'invoice_date'    => __( 'Fecha de factura.', 'gestion-de-proyectos' ),
				'paid_at'         => __( 'Fecha de pago.', 'gestion-de-proyectos' ),
				'expected_at'     => __( 'Entrega esperada.', 'gestion-de-proyectos' ),
				'received_at'     => __( 'Entrega recibida.', 'gestion-de-proyectos' ),
			),
			'quotes'              => array(
				'quote_number' => __( 'Número de la cotización del proveedor.', 'gestion-de-proyectos' ),
				'status'       => __( 'solicitada, recibida, elegida, descartada.', 'gestion-de-proyectos' ),
				'requested_at' => __( 'Fecha de solicitud.', 'gestion-de-proyectos' ),
				'quote_date'   => __( 'Fecha de emisión.', 'gestion-de-proyectos' ),
				'valid_until'  => __( 'Fecha de validez.', 'gestion-de-proyectos' ),
			),
			'quote_items'         => array(
				'quantity'   => __( 'Cantidad.', 'gestion-de-proyectos' ),
				'unit'       => __( 'Unidad de medida.', 'gestion-de-proyectos' ),
				'unit_price' => array( __( 'Precio unitario.', 'gestion-de-proyectos' ), __( 'moneda de la cotización', 'gestion-de-proyectos' ) ),
				'line_total' => array( __( 'Total de la línea.', 'gestion-de-proyectos' ), __( 'moneda de la cotización', 'gestion-de-proyectos' ) ),
				'selected'   => __( 'Se contrata (1) o no (0).', 'gestion-de-proyectos' ),
			),
			'purchase_stages'     => array(
				'stage'      => __( 'Etapa alcanzada.', 'gestion-de-proyectos' ),
				'stage_date' => __( 'Fecha de la etapa.', 'gestion-de-proyectos' ),
			),
			'meetings'            => array(
				'title'               => __( 'Título de la reunión.', 'gestion-de-proyectos' ),
				'kind'                => __( 'equipo, financiador, proveedor, comite, terreno, otra.', 'gestion-de-proyectos' ),
				'status'              => __( 'programada, realizada, cancelada.', 'gestion-de-proyectos' ),
				'meeting_date'        => __( 'Fecha de la reunión.', 'gestion-de-proyectos' ),
				'start_time'          => __( 'Hora de inicio.', 'gestion-de-proyectos' ),
				'end_time'            => __( 'Hora de término.', 'gestion-de-proyectos' ),
				'location'            => __( 'Lugar o enlace.', 'gestion-de-proyectos' ),
				'agenda'              => __( 'Temas tratados.', 'gestion-de-proyectos' ),
				'summary'             => __( 'Resumen.', 'gestion-de-proyectos' ),
				'transcript'          => __( 'Transcripción.', 'gestion-de-proyectos' ),
				'organizer_id'        => __( 'Usuario que organiza.', 'gestion-de-proyectos' ),
				'minutes_document_id' => __( 'Documento del acta.', 'gestion-de-proyectos' ),
			),
			'meeting_attendees'   => array(
				'name'         => __( 'Nombre (asistentes externos).', 'gestion-de-proyectos' ),
				'organization' => __( 'Organización.', 'gestion-de-proyectos' ),
				'attended'     => __( 'Asistió (1) o se excusó (0).', 'gestion-de-proyectos' ),
			),
			'agreements'          => array(
				'owner_name'             => __( 'Responsable cuando no es usuario del sitio.', 'gestion-de-proyectos' ),
				'due_date'               => __( 'Plazo comprometido.', 'gestion-de-proyectos' ),
				'status'                 => __( 'pendiente, en_curso, cumplido, cancelado (si hay actividad, su estado manda).', 'gestion-de-proyectos' ),
				'fulfilled_at'           => __( 'Fecha de cumplimiento.', 'gestion-de-proyectos' ),
				'origin'                 => __( 'manual, propuesto (desde texto) o conector.', 'gestion-de-proyectos' ),
				'follow_up'              => __( 'Registro de revisiones: reunión, fecha, estado anterior y nuevo, nota, usuario.', 'gestion-de-proyectos' ),
				'last_review_meeting_id' => __( 'Última reunión en que se revisó.', 'gestion-de-proyectos' ),
			),
			'links'               => array(
				'from_type'     => __( 'Tipo de la entidad origen.', 'gestion-de-proyectos' ),
				'from_id'       => __( 'Entidad origen.', 'gestion-de-proyectos' ),
				'to_type'       => __( 'Tipo de la entidad destino.', 'gestion-de-proyectos' ),
				'to_id'         => __( 'Entidad destino.', 'gestion-de-proyectos' ),
				'relation_type' => __( 'responds_to, refers_to, supports, related.', 'gestion-de-proyectos' ),
			),
			'external_refs'       => array(
				'entity_type' => __( 'Tipo de la entidad.', 'gestion-de-proyectos' ),
				'entity_id'   => __( 'Entidad.', 'gestion-de-proyectos' ),
				'system_name' => __( 'Sistema externo.', 'gestion-de-proyectos' ),
				'ref_number'  => __( 'Número o identificador en ese sistema.', 'gestion-de-proyectos' ),
				'ref_status'  => __( 'Estado en ese sistema.', 'gestion-de-proyectos' ),
				'url'         => __( 'Enlace.', 'gestion-de-proyectos' ),
				'updated_by'  => __( 'Usuario que actualizó.', 'gestion-de-proyectos' ),
			),
			'audit_log'           => array(
				'channel'      => __( 'admin, connector, import, cron, cli.', 'gestion-de-proyectos' ),
				'entity_type'  => __( 'Tipo de la entidad afectada.', 'gestion-de-proyectos' ),
				'entity_id'    => __( 'Entidad afectada.', 'gestion-de-proyectos' ),
				'action'       => __( 'Acción registrada.', 'gestion-de-proyectos' ),
				'summary'      => __( 'Resumen legible.', 'gestion-de-proyectos' ),
				'before_data'  => __( 'Estado anterior.', 'gestion-de-proyectos' ),
				'after_data'   => __( 'Estado posterior.', 'gestion-de-proyectos' ),
				'operation_id' => __( 'Operación asociada.', 'gestion-de-proyectos' ),
				'ip'           => __( 'Dirección de origen.', 'gestion-de-proyectos' ),
			),
		);
	}
}
