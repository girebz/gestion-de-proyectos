<?php
/**
 * Códigos cortos de las vistas de planificación para el sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

use GDP\Admin\Pages\PlanningPage;
use GDP\Core\Access;
use GDP\Core\Identity;
use GDP\Modules\Dashboards\DashboardData;
use GDP\Modules\Dashboards\DashboardsModule;

defined( 'ABSPATH' ) || exit;

/**
 * Seis códigos cortos reservados a usuarios con sesión iniciada, para la página
 * privada del equipo (o los foros): [gdp_gantt], [gdp_kanban],
 * [gdp_calendario], [gdp_alertas], [gdp_carga] e [gdp_informe_semanal], todos
 * con el atributo proyecto. Muestran las mismas vistas del panel, sin edición;
 * quien puede entrar al panel conserva los enlaces hacia él y quien tiene
 * permiso de ver la planificación conserva las descargas del informe.
 *
 * El acceso sigue la regla del tablero del equipo: sesión iniciada y, si así se
 * configuró en Proyectos → Tableros, pertenencia al equipo del proyecto.
 */
final class Shortcodes {

	/**
	 * Código corto => vista.
	 */
	public const TAGS = array(
		'gdp_gantt'           => 'gantt',
		'gdp_kanban'          => 'board',
		'gdp_calendario'      => 'calendar',
		'gdp_alertas'         => 'alerts',
		'gdp_carga'           => 'workload',
		'gdp_informe_semanal' => 'report',
	);

	/**
	 * Parámetros de navegación de cada vista (en el sitio llevan el prefijo gdp_).
	 */
	public const PARAMS = array(
		'calendar' => array( 'mode', 'date' ),
		'report'   => array( 'week' ),
		'workload' => array( 'from', 'weeks' ),
	);

	public const PREFIX = 'gdp_';

	/**
	 * Proyecto cuyos datos recibió el script (la carta Gantt y el tablero de una
	 * misma página comparten los datos, así que deben ser del mismo proyecto).
	 *
	 * @var int
	 */
	private static int $script_project = 0;

	/**
	 * Registra los códigos cortos y los recursos.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( array_keys( self::TAGS ) as $tag ) {
			add_shortcode( $tag, array( self::class, 'shortcode' ) );
		}
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	/**
	 * Etiquetas de los códigos cortos, para la orientación del panel.
	 *
	 * @return array<string,string> código corto => etiqueta.
	 */
	public static function labels(): array {
		return array(
			'gdp_gantt'           => __( 'Carta Gantt', 'gestion-de-proyectos' ),
			'gdp_kanban'          => __( 'Tablero de tarjetas', 'gestion-de-proyectos' ),
			'gdp_calendario'      => __( 'Calendario', 'gestion-de-proyectos' ),
			'gdp_alertas'         => __( 'Alertas de plazo', 'gestion-de-proyectos' ),
			'gdp_carga'           => __( 'Carga de trabajo', 'gestion-de-proyectos' ),
			'gdp_informe_semanal' => __( 'Informe semanal', 'gestion-de-proyectos' ),
		);
	}

