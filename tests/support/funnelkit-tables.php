<?php
/**
 * The FunnelKit tables the funnel reads use, for tests that exercise them
 * without FunnelKit installed in the shared test core.
 *
 * Copied from FunnelKit Funnel Builder 3.16.0.5 (wordpress.org slug
 * funnel-builder):
 *  - {prefix}bwf_funnels: admin/db/class-wffn-db-tables.php funnels(). The
 *    steps column holds the ordered step list as JSON,
 *    [{"type":"landing","id":12}, ...] (includes/class-wffn-funnel.php);
 *  - {prefix}wfco_report_views: woofunnels/contact/class-woofunnels-db-tables.php,
 *    session counts per object and day, by type (2 landing visited,
 *    3 landing converted, 4 checkout visited, 5 thank you visited,
 *    8 optin visited, 10 optin thank you visited, 11 converted);
 *  - {prefix}wfacp_stats: modules/checkouts/includes/class-wfacp-reporting.php,
 *    one row per order placed through a checkout step;
 *  - {prefix}bwf_optin_entries: modules/optins/admin/db/class-wfopp-db-tables.php,
 *    one row per optin submission, email included;
 *  - {prefix}wfocu_event: created by the upsell add-on, so its DDL is not in
 *    the free source. The columns are the ones the free plugin's own queries
 *    select (admin/rest-api/class-wffn-rest-funnel-canvas.php,
 *    includes/wffn-functions.php): one row per offer event, action_type_id
 *    2 viewed and 4 accepted, value the offer revenue.
 *
 * WFOCU_Core() stands in for the upsell add-on's accessor. It answers the
 * object in $GLOBALS['wpmcp_test_wfocu_core'], null by default, so the
 * reader's add-on API path runs only in tests that set one.
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass(), outside the
 * per-test transaction. Rows written inside a test roll back with it.
 */

if ( ! function_exists( 'wpmcp_test_create_funnelkit_tables' ) ) {
	function wpmcp_test_create_funnelkit_tables(): void {
		global $wpdb;

		wpmcp_test_drop_funnelkit_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE `{$wpdb->prefix}bwf_funnels` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`title` text NOT NULL,
			`desc` text NOT NULL,
			`date_added` DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			`steps` LONGTEXT NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `id` (`id`)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->prefix}wfco_report_views` (
			id bigint(20) unsigned NOT NULL auto_increment,
			date date NOT NULL,
			no_of_sessions int(11) NOT NULL DEFAULT '1',
			object_id bigint(20) DEFAULT '0',
			type tinyint(2) NOT NULL DEFAULT '1',
			PRIMARY KEY  (id),
			KEY date (date),
			KEY object_id (object_id),
			KEY type (type)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->prefix}wfacp_stats` (
			ID bigint(20) unsigned NOT NULL auto_increment,
			order_id bigint(20) unsigned NOT NULL,
			wfacp_id bigint(20) unsigned NOT NULL,
			total_revenue varchar(255) not null default 0,
			cid bigint(20) unsigned NOT NULL DEFAULT 0,
			fid bigint(20) unsigned NOT NULL DEFAULT 0,
			date datetime NOT NULL,
			PRIMARY KEY  (ID),
			KEY oid (order_id),
			KEY bid (wfacp_id),
			KEY date (date)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->prefix}bwf_optin_entries` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`step_id` bigint(20) unsigned NOT NULL,
			`funnel_id` bigint(20) unsigned NOT NULL,
			`cid` bigint(20) unsigned NOT NULL,
			`opid` varchar(255) NOT NULL,
			`email` varchar(100) NOT NULL,
			`data` LONGTEXT NULL DEFAULT NULL,
			`date` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `step_id` (`step_id`),
			KEY `cid` (`cid`),
			KEY `funnel_id` (`funnel_id`),
			KEY `date` (`date`)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->prefix}wfocu_event` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sess_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(100) NOT NULL DEFAULT '',
			action_type_id tinyint(2) unsigned NOT NULL DEFAULT 0,
			value varchar(255) NOT NULL DEFAULT '',
			timestamp datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object_id (object_id),
			KEY action_type_id (action_type_id)
		) {$charset}" );
		// phpcs:enable
	}
}

if ( ! function_exists( 'wpmcp_test_drop_funnelkit_tables' ) ) {
	function wpmcp_test_drop_funnelkit_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bwf_funnels, {$wpdb->prefix}wfco_report_views, {$wpdb->prefix}wfacp_stats, {$wpdb->prefix}bwf_optin_entries, {$wpdb->prefix}wfocu_event" );
	}
}

if ( ! function_exists( 'wpmcp_test_funnelkit_funnel' ) ) {
	/**
	 * Insert a funnel row the way WFFN_Funnel::save() stores one and return
	 * its id. $steps is the ordered list of ['type' => ..., 'id' => ...].
	 *
	 * @param array<int, array{type: string, id: int}> $steps
	 */
	function wpmcp_test_funnelkit_funnel( string $title, array $steps, string $desc = '', string $date_added = '2026-09-01 10:00:00' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bwf_funnels',
			[
				'title'      => $title,
				'desc'       => $desc,
				'date_added' => $date_added,
				'steps'      => wp_json_encode( $steps ),
			]
		);

		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'WFOCU_Core' ) ) {
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors the upsell add-on's accessor name.
	function WFOCU_Core() {
		return $GLOBALS['wpmcp_test_wfocu_core'] ?? null;
	}
}
