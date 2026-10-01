<?php
/**
 * Acta de reunión en LaTeX y Word.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Meetings;

use GDP\Core\Identity;
use GDP\Domain\Projects\ProjectRepository;
use GDP\Modules\Documents\LetterTemplate;
use GDP\Modules\Planning\WeeklyReport;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Acta con datos de la reunión, asistentes, agenda, resumen, acuerdos
 * tomados (responsable y plazo) y acuerdos anteriores revisados.
 */
final class MinutesTemplate {

	/**
	 * Acta en LaTeX.
	 *
	 * @param array<string,mixed> $meeting Reunión.
	 * @return string
	 */
	public static function latex( array $meeting ): string {
		$project    = ProjectRepository::find( (int) $meeting['project_id'] );
		$identity   = Identity::get();
		$e          = array( LetterTemplate::class, 'escape' );
		$agreements = AgreementRepository::for_meeting( (int) $meeting['id'] );
		$reviewed   = self::reviewed( (int) $meeting['id'] );
		$labels     = AgreementRepository::status_labels();
		$l          = array();
		$l[]        = '% Acta generada por Gestión de Proyectos: ' . $e( (string) $meeting['code'] );
		$l[]        = '\documentclass[11pt,a4paper]{article}';
		$l[]        = '\usepackage[utf8]{inputenc}';
		$l[]        = '\usepackage[T1]{fontenc}';
		$l[]        = '\usepackage[spanish,es-noshorthands]{babel}';
		$l[]        = '\usepackage[margin=2.5cm]{geometry}';
		$l[]        = '\usepackage{longtable,booktabs,array}';
		$l[]        = '\usepackage[hidelinks]{hyperref}';
		$l[]        = '\setlength{\parskip}{4pt}';
		$l[]        = '\begin{document}';
		$l[]        = '\begin{center}';
		$l[]        = '{\large\bfseries ' . $e( (string) ( $identity['name'] ?? get_bloginfo( 'name' ) ) ) . '}\\\\[2pt]';
		$l[]        = $e( (string) ( $project['name'] ?? '' ) ) . '\\\\[8pt]';
		$l[]        = '{\Large\bfseries ' . $e( __( 'Acta de reunión', 'gestion-de-proyectos' ) . ' ' . $meeting['code'] ) . '}\\\\[2pt]';
		$l[]        = '{\large ' . $e( (string) $meeting['title'] ) . '}';
		$l[]        = '\end{center}';
		$l[]        = '\begin{tabular}{@{}p{3.5cm}p{11cm}@{}}';
		$l[]        = '\textbf{' . $e( __( 'Fecha', 'gestion-de-proyectos' ) ) . '} & ' . $e( WeeklyReport::human_date( (string) $meeting['meeting_date'] ) . ( $meeting['start_time'] ? ', ' . $meeting['start_time'] . ( $meeting['end_time'] ? ' a ' . $meeting['end_time'] : '' ) : '' ) ) . '\\\\';
		if ( '' !== (string) $meeting['location'] ) {
			$l[] = '\textbf{' . $e( __( 'Lugar', 'gestion-de-proyectos' ) ) . '} & ' . $e( (string) $meeting['location'] ) . '\\\\';
		}
		$l[] = '\textbf{' . $e( __( 'Tipo', 'gestion-de-proyectos' ) ) . '} & ' . $e( MeetingRepository::kind_labels()[ $meeting['kind'] ] ?? $meeting['kind'] ) . '\\\\';
		$l[] = '\textbf{' . $e( __( 'Asistentes', 'gestion-de-proyectos' ) ) . '} & ' . $e( implode( '; ', array_map( static fn( array $a ): string => $a['name'] . ( '' !== $a['organization'] ? ' (' . $a['organization'] . ')' : '' ) . ( $a['attended'] ? '' : ' [' . __( 'ausente', 'gestion-de-proyectos' ) . ']' ), $meeting['attendees'] ) ) ) . '\\\\';
		$l[] = '\end{tabular}';
		foreach ( array( 'agenda' => __( 'Temas tratados', 'gestion-de-proyectos' ), 'summary' => __( 'Resumen', 'gestion-de-proyectos' ) ) as $field => $title ) {
			$text = trim( wp_strip_all_tags( (string) $meeting[ $field ] ) );
			if ( '' === $text ) {
				continue;
			}
			$l[] = '\section*{' . $e( $title ) . '}';
			foreach ( preg_split( '/\n\s*\n/', $text ) as $para ) {
				$l[] = $e( trim( (string) $para ) );
				$l[] = '';
			}
		}
		$l[] = '\section*{' . $e( __( 'Acuerdos', 'gestion-de-proyectos' ) ) . '}';
		if ( empty( $agreements ) ) {
			$l[] = $e( __( 'Sin acuerdos registrados.', 'gestion-de-proyectos' ) );
		} else {
			$l[] = '\begin{longtable}{@{}p{1.6cm}p{7.6cm}p{3.2cm}p{2.2cm}@{}}';
			$l[] = '\toprule';
			$l[] = '\textbf{N.º} & \textbf{' . $e( __( 'Acuerdo', 'gestion-de-proyectos' ) ) . '} & \textbf{' . $e( __( 'Responsable', 'gestion-de-proyectos' ) ) . '} & \textbf{' . $e( __( 'Plazo', 'gestion-de-proyectos' ) ) . '}\\\\';
			$l[] = '\midrule';
			$l[] = '\endhead';
			foreach ( $agreements as $a ) {
				$l[] = $e( (string) $a['code'] ) . ' & ' . $e( (string) $a['description'] ) . ' & ' . $e( (string) $a['owner_name'] ) . ' & ' . $e( $a['due_date'] ? WeeklyReport::human_date( $a['due_date'] ) : '' ) . '\\\\';
			}
			$l[] = '\bottomrule';
			$l[] = '\end{longtable}';
		}
		if ( ! empty( $reviewed ) ) {
			$l[] = '\section*{' . $e( __( 'Seguimiento de acuerdos anteriores', 'gestion-de-proyectos' ) ) . '}';
			$l[] = '\begin{longtable}{@{}p{1.8cm}p{6.6cm}p{2.4cm}p{3.8cm}@{}}';
			$l[] = '\toprule';
			$l[] = '\textbf{N.º} & \textbf{' . $e( __( 'Acuerdo', 'gestion-de-proyectos' ) ) . '} & \textbf{' . $e( __( 'Estado', 'gestion-de-proyectos' ) ) . '} & \textbf{' . $e( __( 'Nota', 'gestion-de-proyectos' ) ) . '}\\\\';
			$l[] = '\midrule';
			$l[] = '\endhead';
			foreach ( $reviewed as $r ) {
				$l[] = $e( (string) $r['code'] ) . ' & ' . $e( (string) $r['description'] ) . ' & ' . $e( $labels[ $r['review']['to'] ] ?? $r['review']['to'] ) . ' & ' . $e( (string) $r['review']['note'] ) . '\\\\';
			}
			$l[] = '\bottomrule';
			$l[] = '\end{longtable}';
		}
		$l[] = '\end{document}';

		return implode( "\n", $l ) . "\n";
	}

