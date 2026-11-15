<?php
/**
 * Tables for the Pretty Links and ThirstyAffiliates ops (issue #682), for
 * tests that run without either plugin loaded.
 *
 * The DDL is taken from the free plugins on wordpress.org:
 *  - Pretty Links 4.0.18, src/Database/Migrator.php: {prefix}prli_links and
 *    {prefix}prli_clicks, every column with the plugin's own type and default;
 *  - ThirstyAffiliates 3.12.2, Models/Bootstrap.php: {prefix}ta_link_clicks
 *    and {prefix}ta_link_clicks_meta.
 * ThirstyAffiliates' links are posts, registered by the test the way
 * Models/Affiliate_Links_CPT.php registers them (thirstylink and its
 * thirstylink-category taxonomy).
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_link_tables' ) ) {
	function wpmcp_test_link_tables(): array {
		return [ 'prli_links', 'prli_clicks', 'ta_link_clicks', 'ta_link_clicks_meta' ];
	}

	function wpmcp_test_create_link_tables(): void {
		global $wpdb;

		wpmcp_test_drop_link_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}prli_links (
			id INT(11) NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) DEFAULT NULL,
			description TEXT DEFAULT NULL,
			url TEXT DEFAULT NULL,
			slug VARCHAR(255) DEFAULT NULL,
			nofollow TINYINT(1) DEFAULT 0,
			sponsored TINYINT(1) DEFAULT 0,
			track_me TINYINT(1) DEFAULT 1,
			param_forwarding VARCHAR(255) DEFAULT NULL,
			redirect_type VARCHAR(255) DEFAULT '307',
			created_at DATETIME NOT NULL,
			updated_at DATETIME DEFAULT NULL,
			group_id INT(11) DEFAULT NULL,
			link_cpt_id BIGINT(20) UNSIGNED DEFAULT 0,
			prettypay_link TINYINT(1) DEFAULT 0,
			new_window TINYINT(1) DEFAULT 0,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			uniques BIGINT UNSIGNED NOT NULL DEFAULT 0,
			deleted_at DATETIME DEFAULT NULL,
			source VARCHAR(16) NOT NULL DEFAULT 'admin',
			PRIMARY KEY  (id),
			KEY slug (slug(191))
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}prli_clicks (
			id INT(11) NOT NULL AUTO_INCREMENT,
			ip VARCHAR(255) DEFAULT NULL,
			browser VARCHAR(255) DEFAULT NULL,
			btype VARCHAR(255) DEFAULT NULL,
			bversion VARCHAR(255) DEFAULT NULL,
			os VARCHAR(255) DEFAULT NULL,
			referer VARCHAR(255) DEFAULT NULL,
			host VARCHAR(255) DEFAULT NULL,
			uri VARCHAR(255) DEFAULT NULL,
			robot TINYINT DEFAULT 0,
			first_click TINYINT DEFAULT 0,
			created_at DATETIME NOT NULL,
			link_id INT(11) DEFAULT NULL,
			vuid VARCHAR(25) DEFAULT NULL,
			country CHAR(2) NOT NULL DEFAULT '',
			device_type VARCHAR(32) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY link_id_vuid (link_id, vuid)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}ta_link_clicks (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			link_id bigint(20) NOT NULL,
			date_clicked datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY date_clicked (date_clicked)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}ta_link_clicks_meta (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			click_id bigint(20) NOT NULL,
			meta_key varchar(255) NULL,
			meta_value longtext NULL,
			PRIMARY KEY  (id),
			KEY click_id (click_id),
			KEY meta_key (meta_key(191))
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_link_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_link_tables() as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		}
	}

	/** One prli_links row as Repositories\Links::create() writes it; returns its id. */
	function wpmcp_test_prli_link( array $row = [] ): int {
		global $wpdb;
		$now = '2026-11-01 10:00:00';
		$wpdb->insert( $wpdb->prefix . 'prli_links', $row + [
			'slug'             => 'deal',
			'url'              => 'https://example.com/deal',
			'name'             => 'The deal',
			'description'      => '',
			'redirect_type'    => '302',
			'param_forwarding' => 0,
			'track_me'         => 1,
			'nofollow'         => 0,
			'sponsored'        => 0,
			'new_window'       => 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		] );
		return (int) $wpdb->insert_id;
	}
}
