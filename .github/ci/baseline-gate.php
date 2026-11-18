<?php
/**
 * Fails only on test failures that are not in the leg's known-failure baseline.
 *
 * Usage: php .github/ci/baseline-gate.php <leg> <phpunit-exit-code> <junit.xml> [<junit.xml> ...]
 *        php .github/ci/baseline-gate.php --fixed <leg> <junit.xml> [<junit.xml> ...]
 *
 * The baseline is tests/phpunit-baseline-<leg>.txt, one "Class::method" per
 * line ("#" comments allowed), the same shrink-only files the local gate
 * (bin/test-local.sh) reads. Data sets are stripped, so one parametrised test
 * is one entry. With no baseline file every failure is new, which is the plain
 * pass or fail the gate had before baselines.
 *
 *   NEW failure (failing, not in the baseline)  -> exit 1
 *   PHPUnit exited non-zero but no failure was parsed (bootstrap fatal,
 *   crash, missing report)                      -> exit 1: the tests did not run
 *   failing and in the baseline                 -> reported, exit 0
 *
 * --fixed runs over all shards' reports of one leg (in the report job) and
 * lists baseline entries that now pass, so the baseline can be pruned; it
 * fails only when a report is missing. A shard alone cannot tell "fixed" from
 * "ran in another shard".
 */

function fail_ids( array $reports, bool &$ok ): array {
	$ids = array();
	foreach ( $reports as $report ) {
		$xml = is_readable( $report ) ? @simplexml_load_file( $report ) : false;
		if ( false === $xml ) {
			fwrite( STDERR, "baseline-gate: no readable JUnit report at $report\n" );
			$ok = false;
			continue;
		}
		foreach ( $xml->xpath( '//testcase[failure or error]' ) as $case ) {
			$name          = preg_replace( '/ with data set .*$/s', '', (string) $case['name'] );
			$ids[ (string) $case['class'] . '::' . $name ] = true;
		}
	}
	$ids = array_keys( $ids );
	sort( $ids );
	return $ids;
}

function baseline( string $leg ): array {
	$file = dirname( __DIR__, 2 ) . "/tests/phpunit-baseline-$leg.txt";
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$lines = array_map( 'trim', file( $file ) );
	$lines = array_filter( $lines, fn( $l ) => '' !== $l && '#' !== $l[0] );
	$lines = array_values( array_unique( $lines ) );
	sort( $lines );
	return $lines;
}

$args = array_slice( $argv, 1 );
if ( '--fixed' === ( $args[0] ?? '' ) ) {
	$leg   = $args[1] ?? '';
	$ok    = true;
	$fixed = array_diff( baseline( $leg ), fail_ids( array_slice( $args, 2 ), $ok ) );
	foreach ( $fixed as $id ) {
		echo "::notice::$leg: $id passes now; delete it from tests/phpunit-baseline-$leg.txt (baselines only shrink)\n";
	}
	printf( "baseline-gate %s: %d baseline entries fixed\n", $leg, count( $fixed ) );
	exit( $ok ? 0 : 1 );
}

if ( count( $args ) < 3 ) {
	fwrite( STDERR, "usage: php baseline-gate.php <leg> <phpunit-exit-code> <junit.xml> [...]\n" );
	exit( 2 );
}
$leg      = $args[0];
$rc       = (int) $args[1];
$ok       = true;
$failing  = fail_ids( array_slice( $args, 2 ), $ok );
$base     = baseline( $leg );
$new      = array_values( array_diff( $failing, $base ) );
$known    = array_values( array_intersect( $failing, $base ) );

printf( "baseline-gate %s: %d failing, %d of them baselined, %d new\n", $leg, count( $failing ), count( $known ), count( $new ) );
foreach ( $known as $id ) {
	echo "  known: $id\n";
}
foreach ( $new as $id ) {
	echo "::error::$leg: new failure $id\n";
}
if ( ! $ok ) {
	exit( 1 );
}
if ( 0 !== $rc && ! $failing ) {
	echo "::error::$leg: PHPUnit exited $rc but reported no failing test, so the tests did not run to the end\n";
	exit( 1 );
}
exit( $new ? 1 : 0 );
