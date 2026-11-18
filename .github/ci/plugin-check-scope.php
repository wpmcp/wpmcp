<?php
/**
 * Scopes the Plugin Check job of a pull request to the shipped files it changes.
 *
 * Usage: php .github/ci/plugin-check-scope.php <base> <head> <build-dir>
 *
 * Plugin Check's code checks are per file (PHPCS sniffs and file scans), so a
 * file the PR does not change cannot gain a finding from it. Scanning only the
 * changed files, plus the plugin's main file, readme and uninstall.php (which
 * the header, readme and plugin-wide checks read), gives the same answer for
 * the PR in seconds instead of two minutes. Every push to main still checks
 * the whole build.
 *
 * Prints GitHub step outputs:
 *   mode          full | scoped | skip
 *   reason        one line
 *   exclude-dirs  newline-free, comma separated (scoped only)
 *   exclude-files comma separated (scoped only)
 *
 *   full    a file that shapes the build or the check changed (BUILD below)
 *   skip    the PR changes no shipped file: the build is the one main passed
 *   scoped  everything else
 */

const BUILD = array(
	'#^scripts/#',
	'#^tools/#',
	'#^\.github/#',
	'#^phpcs[^/]*\.xml(\.dist)?$#',
	'#^composer\.(json|lock)$#',
	'#^\.distignore$#',
);
const ALWAYS = array( 'wpmcp.php', 'readme.txt', 'uninstall.php' );

function out( array $kv ): void {
	foreach ( $kv as $k => $v ) {
		echo "$k=$v\n";
	}
	exit( 0 );
}

if ( $argc < 4 ) {
	fwrite( STDERR, "usage: php plugin-check-scope.php <base> <head> <build-dir>\n" );
	exit( 2 );
}
list( , $base, $head, $build ) = $argv;
chdir( dirname( __DIR__, 2 ) );

if ( ! is_file( "$build/wpmcp.php" ) ) {
	out( array( 'mode' => 'full', 'reason' => 'no build tree to scope' ) );
}

$raw = shell_exec( 'git diff --name-only --diff-filter=ACMRT ' . escapeshellarg( $base ) . ' ' . escapeshellarg( $head ) . ' 2>/dev/null' );
if ( ! is_string( $raw ) ) {
	out( array( 'mode' => 'full', 'reason' => "cannot diff against $base" ) );
}
$changed = array_filter( explode( "\n", trim( $raw ) ) );
foreach ( $changed as $path ) {
	foreach ( BUILD as $re ) {
		if ( preg_match( $re, $path ) ) {
			out( array( 'mode' => 'full', 'reason' => "$path shapes the build or the check" ) );
		}
	}
}

// Every file of the build tree, relative to it.
$shipped = array();
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $build, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( $f->isFile() ) {
		$shipped[] = substr( $f->getPathname(), strlen( $build ) + 1 );
	}
}

$keep = array();
foreach ( $shipped as $rel ) {
	// A changed file, an always-checked one, or one the build generates.
	if ( in_array( $rel, $changed, true ) || in_array( $rel, ALWAYS, true ) || ! file_exists( $rel ) ) {
		$keep[ $rel ] = true;
	}
}
$changed_shipped = array_values( array_filter( $changed, fn( $p ) => in_array( $p, $shipped, true ) ) );
if ( ! $changed_shipped ) {
	out( array( 'mode' => 'skip', 'reason' => 'no shipped file changed' ) );
}

// Collapse: a directory holding no kept file is excluded whole; inside one
// that does, each file not kept is excluded by path.
$dirs_with_keep = array();
foreach ( array_keys( $keep ) as $rel ) {
	for ( $d = dirname( $rel ); '.' !== $d; $d = dirname( $d ) ) {
		$dirs_with_keep[ $d ] = true;
	}
}
$ex_dirs  = array();
$ex_files = array();
foreach ( $shipped as $rel ) {
	if ( isset( $keep[ $rel ] ) ) {
		continue;
	}
	// The topmost ancestor directory with no kept file, if any.
	$top = null;
	for ( $d = dirname( $rel ); '.' !== $d; $d = dirname( $d ) ) {
		if ( ! isset( $dirs_with_keep[ $d ] ) ) {
			$top = $d;
		}
	}
	if ( null !== $top ) {
		$ex_dirs[ $top ] = true;
	} else {
		$ex_files[ $rel ] = true;
	}
}

out(
	array(
		'mode'          => 'scoped',
		'reason'        => sprintf( '%d changed shipped file(s): %s', count( $changed_shipped ), implode( ' ', array_slice( $changed_shipped, 0, 10 ) ) ),
		'exclude-dirs'  => implode( ',', array_keys( $ex_dirs ) ),
		'exclude-files' => implode( ',', array_keys( $ex_files ) ),
	)
);
