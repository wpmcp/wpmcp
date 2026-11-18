<?php
/**
 * Test impact selection for a pull request: which PHPUnit test files and which
 * free live legs a change needs, or that it needs the full sharded run.
 *
 * Usage:
 *   php .github/ci/impact.php --base <sha> --head <sha> --out <dir>
 *       [--labels <a,b>] [--map <impact-map.json>]... [--timings <timings.json>]
 *
 * The change set is `git diff <base> <head>`. On a pull_request run the
 * checkout is GitHub's merge commit, so base is its first parent (the base
 * branch tip) and the diff is exactly what merging the PR would change.
 *
 * Writes <out>/tests.txt (the selected test files) and <out>/summary.md, and
 * prints GitHub step outputs (key=value lines):
 *   mode    full | impact
 *   reason  why (one line)
 *   count   selected test files (impact mode)
 *   shards  shards per environment in impact mode (1 or 2)
 *   legs    selected free live leg kinds, space separated (impact mode)
 *
 * Full run (mode=full) when:
 *   - the PR has the label full-ci;
 *   - a core file changed (CORE below);
 *   - no impact map, or the newest one is older than MAP_MAX_AGE_DAYS;
 *   - a changed PHP file resolves to no test by map, convention or reference;
 *   - the selection is estimated to take longer than MAX_SECONDS in one job.
 *
 * Otherwise the selection is: tests that cover the changed src/ files (impact
 * map from per-test coverage), tests named by convention after them or that
 * reference their class, every test file the PR changes, and the always-run
 * guard set (GUARDS, plus every flavor and hardening test).
 */

const CORE = array(
	'#^src/Plugin\.php$#',
	'#^src/MCP/#',
	'#^src/Safety/#',
	'#^src/Integrations/Integration_Dispatcher\.php$#',
	'#^src/Privacy/#',
	'#^composer\.(json|lock)$#',
	'#^phpunit[^/]*\.xml(\.dist)?$#',
	'#^tests/bootstrap\.php$#',
	'#^tests/support/#',
	'#^tests/phpunit-baseline-[^/]*\.txt$#',
	'#^\.github/#',
	'#^bin/test-local\.sh$#',
	'#^bin/legs\.tsv$#',
	'#^bin/install-wp-tests\.sh$#',
	'#^bin/install-test-plugins\.sh$#',
	'#^bin/download-cache\.sh$#',
	'#^wpmcp\.php$#', // Except a version-only bump, see version_only().
);

// The rule 15 guard filter, by class name (any directory).
const GUARDS = array(
	'PackObjectCapabilityRegistryTest',
	'DataRegistryGuardTest',
	'DirectPostWriteGuardTest',
	'PluginDataRefusalTest',
	'ToolsListBudgetTest',
	'AbilityManifestTest',
	'WporgStripTest',
);
const GUARD_GLOBS = array( '#Flavor[^/]*Test\.php$#', '#Hardening[^/]*Test\.php$#' );

// Files a change to which needs no test at all.
const NO_TEST = array( '#^tests/impact-map\.json$#' );

const MAP_MAX_AGE_DAYS = 14;
const MAX_SECONDS      = 420;  // Above this one job is slower than the shards.
const SPLIT_SECONDS    = 150;  // Above this, two shards per environment.
const DEFAULT_SECONDS  = 0.6;  // Per test file with no timing.

$opt = array( 'map' => array() );
for ( $i = 1; $i < $argc; $i++ ) {
	$k = ltrim( $argv[ $i ], '-' );
	$v = $argv[ ++$i ] ?? '';
	if ( 'map' === $k ) {
		$opt['map'][] = $v;
	} else {
		$opt[ $k ] = $v;
	}
}
foreach ( array( 'base', 'head', 'out' ) as $req ) {
	if ( empty( $opt[ $req ] ) ) {
		fwrite( STDERR, "impact: --$req is required\n" );
		exit( 2 );
	}
}

$root = dirname( __DIR__, 2 );
chdir( $root );
$out = $opt['out'];
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}

$summary = array();
function decide( string $mode, string $reason, array $extra = array() ): void {
	global $out, $summary;
	$lines = array_merge( array( "mode=$mode", 'reason=' . str_replace( "\n", ' ', $reason ) ), $extra );
	echo implode( "\n", $lines ), "\n";
	$md  = "### Test impact selection: $mode\n\n$reason\n";
	$md .= $summary ? "\n" . implode( "\n", $summary ) . "\n" : '';
	file_put_contents( "$out/summary.md", $md );
	exit( 0 );
}

function git( string $args ): string {
	$res = shell_exec( "git $args 2>/dev/null" );
	return is_string( $res ) ? $res : '';
}

