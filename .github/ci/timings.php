<?php
/**
 * Per-file run times from PHPUnit JUnit reports, for .github/ci/shard.php.
 *
 * Usage: php .github/ci/timings.php <out.json> <junit.xml> [<junit.xml> ...]
 *
 * Each report gives the seconds of every test class, keyed by its file
 * relative to the repository root. A file timed in several reports (one per
 * WordPress variant) gets the mean, so the split suits every variant.
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: php timings.php <out.json> <junit.xml> [<junit.xml> ...]\n" );
	exit( 2 );
}

$sum = array();
$n   = array();
foreach ( array_slice( $argv, 2 ) as $report ) {
	$xml = @simplexml_load_file( $report );
	if ( false === $xml ) {
		fwrite( STDERR, "timings: skipping unreadable report $report\n" );
		continue;
	}
	$per = array();
	foreach ( $xml->xpath( '//testsuite[@file]' ) as $suite ) {
		$name = (string) $suite['name'];
		if ( str_contains( $name, '::' ) ) {
			continue; // A data provider's suite; its class suite counts it.
		}
		$file = (string) $suite['file'];
		$pos  = strpos( $file, '/tests/' );
		if ( false === $pos ) {
			continue;
		}
		$file          = substr( $file, $pos + 1 );
		$per[ $file ]  = ( $per[ $file ] ?? 0 ) + (float) $suite['time'];
	}
	foreach ( $per as $file => $t ) {
		$sum[ $file ] = ( $sum[ $file ] ?? 0 ) + $t;
		$n[ $file ]   = ( $n[ $file ] ?? 0 ) + 1;
	}
}

$out = array();
foreach ( $sum as $file => $t ) {
	$out[ $file ] = round( $t / $n[ $file ], 3 );
}
ksort( $out );
if ( ! $out ) {
	fwrite( STDERR, "timings: no timings found\n" );
	exit( 1 );
}
file_put_contents( $argv[1], json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
printf( "timings: %d files, %.0f s in all\n", count( $out ), array_sum( $out ) );
