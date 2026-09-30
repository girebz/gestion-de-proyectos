<?php
/**
 * Sustituto mínimo de PHPUnit\Framework\TestCase para entornos sin Composer.
 *
 * Solo se carga cuando PHPUnit no está instalado (véase tests/bin/run.php).
 * Implementa el subconjunto de aserciones que usan las pruebas del plugin,
 * con la misma firma que PHPUnit, de modo que la suite corre igual en ambos
 * entornos.
 *
 * @package GDP
 */

declare( strict_types=1 );

namespace PHPUnit\Framework;

use Throwable;

/**
 * Error de aserción.
 */
class AssertionFailedError extends \Exception {}

/**
 * Prueba omitida.
 */
class SkippedTestError extends \Exception {}

/**
 * Caso de prueba mínimo.
 */
abstract class TestCase {

	/**
	 * Excepción esperada.
	 *
	 * @var string|null
	 */
	private ?string $expected_exception = null;

	/**
	 * Mensaje esperado de la excepción.
	 *
	 * @var string|null
	 */
	private ?string $expected_message = null;

	/**
	 * Aserciones ejecutadas.
	 *
	 * @var int
	 */
	private static int $assertions = 0;

	/**
	 * Preparación previa a cada prueba.
	 *
	 * @return void
	 */
	protected function setUp(): void {}

	/**
	 * Limpieza posterior a cada prueba.
	 *
	 * @return void
	 */
	protected function tearDown(): void {}

	/**
	 * Ejecuta un método de prueba con su ciclo de vida.
	 *
	 * @param string $method Método.
	 * @return void
	 * @throws Throwable Si la prueba falla.
	 */
	public function gdp_run( string $method ): void {
		$this->expected_exception = null;
		$this->expected_message   = null;
		$this->setUp();
		try {
			$this->$method();
			if ( null !== $this->expected_exception ) {
				throw new AssertionFailedError( sprintf( 'Se esperaba la excepción %s y no se lanzó.', $this->expected_exception ) );
			}
		} catch ( AssertionFailedError | SkippedTestError $e ) {
			throw $e;
		} catch ( Throwable $e ) {
			if ( null !== $this->expected_exception && $e instanceof $this->expected_exception ) {
				if ( null !== $this->expected_message && false === strpos( $e->getMessage(), $this->expected_message ) ) {
					throw new AssertionFailedError( sprintf( 'El mensaje "%s" no contiene "%s".', $e->getMessage(), $this->expected_message ) );
				}
				++self::$assertions;
			} else {
				throw $e;
			}
		} finally {
			$this->tearDown();
		}
	}

	/**
	 * Número de aserciones ejecutadas.
	 *
	 * @return int
	 */
	public static function gdp_assertions(): int {
		return self::$assertions;
	}

	/**
	 * Declara la excepción esperada.
	 *
	 * @param string $class Clase.
	 * @return void
	 */
	public function expectException( string $class ): void {
		$this->expected_exception = $class;
	}

	/**
	 * Declara el mensaje esperado.
	 *
	 * @param string $message Fragmento del mensaje.
	 * @return void
	 */
	public function expectExceptionMessage( string $message ): void {
		$this->expected_message = $message;
	}

	/**
	 * Marca la prueba como omitida.
	 *
	 * @param string $message Motivo.
	 * @return never
	 */
	public static function markTestSkipped( string $message = '' ): never {
		throw new SkippedTestError( $message );
	}

	/**
	 * Falla explícitamente.
	 *
	 * @param string $message Motivo.
	 * @return never
	 */
	public static function fail( string $message = '' ): never {
		throw new AssertionFailedError( $message );
	}

	/**
	 * Registra una aserción y falla si la condición es falsa.
	 *
	 * @param bool   $condition Condición.
	 * @param string $message   Mensaje.
	 * @return void
	 */
	private static function check( bool $condition, string $message ): void {
		++self::$assertions;
		if ( ! $condition ) {
			throw new AssertionFailedError( $message );
		}
	}

	/**
	 * Representación breve de un valor.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private static function export( mixed $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			$json = var_export( $value, true );
		}

		return strlen( $json ) > 300 ? substr( $json, 0, 300 ) . '…' : $json;
	}

	/**
	 * Compone el mensaje de fallo.
	 *
	 * @param string $message  Mensaje del usuario.
	 * @param string $detail   Detalle.
	 * @return string
	 */
	private static function msg( string $message, string $detail ): string {
		return '' === $message ? $detail : $message . ' — ' . $detail;
	}

	public static function assertTrue( mixed $actual, string $message = '' ): void {
		self::check( true === $actual, self::msg( $message, 'Se esperaba true, se obtuvo ' . self::export( $actual ) ) );
	}

	public static function assertFalse( mixed $actual, string $message = '' ): void {
		self::check( false === $actual, self::msg( $message, 'Se esperaba false, se obtuvo ' . self::export( $actual ) ) );
	}

	public static function assertNull( mixed $actual, string $message = '' ): void {
		self::check( null === $actual, self::msg( $message, 'Se esperaba null, se obtuvo ' . self::export( $actual ) ) );
	}

