<?php
/**
 * Pantalla de ajustes.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Cron;
use GDP\Core\Identity;
use GDP\Core\Options;
use GDP\Core\Storage;
use GDP\Core\TwoFactor;
use GDP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes generales, identidad visual, conector y salud del sistema.
 */
final class SettingsPage extends Page {

	/**
	 * Registra el manejador del formulario.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_settings', array( self::class, 'handle_save' ) );
	}

	/**
	 * Renderiza la pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_manager();

		$o         = Options::all();
		$overrides = is_array( $o['identity_overrides'] ) ? $o['identity_overrides'] : array();
		$site      = Identity::from_site();
		$resolved  = Identity::get();
		$storage   = Storage::status();
		$cron      = Cron::status();

		self::open( __( 'Ajustes', 'gestion-de-proyectos' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gdp_save_settings' ); ?>
			<input type="hidden" name="action" value="gdp_save_settings">

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Identidad visual', 'gestion-de-proyectos' ); ?></h2>
				<p><?php esc_html_e( 'El plugin adopta el nombre, logotipo, ícono, paleta y tipografía del sitio. Si el tema no los declara, corríjalos aquí; los campos vacíos conservan el valor del sitio.', 'gestion-de-proyectos' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Adoptar la identidad del sitio', 'gestion-de-proyectos' ); ?></th>
						<td><label><input type="checkbox" name="identity_use_site" value="1" <?php checked( ! empty( $o['identity_use_site'] ) ); ?>> <?php esc_html_e( 'Aplicar la identidad detectada y las correcciones siguientes', 'gestion-de-proyectos' ); ?></label></td>
					</tr>
					<?php
					$fields = array(
						'name'       => __( 'Nombre', 'gestion-de-proyectos' ),
						'logo_url'   => __( 'URL del logotipo', 'gestion-de-proyectos' ),
						'primary'    => __( 'Color principal', 'gestion-de-proyectos' ),
						'secondary'  => __( 'Color secundario', 'gestion-de-proyectos' ),
						'accent'     => __( 'Color de acento', 'gestion-de-proyectos' ),
						'background' => __( 'Color de fondo', 'gestion-de-proyectos' ),
						'text'       => __( 'Color del texto', 'gestion-de-proyectos' ),
						'font'       => __( 'Tipografía', 'gestion-de-proyectos' ),
					);
					foreach ( $fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="gdp-id-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" id="gdp-id-<?php echo esc_attr( $key ); ?>" name="identity[<?php echo esc_attr( $key ); ?>]" class="regular-text" value="<?php echo esc_attr( (string) ( $overrides[ $key ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $site[ $key ] ); ?>">
								<?php if ( in_array( $key, array( 'primary', 'secondary', 'accent', 'background', 'text' ), true ) ) : ?>
									<span class="gdp-swatch" style="background: <?php echo esc_attr( Identity::sanitize_color( (string) $resolved[ $key ] ) ); ?>"></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Conector', 'gestion-de-proyectos' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Habilitado', 'gestion-de-proyectos' ); ?></th>
						<td><label><input type="checkbox" name="connector_enabled" value="1" <?php checked( ! empty( $o['connector_enabled'] ) ); ?>> <?php esc_html_e( 'Registrar las herramientas y el servidor MCP', 'gestion-de-proyectos' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Modo de solo lectura', 'gestion-de-proyectos' ); ?></th>
						<td><label><input type="checkbox" name="connector_read_only" value="1" <?php checked( ! empty( $o['connector_read_only'] ) ); ?>> <?php esc_html_e( 'Rechazar toda propuesta de cambio proveniente del conector', 'gestion-de-proyectos' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="gdp-token-days"><?php esc_html_e( 'Validez por defecto de los tokens (días)', 'gestion-de-proyectos' ); ?></label></th>
						<td><input type="number" id="gdp-token-days" name="connector_token_days" min="0" max="3650" value="<?php echo (int) $o['connector_token_days']; ?>"> <span class="description"><?php esc_html_e( '0 = sin caducidad', 'gestion-de-proyectos' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="gdp-op-ttl"><?php esc_html_e( 'Caducidad de las operaciones propuestas (horas)', 'gestion-de-proyectos' ); ?></label></th>
						<td><input type="number" id="gdp-op-ttl" name="operation_ttl_hours" min="1" max="720" value="<?php echo (int) $o['operation_ttl_hours']; ?>"></td>
					</tr>
				</table>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Seguridad', 'gestion-de-proyectos' ); ?></h2>
				<?php $provider = TwoFactor::active_provider(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Doble factor de autenticación', 'gestion-de-proyectos' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_two_factor" value="1" <?php checked( ! empty( $o['require_two_factor'] ) ); ?>> <?php esc_html_e( 'Exigir un segundo factor a los perfiles con acceso a documentos, montos, exportaciones y bitácora (incluidos los administradores del plugin)', 'gestion-de-proyectos' ); ?></label>
							<p class="description">
								<?php if ( $provider ) : ?>
									<?php printf( esc_html__( 'Proveedor detectado: %s. Quien no tenga configurado el segundo factor verá cerradas esas secciones hasta activarlo en su perfil.', 'gestion-de-proyectos' ), esc_html( $provider['label'] ) ); ?>
								<?php else : ?>
									<?php esc_html_e( 'No se detecta ningún plugin de doble factor. El plugin no implementa uno propio: instale y active Two Factor (del equipo de WordPress), WP 2FA o Wordfence Login Security; mientras tanto la exigencia no puede aplicarse y el diagnóstico del conector lo señalará.', 'gestion-de-proyectos' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Datos', 'gestion-de-proyectos' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gdp-private-dir"><?php esc_html_e( 'Directorio privado de adjuntos', 'gestion-de-proyectos' ); ?></label></th>
						<td><input type="text" id="gdp-private-dir" name="private_dir" class="regular-text" value="<?php echo esc_attr( (string) $o['private_dir'] ); ?>"> <span class="description"><?php esc_html_e( 'Subcarpeta dentro de wp-content/uploads, protegida contra acceso web.', 'gestion-de-proyectos' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Al desinstalar', 'gestion-de-proyectos' ); ?></th>
						<td><label><input type="checkbox" name="uninstall_remove_data" value="1" <?php checked( ! empty( $o['uninstall_remove_data'] ) ); ?>> <?php esc_html_e( 'Eliminar tablas, ajustes y roles del plugin (exporte antes un respaldo)', 'gestion-de-proyectos' ); ?></label></td>
					</tr>
				</table>
			</div>

			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar ajustes', 'gestion-de-proyectos' ); ?></button></p>
		</form>

		<div class="gdp-card">
			<h2><?php esc_html_e( 'Salud del sistema', 'gestion-de-proyectos' ); ?></h2>
			<table class="gdp-facts">
				<tr><th><?php esc_html_e( 'Versión del plugin', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( GDP_VERSION ); ?> (<?php printf( esc_html__( 'esquema %s', 'gestion-de-proyectos' ), esc_html( GDP_DB_VERSION ) ); ?>)</td></tr>
				<tr><th><?php esc_html_e( 'Instalado el', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::date( (string) get_option( 'gdp_installed_at' ) ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Directorio privado', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( Storage::private_dir() ); ?> · <?php echo $storage['writable'] ? esc_html__( 'escribible', 'gestion-de-proyectos' ) : esc_html__( 'NO escribible', 'gestion-de-proyectos' ); ?> · <?php echo $storage['htaccess'] ? '.htaccess' : esc_html__( 'sin .htaccess', 'gestion-de-proyectos' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Tarea diaria', 'gestion-de-proyectos' ); ?></th><td><?php echo $cron['next_daily'] ? esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cron['next_daily'] ) ) : esc_html__( 'no programada', 'gestion-de-proyectos' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Tarea semanal', 'gestion-de-proyectos' ); ?></th><td><?php echo $cron['next_weekly'] ? esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cron['next_weekly'] ) ) : esc_html__( 'no programada', 'gestion-de-proyectos' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'WP-Cron', 'gestion-de-proyectos' ); ?></th><td><?php echo $cron['wp_cron_disabled'] ? esc_html__( 'desactivado (cron real)', 'gestion-de-proyectos' ) : esc_html__( 'interno', 'gestion-de-proyectos' ); ?></td></tr>
			</table>

			<h3><?php esc_html_e( 'Módulos', 'gestion-de-proyectos' ); ?></h3>
			<table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Módulo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Etapa', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Núcleo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Plugin::instance()->modules()->status() as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><?php echo (int) $row['stage']; ?></td>
						<td><?php echo $row['core'] ? esc_html__( 'sí', 'gestion-de-proyectos' ) : esc_html__( 'no', 'gestion-de-proyectos' ); ?></td>
						<td><?php echo self::badge( 'disponible' === $row['status'] ? 'ok' : 'planned', $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		self::close();
	}

	/**
	 * Guarda los ajustes.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		check_admin_referer( 'gdp_save_settings' );
		self::require_manager();

		$identity = array();
		if ( isset( $_POST['identity'] ) && is_array( $_POST['identity'] ) ) {
			foreach ( wp_unslash( $_POST['identity'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$key = sanitize_key( (string) $key );
				if ( ! in_array( $key, array( 'name', 'logo_url', 'primary', 'secondary', 'accent', 'background', 'text', 'font' ), true ) ) {
					continue;
				}
				$value = 'logo_url' === $key ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value );
				if ( '' !== $value ) {
					$identity[ $key ] = $value;
				}
			}
		}

		Options::update(
			array(
				'identity_use_site'     => ! empty( $_POST['identity_use_site'] ),
				'identity_overrides'    => $identity,
				'connector_enabled'     => ! empty( $_POST['connector_enabled'] ),
				'connector_read_only'   => ! empty( $_POST['connector_read_only'] ),
				'connector_token_days'  => isset( $_POST['connector_token_days'] ) ? max( 0, min( 3650, (int) $_POST['connector_token_days'] ) ) : 365,
				'require_two_factor'    => ! empty( $_POST['require_two_factor'] ),
				'operation_ttl_hours'   => isset( $_POST['operation_ttl_hours'] ) ? max( 1, min( 720, (int) $_POST['operation_ttl_hours'] ) ) : 24,
				'private_dir'           => isset( $_POST['private_dir'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['private_dir'] ) ) : 'gdp-privado',
				'uninstall_remove_data' => ! empty( $_POST['uninstall_remove_data'] ),
			)
		);

		Storage::ensure_private_dir();

		Admin::redirect_with_notice( Admin::url( 'settings' ), __( 'Ajustes guardados.', 'gestion-de-proyectos' ) );
	}
}
