<?php
/**
 * Tables and rows for the activity log ops (issue #698) on the plugin-data
 * pair, for tests that run without WP Activity Log or Simple History loaded.
 *
 * The DDL is what the plugins create (WP Activity Log 5.7.0,
 * classes/Entities/class-occurrences-entity.php and
 * class-metadata-entity.php; Simple History 5.36.0,
 * inc/services/class-setup-database.php), every column with the plugin's
 * own type. DDL commits implicitly, so the tables are created in
 * wpSetUpBeforeClass() and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_activity_log_tables' ) ) {
	function wpmcp_test_activity_log_tables(): array {
		global $wpdb;
		return [
			$wpdb->base_prefix . 'wsal_occurrences',
			$wpdb->base_prefix . 'wsal_metadata',
			$wpdb->prefix . 'simple_history',
			$wpdb->prefix . 'simple_history_contexts',
		];
	}

	function wpmcp_test_create_activity_log_tables(): void {
		global $wpdb;

		wpmcp_test_drop_activity_log_tables();
		$charset = $wpdb->get_charset_collate();
		list( $occ, $meta, $hist, $ctx ) = wpmcp_test_activity_log_tables();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE `{$occ}` (
			`id` bigint NOT NULL AUTO_INCREMENT,
			`site_id` bigint NOT NULL,
			`alert_id` bigint NOT NULL,
			`created_on` double NOT NULL,
			`client_ip` varchar(255) NOT NULL,
			`severity` varchar(255) NOT NULL,
			`object` varchar(255) NOT NULL,
			`event_type` varchar(255) NOT NULL,
			`user_agent` varchar(255) NOT NULL,
			`user_roles` varchar(255) NOT NULL,
			`username` varchar(60) DEFAULT NULL,
			`user_id` bigint DEFAULT NULL,
			`session_id` varchar(255) NOT NULL,
			`post_status` varchar(255) NOT NULL,
			`post_type` varchar(255) NOT NULL,
			`post_id` bigint NOT NULL,
			PRIMARY KEY (`id`),
			KEY `created_on` (`created_on`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE `{$meta}` (
			`id` bigint NOT NULL AUTO_INCREMENT,
			`occurrence_id` bigint(20) NOT NULL,
			`name` varchar(60) NOT NULL,
			`value` longtext NOT NULL,
			PRIMARY KEY (`id`),
			KEY `occurrence_name` (`occurrence_id`,`name`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$hist} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			date datetime NOT NULL,
			logger varchar(30) DEFAULT NULL,
			level varchar(20) DEFAULT NULL,
			message varchar(255) DEFAULT NULL,
			occasionsID varchar(32) DEFAULT NULL,
			initiator varchar(16) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY date (date)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$ctx} (
			context_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			history_id bigint(20) unsigned NOT NULL,
			`key` varchar(255) DEFAULT NULL,
			value longtext,
			PRIMARY KEY  (context_id),
			KEY history_id (history_id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_activity_log_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_activity_log_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- test fixture teardown.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/** A WP Activity Log event with its metadata; returns its id. */
	function wpmcp_test_wsal_event( array $row, array $meta = [] ): int {
		global $wpdb;
		list( $occ, $metat ) = wpmcp_test_activity_log_tables();
		$wpdb->insert( $occ, $row + [
			'site_id'    => 1, 'alert_id' => 2065, 'created_on' => 1794000000.5, 'client_ip' => '203.0.113.7',
			'severity'   => '250', 'object' => 'post', 'event_type' => 'modified',
			'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)', 'user_roles' => 'administrator',
			'username'   => 'ada', 'user_id' => 2, 'session_id' => 'sess-secret-token',
			'post_status' => 'publish', 'post_type' => 'page', 'post_id' => 12,
		] );
		$id = (int) $wpdb->insert_id;
		foreach ( $meta as $name => $value ) {
			$wpdb->insert( $metat, [ 'occurrence_id' => $id, 'name' => $name, 'value' => $value ] );
		}
		return $id;
	}

	/** A Simple History event with its contexts; returns its id. */
	function wpmcp_test_simple_history_event( array $row, array $context = [] ): int {
		global $wpdb;
		list( , , $hist, $ctx ) = wpmcp_test_activity_log_tables();
		$wpdb->insert( $hist, $row + [
			'date' => '2026-11-10 09:30:00', 'logger' => 'SimplePostLogger', 'level' => 'info',
			'message' => 'Updated {post_type} "{post_title}"', 'occasionsID' => md5( (string) wp_rand() ), 'initiator' => 'wp_user',
		] );
		$id = (int) $wpdb->insert_id;
		foreach ( $context as $key => $value ) {
			$wpdb->insert( $ctx, [ 'history_id' => $id, 'key' => $key, 'value' => $value ] );
		}
		return $id;
	}
}
