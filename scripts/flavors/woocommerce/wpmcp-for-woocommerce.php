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
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// The full WP MCP plugin already bundles everything this build ships.
// Running both would double-register the same abilities, so defer to it.
if ( defined( 'WPMCP_VERSION' ) ) {
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
