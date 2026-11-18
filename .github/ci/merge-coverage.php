<?php
/**
 * Merges the shards' PHP coverage files into one clover report.
 *
 * Usage: php .github/ci/merge-coverage.php <clover.xml> <shard.cov> [<shard.cov> ...]
 *
 * Each shard of the coverage leg runs PHPUnit with --coverage-php; this does
 * what phpcov's merge does, with the php-code-coverage that PHPUnit itself
 * brings (vendor/), so the serialised objects always match their reader.
 * bin/check-coverage.php then holds the merged report to the floor.
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: php merge-coverage.php <clover.xml> <shard.cov> [...]\n" );
	exit( 2 );
}

$merged = null;
foreach ( array_slice( $argv, 2 ) as $file ) {
	$coverage = include $file;
	if ( ! $coverage instanceof CodeCoverage ) {
		fwrite( STDERR, "merge-coverage: $file is not a PHP coverage file\n" );
		exit( 1 );
	}
	if ( null === $merged ) {
		$merged = $coverage;
	} else {
		$merged->merge( $coverage );
	}
	echo "merged $file\n";
}
( new Clover() )->process( $merged, $argv[1] );
echo "wrote {$argv[1]}\n";
