<?php
/**
 * Pruebas del escritor de libros XLSX con formato.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Core;

use GDP\Core\Workbook;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Letras de columna, números de serie de las fechas, nombres de pestaña y
 * el libro escrito: partes del paquete, celdas con su tipo y formato,
 * fórmulas con su valor calculado, filas fijas, filtros, vínculos y títulos
 * de impresión.
 */
final class WorkbookTest extends TestCase {

	public function test_column_letters(): void {
		$this->assertSame( 'A', Workbook::column( 0 ) );
		$this->assertSame( 'Z', Workbook::column( 25 ) );
		$this->assertSame( 'AA', Workbook::column( 26 ) );
		$this->assertSame( 'AZ', Workbook::column( 51 ) );
		$this->assertSame( 'ZZ', Workbook::column( 701 ) );
		$this->assertSame( 'AAA', Workbook::column( 702 ) );
		$this->assertSame( 'C7', Workbook::ref( 6, 2 ) );
	}

	public function test_date_serial_counts_days_from_1899_12_30(): void {
		$this->assertSame( 45292, Workbook::date_serial( '2024-01-01' ) );
		$this->assertSame( 46037, Workbook::date_serial( '2026-01-15' ) );
		$this->assertSame( 46037, Workbook::date_serial( '2026-01-15 10:30:00' ) );
		$this->assertNull( Workbook::date_serial( '2026-02-30' ) );
		$this->assertNull( Workbook::date_serial( '15/01/2026' ) );
		$this->assertNull( Workbook::date_serial( '' ) );
	}

	public function test_sheet_names_are_valid_short_and_unique(): void {
		$names = Workbook::sheet_names(
			array(
				array( 'name' => 'Pagos' ),
				array( 'name' => 'pagos' ),
				array( 'name' => 'Caja: [mes/año]?' ),
				array( 'name' => str_repeat( 'Rendiciones ', 5 ) ),
				array( 'name' => "  'Notas'  " ),
				array( 'name' => '' ),
			)
		);
		$this->assertSame( 'Pagos', $names[0] );
		$this->assertSame( 'pagos (2)', $names[1] );
		$this->assertSame( 'Caja mes año', $names[2] );
		$this->assertLessThanOrEqual( 31, mb_strlen( $names[3] ) );
		$this->assertSame( 'Notas', $names[4] );
		$this->assertSame( 'Hoja 6', $names[5] );
		$this->assertSame( "'Caja de O''Higgins'", Workbook::quote_sheet( "Caja de O'Higgins" ) );
	}

	public function test_every_style_has_a_cell_format(): void {
		$indexes = array_values( Workbook::STYLES );
		$this->assertSame( count( $indexes ), count( array_unique( $indexes ) ) );
		$this->assertNotContains( 0, $indexes );
		$this->assertSame( max( $indexes ), count( $indexes ) );
	}

