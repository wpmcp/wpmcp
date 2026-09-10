<?php
/**
 * Plugin Name: wpmcp
 * Description: AI builds and edits your WordPress site, and physically can't wreck it. MCP server + snapshot/rollback safety.
 * Version: 0.8.1
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: wpmcp
 * Domain Path: /languages
 * WPMCP Flavor: full
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/src/flavor-guard.php';
// Only one WP MCP build may boot per request: they share the WPMCP_* constants,
// the \WPMCP\ namespace and all persisted state. The guard ranks builds by the
// WPMCP Flavor header above, and 'full' ranks highest, so this file only
// stands down for a copy that already booted this request (a second install
// of the full plugin in another directory, or a vertical booted in the same
// request that activates this file). That return also skips Plugin::boot(),
// so register_activation_hook() is not reached and Activator::activate does
// not run for this activation. Acceptable: every table it would create is
// shared with the copy that booted, which created them on its own activation.
// A schema change only this copy knows about waits until it is deactivated
// and reactivated after the other build is gone.
if ( wpmcp_flavor_should_defer( __FILE__, 'full', defined( 'WPMCP_VERSION' ) ) ) {
	// No load_plugin_textdomain() here: Plugin::load_textdomain() is the one
	// loader (ListingRulesTest pins it), the copy that booted loads the
	// 'wpmcp' domain itself, and core's just-in-time loader covers the notice.
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'This copy of WP MCP is inactive because another WP MCP build already loaded on this site. Deactivate one of them.', 'wpmcp' );
		echo '</p></div>';
	} );
	return;
}
define( 'WPMCP_VERSION', '0.8.1' );
// Must match the Text Domain header above: Plugin::load_textdomain() loads
// the self-hosted .mo from languages/ into this domain (issue #184).
define( 'WPMCP_TEXT_DOMAIN', 'wpmcp' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
// Freemius credentials (registered on freemius.com; the public key is public by design).
define( 'WPMCP_FS_ID', 34955 );
define( 'WPMCP_FS_PUBLIC_KEY', 'pk_198c5294157bf7068fd2ffd493957' );
require_once __DIR__ . '/vendor/autoload.php';
\WPMCP\Freemius\Bootstrap::init();
\WPMCP\Plugin::instance()->boot();