function norm( string $s ): string {
	return preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) );
}

// --- the change set -------------------------------------------------------

$base = $opt['base'];
$head = $opt['head'];
$raw  = trim( git( 'diff --name-status -M ' . escapeshellarg( $base ) . ' ' . escapeshellarg( $head ) ) );
if ( '' === $raw && '' === trim( git( 'rev-parse --verify ' . escapeshellarg( $base ) ) ) ) {
	decide( 'full', "Cannot diff against $base (not fetched)." );
}
$changed = array(); // path => status letter
foreach ( array_filter( explode( "\n", $raw ) ) as $line ) {
	$parts  = explode( "\t", $line );
	$status = $parts[0][0];
	if ( 'R' === $status ) {
		$changed[ $parts[1] ] = 'D';
		$changed[ $parts[2] ] = 'A';
	} elseif ( 'C' === $status ) {
		$changed[ $parts[2] ] = 'A';
	} else {
		$changed[ $parts[1] ] = $status;
	}
}
$summary[] = sprintf( '%d changed file(s) between `%s` and `%s`.', count( $changed ), substr( $base, 0, 8 ), substr( $head, 0, 8 ) );

$labels = array_filter( array_map( 'trim', explode( ',', $opt['labels'] ?? '' ) ) );
if ( in_array( 'full-ci', $labels, true ) ) {
	decide( 'full', 'The PR has the full-ci label.' );
}

function version_only( string $base, string $head ): bool {
	$diff = git( '-c core.quotepath=off diff -U0 ' . escapeshellarg( $base ) . ' ' . escapeshellarg( $head ) . ' -- wpmcp.php' );
	foreach ( explode( "\n", $diff ) as $l ) {
		if ( preg_match( '/^[-+](?![-+]{2} )/', $l ) && ! preg_match( "/^[-+]( \\* Version: |define\\( 'WPMCP_VERSION', )/", $l ) ) {
			return false;
		}
	}
	return true;
}

foreach ( $changed as $path => $status ) {
	foreach ( CORE as $re ) {
		if ( preg_match( $re, $path ) ) {
			if ( 'wpmcp.php' === $path && version_only( $base, $head ) ) {
				continue;
			}
			decide( 'full', "Core file changed: $path" );
		}
	}
}

// --- the impact map -------------------------------------------------------

$map = null;
foreach ( $opt['map'] as $file ) {
	$m = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $m ) || empty( $m['files'] ) || empty( $m['tests'] ) ) {
		continue;
	}
	if ( null === $map || strcmp( $m['generated_at'], $map['generated_at'] ) > 0 ) {
		$m['path'] = $file;
		$map       = $m;
	}
}
if ( null === $map ) {
	decide( 'full', 'No impact map (no cache from main and no tests/impact-map.json).' );
}
$age = ( time() - strtotime( $map['generated_at'] ) ) / 86400;
if ( $age > MAP_MAX_AGE_DAYS ) {
	decide( 'full', sprintf( 'The impact map (%s, %s) is %.0f days old; the limit is %d.', $map['path'], $map['generated_at'], $age, MAP_MAX_AGE_DAYS ) );
}
$summary[] = sprintf( 'Impact map: `%s`, built %s from %s at `%s`.', $map['path'], $map['generated_at'], $map['source'], substr( $map['commit'], 0, 8 ) );

// --- the test tree --------------------------------------------------------

$all_tests = array();
foreach ( array( 'tests/free', 'tests/pro' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && str_ends_with( $f->getFilename(), 'Test.php' ) ) {
			$all_tests[] = $f->getPathname();
		}
	}
}
sort( $all_tests );
$source = array(); // test file => contents, for reference lookups
foreach ( $all_tests as $t ) {
	$source[ $t ] = (string) file_get_contents( $t );
}

function tests_referencing( string $needle ): array {
	global $source;
	$hits = array();
	foreach ( $source as $t => $code ) {
		if ( str_contains( $code, $needle ) ) {
			$hits[] = $t;
		}
	}
	return $hits;
}

// The class a PSR-4 PHP file declares, for reference lookups.
function fqcn_of( string $path ): ?string {
	$roots = array(
		'src/'                   => 'WPMCP\\',
		'tools/compliance/src/'  => 'WPMCP\\Compliance\\',
		'tools/lint/src/'        => 'WPMCP\\Lint\\',
		'tests/free/'            => 'WPMCP\\Tests\\Free\\',
		'tests/pro/'             => 'WPMCP\\Tests\\Pro\\',
	);
	foreach ( $roots as $dir => $ns ) {
		if ( str_starts_with( $path, $dir ) && str_ends_with( $path, '.php' ) ) {
			return $ns . str_replace( '/', '\\', substr( $path, strlen( $dir ), -4 ) );
		}
	}
	return null;
}

