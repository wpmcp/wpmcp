<?php
/**
 * Test fixtures for Thrive Ultimatum (issue #828), recorded from the plugin's
 * own code (10.9.1.4), so the pack's tests run without the commercial
 * plugin:
 *  - a campaign is a tve_ult_campaign post (always post_status publish) with
 *    tve_ult_campaign_type, tve_ult_rolling_type, tve_ult_status (running or
 *    paused), tve_ult_campaign_settings, tve_campaign_order and the lockdown
 *    metas (inc/data.php tve_ult_save_campaign());
 *  - {prefix}tve_ult_events holds the timeline (db/install-1.0.php), one
 *    'start' row per campaign whose days and hours are the campaign length;
 *  - {prefix}tve_ult_designs holds the designs and their states, with the
 *    editor fields (tpl and so on) serialized in tcb_fields;
 *  - {prefix}tve_ult_settings_campaign holds one display settings row per
 *    campaign, show_options and hide_options as JSON {"tabs":[{"options":
 *    [...]}, ...]} in the tab order of the display settings screen;
 *  - {prefix}tve_ult_event_log counts impressions (type 1) and conversions
 *    (type 2); {prefix}tve_ult_emails holds per-visitor evergreen state and
 *    is created here only so a test can prove nothing reads it.
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL and seeding.

if ( ! function_exists( 'wpmcp_test_create_ultimatum_tables' ) ) {

	function wpmcp_test_create_ultimatum_tables(): void {
		global $wpdb;
		$p       = $wpdb->prefix . 'tve_ult_';
		$charset = $wpdb->get_charset_collate();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}events (
			`id` BIGINT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
			`days` INT(5) UNSIGNED NOT NULL DEFAULT 0,
			`hours` INT(5) UNSIGNED NOT NULL DEFAULT 0,
			`trigger_options` TEXT NULL,
			`actions` TEXT NULL,
			`type` ENUM('time','conv','start') NULL DEFAULT 'time'
		) {$charset}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}designs (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`post_parent` BIGINT(20) NOT NULL,
			`post_status` VARCHAR(20) NOT NULL DEFAULT 'publish',
			`post_type` VARCHAR(20) NOT NULL,
			`post_title` TEXT NOT NULL,
			`content` LONGTEXT NULL DEFAULT NULL,
			`tcb_fields` LONGTEXT NULL DEFAULT NULL,
			parent_id INT( 11 ) NULL DEFAULT '0'
		) {$charset}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}settings_campaign (
			`id` INT(11) NOT NULL AUTO_INCREMENT,
			`campaign_id` BIGINT(20) NOT NULL,
			`description` VARCHAR(255),
			`show_options` LONGTEXT NULL,
			`hide_options` LONGTEXT NULL,
			PRIMARY KEY (`id`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}event_log (
			`id` BIGINT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
			`date` DATETIME NULL DEFAULT NULL,
			`type` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
		) {$charset}" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}emails (
			`id` BIGINT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
			`email` VARCHAR(255) NOT NULL,
			`started` DATETIME NOT NULL,
			`type` VARCHAR(255) NOT NULL DEFAULT 'url',
			`end` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`has_impression` TINYINT UNSIGNED NOT NULL DEFAULT 0
		) {$charset}" );
	}

	function wpmcp_test_drop_ultimatum_tables(): void {
		global $wpdb;
		foreach ( [ 'events', 'designs', 'settings_campaign', 'event_log', 'emails' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tve_ult_{$table}" );
		}
	}

	/** The campaign post type as inc/functions.php registers it (capabilities left to the post defaults). */
	function wpmcp_test_register_ultimatum_type(): void {
		register_post_type( 'tve_ult_campaign', [ 'publicly_queryable' => true, 'query_var' => false, 'rewrite' => false, 'map_meta_cap' => true ] );
	}

	function wpmcp_test_unregister_ultimatum_type(): void {
		unregister_post_type( 'tve_ult_campaign' );
	}

	/**
	 * A campaign stored the way tve_ult_save_campaign() leaves one; returns
	 * its id. $meta overrides the metas by their plugin key.
	 */
	function wpmcp_test_ultimatum_campaign( string $title, array $meta = [] ): int {
		$id = wp_insert_post( [ 'post_type' => 'tve_ult_campaign', 'post_title' => $title, 'post_status' => 'publish' ] );
		foreach ( $meta + [ 'tve_ult_status' => 'paused', 'tve_campaign_order' => 0 ] as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return (int) $id;
	}

	/** A design row the way TVE_Ult_DB::save_design() writes one; returns its id. */
	function wpmcp_test_ultimatum_design( int $campaign, string $type, string $title, array $tcb_fields = [], int $parent = 0 ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'tve_ult_designs', [
			'post_parent' => $campaign,
			'post_status' => 'publish',
			'post_type'   => $type,
			'post_title'  => $title,
			'content'     => '<div class="tve_ult_design">x</div>',
			'tcb_fields'  => serialize( $tcb_fields ),
			'parent_id'   => $parent,
		] );
		return (int) $wpdb->insert_id;
	}

	/** An event row the way TVE_Ult_DB::save_event() writes one; returns its id. */
	function wpmcp_test_ultimatum_event( int $campaign, string $type, int $days, int $hours, array $actions = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'tve_ult_events', [
			'campaign_id' => $campaign,
			'days'        => $days,
			'hours'       => $hours,
			'actions'     => serialize( $actions ),
			'type'        => $type,
		] );
		return (int) $wpdb->insert_id;
	}

	/** Display settings saved the way Thrive_Ult_Campaign_Options::save() stores them. */
	function wpmcp_test_ultimatum_display( int $campaign, string $show, string $hide = '{"tabs":[]}' ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'tve_ult_settings_campaign', [
			'campaign_id'  => $campaign,
			'description'  => null,
			'show_options' => $show,
			'hide_options' => $hide,
		] );
	}
}
