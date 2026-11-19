<?php
/**
 * FileBird's tables (issue #906), for tests that run without the plugin
 * loaded. The DDL is FileBird 6.5.9's own, includes/Install.php:
 *  - {prefix}fbv: one row per folder (id, name, parent with 0 for the root,
 *    type 0 folder or 1 collection, ord, created_by, the owner in per-user
 *    mode and 0 otherwise);
 *  - {prefix}fbv_attachment_folder: one row per attachment in a folder,
 *    primary key (folder_id, attachment_id). An attachment with no row is
 *    "Uncategorized".
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_filebird_tables' ) ) {
	function wpmcp_test_create_filebird_tables(): void {
		global $wpdb;

		wpmcp_test_drop_filebird_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}fbv (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(250) NOT NULL,
			`parent` int(11) NOT NULL DEFAULT 0,
			`type` int(2) NOT NULL DEFAULT 0,
			`ord` int(11) NULL DEFAULT 0,
			`created_by` int(11) NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY `id` (id)) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fbv_attachment_folder (
			`folder_id` int(11) unsigned NOT NULL,
			`attachment_id` bigint(20) unsigned NOT NULL,
			PRIMARY KEY( `folder_id`, `attachment_id`)
			) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_filebird_tables(): void {
		global $wpdb;
		foreach ( [ 'fbv', 'fbv_attachment_folder' ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	function wpmcp_test_clear_filebird_tables(): void {
		global $wpdb;
		foreach ( [ 'fbv', 'fbv_attachment_folder' ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $table ) );
		}
	}
}