	/**
	 * Registra hojas de estilo y script del sitio (se encolan solo cuando se usan).
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		if ( is_admin() ) {
			return;
		}
		wp_register_style( 'gdp-front-base', GDP_URL . 'assets/css/admin.css', array(), GDP_VERSION );
		wp_register_style( 'gdp-front-planning', GDP_URL . 'assets/css/planning.css', array( 'gdp-front-base' ), GDP_VERSION );
		wp_register_style( 'gdp-front', GDP_URL . 'assets/css/frontend.css', array( 'gdp-front-planning' ), GDP_VERSION );
		wp_add_inline_style( 'gdp-front', Identity::css_variables( '.gdp-front' ) );
		wp_register_script( 'gdp-front-planning', GDP_URL . 'assets/js/planning.js', array(), GDP_VERSION, true );
	}

	/**
	 * Encola los estilos en la cabecera cuando el contenido de la entrada lleva
	 * alguno de los códigos (si vienen de un foro o un widget, se encolan al
	 * ejecutarse el código y se imprimen al pie).
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		foreach ( array_keys( self::TAGS ) as $tag ) {
			if ( has_shortcode( (string) $post->post_content, $tag ) ) {
				self::enqueue();
				return;
			}
		}
	}

	/**
	 * Encola los estilos del sitio.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'gdp-front' );
	}

	/**
	 * Ejecuta un código corto.
	 *
	 * @param array<string,string>|string $atts    Atributos.
	 * @param string|null                 $content Contenido (no se usa).
	 * @param string                      $tag     Código corto.
	 * @return string
	 */
	public static function shortcode( $atts, $content = '', string $tag = '' ): string {
		$view = self::TAGS[ $tag ] ?? '';
		if ( '' === $view ) {
			return '';
		}
		$atts = shortcode_atts( array( 'proyecto' => '' ), is_array( $atts ) ? $atts : array(), $tag );
		self::enqueue();

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$here = is_singular() ? (string) get_permalink() : home_url( '/' );
			return self::notice( esc_html__( 'Esta vista es solo para el equipo del proyecto.', 'gestion-de-proyectos' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Inicie sesión', 'gestion-de-proyectos' ) . '</a>' );
		}
		$project = DashboardsModule::resolve_project( (string) $atts['proyecto'] );
		if ( ! $project ) {
			if ( ! Access::is_manager( $user_id ) ) {
				return '';
			}
			/* translators: código corto. */
			return self::notice( esc_html( sprintf( __( '%s: no se encontró el proyecto. Indique su código en el atributo proyecto. Solo los administradores ven este aviso.', 'gestion-de-proyectos' ), '[' . $tag . ']' ) ) );
		}
		$project_id = (int) $project['id'];
		if ( ! DashboardData::team_allowed( $project_id, $user_id ) ) {
			return self::notice( esc_html__( 'Su cuenta no tiene acceso a la planificación de este proyecto. Pida al administrador que lo agregue al equipo.', 'gestion-de-proyectos' ) );
		}

		$ctx = self::context( $project_id, $view, $user_id );
		ob_start();
		echo '<div class="gdp-front gdp-wrap gdp-front--' . esc_attr( $view ) . '">';
		switch ( $view ) {
			case 'gantt':
			case 'board':
				if ( self::script( $project_id, $view ) ) {
					CanvasView::render( $project_id, $ctx, $view );
				} elseif ( Access::is_manager( $user_id ) ) {
					echo '<p class="gdp-front__notice">' . esc_html__( 'La carta Gantt y el tablero de una misma página deben ser del mismo proyecto. Solo los administradores ven este aviso.', 'gestion-de-proyectos' ) . '</p>';
				}
				break;
			case 'calendar':
				CalendarView::render( $project_id, $ctx );
				break;
			case 'alerts':
				AlertsView::render( $project_id, $ctx );
				break;
			case 'workload':
				WorkloadView::render( $project_id, $ctx );
				break;
			case 'report':
				ReportView::render( $project_id, $ctx );
				break;
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Contexto de presentación en el sitio: enlaces a la misma página, parámetros
	 * con prefijo, enlaces al panel solo para quien puede entrar a él y descargas
	 * solo para quien puede ver la planificación.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $view       Vista.
	 * @param int    $user_id    Usuario.
	 * @return ViewContext
	 */
	public static function context( int $project_id, string $view, int $user_id ): ViewContext {
		$admin    = Access::can_access( $user_id );
		$can_edit = $admin && Access::can( 'planning.edit', $project_id, $user_id );

		return new ViewContext(
			self::current_url( $view ),
			array(),
			array(
				'prefix'    => self::PREFIX,
				'admin'     => $admin,
				'edit'      => $can_edit ? PlanningPage::url( $project_id, 'edit', array( 'id' => '%d' ) ) : '',
				'baselines' => $can_edit ? PlanningPage::url( $project_id, 'baselines' ) : '',
				'exports'   => Access::can( 'planning.view', $project_id, $user_id ),
			)
		);
	}

	/**
	 * Dirección de la petición actual sin los parámetros de la vista (relativa,
	 * para que funcione igual en páginas, entradas y temas de foro).
	 *
	 * @param string $view Vista.
	 * @return string
	 */
	public static function current_url( string $view ): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || '/' !== $uri[0] ) {
			$uri = is_singular() ? (string) get_permalink() : home_url( '/' );
		}
		$params = array();
		foreach ( self::PARAMS[ $view ] ?? array() as $key ) {
			$params[] = self::PREFIX . $key;
		}

		return $params ? remove_query_arg( $params, $uri ) : $uri;
	}

	/**
	 * Entrega al script los datos del proyecto (una vez por página).
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $view       gantt|board.
	 * @return bool False si la página ya lleva los datos de otro proyecto.
	 */
	private static function script( int $project_id, string $view ): bool {
		if ( self::$script_project > 0 ) {
			return self::$script_project === $project_id;
		}
		self::$script_project = $project_id;
		$config               = CanvasView::config( $project_id, $view, false );
		$config['data']       = CanvasView::data( $project_id );
		wp_enqueue_script( 'gdp-front-planning' );
		wp_localize_script( 'gdp-front-planning', 'gdpPlanning', $config );

		return true;
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
	 * Fragmentos de ayuda para la pantalla de tableros: código corto y descripción.
	 *
	 * @param string $code Código del proyecto.
	 * @return array<string,array{shortcode:string,label:string,text:string}>
	 */
	public static function snippets( string $code ): array {
		$texts = array(
			'gdp_gantt'           => __( 'Barras por actividad, línea base, ruta crítica y dependencias; escala por día, semana, mes o trimestre y filtros por frente y responsable. Solo lectura.', 'gestion-de-proyectos' ),
			'gdp_kanban'          => __( 'Tarjetas agrupadas por estado, frente de trabajo o responsable. Solo lectura.', 'gestion-de-proyectos' ),
			'gdp_calendario'      => __( 'Hitos, inicios y términos, término contractual y eventos de otros módulos; vistas de mes, semana y agenda con navegación.', 'gestion-de-proyectos' ),
			'gdp_alertas'         => __( 'Alertas de plazo calculadas al momento: vencidas, próximas, holgura negativa, conflictos, sobreasignación.', 'gestion-de-proyectos' ),
			'gdp_carga'           => __( 'Porcentaje de dedicación por persona y semana, con sobreasignaciones destacadas.', 'gestion-de-proyectos' ),
			'gdp_informe_semanal' => __( 'Resumen de la semana: avance por frente, cambios, curva S, avances registrados, desviación respecto de la línea base y alertas altas; navegación por semana.', 'gestion-de-proyectos' ),
		);
		$out = array();
		foreach ( self::labels() as $tag => $label ) {
			$out[ $tag ] = array(
				'shortcode' => '[' . $tag . ' proyecto="' . $code . '"]',
				'label'     => $label,
				'text'      => $texts[ $tag ],
			);
		}

		return $out;
	}
}
