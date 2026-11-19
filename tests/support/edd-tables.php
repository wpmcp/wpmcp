<?php

/**
 * Easy Digital Downloads storage for the EDD pack tests (issue #664), built
 * the way Easy Digital Downloads 3.7.3 keeps it: the `download` post type
 * (capability_type product, map_meta_cap, includes/post-types.php) and the
 * BerlinDB tables of src/Database/Tables (orders, order_items, customers,
 * adjustments, adjustmentmeta), column for column.
 *
 * The real plugin is not loaded in the shared test core.
 */

if (! function_exists('wpmcp_test_create_edd_tables')) {
	/** The EDD tables this fixture builds, unprefixed. */
	function wpmcp_test_edd_table_names(): array {
		return [ 'edd_orders', 'edd_order_items', 'edd_customers', 'edd_adjustments', 'edd_adjustmentmeta' ];
	}

	function wpmcp_test_drop_edd_tables(): void {
		global $wpdb;
		foreach (wpmcp_test_edd_table_names() as $name) {
			$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $name));
		}
	}

	function wpmcp_test_create_edd_tables(): void {
		global $wpdb;

		wpmcp_test_drop_edd_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query("CREATE TABLE {$p}edd_orders (
			id bigint(20) unsigned NOT NULL auto_increment,
			parent bigint(20) unsigned NOT NULL default '0',
			order_number varchar(255) NOT NULL default '',
			status varchar(20) NOT NULL default 'pending',
			type varchar(20) NOT NULL default 'sale',
			user_id bigint(20) unsigned NOT NULL default '0',
			customer_id bigint(20) unsigned NOT NULL default '0',
			email varchar(100) NOT NULL default '',
			ip varchar(60) NOT NULL default '',
			gateway varchar(100) NOT NULL default 'manual',
			mode varchar(20) NOT NULL default '',
			currency varchar(20) NOT NULL default '',
			payment_key varchar(64) NOT NULL default '',
			tax_rate_id bigint(20) DEFAULT NULL,
			subtotal decimal(18,9) NOT NULL default '0',
			discount decimal(18,9) NOT NULL default '0',
			tax decimal(18,9) NOT NULL default '0',
			total decimal(18,9) NOT NULL default '0',
			rate decimal(10,5) NOT NULL DEFAULT 1.00000,
			date_created datetime NOT NULL default CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL default CURRENT_TIMESTAMP,
			date_completed datetime default null,
			date_refundable datetime default null,
			date_actions_run datetime default null,
			uuid varchar(100) NOT NULL default '',
			PRIMARY KEY (id),
			KEY customer_id (customer_id)
		) {$charset}");
		$wpdb->query("CREATE TABLE {$p}edd_order_items (
			id bigint(20) unsigned NOT NULL auto_increment,
			parent bigint(20) unsigned NOT NULL default '0',
			order_id bigint(20) unsigned NOT NULL default '0',
			product_id bigint(20) unsigned NOT NULL default '0',
			product_name text NOT NULL,
			price_id bigint(20) unsigned default null,
			cart_index bigint(20) unsigned NOT NULL default '0',
			type varchar(20) NOT NULL default 'download',
			status varchar(20) NOT NULL default 'pending',
			quantity int signed NOT NULL default '0',
			amount decimal(18,9) NOT NULL default '0',
			subtotal decimal(18,9) NOT NULL default '0',
			discount decimal(18,9) NOT NULL default '0',
			tax decimal(18,9) NOT NULL default '0',
			total decimal(18,9) NOT NULL default '0',
			rate decimal(10,5) NOT NULL DEFAULT 1.00000,
			date_created datetime NOT NULL default CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL default CURRENT_TIMESTAMP,
			uuid varchar(100) NOT NULL default '',
			PRIMARY KEY (id),
			KEY order_product_price_id (order_id,product_id,price_id)
		) {$charset}");
		$wpdb->query("CREATE TABLE {$p}edd_customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL default '0',
			email varchar(100) NOT NULL default '',
			name varchar(255) NOT NULL default '',
			status varchar(20) NOT NULL default '',
			purchase_value decimal(18,9) NOT NULL default '0',
			purchase_count bigint(20) unsigned NOT NULL default '0',
			date_created datetime NOT NULL default CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL default CURRENT_TIMESTAMP,
			uuid varchar(100) NOT NULL default '',
			PRIMARY KEY (id),
			UNIQUE KEY email (email)
		) {$charset}");
		$wpdb->query("CREATE TABLE {$p}edd_adjustments (
			id bigint(20) unsigned NOT NULL auto_increment,
			parent bigint(20) unsigned NOT NULL default '0',
			name varchar(200) NOT NULL default '',
			code varchar(50) NOT NULL default '',
			status varchar(20) NOT NULL default '',
			type varchar(20) NOT NULL default '',
			scope varchar(20) NOT NULL default 'all',
			amount_type varchar(20) NOT NULL default '',
			amount decimal(18,9) NOT NULL default '0',
			description longtext NOT NULL,
			max_uses bigint(20) unsigned NOT NULL default '0',
			use_count bigint(20) unsigned NOT NULL default '0',
			once_per_customer int(1) NOT NULL default '0',
			min_charge_amount decimal(18,9) NOT NULL default '0',
			start_date datetime default null,
			end_date datetime default null,
			date_created datetime NOT NULL default CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL default CURRENT_TIMESTAMP,
			uuid varchar(100) NOT NULL default '',
			PRIMARY KEY (id),
			KEY code (code)
		) {$charset}");
		$wpdb->query("CREATE TABLE {$p}edd_adjustmentmeta (
			meta_id bigint(20) unsigned NOT NULL auto_increment,
			edd_adjustment_id bigint(20) unsigned NOT NULL default '0',
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext DEFAULT NULL,
			PRIMARY KEY (meta_id),
			KEY edd_adjustment_id (edd_adjustment_id)
		) {$charset}");
		// phpcs:enable
	}

	/** The `download` post type as EDD 3.7.3 registers it (the parts the pack depends on). */
	function wpmcp_test_register_edd_download_type(): void {
		if (! post_type_exists('download')) {
			register_post_type('download', [
				'public'          => true,
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' ],
			]);
		}
	}

	/** The product capabilities EDD's roles give an administrator (EDD_Roles::add_caps). */
	function wpmcp_test_edd_product_caps(): array {
		return [ 'edit_products', 'edit_others_products', 'publish_products', 'read_private_products', 'delete_products', 'delete_private_products', 'delete_published_products', 'delete_others_products', 'edit_private_products', 'edit_published_products' ];
	}
}