	public static function assertNotNull( mixed $actual, string $message = '' ): void {
		self::check( null !== $actual, self::msg( $message, 'No se esperaba null.' ) );
	}

	public static function assertSame( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $expected === $actual, self::msg( $message, 'Se esperaba ' . self::export( $expected ) . ', se obtuvo ' . self::export( $actual ) ) );
	}

	public static function assertNotSame( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $expected !== $actual, self::msg( $message, 'No se esperaba ' . self::export( $expected ) ) );
	}

	public static function assertEquals( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $expected == $actual, self::msg( $message, 'Se esperaba ' . self::export( $expected ) . ', se obtuvo ' . self::export( $actual ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	}

	public static function assertCount( int $expected, mixed $actual, string $message = '' ): void {
		$n = is_countable( $actual ) ? count( $actual ) : -1;
		self::check( $expected === $n, self::msg( $message, sprintf( 'Se esperaban %d elementos, hay %d.', $expected, $n ) ) );
	}

	public static function assertEmpty( mixed $actual, string $message = '' ): void {
		self::check( empty( $actual ), self::msg( $message, 'Se esperaba un valor vacío, se obtuvo ' . self::export( $actual ) ) );
	}

	public static function assertNotEmpty( mixed $actual, string $message = '' ): void {
		self::check( ! empty( $actual ), self::msg( $message, 'Se esperaba un valor no vacío.' ) );
	}

	public static function assertContains( mixed $needle, iterable $haystack, string $message = '' ): void {
		$found = false;
		foreach ( $haystack as $item ) {
			if ( $item === $needle ) {
				$found = true;
				break;
			}
		}
		self::check( $found, self::msg( $message, 'No se encontró ' . self::export( $needle ) . ' en ' . self::export( $haystack ) ) );
	}

	public static function assertNotContains( mixed $needle, iterable $haystack, string $message = '' ): void {
		$found = false;
		foreach ( $haystack as $item ) {
			if ( $item === $needle ) {
				$found = true;
				break;
			}
		}
		self::check( ! $found, self::msg( $message, 'No se esperaba ' . self::export( $needle ) . ' en ' . self::export( $haystack ) ) );
	}

	public static function assertArrayHasKey( mixed $key, array $array, string $message = '' ): void {
		self::check( array_key_exists( $key, $array ), self::msg( $message, 'Falta la clave ' . self::export( $key ) . ' en ' . self::export( array_keys( $array ) ) ) );
	}

	public static function assertArrayNotHasKey( mixed $key, array $array, string $message = '' ): void {
		self::check( ! array_key_exists( $key, $array ), self::msg( $message, 'No se esperaba la clave ' . self::export( $key ) ) );
	}

	public static function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {
		self::check( '' === $needle || false !== strpos( $haystack, $needle ), self::msg( $message, sprintf( '"%s" no contiene "%s".', $haystack, $needle ) ) );
	}

	public static function assertStringStartsWith( string $prefix, string $string, string $message = '' ): void {
		self::check( str_starts_with( $string, $prefix ), self::msg( $message, sprintf( '"%s" no empieza por "%s".', $string, $prefix ) ) );
	}

	public static function assertMatchesRegularExpression( string $pattern, string $string, string $message = '' ): void {
		self::check( 1 === preg_match( $pattern, $string ), self::msg( $message, sprintf( '"%s" no coincide con %s.', $string, $pattern ) ) );
	}

	public static function assertGreaterThan( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $actual > $expected, self::msg( $message, self::export( $actual ) . ' no es mayor que ' . self::export( $expected ) ) );
	}

	public static function assertGreaterThanOrEqual( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $actual >= $expected, self::msg( $message, self::export( $actual ) . ' no es mayor o igual que ' . self::export( $expected ) ) );
	}

	public static function assertLessThan( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $actual < $expected, self::msg( $message, self::export( $actual ) . ' no es menor que ' . self::export( $expected ) ) );
	}

	public static function assertLessThanOrEqual( mixed $expected, mixed $actual, string $message = '' ): void {
		self::check( $actual <= $expected, self::msg( $message, self::export( $actual ) . ' no es menor o igual que ' . self::export( $expected ) ) );
	}

	public static function assertIsArray( mixed $actual, string $message = '' ): void {
		self::check( is_array( $actual ), self::msg( $message, 'Se esperaba un arreglo.' ) );
	}

	public static function assertIsInt( mixed $actual, string $message = '' ): void {
		self::check( is_int( $actual ), self::msg( $message, 'Se esperaba un entero, se obtuvo ' . self::export( $actual ) ) );
	}

	public static function assertIsString( mixed $actual, string $message = '' ): void {
		self::check( is_string( $actual ), self::msg( $message, 'Se esperaba una cadena.' ) );
	}

	public static function assertInstanceOf( string $class, mixed $actual, string $message = '' ): void {
		self::check( $actual instanceof $class, self::msg( $message, 'Se esperaba una instancia de ' . $class ) );
	}
}
