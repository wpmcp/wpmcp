<?php

/**
 * Inline the Freemius SDK config into the staged wpmcp-fs.php.
 *
 * Freemius's deployment validator reads the uploaded zip statically and
 * insists that fs_dynamic_init() is called with a literal associative array.
 * The source calls it with Bootstrap::config() so the config stays testable
 * and written once, which the validator rejects ("fs_dynamic_init must be
 * used with associative array with SDK params"), so a zip built from source
 * can never be deployed. This runs on the STAGED copy only, after which the
 * shipped file carries the same values Bootstrap::config() returns.
 *
 * Usage: php inline-fs-config.php <repo-root> <stage-root>
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: inline-fs-config.php <repo-root> <stage-root>\n");
    exit(2);
}
[, $root, $stage] = $argv;

$main = (string) file_get_contents($root . '/wpmcp.php');
if (
    ! preg_match("/define\( 'WPMCP_FS_ID', (\d+) \);/", $main, $id)
    || ! preg_match("/define\( 'WPMCP_FS_PUBLIC_KEY', '(pk_[A-Za-z0-9]+)' \);/", $main, $key)
) {
    fwrite(STDERR, "could not read WPMCP_FS_ID / WPMCP_FS_PUBLIC_KEY from wpmcp.php\n");
    exit(1);
}

define('ABSPATH', '/');
define('WPMCP_FS_ID', (int) $id[1]);
define('WPMCP_FS_PUBLIC_KEY', $key[1]);
require $root . '/src/Freemius/Bootstrap.php';

$target = $stage . '/src/Freemius/wpmcp-fs.php';
$source = (string) file_get_contents($target);
$call   = 'fs_dynamic_init(\\WPMCP\\Freemius\\Bootstrap::config())';
if (substr_count($source, $call) !== 1) {
    fwrite(STDERR, "expected exactly one $call in $target\n");
    exit(1);
}

file_put_contents($target, str_replace($call, 'fs_dynamic_init(' . var_export(\WPMCP\Freemius\Bootstrap::config(), true) . ')', $source));