// src/<Sub>/Foo_Integration.php -> tests/*/<Sub>/Foo*Test.php, and
// tests/**/FooIntegration*Test.php anywhere.
function by_convention( string $path ): array {
	global $all_tests;
	$stem  = basename( $path, '.php' );
	$sub   = dirname( preg_replace( '#^(src|tools/[^/]+/src)/#', '', $path ) );
	$short = preg_replace( '/_(Integration|Pack|Adapter|Ops|Operations|Tools?|Abilit(y|ies)|Handler|Service|Controller|Page|Store|Job|Hooks)$/', '', $stem );
	$full  = norm( $stem );
	$short = norm( $short );
	$hits  = array();
	foreach ( $all_tests as $t ) {
		$name = norm( substr( basename( $t ), 0, -8 ) );
		$tsub = dirname( preg_replace( '#^tests/(free|pro)/#', '', $t ) );
		if ( strlen( $full ) >= 3 && str_starts_with( $name, $full ) ) {
			$hits[] = $t;
		} elseif ( strlen( $short ) >= 3 && '.' !== $sub && $tsub === $sub && str_starts_with( $name, $short ) ) {
			$hits[] = $t;
		}
	}
	return $hits;
}

// tools/compliance/** -> tests/*/Compliance/**, tools/lint/** -> tests/*/Lint/**.
function by_tool_dir( string $path ): array {
	global $all_tests;
	$dirs = array( 'tools/compliance/' => 'Compliance', 'tools/lint/' => 'Lint' );
	foreach ( $dirs as $prefix => $sub ) {
		if ( str_starts_with( $path, $prefix ) ) {
			return array_values( array_filter( $all_tests, fn( $t ) => (bool) preg_match( "#^tests/(free|pro)/$sub/#", $t ) ) );
		}
	}
	return array();
}

$map_index = array();
foreach ( $map['files'] as $src => $idx ) {
	$map_index[ $src ] = array_map( fn( $i ) => $map['tests'][ $i ], $idx );
}

// --- selection ------------------------------------------------------------

$selected       = array(); // test file => why
$changed_tests  = array();
$changed_groups = array();
$unresolved     = array();
foreach ( $changed as $path => $status ) {
	foreach ( NO_TEST as $re ) {
		if ( preg_match( $re, $path ) ) {
			continue 2;
		}
	}
	if ( preg_match( '#^tests/(free|pro)/.*Test\.php$#', $path ) ) {
		if ( 'D' !== $status && is_file( $path ) ) {
			$selected[ $path ] ??= 'changed in the PR';
			$changed_tests[]     = $path;
			if ( preg_match_all( '/@group\s+(\S+)/', $source[ $path ] ?? '', $m ) ) {
				$changed_groups = array_merge( $changed_groups, $m[1] );
			}
		}
		continue;
	}

	$hits = array();
	if ( str_starts_with( $path, 'src/' ) ) {
		foreach ( $map_index[ $path ] ?? array() as $t ) {
			$hits[ $t ] = 'covers ' . $path;
		}
		foreach ( by_convention( $path ) as $t ) {
			$hits[ $t ] ??= 'named after ' . $path;
		}
	}
	$fqcn = fqcn_of( $path );
	if ( null !== $fqcn ) {
		foreach ( array_unique( array_merge( tests_referencing( $fqcn ), tests_referencing( str_replace( '\\', '\\\\', $fqcn ) ) ) ) as $t ) {
			$hits[ $t ] ??= 'references ' . $fqcn;
		}
	}
	foreach ( by_tool_dir( $path ) as $t ) {
		$hits[ $t ] ??= 'tests the tool in ' . $path;
	}
	if ( preg_match( '#^tests/#', $path ) ) {
		// A helper or fixture beside the tests: its directory's tests.
		foreach ( $all_tests as $t ) {
			if ( dirname( $t ) === dirname( $path ) ) {
				$hits[ $t ] ??= 'beside ' . $path;
			}
		}
	}
	if ( ! str_starts_with( $path, 'src/' ) ) {
		// Anything else (scripts, readme, assets): tests naming the file.
		foreach ( array_unique( array_merge( tests_referencing( $path ), strlen( basename( $path ) ) >= 8 ? tests_referencing( basename( $path ) ) : array() ) ) as $t ) {
			$hits[ $t ] ??= 'mentions ' . $path;
		}
	}
	foreach ( $hits as $t => $why ) {
		$selected[ $t ] ??= $why;
	}
	if ( ! $hits && ( str_ends_with( $path, '.php' ) || str_starts_with( $path, 'tests/' ) ) && 'D' !== $status ) {
		$unresolved[ $path ] = $status;
	}
}

