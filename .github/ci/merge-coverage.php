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
	// The shards recorded their uncovered files already. Processing them
	// again means including every src/ file outside WordPress, where the
	// first `defined( 'ABSPATH' ) || exit;` ends this script with status 0
	// and no report.
	$coverage->doNotProcessUncoveredFiles();
	if ( null === $merged ) {
		$merged = $coverage;
	} else {
		$merged->merge( $coverage );
	}
	echo "merged $file\n";
}
fwrite( STDERR, "writing {$argv[1]}\n" );
$xml = ( new Clover() )->process( $merged, $argv[1] );
if ( ! is_file( $argv[1] ) || filesize( $argv[1] ) < 1000 ) {
	fwrite( STDERR, "merge-coverage: the clover report is missing or empty (" . strlen( $xml ) . " bytes built)\n" );
	exit( 1 );
}
echo "wrote {$argv[1]} (" . filesize( $argv[1] ) . " bytes)\n";
