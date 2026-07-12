<?php
/**
 * Plugin Name: wpmcp
 * Description: AI builds and edits your WordPress site — and physically can't wreck it. MCP server + snapshot/rollback safety.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: wpmcp
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'WPMCP_VERSION', '0.1.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
require_once __DIR__ . '/vendor/autoload.php';
\WPMCP\Freemius\Bootstrap::init();
\WPMCP\Plugin::instance()->boot();
