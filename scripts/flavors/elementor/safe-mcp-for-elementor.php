<?php
/**
 * Plugin Name: Safe MCP for Elementor
 * Plugin URI: https://wpmcp-pro.com/
 * Description: Let AI agents edit your Elementor pages and widgets over MCP, with a snapshot before every write and one-click undo. Not affiliated with or endorsed by Elementor Ltd.
 * Version: {{VERSION}}
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: elementor
 * Elementor tested up to: 4.3.4
 * Author: Fahad Murtaza
 * Author URI: https://wpmcp-pro.com/
 * Update URI: https://wpmcp-pro.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpmcp
 * Domain Path: /languages
 * WPMCP Flavor: elementor
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Same tree as the full plugin, flavor-gated at runtime (Plugin::FLAVOR_GROUPS
// and Plugin::FLAVOR_FREE_TOOLS) and carrying the licensing SDK on the same
// product, so a WP MCP Pro licence unlocks everything in this folder in place.
// It shares the WPMCP_* constants, the \WPMCP\ namespace, every option, custom
// table, cron hook, admin menu slug and the licensing SDK's account data with
// the other builds, so it stands down whenever a higher-ranked build (the full
// plugin or the directory build) is active; see src/flavor-guard.php. Core
// loads plugins in sorted basename order and 'safe-mcp-for-elementor/...'
// sorts before 'wpmcp/wpmcp.php', so this file normally runs first and the
// guard reads the active plugin list rather than defined( 'WPMCP_VERSION' ).
require_once __DIR__ . '/src/flavor-guard.php';
if ( wpmcp_flavor_should_defer( __FILE__, 'elementor', defined( 'WPMCP_VERSION' ) ) ) {
	add_action( 'admin_notices', function () {
		// A vertical that took the request defines WPMCP_FLAVOR; the full and
		// directory builds rank higher and carry every tool this one has. A
		// vertical of the same rank (Safe MCP for WooCommerce) does not, so
		// that case says only one of the two can run.
		$tie = defined( 'WPMCP_FLAVOR' ) && wpmcp_flavor_rank( (string) WPMCP_FLAVOR ) === wpmcp_flavor_rank( 'elementor' );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			$tie
				? __( 'Safe MCP for Elementor is inactive because another WP MCP build is active on this site, and only one can run at a time. They share settings and history, so deactivate the one you do not need.', 'wpmcp' )
				: __( 'Safe MCP for Elementor is inactive because another WP MCP build that already includes all of its tools is active on this site.', 'wpmcp' )
		);
		echo '</p></div>';
	} );
	return;
}

define( 'WPMCP_VERSION', '{{VERSION}}' );
define( 'WPMCP_FLAVOR', 'elementor' );
define( 'WPMCP_TEXT_DOMAIN', 'wpmcp' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
// The WP MCP Pro product (same id and public key as wpmcp.php): a WP MCP Pro
// licence is what unlocks this build. The public key is public by design.
define( 'WPMCP_FS_ID', 34955 );
define( 'WPMCP_FS_PUBLIC_KEY', 'pk_198c5294157bf7068fd2ffd493957' );
require_once __DIR__ . '/vendor/autoload.php';
// Never let the bundled MCP Adapter shadow the canonical plugin or another
// plugin's copy (issue #386); see src/adapter-guard.php.
require_once __DIR__ . '/src/adapter-guard.php';
wpmcp_prefer_shared_mcp_adapter( __DIR__ . '/vendor' );
\WPMCP\Freemius\Bootstrap::init();
\WPMCP\Plugin::instance()->boot();
