<?php
/**
 * HTML de los tableros.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Dashboards;

use GDP\Core\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Presenta los datos de DashboardData con la identidad visual del sitio: el
 * tablero público como pieza de difusión (mensaje, indicadores, etapas,
 * logros, aliados y contacto) y el del equipo como resumen de gestión.
 */
final class DashboardRenderer {

	/**
	 * Tablero público.
	 *
	 * @param array<string,mixed> $d      Datos.
	 * @param string[]            $blocks Bloques pedidos (vacío = los configurados).
	 * @return string
	 */
	public static function public_html( array $d, array $blocks = array() ): string {
		$blocks = empty( $blocks ) ? (array) $d['blocks'] : array_values( array_intersect( $blocks, DashboardSettings::BLOCKS ) );
		ob_start();
		?>
		<section class="gdp-dash gdp-dash--public" style="<?php echo esc_attr( self::vars() ); ?>" aria-label="<?php echo esc_attr( $d['headline'] ); ?>">
			<?php foreach ( $blocks as $block ) : ?>
				<?php
				switch ( $block ) {
					case 'portada':
						self::public_hero( $d );
						break;
					case 'indicadores':
						self::public_indicators( $d );
						break;
					case 'etapas':
						self::public_stages( $d );
						break;
					case 'hitos':
						self::public_milestones( $d );
						break;
					case 'aliados':
						self::public_partners( $d );
						break;
					case 'contacto':
						self::public_cta( $d );
						break;
				}
				?>
			<?php endforeach; ?>
			<?php if ( ! empty( $d['updated'] ) ) : ?>
				<p class="gdp-dash__updated">
					<?php
					/* translators: fecha de actualización. */
					echo esc_html( sprintf( __( 'Información actualizada al %s.', 'gestion-de-proyectos' ), self::long_date( (string) $d['updated'] ) ) );
					?>
				</p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Portada: mensaje central y dos medidores.
	 *
	 * @param array<string,mixed> $d Datos.
	 * @return void
	 */
	private static function public_hero( array $d ): void {
		?>
		<div class="gdp-dash__hero">
			<div class="gdp-dash__hero-text">
				<h2 class="gdp-dash__title"><?php echo esc_html( $d['headline'] ); ?></h2>
				<?php if ( '' !== (string) $d['summary'] ) : ?>
					<?php foreach ( preg_split( '/\R\s*\R/', (string) $d['summary'] ) as $para ) : ?>
						<p class="gdp-dash__lead"><?php echo esc_html( trim( (string) $para ) ); ?></p>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div class="gdp-dash__meters">
				<?php self::meter( __( 'Avance del proyecto', 'gestion-de-proyectos' ), (int) $d['progress'] ); ?>
				<?php if ( null !== $d['time']['percent'] ) : ?>
					<?php self::meter( __( 'Plazo transcurrido', 'gestion-de-proyectos' ), (int) $d['time']['percent'], true ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Medidor circular.
	 *
	 * @param string $label     Etiqueta.
	 * @param int    $percent   Porcentaje.
	 * @param bool   $secondary Color secundario.
	 * @return void
	 */
	private static function meter( string $label, int $percent, bool $secondary = false ): void {
		$percent = max( 0, min( 100, $percent ) );
		$r       = 42;
		$c       = 2 * M_PI * $r;
		?>
		<figure class="gdp-dash__meter<?php echo $secondary ? ' gdp-dash__meter--secondary' : ''; ?>">
			<svg viewBox="0 0 100 100" role="img" aria-label="<?php echo esc_attr( $label . ': ' . $percent . ' %' ); ?>">
				<circle cx="50" cy="50" r="<?php echo (int) $r; ?>" class="gdp-dash__meter-track"></circle>
				<circle cx="50" cy="50" r="<?php echo (int) $r; ?>" class="gdp-dash__meter-value" stroke-dasharray="<?php echo esc_attr( number_format( $c, 2, '.', '' ) ); ?>" stroke-dashoffset="<?php echo esc_attr( number_format( $c * ( 1 - $percent / 100 ), 2, '.', '' ) ); ?>" transform="rotate(-90 50 50)"></circle>
				<text x="50" y="56" text-anchor="middle" class="gdp-dash__meter-number"><?php echo (int) $percent; ?>%</text>
			</svg>
			<figcaption><?php echo esc_html( $label ); ?></figcaption>
		</figure>
		<?php
	}

	/**
	 * Indicadores.
	 *
	 * @param array<string,mixed> $d Datos.
	 * @return void
	 */
	private static function public_indicators( array $d ): void {
		if ( empty( $d['indicators'] ) ) {
			return;
		}
		?>
		<ul class="gdp-dash__indicators">
			<?php foreach ( $d['indicators'] as $ind ) : ?>
				<li class="gdp-dash__indicator"><strong><?php echo esc_html( $ind['value'] ); ?></strong><span><?php echo esc_html( $ind['label'] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Etapas.
	 *
	 * @param array<string,mixed> $d Datos.
	 * @return void
	 */
	private static function public_stages( array $d ): void {
		if ( empty( $d['stages'] ) ) {
			return;
		}
		$labels = array(
			'done'     => __( 'Completada', 'gestion-de-proyectos' ),
			'active'   => __( 'En curso', 'gestion-de-proyectos' ),
			'upcoming' => __( 'Próximamente', 'gestion-de-proyectos' ),
		);
		?>
		<div class="gdp-dash__block">
			<h3 class="gdp-dash__subtitle"><?php esc_html_e( 'Etapas del proyecto', 'gestion-de-proyectos' ); ?></h3>
			<ol class="gdp-dash__stages">
				<?php foreach ( $d['stages'] as $i => $s ) : ?>
					<li class="gdp-dash__stage gdp-dash__stage--<?php echo esc_attr( $s['state'] ); ?>">
						<span class="gdp-dash__stage-number"><?php echo (int) $i + 1; ?></span>
						<span class="gdp-dash__stage-name"><?php echo esc_html( $s['label'] ); ?></span>
						<?php if ( '' !== (string) $s['text'] ) : ?><span class="gdp-dash__stage-text"><?php echo esc_html( $s['text'] ); ?></span><?php endif; ?>
						<span class="gdp-dash__bar" aria-hidden="true"><span style="width:<?php echo (int) $s['percent']; ?>%"></span></span>
						<span class="gdp-dash__stage-state"><?php echo esc_html( $labels[ $s['state'] ] . ( 'active' === $s['state'] ? ' · ' . (int) $s['percent'] . ' %' : '' ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Logros y próximos hitos.
	 *
	 * @param array<string,mixed> $d Datos.
	 * @return void
	 */
	private static function public_milestones( array $d ): void {
		if ( empty( $d['achieved'] ) && empty( $d['upcoming'] ) ) {
			return;
		}
		?>
		<div class="gdp-dash__block gdp-dash__columns">
			<?php if ( ! empty( $d['achieved'] ) ) : ?>
				<div>
					<h3 class="gdp-dash__subtitle"><?php esc_html_e( 'Lo que hemos logrado', 'gestion-de-proyectos' ); ?></h3>
					<ul class="gdp-dash__timeline">
						<?php foreach ( $d['achieved'] as $m ) : ?>
							<li class="gdp-dash__event gdp-dash__event--done"><time datetime="<?php echo esc_attr( (string) $m['date'] ); ?>"><?php echo esc_html( self::month_year( (string) $m['date'] ) ); ?></time><span><?php echo esc_html( $m['label'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $d['upcoming'] ) ) : ?>
				<div>
					<h3 class="gdp-dash__subtitle"><?php esc_html_e( 'Lo que viene', 'gestion-de-proyectos' ); ?></h3>
					<ul class="gdp-dash__timeline">
						<?php foreach ( $d['upcoming'] as $m ) : ?>
							<li class="gdp-dash__event"><time datetime="<?php echo esc_attr( (string) $m['date'] ); ?>"><?php echo esc_html( self::month_year( (string) $m['date'] ) ); ?></time><span><?php echo esc_html( $m['label'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Instituciones y financiamiento.
	 *
	 * @param array<string,mixed> $d Datos.
	 * @return void
	 */
	private static function public_partners( array $d ): void {
		if ( empty( $d['partners'] ) && '' === (string) $d['funding'] ) {
			return;
		}
		?>
		<div class="gdp-dash__block">
			<h3 class="gdp-dash__subtitle"><?php esc_html_e( 'Quiénes hacen posible este proyecto', 'gestion-de-proyectos' ); ?></h3>
			<?php if ( '' !== (string) $d['funding'] ) : ?><p class="gdp-dash__funding"><?php echo esc_html( $d['funding'] ); ?></p><?php endif; ?>
			<?php if ( ! empty( $d['partners'] ) ) : ?>
				<ul class="gdp-dash__partners">
					<?php foreach ( $d['partners'] as $p ) : ?>
						<li class="gdp-dash__partner">
							<?php $inner = '' !== (string) $p['logo'] ? '<img src="' . esc_url( $p['logo'] ) . '" alt="' . esc_attr( $p['name'] ) . '" loading="lazy">' : '<span class="gdp-dash__partner-name">' . esc_html( $p['name'] ) . '</span>'; ?>
							<?php if ( '' !== (string) $p['url'] ) : ?>
								<a href="<?php echo esc_url( $p['url'] ); ?>" rel="noopener" target="_blank"><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
							<?php else : ?>
								<?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
							<?php if ( '' !== (string) $p['role'] ) : ?><span class="gdp-dash__partner-role"><?php echo esc_html( $p['role'] ); ?></span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Llamado a la acción con el correo ofuscado.
	 *
	 * @param array<string,mixed> $d Datos.
	 * @return void
	 */
	private static function public_cta( array $d ): void {
		$cta = $d['cta'];
		if ( ! is_email( (string) $cta['email'] ) ) {
			return;
		}
		$href = 'mailto:' . antispambot( (string) $cta['email'] ) . ( '' !== (string) $cta['subject'] ? '?subject=' . rawurlencode( (string) $cta['subject'] ) : '' );
		?>
		<div class="gdp-dash__cta">
			<div>
				<?php if ( '' !== (string) $cta['title'] ) : ?><h3 class="gdp-dash__cta-title"><?php echo esc_html( $cta['title'] ); ?></h3><?php endif; ?>
				<?php if ( '' !== (string) $cta['text'] ) : ?><p><?php echo esc_html( $cta['text'] ); ?></p><?php endif; ?>
			</div>
			<div class="gdp-dash__cta-actions">
				<a class="gdp-dash__button" href="<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php echo esc_html( '' !== (string) $cta['button'] ? $cta['button'] : __( 'Contactar', 'gestion-de-proyectos' ) ); ?></a>
				<span class="gdp-dash__cta-email"><?php echo antispambot( (string) $cta['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Tablero del equipo.
	 *
	 * @param array<string,mixed> $d     Datos.
	 * @param string              $admin Enlace al panel (vacío si el usuario no tiene acceso).
	 * @return string
	 */
	public static function team_html( array $d, string $admin = '' ): string {
		$dev = $d['deviation'];
		ob_start();
		?>
		<section class="gdp-dash gdp-dash--team" style="<?php echo esc_attr( self::vars() ); ?>">
			<header class="gdp-dash__team-header">
				<div>
					<h2 class="gdp-dash__title"><?php echo esc_html( $d['project']['name'] ); ?></h2>
					<p class="gdp-dash__muted">
						<?php
						/* translators: 1: fecha, 2: días del horizonte. */
						echo esc_html( sprintf( __( 'Estado al %1$s · próximos %2$d días', 'gestion-de-proyectos' ), self::long_date( (string) $d['today'] ), (int) $d['horizon'] ) );
						?>
					</p>
				</div>
				<?php if ( '' !== $admin ) : ?><a class="gdp-dash__button gdp-dash__button--ghost" href="<?php echo esc_url( $admin ); ?>"><?php esc_html_e( 'Abrir en el panel', 'gestion-de-proyectos' ); ?></a><?php endif; ?>
			</header>

			<ul class="gdp-dash__indicators gdp-dash__indicators--team">
				<li class="gdp-dash__indicator"><strong><?php echo (int) $d['progress']; ?> %</strong><span><?php esc_html_e( 'avance real', 'gestion-de-proyectos' ); ?></span></li>
				<?php if ( null !== $d['planned'] ) : ?>
					<li class="gdp-dash__indicator <?php echo $dev < -5 ? 'is-bad' : ( $dev < 0 ? 'is-warn' : 'is-good' ); ?>"><strong><?php echo esc_html( number_format_i18n( (float) $d['planned'], 0 ) ); ?> %</strong><span>
						<?php
						/* translators: desviación en puntos porcentuales. */
						echo esc_html( sprintf( __( 'planificado (%s puntos)', 'gestion-de-proyectos' ), ( $dev > 0 ? '+' : '' ) . number_format_i18n( (float) $dev, 1 ) ) );
						?>
					</span></li>
				<?php endif; ?>
				<?php if ( null !== $d['time']['percent'] ) : ?>
					<li class="gdp-dash__indicator"><strong><?php echo (int) $d['time']['percent']; ?> %</strong><span><?php esc_html_e( 'plazo transcurrido', 'gestion-de-proyectos' ); ?></span></li>
				<?php endif; ?>
				<li class="gdp-dash__indicator <?php echo count( $d['overdue'] ) > 0 ? 'is-bad' : 'is-good'; ?>"><strong><?php echo count( $d['overdue'] ); ?></strong><span><?php esc_html_e( 'actividades atrasadas', 'gestion-de-proyectos' ); ?></span></li>
				<li class="gdp-dash__indicator <?php echo count( array_filter( $d['agreements'], static fn( array $a ): bool => $a['overdue'] ) ) > 0 ? 'is-warn' : ''; ?>"><strong><?php echo count( $d['agreements'] ); ?></strong><span><?php esc_html_e( 'acuerdos abiertos', 'gestion-de-proyectos' ); ?></span></li>
				<li class="gdp-dash__indicator <?php echo count( array_filter( $d['documents'], static fn( array $x ): bool => $x['overdue'] ) ) > 0 ? 'is-warn' : ''; ?>"><strong><?php echo count( $d['documents'] ); ?></strong><span><?php esc_html_e( 'respuestas pendientes', 'gestion-de-proyectos' ); ?></span></li>
			</ul>

			<?php if ( $d['next_meeting'] ) : ?>
				<p class="gdp-dash__notice">
					<?php
					$m = $d['next_meeting'];
					/* translators: 1: título de la reunión, 2: fecha, 3: hora y lugar. */
					echo esc_html( sprintf( __( 'Próxima reunión: %1$s, %2$s%3$s.', 'gestion-de-proyectos' ), $m['title'], self::long_date( (string) $m['date'] ), ( $m['time'] ? ', ' . $m['time'] : '' ) . ( '' !== (string) $m['location'] ? ', ' . $m['location'] : '' ) ) );
					?>
				</p>
			<?php endif; ?>

			<div class="gdp-dash__grid">
				<?php self::team_list( __( 'Atrasadas', 'gestion-de-proyectos' ), $d['overdue'], static function ( array $a ): string { return self::row( $a['code'] . ' ' . $a['name'], $a['owner'], sprintf( /* translators: días de atraso. */ _n( '%d día de atraso', '%d días de atraso', (int) $a['days'], 'gestion-de-proyectos' ), (int) $a['days'] ), 'bad' ); }, __( 'Sin actividades atrasadas.', 'gestion-de-proyectos' ) ); ?>
				<?php self::team_list( __( 'Vencen pronto', 'gestion-de-proyectos' ), $d['due_soon'], static function ( array $a ): string { return self::row( $a['code'] . ' ' . $a['name'], $a['owner'], self::short_date( (string) $a['end_date'] ) . ' · ' . (int) $a['percent'] . ' %', $a['critical'] ? 'warn' : '' ); }, __( 'Nada vence en el horizonte.', 'gestion-de-proyectos' ) ); ?>
				<?php self::team_list( __( 'Ruta crítica en curso o próxima', 'gestion-de-proyectos' ), $d['critical'], static function ( array $a ): string { return self::row( $a['code'] . ' ' . $a['name'], $a['owner'], self::short_date( (string) $a['start'] ) . ' → ' . self::short_date( (string) $a['end_date'] ), '' ); }, __( 'Sin actividades críticas en el horizonte.', 'gestion-de-proyectos' ) ); ?>
				<?php self::team_list( __( 'Acuerdos abiertos', 'gestion-de-proyectos' ), $d['agreements'], static function ( array $a ): string { return self::row( $a['code'] . ' ' . $a['description'], (string) $a['owner'], $a['due_date'] ? self::short_date( (string) $a['due_date'] ) : __( 'sin plazo', 'gestion-de-proyectos' ), $a['overdue'] ? 'bad' : '' ); }, __( 'Sin acuerdos abiertos.', 'gestion-de-proyectos' ) ); ?>
				<?php self::team_list( __( 'Documentos con respuesta pendiente', 'gestion-de-proyectos' ), $d['documents'], static function ( array $x ): string { return self::row( trim( $x['number'] . ' ' . $x['subject'] ), '', self::short_date( (string) $x['due'] ), $x['overdue'] ? 'bad' : 'warn' ); }, __( 'Sin respuestas pendientes.', 'gestion-de-proyectos' ) ); ?>
				<?php
				self::team_list(
					__( 'Compras abiertas', 'gestion-de-proyectos' ),
					$d['purchases'],
					static function ( array $p ) use ( $d ): string {
						$detail = $p['stage'] . ( $p['expected'] ? ' · ' . self::short_date( (string) $p['expected'] ) : '' );
						if ( $d['amounts'] && null !== ( $p['amount_clp'] ?? null ) ) {
							$detail .= ' · $' . number_format_i18n( (float) $p['amount_clp'], 0 );
						}
						return self::row( $p['code'] . ' ' . $p['title'], '', $detail, $p['waiting'] ? 'warn' : '' );
					},
					__( 'Sin compras abiertas.', 'gestion-de-proyectos' )
				);
				?>
				<?php if ( ! empty( $d['finished'] ) || ! empty( $d['recent'] ) ) : ?>
					<?php
					$week = array_merge(
						array_map( static fn( array $a ): array => array( 'text' => $a['code'] . ' ' . $a['name'], 'who' => $a['owner'], 'tag' => __( 'terminada', 'gestion-de-proyectos' ) ), $d['finished'] ),
						array_map( static fn( array $m ): array => array( 'text' => $m['code'] . ' ' . $m['title'], 'who' => '', 'tag' => self::short_date( (string) $m['date'] ) ), $d['recent'] )
					);
					self::team_list( __( 'Últimos siete días', 'gestion-de-proyectos' ), $week, static function ( array $w ): string { return self::row( $w['text'], $w['who'], $w['tag'], 'good' ); }, '' );
					?>
				<?php endif; ?>
				<?php if ( $d['amounts'] && is_array( $d['budget'] ) ) : ?>
					<div class="gdp-dash__card">
						<h3 class="gdp-dash__subtitle"><?php esc_html_e( 'Presupuesto', 'gestion-de-proyectos' ); ?></h3>
						<dl class="gdp-dash__facts">
							<dt><?php esc_html_e( 'Asignado', 'gestion-de-proyectos' ); ?></dt><dd>$<?php echo esc_html( number_format_i18n( (float) $d['budget']['assigned'], 0 ) ); ?></dd>
							<dt><?php esc_html_e( 'Comprometido', 'gestion-de-proyectos' ); ?></dt><dd>$<?php echo esc_html( number_format_i18n( (float) $d['budget']['committed'], 0 ) ); ?></dd>
							<dt><?php esc_html_e( 'Ejecutado', 'gestion-de-proyectos' ); ?></dt><dd>$<?php echo esc_html( number_format_i18n( (float) $d['budget']['executed'], 0 ) ); ?></dd>
						</dl>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Tarjeta con una lista.
	 *
	 * @param string   $title Título.
	 * @param array    $items Elementos.
	 * @param callable $row   Función que devuelve el HTML de una fila.
	 * @param string   $empty Texto si no hay elementos.
	 * @return void
	 */
	private static function team_list( string $title, array $items, callable $row, string $empty ): void {
		?>
		<div class="gdp-dash__card">
			<h3 class="gdp-dash__subtitle"><?php echo esc_html( $title ); ?> <span class="gdp-dash__count"><?php echo count( $items ); ?></span></h3>
			<?php if ( empty( $items ) ) : ?>
				<p class="gdp-dash__muted"><?php echo esc_html( $empty ); ?></p>
			<?php else : ?>
				<ul class="gdp-dash__list">
					<?php foreach ( array_slice( $items, 0, 8 ) as $item ) : ?>
						<?php echo $row( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $items ) > 8 ) : ?>
					<p class="gdp-dash__muted">
						<?php
						/* translators: número de elementos adicionales. */
						echo esc_html( sprintf( __( 'y %d más en el panel.', 'gestion-de-proyectos' ), count( $items ) - 8 ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Fila escapada.
	 *
	 * @param string $text   Texto.
	 * @param string $who    Responsable.
	 * @param string $detail Detalle.
	 * @param string $tone   good, warn, bad o vacío.
	 * @return string
	 */
	private static function row( string $text, string $who, string $detail, string $tone ): string {
		return '<li class="gdp-dash__item' . ( '' !== $tone ? ' is-' . esc_attr( $tone ) : '' ) . '"><span class="gdp-dash__item-text">' . esc_html( $text ) . '</span><span class="gdp-dash__item-meta">' . esc_html( trim( $who . ( '' !== $who && '' !== $detail ? ' · ' : '' ) . $detail ) ) . '</span></li>';
	}

	/**
	 * Variables de color de la identidad del sitio.
	 *
	 * @return string
	 */
	private static function vars(): string {
		$id  = Identity::get();
		$map = array( '--gdp-dash-primary' => $id['primary'] ?? '', '--gdp-dash-secondary' => $id['secondary'] ?? '', '--gdp-dash-accent' => $id['accent'] ?? '' );
		$out = '';
		foreach ( $map as $var => $value ) {
			if ( is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{3,8}$|^rgba?\([0-9.,\s%]+\)$/', $value ) ) {
				$out .= $var . ':' . $value . ';';
			}
		}

		return $out;
	}

	/**
	 * Fecha larga ("1 de octubre de 2026").
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	public static function long_date( string $date ): string {
		$ts = strtotime( $date );

		return $ts ? date_i18n( 'j \d\e F \d\e Y', $ts ) : $date;
	}

	/**
	 * Mes y año ("octubre de 2026").
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	private static function month_year( string $date ): string {
		$ts = strtotime( $date );

		return $ts ? date_i18n( 'F \d\e Y', $ts ) : '';
	}

	/**
	 * Fecha corta ("15 oct").
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	private static function short_date( string $date ): string {
		$ts = strtotime( $date );

		return $ts ? date_i18n( 'j M', $ts ) : '';
	}
}
