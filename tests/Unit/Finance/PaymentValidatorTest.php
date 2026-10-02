<?php
/**
 * Pruebas de las validaciones por pago, la transliteración y la carga masiva.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Finance;

use GDP\Modules\Finance\Logic\BulkLoad;
use GDP\Modules\Finance\Logic\PaymentValidator;
use GDP\Modules\Finance\Logic\Transliterator;
use PHPUnit\Framework\TestCase;

/**
 * Casos tomados del documento de lineamientos.
 */
final class PaymentValidatorTest extends TestCase {

	private function context(): array {
		return array(
			'start_date'           => '2025-12-31',
			'end_date'             => '2027-12-31',
			'uf_value'             => 39500.0,
			'invoice_threshold_uf' => 2.0,
			'quotes_threshold'     => 3000000.0,
			'tender_threshold_uf'  => 3000.0,
			'items'                => array( 'personal' => 'Personal', 'generales' => 'Gastos generales', 'inversion' => 'Inversión' ),
			'today'                => '2026-10-02',
		);
	}

	public function test_clean_payment_has_no_blocking_issues(): void {
		$payment = array( 'amount' => 300000.0, 'doc_type' => 'boleta_honorarios', 'doc_date' => '2026-09-05', 'paid_at' => '2026-09-30', 'egress_number' => '4512', 'egress_document_id' => 7, 'item_slug' => 'personal', 'support' => array( array( 'kind' => 'contrato' ), array( 'kind' => 'informe' ) ) );
		$issues  = PaymentValidator::validate( $payment, $this->context() );
		$this->assertFalse( PaymentValidator::blocks( $issues ) );
		$this->assertTrue( PaymentValidator::complete( $issues ) );
	}

	public function test_boleta_above_two_uf_requires_invoice(): void {
		$payment = array( 'amount' => 79000.0, 'doc_type' => 'boleta', 'doc_date' => '2026-09-05', 'paid_at' => '2026-09-30', 'egress_number' => '1', 'egress_document_id' => 1, 'item_slug' => 'generales', 'support' => array( array( 'kind' => 'fondo_por_rendir' ) ) );
		$issues  = PaymentValidator::validate( $payment, $this->context() );
		$codes   = array_column( $issues, 'code' );
		$this->assertContains( 'V2', $codes );
		$this->assertTrue( PaymentValidator::blocks( $issues ) );

		$payment['amount'] = 78999.0;
		$issues            = PaymentValidator::validate( $payment, $this->context() );
		$this->assertNotContains( 'V2', array_column( $issues, 'code' ) );
	}

	public function test_dates_outside_the_agreement_block(): void {
		$payment = array( 'amount' => 100000.0, 'doc_type' => 'factura', 'doc_date' => '2025-12-01', 'paid_at' => '2025-12-15', 'egress_number' => '9', 'egress_document_id' => 1, 'item_slug' => 'generales', 'support' => array( array( 'kind' => 'cotizaciones' ) ) );
		$issues  = PaymentValidator::validate( $payment, $this->context() );
		$this->assertTrue( PaymentValidator::blocks( $issues ) );
		$this->assertSame( 'V1', $issues[0]['code'] );

		// Ejecutado dentro del plazo y pagado después: solo advierte sobre la fecha de fin en la plataforma.
		$ctx     = $this->context() + array( 'platform_end_date' => '2027-12-31' );
		$payment = array( 'amount' => 100000.0, 'doc_type' => 'factura', 'doc_date' => '2027-12-20', 'executed_at' => '2027-12-20', 'paid_at' => '2028-01-15', 'egress_number' => '9', 'egress_document_id' => 1, 'item_slug' => 'generales', 'support' => array( array( 'kind' => 'cotizaciones' ) ) );
		$ctx['today'] = '2028-02-01';
		$issues       = PaymentValidator::validate( $payment, $ctx );
		$this->assertFalse( PaymentValidator::blocks( $issues ) );
		$this->assertSame( 'V1', $issues[0]['code'] );
		$this->assertSame( PaymentValidator::WARN, $issues[0]['severity'] );
	}

	public function test_missing_egress_and_support_are_reported(): void {
		$payment = array( 'amount' => 5000000.0, 'doc_type' => 'factura', 'doc_date' => '2026-09-05', 'paid_at' => '2026-09-30', 'egress_number' => '', 'egress_document_id' => 0, 'item_slug' => 'inversion', 'support' => array() );
		$ctx     = $this->context() + array( 'required_support' => array( 'factura' => 'Factura', 'inventario' => 'Alta en el inventario' ), 'supplier_registered' => false );
		$issues  = PaymentValidator::validate( $payment, $ctx );
		$codes   = array_column( $issues, 'code' );
		$this->assertContains( 'V5', $codes );
		$this->assertContains( 'V3', $codes );
		$this->assertContains( 'V8', $codes );
		$this->assertContains( 'R', $codes );
		$this->assertTrue( PaymentValidator::blocks( $issues ) );
		$this->assertFalse( PaymentValidator::complete( $issues ) );
	}

