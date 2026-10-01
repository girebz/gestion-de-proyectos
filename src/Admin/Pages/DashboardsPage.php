<?php
/**
 * Pantalla de configuración de los tableros.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Roles;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Dashboards\DashboardData;
use GDP\Modules\Dashboards\DashboardRenderer;
use GDP\Modules\Dashboards\DashboardSettings;
use GDP\Modules\Planning\ActivityRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Configura qué muestra el tablero público (mensaje, etapas, hitos
 * destacados, indicadores, instituciones y contacto) y el del equipo, con
 * vista previa de ambos y los códigos cortos listos para copiar.
 */
final class DashboardsPage extends Page {

	public const SLUG  = 'dashboards';
	public const VIEWS = array( 'public', 'team' );

	/**
	 * Submenú.
	 *
	 * @param string $parent Slug del menú principal.
	 * @return void
	 */
	public static function menu( string $parent ): void {
		add_submenu_page( $parent, __( 'Tableros', 'gestion-de-proyectos' ), __( 'Tableros', 'gestion-de-proyectos' ), Roles::CAP_ACCESS, $parent . '-' . self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Manejadores.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_dashboards_save', array( self::class, 'handle_save' ) );
	}

	/**
	 * Estilos.
	 *
	 * @param string $hook Pantalla.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, Admin::SLUG . '-' . self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'gdp-planning', GDP_URL . 'assets/css/planning.css', array( 'gdp-admin' ), GDP_VERSION );
		wp_enqueue_style( 'gdp-dashboards', GDP_URL . 'assets/css/dashboards.css', array( 'gdp-admin' ), GDP_VERSION );
		add_filter( 'admin_body_class', static fn( string $classes ): string => $classes . ' gdp-dashboards-admin' );
	}

	/**
	 * URL de una vista.
	 *
	 * @param int                 $project_id Proyecto.
	 * @param array<string,mixed> $args       Parámetros.
	 * @return string
	 */
	public static function url( int $project_id, array $args = array() ): string {
		return Admin::url( self::SLUG, array_merge( array( 'project_id' => $project_id ), $args ) );
	}

	/**
	 * Enrutador.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$project = PlanningPage::current_project();
		if ( ! $project || ! Access::can( 'project.view', (int) $project['id'] ) ) {
			self::open( __( 'Tableros', 'gestion-de-proyectos' ) );
			echo '<p>' . esc_html__( 'No hay proyectos visibles.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		update_user_meta( get_current_user_id(), 'gdp_planning_project', (int) $project['id'] );

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'public';

		self::header( $project, $view );
		if ( 'team' === $view ) {
			self::render_team( $project );
		} else {
			self::render_public( $project );
		}
		self::close();
	}

	/**
	 * Tarjeta en la ficha del proyecto: estado de publicación y códigos cortos.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	public static function project_card( array $p ): void {
		$project_id = (int) $p['id'];
		if ( ! Access::can( 'project.view', $project_id ) ) {
			return;
		}
		$enabled = ! empty( DashboardSettings::get( $project_id )['public']['enabled'] );
		?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Tableros', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Tablero público', 'gestion-de-proyectos' ); ?></th><td><?php echo $enabled ? esc_html__( 'publicado en el sitio', 'gestion-de-proyectos' ) : '<span class="gdp-text-danger">' . esc_html__( 'sin publicar', 'gestion-de-proyectos' ) . '</span>'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Código corto público', 'gestion-de-proyectos' ); ?></th><td><code>[gdp_avance proyecto="<?php echo esc_html( $p['code'] ); ?>"]</code></td></tr>
				<tr><th><?php esc_html_e( 'Código corto del equipo', 'gestion-de-proyectos' ); ?></th><td><code>[gdp_tablero_equipo proyecto="<?php echo esc_html( $p['code'] ); ?>"]</code></td></tr>
			</table>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'El público va en la portada del sitio; el del equipo, en un tema del foro o en una página privada. Lo que muestra cada uno se decide en Tableros.', 'gestion-de-proyectos' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Configurar los tableros', 'gestion-de-proyectos' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Cabecera con selector de proyecto y pestañas.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @param string              $view    Vista.
	 * @return void
	 */
	private static function header( array $project, string $view ): void {
		$project_id = (int) $project['id'];
		$projects   = ProjectRepository::all( Access::visible_project_ids() );
		self::open( __( 'Tableros', 'gestion-de-proyectos' ), sprintf( '%s · %s', $project['code'], $project['name'] ) );
		?>
		<div class="gdp-planning-bar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gdp-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-' . self::SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<select name="project_id" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Proyecto', 'gestion-de-proyectos' ); ?>">
					<?php foreach ( $projects as $p ) : ?>
						<option value="<?php echo (int) $p['id']; ?>" <?php selected( (int) $p['id'], $project_id ); ?>><?php echo esc_html( $p['code'] . ' · ' . $p['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<nav class="nav-tab-wrapper gdp-planning-tabs">
				<a class="nav-tab <?php echo 'public' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $project_id ) ); ?>"><?php esc_html_e( 'Tablero público', 'gestion-de-proyectos' ); ?></a>
				<a class="nav-tab <?php echo 'team' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $project_id, array( 'view' => 'team' ) ) ); ?>"><?php esc_html_e( 'Tablero del equipo', 'gestion-de-proyectos' ); ?></a>
			</nav>
		</div>
		<?php
	}

	/**
	 * Código corto para copiar.
	 *
	 * @param string $code Código corto.
	 * @return void
	 */
	private static function snippet( string $code ): void {
		?>
		<input type="text" class="large-text code gdp-dash-snippet" readonly value="<?php echo esc_attr( $code ); ?>" onclick="this.select()" aria-label="<?php esc_attr_e( 'Código corto', 'gestion-de-proyectos' ); ?>">
		<?php
	}

	/**
	 * Configuración y vista previa del tablero público.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_public( array $project ): void {
		$project_id = (int) $project['id'];
		$can_edit   = Access::can( 'project.edit', $project_id );
		$conf       = DashboardSettings::get( $project_id );
		$pub        = $conf['public'];
		$activities = ActivityRepository::for_project( $project_id );
		$summaries  = array_values( array_filter( $activities, static fn( array $a ): bool => 0 === (int) $a['parent_id'] && 'summary' === $a['kind'] ) );
		$candidates = array_values( array_filter( $activities, static fn( array $a ): bool => 'summary' !== $a['kind'] && 'cancelada' !== $a['status'] ) );
		usort( $candidates, static fn( array $x, array $y ): int => ( 'milestone' === $y['kind'] ) <=> ( 'milestone' === $x['kind'] ) ?: strcmp( (string) $x['end_date'], (string) $y['end_date'] ) );
		$indicators = $pub['indicators'];
		$partners   = $pub['partners'];
		for ( $i = 0; $i < 2; $i++ ) {
			$indicators[] = array( 'label' => '', 'source' => 'manual', 'value' => '' );
			$partners[]   = array( 'name' => '', 'role' => '', 'logo' => '', 'url' => '' );
		}
		$data = DashboardData::public_data( $project_id );
		?>
		<div class="gdp-card gdp-card--intro">
			<p>
				<?php esc_html_e( 'El tablero público es una pieza de difusión para el sitio: muestra el avance, las etapas, los logros y lo que viene, las instituciones que participan y una invitación a escribir al equipo. Solo aparece lo que se marca aquí como publicable; montos, nombres de personas, compras y documentos nunca se muestran.', 'gestion-de-proyectos' ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Código corto para la portada del sitio:', 'gestion-de-proyectos' ); ?></strong></p>
			<?php self::snippet( '[gdp_avance proyecto="' . $project['code'] . '"]' ); ?>
			<p class="gdp-muted gdp-small">
				<?php esc_html_e( 'Para mostrar solo algunos bloques, en el orden que quiera, agregue por ejemplo bloques="portada,indicadores,contacto". Bloques disponibles: portada, indicadores, etapas, hitos, aliados, contacto. Los datos se actualizan solos cada vez que cambia el proyecto.', 'gestion-de-proyectos' ); ?>
			</p>
			<?php if ( ! $pub['enabled'] ) : ?>
				<p class="gdp-text-danger"><?php esc_html_e( 'La publicación está desactivada: el código corto no muestra nada a los visitantes hasta que la active.', 'gestion-de-proyectos' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $can_edit ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-dash-form">
			<?php wp_nonce_field( 'gdp_dashboards_save_' . $project_id ); ?>
			<input type="hidden" name="action" value="gdp_dashboards_save">
			<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
			<input type="hidden" name="part" value="public">

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Mensaje central', 'gestion-de-proyectos' ); ?></h2>
				<p><label><input type="checkbox" name="public[enabled]" value="1" <?php checked( $pub['enabled'] ); ?>> <strong><?php esc_html_e( 'Publicar el tablero en el sitio', 'gestion-de-proyectos' ); ?></strong></label></p>
				<p><label><?php esc_html_e( 'Título', 'gestion-de-proyectos' ); ?><br><input type="text" name="public[headline]" class="large-text" value="<?php echo esc_attr( $pub['headline'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Resumen para el público (deje una línea en blanco entre párrafos)', 'gestion-de-proyectos' ); ?><br><textarea name="public[summary]" rows="4" class="large-text"><?php echo esc_textarea( $pub['summary'] ); ?></textarea></label></p>
				<fieldset>
					<legend><?php esc_html_e( 'Bloques que se muestran', 'gestion-de-proyectos' ); ?></legend>
					<?php foreach ( DashboardSettings::block_labels() as $slug => $label ) : ?>
						<label class="gdp-dash-check"><input type="checkbox" name="public[blocks][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $pub['blocks'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p><label><input type="checkbox" name="public[show_updated]" value="1" <?php checked( $pub['show_updated'] ); ?>> <?php esc_html_e( 'Mostrar la fecha de actualización', 'gestion-de-proyectos' ); ?></label></p>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Etapas', 'gestion-de-proyectos' ); ?></h2>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Cada etapa es una actividad resumen de primer nivel; su avance se calcula con las actividades que contiene. Puede darle un nombre más cercano al público y una frase que explique para qué sirve.', 'gestion-de-proyectos' ); ?></p>
				<?php if ( empty( $summaries ) ) : ?>
					<p><?php esc_html_e( 'El cronograma no tiene actividades resumen de primer nivel.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Mostrar', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'En el cronograma', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Nombre público', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Frase para el público', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $summaries as $s ) : ?>
							<?php $c = $pub['stages'][ (string) $s['code'] ] ?? array( 'label' => '', 'text' => '', 'visible' => true ); ?>
							<tr>
								<td><input type="checkbox" name="public[stages][<?php echo esc_attr( $s['code'] ); ?>][visible]" value="1" <?php checked( ! empty( $c['visible'] ) ); ?> aria-label="<?php esc_attr_e( 'Mostrar', 'gestion-de-proyectos' ); ?>"></td>
								<td><?php echo esc_html( trim( $s['code'] . ' ' . $s['name'] ) ); ?></td>
								<td><input type="text" name="public[stages][<?php echo esc_attr( $s['code'] ); ?>][label]" class="regular-text" value="<?php echo esc_attr( (string) $c['label'] ); ?>" placeholder="<?php echo esc_attr( $s['name'] ); ?>"></td>
								<td><input type="text" name="public[stages][<?php echo esc_attr( $s['code'] ); ?>][text]" class="large-text" value="<?php echo esc_attr( (string) $c['text'] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Logros y próximos hitos', 'gestion-de-proyectos' ); ?></h2>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Marque los hitos o actividades que quiere destacar. Los terminados aparecen como logros (los seis más recientes) y los pendientes como lo que viene (los cuatro más próximos). Escriba un texto público si el nombre interno no se entiende fuera del equipo.', 'gestion-de-proyectos' ); ?></p>
				<?php if ( empty( $candidates ) ) : ?>
					<p><?php esc_html_e( 'El cronograma no tiene actividades.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<div class="gdp-dash-scroll">
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Destacar', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Actividad', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Texto público', 'gestion-de-proyectos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $candidates as $a ) : ?>
							<?php $h = $pub['highlights'][ (string) $a['code'] ] ?? null; ?>
							<tr>
								<td><input type="checkbox" name="public[highlights][<?php echo esc_attr( $a['code'] ); ?>][public]" value="1" <?php checked( null !== $h ); ?> aria-label="<?php esc_attr_e( 'Destacar', 'gestion-de-proyectos' ); ?>"></td>
								<td><?php echo esc_html( trim( $a['code'] . ' ' . $a['name'] ) ); ?><?php echo 'milestone' === $a['kind'] ? ' <span class="gdp-badge">' . esc_html__( 'hito', 'gestion-de-proyectos' ) . '</span>' : ''; ?><?php echo 'terminada' === $a['status'] ? ' <span class="gdp-badge gdp-badge--terminada">' . esc_html__( 'terminada', 'gestion-de-proyectos' ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( (string) $a['end_date'] ); ?></td>
								<td><input type="text" name="public[highlights][<?php echo esc_attr( $a['code'] ); ?>][label]" class="large-text" value="<?php echo esc_attr( (string) ( $h['label'] ?? '' ) ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Indicadores', 'gestion-de-proyectos' ); ?></h2>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Cifras grandes con una etiqueta corta. Las fuentes automáticas se calculan con los datos del proyecto; con "Valor escrito a mano" se muestra el valor que escriba (por ejemplo, toneladas de relave estudiadas). Deje la etiqueta vacía para quitar una fila.', 'gestion-de-proyectos' ); ?></p>
				<table class="widefat gdp-table">
					<thead><tr><th><?php esc_html_e( 'Etiqueta', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Fuente', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Valor (solo a mano)', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $indicators as $i => $ind ) : ?>
						<tr>
							<td><input type="text" name="public[indicators][<?php echo (int) $i; ?>][label]" class="regular-text" value="<?php echo esc_attr( (string) $ind['label'] ); ?>"></td>
							<td><select name="public[indicators][<?php echo (int) $i; ?>][source]"><?php foreach ( DashboardSettings::source_labels() as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) $ind['source'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
							<td><input type="text" name="public[indicators][<?php echo (int) $i; ?>][value]" class="small-text" value="<?php echo esc_attr( (string) $ind['value'] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Instituciones y financiamiento', 'gestion-de-proyectos' ); ?></h2>
				<p><label><?php esc_html_e( 'Texto de financiamiento', 'gestion-de-proyectos' ); ?><br><input type="text" name="public[funding]" class="large-text" value="<?php echo esc_attr( $pub['funding'] ); ?>"></label></p>
				<table class="widefat gdp-table">
					<thead><tr><th><?php esc_html_e( 'Institución', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Rol', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Dirección del logo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Enlace', 'gestion-de-proyectos' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $partners as $i => $pa ) : ?>
						<tr>
							<td><input type="text" name="public[partners][<?php echo (int) $i; ?>][name]" class="regular-text" value="<?php echo esc_attr( (string) $pa['name'] ); ?>"></td>
							<td><input type="text" name="public[partners][<?php echo (int) $i; ?>][role]" value="<?php echo esc_attr( (string) $pa['role'] ); ?>"></td>
							<td><input type="url" name="public[partners][<?php echo (int) $i; ?>][logo]" class="regular-text" value="<?php echo esc_attr( (string) $pa['logo'] ); ?>" placeholder="https://"></td>
							<td><input type="url" name="public[partners][<?php echo (int) $i; ?>][url]" class="regular-text" value="<?php echo esc_attr( (string) $pa['url'] ); ?>" placeholder="https://"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'Copie la dirección del logo desde la biblioteca de medios. Deje el nombre vacío para quitar una fila.', 'gestion-de-proyectos' ); ?></p>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Llamado a la acción', 'gestion-de-proyectos' ); ?></h2>
				<p><label><?php esc_html_e( 'Título', 'gestion-de-proyectos' ); ?><br><input type="text" name="public[cta_title]" class="large-text" value="<?php echo esc_attr( $pub['cta_title'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Texto', 'gestion-de-proyectos' ); ?><br><input type="text" name="public[cta_text]" class="large-text" value="<?php echo esc_attr( $pub['cta_text'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Texto del botón', 'gestion-de-proyectos' ); ?><br><input type="text" name="public[cta_button]" class="regular-text" value="<?php echo esc_attr( $pub['cta_button'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Correo de contacto', 'gestion-de-proyectos' ); ?><br><input type="email" name="public[cta_email]" class="regular-text" value="<?php echo esc_attr( $pub['cta_email'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Asunto sugerido del correo', 'gestion-de-proyectos' ); ?><br><input type="text" name="public[cta_subject]" class="large-text" value="<?php echo esc_attr( $pub['cta_subject'] ); ?>"></label></p>
				<p class="gdp-muted gdp-small"><?php esc_html_e( 'El correo se publica codificado para dificultar que lo recojan los programas de envío masivo.', 'gestion-de-proyectos' ); ?></p>
			</div>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar el tablero público', 'gestion-de-proyectos' ); ?></button></p>
		</form>
		<?php endif; ?>

		<h2 class="gdp-dash-preview-title"><?php esc_html_e( 'Vista previa', 'gestion-de-proyectos' ); ?></h2>
		<div class="gdp-dash-preview">
			<?php
			if ( $data ) {
				echo DashboardRenderer::public_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
	}

	/**
	 * Configuración y vista previa del tablero del equipo.
	 *
	 * @param array<string,mixed> $project Proyecto.
	 * @return void
	 */
	private static function render_team( array $project ): void {
		$project_id = (int) $project['id'];
		$team       = DashboardSettings::get( $project_id )['team'];
		$data       = DashboardData::team_data( $project_id, Access::can( 'procurement.view_amounts', $project_id ) );
		?>
		<div class="gdp-card gdp-card--intro">
			<p><?php esc_html_e( 'El tablero del equipo resume lo que hay que mirar esta semana: avance real frente al planificado, actividades atrasadas y que vencen pronto, ruta crítica, acuerdos abiertos, respuestas pendientes, compras en curso y la próxima reunión. Solo lo ven usuarios con sesión iniciada; a los visitantes se les pide iniciar sesión. Los montos solo aparecen a quien tenga permiso para verlos.', 'gestion-de-proyectos' ); ?></p>
			<p><strong><?php esc_html_e( 'Código corto para el foro o una página privada:', 'gestion-de-proyectos' ); ?></strong></p>
			<?php self::snippet( '[gdp_tablero_equipo proyecto="' . $project['code'] . '"]' ); ?>
			<p class="gdp-muted gdp-small"><?php esc_html_e( 'En wpForo, los códigos cortos dentro de un mensaje solo se interpretan si está activada la opción "Enable WordPress Shortcodes in Post Content" (Foros → Ajustes → Funciones). Publique el código en un tema de un foro visible solo para los usuarios registrados; el tablero además comprueba la sesión por su cuenta.', 'gestion-de-proyectos' ); ?></p>
		</div>

		<?php if ( Access::can( 'project.edit', $project_id ) ) : ?>
		<div class="gdp-card">
			<h2><?php esc_html_e( 'Opciones', 'gestion-de-proyectos' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gdp_dashboards_save_' . $project_id ); ?>
				<input type="hidden" name="action" value="gdp_dashboards_save">
				<input type="hidden" name="project_id" value="<?php echo (int) $project_id; ?>">
				<input type="hidden" name="part" value="team">
				<p><label><input type="checkbox" name="team[require_member]" value="1" <?php checked( $team['require_member'] ); ?>> <?php esc_html_e( 'Exigir que el usuario sea miembro del proyecto (si no se marca, basta con haber iniciado sesión, porque el registro del sitio está cerrado)', 'gestion-de-proyectos' ); ?></label></p>
				<p><label><?php esc_html_e( 'Horizonte de "vencen pronto" (días)', 'gestion-de-proyectos' ); ?><br><input type="number" name="team[horizon_days]" class="small-text" min="3" max="60" value="<?php echo (int) $team['horizon_days']; ?>"></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar opciones', 'gestion-de-proyectos' ); ?></button></p>
			</form>
		</div>
		<?php endif; ?>

		<h2 class="gdp-dash-preview-title"><?php esc_html_e( 'Vista previa (con sus permisos)', 'gestion-de-proyectos' ); ?></h2>
		<div class="gdp-dash-preview">
			<?php
			if ( $data ) {
				echo DashboardRenderer::team_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
	}

	/**
	 * Guarda una parte de la configuración (la otra se conserva).
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_dashboards_save_' . $project_id );
		if ( ! Access::can( 'project.edit', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}
		$part    = isset( $_POST['part'] ) && 'team' === sanitize_key( wp_unslash( (string) $_POST['part'] ) ) ? 'team' : 'public';
		$current = DashboardSettings::get( $project_id );
		$values  = array(
			'public' => $current['public'],
			'team'   => $current['team'],
		);
		// Los datos se normalizan en DashboardSettings::sanitize().
		$posted          = isset( $_POST[ $part ] ) && is_array( $_POST[ $part ] ) ? wp_unslash( $_POST[ $part ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$values[ $part ] = $posted;
		if ( 'public' === $part ) {
			// Sin ninguna casilla marcada el navegador no envía el campo.
			$values['public']['blocks'] = (array) ( $posted['blocks'] ?? array() );
		} else {
			// Los destacados guardados se reenvían con la marca que espera el saneamiento.
			foreach ( $values['public']['highlights'] as $id => $h ) {
				$values['public']['highlights'][ $id ] = array_merge( (array) $h, array( 'public' => 1 ) );
			}
		}

		$result = DashboardSettings::save( $project_id, $values );
		$back   = self::url( $project_id, 'team' === $part ? array( 'view' => 'team' ) : array() );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $back, $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( $back, __( 'Tablero guardado.', 'gestion-de-proyectos' ) );
	}
}
