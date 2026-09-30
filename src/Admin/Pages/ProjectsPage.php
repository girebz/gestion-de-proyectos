<?php
/**
 * Pantalla de proyectos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Admin\Pages;

use GDP\Admin\Admin;
use GDP\Core\Access;
use GDP\Core\Catalogs;
use GDP\Core\Roles;
use GDP\Domain\Projects\MemberRepository;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Registry;
use GDP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lista, ficha, formulario y equipo de cada proyecto.
 */
final class ProjectsPage extends Page {

	/**
	 * Registra los manejadores de formularios.
	 *
	 * @return void
	 */
	public static function register_handlers(): void {
		add_action( 'admin_post_gdp_save_project', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_gdp_delete_project', array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_gdp_set_member', array( self::class, 'handle_set_member' ) );
		add_action( 'admin_post_gdp_remove_member', array( self::class, 'handle_remove_member' ) );
	}

	/**
	 * Enrutador de la pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_access();

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				self::require_manager();
				self::render_form( null );
				break;
			case 'edit':
				$project = ProjectRepository::find( $id );
				if ( ! $project || ! Access::can( 'project.edit', $id ) ) {
					wp_die( esc_html__( 'Proyecto no encontrado o sin permiso.', 'gestion-de-proyectos' ), 403 );
				}
				self::render_form( $project );
				break;
			case 'view':
				$project = ProjectRepository::find( $id );
				if ( ! $project || ! Access::can( 'project.view', $id ) ) {
					wp_die( esc_html__( 'Proyecto no encontrado o sin permiso.', 'gestion-de-proyectos' ), 403 );
				}
				self::render_view( $project );
				break;
			default:
				self::render_list();
		}
	}

	/**
	 * Lista de proyectos visibles.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$projects = ProjectRepository::all( Access::visible_project_ids() );

		self::open( __( 'Proyectos', 'gestion-de-proyectos' ) );

		if ( Access::is_manager() ) {
			printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( Admin::url( 'projects', array( 'action' => 'new' ) ) ), esc_html__( 'Nuevo proyecto', 'gestion-de-proyectos' ) );
		}

		if ( empty( $projects ) ) {
			echo '<p class="gdp-muted">' . esc_html__( 'No hay proyectos visibles para su usuario.', 'gestion-de-proyectos' ) . '</p>';
			self::close();
			return;
		}
		?>
		<table class="widefat striped gdp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Financiador', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Inicio', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Presupuesto', 'gestion-de-proyectos' ); ?></th>
					<th><?php esc_html_e( 'Versión', 'gestion-de-proyectos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $projects as $p ) : ?>
				<tr>
					<td><code><?php echo esc_html( $p['code'] ); ?></code></td>
					<td>
						<strong><a href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'view', 'id' => $p['id'] ) ) ); ?>"><?php echo esc_html( $p['name'] ); ?></a></strong>
						<div class="row-actions">
							<span><a href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'view', 'id' => $p['id'] ) ) ); ?>"><?php esc_html_e( 'Ver', 'gestion-de-proyectos' ); ?></a></span>
							<?php if ( Access::can( 'project.edit', (int) $p['id'] ) ) : ?>
								| <span><a href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'edit', 'id' => $p['id'] ) ) ); ?>"><?php esc_html_e( 'Editar', 'gestion-de-proyectos' ); ?></a></span>
							<?php endif; ?>
						</div>
					</td>
					<td><?php echo esc_html( $p['funder'] ); ?><?php echo $p['funding_code'] ? ' <span class="gdp-muted">(' . esc_html( $p['funding_code'] ) . ')</span>' : ''; ?></td>
					<td><?php echo self::badge( (string) $p['status'], Catalogs::label( Catalogs::PROJECT_STATUS, (string) $p['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( $p['start_date'] ? $p['start_date'] : '—' ); ?></td>
					<td><?php echo esc_html( $p['end_date'] ? $p['end_date'] : '—' ); ?></td>
					<td><?php echo Access::can( 'procurement.view_amounts', (int) $p['id'] ) ? self::money( $p['budget_total'], (string) $p['currency'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo (int) $p['version']; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::close();
	}

	/**
	 * Ficha del proyecto con su equipo.
	 *
	 * @param array<string,mixed> $p Proyecto.
	 * @return void
	 */
	private static function render_view( array $p ): void {
		$id       = (int) $p['id'];
		$members  = MemberRepository::for_project( $id );
		$registry = Plugin::instance()->modules();

		self::open( $p['name'], sprintf( '%s · %s', $p['code'], Catalogs::label( Catalogs::PROJECT_STATUS, (string) $p['status'] ) ) );
		?>
		<p>
			<a class="button" href="<?php echo esc_url( Admin::url( 'projects' ) ); ?>">&larr; <?php esc_html_e( 'Volver a la lista', 'gestion-de-proyectos' ); ?></a>
			<?php if ( Access::can( 'project.edit', $id ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( Admin::url( 'projects', array( 'action' => 'edit', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Editar', 'gestion-de-proyectos' ); ?></a>
			<?php endif; ?>
		</p>

		<div class="gdp-grid">
			<div class="gdp-card">
				<h2><?php esc_html_e( 'Ficha', 'gestion-de-proyectos' ); ?></h2>
				<table class="gdp-facts">
					<tr><th><?php esc_html_e( 'Nombre corto', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $p['short_name'] ? $p['short_name'] : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Financiador', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $p['funder'] ? $p['funder'] : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Código de financiamiento', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $p['funding_code'] ? $p['funding_code'] : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Entidad ejecutora', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $p['executing_entity'] ? $p['executing_entity'] : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Inicio', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $p['start_date'] ? $p['start_date'] : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( $p['end_date'] ? $p['end_date'] : '—' ); ?></td></tr>
					<?php if ( Access::can( 'procurement.view_amounts', $id ) ) : ?>
					<tr><th><?php esc_html_e( 'Presupuesto total', 'gestion-de-proyectos' ); ?></th><td><?php echo self::money( $p['budget_total'], (string) $p['currency'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'Versión del registro', 'gestion-de-proyectos' ); ?></th><td><?php echo (int) $p['version']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Última modificación', 'gestion-de-proyectos' ); ?></th><td><?php echo esc_html( self::date( $p['updated_at'] ) ); ?></td></tr>
				</table>
				<?php if ( $p['description'] ) : ?>
					<h3><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></h3>
					<div class="gdp-prose"><?php echo wp_kses_post( wpautop( (string) $p['description'] ) ); ?></div>
				<?php endif; ?>
			</div>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Equipo', 'gestion-de-proyectos' ); ?></h2>
				<?php if ( empty( $members ) ) : ?>
					<p class="gdp-muted"><?php esc_html_e( 'Sin miembros asignados.', 'gestion-de-proyectos' ); ?></p>
				<?php else : ?>
					<table class="widefat striped gdp-table">
						<thead><tr><th><?php esc_html_e( 'Persona', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Perfil', 'gestion-de-proyectos' ); ?></th><th><?php esc_html_e( 'Desde', 'gestion-de-proyectos' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $members as $m ) : ?>
							<tr>
								<td><?php echo esc_html( $m['display_name'] ); ?><?php echo Access::is_manager() && $m['email'] ? ' <span class="gdp-muted">' . esc_html( $m['email'] ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( $m['role_label'] ); ?></td>
								<td><?php echo esc_html( self::date( $m['since'], false ) ); ?></td>
								<td>
									<?php if ( Access::can( 'project.members', $id ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-inline-form" data-confirm="1">
										<?php wp_nonce_field( 'gdp_remove_member_' . $id ); ?>
										<input type="hidden" name="action" value="gdp_remove_member">
										<input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
										<input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
										<button type="submit" class="button-link gdp-link-danger"><?php esc_html_e( 'Retirar', 'gestion-de-proyectos' ); ?></button>
									</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( Access::can( 'project.members', $id ) ) : ?>
					<h3><?php esc_html_e( 'Añadir o cambiar perfil', 'gestion-de-proyectos' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-form-row">
						<?php wp_nonce_field( 'gdp_set_member_' . $id ); ?>
						<input type="hidden" name="action" value="gdp_set_member">
						<input type="hidden" name="project_id" value="<?php echo (int) $id; ?>">
						<label for="gdp-member-user" class="screen-reader-text"><?php esc_html_e( 'Usuario', 'gestion-de-proyectos' ); ?></label>
						<?php
						wp_dropdown_users(
							array(
								'name'            => 'user_id',
								'id'              => 'gdp-member-user',
								'show'            => 'display_name_with_login',
								'orderby'         => 'display_name',
								'include_selected' => true,
							)
						);
						?>
						<label for="gdp-member-role" class="screen-reader-text"><?php esc_html_e( 'Perfil', 'gestion-de-proyectos' ); ?></label>
						<select name="role" id="gdp-member-role">
							<?php foreach ( Roles::project_roles() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Guardar', 'gestion-de-proyectos' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<?php
			/**
			 * Permite a los módulos añadir tarjetas a la ficha del proyecto.
			 *
			 * @param array<string,mixed> $p Proyecto.
			 */
			do_action( 'gdp_project_view_cards', $p );
			?>

			<div class="gdp-card">
				<h2><?php esc_html_e( 'Módulos', 'gestion-de-proyectos' ); ?></h2>
				<ul class="gdp-list">
					<?php foreach ( $registry->status() as $row ) : ?>
						<li>
							<?php echo esc_html( $row['label'] ); ?>
							<?php
							if ( 'planificado' === $row['status'] ) {
								echo self::badge( 'planned', sprintf( __( 'etapa %d, planificado', 'gestion-de-proyectos' ), (int) $row['stage'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							} else {
								echo self::badge( $registry->is_enabled( $row['slug'], $id ) ? 'ejecucion' : 'suspendido', $registry->is_enabled( $row['slug'], $id ) ? __( 'activo', 'gestion-de-proyectos' ) : __( 'inactivo', 'gestion-de-proyectos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
		self::close();
	}

	/**
	 * Formulario de creación o edición.
	 *
	 * @param array<string,mixed>|null $p Proyecto (null = nuevo).
	 * @return void
	 */
	private static function render_form( ?array $p ): void {
		$is_new = null === $p;
		$p      = $p ?? array(
			'id'               => 0,
			'code'             => '',
			'name'             => '',
			'short_name'       => '',
			'description'      => '',
			'funder'           => '',
			'funding_code'     => '',
			'executing_entity' => '',
			'status'           => 'planificacion',
			'start_date'       => '',
			'end_date'         => '',
			'budget_total'     => null,
			'currency'         => 'CLP',
			'version'          => 0,
			'settings'         => array(),
		);

		$enabled_modules = isset( $p['settings']['modules'] ) && is_array( $p['settings']['modules'] ) ? $p['settings']['modules'] : array();

		self::open( $is_new ? __( 'Nuevo proyecto', 'gestion-de-proyectos' ) : sprintf( __( 'Editar: %s', 'gestion-de-proyectos' ), $p['name'] ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-card gdp-card--form">
			<?php wp_nonce_field( 'gdp_save_project_' . (int) $p['id'] ); ?>
			<input type="hidden" name="action" value="gdp_save_project">
			<input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
			<input type="hidden" name="expected_version" value="<?php echo (int) $p['version']; ?>">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gdp-code"><?php esc_html_e( 'Código', 'gestion-de-proyectos' ); ?> *</label></th>
					<td><input type="text" id="gdp-code" name="code" class="regular-text" required value="<?php echo esc_attr( $p['code'] ); ?>" placeholder="relaves-coquimbo"><p class="description"><?php esc_html_e( 'Identificador corto y estable, sin espacios ni tildes. Se usa en exportaciones y en el conector.', 'gestion-de-proyectos' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-name"><?php esc_html_e( 'Nombre', 'gestion-de-proyectos' ); ?> *</label></th>
					<td><input type="text" id="gdp-name" name="name" class="large-text" required value="<?php echo esc_attr( $p['name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-short-name"><?php esc_html_e( 'Nombre corto', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-short-name" name="short_name" class="regular-text" value="<?php echo esc_attr( $p['short_name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-funder"><?php esc_html_e( 'Entidad financiadora', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-funder" name="funder" class="regular-text" value="<?php echo esc_attr( $p['funder'] ); ?>" placeholder="Gobierno Regional de Coquimbo"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-funding-code"><?php esc_html_e( 'Código de financiamiento', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-funding-code" name="funding_code" class="regular-text" value="<?php echo esc_attr( $p['funding_code'] ); ?>" placeholder="BIP 40075890-0"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-executing"><?php esc_html_e( 'Entidad ejecutora', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="text" id="gdp-executing" name="executing_entity" class="regular-text" value="<?php echo esc_attr( $p['executing_entity'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-status"><?php esc_html_e( 'Estado', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<select id="gdp-status" name="status">
							<?php foreach ( Catalogs::items( Catalogs::PROJECT_STATUS ) as $item ) : ?>
								<option value="<?php echo esc_attr( $item['slug'] ); ?>" <?php selected( $p['status'], $item['slug'] ); ?>><?php echo esc_html( $item['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-start"><?php esc_html_e( 'Inicio', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="date" id="gdp-start" name="start_date" value="<?php echo esc_attr( (string) $p['start_date'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-end"><?php esc_html_e( 'Término', 'gestion-de-proyectos' ); ?></label></th>
					<td><input type="date" id="gdp-end" name="end_date" value="<?php echo esc_attr( (string) $p['end_date'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-budget"><?php esc_html_e( 'Presupuesto total', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<input type="number" step="0.01" min="0" id="gdp-budget" name="budget_total" value="<?php echo esc_attr( null === $p['budget_total'] ? '' : (string) $p['budget_total'] ); ?>">
						<input type="text" name="currency" maxlength="3" size="4" value="<?php echo esc_attr( $p['currency'] ); ?>" aria-label="<?php esc_attr_e( 'Moneda', 'gestion-de-proyectos' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-description"><?php esc_html_e( 'Descripción', 'gestion-de-proyectos' ); ?></label></th>
					<td><textarea id="gdp-description" name="description" rows="5" class="large-text"><?php echo esc_textarea( (string) $p['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdp-threshold"><?php esc_html_e( 'Desviación que exige aprobación', 'gestion-de-proyectos' ); ?></label></th>
					<td>
						<input type="number" id="gdp-threshold" name="approval_threshold_days" min="0" max="365" class="small-text" value="<?php echo (int) ( $p['settings']['planning']['approval_threshold_days'] ?? 10 ); ?>"> <?php esc_html_e( 'días hábiles', 'gestion-de-proyectos' ); ?>
						<p class="description"><?php esc_html_e( 'Cuando una actividad o hito se atrasa respecto de la línea base vigente más allá de este umbral, o el término programado supera el contractual, el módulo de planificación alerta que la reprogramación exige aprobación formal del financiador.', 'gestion-de-proyectos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Módulos opcionales', 'gestion-de-proyectos' ); ?></th>
					<td>
						<?php foreach ( Registry::roadmap() as $slug => $info ) : ?>
							<?php if ( $info['core'] ) { continue; } ?>
							<label class="gdp-check">
								<input type="checkbox" name="modules[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $enabled_modules, true ) ); ?>>
								<?php echo esc_html( $info['label'] ); ?> <span class="gdp-muted">(<?php printf( esc_html__( 'etapa %d', 'gestion-de-proyectos' ), (int) $info['stage'] ); ?>)</span>
							</label><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Los módulos del núcleo (planificación, documentos, adquisiciones, reuniones, datos) están siempre activos.', 'gestion-de-proyectos' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $is_new ? esc_html__( 'Crear proyecto', 'gestion-de-proyectos' ) : esc_html__( 'Guardar cambios', 'gestion-de-proyectos' ); ?></button>
				<a class="button" href="<?php echo esc_url( $is_new ? Admin::url( 'projects' ) : Admin::url( 'projects', array( 'action' => 'view', 'id' => $p['id'] ) ) ); ?>"><?php esc_html_e( 'Cancelar', 'gestion-de-proyectos' ); ?></a>
			</p>
		</form>

		<?php if ( ! $is_new && Access::is_manager() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gdp-danger-zone" data-confirm="1">
				<?php wp_nonce_field( 'gdp_delete_project_' . (int) $p['id'] ); ?>
				<input type="hidden" name="action" value="gdp_delete_project">
				<input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
				<label for="gdp-confirm-code" class="screen-reader-text"><?php esc_html_e( 'Código del proyecto', 'gestion-de-proyectos' ); ?></label>
				<input type="text" id="gdp-confirm-code" name="confirm_code" autocomplete="off" placeholder="<?php echo esc_attr( $p['code'] ); ?>" aria-describedby="gdp-confirm-help">
				<button type="submit" class="button gdp-button-danger"><?php esc_html_e( 'Eliminar proyecto', 'gestion-de-proyectos' ); ?></button>
				<span class="gdp-muted" id="gdp-confirm-help"><?php esc_html_e( 'Eliminación definitiva: escriba el código del proyecto para confirmarla. Se eliminan el proyecto, sus miembros y los datos de todos los módulos; la bitácora conserva el registro. Las eliminaciones de actividades, calendarios y líneas base, en cambio, van a la papelera y pueden restaurarse.', 'gestion-de-proyectos' ); ?></span>
			</form>
		<?php endif; ?>
		<?php
		self::close();
	}

	/**
	 * Guarda un proyecto (nuevo o existente).
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_save_project_' . $id );

		if ( 0 === $id ) {
			self::require_manager();
		} elseif ( ! Access::can( 'project.edit', $id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$fields = array( 'code', 'name', 'short_name', 'description', 'funder', 'funding_code', 'executing_entity', 'status', 'start_date', 'end_date', 'budget_total', 'currency' );
		$data   = array();
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = 'description' === $field ? wp_kses_post( wp_unslash( (string) $_POST[ $field ] ) ) : sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}

		$modules = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['modules'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$current = $id > 0 ? ProjectRepository::find( $id ) : null;
		$settings = $current ? $current['settings'] : array();
		$settings['modules'] = array_values( array_intersect( $modules, array_keys( Registry::roadmap() ) ) );
		if ( isset( $_POST['approval_threshold_days'] ) ) {
			$settings['planning']                            = is_array( $settings['planning'] ?? null ) ? $settings['planning'] : array();
			$settings['planning']['approval_threshold_days'] = max( 0, min( 365, (int) $_POST['approval_threshold_days'] ) );
		}
		$data['settings']    = $settings;

		if ( 0 === $id ) {
			$result = ProjectRepository::create( $data );
			if ( is_wp_error( $result ) ) {
				Admin::redirect_with_notice( Admin::url( 'projects', array( 'action' => 'new' ) ), implode( ' ', $result->get_error_messages() ), 'error' );
			}
			Admin::redirect_with_notice( Admin::url( 'projects', array( 'action' => 'view', 'id' => $result ) ), __( 'Proyecto creado.', 'gestion-de-proyectos' ) );
		}

		$expected = isset( $_POST['expected_version'] ) ? (int) $_POST['expected_version'] : null;
		$result   = ProjectRepository::update( $id, $data, $expected );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( Admin::url( 'projects', array( 'action' => 'edit', 'id' => $id ) ), implode( ' ', $result->get_error_messages() ), 'error' );
		}

		Admin::redirect_with_notice( Admin::url( 'projects', array( 'action' => 'view', 'id' => $id ) ), __( 'Proyecto actualizado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Elimina un proyecto.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'gdp_delete_project_' . $id );
		self::require_manager();

		// Segunda confirmación: la eliminación de un proyecto es definitiva (los módulos borran sus datos).
		$project = ProjectRepository::find( $id );
		$typed   = isset( $_POST['confirm_code'] ) ? sanitize_title( wp_unslash( (string) $_POST['confirm_code'] ) ) : '';
		if ( ! $project || $typed !== $project['code'] ) {
			Admin::redirect_with_notice( Admin::url( 'projects', array( 'action' => 'edit', 'id' => $id ) ), __( 'Para eliminar el proyecto escriba su código exactamente como aparece en la ficha.', 'gestion-de-proyectos' ), 'error' );
		}

		$result = ProjectRepository::delete( $id );
		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( Admin::url( 'projects' ), implode( ' ', $result->get_error_messages() ), 'error' );
		}

		Admin::redirect_with_notice( Admin::url( 'projects' ), __( 'Proyecto eliminado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Asigna un perfil a un usuario.
	 *
	 * @return void
	 */
	public static function handle_set_member(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_set_member_' . $project_id );

		if ( ! Access::can( 'project.members', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$role    = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : '';

		$result = MemberRepository::set_role( $project_id, $user_id, $role );
		$url    = Admin::url( 'projects', array( 'action' => 'view', 'id' => $project_id ) );

		if ( is_wp_error( $result ) ) {
			Admin::redirect_with_notice( $url, implode( ' ', $result->get_error_messages() ), 'error' );
		}

		Admin::redirect_with_notice( $url, __( 'Perfil guardado.', 'gestion-de-proyectos' ) );
	}

	/**
	 * Retira a un usuario del proyecto.
	 *
	 * @return void
	 */
	public static function handle_remove_member(): void {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		check_admin_referer( 'gdp_remove_member_' . $project_id );

		if ( ! Access::can( 'project.members', $project_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'gestion-de-proyectos' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		MemberRepository::remove( $project_id, $user_id );

		Admin::redirect_with_notice( Admin::url( 'projects', array( 'action' => 'view', 'id' => $project_id ) ), __( 'Miembro retirado.', 'gestion-de-proyectos' ) );
	}
}
