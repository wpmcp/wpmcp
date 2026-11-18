<?php
/**
 * Tests that read the whole source tree (registry and guard tests such as
 * OptInSettingsTest, which collects every opt_in_filter under src/). No
 * per-test coverage map links them to a new file, so impact selection never
 * picked them for a PR that adds one, and #990 merged with OptInSettingsTest
 * red on main (#1016). impact.php runs every test this finds on every PR.
 *
 * A test counts when its source walks a directory (RecursiveDirectoryIterator,
 * glob, scandir, git ls-files) and names src/ as a path.
 */

/**
 * @param string[] $test_files Paths of test files, relative to the repo root.
 * @return string[] The ones that scan src/.
 */
function ci_src_scanning_tests( array $test_files ): array {
	$out = array();
	foreach ( $test_files as $file ) {
		$src = (string) @file_get_contents( $file );
		$walks = preg_match( '/RecursiveDirectoryIterator|\bglob\s*\(|\bscandir\s*\(|ls-files/', $src );
		$names = preg_match( '#[\'"/]src[\'"/]|\'/src\b|"src"|\'src\'#', $src );
		if ( $walks && $names ) {
			$out[] = $file;
		}
	}
	return $out;
}
