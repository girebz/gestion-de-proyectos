<?php
/**
 * Pantalla de grupos de permisos a medida.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Groups;
use GDP\Core\Roles;
use GDP\Core\TwoFactor;

defined( 'ABSPATH' ) || exit;

/**
 * Lista los perfiles predefinidos (solo lectura) y los grupos a medida, con
 * un formulario de permisos por módulo y las advertencias sobre
 * combinaciones que filtran información. Reservada a los administradores
 * del plugin.
 */
final class GroupsPage extends Page {

	public const SLUG = 'groups';

	/**
	 * Manejadores de formularios.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_group', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_gdp_delete_group', array( self::class, 'handle_delete' ) );
	}

	/**
	 * URL de la pantalla.
	 *
	 * @param array<string,mixed> $args Parámetros.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return Admin::url( self::SLUG, $args );
	}

	/**
	 * Pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_manager();

		$edit_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$copy    = isset( $_GET['copy'] ) ? sanitize_key( wp_unslash( (string) $_GET['copy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::open( __( 'Grupos de permisos', 'gestion-de-proyectos' ), __( 'Perfiles de proyecto a medida: un nombre y una selección explícita de permisos. Se asignan a los miembros igual que los perfiles predefinidos.', 'gestion-de-proyectos' ) );

		if ( $edit_id >= 0 || '' !== $copy ) {
			self::render_form( $edit_id, $copy );
		} else {
			self::render_list();
		}
		self::close();
	}

	/**
	 * Lista de perfiles y grupos.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$labels  = Roles::permission_labels();
		$builtin = Roles::builtin_permissions();
		?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'id' => 0 ) ) ); ?>"><?php esc_html_e( 'Nuevo grupo', 'gestion-de-proyectos' ); ?></a>
		</p>
		<h2><?php esc_html_e( 'Grupos a medida', 'gestion-de-proyectos' ); ?></h2>
		<?php $groups = Groups::all(); ?>
		<?php if ( empty( $groups ) ) : ?>
			<p class="gdp-muted"><?php esc_html_e( 'Aún no hay grupos a medida. Para este proyecto suelen bastar dos: "Dirección" (todo en lectura, finanzas incluidas) y "Equipo" (todo en lectura salvo finanzas y montos).', 'gestion-de-proyectos' ); ?></p>
		<?php else : ?>
			<table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Grupo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Identificador', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Permisos', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Miembros', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Advertencias', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $groups as $g ) : ?>
					<?php $warnings = Groups::warnings( $g['permissions'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( $g['label'] ); ?></strong><?php echo '' !== $g['description'] ? '<br><span class="gdp-muted gdp-small">' . esc_html( $g['description'] ) . '</span>' : ''; ?></td>
						<td><code><?php echo esc_html( $g['slug'] ); ?></code></td>
						<td class="gdp-small"><?php echo esc_html( implode( ', ', array_map( static fn( string $p ): string => $labels[ $p ] ?? $p, $g['permissions'] ) ) ); ?></td>
						<td><?php echo (int) Groups::member_count( $g['slug'] ); ?></td>
						<td class="gdp-small <?php echo $warnings ? 'gdp-text-danger' : ''; ?>"><?php echo esc_html( $warnings ? implode( ' ', $warnings ) : '—' ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( self::url( array( 'id' => $g['id'] ) ) ); ?>"><?php esc_html_e( 'Editar', 'gestion-de-proyectos' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar el grupo? Sus miembros pasarán a observadores.', 'gestion-de-proyectos' ) ); ?>');">
								<?php wp_nonce_field( 'gdp_delete_group_' . $g['id'] ); ?>
								<input type="hidden" name="action" value="gdp_delete_group">
								<input type="hidden" name="id" value="<?php echo (int) $g['id']; ?>">
								<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'gestion-de-proyectos' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Perfiles predefinidos', 'gestion-de-proyectos' ); ?></h2>
		<p class="gdp-muted"><?php esc_html_e( 'No se editan; pueden copiarse como punto de partida de un grupo nuevo.', 'gestion-de-proyectos' ); ?></p>
		<table class="widefat striped gdp-table">
			<thead><tr><th><?php esc_html_e( 'Perfil', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Permisos', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( Roles::builtin_roles() as $slug => $label ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong> <code><?php echo esc_html( $slug ); ?></code></td>
					<td class="gdp-small"><?php echo esc_html( implode( ', ', array_map( static fn( string $p ): string => $labels[ $p ] ?? $p, $builtin[ $slug ] ?? array() ) ) ); ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( self::url( array( 'copy' => $slug ) ) ); ?>"><?php esc_html_e( 'Copiar como grupo', 'gestion-de-proyectos' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Formulario de un grupo.
	 *
	 * @param int    $id   Grupo (0 = nuevo).
	 * @param string $copy Perfil predefinido que se copia.
	 * @return void
	 */
	private static function render_form( int $id, string $copy ): void {
		$group = $id > 0 ? Groups::find_by_id( $id ) : null;
		if ( $id > 0 && ! $group ) {
			echo '<p>' . esc_html__( 'El grupo no existe.', 'gestion-de-proyectos' ) . '</p>';
			return;
		}
		$selected = $group ? $group['permissions'] : ( Roles::builtin_permissions()[ $copy ] ?? array() );
		$label    = $group ? $group['label'] : ( '' !== $copy ? sprintf( '%s (copia)', Roles::builtin_roles()[ $copy ] ?? $copy ) : '' );
		$modules  = Roles::permission_modules();
		$labels   = Roles::permission_labels();
		$warnings = Groups::warnings( $selected );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form">
			<?php wp_nonce_field( 'gdp_save_group_' . $id ); ?>
			<input type="hidden" name="action" value="gdp_save_group">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gdp-group-label"><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" id="gdp-group-label" name="label" class="regular-text" required value="<?php echo esc_attr( $label ); ?>"></td></tr>
				<?php if ( ! $group ) : ?>
					<tr><th scope="row"><label for="gdp-group-slug"><?php esc_html_e( 'Identificador', 'gestion-de-proyectos' ); ?></label></th><td><input type="text" id="gdp-group-slug" name="slug" class="regular-text" value="" placeholder="<?php esc_attr_e( 'Se deriva del nombre si se deja vacío', 'gestion-de-proyectos' ); ?>"><p class="description"><?php esc_html_e( 'Minúsculas, números y guiones bajos; no puede cambiarse después.', 'gestion-de-proyectos' ); ?></p></td></tr>
				<?php else : ?>
					<tr><th scope="row"><?php esc_html_e( 'Identificador', 'gestion-de-proyectos' ); ?></th><td><code><?php echo esc_html( $group['slug'] ); ?></code></td></tr>
				<?php endif; ?>
				<tr><th scope="row"><label for="gdp-group-desc"><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></label></th><td><textarea id="gdp-group-desc" name="description" rows="2" class="large-text"><?php echo esc_textarea( $group ? $group['description'] : '' ); ?></textarea></td></tr>
			</table>
			<h2><?php esc_html_e( 'Permisos', 'gestion-de-proyectos' ); ?></h2>
			<?php if ( $warnings ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( implode( ' ', $warnings ) ); ?></p></div>
			<?php endif; ?>
			<div class="gdp-permission-grid">
			<?php foreach ( $modules as $prefix => $module_label ) : ?>
				<fieldset class="gdp-permission-group">
					<legend><?php echo esc_html( $module_label ); ?></legend>
					<?php foreach ( $labels as $perm => $perm_label ) : ?>
						<?php if ( 0 !== strpos( $perm, $prefix . '.' ) ) { continue; } ?>
						<label><input type="checkbox" name="permissions[]" value="<?php echo esc_attr( $perm ); ?>" <?php checked( in_array( $perm, $selected, true ) ); ?>> <?php echo esc_html( $perm_label ); ?><?php echo TwoFactor::is_sensitive( $perm ) ? ' <span class="gdp-muted gdp-small">(' . esc_html__( 'doble factor', 'gestion-de-proyectos' ) . ')</span>' : ''; ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Los permisos marcados con "doble factor" exigen que el usuario tenga el segundo factor activado cuando la exigencia está activa en los ajustes.', 'gestion-de-proyectos' ); ?></p>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $group ? esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ) : esc_html__( 'Crear grupo', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Guarda un grupo.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_save_group_' . $id );
		self::require_manager();
		$data = array(
			'label'       => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '',
			'slug'        => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['slug'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '',
			'permissions' => isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['permissions'] ) ) : array(),
		);
		$result = $id > 0 ? Groups::update( $id, $data ) : Groups::create( $data );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url( array( 'id' => $id ) ), $result->get_error_message(), 'error' );
		}
		$warnings = Groups::warnings( $data['permissions'] );
		Admin::redirect_with_notice( self::url(), $warnings ? __( 'Grupo guardado. Revise las advertencias de la tabla.', 'gestion-de-proyectos' ) : __( 'Grupo guardado.', 'gestion-de-proyectos' ), $warnings ? 'warning' : 'success' );
	}

	/**
	 * Elimina un grupo.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_group_' . $id );
		self::require_manager();
		$result = Groups::delete( $id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( self::url(), $result->get_error_message(), 'error' );
		}
		Admin::redirect_with_notice( self::url(), __( 'Grupo eliminado.', 'gestion-de-proyectos' ) );
	}
}
