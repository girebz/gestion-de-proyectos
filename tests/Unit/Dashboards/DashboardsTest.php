<?php
/**
 * Pruebas de la configuración y los cálculos de los tableros.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Dashboards;

use GDP\Modules\Dashboards\DashboardData;
use GDP\Modules\Dashboards\DashboardSettings;
use GDP\Modules\Dashboards\DashboardsModule;
use PHPUnit\Framework\TestCase;

/**
 * El saneamiento solo conserva lo declarado publicable y nunca deja pasar
 * fuentes, bloques o correos inválidos; el avance ponderado y el plazo
 * transcurrido se calculan sin tocar la base de datos.
 */
final class DashboardsTest extends TestCase {

	private const PROJECT = array(
		'id'          => 7,
		'name'        => 'Valorización de relaves',
		'description' => "Primer párrafo del proyecto.\n\nSegundo párrafo con detalle interno.",
		'funder'      => 'Gobierno Regional de Coquimbo',
		'start_date'  => '2026-02-01',
		'end_date'    => '2028-01-31',
	);

	public function test_defaults_use_project_name_and_first_paragraph(): void {
		$d = DashboardSettings::defaults( self::PROJECT );

		$this->assertFalse( $d['public']['enabled'] );
		$this->assertSame( DashboardSettings::BLOCKS, $d['public']['blocks'] );
		$this->assertSame( 'Valorización de relaves', $d['public']['headline'] );
		$this->assertSame( 'Primer párrafo del proyecto.', $d['public']['summary'] );
		$this->assertSame( 'Gobierno Regional de Coquimbo', $d['public']['funding'] );
		$this->assertSame( 'admin@example.test', $d['public']['cta_email'] );
		$this->assertCount( 3, $d['public']['indicators'] );
		$this->assertFalse( $d['team']['require_member'] );
		$this->assertSame( 14, $d['team']['horizon_days'] );
	}

	public function test_sanitize_keeps_only_public_highlights_and_valid_values(): void {
		$clean = DashboardSettings::sanitize(
			array(
				'public' => array(
					'enabled'     => '1',
					'blocks'      => array( 'contacto', 'portada', 'inventado', 'etapas' ),
					'headline'    => ' <b>Relaves</b> que vuelven ',
					'summary'     => "Línea uno.\n\nLínea dos.",
					'stages'      => array( '1' => array( 'label' => 'Etapa A', 'text' => 'Qué hace', 'visible' => '1' ), '2' => array( 'label' => '', 'text' => '' ), '' => array( 'visible' => '1' ) ),
					'highlights'  => array( '1.2' => array( 'public' => '1', 'label' => 'Hito público' ), '1.3' => array( 'label' => 'No marcado' ), '2.1' => array( 'public' => '1' ) ),
					'indicators'  => array(
						array( 'label' => 'de avance', 'source' => 'avance', 'value' => '' ),
						array( 'label' => '', 'source' => 'meses', 'value' => '' ),
						array( 'label' => 'toneladas', 'source' => 'fuente_rara', 'value' => '12' ),
					),
					'partners'    => array(
						array( 'name' => 'Universidad', 'role' => 'Ejecutor', 'logo' => 'javascript:alert(1)', 'url' => 'https://www.ucentral.cl/' ),
						array( 'name' => '', 'role' => 'vacío' ),
					),
					'cta_email'   => 'no-es-un-correo',
					'cta_button'  => '',
					'cta_subject' => 'Consulta',
				),
				'team'   => array( 'require_member' => '1', 'horizon_days' => '400' ),
			),
			self::PROJECT
		);
		$p = $clean['public'];

		$this->assertTrue( $p['enabled'] );
		// Los bloques se conservan en el orden canónico y sin desconocidos.
		$this->assertSame( array( 'portada', 'etapas', 'contacto' ), $p['blocks'] );
		$this->assertSame( 'Relaves que vuelven', $p['headline'] );
		$this->assertSame( "Línea uno.\n\nLínea dos.", $p['summary'] );
		$this->assertSame( array( 'label' => 'Etapa A', 'text' => 'Qué hace', 'visible' => true ), $p['stages']['1'] );
		$this->assertFalse( $p['stages']['2']['visible'] );
		$this->assertCount( 2, $p['stages'] );
		// Solo los destacados marcados como públicos, por código de actividad.
		$this->assertSame( array( '1.2', '2.1' ), array_keys( $p['highlights'] ) );
		$this->assertSame( 'Hito público', $p['highlights']['1.2']['label'] );
		$this->assertSame( '', $p['highlights']['2.1']['label'] );
		// Sin etiqueta se descarta; la fuente desconocida pasa a manual.
		$this->assertCount( 2, $p['indicators'] );
		$this->assertSame( array( 'label' => 'toneladas', 'source' => 'manual', 'value' => '12' ), $p['indicators'][1] );
		// Institución sin nombre fuera; logo con esquema no permitido en blanco.
		$this->assertCount( 1, $p['partners'] );
		$this->assertSame( '', $p['partners'][0]['logo'] );
		$this->assertSame( 'https://www.ucentral.cl/', $p['partners'][0]['url'] );
		// Correo inválido: vuelve al correo del sitio; botón vacío: texto por defecto.
		$this->assertSame( 'admin@example.test', $p['cta_email'] );
		$this->assertNotSame( '', $p['cta_button'] );
		$this->assertFalse( $p['show_updated'] );
		$this->assertTrue( $clean['team']['require_member'] );
		$this->assertSame( 60, $clean['team']['horizon_days'] );
	}

