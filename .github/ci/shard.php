<?php
/**
 * Writes PHPUnit configs that split the free and pro suites into shards.
 *
 * Usage: php .github/ci/shard.php <out-dir> <timings.json|-> <count> [<count> ...]
 *
 * For each count N it writes <out-dir>/shard-N-<i>.xml (i = 1..N): a copy of
 * phpunit.xml.dist whose test suite lists that shard's test files. Files are
 * spread by longest processing time first, using the per-file seconds in the
 * timings file (written by .github/ci/timings.php from the JUnit reports of an
 * earlier run). A file with no timing gets the median; with no timings at all,
 * the file size stands in for its run time. Every *Test.php file under
 * tests/free and tests/pro lands in exactly one shard of each count, and the
 * script fails if not, so a shard plan can never drop a test.
 *
 * The plan job runs this once and hands the configs to every shard job, so
 * all shards of a run split the same way.
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "usage: php shard.php <out-dir> <timings.json|-> <count> [<count> ...]\n" );
	exit( 2 );
}

$root   = dirname( __DIR__, 2 );
$out    = $argv[1];
$counts = array_map( 'intval', array_slice( $argv, 3 ) );

$files = array();
foreach ( array( 'tests/free', 'tests/pro' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && str_ends_with( $f->getFilename(), 'Test.php' ) ) {
			$files[] = substr( $f->getPathname(), strlen( $root ) + 1 );
		}
	}
}
sort( $files );
if ( ! $files ) {
	fwrite( STDERR, "shard: no test files found\n" );
	exit( 1 );
}

$timings = array();
if ( '-' !== $argv[2] && is_readable( $argv[2] ) ) {
	$timings = json_decode( (string) file_get_contents( $argv[2] ), true ) ?: array();
}
$known  = array_values( array_filter( array_map( fn( $f ) => $timings[ $f ] ?? null, $files ), 'is_numeric' ) );
$weight = array();
if ( count( $known ) >= count( $files ) / 2 ) {
	sort( $known );
	$median = $known[ intdiv( count( $known ), 2 ) ];
	foreach ( $files as $f ) {
		$weight[ $f ] = (float) ( $timings[ $f ] ?? $median );
	}
	$basis = sprintf( 'timings (%d of %d files timed)', count( $known ), count( $files ) );
} else {
	foreach ( $files as $f ) {
		$weight[ $f ] = (float) filesize( "$root/$f" );
	}
	$basis = 'file size (no usable timings yet)';
}

$dist = file_get_contents( "$root/phpunit.xml.dist" );
if ( ! preg_match( '#<testsuites>.*?</testsuites>#s', $dist ) ) {
	fwrite( STDERR, "shard: phpunit.xml.dist has no <testsuites> block\n" );
	exit( 1 );
}

if ( ! is_dir( $out ) && ! mkdir( $out, 0777, true ) ) {
	fwrite( STDERR, "shard: cannot create $out\n" );
	exit( 1 );
}

// The configs live one level below the root (<out-dir> is "shards"), so
// every path in them is relative to the root through "../".
$rel = rtrim( str_repeat( '../', substr_count( trim( $out, '/' ), '/' ) + 1 ), '/' );

foreach ( $counts as $n ) {
	$order = $files;
	usort( $order, fn( $a, $b ) => $weight[ $b ] <=> $weight[ $a ] ?: strcmp( $a, $b ) );
	$load   = array_fill( 1, $n, 0.0 );
	$member = array_fill( 1, $n, array() );
	foreach ( $order as $f ) {
		$i              = array_keys( $load, min( $load ), true )[0];
		$member[ $i ][] = $f;
		$load[ $i ]    += $weight[ $f ];
	}
	$seen = array_merge( ...array_values( $member ) );
	sort( $seen );
	if ( $seen !== $files ) {
		fwrite( STDERR, "shard: the $n-way plan does not cover every test file exactly once\n" );
		exit( 1 );
	}
	foreach ( $member as $i => $list ) {
		sort( $list );
		$suite = "<testsuites>\n    <testsuite name=\"shard-$n-$i\">\n";
		foreach ( $list as $f ) {
			$suite .= "      <file>$rel/$f</file>\n";
		}
		$suite .= "    </testsuite>\n  </testsuites>";
		$xml    = preg_replace( '#<testsuites>.*?</testsuites>#s', $suite, $dist );
		$xml    = str_replace( 'bootstrap="tests/', "bootstrap=\"$rel/tests/", $xml );
		$xml    = preg_replace( '#<directory suffix="\.php">src</directory>#', "<directory suffix=\".php\">$rel/src</directory>", $xml );
		file_put_contents( "$out/shard-$n-$i.xml", $xml );
		printf( "shard %d of %d: %3d files, estimated %.0f%s\n", $i, $n, count( $list ), $load[ $i ], 'file size (no usable timings yet)' === $basis ? ' bytes' : ' s' );
	}
}
echo "Balanced by $basis.\n";
