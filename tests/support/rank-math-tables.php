<?php
/**
 * Rank Math's redirections and 404 log tables, for tests that exercise the
 * Rank Math redirects pack without the plugin installed. Columns are copied
 * from the plugin's installer (Installer::create_tables, Rank Math 1.0.282) and
 * checked against the real tables by the live leg (RankMathLiveTest). DDL commits implicitly,
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
			`created` datetime NOT NULL default '0000-00-00 00:00:00',
			`updated` datetime NOT NULL default '0000-00-00 00:00:00',
			`last_accessed` datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY (`id`),
			KEY `status` (`status`),
			KEY `idx_rm_status_updated` (`status`, `updated`)
		) {$wpdb->get_charset_collate()}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}rank_math_404_logs` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`uri` varchar(255) NOT NULL,
			`accessed` datetime NOT NULL default '0000-00-00 00:00:00',
			`times_accessed` bigint(20) unsigned NOT NULL DEFAULT 1,
			`referer` varchar(255) NOT NULL DEFAULT '',
			`user_agent` varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`),
			KEY `uri` (`uri`(191))
		) {$wpdb->get_charset_collate()}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}rank_math_redirections_cache` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`from_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`redirection_id` bigint(20) unsigned NOT NULL,
			`object_id` bigint(20) unsigned NOT NULL default '0',
			`object_type` varchar(10) NOT NULL default 'post',
			`is_redirected` tinyint(1) NOT NULL default '0',
			PRIMARY KEY (`id`),
			KEY `redirection_id` (`redirection_id`)
		) {$wpdb->get_charset_collate()}" );
	}
}

if ( ! function_exists( 'wpmcp_test_drop_rank_math_tables' ) ) {
	function wpmcp_test_drop_rank_math_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rank_math_redirections, {$wpdb->prefix}rank_math_redirections_cache, {$wpdb->prefix}rank_math_404_logs" );
	}
}
