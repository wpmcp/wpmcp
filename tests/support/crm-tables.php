<?php
/**
 * The FluentCRM and MailPoet tables the CRM ops read and write (issue #477),
 * for tests that exercise them without either plugin installed in the
 * shared test core.
 *
 * Copied from the tables FluentCRM 3.2.5 and MailPoet 5.41.0 create on
 * activation (SHOW CREATE TABLE on a live install), with the plain indexes
 * left out; the primary and unique keys stay, since the restore depends on
 * them.
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass(), outside the
 * per-test transaction. Rows written inside a test still roll back with the
 * test's transaction.
 */

if ( ! function_exists( 'wpmcp_test_crm_tables' ) ) {
	/** @return string[] every CRM table the fixture creates, unprefixed. */
	function wpmcp_test_crm_tables(): array {
		return [
			'fc_subscribers',
			'fc_subscriber_pivot',
			'fc_subscriber_meta',
			'fc_subscriber_notes',
			'fc_lists',
			'fc_tags',
			'fc_meta',
			'fc_campaigns',
			'fc_funnels',
			'fc_funnel_subscribers',
			'fc_funnel_metrics',
			'fc_campaign_emails',
			'fc_campaign_url_metrics',
			'mailpoet_subscribers',
			'mailpoet_subscriber_segment',
			'mailpoet_segments',
			'mailpoet_tags',
			'mailpoet_subscriber_tag',
			'mailpoet_custom_fields',
			'mailpoet_subscriber_custom_field',
			'mailpoet_newsletters',
			'mailpoet_newsletter_segment',
			'mailpoet_settings',
			'mailpoet_sending_queues',
			'mailpoet_scheduled_tasks',
			'mailpoet_automations',
			'mailpoet_automation_runs',
		];
	}
}

