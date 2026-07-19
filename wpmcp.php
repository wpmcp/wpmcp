<?php
/**
 * Plugin Name: wpmcp
 * Description: AI builds and edits your WordPress site, and physically can't wreck it. MCP server + snapshot/rollback safety.
 * Version: 0.7.32
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: wpmcp
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'WPMCP_VERSION', '0.7.32' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
// Freemius credentials (registered on freemius.com; the public key is public by design).
define( 'WPMCP_FS_ID', 34955 );
define( 'WPMCP_FS_PUBLIC_KEY', 'pk_198c5294157bf7068fd2ffd493957' );
require_once __DIR__ . '/vendor/autoload.php';
\WPMCP\Freemius\Bootstrap::init();
\WPMCP\Plugin::instance()->boot();
