<?php
/**
 * Uninstall for the directory builds (issues #468, #480): clears every WP MCP
 * cron event on every site. Settings, tables, snapshots and backups are kept
 * unless the owner turned on "Delete all WP MCP data when the plugin is
 * deleted" (or defined WPMCP_UNINSTALL_DELETE_DATA), in which case every
 * store listed in src/Data_Registry.php is removed on every site.
 *
 * Nothing at all happens while another WP MCP build is installed (the full
 * plugin, the directory build or another flavor): they share every option,
 * table and cron hook, and the build that stays still needs them. The check
 * is in WPMCP\Uninstaller::uninstall(), so the licensing SDK's path below
 * gets it too.
 *
 * Shipped by scripts/build-wporg-release.sh, scripts/build-woo-release.sh and
 * scripts/build-elementor-release.sh. The self-hosted build leaves this file
 * out, because WordPress runs uninstall.php instead of the licensing SDK's
 * uninstall hook; that build reaches the same WPMCP\Uninstaller through the
 * SDK's after_uninstall action. The Elementor flavor carries the SDK but
 * ships this file on purpose: it shares the SDK's licence install with the
 * full plugin, and the SDK's hook would report that install as uninstalled.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/flavor-guard.php';
require_once __DIR__ . '/src/Cron_Registry.php';
require_once __DIR__ . '/src/Data_Registry.php';
require_once __DIR__ . '/src/Uninstaller.php';

\WPMCP\Uninstaller::uninstall();
