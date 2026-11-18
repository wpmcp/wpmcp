<?php
/**
 * The unit and live job matrices of a PHPUnit run, as JSON step outputs.
 *
 * Usage: php .github/ci/unit-matrix.php full
 *        php .github/ci/unit-matrix.php impact <shards> "<live leg kinds>"
 *
 * full    the sharded suite: WP 7.1 with coverage (4), the WP 6.9 floor (3),
 *         WP 7.1 multisite (3), the PHP 8.1 floor on WP 6.9 (2); live legs in
 *         3 groups (.github/ci/live-legs.sh)
 * impact  the selected tests on WP 7.1 and on the PHP 8.1 / WP 6.9 floor,
 *         <shards> jobs each, no coverage; the selected live legs in one job
 *         (none: an empty live matrix, the live job is skipped)
 *
 * The shard configs these rows name (shards/shard-<of>-<shard>.xml) are
 * written by .github/ci/shard.php in the same plan job.
 */

$mode = $argv[1] ?? '';
$rows = array();
$live = array();

$env = fn( $label, $leg, $wp, $php, $ms, $cov, $of ) => array_map(
	fn( $i ) => array( 'label' => $label, 'leg' => $leg, 'wp' => $wp, 'php' => $php, 'multisite' => $ms, 'coverage' => $cov, 'of' => $of, 'shard' => $i ),
	range( 1, $of )
);

if ( 'full' === $mode ) {
	$rows = array_merge(
		$env( 'WP 7.1 coverage', 'phpunit-default', '7.1', '8.3', false, true, 4 ),
		$env( 'WP 6.9', 'phpunit-floor', '6.9', '8.3', false, false, 3 ),
		$env( 'WP 7.1 multisite', 'multisite', '7.1', '8.3', true, false, 3 ),
		$env( 'PHP 8.1 WP 6.9', 'phpunit-floor', '6.9', '8.1', false, false, 2 )
	);
	foreach ( array( 1, 2, 3 ) as $g ) {
		$live[] = array( 'label' => "group $g of 3", 'group' => $g, 'legs' => '' );
	}
} elseif ( 'impact' === $mode ) {
	$n    = max( 1, min( 2, (int) ( $argv[2] ?? 1 ) ) );
	$rows = array_merge(
		$env( 'impact WP 7.1', 'phpunit-default', '7.1', '8.3', false, false, $n ),
		$env( 'impact PHP 8.1 WP 6.9', 'phpunit-floor', '6.9', '8.1', false, false, $n )
	);
	$legs = trim( $argv[3] ?? '' );
	if ( '' !== $legs ) {
		$live[] = array( 'label' => 'impact', 'group' => 0, 'legs' => $legs );
	}
} else {
	fwrite( STDERR, "usage: php unit-matrix.php full | impact <shards> \"<legs>\"\n" );
	exit( 2 );
}

echo 'unit-matrix=' . json_encode( $rows ) . "\n";
echo 'live-matrix=' . json_encode( $live ) . "\n";
