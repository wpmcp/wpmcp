<?php
/**
 * Plugin Name: WP MCP for WooCommerce
 * Description: AI agents run your WooCommerce store over MCP, with a snapshot before every write and one-click rollback.
 * Version: {{VERSION}}
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: wpmcp-for-woocommerce
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// The full WP MCP plugin already bundles everything this build ships, and the
// two share the WPMCP_* constants, the \WPMCP\ namespace, every option, custom
// table, cron hook and admin menu slug. Running both would double-register the
// abilities and make class resolution depend on which vendor/ autoloader was
// registered first, so this build stands down whenever the full plugin is
// active. The check reads the active plugin list rather than
// defined( 'WPMCP_VERSION' ) alone, because core loads plugins in sorted
// basename order and 'wpmcp-for-woocommerce/...' sorts before 'wpmcp/wpmcp.php'
// ('-' is 0x2D, '/' is 0x2F): this file normally runs first, so the constant is
// not defined yet at this point.
require_once __DIR__ . '/src/flavor-guard.php';
if ( wpmcp_flavor_should_defer( basename( __FILE__ ), array( 'wpmcp.php' ), defined( 'WPMCP_VERSION' ) ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WP MCP for WooCommerce is inactive because the full WP MCP plugin is already active and includes all of its tools.', 'wpmcp-for-woocommerce' );
		echo '</p></div>';
	} );
	return;
}

define( 'WPMCP_VERSION', '{{VERSION}}' );
define( 'WPMCP_FLAVOR', 'woocommerce' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
require_once __DIR__ . '/vendor/autoload.php';
\WPMCP\Plugin::instance()->boot();
