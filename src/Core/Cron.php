<?php
/**
 * Tareas programadas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use GDP\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Programa los eventos diario y semanal del plugin.
 *
 * El cron interno de WordPress solo corre con visitas al sitio; para alertas
 * fiables conviene definir DISABLE_WP_CRON y llamar a wp-cron.php desde el
 * cron real del hosting (cPanel en BanaHosting lo permite). El asistente de
 * diagnóstico lo comprueba.
 */
final class Cron {

	public const DAILY  = 'gdp_daily';
	public const WEEKLY = 'gdp_weekly';

	/**
	 * Registra los manejadores de los eventos.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::DAILY, array( self::class, 'run_daily' ) );
		add_action( self::WEEKLY, array( self::class, 'run_weekly' ) );
	}

	/**
	 * Programa los eventos si no existen.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}

		if ( ! wp_next_scheduled( self::WEEKLY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::WEEKLY );
		}
	}

	/**
	 * Retira los eventos.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::WEEKLY );
	}

	/**
	 * Tareas diarias: caducar operaciones propuestas no confirmadas y
	 * disparar el gancho para los módulos (alertas de plazo, unidad de fomento).
	 *
	 * @return void
	 */
	public static function run_daily(): void {
		OperationManager::expire_stale();

		/**
		 * Tareas diarias de los módulos.
		 */
		do_action( 'gdp_daily_tasks' );
	}

	/**
	 * Tareas semanales: informe semanal y resúmenes por persona.
	 *
	 * @return void
	 */
	public static function run_weekly(): void {
		/**
		 * Tareas semanales de los módulos.
		 */
		do_action( 'gdp_weekly_tasks' );
	}

	/**
	 * Estado del cron para el diagnóstico.
	 *
	 * @return array{wp_cron_disabled:bool,next_daily:int|false,next_weekly:int|false}
	 */
	public static function status(): array {
		return array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_daily'       => wp_next_scheduled( self::DAILY ),
			'next_weekly'      => wp_next_scheduled( self::WEEKLY ),
		);
	}
}
