<?php
/**
 * Tables and rows for the WP All Import and WP All Export ops (issue #909)
 * on the plugin-data pair, for tests that run without either plugin loaded.
 *
 * The DDL is what the plugins create (WP All Import 4.2.0 schema.php:
 * {prefix}pmxi_imports and {prefix}pmxi_history; WP All Export 1.6.0
 * schema.php: {prefix}pmxe_exports), every column with the plugin's own
 * type. Both plugins store a job's settings in the options column with
 * PHP serialize(). DDL commits implicitly, so the tables are created in
 * wpSetUpBeforeClass() and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_wp_all_import_tables' ) ) {
	function wpmcp_test_wp_all_import_tables(): array {
		global $wpdb;
		return [
			$wpdb->prefix . 'pmxi_imports',
			$wpdb->prefix . 'pmxi_history',
			$wpdb->prefix . 'pmxe_exports',
		];
	}

	function wpmcp_test_create_wp_all_import_tables(): void {
		global $wpdb;

		wpmcp_test_drop_wp_all_import_tables();
		$charset = $wpdb->get_charset_collate();
		list( $imports, $history, $exports ) = wpmcp_test_wp_all_import_tables();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE `{$imports}` (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_import_id BIGINT(20) NOT NULL DEFAULT 0,
			name TEXT,
			friendly_name VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(32) NOT NULL DEFAULT '',
			feed_type ENUM('xml','csv','zip','gz','') NOT NULL DEFAULT '',
			path TEXT,
			xpath TEXT,
			options LONGTEXT,
			registered_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			root_element VARCHAR(255) DEFAULT '',
			processing BOOL NOT NULL DEFAULT 0,
			executing BOOL NOT NULL DEFAULT 0,
			triggered BOOL NOT NULL DEFAULT 0,
			queue_chunk_number BIGINT(20) NOT NULL DEFAULT 0,
			first_import TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			count BIGINT(20) NOT NULL DEFAULT 0,
			imported BIGINT(20) NOT NULL DEFAULT 0,
			created BIGINT(20) NOT NULL DEFAULT 0,
			updated BIGINT(20) NOT NULL DEFAULT 0,
			skipped BIGINT(20) NOT NULL DEFAULT 0,
			deleted BIGINT(20) NOT NULL DEFAULT 0,
			changed_missing BIGINT(20) NOT NULL DEFAULT 0,
			canceled BOOL NOT NULL DEFAULT 0,
			canceled_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			failed BOOL NOT NULL DEFAULT 0,
			failed_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			settings_update_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_activity DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			iteration BIGINT(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE `{$history}` (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			import_id BIGINT(20) UNSIGNED NOT NULL,
			type ENUM('manual','processing','trigger','continue', 'cli', '') NOT NULL DEFAULT '',
			time_run TEXT,
			date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			summary TEXT,
			PRIMARY KEY  (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE `{$exports}` (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_id BIGINT(20) NOT NULL DEFAULT 0,
			attch_id BIGINT(20) NOT NULL DEFAULT 0,
			options LONGTEXT,
			scheduled VARCHAR(64) NOT NULL DEFAULT '',
			registered_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			friendly_name TEXT NOT NULL,
			exported BIGINT(20) NOT NULL DEFAULT 0,
			canceled BOOL NOT NULL DEFAULT 0,
			canceled_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			settings_update_on DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_activity DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			processing BOOL NOT NULL DEFAULT 0,
			executing BOOL NOT NULL DEFAULT 0,
			triggered BOOL NOT NULL DEFAULT 0,
			iteration BIGINT(20) NOT NULL DEFAULT 0,
			export_post_type TEXT NOT NULL,
			PRIMARY KEY  (id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_wp_all_import_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_wp_all_import_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/** One saved import, its options merged over the plugin's own defaults for the keys read. */
	function wpmcp_test_pmxi_import( array $row = [], array $options = [] ): int {
		global $wpdb;
		$options = array_merge(
			[
				'type'                => 'post',
				'custom_type'         => 'product',
				'wizard_type'         => 'new',
				'ftp_host'            => '',
				'ftp_path'            => '',
				'ftp_root'            => '/',
				'ftp_port'            => 21,
				'ftp_username'        => '',
				'ftp_password'        => '',
				'ftp_private_key'     => '',
				'status'              => 'publish',
				'title'               => '{title[1]}',
				'content'             => '{description[1]}',
				'post_excerpt'        => '',
				'post_slug'           => '',
				'custom_name'         => [ '_sku', '_price' ],
				'custom_value'        => [ '{sku[1]}', '{price[1]}' ],
				'unique_key'          => '{sku[1]}',
				'duplicate_matching'  => 'auto',
				'duplicate_indicator' => 'title',
				'create_new_records'  => 1,
				'is_delete_missing'   => 0,
				'update_all_data'     => 'yes',
				'is_update_title'     => 1,
				'is_update_content'   => 1,
				'delimiter'           => ',',
				'is_scheduled'        => '',
				'scheduled_period'    => '',
				'scheduling_enable'   => false,
				'scheduling_run_on'   => 'weekly',
				'scheduling_times'    => [],
				'scheduling_timezone' => 'UTC',
			],
			$options
		);
		$wpdb->insert(
			$wpdb->prefix . 'pmxi_imports',
			array_merge(
				[
					'name'          => 'products.csv',
					'friendly_name' => 'Product feed',
					'type'          => 'url',
					'feed_type'     => 'csv',
					'path'          => 'https://feeds.example.com/products.csv',
					'xpath'         => '/node',
					'registered_on' => '2026-11-01 10:00:00',
					'root_element'  => 'node',
					'count'         => 120,
					'imported'      => 120,
					'created'       => 100,
					'updated'       => 20,
					'skipped'       => 0,
					'last_activity' => '2026-11-15 08:00:00',
				],
				$row,
				[ 'options' => serialize( $options ) ] // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the plugin stores options this way.
			)
		);
		return (int) $wpdb->insert_id;
	}

	function wpmcp_test_pmxi_history( int $import_id, array $row = [] ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'pmxi_history',
			array_merge(
				[
					'import_id' => $import_id,
					'type'      => 'manual',
					'time_run'  => '42',
					'date'      => '2026-11-15 08:00:00',
					'summary'   => '100 products created 20 updated 0 skipped',
				],
				$row
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** One saved export, its options merged over the plugin's own defaults for the keys read. */
	function wpmcp_test_pmxe_export( array $row = [], array $options = [] ): int {
		global $wpdb;
		$options = array_merge(
			[
				'cpt'                        => [ 'product' ],
				'export_type'                => 'specific',
				'export_to'                  => 'csv',
				'export_to_sheet'            => 'csv',
				'delimiter'                  => ',',
				'is_user_export'             => false,
				'is_comment_export'          => false,
				'friendly_name'              => 'Products to Google',
				'filepath'                   => '/wpallexport/exports/abc123/products.csv',
				'cc_label'                   => [ 'ID', 'Title' ],
				'cc_type'                    => [ 'id', 'title' ],
				'cc_name'                    => [ 'ID', 'Title' ],
				'cc_php'                     => [ 0, 0 ],
				'cc_code'                    => [ '', '' ],
				'cc_sql'                     => [ '', '' ],
				'filter_rules_hierarhy'      => '',
				'whereclause'                => '',
				'joinclause'                 => '',
				'scheduled_email'            => '',
				'is_scheduled'               => 0,
				'scheduled_period'           => '',
				'scheduling_enable'          => false,
				'scheduling_run_on'          => 'weekly',
				'scheduling_times'           => [],
				'scheduling_timezone'        => 'UTC',
				'export_only_new_stuff'      => 0,
				'export_only_modified_stuff' => 0,
			],
			$options
		);
		$wpdb->insert(
			$wpdb->prefix . 'pmxe_exports',
			array_merge(
				[
					'friendly_name'    => 'Products to Google',
					'registered_on'    => '2026-11-02 10:00:00',
					'exported'         => 55,
					'last_activity'    => '2026-11-16 07:00:00',
					'export_post_type' => '',
				],
				$row,
				[ 'options' => serialize( $options ) ] // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the plugin stores options this way.
			)
		);
		return (int) $wpdb->insert_id;
	}
}
