<?php
/**
 * Libros XLSX con formato.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Core;

use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe libros XLSX con formato de informe, sin dependencias (basta la
 * extensión zip de PHP): varias hojas con encabezado de color, montos en
 * pesos, fechas y porcentajes como valores numéricos con su formato, anchos
 * de columna, filas y columnas fijas, filtros, celdas combinadas, fórmulas
 * con su valor ya calculado (el programa de hojas de cálculo las recalcula
 * al abrir el libro) y página apaisada ajustada al ancho al imprimir.
 *
 * Una hoja se describe con un arreglo: name (nombre de la pestaña), rows
 * (filas, cada una una lista de celdas), columns (ancho y formato de cada
 * columna para las filas de datos), header (índice de la fila de
 * encabezado, o null), freeze (fija las filas hasta el encabezado),
 * freeze_cols (columnas fijas), filter (filtro sobre el encabezado y los
 * datos), filter_last (índice de la última fila de datos del filtro),
 * merges (rangos combinados, como "A1:F1"), links (vínculos a otras hojas:
 * ref, location y display), gridlines y landscape. Una celda
 * es un valor (texto, número, booleano o null) o un arreglo con v (valor),
 * f (fórmula sin el signo igual) y s (estilo); un texto con forma de fecha
 * (AAAA-MM-DD) en una celda de estilo date se guarda como fecha.
 */
final class Workbook {

	/**
	 * Estilos de celda: clave => posición en la lista de formatos del libro.
	 */
	public const STYLES = array(
		'text'        => 23,
		'header'      => 1,
		'wrap'        => 2,
		'money'       => 3,
		'int'         => 4,
		'pct'         => 5,
		'date'        => 6,
		'title'       => 7,
		'subtitle'    => 8,
		'bold'        => 9,
		'section'     => 10,
		'total'       => 11,
		'total_money' => 12,
		'total_int'   => 13,
		'total_pct'   => 14,
		'ok'          => 15,
		'warn'        => 16,
		'bad'         => 17,
		'muted'       => 18,
		'num'         => 19,
		'bold_money'  => 20,
		'link'        => 21,
		'thousands'   => 22,
		'note'        => 24,
		'code'        => 25,
		'center'      => 26,
	);

	/**
	 * Formato por omisión del libro (sin alineación propia). Las celdas de
	 * texto llevan siempre un formato explícito ('text', alineado arriba),
	 * porque algunos programas no aplican la alineación del formato 0.
	 */
	private const DEFAULT_STYLE = 0;

