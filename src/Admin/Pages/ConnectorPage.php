<?php
/**
 * Asistente de integración del conector.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Connector\Abilities;
use GDP\Connector\Auth;
use GDP\Connector\Connector;
use GDP\Connector\Diagnostics;
use GDP\Connector\Tokens;
use GDP\Core\Access;
use GDP\Core\Options;
use GDP\Core\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Guía paso a paso, con diagnóstico y generación de credenciales, para
 * conectar un asistente (Claude u otro cliente MCP) al plugin.
 *
 * Las instrucciones están versionadas (INSTRUCTIONS_VERSION) y enlazan a la
 * documentación oficial, porque la interfaz de los asistentes cambia con
 * frecuencia.
 */
final class ConnectorPage extends Page {

	public const INSTRUCTIONS_VERSION = '2026-09';

	public const DOC_CLAUDE_CONNECTORS = 'https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp';
	public const DOC_CLAUDE_REMOTE_MCP = 'https://claude.com/docs/connectors/custom/remote-mcp';
	public const DOC_MCP_ADAPTER       = 'https://github.com/WordPress/mcp-adapter';
	public const DOC_ABILITIES_API     = 'https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/';

	/**
	 * Registra los manejadores de formularios.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_create_token', array( self::class, 'handle_create_token' ) );
		add_action( 'admin_post_gdp_revoke_token', array( self::class, 'handle_revoke_token' ) );
	}

	/**
	 * Renderiza el asistente.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$user_id   = get_current_user_id();
		$can_use   = Access::is_manager() || current_user_can( Roles::CAP_CONNECTOR );
		$checks    = Diagnostics::run( isset( $_GET['recheck'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$passed    = Diagnostics::all_passed( $checks );
		$endpoint  = Connector::endpoint_url();
		$new_token = get_transient( 'gdp_new_token_' . $user_id );
		if ( $new_token ) {
			delete_transient( 'gdp_new_token_' . $user_id );
		}
		$tokens = Tokens::for_user( $user_id );

		self::open( __( 'Conector para asistentes de inteligencia artificial', 'gestion-de-proyectos' ), __( 'Integración paso a paso con Claude y otros clientes compatibles con el protocolo MCP.', 'gestion-de-proyectos' ) );
		?>
		<div class="gdp-card gdp-card--intro">
			<p><?php esc_html_e( 'El conector permite que un asistente consulte los datos del proyecto (planificación, documentos, compras, laboratorio) y proponga cambios. Ninguna escritura se aplica sin que una persona la confirme: el asistente propone, usted revisa la vista previa y confirma.', 'gestion-de-proyectos' ); ?></p>
			<p class="gdp-muted">
				<?php printf( /* translators: número de versión de las instrucciones. */ esc_html__( 'Instrucciones versión %s.', 'gestion-de-proyectos' ), esc_html( self::INSTRUCTIONS_VERSION ) ); ?>
				<?php esc_html_e( 'Si la interfaz del asistente cambió, consulte la documentación oficial:', 'gestion-de-proyectos' ); ?>
				<a href="<?php echo esc_url( self::DOC_CLAUDE_CONNECTORS ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Conectores personalizados en Claude', 'gestion-de-proyectos' ); ?></a>,
				<a href="<?php echo esc_url( self::DOC_CLAUDE_REMOTE_MCP ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Servidores MCP remotos', 'gestion-de-proyectos' ); ?></a>,
				<a href="<?php echo esc_url( self::DOC_MCP_ADAPTER ); ?>" target="_blank" rel="noopener">MCP Adapter</a>.
			</p>
		</div>

		<?php self::step_diagnostics( $checks, $passed ); ?>
		<?php self::step_credential( $can_use, $new_token, $tokens ); ?>
		<?php self::step_claude( $endpoint ); ?>
		<?php self::step_test( $endpoint ); ?>
		<?php self::step_maintenance(); ?>
		<?php
		self::close();
	}

	/**
	 * Paso 1: diagnóstico.
	 *
	 * @param array<int,array<string,string>> $checks Comprobaciones.
	 * @param bool                            $passed Todo correcto.
	 * @return void
	 */
	private static function step_diagnostics( array $checks, bool $passed ): void {
		?>
		<div class="gdp-card gdp-step">
			<h2><span class="gdp-step__number">1</span> <?php esc_html_e( 'Comprobar los requisitos', 'gestion-de-proyectos' ); ?></h2>
			<p><?php esc_html_e( 'El plugin comprueba cada requisito y explica cómo corregir lo que falte. Las comprobaciones que consultan el propio sitio por HTTP pueden fallar en hosting compartido aunque el conector funcione desde fuera.', 'gestion-de-proyectos' ); ?></p>
			<table class="widefat striped gdp-table gdp-diagnostics">
				<thead><tr><th><?php esc_html_e( 'Requisito', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Detalle', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Cómo corregirlo', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $checks as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['label'] ); ?></td>
						<td><?php echo self::badge( $c['status'], self::status_label( $c['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $c['detail'] ); ?></td>
						<td><?php echo esc_html( $c['fix'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( Admin::url( 'connector', array( 'recheck' => 1 ) ) ); ?>"><?php esc_html_e( 'Volver a comprobar (incluye pruebas HTTP)', 'gestion-de-proyectos' ); ?></a>
				<?php if ( $passed ) : ?>
					<span class="gdp-text-ok"><?php esc_html_e( 'Todos los requisitos críticos se cumplen.', 'gestion-de-proyectos' ); ?></span>
				<?php else : ?>
					<span class="gdp-text-danger"><?php esc_html_e( 'Corrija los requisitos en rojo antes de continuar.', 'gestion-de-proyectos' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Paso 2: credencial.
	 *
	 * @param bool                            $can_use   El usuario puede usar el conector.
	 * @param array|false                     $new_token Token recién creado (una sola vez).
	 * @param array<int,array<string,mixed>>  $tokens    Tokens del usuario.
	 * @return void
	 */
	private static function step_credential( bool $can_use, $new_token, array $tokens ): void {
		?>
		<div class="gdp-card gdp-step">
			<h2><span class="gdp-step__number">2</span> <?php esc_html_e( 'Generar su credencial', 'gestion-de-proyectos' ); ?></h2>
			<p><?php esc_html_e( 'El asistente se identifica con un token personal. El token hereda exactamente sus permisos en cada proyecto y puede limitarse a solo lectura. Se muestra una única vez: guárdelo en un lugar seguro.', 'gestion-de-proyectos' ); ?></p>

			<?php if ( is_array( $new_token ) && ! empty( $new_token['token'] ) ) : ?>
				<div class="notice notice-success inline gdp-token-reveal">
					<p><strong><?php esc_html_e( 'Token creado. Cópielo ahora; no volverá a mostrarse.', 'gestion-de-proyectos' ); ?></strong></p>
					<p class="gdp-copy"><code id="gdp-token-value"><?php echo esc_html( $new_token['token'] ); ?></code> <button type="button" class="button gdp-copy-button" data-copy="#gdp-token-value"><?php esc_html_e( 'Copiar', 'gestion-de-proyectos' ); ?></button></p>
					<?php if ( ! empty( $new_token['expires_at'] ) ) : ?>
						<p class="gdp-muted"><?php printf( /* translators: fecha de caducidad. */ esc_html__( 'Caduca el %s.', 'gestion-de-proyectos' ), esc_html( self::date( (string) $new_token['expires_at'], false ) ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $can_use ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
					<?php wp_nonce_field( 'gdp_create_token' ); ?>
					<input type="hidden" name="action" value="gdp_create_token">
					<label for="gdp-token-label"><?php esc_html_e( 'Etiqueta', 'gestion-de-proyectos' ); ?></label>
					<input type="text" id="gdp-token-label" name="label" placeholder="<?php esc_attr_e( 'Claude (cuenta personal)', 'gestion-de-proyectos' ); ?>" required>
					<label for="gdp-token-scope"><?php esc_html_e( 'Alcance', 'gestion-de-proyectos' ); ?></label>
					<select id="gdp-token-scope" name="scopes">
						<option value="read,write"><?php esc_html_e( 'Lectura y propuestas de cambio', 'gestion-de-proyectos' ); ?></option>
						<option value="read"><?php esc_html_e( 'Solo lectura', 'gestion-de-proyectos' ); ?></option>
					</select>
					<label for="gdp-token-days"><?php esc_html_e( 'Validez (días, 0 = sin caducidad)', 'gestion-de-proyectos' ); ?></label>
					<input type="number" id="gdp-token-days" name="days" min="0" max="3650" value="<?php echo (int) Tokens::default_days(); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Generar token', 'gestion-de-proyectos' ); ?></button>
				</form>
			<?php else : ?>
				<p class="gdp-text-danger"><?php esc_html_e( 'Su usuario no tiene permiso para usar el conector. Pida a un administrador el rol "Miembro de proyectos".', 'gestion-de-proyectos' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $tokens ) ) : ?>
				<h3><?php esc_html_e( 'Sus tokens', 'gestion-de-proyectos' ); ?></h3>
				<table class="widefat striped gdp-table">
					<thead><tr><th><?php esc_html_e( 'Etiqueta', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Prefijo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Alcance', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Último uso', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Caduca', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $tokens as $t ) : $revoked = ! empty( $t['revoked_at'] ); ?>
						<tr>
							<td><?php echo esc_html( $t['label'] ); ?></td>
							<td><code><?php echo esc_html( $t['token_prefix'] ); ?>…</code></td>
							<td><?php echo esc_html( $t['scopes'] ); ?></td>
							<td><?php echo esc_html( self::date( $t['last_used_at'] ) ); ?><?php echo $t['last_ip'] ? ' <span class="gdp-muted">' . esc_html( $t['last_ip'] ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( self::date( $t['expires_at'], false ) ); ?></td>
							<td><?php echo self::badge( $revoked ? 'cerrado' : 'ejecucion', $revoked ? __( 'revocado', 'gestion-de-proyectos' ) : __( 'activo', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td>
								<?php if ( ! $revoked ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
									<?php wp_nonce_field( 'gdp_revoke_token_' . (int) $t['id'] ); ?>
									<input type="hidden" name="action" value="gdp_revoke_token">
									<input type="hidden" name="token_id" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="button-link gdp-link-danger"><?php esc_html_e( 'Revocar', 'gestion-de-proyectos' ); ?></button>
								</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<details class="gdp-details">
				<summary><?php esc_html_e( 'Alternativa: contraseña de aplicación de WordPress', 'gestion-de-proyectos' ); ?></summary>
				<p><?php esc_html_e( 'También puede autenticarse con una contraseña de aplicación (Usuarios → Perfil → Contraseñas de aplicación) mediante autenticación básica HTTP. En ese caso la cabecera es "Authorization: Basic" con el valor de usuario:contraseña codificado en base64. El token del plugin es preferible porque puede limitarse a solo lectura, caducar y revocarse sin tocar la cuenta.', 'gestion-de-proyectos' ); ?></p>
			</details>
		</div>
		<?php
	}

	/**
	 * Paso 3: configurar Claude.
	 *
	 * @param string $endpoint URL del punto de entrada.
	 * @return void
	 */
	private static function step_claude( string $endpoint ): void {
		?>
		<div class="gdp-card gdp-step">
			<h2><span class="gdp-step__number">3</span> <?php esc_html_e( 'Añadir el conector en Claude', 'gestion-de-proyectos' ); ?></h2>

			<p><strong><?php esc_html_e( 'URL del servidor MCP (cópiela tal cual):', 'gestion-de-proyectos' ); ?></strong></p>
			<p class="gdp-copy"><code id="gdp-endpoint-value"><?php echo esc_html( $endpoint ); ?></code> <button type="button" class="button gdp-copy-button" data-copy="#gdp-endpoint-value"><?php esc_html_e( 'Copiar', 'gestion-de-proyectos' ); ?></button></p>

			<h3><?php esc_html_e( 'Claude (web, escritorio y móvil), cuenta personal', 'gestion-de-proyectos' ); ?></h3>
			<ol class="gdp-steps">
				<li><?php esc_html_e( 'Abra Claude e ingrese a la configuración de conectores: en el menú de personalización o ajustes, sección "Conectores".', 'gestion-de-proyectos' ); ?></li>
				<li><?php esc_html_e( 'Pulse "Añadir conector personalizado" (o el botón "+" y luego "Personalizado" → "Web").', 'gestion-de-proyectos' ); ?></li>
				<li><?php esc_html_e( 'Nombre: escriba el nombre del sitio o del proyecto. URL del servidor MCP: pegue la URL anterior.', 'gestion-de-proyectos' ); ?></li>
				<li><?php printf( /* translators: nombre de la cabecera HTTP. */ esc_html__( 'En "Cabeceras de la petición" (Request headers) añada una cabecera con nombre %1$s y, como valor, el token generado en el paso 2. No configure OAuth: este servidor no lo requiere.', 'gestion-de-proyectos' ), '<code>' . esc_html( Auth::HEADER ) . '</code>' ); ?></li>
				<li><?php esc_html_e( 'Guarde. Claude comprobará la conexión desde sus propios servidores; si aparece un error, revise el paso 1 y que el sitio sea alcanzable desde Internet.', 'gestion-de-proyectos' ); ?></li>
				<li><?php esc_html_e( 'En una conversación nueva, active el conector en el menú de herramientas y pida: "Consulta el estado del sistema con la herramienta gestion-de-proyectos-system-status".', 'gestion-de-proyectos' ); ?></li>
			</ol>

			<h3><?php esc_html_e( 'Claude para equipos y empresas', 'gestion-de-proyectos' ); ?></h3>
			<p><?php esc_html_e( 'Un administrador de la organización añade el conector en "Configuración de la organización → Conectores" con la misma URL y cabecera; luego cada persona lo activa en sus propios conectores con su propio token (cada token conserva los permisos de su dueño).', 'gestion-de-proyectos' ); ?></p>

			<h3><?php esc_html_e( 'Claude Code y otros clientes MCP', 'gestion-de-proyectos' ); ?></h3>
			<p><?php esc_html_e( 'Desde la terminal:', 'gestion-de-proyectos' ); ?></p>
			<pre class="gdp-pre">claude mcp add --transport http gestion-de-proyectos "<?php echo esc_html( $endpoint ); ?>" --header "<?php echo esc_html( Auth::HEADER ); ?>: gdp_SU_TOKEN"</pre>
			<p><?php esc_html_e( 'Cualquier cliente que admita servidores MCP remotos por HTTP con cabeceras fijas puede usar la misma URL y cabecera.', 'gestion-de-proyectos' ); ?></p>

			<details class="gdp-details">
				<summary><?php esc_html_e( 'Si el sitio usa enlaces permanentes simples', 'gestion-de-proyectos' ); ?></summary>
				<p><?php esc_html_e( 'La URL del servidor incluirá el parámetro rest_route; cópiela igualmente tal como aparece arriba. Conviene activar una estructura de enlaces permanentes en Ajustes → Enlaces permanentes.', 'gestion-de-proyectos' ); ?></p>
			</details>
		</div>
		<?php
	}

	/**
	 * Paso 4: probar.
	 *
	 * @param string $endpoint URL del punto de entrada.
	 * @return void
	 */
	private static function step_test( string $endpoint ): void {
		$init = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array( 'name' => 'prueba', 'version' => '1.0' ),
				),
			)
		);
		?>
		<div class="gdp-card gdp-step">
			<h2><span class="gdp-step__number">4</span> <?php esc_html_e( 'Probar desde fuera del sitio', 'gestion-de-proyectos' ); ?></h2>
			<p><?php esc_html_e( 'Estas órdenes, ejecutadas desde cualquier equipo con curl, confirman que el servidor responde y acepta el token. La primera inicia la sesión MCP y devuelve la cabecera Mcp-Session-Id; la segunda lista las herramientas.', 'gestion-de-proyectos' ); ?></p>
			<pre class="gdp-pre"># 1. Inicializar (anote el valor de Mcp-Session-Id en la respuesta)
curl -i -X POST "<?php echo esc_html( $endpoint ); ?>" \
  -H "Content-Type: application/json" \
  -H "<?php echo esc_html( Auth::HEADER ); ?>: gdp_SU_TOKEN" \
  -d '<?php echo esc_html( (string) $init ); ?>'

# 2. Listar herramientas (sustituya SESION por el valor anotado)
curl -s -X POST "<?php echo esc_html( $endpoint ); ?>" \
  -H "Content-Type: application/json" \
  -H "<?php echo esc_html( Auth::HEADER ); ?>: gdp_SU_TOKEN" \
  -H "Mcp-Session-Id: SESION" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'</pre>
			<p><?php esc_html_e( 'Respuestas esperadas: HTTP 200 con JSON en ambas. Un 401 indica token inválido; un 404, que el servidor MCP no está registrado (paso 1); un 403 de un cortafuegos, que el hosting bloquea la API REST para clientes externos.', 'gestion-de-proyectos' ); ?></p>

			<h3><?php esc_html_e( 'Herramientas disponibles', 'gestion-de-proyectos' ); ?></h3>
			<table class="widefat striped gdp-table">
				<thead><tr><th><?php esc_html_e( 'Herramienta', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Tipo', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Abilities::definitions() as $name => $def ) : $ro = ! empty( $def['meta']['annotations']['readOnlyHint'] ); ?>
					<tr>
						<td><code>gestion-de-proyectos-<?php echo esc_html( $name ); ?></code></td>
						<td><?php echo self::badge( $ro ? 'ok' : 'warn', $ro ? __( 'consulta', 'gestion-de-proyectos' ) : __( 'propuesta', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( (string) $def['description'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Paso 5: seguridad y mantenimiento.
	 *
	 * @return void
	 */
	private static function step_maintenance(): void {
		?>
		<div class="gdp-card gdp-step">
			<h2><span class="gdp-step__number">5</span> <?php esc_html_e( 'Seguridad y mantenimiento', 'gestion-de-proyectos' ); ?></h2>
			<ul class="gdp-list">
				<li><?php esc_html_e( 'Cada llamada del conector queda en la bitácora con el usuario dueño del token y el canal "connector".', 'gestion-de-proyectos' ); ?></li>
				<li><?php esc_html_e( 'Las herramientas de escritura solo proponen; revise y confirme las operaciones en Proyectos → Operaciones o desde el propio asistente con confirm-operation.', 'gestion-de-proyectos' ); ?></li>
				<li><?php printf( /* translators: estado actual del modo de solo lectura (activado o desactivado). */ esc_html__( 'Puede poner todo el conector en modo de solo lectura desde Ajustes (actualmente: %s).', 'gestion-de-proyectos' ), Options::get( 'connector_read_only', false ) ? esc_html__( 'solo lectura', 'gestion-de-proyectos' ) : esc_html__( 'lectura y propuestas', 'gestion-de-proyectos' ) ); ?></li>
				<li><?php esc_html_e( 'Revoque de inmediato cualquier token que sospeche comprometido y genere otro; los tokens caducan según la validez elegida.', 'gestion-de-proyectos' ); ?></li>
				<li><?php esc_html_e( 'Las peticiones de Claude llegan desde la infraestructura de Anthropic, no desde su equipo: si restringe por dirección IP, consulte las direcciones publicadas en la documentación oficial.', 'gestion-de-proyectos' ); ?></li>
				<li><?php esc_html_e( 'Datos que salen del sitio: solo lo que las herramientas devuelven en respuesta a una consulta del usuario. Los adjuntos privados no se exponen por el conector.', 'gestion-de-proyectos' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Crea un token para el usuario actual.
	 *
	 * @return void
	 */
	public static function handle_create_token(): void {
		check_admin_referer( 'gdp_create_token' );
		self::require_access();

		if ( ! Access::is_manager() && ! current_user_can( Roles::CAP_CONNECTOR ) ) {
			wp_die( esc_html__( 'Sin permiso para usar el conector.', 'gestion-de-proyectos' ), 403 );
		}

		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
		$scopes = isset( $_POST['scopes'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['scopes'] ) ) : 'read';
		$days   = isset( $_POST['days'] ) ? max( 0, min( 3650, (int) $_POST['days'] ) ) : Tokens::default_days();

		$result = Tokens::create( get_current_user_id(), '' === $label ? __( 'Conector', 'gestion-de-proyectos' ) : $label, $scopes, $days );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( Admin::url( 'connector' ), implode( ' ', $result->get_error_messages() ), 'error' );
		}

		set_transient( 'gdp_new_token_' . get_current_user_id(), $result, 300 );
		Admin::redirect_with_notice( Admin::url( 'connector' ) . '#gdp-token-value', __( 'Token generado; cópielo ahora.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Revoca un token del usuario actual.
	 *
	 * @return void
	 */
	public static function handle_revoke_token(): void {
		$id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
		check_admin_referer( 'gdp_revoke_token_' . $id );
		self::require_access();

		Tokens::revoke( $id, get_current_user_id(), Access::is_manager() );
		Admin::redirect_with_notice( Admin::url( 'connector' ), __( 'Token revocado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Etiqueta de un estado de diagnóstico.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case Diagnostics::OK:
				return __( 'Correcto', 'gestion-de-proyectos' );
			case Diagnostics::WARN:
				return __( 'Revisar', 'gestion-de-proyectos' );
			default:
				return __( 'Falta', 'gestion-de-proyectos' );
		}
	}
}
