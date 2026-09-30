<?php
/**
 * Contrato de los módulos funcionales.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Un módulo agrupa una funcionalidad (documentos, adquisiciones, laboratorio)
 * con sus tablas, pantallas, manejadores de operaciones y herramientas del
 * conector. Los módulos del núcleo están siempre activos; los demás se activan
 * por proyecto.
 */
interface ModuleInterface {

	/**
	 * Identificador (por ejemplo, "documents").
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Nombre visible.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Descripción breve.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Indica si forma parte del núcleo (no desactivable).
	 *
	 * @return bool
	 */
	public function is_core(): bool;

	/**
	 * Registra ganchos, tablas, manejadores y herramientas.
	 *
	 * @return void
	 */
	public function register(): void;
}
