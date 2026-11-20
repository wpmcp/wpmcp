<?php
/**
 * Plugin Name: Safe MCP for WooCommerce
 * Description: AI agents run your WooCommerce store over MCP, with a snapshot before every write and one-click rollback.
 * Version: {{VERSION}}
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: safe-mcp-for-woocommerce
 * Domain Path: /languages
 * WPMCP Flavor: woocommerce
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// The full WP MCP plugin already bundles everything this build ships, and the
// two share the WPMCP_* constants, the \WPMCP\ namespace, every option, custom
// table, cron hook and admin menu slug. Running both would double-register the
// abilities and make class resolution depend on which vendor/ autoloader was
// registered first, so this build stands down whenever the full plugin or
// the wp.org directory build is active. The guard ranks builds by the WPMCP
// Flavor header above and reads the active plugin list rather than
// defined( 'WPMCP_VERSION' ) alone, because core loads plugins in sorted
// basename order and 'safe-mcp-for-woocommerce/...' sorts before 'wpmcp/wpmcp.php'
// ('s' is 0x73, 'w' is 0x77): this file normally runs first, so the constant is
// not defined yet at this point.
require_once __DIR__ . '/src/flavor-guard.php';
if ( wpmcp_flavor_should_defer( __FILE__, 'woocommerce', defined( 'WPMCP_VERSION' ) ) ) {
	// No translation loader here (issue #1147): WordPress.org loads this
	// slug's language packs just in time for the notice below.
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Safe MCP for WooCommerce is inactive because another WP MCP build that already includes all of its tools is active on this site.', 'safe-mcp-for-woocommerce' );
		echo '</p></div>';
	} );
	return;
}

define( 'WPMCP_VERSION', '{{VERSION}}' );
define( 'WPMCP_FLAVOR', 'woocommerce' );
// Must match the Text Domain header above. The build rewrites every string
// in src/ to this domain, so the loader has to load into it too (issue #184).
define( 'WPMCP_TEXT_DOMAIN', 'safe-mcp-for-woocommerce' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
require_once __DIR__ . '/vendor/autoload.php';
// Never let the bundled MCP Adapter shadow the canonical plugin or another
// plugin's copy (issue #386); see src/adapter-guard.php.
require_once __DIR__ . '/src/adapter-guard.php';
wpmcp_prefer_shared_mcp_adapter( __DIR__ . '/vendor' );
\WPMCP\Plugin::instance()->boot();
