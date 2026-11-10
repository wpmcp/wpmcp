<?php
/**
 * Issue #600 probe, run by ManageUpdatesFreshRequestTest in its own PHP
 * process: boot WordPress the way a REST (MCP) request does, with none of
 * wp-admin/includes loaded and no other plugin to load them, then ask
 * Manage_Updates for the effective auto-update setting it reports after a
 * toggle. The suite process cannot show this, because plugins in the
 * harness load wp-admin/includes/update.php long before any test runs.
 *
 * Usage: php auto-update-effective.php <wp-tests-config.php> <repo root>
 * Prints one JSON object: preloaded, plugin, theme, error.
 */

// phpcs:disable -- a standalone test probe, not plugin code.

if (PHP_SAPI !== 'cli' || $argc < 3) {
    exit(2);
}

define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
$_SERVER['HTTP_HOST']   = 'example.org';
$_SERVER['SERVER_NAME'] = 'example.org';
$_SERVER['REQUEST_URI'] = '/wp-json/';

require $argv[1];
require ABSPATH . 'wp-settings.php';
require $argv[2] . '/vendor/autoload.php';

$out = [
    'preloaded' => function_exists('wp_is_auto_update_enabled_for_type'),
    'plugin'    => null,
    'theme'     => null,
    'error'     => null,
];

try {
    $out['plugin'] = \WPMCP\Tools\Packages\Manage_Updates::auto_update_effective('plugin');
    $out['theme']  = \WPMCP\Tools\Packages\Manage_Updates::auto_update_effective('theme');
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out);