// A new PHP file with nothing pointing at it yet is fine when the PR brings
// tests of its own; anything else unresolved means the map cannot tell.
foreach ( $unresolved as $path => $status ) {
	if ( 'A' === $status && $changed_tests ) {
		$summary[] = "- `$path` is new and unmapped; the PR's own tests run for it.";
		continue;
	}
	decide( 'full', "No test maps to $path (not in the impact map, no naming or reference match)." );
}

foreach ( $all_tests as $t ) {
	$name = basename( $t, '.php' );
	$why  = in_array( $name, GUARDS, true ) ? 'guard set' : null;
	foreach ( GUARD_GLOBS as $re ) {
		$why ??= preg_match( $re, $t ) ? 'flavor and hardening set' : null;
	}
	if ( null !== $why ) {
		$selected[ $t ] ??= $why;
	}
}
ksort( $selected );

// --- free live legs -------------------------------------------------------

$legs = array();
foreach ( file( 'bin/legs.tsv', FILE_IGNORE_NEW_LINES ) as $line ) {
	if ( '' === trim( $line ) || '#' === $line[0] ) {
		continue;
	}
	$col = explode( "\t", $line );
	if ( count( $col ) < 8 || 'free' !== $col[2] ) {
		continue;
	}
	$groups = '-' === $col[5] ? array() : explode( ',', $col[5] );
	$files  = '-' === $col[7] ? array() : explode( ',', $col[7] );
	$why    = null;
	foreach ( $files as $f ) {
		if ( isset( $changed[ $f ] ) ) {
			$why = "$f changed";
			break;
		}
		$code = $source[ $f ] ?? '';
		$stem = norm( preg_replace( '/LiveTest$/', '', basename( $f, '.php' ) ) );
		foreach ( $changed as $path => $status ) {
			if ( ! preg_match( '#^(src|tools)/#', $path ) ) {
				continue;
			}
			$fqcn = fqcn_of( $path );
			if ( null !== $fqcn && ( str_contains( $code, $fqcn ) || str_contains( $code, str_replace( '\\', '\\\\', $fqcn ) ) ) ) {
				$why = "$f references $fqcn";
				break 2;
			}
			$pstem = norm( basename( $path, '.php' ) );
			if ( ( strlen( $stem ) >= 4 && str_starts_with( $pstem, $stem ) ) || ( strlen( $stem ) >= 5 && str_contains( $pstem, $stem ) ) ) {
				$why = "$f is named after $path";
				break 2;
			}
		}
	}
	if ( null === $why && array_intersect( $groups, $changed_groups ) ) {
		$why = 'a changed test is in group ' . implode( ',', array_intersect( $groups, $changed_groups ) );
	}
	if ( null !== $why ) {
		$legs[ $col[1] ] = $why;
	}
}

// --- size ----------------------------------------------------------------

$timings = array();
if ( ! empty( $opt['timings'] ) && is_readable( $opt['timings'] ) ) {
	$timings = json_decode( (string) file_get_contents( $opt['timings'] ), true ) ?: array();
}
$seconds = 0.0;
foreach ( array_keys( $selected ) as $t ) {
	$seconds += (float) ( $timings[ $t ] ?? DEFAULT_SECONDS );
}
$summary[] = sprintf( "\n%d of %d test files selected, about %.0f s in one process; %d free live leg(s).", count( $selected ), count( $all_tests ), $seconds, count( $legs ) );
if ( $seconds > MAX_SECONDS ) {
	decide( 'full', sprintf( 'The selection (%d test files, about %.0f s) is too large for one job; the shards are faster.', count( $selected ), $seconds ) );
}

file_put_contents( "$out/tests.txt", implode( "\n", array_keys( $selected ) ) . "\n" );
$summary[] = "\n<details><summary>Selected tests</summary>\n";
foreach ( $selected as $t => $why ) {
	$summary[] = "- `$t`: $why";
}
$summary[] = "\n</details>\n";
foreach ( $legs as $leg => $why ) {
	$summary[] = "- live leg `$leg`: $why";
}

decide(
	'impact',
	sprintf( 'Impact selection: %d test files, %d live leg(s).', count( $selected ), count( $legs ) ),
	array(
		'count=' . count( $selected ),
		'shards=' . ( $seconds > SPLIT_SECONDS ? 2 : 1 ),
		'legs=' . implode( ' ', array_keys( $legs ) ),
	)
);
