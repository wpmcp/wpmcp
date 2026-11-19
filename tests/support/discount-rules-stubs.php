<?php
/**
 * The rules table of Discount Rules for WooCommerce (issue #901), for tests
 * that run without the plugin loaded.
 *
 * The DDL is taken from the free plugin on wordpress.org, version 2.6.18,
 * v2/App/Models/DBTable.php createTable(): {prefix}wdr_rules, every column
 * with the plugin's own type and default. A row is written the way
 * Helpers/Rule.php save() writes one (JSON columns, unix times for the
 * dates, deleted as the soft delete flag).
 *
 * DDL commits implicitly, so the table is created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_wdr_tables' ) ) {
	function wpmcp_test_create_wdr_tables(): void {
		global $wpdb;

		wpmcp_test_drop_wdr_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}wdr_rules (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`enabled` tinyint(1) DEFAULT '1',
			`deleted` tinyint(1) DEFAULT '0',
			`exclusive` tinyint(1) DEFAULT '0',
			`title` varchar(255) DEFAULT NULL,
			`priority` int(11) DEFAULT NULL,
			`apply_to` text,
			`filters` longtext NOT NULL,
			`conditions` longtext,
			`product_adjustments` text,
			`cart_adjustments` text,
			`buy_x_get_x_adjustments` text,
			`buy_x_get_y_adjustments` text,
			`bulk_adjustments` text NOT NULL,
			`set_adjustments` text NOT NULL,
			`other_discounts` text,
			`date_from` int(11) DEFAULT NULL,
			`date_to` int(11) DEFAULT NULL,
			`usage_limits` int(11) DEFAULT NULL,
			`rule_language` text DEFAULT NULL,
			`used_limits` int(11) DEFAULT NULL,
			`additional` text DEFAULT NULL,
			`max_discount_sum` varchar(255) DEFAULT NULL,
			`advanced_discount_message` text DEFAULT NULL,
			`discount_type` varchar(255) DEFAULT NULL,
			`used_coupons` text DEFAULT NULL,
			`created_by` int(11) DEFAULT NULL,
			`created_on` datetime DEFAULT NULL,
			`modified_by` int(11) DEFAULT NULL,
			`modified_on` datetime DEFAULT NULL,
			PRIMARY KEY (`id`)
		) $charset" );
		// phpcs:enable
	}

	function wpmcp_test_drop_wdr_tables(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdr_rules" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
	}

	/** One wdr_rules row as Rule::save() writes it (the plugin's sample 10 percent rule by default); returns its id. */
	function wpmcp_test_wdr_rule( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wdr_rules', $row + [
			'enabled'                   => 1,
			'deleted'                   => 0,
			'exclusive'                 => 0,
			'title'                     => 'Ten percent off',
			'priority'                  => 1,
			'usage_limits'              => 0,
			'date_from'                 => null,
			'date_to'                   => null,
			'filters'                   => '{"1":{"type":"all_products"}}',
			'conditions'                => '{"2":{"type":"cart_subtotal","options":{"operator":"greater_than_or_equal","value":"500","calculate_from":"from_cart"}}}',
			'additional'                => '{"condition_relationship":"and"}',
			'product_adjustments'       => '{"type":"percentage","value":"10","cart_label":""}',
			'cart_adjustments'          => '[]',
			'buy_x_get_x_adjustments'   => '[]',
			'buy_x_get_y_adjustments'   => '[]',
			'bulk_adjustments'          => '{"cart_label":""}',
			'rule_language'             => '[]',
			'set_adjustments'           => '{"cart_label":""}',
			'advanced_discount_message' => '{"display":"0","badge_color_picker":"#ffffff","badge_text_color_picker":"#000000","badge_text":""}',
			'discount_type'             => 'wdr_simple_discount',
			'used_coupons'              => '[]',
			'created_by'                => 1,
			'created_on'                => '2026-11-01 10:00:00',
			'modified_by'               => 1,
			'modified_on'               => '2026-11-01 10:00:00',
		] );
		return (int) $wpdb->insert_id;
	}

	/** One wdr_rules row as an array, or null. */
	function wpmcp_test_wdr_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'wdr_rules', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