if ( ! function_exists( 'wpmcp_test_create_crm_tables' ) ) {
	function wpmcp_test_create_crm_tables(): void {
		global $wpdb;

		wpmcp_test_drop_crm_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}fc_subscribers (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) unsigned DEFAULT NULL,
			`hash` varchar(90) DEFAULT NULL,
			`contact_owner` bigint(20) unsigned DEFAULT NULL,
			`company_id` bigint(20) unsigned DEFAULT NULL,
			`prefix` varchar(192) DEFAULT NULL,
			`first_name` varchar(192) DEFAULT NULL,
			`last_name` varchar(192) DEFAULT NULL,
			`email` varchar(190) NOT NULL,
			`timezone` varchar(192) DEFAULT NULL,
			`address_line_1` varchar(192) DEFAULT NULL,
			`address_line_2` varchar(192) DEFAULT NULL,
			`postal_code` varchar(192) DEFAULT NULL,
			`city` varchar(192) DEFAULT NULL,
			`state` varchar(192) DEFAULT NULL,
			`country` varchar(192) DEFAULT NULL,
			`ip` varchar(40) DEFAULT NULL,
			`latitude` decimal(10,8) DEFAULT NULL,
			`longitude` decimal(10,8) DEFAULT NULL,
			`total_points` int(10) unsigned NOT NULL DEFAULT 0,
			`life_time_value` int(10) unsigned NOT NULL DEFAULT 0,
			`phone` varchar(50) DEFAULT NULL,
			`status` varchar(50) NOT NULL DEFAULT 'subscribed',
			`contact_type` varchar(50) DEFAULT 'lead',
			`source` varchar(50) DEFAULT NULL,
			`avatar` varchar(192) DEFAULT NULL,
			`date_of_birth` date DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`last_activity` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`email`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_subscriber_pivot (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`subscriber_id` bigint(20) unsigned NOT NULL,
			`object_id` bigint(20) unsigned NOT NULL,
			`object_type` varchar(50) NOT NULL,
			`status` varchar(50) DEFAULT NULL,
			`is_public` tinyint(1) NOT NULL DEFAULT 1,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`subscriber_id`,`object_id`,`object_type`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_subscriber_meta (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`subscriber_id` bigint(20) unsigned NOT NULL,
			`created_by` bigint(20) unsigned NOT NULL,
			`object_type` varchar(50) DEFAULT 'option',
			`key` varchar(192) NOT NULL,
			`value` longtext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_subscriber_notes (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`subscriber_id` bigint(20) unsigned NOT NULL,
			`parent_id` bigint(20) unsigned DEFAULT NULL,
			`created_by` bigint(20) unsigned DEFAULT NULL,
			`status` varchar(50) DEFAULT 'open',
			`type` varchar(50) DEFAULT 'note',
			`is_private` tinyint(4) DEFAULT 1,
			`title` varchar(192) DEFAULT NULL,
			`description` longtext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_lists (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`title` varchar(192) NOT NULL,
			`slug` varchar(192) NOT NULL,
			`description` tinytext DEFAULT NULL,
			`is_public` tinyint(1) DEFAULT 0,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_tags (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`title` varchar(192) NOT NULL,
			`slug` varchar(192) NOT NULL,
			`description` tinytext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_meta (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`object_type` varchar(50) NOT NULL,
			`object_id` bigint(20) DEFAULT NULL,
			`key` varchar(192) NOT NULL,
			`value` longtext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_campaigns (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`parent_id` bigint(20) unsigned DEFAULT NULL,
			`type` varchar(50) NOT NULL DEFAULT 'campaign',
			`title` varchar(192) NOT NULL,
			`available_urls` text DEFAULT NULL,
			`slug` varchar(192) NOT NULL,
			`status` varchar(50) NOT NULL,
			`template_id` bigint(20) unsigned DEFAULT NULL,
			`email_subject` varchar(192) DEFAULT NULL,
			`email_pre_header` varchar(192) DEFAULT NULL,
			`email_body` longtext NOT NULL,
			`recipients_count` int(11) NOT NULL DEFAULT 0,
			`delay` int(11) DEFAULT 0,
			`utm_status` tinyint(1) DEFAULT 0,
			`utm_source` varchar(192) DEFAULT NULL,
			`utm_medium` varchar(192) DEFAULT NULL,
			`utm_campaign` varchar(192) DEFAULT NULL,
			`utm_term` varchar(192) DEFAULT NULL,
			`utm_content` varchar(192) DEFAULT NULL,
			`design_template` varchar(192) DEFAULT NULL,
			`scheduled_at` timestamp NULL DEFAULT NULL,
			`settings` longtext DEFAULT NULL,
			`created_by` bigint(20) unsigned DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_funnels (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`type` varchar(50) NOT NULL DEFAULT 'funnel',
			`title` varchar(192) NOT NULL,
			`trigger_name` varchar(150) DEFAULT NULL,
			`status` varchar(50) DEFAULT 'draft',
			`conditions` text DEFAULT NULL,
			`settings` text DEFAULT NULL,
			`created_by` bigint(20) unsigned DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_funnel_subscribers (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`funnel_id` bigint(20) unsigned DEFAULT NULL,
			`starting_sequence_id` bigint(20) unsigned DEFAULT NULL,
			`next_sequence` bigint(20) unsigned DEFAULT NULL,
			`subscriber_id` bigint(20) unsigned DEFAULT NULL,
			`last_sequence_id` bigint(20) unsigned DEFAULT NULL,
			`next_sequence_id` bigint(20) unsigned DEFAULT NULL,
			`last_sequence_status` varchar(50) DEFAULT 'pending',
			`status` varchar(50) DEFAULT 'active',
			`type` varchar(50) DEFAULT 'funnel',
			`last_executed_time` timestamp NULL DEFAULT NULL,
			`next_execution_time` timestamp NULL DEFAULT NULL,
			`notes` text DEFAULT NULL,
			`source_trigger_name` varchar(192) DEFAULT NULL,
			`source_ref_id` bigint(20) unsigned DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`funnel_id`,`subscriber_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_funnel_metrics (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`funnel_id` bigint(20) unsigned DEFAULT NULL,
			`sequence_id` bigint(20) unsigned DEFAULT NULL,
			`subscriber_id` bigint(20) unsigned DEFAULT NULL,
			`benchmark_value` bigint(20) unsigned DEFAULT 0,
			`benchmark_currency` varchar(10) DEFAULT 'USD',
			`status` varchar(50) DEFAULT 'completed',
			`notes` text DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`funnel_id`,`sequence_id`,`subscriber_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_campaign_emails (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`campaign_id` bigint(20) unsigned DEFAULT NULL,
			`email_type` varchar(50) DEFAULT 'campaign',
			`subscriber_id` bigint(20) unsigned DEFAULT NULL,
			`email_subject_id` bigint(20) unsigned DEFAULT NULL,
			`email_address` varchar(192) NOT NULL,
			`email_subject` varchar(192) DEFAULT NULL,
			`email_body` longtext DEFAULT NULL,
			`email_headers` text DEFAULT NULL,
			`is_open` tinyint(1) NOT NULL DEFAULT 0,
			`is_parsed` tinyint(1) NOT NULL DEFAULT 0,
			`click_counter` int(11) DEFAULT NULL,
			`status` varchar(50) NOT NULL DEFAULT 'draft',
			`note` text DEFAULT NULL,
			`scheduled_at` timestamp NULL DEFAULT NULL,
			`email_hash` varchar(192) DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}fc_campaign_url_metrics (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`url_id` bigint(20) unsigned DEFAULT NULL,
			`campaign_id` bigint(20) unsigned DEFAULT NULL,
			`subscriber_id` bigint(20) unsigned DEFAULT NULL,
			`type` varchar(50) DEFAULT 'click',
			`ip_address` varchar(30) DEFAULT NULL,
			`country` varchar(40) DEFAULT NULL,
			`city` varchar(40) DEFAULT NULL,
			`counter` int(10) unsigned NOT NULL DEFAULT 1,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_subscribers (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`wp_user_id` bigint(20) DEFAULT NULL,
			`is_woocommerce_user` int(1) NOT NULL DEFAULT 0,
			`first_name` varchar(255) NOT NULL DEFAULT '',
			`last_name` varchar(255) NOT NULL DEFAULT '',
			`email` varchar(150) NOT NULL,
			`status` varchar(12) NOT NULL DEFAULT 'unconfirmed',
			`subscribed_ip` varchar(45) DEFAULT NULL,
			`confirmed_ip` varchar(45) DEFAULT NULL,
			`confirmed_at` timestamp NULL DEFAULT NULL,
			`last_subscribed_at` timestamp NULL DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`deleted_at` timestamp NULL DEFAULT NULL,
			`unconfirmed_data` longtext DEFAULT NULL,
			`source` enum('form','imported','administrator','api','wordpress_user','wordpress_user_deleted','woocommerce_user','woocommerce_checkout','unknown') DEFAULT 'unknown',
			`count_confirmations` int(11) unsigned NOT NULL DEFAULT 0,
			`unsubscribe_token` char(15) DEFAULT NULL,
			`link_token` char(32) DEFAULT NULL,
			`engagement_score` float unsigned DEFAULT NULL,
			`engagement_score_updated_at` timestamp NULL DEFAULT NULL,
			`last_engagement_at` timestamp NULL DEFAULT NULL,
			`woocommerce_synced_at` timestamp NULL DEFAULT NULL,
			`email_count` int(11) unsigned NOT NULL DEFAULT 0,
			`last_sending_at` timestamp NULL DEFAULT NULL,
			`last_open_at` timestamp NULL DEFAULT NULL,
			`last_click_at` timestamp NULL DEFAULT NULL,
			`last_purchase_at` timestamp NULL DEFAULT NULL,
			`last_page_view_at` timestamp NULL DEFAULT NULL,
			`last_confirmation_email_sent_at` timestamp NULL DEFAULT NULL,
			`time_zone` varchar(64) DEFAULT NULL,
			`time_zone_source` varchar(32) DEFAULT NULL,
			`time_zone_confidence` int(11) DEFAULT NULL,
			`time_zone_updated_at` timestamp NULL DEFAULT NULL,
			`segments_count` int(10) unsigned NOT NULL DEFAULT 0,
			`tracking_consent` varchar(20) NOT NULL DEFAULT 'unknown',
			`tracking_consent_updated_at` timestamp NULL DEFAULT NULL,
			`tracking_consent_method` varchar(40) DEFAULT NULL,
			`tracking_consent_copy` text DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`email`),
			UNIQUE KEY (`unsubscribe_token`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_subscriber_segment (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`subscriber_id` int(11) unsigned NOT NULL,
			`segment_id` int(11) unsigned NOT NULL,
			`status` varchar(12) NOT NULL DEFAULT 'subscribed',
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			PRIMARY KEY (`id`),
			UNIQUE KEY (`subscriber_id`,`segment_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_segments (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(90) NOT NULL,
			`type` varchar(90) NOT NULL DEFAULT 'default',
			`description` varchar(250) NOT NULL DEFAULT '',
			`public_description` text DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`deleted_at` timestamp NULL DEFAULT NULL,
			`average_engagement_score` float unsigned DEFAULT NULL,
			`average_engagement_score_updated_at` timestamp NULL DEFAULT NULL,
			`display_in_manage_subscription_page` tinyint(1) NOT NULL DEFAULT 0,
			`confirmation_email_id` int(11) unsigned DEFAULT NULL,
			`confirmation_page_id` int(11) unsigned DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`name`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_tags (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(191) NOT NULL,
			`description` text NOT NULL DEFAULT '',
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			PRIMARY KEY (`id`),
			UNIQUE KEY (`name`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_subscriber_tag (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`subscriber_id` int(11) unsigned NOT NULL,
			`tag_id` int(11) unsigned NOT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			PRIMARY KEY (`id`),
			UNIQUE KEY (`subscriber_id`,`tag_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_custom_fields (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(90) NOT NULL,
			`type` varchar(90) NOT NULL,
			`params` longtext NOT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`deleted_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`name`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_subscriber_custom_field (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`subscriber_id` int(11) unsigned NOT NULL,
			`custom_field_id` int(11) unsigned NOT NULL,
			`value` text NOT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			PRIMARY KEY (`id`),
			UNIQUE KEY (`subscriber_id`,`custom_field_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_newsletters (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`hash` varchar(150) DEFAULT NULL,
			`parent_id` int(11) unsigned DEFAULT NULL,
			`subject` varchar(250) NOT NULL DEFAULT '',
			`type` varchar(150) NOT NULL DEFAULT 'standard',
			`sender_address` varchar(150) NOT NULL DEFAULT '',
			`sender_name` varchar(150) NOT NULL DEFAULT '',
			`status` varchar(20) NOT NULL DEFAULT 'draft',
			`reply_to_address` varchar(150) NOT NULL DEFAULT '',
			`reply_to_name` varchar(150) NOT NULL DEFAULT '',
			`preheader` varchar(250) NOT NULL DEFAULT '',
			`body` longtext DEFAULT NULL,
			`sent_at` timestamp NULL DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`deleted_at` timestamp NULL DEFAULT NULL,
			`unsubscribe_token` char(15) DEFAULT NULL,
			`ga_campaign` varchar(250) NOT NULL DEFAULT '',
			`wp_post_id` int(11) DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY (`unsubscribe_token`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_newsletter_segment (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`newsletter_id` int(11) unsigned NOT NULL,
			`segment_id` int(11) unsigned NOT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			PRIMARY KEY (`id`),
			UNIQUE KEY (`newsletter_id`,`segment_id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_settings (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(50) NOT NULL,
			`value` longtext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			PRIMARY KEY (`id`),
			UNIQUE KEY (`name`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_sending_queues (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`task_id` int(11) unsigned NOT NULL,
			`newsletter_id` int(11) unsigned DEFAULT NULL,
			`newsletter_rendered_body` longtext DEFAULT NULL,
			`newsletter_rendered_subject` varchar(250) DEFAULT NULL,
			`subscribers` longtext DEFAULT NULL,
			`count_total` int(11) unsigned NOT NULL DEFAULT 0,
			`count_processed` int(11) unsigned NOT NULL DEFAULT 0,
			`count_to_process` int(11) unsigned NOT NULL DEFAULT 0,
			`meta` longtext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`deleted_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_scheduled_tasks (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`type` varchar(90) DEFAULT NULL,
			`status` varchar(12) DEFAULT NULL,
			`priority` mediumint(9) NOT NULL DEFAULT 0,
			`scheduled_at` timestamp NULL DEFAULT NULL,
			`processed_at` timestamp NULL DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`deleted_at` timestamp NULL DEFAULT NULL,
			`in_progress` int(1) DEFAULT NULL,
			`reschedule_count` int(11) NOT NULL DEFAULT 0,
			`meta` longtext DEFAULT NULL,
			`cancelled_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_automations (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(191) NOT NULL,
			`author` bigint(20) NOT NULL,
			`status` varchar(191) NOT NULL,
			`meta` longtext DEFAULT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`activated_at` timestamp NULL DEFAULT NULL,
			`deleted_at` timestamp NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}mailpoet_automation_runs (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`automation_id` int(11) unsigned NOT NULL,
			`version_id` int(11) unsigned NOT NULL,
			`trigger_key` varchar(191) NOT NULL,
			`status` varchar(191) NOT NULL,
			`created_at` timestamp NULL DEFAULT NULL,
			`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			`next_step_id` varchar(191) DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

if ( ! function_exists( 'wpmcp_test_drop_crm_tables' ) ) {
	function wpmcp_test_drop_crm_tables(): void {
		global $wpdb;

		foreach ( wpmcp_test_crm_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		}
	}
}
