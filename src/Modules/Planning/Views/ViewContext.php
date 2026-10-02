<?php
/**
 * Contexto de presentación de las vistas de planificación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Planning\Views;

defined( 'ABSPATH' ) || exit;

/**
 * Las mismas vistas (carta Gantt, tablero, calendario, alertas, carga de
 * trabajo e informe semanal) se muestran en el panel y, mediante códigos
 * cortos, en el sitio. Lo que cambia entre ambos es cómo se construyen los
 * enlaces de navegación, cómo se llaman los parámetros de la petición y si se
 * ofrecen enlaces al panel, edición y descargas; este objeto encapsula esas
 * diferencias.
 */
final class ViewContext {

	/**
	 * Dirección base (puede ser relativa y llevar parámetros ajenos a la vista).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Parámetros fijos de la dirección base (por ejemplo page, project_id y view en el panel).
	 *
	 * @var array<string,string>
	 */
	private array $base_args;

	/**
	 * Prefijo de los parámetros de la vista ('' en el panel, 'gdp_' en el sitio).
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Si se ofrecen enlaces al panel (fichas de otros módulos, por ejemplo).
	 *
	 * @var bool
	 */
	private bool $admin;

	/**
	 * Enlace de edición de una actividad, con "%d" donde va el identificador
	 * ('' si quien mira no puede editar).
	 *
	 * @var string
	 */
	private string $edit;

	/**
	 * Enlace a la pantalla de líneas base ('' si no corresponde).
	 *
	 * @var string
	 */
	private string $baselines;

	/**
	 * Si se ofrecen descargas e informes exportados.
	 *
	 * @var bool
	 */
	private bool $exports;

	/**
	 * Si la carta Gantt y el tablero admiten cambios con el ratón (solo en el panel).
	 *
	 * @var bool
	 */
	private bool $editable;

	/**
	 * Constructor.
	 *
	 * @param string               $base_url  Dirección base.
	 * @param array<string,string> $base_args Parámetros fijos.
	 * @param array<string,mixed>  $options   prefix, admin, edit, baselines, exports, editable.
	 */
	public function __construct( string $base_url, array $base_args = array(), array $options = array() ) {
		$this->base_url  = $base_url;
		$this->base_args = array_map( 'strval', $base_args );
		$this->prefix    = (string) ( $options['prefix'] ?? '' );
		$this->admin     = ! empty( $options['admin'] );
		$this->edit      = (string) ( $options['edit'] ?? '' );
		$this->baselines = (string) ( $options['baselines'] ?? '' );
		$this->exports   = ! empty( $options['exports'] );
		$this->editable  = ! empty( $options['editable'] ) && '' !== $this->edit;
	}

	/**
	 * Nombre real de un parámetro de la vista.
	 *
	 * @param string $key Clave lógica (mode, date, week, from, weeks).
	 * @return string
	 */
	public function param( string $key ): string {
		return $this->prefix . $key;
	}

	/**
	 * Valor de un parámetro de la petición, saneado como texto.
	 *
	 * @param string $key     Clave lógica.
	 * @param string $default Valor por defecto.
	 * @return string
	 */
	public function get( string $key, string $default = '' ): string {
		$name = $this->param( $key );
		if ( ! isset( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $default;
		}

		return sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Dirección de la vista con los parámetros indicados (claves lógicas).
	 *
	 * @param array<string,string|int> $args Parámetros.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		$query = $this->base_args;
		foreach ( $args as $key => $value ) {
			$query[ $this->param( (string) $key ) ] = (string) $value;
		}

		return add_query_arg( array_map( 'rawurlencode', $query ), $this->base_url );
	}

	/**
	 * Apertura de un formulario GET hacia la vista. Los parámetros fijos y los
	 * que ya lleva la dirección base van como campos ocultos, porque el
	 * navegador descarta la cadena de consulta de la acción al enviar.
	 *
	 * @param string $class Clase CSS del formulario.
	 * @return string HTML.
	 */
	public function form_start( string $class = 'gdp-inline-form' ): string {
		$action = $this->base_url;
		$fields = array();
		$hash   = strpos( $action, '#' );
		if ( false !== $hash ) {
			$action = substr( $action, 0, $hash );
		}
		$pos = strpos( $action, '?' );
		if ( false !== $pos ) {
			wp_parse_str( substr( $action, $pos + 1 ), $fields );
			$action = substr( $action, 0, $pos );
		}
		foreach ( $this->base_args as $name => $value ) {
			$fields[ $name ] = $value;
		}

		$html = '<form method="get" action="' . esc_url( $action ) . '" class="' . esc_attr( $class ) . '">';
		foreach ( $fields as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}

		return $html;
	}

	/**
	 * Enlace de edición de una actividad ('' si no corresponde).
	 *
	 * @param int $activity_id Actividad.
	 * @return string
	 */
	public function edit_url( int $activity_id ): string {
		return '' === $this->edit || $activity_id <= 0 ? '' : str_replace( '%d', (string) $activity_id, $this->edit );
	}

	/**
	 * Si la vista admite cambios con el ratón (arrastrar barras y tarjetas, trazar
	 * dependencias): solo en el panel y para quien puede editar.
	 *
	 * @return bool
	 */
	public function editable(): bool {
		return $this->editable;
	}

	/**
	 * Si se ofrecen enlaces al panel (fichas de otros módulos, líneas base).
	 *
	 * @return bool
	 */
	public function links(): bool {
		return $this->admin;
	}

	/**
	 * Enlace a la pantalla de líneas base ('' si no corresponde).
	 *
	 * @return string
	 */
	public function baselines_url(): string {
		return $this->admin ? $this->baselines : '';
	}

	/**
	 * Si se ofrecen descargas.
	 *
	 * @return bool
	 */
	public function exports(): bool {
		return $this->exports;
	}

	/**
	 * Etiqueta con color de estado.
	 *
	 * @param string $status Estado (ok, warn, fail, critical...).
	 * @param string $label  Texto.
	 * @return string HTML.
	 */
	public static function badge( string $status, string $label ): string {
		return sprintf( '<span class="gdp-badge gdp-badge--%s">%s</span>', esc_attr( sanitize_html_class( $status ) ), esc_html( $label ) );
	}

	/**
	 * Fecha UTC de la base de datos en la zona horaria del sitio.
	 *
	 * @param string|null $mysql     Fecha "Y-m-d H:i:s" en UTC.
	 * @param bool        $with_time Con hora.
	 * @return string
	 */
	public static function date( ?string $mysql, bool $with_time = true ): string {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return '—';
		}
		$timestamp = strtotime( $mysql . ' UTC' );
		if ( false === $timestamp ) {
			return $mysql;
		}
		$format = $with_time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' );

		return wp_date( (string) $format, $timestamp );
	}
}
