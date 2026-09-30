<?php
/**
 * Contrato de los manejadores de operaciones.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Operations;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Un manejador encapsula las escrituras sobre un tipo de entidad en cuatro
 * pasos separables: validar, previsualizar, aplicar y revertir. La capa de
 * operaciones los combina para ofrecer el flujo en dos tiempos (proponer y
 * confirmar) tanto al conector como a la importación de datos.
 */
interface HandlerInterface {

	/**
	 * Clave única del manejador (por ejemplo, "project").
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Acciones admitidas (por ejemplo, create, update, delete).
	 *
	 * @return string[]
	 */
	public function actions(): array;

	/**
	 * Comprueba que el usuario puede ejecutar la acción en el proyecto.
	 *
	 * @param string $action     Acción.
	 * @param array  $payload    Datos de la operación.
	 * @param int    $project_id Proyecto.
	 * @param int    $user_id    Usuario.
	 * @return bool
	 */
	public function can( string $action, array $payload, int $project_id, int $user_id ): bool;

	/**
	 * Valida y normaliza los datos. Devuelve el payload limpio o un error.
	 *
	 * @param string $action     Acción.
	 * @param array  $payload    Datos.
	 * @param int    $project_id Proyecto.
	 * @return array|WP_Error
	 */
	public function validate( string $action, array $payload, int $project_id );

	/**
	 * Describe el efecto de la operación sin ejecutarla.
	 *
	 * Debe devolver, al menos: summary (texto), changes (campo => [before, after]),
	 * warnings (lista de textos) y conflicts (lista de textos; si no está vacía,
	 * la operación no puede confirmarse sin volver a proponerse).
	 *
	 * @param string $action     Acción.
	 * @param array  $payload    Datos ya validados.
	 * @param int    $project_id Proyecto.
	 * @return array{summary:string,changes:array,warnings:array,conflicts:array}
	 */
	public function preview( string $action, array $payload, int $project_id ): array;

	/**
	 * Ejecuta la operación. Devuelve el estado anterior (para revertir) y el resultado.
	 *
	 * @param string $action     Acción.
	 * @param array  $payload    Datos ya validados.
	 * @param int    $project_id Proyecto.
	 * @return array{before:array|null,result:array}|WP_Error
	 */
	public function apply( string $action, array $payload, int $project_id );

	/**
	 * Deshace una operación aplicada.
	 *
	 * @param string     $action     Acción.
	 * @param array|null $before     Estado anterior guardado en apply().
	 * @param array      $result     Resultado guardado en apply().
	 * @param int        $project_id Proyecto.
	 * @return bool|WP_Error
	 */
	public function revert( string $action, ?array $before, array $result, int $project_id );
}
