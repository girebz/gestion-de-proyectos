<?php
/**
 * Transliteración para la carga masiva.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace GDP\Modules\Finance\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * La planilla de carga de SISREC no admite tildes ni la letra eñe en los
 * campos de texto. La pérdida es inocua porque la plataforma identifica al
 * proveedor por su rol y no por su nombre; por eso la transliteración se
 * aplica solo a los campos de texto libre.
 */
final class Transliterator {

	/**
	 * Mapa de sustitución.
	 *
	 * @var array<string,string>
	 */
	private const MAP = array(
		'á' => 'a',
		'é' => 'e',
		'í' => 'i',
		'ó' => 'o',
		'ú' => 'u',
		'ü' => 'u',
		'ñ' => 'n',
		'Á' => 'A',
		'É' => 'E',
		'Í' => 'I',
		'Ó' => 'O',
		'Ú' => 'U',
		'Ü' => 'U',
		'Ñ' => 'N',
		'à' => 'a',
		'è' => 'e',
		'ì' => 'i',
		'ò' => 'o',
		'ù' => 'u',
		'ç' => 'c',
		'Ç' => 'C',
		'º' => 'o',
		'ª' => 'a',
	);

	/**
	 * Devuelve el texto sin tildes ni eñes.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function ascii( string $text ): string {
		$text = strtr( $text, self::MAP );
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $converted ) {
				$text = $converted;
			}
		}

		return (string) preg_replace( '/[^\x20-\x7E]/', '', $text );
	}

	/**
	 * Rol tributario sin puntos ni dígito verificador (como lo pide la búsqueda de proveedores).
	 *
	 * @param string $tax_id Rol con o sin formato.
	 * @return string
	 */
	public static function tax_id_body( string $tax_id ): string {
		$clean = preg_replace( '/[^0-9kK]/', '', $tax_id );
		$clean = (string) $clean;
		if ( strlen( $clean ) >= 8 ) {
			return substr( $clean, 0, -1 );
		}

		return $clean;
	}

	/**
	 * Rol tributario con puntos y guion.
	 *
	 * @param string $tax_id Rol con o sin formato.
	 * @return string
	 */
	public static function tax_id_pretty( string $tax_id ): string {
		$clean = (string) preg_replace( '/[^0-9kK]/', '', $tax_id );
		if ( strlen( $clean ) < 2 ) {
			return $tax_id;
		}
		$body = substr( $clean, 0, -1 );
		$dv   = strtoupper( substr( $clean, -1 ) );

		return number_format( (float) $body, 0, '', '.' ) . '-' . $dv;
	}
}
