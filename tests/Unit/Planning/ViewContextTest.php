<?php
/**
 * Pruebas del contexto de presentación de las vistas compartidas.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Planning;

use GDP\Modules\Planning\Views\Shortcodes;
use GDP\Modules\Planning\Views\ViewContext;
use PHPUnit\Framework\TestCase;

/**
 * Las mismas vistas construyen sus enlaces y formularios según el contexto:
 * en el panel con los parámetros de la pantalla y sin prefijo; en el sitio
 * sobre la dirección de la página, con prefijo y conservando los parámetros
 * ajenos a la vista.
 */
final class ViewContextTest extends TestCase {

	public function test_admin_context_builds_screen_urls_without_prefix(): void {
		$ctx = new ViewContext(
			'https://sitio.test/wp-admin/admin.php',
			array( 'page' => 'gdp-planning', 'project_id' => '16', 'view' => 'calendar' ),
			array( 'admin' => true, 'edit' => 'https://sitio.test/wp-admin/admin.php?page=gdp-planning&project_id=16&view=edit&id=%d', 'baselines' => 'https://sitio.test/wp-admin/admin.php?view=baselines', 'exports' => true, 'editable' => true )
		);

		$this->assertSame( 'mode', $ctx->param( 'mode' ) );
		$this->assertSame( 'https://sitio.test/wp-admin/admin.php?page=gdp-planning&project_id=16&view=calendar&mode=week&date=2026-10-05', $ctx->url( array( 'mode' => 'week', 'date' => '2026-10-05' ) ) );
		$this->assertSame( 'https://sitio.test/wp-admin/admin.php?page=gdp-planning&project_id=16&view=edit&id=42', $ctx->edit_url( 42 ) );
		$this->assertSame( '', $ctx->edit_url( 0 ) );
		$this->assertTrue( $ctx->links() );
		$this->assertTrue( $ctx->editable() );
		$this->assertTrue( $ctx->exports() );
		$this->assertSame( 'https://sitio.test/wp-admin/admin.php?view=baselines', $ctx->baselines_url() );

		$form = $ctx->form_start();
		$this->assertStringStartsWith( '<form method="get" action="https://sitio.test/wp-admin/admin.php" class="gdp-inline-form">', $form );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="gdp-planning">', $form );
		$this->assertStringContainsString( '<input type="hidden" name="project_id" value="16">', $form );
		$this->assertStringContainsString( '<input type="hidden" name="view" value="calendar">', $form );
	}

	public function test_front_context_prefixes_params_and_keeps_foreign_query_args(): void {
		$ctx = new ViewContext( '/faena/?gdp_week=2026-09-14&foro=3', array(), array( 'prefix' => 'gdp_' ) );

		$this->assertSame( 'gdp_mode', $ctx->param( 'mode' ) );
		$this->assertSame( '/faena/?gdp_week=2026-09-14&foro=3&gdp_mode=agenda&gdp_date=2026-10-05', $ctx->url( array( 'mode' => 'agenda', 'date' => '2026-10-05' ) ) );
		$this->assertFalse( $ctx->links() );
		$this->assertFalse( $ctx->editable() );
		$this->assertFalse( $ctx->exports() );
		$this->assertSame( '', $ctx->edit_url( 42 ) );
		$this->assertSame( '', $ctx->baselines_url() );

		// Los parámetros ajenos de la dirección base viajan como campos ocultos, porque el navegador descarta la consulta de la acción.
		$form = $ctx->form_start( 'gdp-x' );
		$this->assertStringStartsWith( '<form method="get" action="/faena/" class="gdp-x">', $form );
		$this->assertStringContainsString( '<input type="hidden" name="gdp_week" value="2026-09-14">', $form );
		$this->assertStringContainsString( '<input type="hidden" name="foro" value="3">', $form );
	}

	public function test_front_links_to_admin_do_not_imply_editing(): void {
		$ctx = new ViewContext( '/faena/', array(), array( 'prefix' => 'gdp_', 'admin' => true, 'edit' => '/wp-admin/admin.php?view=edit&id=%d', 'baselines' => '/wp-admin/admin.php?view=baselines' ) );

		$this->assertTrue( $ctx->links() );
		$this->assertSame( '/wp-admin/admin.php?view=edit&id=7', $ctx->edit_url( 7 ) );
		$this->assertFalse( $ctx->editable(), 'Sin la opción editable no se ofrecen cambios con el ratón aunque haya enlace de edición.' );
		$this->assertSame( '/wp-admin/admin.php?view=baselines', $ctx->baselines_url() );
	}

	public function test_get_reads_prefixed_request_param(): void {
		$_GET['gdp_date'] = '2026-10-05';
		$_GET['date']     = 'otra';
		$ctx              = new ViewContext( '/faena/', array(), array( 'prefix' => 'gdp_' ) );

		$this->assertSame( '2026-10-05', $ctx->get( 'date' ) );
		$this->assertSame( 'month', $ctx->get( 'mode', 'month' ) );
		unset( $_GET['gdp_date'], $_GET['date'] );
	}

	public function test_badge_escapes_and_sanitizes(): void {
		$this->assertSame( '<span class="gdp-badge gdp-badge--fail">Alta &lt;b&gt;</span>', ViewContext::badge( 'fail', 'Alta <b>' ) );
		$this->assertSame( '<span class="gdp-badge gdp-badge--ok">x</span>', ViewContext::badge( 'ok"', 'x' ) );
	}

	public function test_current_url_strips_only_the_view_params(): void {
		$_SERVER['REQUEST_URI'] = '/foro/tema-7/?gdp_mode=week&gdp_date=2026-10-05&gdp_week=2026-09-14&p=2';

		$this->assertSame( '/foro/tema-7/?gdp_week=2026-09-14&p=2', Shortcodes::current_url( 'calendar' ) );
		$this->assertSame( '/foro/tema-7/?gdp_mode=week&gdp_date=2026-10-05&p=2', Shortcodes::current_url( 'report' ) );
		$this->assertSame( '/foro/tema-7/?gdp_mode=week&gdp_date=2026-10-05&gdp_week=2026-09-14&p=2', Shortcodes::current_url( 'gantt' ) );
		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_snippets_cover_the_six_shortcodes(): void {
		$snippets = Shortcodes::snippets( 'RELAVES-COQUIMBO' );

		$this->assertSame( array_keys( Shortcodes::TAGS ), array_keys( $snippets ) );
		$this->assertSame( '[gdp_gantt proyecto="RELAVES-COQUIMBO"]', $snippets['gdp_gantt']['shortcode'] );
		foreach ( $snippets as $s ) {
			$this->assertNotSame( '', $s['label'] );
			$this->assertNotSame( '', $s['text'] );
		}
		$this->assertSame( array( 'gantt', 'board', 'calendar', 'alerts', 'workload', 'report' ), array_values( Shortcodes::TAGS ) );
	}
}
