#!/usr/bin/env php
<?php
/**
 * Ejecutor de la suite de pruebas.
 *
 * Con PHPUnit instalado (composer install) delega en él; sin Composer usa
 * el sustituto mínimo de tests/bin/TestCase.php, que ejecuta las mismas
 * clases de prueba. Uso: php tests/bin/run.php [--filter=Texto]
 *
 * @package GDP
 */

declare( strict_types=1 );

$gdp_runner_root = dirname( __DIR__, 2 );

if ( is_file( $gdp_runner_root . '/vendor/bin/phpunit' ) ) {
	$args = array_slice( $argv, 1 );
	passthru( PHP_BINARY . ' ' . escapeshellarg( $gdp_runner_root . '/vendor/bin/phpunit' ) . ' -c ' . escapeshellarg( $gdp_runner_root . '/phpunit.xml.dist' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ), $code );
	exit( $code );
}

$filter = null;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--filter=' ) ) {
		$filter = substr( $arg, 9 );
	}
}

require_once __DIR__ . '/TestCase.php';
require_once $gdp_runner_root . '/tests/bootstrap.php';

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $gdp_runner_root . '/tests/Unit', FilesystemIterator::SKIP_DOTS ) );
$files    = array();
foreach ( $iterator as $file ) {
	if ( str_ends_with( $file->getFilename(), 'Test.php' ) ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

$before = get_declared_classes();
foreach ( $files as $file ) {
	require_once $file;
}
$classes = array_filter(
	array_diff( get_declared_classes(), $before ),
	static fn( string $class ): bool => is_subclass_of( $class, PHPUnit\Framework\TestCase::class ) && ! ( new ReflectionClass( $class ) )->isAbstract()
);
sort( $classes );

/**
 * Ubicación del fallo dentro de la prueba (primer marco en tests/Unit).
 *
 * @param Throwable $e Excepción.
 * @return string
 */
function gdp_test_location( Throwable $e ): string {
	foreach ( $e->getTrace() as $frame ) {
		if ( isset( $frame['file'] ) && str_contains( $frame['file'], DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR ) ) {
			return $frame['file'] . ':' . $frame['line'];
		}
	}

	return $e->getFile() . ':' . $e->getLine();
}

$passed   = 0;
$failed   = array();
$skipped  = 0;
$errors   = array();
$started  = microtime( true );

foreach ( $classes as $class ) {
	$reflection = new ReflectionClass( $class );
	foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		$name = $method->getName();
		if ( ! str_starts_with( $name, 'test' ) || $method->isStatic() ) {
			continue;
		}
		$label = $class . '::' . $name;
		if ( null !== $filter && false === stripos( $label, $filter ) ) {
			continue;
		}
		try {
			$instance = new $class();
			$instance->gdp_run( $name );
			++$passed;
			echo '.';
		} catch ( PHPUnit\Framework\SkippedTestError $e ) {
			++$skipped;
			echo 'S';
		} catch ( PHPUnit\Framework\AssertionFailedError $e ) {
			$failed[] = array( $label, $e->getMessage(), gdp_test_location( $e ) );
			echo 'F';
		} catch ( Throwable $e ) {
			$errors[] = array( $label, get_class( $e ) . ': ' . $e->getMessage(), gdp_test_location( $e ) );
			echo 'E';
		}
	}
}

$elapsed = microtime( true ) - $started;
echo "\n\n";

foreach ( $failed as $i => $f ) {
	printf( "%d) %s\n   %s\n   %s\n\n", $i + 1, $f[0], $f[1], $f[2] );
}
foreach ( $errors as $i => $f ) {
	printf( "E%d) %s\n   %s\n   %s\n\n", $i + 1, $f[0], $f[1], $f[2] );
}

printf(
	"Pruebas: %d, Aserciones: %d, Fallos: %d, Errores: %d, Omitidas: %d (%.2f s)\n",
	$passed + count( $failed ) + count( $errors ),
	PHPUnit\Framework\TestCase::gdp_assertions(),
	count( $failed ),
	count( $errors ),
	$skipped,
	$elapsed
);

exit( empty( $failed ) && empty( $errors ) ? 0 : 1 );
