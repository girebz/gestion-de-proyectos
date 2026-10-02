<?php
/**
 * Código corto del tablero de finanzas para el sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

use GDP\Core\Access;
use GDP\Modules\Dashboards\DashboardData;
use GDP\Modules\Dashboards\DashboardsModule;

defined( 'ABSPATH' ) || exit;

/**
 * [gdp_finanzas proyecto="CODIGO" vista="resumen"] muestra en una página
 * privada el estado financiero completo del proyecto, en modo lectura. Las
 * cifras son las más sensibles del plugin, de modo que, además de la regla
 * del tablero del equipo (sesión iniciada y, si así se configuró,
 * pertenencia al proyecto), se exige el permiso de ver las finanzas: quien
 * no lo tiene recibe un aviso sin ninguna cifra.
 *
 * Sin el atributo vista se muestran todas las pestañas; con él, solo la
 * indicada (resumen, cuotas, items, pagos, rendiciones, caja, convenio,
 * paso o reportes), para armar páginas con una sola sección.
 */
final class BoardShortcode {

	public const TAG = 'gdp_finanzas';

	/**
	 * Registra el código corto y los recursos.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_assets' ), 11 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	/**
	 * Registra las hojas de estilo y el script (se encolan solo cuando se usan).
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! wp_style_is( 'gdp-front', 'registered' ) ) {
			wp_register_style( 'gdp-front-base', GDP_URL . 'assets/css/admin.css', array(), GDP_VERSION );
			wp_register_style( 'gdp-front', GDP_URL . 'assets/css/frontend.css', array( 'gdp-front-base' ), GDP_VERSION );
		}
		wp_register_style( 'gdp-front-finance', GDP_URL . 'assets/css/finance-board.css', array( 'gdp-front' ), GDP_VERSION );
		wp_register_script( 'gdp-front-finance-copy', GDP_URL . 'assets/js/finance.js', array(), GDP_VERSION, true );
		wp_register_script( 'gdp-front-finance', GDP_URL . 'assets/js/finance-board.js', array( 'gdp-front-finance-copy' ), GDP_VERSION, true );
	}

	/**
	 * Encola los recursos en la cabecera cuando el contenido de la entrada
	 * lleva el código (desde un foro o un widget, se encolan al ejecutarse).
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			// Sin sesión solo se muestra un aviso: basta la hoja base.
			if ( is_user_logged_in() ) {
				self::enqueue();
			} else {
				wp_enqueue_style( 'gdp-front' );
			}
		}
	}

	/**
	 * Encola estilos y script.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		static $done = false;
		wp_enqueue_style( 'gdp-front-finance' );
		wp_enqueue_script( 'gdp-front-finance' );
		if ( ! $done ) {
			$done = true;
			wp_localize_script(
				'gdp-front-finance-copy',
				'gdpFinance',
				array(
					'copied' => __( 'Copiado', 'gestion-de-proyectos' ),
					'copy'   => __( 'Copiar', 'gestion-de-proyectos' ),
				)
			);
		}
	}

	/**
	 * Ejecuta el código corto.
	 *
	 * @param array<string,string>|string $atts    Atributos.
	 * @param string|null                 $content Contenido (no se usa).
	 * @param string                      $tag     Código corto.
	 * @return string
	 */
	public static function shortcode( $atts, $content = '', string $tag = '' ): string {
		$atts = shortcode_atts(
			array(
				'proyecto' => '',
				'vista'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			'' !== $tag ? $tag : self::TAG
		);
		wp_enqueue_style( 'gdp-front' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$here = is_singular() ? (string) get_permalink() : home_url( '/' );
			return self::notice( esc_html__( 'Las finanzas del proyecto son solo para el equipo autorizado.', 'gestion-de-proyectos' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Inicie sesión', 'gestion-de-proyectos' ) . '</a>' );
		}
		$project = DashboardsModule::resolve_project( (string) $atts['proyecto'] );
		if ( ! $project ) {
			if ( ! Access::is_manager( $user_id ) ) {
				return '';
			}
			/* translators: código corto. */
			return self::notice( esc_html( sprintf( __( '%s: no se encontró el proyecto. Indique su código en el atributo proyecto. Solo los administradores ven este aviso.', 'gestion-de-proyectos' ), '[' . self::TAG . ']' ) ) );
		}
		$project_id = (int) $project['id'];
		if ( ! DashboardData::team_allowed( $project_id, $user_id ) || ! Access::can( 'finance.view', $project_id, $user_id ) ) {
			return self::notice( esc_html__( 'Su cuenta no tiene permiso para ver las finanzas de este proyecto.', 'gestion-de-proyectos' ) );
		}

		$fixed = self::view_slug( (string) $atts['vista'] );
		$ctx   = new BoardContext(
			$project,
			BoardContext::current_url(),
			array(
				'fixed'  => $fixed,
				'panel'  => Access::can_access( $user_id ),
				'edit'   => Access::can( 'finance.edit', $project_id, $user_id ),
				'export' => Access::can( 'finance.export', $project_id, $user_id ),
				'docs'   => Access::can( 'documents.view', $project_id, $user_id ),
			)
		);
		$data  = BoardData::build( $project );
		$route = self::route( $ctx, $data, $fixed );
		self::enqueue();

		ob_start();
		echo '<div class="gdp-front gdp-wrap gdp-fin" id="' . esc_attr( $ctx->anchor() ) . '">';
		BoardView::render( $data, $ctx, $route['tab'], $route );
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Pestaña y destino de la petición: la vista fija del atributo, la tarea
	 * elegida en el menú de ayuda o los parámetros de la dirección.
	 *
	 * @param BoardContext        $ctx   Contexto.
	 * @param array<string,mixed> $data  Datos.
	 * @param string              $fixed Vista fija ('' si hay pestañas).
	 * @return array<string,string>
	 */
	public static function route( BoardContext $ctx, array $data, string $fixed ): array {
		$route = array(
			'tab'    => 'resumen',
			'guide'  => '',
			'entity' => '',
			'id'     => '0',
			'estado' => '',
		);
		if ( '' !== $fixed ) {
			$route['tab'] = $fixed;
		} else {
			$tab          = sanitize_key( $ctx->get( 'fin' ) );
			$route['tab'] = in_array( $tab, BoardView::TABS, true ) ? $tab : 'resumen';
		}
		// Una tarea del menú de ayuda manda, si su pestaña está en la página.
		$task  = sanitize_key( $ctx->get( 'tarea' ) );
		$tasks = '' !== $task ? BoardHelp::tasks( $data ) : array();
		if ( isset( $tasks[ $task ] ) && $ctx->reachable( (string) $tasks[ $task ]['tab'] ) ) {
			$t            = $tasks[ $task ];
			$route['tab'] = (string) $t['tab'];
			if ( 'paso' === $t['tab'] ) {
				$route['guide']  = (string) $t['guide'];
				$route['entity'] = (string) $t['entity'];
				$route['id']     = (string) $t['id'];
			} elseif ( 'pagos' === $t['tab'] ) {
				$route['estado'] = 'comprometidos' === $t['entity'] ? 'por_pagar' : 'observados';
			}
			return $route;
		}
		if ( 'paso' === $route['tab'] ) {
			$route['guide']  = sanitize_key( $ctx->get( 'guia' ) );
			$route['entity'] = sanitize_key( $ctx->get( 'ent' ) );
			$route['id']     = (string) absint( $ctx->get( 'id', '0' ) );
		}

		return $route;
	}

	/**
	 * Normaliza el atributo vista ('' si no es una pestaña conocida).
	 *
	 * @param string $value Atributo.
	 * @return string
	 */
	public static function view_slug( string $value ): string {
		$value   = sanitize_key( (string) preg_replace( '/[\s\-]+/', '_', strtolower( remove_accents( DashboardsModule::clean_attribute( $value ) ) ) ) );
		$aliases = array(
			'todo'                  => '',
			'todas'                 => '',
			'paso_a_paso'           => 'paso',
			'rendicion'             => 'paso',
			'reporte'               => 'reportes',
			'informe'               => 'reportes',
			'item'                  => 'items',
			'cuota'                 => 'cuotas',
			'pago'                  => 'pagos',
			'rendiciones_mensuales' => 'rendiciones',
		);
		$value   = array_key_exists( $value, $aliases ) ? $aliases[ $value ] : $value;

		return in_array( $value, BoardView::TABS, true ) ? $value : '';
	}

	/**
	 * Aviso dentro del contenedor del plugin.
	 *
	 * @param string $html Contenido ya escapado.
	 * @return string
	 */
	private static function notice( string $html ): string {
		return '<div class="gdp-front gdp-front--notice"><p class="gdp-front__notice">' . $html . '</p></div>';
	}

	/**
	 * Fragmento de ayuda para la pantalla de tableros.
	 *
	 * @param string $code Código del proyecto.
	 * @return array<int,array{shortcode:string,label:string,text:string}>
	 */
	public static function snippets( string $code ): array {
		return array(
			array(
				'shortcode' => '[' . self::TAG . ' proyecto="' . $code . '"]',
				'label'     => __( 'Tablero completo', 'gestion-de-proyectos' ),
				'text'      => __( 'Nueve pestañas: resumen para el director (alertas, plazo frente a ejecución, cifras por fuente, cuota siguiente, cuotas, ítems, caja y rendiciones, con gráficos), cuotas, ítems, pagos, rendiciones, caja, convenio, paso a paso de la rendición en SISREC y reportes; con menú de ayuda.', 'gestion-de-proyectos' ),
			),
			array(
				'shortcode' => '[' . self::TAG . ' proyecto="' . $code . '" vista="resumen"]',
				'label'     => __( 'Solo el resumen', 'gestion-de-proyectos' ),
				'text'      => __( 'La vista integral del director, sin pestañas. El atributo vista admite también cuotas, items, pagos, rendiciones, caja, convenio, paso o reportes.', 'gestion-de-proyectos' ),
			),
			array(
				'shortcode' => '[' . self::TAG . ' proyecto="' . $code . '" vista="paso"]',
				'label'     => __( 'Solo el paso a paso', 'gestion-de-proyectos' ),
				'text'      => __( 'Las acciones pendientes y las hojas de ejecución de SISREC con los valores listos para copiar, para la página de quien rinde.', 'gestion-de-proyectos' ),
			),
		);
	}
}
