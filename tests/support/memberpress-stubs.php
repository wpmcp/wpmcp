<?php
/**
 * Post types and tables for the MemberPress ops (issue #668) on the
 * plugin-data pair, for tests that run without MemberPress loaded.
 *
 * Taken from MemberPress 1.14.3's own source:
 *  - app/controllers/MeprProductsCtrl.php and MeprRulesCtrl.php register the
 *    memberpressproduct (public, hierarchical) and memberpressrule (not
 *    public) post types, both with capability_type page;
 *  - app/lib/MeprDb.php holds the DDL of {prefix}mepr_transactions,
 *    {prefix}mepr_members and {prefix}mepr_rule_access_conditions (only the
 *    columns the ops read are kept here, with MemberPress's own types and
 *    defaults);
 *  - app/models/MeprProduct.php and MeprRule.php name the post meta keys
 *    (the *_str statics) and store booleans the way update_post_meta()
 *    stores PHP true and false: '1' and ''.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_register_memberpress_types' ) ) {
	function wpmcp_test_register_memberpress_types(): void {
		register_post_type( 'memberpressproduct', [
			'public'          => true,
			'capability_type' => 'page',
			'hierarchical'    => true,
			'supports'        => [ 'title', 'editor', 'page-attributes', 'comments', 'thumbnail' ],
		] );
		register_post_type( 'memberpressrule', [
			'public'          => false,
			'show_ui'         => true,
			'capability_type' => 'page',
			'hierarchical'    => false,
			'supports'        => [ 'title' ],
		] );
	}

	function wpmcp_test_unregister_memberpress_types(): void {
		foreach ( [ 'memberpressproduct', 'memberpressrule' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}

	function wpmcp_test_create_memberpress_tables(): void {
		global $wpdb;

		wpmcp_test_drop_memberpress_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mepr_transactions (
			id bigint(20) NOT NULL auto_increment,
			amount decimal(16,2) NOT NULL,
			total decimal(16,2) DEFAULT 0,
			tax_amount decimal(16,2) DEFAULT 0,
			user_id bigint(20) NOT NULL,
			product_id bigint(20) NOT NULL,
			coupon_id bigint(20) DEFAULT NULL,
			trans_num varchar(255) DEFAULT NULL,
			status varchar(255) DEFAULT 'pending',
			txn_type varchar(255) DEFAULT 'payment',
			response text DEFAULT NULL,
			gateway varchar(255) DEFAULT 'manual',
			subscription_id bigint(20) DEFAULT NULL,
			created_at datetime NOT NULL,
			expires_at datetime DEFAULT '0000-00-00 00:00:00',
			refunded_at datetime DEFAULT NULL,
			currency varchar(3) DEFAULT '',
			PRIMARY KEY (id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mepr_members (
			id bigint(20) NOT NULL auto_increment,
			user_id bigint(20) NOT NULL,
			txn_count bigint(20),
			expired_txn_count bigint(20),
			active_txn_count bigint(20),
			sub_count bigint(20),
			active_sub_count bigint(20),
			memberships longtext,
			inactive_memberships longtext,
			login_count bigint(20),
			total_spent decimal(16,2) DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			trial_txn_count bigint(20),
			PRIMARY KEY (id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mepr_rule_access_conditions (
			id bigint(20) NOT NULL auto_increment,
			rule_id bigint(20) NOT NULL,
			access_type varchar(20) NOT NULL,
			access_operator varchar(20) NOT NULL,
			access_condition varchar(60) NOT NULL,
			PRIMARY KEY (id)
		) {$charset}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	function wpmcp_test_drop_memberpress_tables(): void {
		global $wpdb;
		foreach ( [ 'mepr_transactions', 'mepr_members', 'mepr_rule_access_conditions' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		}
	}

	/** A transaction row the way MemberPress writes one; returns its id. */
	function wpmcp_test_memberpress_transaction( int $user_id, int $product_id, array $fields = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'mepr_transactions', $fields + [
			'amount'     => '10.00',
			'total'      => '10.00',
			'tax_amount' => '0.00',
			'user_id'    => $user_id,
			'product_id' => $product_id,
			'trans_num'  => 'mp-txn-' . wp_generate_password( 8, false ),
			'status'     => 'complete',
			'txn_type'   => 'payment',
			'gateway'    => 'manual',
			'response'   => '{"secret":"gateway payload"}',
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			'expires_at' => '0000-00-00 00:00:00',
		] );
		return (int) $wpdb->insert_id;
	}

	/** A mepr_members row as MeprUser::update_member_data() keeps it. */
	function wpmcp_test_memberpress_member( int $user_id, string $active, string $inactive = '', array $fields = [] ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $wpdb->prefix . 'mepr_members', $fields + [
			'user_id'              => $user_id,
			'txn_count'            => 1,
			'expired_txn_count'    => '' === $inactive ? 0 : 1,
			'active_txn_count'     => '' === $active ? 0 : 1,
			'sub_count'            => 0,
			'active_sub_count'     => 0,
			'memberships'          => $active,
			'inactive_memberships' => $inactive,
			'login_count'          => 3,
			'total_spent'          => '10.00',
			'created_at'           => $now,
			'updated_at'           => $now,
			'trial_txn_count'      => 0,
		] );
	}

	/** Every access condition row of a rule, in id order. */
	function wpmcp_test_memberpress_conditions( int $rule_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT access_type, access_operator, access_condition FROM {$wpdb->prefix}mepr_rule_access_conditions WHERE rule_id = %d ORDER BY id ASC", $rule_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture.
	}
}
