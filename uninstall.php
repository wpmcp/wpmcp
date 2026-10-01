<?php
/**
 * Uninstall for the directory builds (issue #468): clears every WP MCP cron
 * event on every site. Settings, tables and snapshots are kept.
 *
 * Shipped by scripts/build-wporg-release.sh and scripts/build-woo-release.sh
 * only. The self-hosted build leaves this file out, because WordPress runs
 * uninstall.php instead of the licensing SDK's uninstall hook; that build
 * reaches the same WPMCP\Uninstaller through the SDK's after_uninstall action.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Cron_Registry.php';
require_once __DIR__ . '/src/Uninstaller.php';

\WPMCP\Uninstaller::uninstall();
