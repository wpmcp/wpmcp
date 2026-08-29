<?php
/**
 * Plugin Name: WP MCP - MCP Server with Snapshot Undo for AI Agents
 * Plugin URI: https://wpmcp-pro.com/
 * Description: AI builds and edits your WordPress site, and physically can't wreck it. MCP server + snapshot/rollback safety.
 * Version: 0.8.0
 * Requires at least: 6.9
 * Tested up to:      7.1
 * Requires PHP: 8.1
 * Author: Fahad Murtaza
 * Author URI: https://wpmcp-pro.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpmcp
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'WPMCP_VERSION', '0.8.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
// Freemius credentials (registered on freemius.com; the public key is public by design).
define( 'WPMCP_FS_ID', 34955 );
define( 'WPMCP_FS_PUBLIC_KEY', 'pk_198c5294157bf7068fd2ffd493957' );
require_once __DIR__ . '/vendor/autoload.php';
\WPMCP\Freemius\Bootstrap::init();
\WPMCP\Plugin::instance()->boot();
