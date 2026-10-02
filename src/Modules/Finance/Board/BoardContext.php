<?php
/**
 * Contexto de presentación del tablero de finanzas en el sitio.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Board;

defined( 'ABSPATH' ) || exit;

/**
 * El tablero vive en una página, entrada o tema de foro cualquiera: sus
 * enlaces vuelven a la misma dirección con parámetros de prefijo gdp_ (la
 * pestaña, la guía, la entidad, los filtros) y un ancla al tablero, para que
 * la navegación funcione igual en todos esos contextos. Aquí se guardan
 * además los permisos de quien mira, que deciden qué enlaces y descargas se
 * ofrecen.
 */
final class BoardContext {

	public const PREFIX = 'gdp_';

	/**
	 * Parámetros propios del tablero (se quitan de la dirección base).
	 */
	public const PARAMS = array( 'fin', 'guia', 'ent', 'id', 'estado', 'item', 'fuente', 'q', 'tarea' );

	/**
	 * Proyecto.
	 *
	 * @var array<string,mixed>
	 */
	public array $project;

	/**
	 * Dirección de la página sin los parámetros del tablero.
	 *
	 * @var string
	 */
	public string $base_url;

	/**
	 * Vista fija del atributo vista ('' cuando se muestran las pestañas).
	 *
	 * @var string
	 */
	public string $fixed;

	/**
	 * Si se muestra una sola vista (atributo vista), sin pestañas.
	 *
	 * @var bool
	 */
	public bool $single;

	/**
	 * Si quien mira puede entrar al panel (enlaces a la pantalla Finanzas).
	 *
	 * @var bool
	 */
	public bool $panel;

	/**
	 * Si puede registrar pagos, rendiciones y estados (finance.edit).
	 *
	 * @var bool
	 */
	public bool $edit;

	/**
	 * Si puede exportar planillas, ZIP y programación (finance.export).
	 *
	 * @var bool
	 */
	public bool $export;

	/**
	 * Si puede abrir los documentos de respaldo (documents.view).
	 *
	 * @var bool
	 */
	public bool $docs;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>       $project  Proyecto.
	 * @param string                    $base_url Dirección base.
	 * @param array<string,bool|string> $flags    fixed (vista fija), panel, edit, export, docs.
	 */
	public function __construct( array $project, string $base_url, array $flags ) {
		$this->project  = $project;
		$this->base_url = $base_url;
		$this->fixed    = (string) ( $flags['fixed'] ?? '' );
		$this->single   = '' !== $this->fixed;
		$this->panel    = ! empty( $flags['panel'] );
		$this->edit     = ! empty( $flags['edit'] );
		$this->export   = ! empty( $flags['export'] );
		$this->docs     = ! empty( $flags['docs'] );
	}

	/**
	 * Indica si un enlace a una pestaña tiene destino en esta página: con
	 * pestañas, siempre; con una vista fija, solo si es esa misma vista.
	 *
	 * @param string $tab Pestaña.
	 * @return bool
	 */
	public function reachable( string $tab ): bool {
		return ! $this->single || $tab === $this->fixed;
	}

	/**
	 * Identificador del ancla del tablero en la página.
	 *
	 * @return string
	 */
	public function anchor(): string {
		return 'gdp-fin-' . (int) $this->project['id'];
	}

	/**
	 * Dirección del tablero con los parámetros indicados (claves sin prefijo).
	 *
	 * @param array<string,string|int> $args Parámetros.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		$query = array();
		foreach ( $args as $key => $value ) {
			if ( '' !== (string) $value ) {
				$query[ self::PREFIX . $key ] = rawurlencode( (string) $value );
			}
		}
		$url = $query ? add_query_arg( $query, $this->base_url ) : $this->base_url;

		return $url . '#' . $this->anchor();
	}

	/**
	 * Valor de un parámetro de la petición, saneado como texto.
	 *
	 * @param string $key      Clave sin prefijo.
	 * @param string $fallback Valor si el parámetro no viene.
	 * @return string
	 */
	public function get( string $key, string $fallback = '' ): string {
		$name = self::PREFIX . $key;
		if ( ! isset( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $fallback;
		}

		return sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Apertura de un formulario GET hacia el tablero: los parámetros ajenos de
	 * la dirección base viajan como campos ocultos, porque el navegador
	 * descarta la cadena de consulta de la acción al enviar.
	 *
	 * @param array<string,string> $hidden    Campos ocultos propios (claves sin prefijo).
	 * @param string               $css_class Clase CSS.
	 * @return string HTML.
	 */
	public function form_start( array $hidden = array(), string $css_class = 'gdp-fin-form' ): string {
		$action = $this->base_url;
		$fields = array();
		$pos    = strpos( $action, '?' );
		if ( false !== $pos ) {
			wp_parse_str( substr( $action, $pos + 1 ), $fields );
			$action = substr( $action, 0, $pos );
		}
		$html = '<form method="get" action="' . esc_url( $action . '#' . $this->anchor() ) . '" class="' . esc_attr( $css_class ) . '">';
		foreach ( $fields as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
		}
		foreach ( $hidden as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( self::PREFIX . $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}

		return $html;
	}

	/**
	 * Dirección de la petición actual sin los parámetros del tablero.
	 *
	 * @return string
	 */
	public static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || '/' !== $uri[0] ) {
			$uri = is_singular() ? (string) get_permalink() : home_url( '/' );
		}

		return remove_query_arg( array_map( static fn( string $k ): string => self::PREFIX . $k, self::PARAMS ), $uri );
	}
}
