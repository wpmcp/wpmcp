<?php
/**
 * Rank Math's redirections and 404 log tables, for tests that exercise the
 * Rank Math redirects pack without the plugin installed. Columns follow the
 * plugin's installer (includes/class-installer.php). DDL commits implicitly,
 * so callers create them in wpSetUpBeforeClass() and drop them after the class.
 */

if ( ! function_exists( 'wpmcp_test_create_rank_math_tables' ) ) {
	function wpmcp_test_create_rank_math_tables(): void {
		global $wpdb;

		wpmcp_test_drop_rank_math_tables();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}rank_math_redirections` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`sources` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`url_to` text NOT NULL,
			`header_code` smallint(4) unsigned NOT NULL,
			`hits` bigint(20) unsigned NOT NULL DEFAULT 0,
			`status` varchar(25) NOT NULL DEFAULT 'active',
			`created` datetime NOT NULL,
			`updated` datetime NOT NULL,
			`last_accessed` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `status` (`status`)
		) {$wpdb->get_charset_collate()}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}rank_math_404_logs` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`uri` varchar(255) NOT NULL,
			`accessed` datetime NOT NULL,
			`times_accessed` bigint(20) unsigned NOT NULL DEFAULT 1,
			`referer` varchar(255) NOT NULL DEFAULT '',
			`user_agent` varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`)
		) {$wpdb->get_charset_collate()}" );
	}
}

if ( ! function_exists( 'wpmcp_test_drop_rank_math_tables' ) ) {
	function wpmcp_test_drop_rank_math_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rank_math_redirections, {$wpdb->prefix}rank_math_404_logs" );
	}
}
