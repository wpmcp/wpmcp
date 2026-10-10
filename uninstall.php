<?php
/**
 * Uninstall for the directory builds (issues #468, #480): clears every WP MCP
 * cron event on every site. Settings, tables, snapshots and backups are kept
 * unless the owner turned on "Delete all WP MCP data when the plugin is
 * deleted" (or defined WPMCP_UNINSTALL_DELETE_DATA), in which case every
 * store listed in src/Data_Registry.php is removed on every site.
 *
 * Shipped by scripts/build-wporg-release.sh and scripts/build-woo-release.sh
 * only. The self-hosted build leaves this file out, because WordPress runs
 * uninstall.php instead of the licensing SDK's uninstall hook; that build
 * reaches the same WPMCP\Uninstaller through the SDK's after_uninstall action.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Cron_Registry.php';
require_once __DIR__ . '/src/Data_Registry.php';
require_once __DIR__ . '/src/Uninstaller.php';

\WPMCP\Uninstaller::uninstall();
