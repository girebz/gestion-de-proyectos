<?php
/**
 * Módulo de tableros público y del equipo.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Dashboards;

use GDP\Admin\Admin;
use GDP\Admin\Pages\DashboardsPage;
use GDP\Core\Access;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Dos códigos cortos para el sitio: [gdp_avance] muestra el tablero público
 * de difusión (solo lo declarado publicable) y [gdp_tablero_equipo] el de
 * gestión, reservado a usuarios con sesión iniciada. Los datos se guardan en
 * caché por proyecto y se invalidan con cada cambio registrado en la bitácora.
 */
final class DashboardsModule implements ModuleInterface {

	public const PUBLIC_TTL = 30 * MINUTE_IN_SECONDS;
	public const TEAM_TTL   = 5 * MINUTE_IN_SECONDS;

	/**
	 * Proyectos cuya caché ya se invalidó en esta petición (una importación
	 * registra cientos de cambios seguidos; basta con invalidar una vez).
	 *
	 * @var array<int,bool>
	 */
	private static array $flushed = array();

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return 'dashboards';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Tableros público y del equipo', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Tablero de difusión para el sitio y tablero de gestión para el equipo, con códigos cortos.', 'gestion-de-proyectos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_shortcode( 'gdp_avance', array( self::class, 'shortcode_public' ) );
		add_shortcode( 'gdp_tablero_equipo', array( self::class, 'shortcode_team' ) );
		add_action( 'gdp_audit_logged', array( self::class, 'on_audit' ), 10, 1 );
		add_filter( 'gdp_connector_abilities', array( self::class, 'add_abilities' ) );

