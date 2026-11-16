<?php
/**
 * Tables and rows for the email log ops (issue #905) on the plugin-data
 * pair, for tests that run without WP Mail Logging, Check and Log Email or
 * Email Log loaded.
 *
 * The DDL is what the plugins create (WP Mail Logging 1.17.1,
 * src/WPML_Plugin.php installDatabaseTables(); Check and Log Email 2.0.17,
 * include/install.php, include/helper-function.php and the upgrade in
 * Check_Email_Table_Manager::get_create_table_query(); Email Log 2.64,
 * include/Core/DB/TableManager.php), every column with the plugin's own
 * type. DDL commits implicitly, so the tables are created in
 * wpSetUpBeforeClass() and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_email_log_tables' ) ) {
	function wpmcp_test_email_log_tables(): array {
		global $wpdb;
		return [
			$wpdb->prefix . 'wpml_mails',
			$wpdb->prefix . 'check_email_log',
			$wpdb->prefix . 'check_email_error_logs',
			$wpdb->prefix . 'email_log',
		];
	}

	/**
	 * Creates the four tables. $upgraded_check_email false builds Check and
	 * Log Email's table as install.php does, before its own upgrade adds
	 * result, error_message, attachment_name and ip_address.
	 */
	function wpmcp_test_create_email_log_tables( bool $upgraded_check_email = true ): void {
		global $wpdb;

		wpmcp_test_drop_email_log_tables();
		$charset = $wpdb->get_charset_collate();
		list( $wpml, $check, $errors, $el ) = wpmcp_test_email_log_tables();
		$extra = $upgraded_check_email ? ',
			attachment_name VARCHAR(1000),
			ip_address VARCHAR(15),
			result TINYINT(1),
			error_message VARCHAR(1000)' : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE `{$wpml}` (
			`mail_id` INT NOT NULL AUTO_INCREMENT,
			`timestamp` TIMESTAMP NOT NULL,
			`host` VARCHAR(200) NOT NULL DEFAULT '0',
			`receiver` VARCHAR(200) NOT NULL DEFAULT '0',
			`subject` VARCHAR(200) NOT NULL DEFAULT '0',
			`message` TEXT NULL,
			`headers` TEXT NULL,
			`attachments` VARCHAR(800) NOT NULL DEFAULT '0',
			`error` VARCHAR(400) NULL DEFAULT '',
			`plugin_version` VARCHAR(200) NOT NULL DEFAULT '0',
			PRIMARY KEY (`mail_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE `{$check}` (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			to_email VARCHAR(100) NOT NULL,
			subject VARCHAR(250) NOT NULL,
			message TEXT NOT NULL,
			backtrace_segment TEXT NOT NULL,
			open_tracking_id VARCHAR(250) NOT NULL,
			open_count VARCHAR(250) NOT NULL,
			headers TEXT NOT NULL,
			attachments TEXT NOT NULL,
			sent_date timestamp NOT NULL{$extra},
			PRIMARY KEY  (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE `{$errors}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`check_email_log_id` INT DEFAULT NULL,
			`content` TEXT DEFAULT NULL,
			`initiator` TEXT DEFAULT NULL,
			`event_type` TINYINT UNSIGNED NOT NULL DEFAULT '0',
			`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE `{$el}` (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			to_email VARCHAR(500) NOT NULL,
			subject VARCHAR(500) NOT NULL,
			message TEXT NOT NULL,
			headers TEXT NOT NULL,
			attachments TEXT NOT NULL,
			sent_date timestamp NOT NULL,
			attachment_name VARCHAR(1000),
			ip_address VARCHAR(15),
			result TINYINT(1),
			error_message VARCHAR(1000),
			PRIMARY KEY  (id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_email_log_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_email_log_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- test fixture teardown.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/** A WP Mail Logging row (an empty error is a sent mail); returns its id. */
	function wpmcp_test_wpml_mail( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wpml_mails', $row + [
			'timestamp' => '2026-11-10 09:30:00', 'host' => '127.0.0.1', 'receiver' => 'Ada Lovelace <ada@example.com>',
			'subject' => 'Order received', 'message' => 'Hello Ada', 'headers' => "Content-Type: text/html\nFrom: shop@example.com",
			'attachments' => '0', 'error' => '', 'plugin_version' => '1.17.1',
		] );
		return (int) $wpdb->insert_id;
	}

	/** A Check and Log Email row; returns its id. Extra columns need the upgraded table. */
	function wpmcp_test_check_email_mail( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'check_email_log', $row + [
			'to_email' => 'ada@example.com', 'subject' => 'Order received', 'message' => 'Hello Ada',
			'backtrace_segment' => '', 'open_tracking_id' => '', 'open_count' => '', 'headers' => 'From: shop@example.com',
			'attachments' => 'false', 'sent_date' => '2026-11-10 09:30:00',
		] );
		return (int) $wpdb->insert_id;
	}

	/** An Email Log row; returns its id. */
	function wpmcp_test_email_log_mail( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'email_log', $row + [
			'to_email' => 'ada@example.com', 'subject' => 'Order received', 'message' => 'Hello Ada', 'headers' => 'From: shop@example.com',
			'attachments' => 'false', 'sent_date' => '2026-11-10 09:30:00', 'attachment_name' => '', 'ip_address' => '203.0.113.7',
			'result' => 1, 'error_message' => '',
		] );
		return (int) $wpdb->insert_id;
	}
}
