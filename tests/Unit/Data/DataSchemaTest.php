<?php
/**
 * Pruebas del conocimiento del esquema usado por exportación e importación.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Tests\Unit\Data;

use GDP\Modules\Data\DataSchema;
use GDP\Modules\Data\Exporter;
use GDP\Modules\Data\Importer;
use PHPUnit\Framework\TestCase;

/**
 * Las definiciones SQL se analizan para obtener columnas y tipos; las
 * referencias, claves naturales y columnas polimórficas declaradas deben
 * apuntar a tablas y columnas que existen, para que una tabla nueva no
 * rompa la importación en silencio.
 */
final class DataSchemaTest extends TestCase {

	/**
	 * Base de datos simulada para Schema::table() y el collate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Prefijo de tablas.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Collate.
			 *
			 * @return string
			 */
			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
			}
		};
	}

	public function test_columns_are_parsed_from_definitions(): void {
		$columns = DataSchema::columns();
		$this->assertSame( 'varchar(64)', $columns['projects']['code'] );
		$this->assertSame( 'bigint(20) unsigned', $columns['activities']['parent_id'] );
		$this->assertSame( 'decimal(18,4)', $columns['purchases']['amount_net'] );
		$this->assertSame( 'date', $columns['uf_rates']['rate_date'] );
		$this->assertFalse( DataSchema::has_id( 'uf_rates' ) );
		$this->assertTrue( DataSchema::has_id( 'agreements' ) );
		$this->assertTrue( DataSchema::nullable( 'projects', 'description' ) );
		$this->assertFalse( DataSchema::nullable( 'projects', 'code' ) );
		foreach ( DataSchema::order() as $table ) {
			$this->assertNotEmpty( $columns[ $table ] ?? array(), $table );
		}
	}

	public function test_logical_types(): void {
		$this->assertSame( 'integer', DataSchema::logical_type( 'bigint(20) unsigned' ) );
		$this->assertSame( 'boolean', DataSchema::logical_type( 'tinyint(1)' ) );
		$this->assertSame( 'integer', DataSchema::logical_type( 'tinyint(3) unsigned' ) );
		$this->assertSame( 'decimal', DataSchema::logical_type( 'decimal(18,2)' ) );
		$this->assertSame( 'date', DataSchema::logical_type( 'date' ) );
		$this->assertSame( 'datetime', DataSchema::logical_type( 'datetime' ) );
		$this->assertSame( 'time', DataSchema::logical_type( 'time' ) );
		$this->assertSame( 'text', DataSchema::logical_type( 'longtext' ) );
	}

	public function test_references_keys_and_polymorphic_columns_exist(): void {
		$columns = DataSchema::columns();
		foreach ( DataSchema::refs() as $table => $refs ) {
			$this->assertArrayHasKey( $table, $columns, $table );
			foreach ( $refs as $col => $target ) {
				$this->assertArrayHasKey( $col, $columns[ $table ], "$table.$col" );
				$this->assertArrayHasKey( $target, $columns, "$table.$col → $target" );
				$this->assertArrayHasKey( 'id', $columns[ $target ], $target );
			}
		}
		foreach ( DataSchema::keys() as $table => $keys ) {
			foreach ( $keys as $col ) {
				$this->assertArrayHasKey( $col, $columns[ $table ], "$table.$col" );
			}
		}
		foreach ( DataSchema::polymorphic() as $table => $pairs ) {
			foreach ( $pairs as $pair ) {
				$this->assertArrayHasKey( $pair[0], $columns[ $table ], "$table.{$pair[0]}" );
				$this->assertArrayHasKey( $pair[1], $columns[ $table ], "$table.{$pair[1]}" );
			}
		}
		foreach ( DataSchema::entity_tables() as $type => $table ) {
			$this->assertArrayHasKey( $table, $columns, $type );
		}
		foreach ( DataSchema::parents() as $table => $pair ) {
			$this->assertArrayHasKey( $pair[0], $columns[ $table ], "$table.{$pair[0]}" );
			$this->assertArrayHasKey( $pair[1], $columns, $pair[1] );
			$this->assertSame( $pair[1], DataSchema::refs()[ $table ][ $pair[0] ] ?? null, "$table padre" );
		}
		foreach ( DataSchema::json_columns() as $table => $cols ) {
			foreach ( $cols as $col ) {
				$this->assertArrayHasKey( $col, $columns[ $table ], "$table.$col" );
			}
		}
	}

	public function test_load_order_puts_referenced_tables_first_except_declared_forward_references(): void {
		$order    = DataSchema::order();
		$position = array_flip( $order );
		$forward  = array( 'purchases.chosen_quote_id' );
		foreach ( DataSchema::refs() as $table => $refs ) {
			foreach ( $refs as $col => $target ) {
				if ( $target === $table || in_array( "$table.$col", $forward, true ) ) {
					continue;
				}
				$this->assertTrue( $position[ $target ] < $position[ $table ], "$table.$col → $target debe cargarse antes" );
			}
		}
	}

	public function test_normalize_values_for_comparison(): void {
		$this->assertSame( '', Importer::normalize( null, 'text' ) );
		$this->assertSame( '7', Importer::normalize( '7', 'integer' ) );
		$this->assertSame( '1', Importer::normalize( true, 'boolean' ) );
		$this->assertSame( '0', Importer::normalize( '0', 'boolean' ) );
		$this->assertSame( '3026191', Importer::normalize( '3026191.00', 'decimal' ) );
		$this->assertSame( '4.55', Importer::normalize( 4.55, 'decimal' ) );
		$this->assertSame( '10:00', Importer::normalize( '10:00:00', 'time' ) );
		$this->assertSame( '{"a":1}', Importer::normalize( array( 'a' => 1 ), 'json' ) );
		$this->assertSame( '{"a":1}', Importer::normalize( '{"a": 1}', 'json' ) );
	}

	public function test_csv_and_labels(): void {
		$csv = Exporter::csv( array( array( 'a', 'b' ), array( 1, 'x;y' ), array( null, true ) ) );
		$this->assertSame( "\xEF\xBB\xBF" . "a;b\n1;\"x;y\"\n;1\n", $csv );
		$this->assertSame( '1.2 Muestreo', Exporter::label( 'activities', array( 'code' => '1.2', 'name' => 'Muestreo' ) ) );
		$this->assertSame( 'CARTA-001/2026 Asunto', Exporter::label( 'documents', array( 'doc_number' => 'CARTA-001/2026', 'subject' => 'Asunto' ) ) );
		$this->assertSame( 'document 3 → activity 8', Exporter::label( 'links', array( 'from_type' => 'document', 'from_id' => 3, 'to_type' => 'activity', 'to_id' => 8 ) ) );
		$this->assertSame( 'budget_line/rrhh', Exporter::label( 'catalog_items', array( 'catalog' => 'budget_line', 'slug' => 'rrhh' ) ) );
	}
}