	public function test_written_book_has_typed_cells_formulas_and_layout(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'Sin la extensión zip.' );
		}
		$binary = Workbook::write(
			array(
				array(
					'name'        => 'Pagos',
					'columns'     => array( array( 'width' => 12 ), array( 'width' => 14, 'format' => 'money' ), array( 'width' => 12, 'format' => 'date' ) ),
					'header'      => 1,
					'freeze'      => true,
					'freeze_cols' => 1,
					'filter'      => true,
					'filter_last' => 3,
					'rows'        => array(
						array( array( 'v' => 'Pagos & compras <2026>', 's' => 'title' ) ),
						array( 'Código', 'Monto', 'Fecha' ),
						array( 'PG-0001', 10.5, '2026-01-15' ),
						array( "PG-0002\x07", 19.5, 'sin fecha' ),
						array(
							array( 'v' => 'Total', 's' => 'total' ),
							array( 'v' => 30.0, 'f' => 'SUBTOTAL(109,B3:B4)', 's' => 'total_money' ),
							array( 'v' => '', 's' => 'total' ),
						),
					),
					'links'       => array( array( 'ref' => 'A1', 'location' => "'Resumen'!A1", 'display' => 'Resumen' ) ),
				),
				array(
					'name' => 'Resumen',
					'rows' => array( array( true, null, 3 ) ),
				),
			),
			array( 'title' => 'Libro de prueba' )
		);
		$this->assertIsString( $binary );
		$this->assertStringStartsWith( 'PK', $binary );

		$path = (string) tempnam( sys_get_temp_dir(), 'gdp-prueba' );
		file_put_contents( $path, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path ) );
		$types    = (string) $zip->getFromName( '[Content_Types].xml' );
		$workbook = (string) $zip->getFromName( 'xl/workbook.xml' );
		$sheet    = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$second   = (string) $zip->getFromName( 'xl/worksheets/sheet2.xml' );
		$styles   = (string) $zip->getFromName( 'xl/styles.xml' );
		$core     = (string) $zip->getFromName( 'docProps/core.xml' );
		$zip->close();
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		// Paquete: dos hojas, nombres, filtro y títulos de impresión.
		$this->assertStringContainsString( '/xl/worksheets/sheet2.xml', $types );
		$this->assertStringContainsString( '<sheet name="Pagos" sheetId="1" r:id="rId1"/>', $workbook );
		$this->assertStringContainsString( '<sheet name="Resumen" sheetId="2" r:id="rId2"/>', $workbook );
		$this->assertStringContainsString( '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">&apos;Pagos&apos;!$A$2:$C$4</definedName>', $workbook );
		$this->assertStringContainsString( '<definedName name="_xlnm.Print_Titles" localSheetId="0">&apos;Pagos&apos;!$2:$2</definedName>', $workbook );
		$this->assertStringContainsString( 'fullCalcOnLoad="1"', $workbook );
		$this->assertStringContainsString( '<dc:title>Libro de prueba</dc:title>', $core );

		// Vista: encabezado y primera columna fijos; filtro sobre los datos sin la fila de totales.
		$this->assertStringContainsString( '<pane xSplit="1" ySplit="2" topLeftCell="B3" activePane="bottomRight" state="frozen"/>', $sheet );
		$this->assertStringContainsString( '<autoFilter ref="A2:C4"/>', $sheet );
		$this->assertStringContainsString( '<col min="2" max="2" width="14" customWidth="1"/>', $sheet );

		// Celdas: título escapado, encabezado, texto (formato explícito), monto, fecha como número de serie.
		$text  = Workbook::STYLES['text'];
		$money = Workbook::STYLES['money'];
		$date  = Workbook::STYLES['date'];
		$this->assertStringContainsString( '<c r="A1" s="' . Workbook::STYLES['title'] . '" t="inlineStr"><is><t xml:space="preserve">Pagos &amp; compras &lt;2026&gt;</t></is></c>', $sheet );
		$this->assertStringContainsString( '<c r="A2" s="' . Workbook::STYLES['header'] . '" t="inlineStr">', $sheet );
		$this->assertStringContainsString( '<c r="A3" s="' . $text . '" t="inlineStr"><is><t xml:space="preserve">PG-0001</t></is></c>', $sheet );
		$this->assertStringContainsString( '<c r="B3" s="' . $money . '"><v>10.5</v></c>', $sheet );
		$this->assertStringContainsString( '<c r="C3" s="' . $date . '"><v>46037</v></c>', $sheet );
		// Un texto que no es fecha queda como texto; los caracteres de control se quitan.
		$this->assertStringContainsString( '<c r="C4" s="' . $date . '" t="inlineStr"><is><t xml:space="preserve">sin fecha</t></is></c>', $sheet );
		$this->assertStringContainsString( '<t xml:space="preserve">PG-0002</t>', $sheet );
		// Fórmula con su valor calculado y celda vacía con formato visible.
		$this->assertStringContainsString( '<c r="B5" s="' . Workbook::STYLES['total_money'] . '"><f>SUBTOTAL(109,B3:B4)</f><v>30</v></c>', $sheet );
		$this->assertStringContainsString( '<c r="C5" s="' . Workbook::STYLES['total'] . '"/>', $sheet );
		// Vínculo interno a otra hoja.
		$this->assertStringContainsString( '<hyperlink ref="A1" location="&apos;Resumen&apos;!A1" display="Resumen"/>', $sheet );
		$this->assertStringContainsString( '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>', $sheet );

		// Segunda hoja: booleano, celda nula omitida y número sin formato de columna.
		$this->assertStringContainsString( '<c r="A1" s="' . $text . '" t="b"><v>1</v></c>', $second );
		$this->assertFalse( str_contains( $second, 'r="B1"' ) );
		$this->assertStringContainsString( '<c r="C1" s="' . $text . '"><v>3</v></c>', $second );
		$this->assertFalse( str_contains( $second, '<pane' ) );

		// Formatos: uno por estilo más el formato por omisión.
		$this->assertMatchesRegularExpression( '/<cellXfs count="' . ( count( Workbook::STYLES ) + 1 ) . '">/', $styles );
		$this->assertStringContainsString( 'formatCode="dd/mm/yyyy"', $styles );
		$this->assertStringContainsString( '<name val="Arial"/>', $styles );
	}
}