		if ( is_admin() ) {
			add_action( 'gdp_admin_register', array( DashboardsPage::class, 'register_handlers' ) );
			add_action( 'gdp_admin_menu', array( DashboardsPage::class, 'menu' ) );
			add_action( 'gdp_admin_assets', array( DashboardsPage::class, 'assets' ) );
			add_action( 'gdp_project_view_cards', array( DashboardsPage::class, 'project_card' ) );
		}
	}

	/**
	 * Registra la hoja de estilos del sitio (se encola solo al mostrar un tablero).
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		wp_register_style( 'gdp-dashboards', GDP_URL . 'assets/css/dashboards.css', array(), GDP_VERSION );
	}

	/**
	 * Busca el proyecto indicado en el código corto.
	 *
	 * Acepta el código (tal cual, en mayúsculas o en minúsculas) o el
	 * identificador; sin indicación, usa el único proyecto si hay uno solo.
	 *
	 * @param string $ref Código o identificador.
	 * @return array<string,mixed>|null
	 */
	public static function resolve_project( string $ref ): ?array {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			$all = ProjectRepository::all();
			return 1 === count( $all ) ? $all[0] : null;
		}
		foreach ( array_unique( array( $ref, strtoupper( $ref ), strtolower( $ref ) ) ) as $code ) {
			$project = ProjectRepository::find_by_code( $code );
			if ( $project ) {
				return $project;
			}
		}
		if ( ctype_digit( $ref ) ) {
			return ProjectRepository::find( (int) $ref );
		}

		return null;
	}

	/**
	 * Código corto del tablero público: [gdp_avance proyecto="CODIGO" bloques="portada,etapas"].
	 *
	 * @param array<string,string>|string $atts Atributos.
	 * @return string
	 */
	public static function shortcode_public( $atts ): string {
		$atts    = shortcode_atts( array( 'proyecto' => '', 'bloques' => '' ), is_array( $atts ) ? $atts : array(), 'gdp_avance' );
		$project = self::resolve_project( (string) $atts['proyecto'] );
		if ( ! $project ) {
			return self::manager_notice( __( 'Tablero de avance: no se encontró el proyecto. Indique su código en el atributo proyecto.', 'gestion-de-proyectos' ) );
		}
		$data = self::public_data( (int) $project['id'] );
		if ( ! $data ) {
			return '';
		}
		if ( ! $data['enabled'] ) {
			return self::manager_notice( __( 'Tablero de avance: la publicación está desactivada para este proyecto. Actívela en Proyectos → Tableros. Solo los administradores ven este aviso.', 'gestion-de-proyectos' ) );
		}
		wp_enqueue_style( 'gdp-dashboards' );
		$blocks = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $atts['bloques'] ) ) ) );

		return DashboardRenderer::public_html( $data, $blocks );
	}

	/**
	 * Código corto del tablero del equipo: [gdp_tablero_equipo proyecto="CODIGO"].
	 *
	 * @param array<string,string>|string $atts Atributos.
	 * @return string
	 */
	public static function shortcode_team( $atts ): string {
		$atts = shortcode_atts( array( 'proyecto' => '' ), is_array( $atts ) ? $atts : array(), 'gdp_tablero_equipo' );
		wp_enqueue_style( 'gdp-dashboards' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$here = is_singular() ? (string) get_permalink() : home_url( '/' );
			return '<div class="gdp-dash gdp-dash--notice"><p>' . esc_html__( 'Este tablero es solo para el equipo del proyecto.', 'gestion-de-proyectos' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Inicie sesión', 'gestion-de-proyectos' ) . '</a></p></div>';
		}
		$project = self::resolve_project( (string) $atts['proyecto'] );
		if ( ! $project ) {
			return self::manager_notice( __( 'Tablero del equipo: no se encontró el proyecto. Indique su código en el atributo proyecto.', 'gestion-de-proyectos' ) );
		}
		$project_id = (int) $project['id'];
		if ( ! DashboardData::team_allowed( $project_id, $user_id ) ) {
			return '<div class="gdp-dash gdp-dash--notice"><p>' . esc_html__( 'Su cuenta no tiene acceso al tablero de este proyecto. Pida al administrador que lo agregue al equipo.', 'gestion-de-proyectos' ) . '</p></div>';
		}
		$amounts = Access::can( 'procurement.view_amounts', $project_id, $user_id );
		$data    = self::team_data( $project_id, $amounts );
		if ( ! $data ) {
			return '';
		}
		$admin = Access::can_access( $user_id ) ? Admin::url( 'planning', array( 'project_id' => $project_id ) ) : '';

		return DashboardRenderer::team_html( $data, $admin );
	}

	/**
	 * Datos públicos con caché.
	 *
	 * @param int $project_id Proyecto.
	 * @return array<string,mixed>|null
	 */
	public static function public_data( int $project_id ): ?array {
		$key  = self::key( $project_id, 'pub' );
		$data = get_transient( $key );
		if ( is_array( $data ) ) {
			return $data;
		}
		$data = DashboardData::public_data( $project_id );
		if ( $data ) {
			set_transient( $key, $data, self::PUBLIC_TTL );
			unset( self::$flushed[ $project_id ] );
		}

		return $data;
	}

	/**
	 * Datos del equipo con caché (separada según se muestren montos o no).
	 *
	 * @param int  $project_id Proyecto.
	 * @param bool $amounts    Con montos.
	 * @return array<string,mixed>|null
	 */
	public static function team_data( int $project_id, bool $amounts ): ?array {
		$key  = self::key( $project_id, $amounts ? 'team1' : 'team0' );
		$data = get_transient( $key );
		if ( is_array( $data ) ) {
			return $data;
		}
		$data = DashboardData::team_data( $project_id, $amounts );
		if ( $data ) {
			set_transient( $key, $data, self::TEAM_TTL );
			unset( self::$flushed[ $project_id ] );
		}

		return $data;
	}

	/**
	 * Clave de caché: incluye una generación por proyecto (que sube con cada
	 * cambio) y la fecha, para que los plazos se recalculen al cambiar el día.
	 *
	 * @param int    $project_id Proyecto.
	 * @param string $kind       Tipo.
	 * @return string
	 */
	private static function key( int $project_id, string $kind ): string {
		$gen = (int) get_option( 'gdp_dash_gen_' . $project_id, 0 );

		// La versión del plugin forma parte de la clave para que una actualización no sirva datos con la forma anterior.
		return 'gdp_dash_' . $project_id . '_' . $gen . '_' . $kind . '_' . current_time( 'Ymd' ) . '_' . str_replace( '.', '', GDP_VERSION );
	}

	/**
	 * Invalida la caché de un proyecto.
	 *
	 * @param int $project_id Proyecto.
	 * @return void
	 */
	public static function flush( int $project_id ): void {
		if ( $project_id <= 0 || isset( self::$flushed[ $project_id ] ) ) {
			return;
		}
		foreach ( array( 'pub', 'team0', 'team1' ) as $kind ) {
			delete_transient( self::key( $project_id, $kind ) );
		}
		update_option( 'gdp_dash_gen_' . $project_id, (int) get_option( 'gdp_dash_gen_' . $project_id, 0 ) + 1, false );
		self::$flushed[ $project_id ] = true;
	}

	/**
	 * Cada cambio registrado en la bitácora invalida la caché del proyecto.
	 *
	 * @param int|string $project_id Proyecto.
	 * @return void
	 */
	public static function on_audit( $project_id ): void {
		self::flush( (int) $project_id );
	}

	/**
	 * Aviso visible solo para administradores (el público no ve nada).
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function manager_notice( string $text ): string {
		if ( ! Access::is_manager() ) {
			return '';
		}

		return '<div class="gdp-dash gdp-dash--notice"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Herramienta del conector: datos de un tablero.
	 *
	 * @param array<string,array<string,mixed>> $definitions Definiciones.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_abilities( array $definitions ): array {
		$definitions['get-dashboard'] = array(
			'label'            => __( 'Obtener tablero', 'gestion-de-proyectos' ),
			'description'      => __( 'Devuelve los datos de un tablero del proyecto. kind=public: lo que muestra el tablero de difusión del sitio (mensaje, avance, plazo, etapas, logros, próximos hitos, indicadores, instituciones y contacto) y si está publicado. kind=team: resumen de gestión (avance real y planificado, actividades atrasadas, que vencen pronto y críticas, acuerdos abiertos, respuestas pendientes, compras abiertas, próxima reunión; montos solo con permiso). Incluye los códigos cortos para insertarlo en el sitio.', 'gestion-de-proyectos' ),
			'input_schema'     => array(
				'type'                 => 'object',
				'properties'           => array(
					'project_id' => array( 'type' => 'integer' ),
					'code'       => array( 'type' => 'string' ),
					'kind'       => array( 'type' => 'string', 'enum' => array( 'public', 'team' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'    => array( 'type' => 'object', 'additionalProperties' => true ),
			'execute_callback' => array( self::class, 'get_dashboard' ),
			'meta'             => array( 'annotations' => array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ) ),
		);

		return $definitions;
	}

	/**
	 * Ejecuta get-dashboard.
	 *
	 * @param array $input Entrada.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get_dashboard( array $input = array() ) {
		if ( ! empty( $input['project_id'] ) ) {
			$project = ProjectRepository::find( (int) $input['project_id'] );
		} else {
			$project = self::resolve_project( (string) ( $input['code'] ?? '' ) );
		}
		if ( ! $project ) {
			return new \WP_Error( 'not_found', __( 'El proyecto no existe. Indique project_id o code.', 'gestion-de-proyectos' ), array( 'status' => 404 ) );
		}
		$project_id = (int) $project['id'];
		if ( ! Access::can( 'project.view', $project_id ) ) {
			return new \WP_Error( 'forbidden', __( 'Sin permiso para ver este proyecto.', 'gestion-de-proyectos' ), array( 'status' => 403 ) );
		}
		$kind      = 'team' === ( $input['kind'] ?? 'public' ) ? 'team' : 'public';
		$shortcode = 'team' === $kind ? '[gdp_tablero_equipo proyecto="' . $project['code'] . '"]' : '[gdp_avance proyecto="' . $project['code'] . '"]';
		$data      = 'team' === $kind
			? DashboardData::team_data( $project_id, Access::can( 'procurement.view_amounts', $project_id ) )
			: DashboardData::public_data( $project_id );

		return array( 'project_id' => $project_id, 'kind' => $kind, 'shortcode' => $shortcode, 'data' => $data );
	}
}
