<?php
/**
 * Plugin Name: wpmcp
 * Description: AI builds and edits your WordPress site, and physically can't wreck it. MCP server + snapshot/rollback safety.
 * Version: 0.8.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: wpmcp
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/src/flavor-guard.php';
// Only one WP MCP build may boot per request: they share the WPMCP_* constants,
// the \WPMCP\ namespace and all persisted state. The full plugin outranks the
// wp.org verticals, so it only stands down for a copy that already loaded.
if ( wpmcp_flavor_should_defer( basename( __FILE__ ), array(), defined( 'WPMCP_VERSION' ) ) ) { return; }
define( 'WPMCP_VERSION', '0.8.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
// Freemius credentials (registered on freemius.com; the public key is public by design).
define( 'WPMCP_FS_ID', 34955 );
define( 'WPMCP_FS_PUBLIC_KEY', 'pk_198c5294157bf7068fd2ffd493957' );
require_once __DIR__ . '/vendor/autoload.php';
\WPMCP\Freemius\Bootstrap::init();
\WPMCP\Plugin::instance()->boot();