	/**
	 * Acta en Word (.docx).
	 *
	 * @param array<string,mixed> $meeting Reunión.
	 * @return string|WP_Error
	 */
	public static function docx( array $meeting ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'no_zip', __( 'El servidor no tiene la extensión zip de PHP, necesaria para generar Word.', 'gestion-de-proyectos' ) );
		}
		$project    = ProjectRepository::find( (int) $meeting['project_id'] );
		$identity   = Identity::get();
		$agreements = AgreementRepository::for_meeting( (int) $meeting['id'] );
		$reviewed   = self::reviewed( (int) $meeting['id'] );
		$labels     = AgreementRepository::status_labels();
		$x          = static fn( string $t ): string => htmlspecialchars( $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$p          = static fn( string $text, bool $bold = false, string $align = 'left', int $size = 0 ): string => '<w:p><w:pPr><w:jc w:val="' . $align . '"/><w:spacing w:after="120"/></w:pPr><w:r><w:rPr>' . ( $bold ? '<w:b/>' : '' ) . ( $size ? '<w:sz w:val="' . $size . '"/>' : '' ) . '</w:rPr><w:t xml:space="preserve">' . $x( $text ) . '</w:t></w:r></w:p>';
		$cell       = static fn( string $text, int $width, bool $bold = false ): string => '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr><w:p><w:r><w:rPr>' . ( $bold ? '<w:b/>' : '' ) . '</w:rPr><w:t xml:space="preserve">' . $x( $text ) . '</w:t></w:r></w:p></w:tc>';
		$table      = static function ( array $rows, array $widths ) use ( $cell ): string {
			// Anchos en veinteavos de punto (9072 = ancho útil de A4 con márgenes de 2,5 cm).
			$xml = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="' . array_sum( $widths ) . '" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:insideV w:val="single" w:sz="4" w:space="0" w:color="999999"/></w:tblBorders></w:tblPr><w:tblGrid>';
			foreach ( $widths as $w ) {
				$xml .= '<w:gridCol w:w="' . $w . '"/>';
			}
			$xml .= '</w:tblGrid>';
			foreach ( $rows as $i => $row ) {
				$xml .= '<w:tr>';
				foreach ( array_values( $row ) as $j => $c ) {
					$xml .= $cell( (string) $c, $widths[ $j ] ?? 2000, 0 === $i );
				}
				$xml .= '</w:tr>';
			}
			return $xml . '</w:tbl>' . '<w:p/>';
		};

		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
		$xml .= $p( (string) ( $identity['name'] ?? get_bloginfo( 'name' ) ), true, 'center' );
		$xml .= $p( (string) ( $project['name'] ?? '' ), false, 'center' );
		$xml .= $p( __( 'Acta de reunión', 'gestion-de-proyectos' ) . ' ' . $meeting['code'], true, 'center', 32 );
		$xml .= $p( (string) $meeting['title'], false, 'center', 28 );
		$xml .= $p( __( 'Fecha', 'gestion-de-proyectos' ) . ': ' . WeeklyReport::human_date( (string) $meeting['meeting_date'] ) . ( $meeting['start_time'] ? ', ' . $meeting['start_time'] . ( $meeting['end_time'] ? ' a ' . $meeting['end_time'] : '' ) : '' ) );
		if ( '' !== (string) $meeting['location'] ) {
			$xml .= $p( __( 'Lugar', 'gestion-de-proyectos' ) . ': ' . $meeting['location'] );
		}
		$xml .= $p( __( 'Asistentes', 'gestion-de-proyectos' ) . ': ' . implode( '; ', array_map( static fn( array $a ): string => $a['name'] . ( '' !== $a['organization'] ? ' (' . $a['organization'] . ')' : '' ), $meeting['attendees'] ) ) );
		foreach ( array( 'agenda' => __( 'Temas tratados', 'gestion-de-proyectos' ), 'summary' => __( 'Resumen', 'gestion-de-proyectos' ) ) as $field => $title ) {
			$text = trim( wp_strip_all_tags( (string) $meeting[ $field ] ) );
			if ( '' === $text ) {
				continue;
			}
			$xml .= $p( $title, true, 'left', 26 );
			foreach ( preg_split( '/\n\s*\n/', $text ) as $para ) {
				$xml .= $p( trim( (string) $para ), false, 'both' );
			}
		}
		$xml .= $p( __( 'Acuerdos', 'gestion-de-proyectos' ), true, 'left', 26 );
		if ( empty( $agreements ) ) {
			$xml .= $p( __( 'Sin acuerdos registrados.', 'gestion-de-proyectos' ) );
		} else {
			$rows = array( array( 'N.º', __( 'Acuerdo', 'gestion-de-proyectos' ), __( 'Responsable', 'gestion-de-proyectos' ), __( 'Plazo', 'gestion-de-proyectos' ) ) );
			foreach ( $agreements as $a ) {
				$rows[] = array( $a['code'], $a['description'], $a['owner_name'], $a['due_date'] ? WeeklyReport::human_date( $a['due_date'] ) : '' );
			}
			$xml .= $table( $rows, array( 1500, 4372, 1900, 1300 ) );
		}
		if ( ! empty( $reviewed ) ) {
			$xml .= $p( __( 'Seguimiento de acuerdos anteriores', 'gestion-de-proyectos' ), true, 'left', 26 );
			$rows = array( array( 'N.º', __( 'Acuerdo', 'gestion-de-proyectos' ), __( 'Estado', 'gestion-de-proyectos' ), __( 'Nota', 'gestion-de-proyectos' ) ) );
			foreach ( $reviewed as $r ) {
				$rows[] = array( $r['code'], $r['description'], $labels[ $r['review']['to'] ] ?? $r['review']['to'], $r['review']['note'] );
			}
			$xml .= $table( $rows, array( 1500, 3772, 1400, 2400 ) );
		}
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
	 * Acuerdos anteriores revisados en la reunión, con la revisión correspondiente.
	 *
	 * @param int $meeting_id Reunión.
	 * @return array<int,array<string,mixed>>
	 */
	public static function reviewed( int $meeting_id ): array {
		$meeting = MeetingRepository::find( $meeting_id );
		if ( ! $meeting ) {
			return array();
		}
		$out = array();
		foreach ( AgreementRepository::for_project( (int) $meeting['project_id'], array( 'limit' => 1000 ) ) as $a ) {
			if ( $a['meeting_id'] === $meeting_id ) {
				continue;
			}
			foreach ( array_reverse( $a['follow_up'] ) as $r ) {
				if ( (int) ( $r['meeting_id'] ?? 0 ) === $meeting_id ) {
					$a['review'] = $r;
					$out[]       = $a;
					break;
				}
			}
		}

		return $out;
	}
}
