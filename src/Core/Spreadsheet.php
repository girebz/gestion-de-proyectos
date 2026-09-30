<?php
/**
 * Lectura y escritura mínima de archivos XLSX.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe libros XLSX (una o varias hojas, cadenas en línea, números y
 * fechas como texto) y lee la primera hoja de un XLSX, sin dependencias:
 * basta la extensión zip de PHP, presente en casi todo alojamiento.
 */
final class Spreadsheet {

	/**
	 * Indica si el servidor puede leer y escribir XLSX.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( ZipArchive::class );
	}

	/**
	 * Genera un XLSX en memoria.
	 *
	 * @param array<string,array<int,array<int,mixed>>> $sheets Hojas: nombre => filas (la primera fila es la cabecera).
	 * @return string|WP_Error Contenido binario.
	 */
	public static function write( array $sheets ) {
		if ( ! self::available() ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para generar XLSX.', 'gestion-de-proyectos' ) );
		}
		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdp-xlsx' ) : (string) tempnam( get_temp_dir(), 'gdp-xlsx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', __( 'No se pudo crear el archivo XLSX.', 'gestion-de-proyectos' ) );
		}

		$names   = array_keys( $sheets );
		$count   = count( $names );
		$content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		for ( $i = 1; $i <= $count; $i++ ) {
			$content .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$content .= '</Types>';
		$zip->addFromString( '[Content_Types].xml', $content );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		$rels     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( $names as $i => $name ) {
			$n         = $i + 1;
			$workbook .= '<sheet name="' . self::xml( mb_substr( (string) $name, 0, 31 ) ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels     .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', self::sheet_xml( $sheets[ $name ] ) );
		}
		$workbook .= '</sheets></workbook>';
		$rels     .= '<Relationship Id="rId' . ( $count + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$zip->addFromString( 'xl/workbook.xml', $workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels );
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>' );
		$zip->close();

		$binary = (string) file_get_contents( $tmp );
		wp_delete_file( $tmp );

		return $binary;
	}

	/**
	 * XML de una hoja.
	 *
	 * @param array<int,array<int,mixed>> $rows Filas.
	 * @return string
	 */
	private static function sheet_xml( array $rows ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		foreach ( array_values( $rows ) as $r => $row ) {
			$xml .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( (array) $row ) as $c => $value ) {
				$ref = self::column( $c ) . ( $r + 1 );
				if ( null === $value || '' === $value ) {
					continue;
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					$xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
				} elseif ( is_bool( $value ) ) {
					$xml .= '<c r="' . $ref . '" t="b"><v>' . ( $value ? 1 : 0 ) . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '" t="inlineStr"' . ( 0 === $r ? ' s="1"' : '' ) . '><is><t xml:space="preserve">' . self::xml( (string) $value ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}

		return $xml . '</sheetData></worksheet>';
	}

	/**
	 * Lee la primera hoja de un XLSX como filas de cadenas.
	 *
	 * @param string $path Ruta del archivo.
	 * @return array<int,array<int,string>>|WP_Error
	 */
	public static function read( string $path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para leer XLSX.', 'gestion-de-proyectos' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'zip', __( 'El archivo no es un XLSX válido.', 'gestion-de-proyectos' ) );
		}

		$shared = array();
		$ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$doc = self::parse( $ss );
			if ( $doc ) {
				foreach ( $doc->si as $si ) {
					$shared[] = self::rich_text( $si );
				}
			}
		}

		// Primera hoja según el libro.
		$sheet_file = 'xl/worksheets/sheet1.xml';
		$wb         = $zip->getFromName( 'xl/workbook.xml' );
		$rels       = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false !== $wb && false !== $rels ) {
			$wbx = self::parse( $wb );
			$rlx = self::parse( $rels );
			if ( $wbx && $rlx && isset( $wbx->sheets->sheet[0] ) ) {
				$rid = (string) $wbx->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
				foreach ( $rlx->Relationship as $rel ) {
					if ( (string) $rel['Id'] === $rid ) {
						$target     = (string) $rel['Target'];
						$sheet_file = 0 === strpos( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
					}
				}
			}
		}
		$sheet = $zip->getFromName( $sheet_file );
		$zip->close();
		if ( false === $sheet ) {
			return new WP_Error( 'sheet', __( 'No se encontró la hoja de cálculo dentro del archivo.', 'gestion-de-proyectos' ) );
		}
		$doc = self::parse( $sheet );
		if ( ! $doc ) {
			return new WP_Error( 'xml', __( 'No se pudo leer la hoja de cálculo.', 'gestion-de-proyectos' ) );
		}

		$rows = array();
		foreach ( $doc->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$ref   = (string) $c['r'];
				$col   = self::column_index( preg_replace( '/\d+/', '', $ref ) );
				$type  = (string) $c['t'];
				$value = '';
				if ( 's' === $type ) {
					$value = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$value = self::rich_text( $c->is );
				} elseif ( 'b' === $type ) {
					$value = '1' === (string) $c->v ? '1' : '0';
				} elseif ( isset( $c->v ) ) {
					$value = (string) $c->v;
					// Fechas guardadas como número de serie de Excel (estilo de fecha): se convierten si parecen fechas.
					if ( is_numeric( $value ) && (float) $value > 25569 && (float) $value < 80000 && false === strpos( $value, '.' ) ) {
						$value = gmdate( 'Y-m-d', (int) ( ( (float) $value - 25569 ) * 86400 ) );
					}
				}
				$cells[ $col ] = trim( $value );
			}
			if ( empty( $cells ) ) {
				continue;
			}
			$max = max( array_keys( $cells ) );
			$out = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$out[] = $cells[ $i ] ?? '';
			}
			$rows[] = $out;
		}

		return $rows;
	}

	/**
	 * Texto de un elemento con posibles fragmentos enriquecidos.
	 *
	 * @param \SimpleXMLElement|null $node Nodo si o is.
	 * @return string
	 */
	private static function rich_text( $node ): string {
		if ( ! $node ) {
			return '';
		}
		if ( isset( $node->t ) ) {
			return (string) $node->t;
		}
		$text = '';
		foreach ( $node->r as $run ) {
			$text .= (string) $run->t;
		}

		return $text;
	}

	/**
	 * Analiza XML de forma segura.
	 *
	 * @param string $xml XML.
	 * @return \SimpleXMLElement|null
	 */
	public static function parse( string $xml ): ?\SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $doc instanceof \SimpleXMLElement ? $doc : null;
	}

	/**
	 * Letra de columna (0 = A).
	 *
	 * @param int $index Índice.
	 * @return string
	 */
	private static function column( int $index ): string {
		$letters = '';
		$index++;
		while ( $index > 0 ) {
			$mod     = ( $index - 1 ) % 26;
			$letters = chr( 65 + $mod ) . $letters;
			$index   = intdiv( $index - $mod - 1, 26 );
		}

		return $letters;
	}

	/**
	 * Índice de columna (A = 0).
	 *
	 * @param string $letters Letras.
	 * @return int
	 */
	private static function column_index( string $letters ): int {
		$index = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $ch ) {
			$index = $index * 26 + ( ord( $ch ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	/**
	 * Escapa texto para XML.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function xml( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