	/**
	 * Indica si el servidor puede generar XLSX.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( ZipArchive::class );
	}

	/**
	 * Genera el libro en memoria.
	 *
	 * @param array<int,array<string,mixed>> $sheets Hojas.
	 * @param array<string,string>           $props  title, subject y creator del documento.
	 * @return string|WP_Error Contenido binario.
	 */
	public static function write( array $sheets, array $props = array() ) {
		if ( ! self::available() ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para generar XLSX.', 'gestion-de-proyectos' ) );
		}
		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdp-libro' ) : (string) tempnam( sys_get_temp_dir(), 'gdp-libro' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', __( 'No se pudo crear el archivo XLSX.', 'gestion-de-proyectos' ) );
		}

		$sheets = array_values( $sheets );
		$names  = self::sheet_names( $sheets );
		$count  = count( $sheets );

		$types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		for ( $i = 1; $i <= $count; $i++ ) {
			$types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$zip->addFromString( '[Content_Types].xml', $types . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>' );
		$zip->addFromString( 'docProps/core.xml', self::core_xml( $props ) );
		$zip->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::xml( (string) ( $props['creator'] ?? 'Gestión de Proyectos' ) ) . '</Application></Properties>' );

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
		$rels     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		$defined  = '';
		foreach ( $sheets as $i => $sheet ) {
			$n         = $i + 1;
			$workbook .= '<sheet name="' . self::xml( $names[ $i ] ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels     .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$built     = self::sheet_xml( $sheet, 0 === $i );
			$zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', $built['xml'] );
			$quoted = self::quote_sheet( $names[ $i ] );
			if ( '' !== $built['filter'] ) {
				$defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::xml( $quoted . '!' . self::absolute( $built['filter'] ) ) . '</definedName>';
			}
			if ( null !== $built['header'] ) {
				$row      = $built['header'] + 1;
				$defined .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . self::xml( $quoted . '!$' . $row . ':$' . $row ) . '</definedName>';
			}
		}
		$workbook .= '</sheets>' . ( '' !== $defined ? '<definedNames>' . $defined . '</definedNames>' : '' ) . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
		$rels     .= '<Relationship Id="rId' . ( $count + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$zip->addFromString( 'xl/workbook.xml', $workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels );
		$zip->addFromString( 'xl/styles.xml', self::styles_xml() );
		$zip->close();

		$binary = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo temporal propio.
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $tmp );
		} else {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fuera de WordPress (pruebas).
		}

		return $binary;
	}

	/**
	 * Número de serie de una fecha AAAA-MM-DD (días desde el 30/12/1899).
	 *
	 * @param string $date Fecha.
	 * @return int|null
	 */
	public static function date_serial( string $date ): ?int {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		return intdiv( (int) gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ), 86400 ) + 25569;
	}

	/**
	 * Letra de una columna (0 = A).
	 *
	 * @param int $index Índice.
	 * @return string
	 */
	public static function column( int $index ): string {
		$letters = '';
		++$index;
		while ( $index > 0 ) {
			$mod     = ( $index - 1 ) % 26;
			$letters = chr( 65 + $mod ) . $letters;
			$index   = intdiv( $index - $mod - 1, 26 );
		}

		return $letters;
	}

	/**
	 * Referencia de una celda (fila y columna desde 0).
	 *
	 * @param int $row Fila.
	 * @param int $col Columna.
	 * @return string
	 */
	public static function ref( int $row, int $col ): string {
		return self::column( $col ) . ( $row + 1 );
	}

	/**
	 * Nombre de hoja entre comillas simples, para fórmulas que la citan.
	 *
	 * @param string $name Nombre.
	 * @return string
	 */
	public static function quote_sheet( string $name ): string {
		return "'" . str_replace( "'", "''", $name ) . "'";
	}

	/**
	 * Nombres de pestaña válidos y únicos (31 caracteres, sin []:*?/\).
	 *
	 * @param array<int,array<string,mixed>> $sheets Hojas.
	 * @return string[]
	 */
	public static function sheet_names( array $sheets ): array {
		$out  = array();
		$seen = array();
		foreach ( array_values( $sheets ) as $i => $sheet ) {
			$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[\[\]:*?\/\\\\]+/', ' ', (string) ( $sheet['name'] ?? '' ) ) ), " '" );
			$name = '' !== $name ? mb_substr( $name, 0, 31 ) : sprintf( 'Hoja %d', $i + 1 );
			$base = $name;
			$k    = 2;
			while ( isset( $seen[ mb_strtolower( $name ) ] ) ) {
				$suffix = ' (' . $k . ')';
				$name   = mb_substr( $base, 0, 31 - mb_strlen( $suffix ) ) . $suffix;
				++$k;
			}
			$seen[ mb_strtolower( $name ) ] = true;
			$out[]                          = $name;
		}

		return $out;
	}

	/**
	 * XML de una hoja.
	 *
	 * @param array<string,mixed> $sheet Hoja.
	 * @param bool                $first Primera hoja (seleccionada).
	 * @return array{xml:string,filter:string,header:int|null}
	 */
	private static function sheet_xml( array $sheet, bool $first ): array {
		$rows    = array_values( (array) ( $sheet['rows'] ?? array() ) );
		$columns = array_values( (array) ( $sheet['columns'] ?? array() ) );
		$header  = isset( $sheet['header'] ) && null !== $sheet['header'] ? (int) $sheet['header'] : null;
		$max_col = count( $columns );
		foreach ( $rows as $row ) {
			$max_col = max( $max_col, count( (array) $row ) );
		}
		$max_col  = max( 1, $max_col );
		$last_row = max( 1, count( $rows ) );

		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		$xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
		$xml .= '<dimension ref="A1:' . self::ref( $last_row - 1, $max_col - 1 ) . '"/>';

		// Vista: filas fijas hasta el encabezado y columnas fijas.
		$freeze_rows = null !== $header && ! empty( $sheet['freeze'] ) ? $header + 1 : 0;
		$freeze_cols = max( 0, (int) ( $sheet['freeze_cols'] ?? 0 ) );
		$view        = '<sheetView workbookViewId="0"' . ( $first ? ' tabSelected="1"' : '' ) . ( isset( $sheet['gridlines'] ) && ! $sheet['gridlines'] ? ' showGridLines="0"' : '' ) . '>';
		if ( $freeze_rows > 0 || $freeze_cols > 0 ) {
			$pane  = $freeze_rows > 0 && $freeze_cols > 0 ? 'bottomRight' : ( $freeze_rows > 0 ? 'bottomLeft' : 'topRight' );
			$cell  = self::ref( $freeze_rows, $freeze_cols );
			$view .= '<pane' . ( $freeze_cols > 0 ? ' xSplit="' . $freeze_cols . '"' : '' ) . ( $freeze_rows > 0 ? ' ySplit="' . $freeze_rows . '"' : '' ) . ' topLeftCell="' . $cell . '" activePane="' . $pane . '" state="frozen"/><selection pane="' . $pane . '" activeCell="' . $cell . '" sqref="' . $cell . '"/>';
		}
		$xml .= '<sheetViews>' . $view . '</sheetView></sheetViews>';
		$xml .= '<sheetFormatPr defaultRowHeight="12.75"/>';

		if ( $columns ) {
			$xml .= '<cols>';
			foreach ( $columns as $c => $col ) {
				$width = (float) ( $col['width'] ?? 12 );
				$xml  .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . self::number( $width ) . '" customWidth="1"/>';
			}
			$xml .= '</cols>';
		}

		$xml .= '<sheetData>';
		foreach ( $rows as $r => $row ) {
			$cells = '';
			foreach ( array_values( (array) $row ) as $c => $cell ) {
				$cells .= self::cell_xml( $cell, $r, $c, $header, (string) ( $columns[ $c ]['format'] ?? '' ) );
			}
			$xml .= '<row r="' . ( $r + 1 ) . '">' . $cells . '</row>';
		}
		$xml .= '</sheetData>';

		$filter = '';
		if ( null !== $header && ! empty( $sheet['filter'] ) ) {
			$last   = isset( $sheet['filter_last'] ) ? max( $header, (int) $sheet['filter_last'] ) : $last_row - 1;
			$filter = self::ref( $header, 0 ) . ':' . self::ref( $last, $max_col - 1 );
			$xml   .= '<autoFilter ref="' . $filter . '"/>';
		}
		$merges = array_values( array_filter( array_map( 'strval', (array) ( $sheet['merges'] ?? array() ) ) ) );
		if ( $merges ) {
			$xml .= '<mergeCells count="' . count( $merges ) . '">';
			foreach ( $merges as $m ) {
				$xml .= '<mergeCell ref="' . self::xml( $m ) . '"/>';
			}
			$xml .= '</mergeCells>';
		}
		// Vínculos a otras hojas del mismo libro (no necesitan relaciones).
		$links = array_values( (array) ( $sheet['links'] ?? array() ) );
		if ( $links ) {
			$xml .= '<hyperlinks>';
			foreach ( $links as $l ) {
				$xml .= '<hyperlink ref="' . self::xml( (string) $l['ref'] ) . '" location="' . self::xml( (string) $l['location'] ) . '" display="' . self::xml( (string) ( $l['display'] ?? '' ) ) . '"/>';
			}
			$xml .= '</hyperlinks>';
		}
		$landscape = ! isset( $sheet['landscape'] ) || ! empty( $sheet['landscape'] );
		$xml      .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
		$xml      .= '<pageSetup orientation="' . ( $landscape ? 'landscape' : 'portrait' ) . '" fitToWidth="1" fitToHeight="0"/>';
		$xml      .= '</worksheet>';

		return array(
			'xml'    => $xml,
			'filter' => $filter,
			'header' => null !== $header && $freeze_rows > 0 ? $header : null,
		);
	}

	/**
	 * XML de una celda.
	 *
	 * @param mixed    $cell   Valor o arreglo con v, f y s.
	 * @param int      $row    Fila.
	 * @param int      $col    Columna.
	 * @param int|null $header Fila de encabezado.
	 * @param string   $format Formato de la columna para las filas de datos.
	 * @return string
	 */
	private static function cell_xml( $cell, int $row, int $col, ?int $header, string $format ): string {
		$value   = is_array( $cell ) ? ( $cell['v'] ?? null ) : $cell;
		$formula = is_array( $cell ) && isset( $cell['f'] ) && '' !== (string) $cell['f'] ? (string) $cell['f'] : null;
		$style   = is_array( $cell ) && isset( $cell['s'] ) ? (string) $cell['s'] : '';
		if ( '' === $style ) {
			if ( null !== $header && $row === $header ) {
				$style = 'header';
			} elseif ( '' !== $format && ( null === $header || $row > $header ) ) {
				$style = $format;
			}
		}
		$style = isset( self::STYLES[ $style ] ) ? $style : 'text';
		$index = self::STYLES[ $style ];
		$ref   = self::ref( $row, $col );
		$s     = self::DEFAULT_STYLE !== $index ? ' s="' . $index . '"' : '';

		if ( null !== $formula ) {
			$cached = is_int( $value ) || is_float( $value ) ? '<v>' . self::number( (float) $value ) . '</v>' : '';

			return '<c r="' . $ref . '"' . $s . '><f>' . self::xml( $formula ) . '</f>' . $cached . '</c>';
		}
		if ( null === $value || '' === $value ) {
			// Una celda vacía se escribe solo si su formato se ve (relleno, borde).
			return 'text' !== $style ? '<c r="' . $ref . '"' . $s . '/>' : '';
		}
		if ( is_bool( $value ) ) {
			return '<c r="' . $ref . '"' . $s . ' t="b"><v>' . ( $value ? 1 : 0 ) . '</v></c>';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return '<c r="' . $ref . '"' . $s . '><v>' . self::number( (float) $value ) . '</v></c>';
		}
		$text = (string) $value;
		if ( 'date' === $style ) {
			$serial = self::date_serial( $text );
			if ( null !== $serial ) {
				return '<c r="' . $ref . '"' . $s . '><v>' . $serial . '</v></c>';
			}
		}

		return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::xml( self::clean_text( $text ) ) . '</t></is></c>';
	}

	/**
	 * Hoja de estilos: fuentes, rellenos, bordes y formatos numéricos.
	 *
	 * @return string
	 */
	private static function styles_xml(): string {
		$q       = '&quot;';
		$num_fmt = '<numFmts count="4">'
			. '<numFmt numFmtId="164" formatCode="' . $q . '$' . $q . '\ #,##0;\(' . $q . '$' . $q . '\ #,##0\);' . $q . '-' . $q . '"/>'
			. '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
			. '<numFmt numFmtId="166" formatCode="0.0%;\(0.0%\);' . $q . '-' . $q . '"/>'
			. '<numFmt numFmtId="167" formatCode="#,##0;\(#,##0\);' . $q . '-' . $q . '"/>'
			. '</numFmts>';
		$font    = static fn( string $extra, string $size = '10', string $color = '' ): string => '<font>' . $extra . '<sz val="' . $size . '"/>' . ( '' !== $color ? '<color rgb="FF' . $color . '"/>' : '' ) . '<name val="Arial"/><family val="2"/></font>';
		$fonts   = '<fonts count="11">'
			. $font( '' )
			. $font( '<b/>' )
			. $font( '<b/>', '14', '1F4E5F' )
			. $font( '<i/>', '10', '595959' )
			. $font( '<b/>', '11', '1F4E5F' )
			. $font( '', '9', '595959' )
			. $font( '<b/>', '10', 'FFFFFF' )
			. $font( '', '10', '006100' )
			. $font( '', '10', '9C5700' )
			. $font( '', '10', '9C0006' )
			. $font( '<u/>', '10', '1F4E5F' )
			. '</fonts>';
		$fill    = static fn( string $rgb ): string => '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
		$fills   = '<fills count="7"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. $fill( '1F4E5F' ) . $fill( 'EEF2F4' ) . $fill( 'C6EFCE' ) . $fill( 'FFEB9C' ) . $fill( 'FFC7CE' )
			. '</fills>';
		$borders = '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top style="thin"><color rgb="FF808080"/></top><bottom/><diagonal/></border></borders>';
		$top     = '<alignment vertical="top"/>';
		$wrap    = '<alignment vertical="top" wrapText="1"/>';
		$xf      = static fn( int $num, int $font, int $fill, int $border, string $align = '' ): string => '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"' . ( $num > 0 ? ' applyNumberFormat="1"' : '' ) . ( $font > 0 ? ' applyFont="1"' : '' ) . ( $fill > 0 ? ' applyFill="1"' : '' ) . ( $border > 0 ? ' applyBorder="1"' : '' ) . ( '' !== $align ? ' applyAlignment="1">' . $align . '</xf>' : '/>' );
		$xfs     = array(
			$xf( 0, 0, 0, 0 ),
			$xf( 0, 6, 2, 0, '<alignment vertical="center" wrapText="1"/>' ),
			$xf( 0, 0, 0, 0, $wrap ),
			$xf( 164, 0, 0, 0, $top ),
			$xf( 167, 0, 0, 0, $top ),
			$xf( 166, 0, 0, 0, $top ),
			$xf( 165, 0, 0, 0, '<alignment horizontal="center" vertical="top"/>' ),
			$xf( 0, 2, 0, 0 ),
			$xf( 0, 3, 0, 0 ),
			$xf( 0, 1, 0, 0, $top ),
			$xf( 0, 4, 0, 0 ),
			$xf( 0, 1, 3, 1, $top ),
			$xf( 164, 1, 3, 1, $top ),
			$xf( 167, 1, 3, 1, $top ),
			$xf( 166, 1, 3, 1, $top ),
			$xf( 0, 7, 4, 0, $wrap ),
			$xf( 0, 8, 5, 0, $wrap ),
			$xf( 0, 9, 6, 0, $wrap ),
			$xf( 0, 5, 0, 0, $wrap ),
			$xf( 1, 0, 0, 0, '<alignment horizontal="center" vertical="top"/>' ),
			$xf( 164, 1, 0, 0, $top ),
			$xf( 0, 10, 0, 0, $top ),
			$xf( 3, 0, 0, 0, '<alignment horizontal="left" vertical="top"/>' ),
			$xf( 0, 0, 0, 0, $top ),
			$xf( 0, 5, 0, 0, $top ),
			$xf( 1, 0, 0, 0, '<alignment horizontal="left" vertical="top"/>' ),
			$xf( 0, 0, 0, 0, '<alignment horizontal="center" vertical="top"/>' ),
		);

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. $num_fmt . $fonts . $fills . $borders
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count( $xfs ) . '">' . implode( '', $xfs ) . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
	}

	/**
	 * Propiedades del documento.
	 *
	 * @param array<string,string> $props Propiedades.
	 * @return string
	 */
	private static function core_xml( array $props ): string {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:title>' . self::xml( (string) ( $props['title'] ?? '' ) ) . '</dc:title>'
			. '<dc:subject>' . self::xml( (string) ( $props['subject'] ?? '' ) ) . '</dc:subject>'
			. '<dc:creator>' . self::xml( (string) ( $props['creator'] ?? 'Gestión de Proyectos' ) ) . '</dc:creator>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
			. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
			. '</cp:coreProperties>';
	}

	/**
	 * Referencia absoluta de un rango ("A4:F20" pasa a "$A$4:$F$20").
	 *
	 * @param string $range Rango.
	 * @return string
	 */
	private static function absolute( string $range ): string {
		return (string) preg_replace( '/([A-Z]+)(\d+)/', '\$$1\$$2', $range );
	}

	/**
	 * Número con punto decimal y sin notación científica.
	 *
	 * @param float $value Valor.
	 * @return string
	 */
	private static function number( float $value ): string {
		if ( abs( $value ) < 1e-9 ) {
			return '0';
		}
		$text = number_format( $value, 6, '.', '' );

		return rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * Quita los caracteres de control que XML no admite.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function clean_text( string $text ): string {
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text );
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
