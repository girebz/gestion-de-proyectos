<?php
/**
 * Utilidades comunes de las pantallas del panel.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Core\Access;
use GDP\Core\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Cabecera con la identidad del sitio, comprobación de acceso y ayudas de salida.
 */
abstract class Page {

	/**
	 * Detiene la ejecución si el usuario no puede entrar al panel.
	 *
	 * @return void
	 */
	protected static function require_access(): void {
		if ( ! Access::can_access() ) {
			wp_die( esc_html__( 'No tiene acceso a Gestión de Proyectos.', 'gestion-de-proyectos' ), 403 );
		}
	}

	/**
	 * Detiene la ejecución si el usuario no administra el plugin.
	 *
	 * @return void
	 */
	protected static function require_manager(): void {
		if ( ! Access::is_manager() ) {
			wp_die( esc_html__( 'Esta pantalla está reservada a los administradores del plugin.', 'gestion-de-proyectos' ), 403 );
		}
	}

	/**
	 * Abre la pantalla con la cabecera de identidad.
	 *
	 * @param string $title    Título.
	 * @param string $subtitle Subtítulo opcional.
	 * @return void
	 */
	protected static function open( string $title, string $subtitle = '' ): void {
		$identity = Identity::get();
		?>
		<div class="wrap gdp-wrap">
			<header class="gdp-header">
				<?php if ( $identity['logo_url'] ) : ?>
					<img class="gdp-header__logo" src="<?php echo esc_url( $identity['logo_url'] ); ?>" alt="<?php echo esc_attr( $identity['name'] ); ?>">
				<?php elseif ( $identity['icon_url'] ) : ?>
					<img class="gdp-header__logo gdp-header__logo--icon" src="<?php echo esc_url( $identity['icon_url'] ); ?>" alt="">
				<?php endif; ?>
				<div class="gdp-header__text">
					<p class="gdp-header__site"><?php echo esc_html( $identity['name'] ); ?></p>
					<h1 class="gdp-header__title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( '' !== $subtitle ) : ?>
						<p class="gdp-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
				</div>
			</header>
		<?php
	}

	/**
	 * Cierra la pantalla.
	 *
	 * @return void
	 */
	protected static function close(): void {
		echo '</div>';
	}

	/**
	 * Formatea una fecha UTC de la base de datos en la zona horaria del sitio.
	 *
	 * @param string|null $mysql Fecha "Y-m-d H:i:s" en UTC.
	 * @param bool        $with_time Incluir hora.
	 * @return string
	 */
	protected static function date( ?string $mysql, bool $with_time = true ): string {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return '—';
		}

		$timestamp = strtotime( $mysql . ' UTC' );
		if ( false === $timestamp ) {
			return esc_html( $mysql );
		}

		$format = $with_time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' );

		return wp_date( (string) $format, $timestamp );
	}

	/**
	 * Formatea un monto con la moneda del proyecto.
	 *
	 * @param float|null $amount   Monto.
	 * @param string     $currency Moneda ISO.
	 * @return string
	 */
	protected static function money( ?float $amount, string $currency = 'CLP' ): string {
		if ( null === $amount ) {
			return '—';
		}

		$decimals = 'CLP' === $currency ? 0 : 2;

		return sprintf( '%s %s', esc_html( $currency ), number_format_i18n( $amount, $decimals ) );
	}

	/**
	 * Etiqueta con color de estado.
	 *
	 * @param string $status Estado.
	 * @param string $label  Texto.
	 * @return string HTML.
	 */
	protected static function badge( string $status, string $label ): string {
		return sprintf( '<span class="gdp-badge gdp-badge--%s">%s</span>', esc_attr( sanitize_html_class( $status ) ), esc_html( $label ) );
	}
}
