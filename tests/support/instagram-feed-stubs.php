<?php
/**
 * Tables for the Smash Balloon Instagram Feed ops (issue #902), for tests
 * that run without the plugin loaded.
 *
 * The DDL is taken from the free plugin on wordpress.org (Instagram Feed
 * 6.16.0): inc/Builder/SBI_Db.php::create_tables() for {prefix}sbi_feeds,
 * {prefix}sbi_feed_caches and {prefix}sbi_sources, and
 * inc/class-sb-instagram-feed-locator.php for
 * {prefix}sbi_instagram_feed_locator, every column with the plugin's own
 * type and default.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_sbi_tables' ) ) {
	function wpmcp_test_sbi_tables(): array {
		return [ 'sbi_feeds', 'sbi_feed_caches', 'sbi_sources', 'sbi_instagram_feed_locator' ];
	}

	function wpmcp_test_create_sbi_tables(): void {
		global $wpdb;

		wpmcp_test_drop_sbi_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}sbi_feeds (
			id bigint(20) unsigned NOT NULL auto_increment,
			feed_name text NOT NULL,
			feed_title text NOT NULL,
			settings longtext NOT NULL,
			author bigint(20) unsigned NOT NULL default '1',
			status varchar(255) NOT NULL default '',
			last_modified datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY author (author)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}sbi_feed_caches (
			id bigint(20) unsigned NOT NULL auto_increment,
			feed_id varchar(255) NOT NULL default '',
			cache_key varchar(255) NOT NULL default '',
			cache_value longtext NOT NULL,
			cron_update varchar(20) NOT NULL default 'yes',
			last_updated datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY feed_id (feed_id(191))
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}sbi_sources (
			id bigint(20) unsigned NOT NULL auto_increment,
			account_id varchar(255) NOT NULL default '',
			account_type varchar(255) NOT NULL default '',
			privilege varchar(255) NOT NULL default '',
			access_token varchar(1000) NOT NULL default '',
			username varchar(255) NOT NULL default '',
			info text NOT NULL,
			error text NOT NULL,
			expires datetime NOT NULL,
			last_updated datetime NOT NULL,
			author bigint(20) unsigned NOT NULL default '1',
			connect_type varchar(100) NOT NULL default '',
			PRIMARY KEY  (id),
			KEY account_type (account_type(191)),
			KEY author (author)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}sbi_instagram_feed_locator (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			feed_id VARCHAR(50) DEFAULT '' NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			html_location VARCHAR(50) DEFAULT 'unknown' NOT NULL,
			shortcode_atts LONGTEXT NOT NULL,
			last_update DATETIME
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_sbi_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_sbi_tables() as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		}
	}

	/**
	 * One sbi_feeds row as SBI_Feed_Saver stores it (scalars as strings,
	 * booleans as booleans, the account ids under 'id'); returns its id.
	 */
	function wpmcp_test_sbi_feed( array $settings = [], array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'sbi_feeds', $row + [
			'feed_name'     => 'Shop feed',
			'feed_title'    => 'Shop feed',
			'settings'      => wp_json_encode( $settings + [
				'type'           => 'user',
				'id'             => [ '17841400000000001' ],
				'source_details' => [ [ 'id' => '17841400000000001', 'username' => 'acme.shop' ] ],
				'num'            => '20',
				'nummobile'      => '20',
				'cols'           => '4',
				'colsmobile'     => '2',
				'showheader'     => true,
				'showbutton'     => true,
				'sortby'         => 'none',
				'imageres'       => 'auto',
			] ),
			'author'        => 1,
			'status'        => 'publish',
			'last_modified' => '2026-11-01 10:00:00',
		] );
		return (int) $wpdb->insert_id;
	}

	/** One sbi_sources row as SBI_Source stores it; returns its id. */
	function wpmcp_test_sbi_source( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'sbi_sources', $row + [
			'account_id'   => '17841400000000001',
			'account_type' => 'business',
			'privilege'    => '',
			'access_token' => 'IGQVJSECRETTOKENVALUE0123456789',
			'username'     => 'acme.shop',
			'info'         => '{"id":"17841400000000001","username":"acme.shop","name":"Acme Shop"}',
			'error'        => '',
			'expires'      => '2099-01-01 00:00:00',
			'last_updated' => '2026-11-01 10:00:00',
			'author'       => 1,
			'connect_type' => 'business_basic',
		] );
		return (int) $wpdb->insert_id;
	}

	/** One sbi_feed_caches row as SB_Instagram_Cache::update_or_insert() writes it; returns its id. */
	function wpmcp_test_sbi_cache( string $feed_id, string $key, string $value, string $updated = '2026-11-01 10:00:00' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'sbi_feed_caches', [
			'feed_id'      => $feed_id,
			'cache_key'    => $key,
			'cache_value'  => $value,
			'cron_update'  => 'yes',
			'last_updated' => $updated,
		] );
		return (int) $wpdb->insert_id;
	}
}