	public function test_transliteration_and_tax_id_formats(): void {
		$this->assertSame( 'Munoz Pena', Transliterator::ascii( 'Muñoz Peña' ) );
		$this->assertSame( 'Investigacion', Transliterator::ascii( 'Investigación' ) );
		$this->assertSame( '12345678', Transliterator::tax_id_body( '12.345.678-9' ) );
		$this->assertSame( '12.345.678-9', Transliterator::tax_id_pretty( '123456789' ) );
		$this->assertSame( '76.111.222-3', Transliterator::tax_id_pretty( '76111222-3' ) );
	}

	public function test_bulk_load_rows_and_folders(): void {
		$payments = array(
			array( 'supplier_name' => 'Comercial Los Aromos y Cía.', 'supplier_tax_id' => '76111222-3', 'doc_type' => 'factura', 'doc_number' => '123', 'doc_date' => '2026-09-05', 'amount' => 150000.0, 'installment_no' => 1, 'platform_type' => 'operacion', 'platform_subclass' => 'Gastos generales', 'description' => 'Áridos', 'egress_number' => '4512', 'paid_at' => '2026-09-30', 'egress_file' => '/ce/4512.pdf', 'support_files' => array( '/t/123.pdf' ), 'supplier_registered' => true ),
			array( 'supplier_name' => 'José Peña', 'supplier_tax_id' => '12345678-9', 'doc_type' => 'boleta_honorarios', 'doc_number' => '45', 'doc_date' => '2026-09-05', 'amount' => 300000.0, 'installment_no' => 1, 'platform_type' => 'personal', 'platform_subclass' => 'Personal', 'description' => 'Honorarios septiembre', 'egress_number' => '4512', 'paid_at' => '2026-09-30', 'egress_file' => '/ce/4512.pdf', 'support_files' => array( '/t/45.pdf', '/t/informe.pdf' ), 'supplier_registered' => true ),
		);
		$rows = BulkLoad::rows( $payments );
		$this->assertCount( 2, $rows );
		$this->assertSame( '1', $rows[0]['folio'] );
		$this->assertSame( '2', $rows[1]['folio'] );
		$this->assertSame( 'Comercial Los Aromos y Cia.', $rows[0]['supplier'] );
		$this->assertSame( '76.111.222-3', $rows[0]['tax_id'] );
		$this->assertSame( '05/09/2026', $rows[0]['doc_date'] );
		$this->assertSame( '150000', $rows[0]['amount'] );
		$this->assertSame( 'Boleta de Honorarios', $rows[1]['doc_type'] );
		$this->assertSame( 'Aridos', $rows[0]['description'] );

		$folders = BulkLoad::folders( $payments );
		$this->assertSame( array( '/ce/4512.pdf' ), $folders[1]['CE'] );
		$this->assertSame( array(), $folders[2]['CE'], 'El egreso compartido va solo en la primera carpeta.' );
		$this->assertSame( array( '/t/45.pdf', '/t/informe.pdf' ), $folders[2]['T'] );

		$this->assertSame( array(), BulkLoad::problems( $payments, array( 1 => 19998000.0 ) ) );
		$problems = BulkLoad::problems( $payments, array( 1 => 400000.0 ) );
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'cuota 1', $problems[0] );
	}

	/**
	 * Egresos compartidos por una parte de la carga y egresos sin archivo.
	 */
	public function test_bulk_load_partial_egress_groups(): void {
		$base     = array( 'doc_type' => 'factura', 'amount' => 100000.0, 'installment_no' => 1, 'support_files' => array( '/t/x.pdf' ), 'supplier_registered' => true );
		$payments = array(
			$base + array( 'egress_number' => '10', 'egress_file' => null ),
			$base + array( 'egress_number' => '10', 'egress_file' => '/ce/10.pdf' ),
			$base + array( 'egress_number' => '11', 'egress_file' => null ),
			$base + array( 'egress_number' => '', 'egress_file' => null ),
		);
		$folders = BulkLoad::folders( $payments );
		$this->assertSame( array( '/ce/10.pdf' ), $folders[1]['CE'], 'El archivo del egreso puede venir en otro pago del mismo egreso.' );
		$this->assertSame( array( '/ce/10.pdf' ), $folders[2]['CE'], 'Un egreso que respalda solo parte de la carga se copia en cada folio.' );
		$this->assertSame( array(), $folders[3]['CE'] );

		$problems = BulkLoad::problems( $payments, array( 1 => 19998000.0 ) );
		$this->assertCount( 2, $problems );
		$this->assertStringContainsString( 'Folio 3: falta el archivo del comprobante de egreso 11', $problems[0] );
		$this->assertStringContainsString( 'Folio 4: sin número de comprobante de egreso', $problems[1] );
	}
}
