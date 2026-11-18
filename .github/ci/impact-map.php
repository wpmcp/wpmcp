<?php
/**
 * Builds the test impact map: which test files exercise which src/ files.
 *
 * Usage: php .github/ci/impact-map.php <out.json> <commit> <source> <reports-dir> [<reports-dir> ...]
 *
 * Each reports dir holds what one coverage shard uploads: coverage.cov (from
 * PHPUnit --coverage-php under pcov) and junit.xml. PHPUnit records, for every
 * covered line, the ids of the tests that ran it ("Class::method ..."); the
 * JUnit report names the file of every test class. Together they give, per
 * src/ file, the test files that touched it.
 *
 * Code that runs outside a test (bootstrap, setUpBeforeClass) is attributed
 * to no test, so a src/ file can be missing from the map although tests load
 * it. .github/ci/impact.php treats such a file with its naming conventions
 * and falls back to the full run when those find nothing.
 *
 * The output is consumed by .github/ci/impact.php. Written by the report job
 * of every full PHPUnit run on main (cached as impact-map-*) and committed as
 * tests/impact-map.json by bin/update-impact-map.sh as the fallback.
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( $argc < 5 ) {
	fwrite( STDERR, "usage: php impact-map.php <out.json> <commit> <source> <reports-dir> [...]\n" );
	exit( 2 );
}

$out    = $argv[1];
$commit = $argv[2];
$source = $argv[3];
$dirs   = array_slice( $argv, 4 );

// Repository-relative path from a runner or local absolute path.
function rel_path( string $abs, string $anchor ): ?string {
	$pos = strrpos( $abs, "/$anchor/" );
	return false === $pos ? null : substr( $abs, $pos + 1 );
}

$class_file = array();
$covs       = array();
foreach ( $dirs as $dir ) {
	$cov   = "$dir/coverage.cov";
	$junit = "$dir/junit.xml";
	if ( ! is_readable( $cov ) || ! is_readable( $junit ) ) {
		fwrite( STDERR, "impact-map: $dir lacks coverage.cov or junit.xml\n" );
		exit( 1 );
	}
	$xml = simplexml_load_file( $junit );
	if ( false === $xml ) {
		fwrite( STDERR, "impact-map: unreadable $junit\n" );
		exit( 1 );
	}
	foreach ( $xml->xpath( '//testsuite[@file]' ) as $suite ) {
		$file = rel_path( (string) $suite['file'], 'tests' );
		if ( null !== $file ) {
			$class_file[ (string) $suite['name'] ] = $file;
		}
	}
	$covs[] = $cov;
}

$tests = array(); // test file => index
$files = array(); // src file => set of indexes
$ids   = 0;
foreach ( $covs as $cov ) {
	$coverage = include $cov;
	if ( ! $coverage instanceof SebastianBergmann\CodeCoverage\CodeCoverage ) {
		fwrite( STDERR, "impact-map: $cov is not a PHP coverage file\n" );
		exit( 1 );
	}
	foreach ( $coverage->getData( true )->lineCoverage() as $abs => $lines ) {
		$src = rel_path( $abs, 'src' );
		if ( null === $src ) {
			continue;
		}
		$seen = array();
		foreach ( $lines as $line_tests ) {
			foreach ( (array) $line_tests as $id ) {
				$seen[ strtok( (string) $id, ':' ) ] = true;
			}
		}
		foreach ( array_keys( $seen ) as $class ) {
			$test_file = $class_file[ $class ] ?? null;
			if ( null === $test_file ) {
				continue;
			}
			if ( ! isset( $tests[ $test_file ] ) ) {
				$tests[ $test_file ] = count( $tests );
			}
			$files[ $src ][ $tests[ $test_file ] ] = true;
			++$ids;
		}
	}
	unset( $coverage );
	gc_collect_cycles();
	echo "read $cov\n";
}

if ( ! $files ) {
	fwrite( STDERR, "impact-map: no per-test coverage found (was PHPUnit run with --coverage-php?)\n" );
	exit( 1 );
}

// Stable output: tests sorted, indexes renumbered, one src file per line.
$names = array_keys( $tests );
sort( $names );
$renum = array();
foreach ( $names as $i => $name ) {
	$renum[ $tests[ $name ] ] = $i;
}
ksort( $files );
$rows = array();
foreach ( $files as $src => $set ) {
	$list = array_map( fn( $i ) => $renum[ $i ], array_keys( $set ) );
	sort( $list );
	$rows[] = '    ' . json_encode( $src, JSON_UNESCAPED_SLASHES ) . ': [' . implode( ',', $list ) . ']';
}

$json  = "{\n";
$json .= '  "version": 1,' . "\n";
$json .= '  "generated_at": ' . json_encode( gmdate( 'Y-m-d\TH:i:s\Z' ) ) . ",\n";
$json .= '  "commit": ' . json_encode( $commit ) . ",\n";
$json .= '  "source": ' . json_encode( $source, JSON_UNESCAPED_SLASHES ) . ",\n";
$json .= '  "tests": [' . "\n    " . implode( ",\n    ", array_map( fn( $t ) => json_encode( $t, JSON_UNESCAPED_SLASHES ), $names ) ) . "\n  ],\n";
$json .= '  "files": {' . "\n" . implode( ",\n", $rows ) . "\n  }\n}\n";

if ( null === json_decode( $json, true ) ) {
	fwrite( STDERR, "impact-map: built invalid JSON\n" );
	exit( 1 );
}
file_put_contents( $out, $json );
printf( "wrote %s: %d src files, %d test files, %d links\n", $out, count( $files ), count( $names ), $ids );
