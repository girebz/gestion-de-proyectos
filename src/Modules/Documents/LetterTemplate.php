<?php
/**
 * Borradores de cartas en LaTeX y Word.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Documents;

use GDP\Core\Identity;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Planning\WeeklyReport;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Genera, a partir de los datos del documento y del proyecto, un borrador de
 * carta listo para editar: en LaTeX (documento completo, babel español) o en
 * Word (.docx mínimo escrito sin bibliotecas). El texto del cuerpo se toma del
 * campo "cuerpo" del documento; si está vacío se deja un párrafo de guía.
 */
final class LetterTemplate {

	/**
	 * Carta en LaTeX.
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return string
	 */
	public static function latex( array $document ): string {
		$project  = ProjectRepository::find( (int) $document['project_id'] );
		$identity = self::identity();
		$e        = array( self::class, 'escape' );
		$date     = $document['doc_date'] ? WeeklyReport::human_date( (string) $document['doc_date'] ) : WeeklyReport::human_date( current_time( 'Y-m-d' ) );
		$sender   = '' !== (string) $document['sender'] ? (string) $document['sender'] : $identity['name'];
		$body     = trim( wp_strip_all_tags( (string) $document['body'] ) );
		$paras    = '' === $body ? array( __( '[Escriba aquí el cuerpo de la carta.]', 'gestion-de-proyectos' ) ) : preg_split( '/\n\s*\n/', $body );
		$lines    = array();
		$lines[]  = '% Borrador generado por Gestión de Proyectos a partir del documento ' . $e( (string) $document['number'] );
		$lines[]  = '\documentclass[11pt,a4paper]{letter}';
		$lines[]  = '\usepackage[utf8]{inputenc}';
		$lines[]  = '\usepackage[T1]{fontenc}';
		$lines[]  = '\usepackage[spanish,es-noshorthands]{babel}';
		$lines[]  = '\usepackage[margin=2.5cm]{geometry}';
		$lines[]  = '\usepackage[hidelinks]{hyperref}';
		$lines[]  = '\signature{' . $e( $sender ) . '}';
		$lines[]  = '\address{' . $e( $identity['name'] ) . ' \\\\ ' . $e( (string) ( $project['name'] ?? '' ) ) . ( ! empty( $project['code'] ) ? ' \\\\ ' . $e( __( 'Proyecto', 'gestion-de-proyectos' ) . ' ' . $project['code'] ) : '' ) . '}';
		$lines[]  = '\date{' . $e( $date ) . '}';
		$lines[]  = '\begin{document}';
		$lines[]  = '\begin{letter}{' . $e( '' !== (string) $document['recipient'] ? (string) $document['recipient'] : __( '[Destinatario]', 'gestion-de-proyectos' ) ) . '}';
		if ( '' !== (string) $document['number'] ) {
			$lines[] = '\noindent\textbf{' . $e( (string) $document['number'] ) . '}';
			$lines[] = '';
		}
		$lines[] = '\noindent\textbf{' . $e( __( 'Ref.:', 'gestion-de-proyectos' ) ) . '} ' . $e( (string) $document['subject'] );
		$lines[] = '';
		$lines[] = '\opening{' . $e( __( 'De nuestra consideración:', 'gestion-de-proyectos' ) ) . '}';
		$lines[] = '';
		foreach ( $paras as $p ) {
			$lines[] = $e( trim( (string) $p ) );
			$lines[] = '';
		}
		$lines[] = '\closing{' . $e( __( 'Saluda atentamente,', 'gestion-de-proyectos' ) ) . '}';
		$lines[] = '\end{letter}';
		$lines[] = '\end{document}';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Carta en Word (.docx).
	 *
	 * @param array<string,mixed> $document Documento.
	 * @return string|WP_Error Contenido binario.
	 */
	public static function docx( array $document ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para generar Word.', 'gestion-de-proyectos' ) );
		}
		$project  = ProjectRepository::find( (int) $document['project_id'] );
		$identity = self::identity();
		$date     = $document['doc_date'] ? WeeklyReport::human_date( (string) $document['doc_date'] ) : WeeklyReport::human_date( current_time( 'Y-m-d' ) );
		$sender   = '' !== (string) $document['sender'] ? (string) $document['sender'] : $identity['name'];
		$body     = trim( wp_strip_all_tags( (string) $document['body'] ) );
		$paras    = '' === $body ? array( __( '[Escriba aquí el cuerpo de la carta.]', 'gestion-de-proyectos' ) ) : preg_split( '/\n\s*\n/', $body );

		$p  = static fn( string $text, bool $bold = false, string $align = 'left' ): string => '<w:p><w:pPr><w:jc w:val="' . $align . '"/><w:spacing w:after="200"/></w:pPr><w:r>' . ( $bold ? '<w:rPr><w:b/></w:rPr>' : '' ) . '<w:t xml:space="preserve">' . htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</w:t></w:r></w:p>';
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
		$xml .= $p( $identity['name'], true );
		$xml .= $p( (string) ( $project['name'] ?? '' ) . ( ! empty( $project['code'] ) ? ' (' . $project['code'] . ')' : '' ) );
		$xml .= $p( $date, false, 'right' );
		if ( '' !== (string) $document['number'] ) {
			$xml .= $p( (string) $document['number'], true );
		}
		$xml .= $p( __( 'Señor(a)', 'gestion-de-proyectos' ) );
		$xml .= $p( '' !== (string) $document['recipient'] ? (string) $document['recipient'] : __( '[Destinatario]', 'gestion-de-proyectos' ), true );
		$xml .= $p( __( 'Ref.:', 'gestion-de-proyectos' ) . ' ' . (string) $document['subject'], true );
		$xml .= $p( __( 'De nuestra consideración:', 'gestion-de-proyectos' ) );
		foreach ( $paras as $para ) {
			$xml .= $p( trim( (string) $para ), false, 'both' );
		}
		$xml .= $p( __( 'Saluda atentamente,', 'gestion-de-proyectos' ) );
		$xml .= $p( '' );
		$xml .= $p( $sender, true );
		$xml .= '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>';

		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdp-docx' ) : (string) tempnam( get_temp_dir(), 'gdp-docx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', __( 'No se pudo crear el archivo Word.', 'gestion-de-proyectos' ) );
		}
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
		$zip->addFromString( 'word/document.xml', $xml );
		$zip->close();
		$binary = (string) file_get_contents( $tmp );
		wp_delete_file( $tmp );

		return $binary;
	}

	/**
	 * Escapa texto para LaTeX.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function escape( string $text ): string {
		$map = array(
			'\\' => '\textbackslash{}',
			'&'  => '\&',
			'%'  => '\%',
			'$'  => '\$',
			'#'  => '\#',
			'_'  => '\_',
			'{'  => '\{',
			'}'  => '\}',
			'~'  => '\textasciitilde{}',
			'^'  => '\textasciicircum{}',
		);
		$out = '';
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $ch ) {
			$out .= $map[ $ch ] ?? $ch;
		}

		return $out;
	}

	/**
	 * Identidad del sitio.
	 *
	 * @return array{name:string}
	 */
	private static function identity(): array {
		$identity = Identity::get();
		$name     = (string) ( $identity['name'] ?? '' );

		return array( 'name' => '' !== $name ? $name : (string) get_bloginfo( 'name' ) );
	}
}