	public function test_sanitize_accepts_valid_email_and_floors_horizon(): void {
		$clean = DashboardSettings::sanitize( array( 'public' => array( 'cta_email' => 'contacto@relavecircular.com' ), 'team' => array( 'horizon_days' => '1' ) ), self::PROJECT );

		$this->assertSame( 'contacto@relavecircular.com', $clean['public']['cta_email'] );
		$this->assertSame( array(), $clean['public']['blocks'] );
		$this->assertSame( 3, $clean['team']['horizon_days'] );
	}

	public function test_progress_is_weighted_by_duration_and_ignores_summaries(): void {
		$activities = array(
			array( 'kind' => 'summary', 'status' => 'en_curso', 'duration' => 100, 'percent' => 50 ),
			array( 'kind' => 'activity', 'status' => 'terminada', 'duration' => 10, 'percent' => 40 ),
			array( 'kind' => 'activity', 'status' => 'en_curso', 'duration' => 30, 'percent' => 50 ),
			array( 'kind' => 'milestone', 'status' => 'pendiente', 'duration' => 0, 'percent' => 0 ),
			array( 'kind' => 'activity', 'status' => 'cancelada', 'duration' => 50, 'percent' => 0 ),
		);
		// (10 × 100 + 30 × 50 + 1 × 0) / 41 = 61.
		$this->assertSame( 61, DashboardData::progress( $activities ) );
		$this->assertSame( 0, DashboardData::progress( array() ) );
	}

	public function test_clean_attribute_accepts_forum_quotes(): void {
		$this->assertSame( 'RELAVES-COQUIMBO', DashboardsModule::clean_attribute( ' "RELAVES-COQUIMBO" ' ) );
		$this->assertSame( 'relaves-coquimbo', DashboardsModule::clean_attribute( '&quot;relaves-coquimbo&quot;' ) );
		$this->assertSame( '01', DashboardsModule::clean_attribute( '“01”' ) );
		$this->assertSame( "portada,etapas", DashboardsModule::clean_attribute( '&#8220;portada,etapas&#8221;' ) );
		$this->assertSame( '', DashboardsModule::clean_attribute( '&nbsp;' ) );
	}

	public function test_time_elapsed(): void {
		$t = DashboardData::time_elapsed( self::PROJECT, '2026-10-01' );
		$this->assertSame( 33, $t['percent'] );
		$this->assertSame( 7, $t['months'] );
		$this->assertSame( '2026-02-01', $t['start'] );

		$this->assertSame( 100, DashboardData::time_elapsed( self::PROJECT, '2030-01-01' )['percent'] );
		$this->assertSame( 0, DashboardData::time_elapsed( self::PROJECT, '2025-01-01' )['percent'] );

		$none = DashboardData::time_elapsed( array( 'start_date' => null, 'end_date' => '2028-01-31' ), '2026-10-01' );
		$this->assertNull( $none['percent'] );
		$this->assertNull( $none['months'] );

		$open = DashboardData::time_elapsed( array( 'start_date' => '2026-02-01', 'end_date' => null ), '2026-10-01' );
		$this->assertNull( $open['percent'] );
		$this->assertSame( 7, $open['months'] );
	}
}
