<?php
/**
 * Tables and a minimal API stand-in for the Paid Memberships Pro ops (issue
 * #667), for tests that run without the plugin loaded.
 *
 * The DDL is taken from Paid Memberships Pro 3.2.2 on wordpress.org
 * (includes/upgradecheck.php, pmpro_db_delta()), every column with the
 * plugin's own type and default, so the NOT NULL columns without a default
 * are exercised the way a real install would.
 *
 * pmpro_changeMembershipLevel() is stood in for with the part of PMPro's own
 * (includes/functions.php) the ops depend on: refuse a level the user already
 * holds, put the user's other active levels in the same group to the old
 * status with an end date, insert the new membership row and fire
 * pmpro_after_change_membership_level.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_pmpro_tables' ) ) {
	function wpmcp_test_pmpro_tables(): array {
		return [
			'pmpro_membership_levels',
			'pmpro_memberships_users',
			'pmpro_membership_orders',
			'pmpro_discount_codes',
			'pmpro_discount_codes_levels',
			'pmpro_discount_codes_uses',
			'pmpro_subscriptions',
			'pmpro_groups',
			'pmpro_membership_levels_groups',
		];
	}

	function wpmcp_test_create_pmpro_tables(): void {
		global $wpdb;

		wpmcp_test_drop_pmpro_tables();
		$p = $wpdb->prefix;
		$c = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}pmpro_membership_levels (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(255) NOT NULL,
			`description` longtext NOT NULL,
			`confirmation` longtext NOT NULL,
			`initial_payment` decimal(18,8) NOT NULL DEFAULT '0.00',
			`billing_amount` decimal(18,8) NOT NULL DEFAULT '0.00',
			`cycle_number` int(11) NOT NULL DEFAULT '0',
			`cycle_period` varchar(10) DEFAULT 'Month',
			`billing_limit` int(11) NOT NULL,
			`trial_amount` decimal(18,8) NOT NULL DEFAULT '0.00',
			`trial_limit` int(11) NOT NULL DEFAULT '0',
			`allow_signups` tinyint(4) NOT NULL DEFAULT '1',
			`expiration_number` int(10) unsigned NOT NULL,
			`expiration_period` varchar(10) NOT NULL,
			PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_memberships_users (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) unsigned NOT NULL,
			`membership_id` int(11) unsigned NOT NULL,
			`code_id` bigint(20) unsigned NOT NULL,
			`initial_payment` decimal(18,8) NOT NULL,
			`billing_amount` decimal(18,8) NOT NULL,
			`cycle_number` int(11) NOT NULL,
			`cycle_period` varchar(10) NOT NULL DEFAULT 'Month',
			`billing_limit` int(11) NOT NULL,
			`trial_amount` decimal(18,8) NOT NULL,
			`trial_limit` int(11) NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'active',
			`startdate` datetime NOT NULL,
			`enddate` datetime DEFAULT NULL,
			`modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `user_id` (`user_id`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_membership_orders (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`code` varchar(32) NOT NULL,
			`session_id` varchar(64) NOT NULL DEFAULT '',
			`user_id` bigint(20) unsigned NOT NULL DEFAULT '0',
			`membership_id` int(11) unsigned NOT NULL DEFAULT '0',
			`paypal_token` varchar(64) NOT NULL DEFAULT '',
			`billing_name` varchar(128) NOT NULL DEFAULT '',
			`billing_street` varchar(128) NOT NULL DEFAULT '',
			`billing_street2` varchar(128) NOT NULL DEFAULT '',
			`billing_city` varchar(128) NOT NULL DEFAULT '',
			`billing_state` varchar(32) NOT NULL DEFAULT '',
			`billing_zip` varchar(16) NOT NULL DEFAULT '',
			`billing_country` varchar(128) NOT NULL,
			`billing_phone` varchar(32) NOT NULL,
			`subtotal` varchar(16) NOT NULL DEFAULT '',
			`tax` varchar(16) NOT NULL DEFAULT '',
			`checkout_id` bigint(20) NOT NULL DEFAULT '0',
			`total` varchar(16) NOT NULL DEFAULT '',
			`payment_type` varchar(64) NOT NULL DEFAULT '',
			`cardtype` varchar(32) NOT NULL DEFAULT '',
			`accountnumber` varchar(32) NOT NULL DEFAULT '',
			`expirationmonth` char(2) NOT NULL DEFAULT '',
			`expirationyear` varchar(4) NOT NULL DEFAULT '',
			`status` varchar(32) NOT NULL DEFAULT '',
			`gateway` varchar(64) NOT NULL,
			`gateway_environment` varchar(64) NOT NULL,
			`payment_transaction_id` varchar(64) NOT NULL,
			`subscription_transaction_id` varchar(32) NOT NULL,
			`timestamp` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
			`affiliate_id` varchar(32) NOT NULL,
			`affiliate_subid` varchar(32) NOT NULL,
			`notes` TEXT NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `code` (`code`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_discount_codes (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`code` varchar(32) NOT NULL,
			`starts` date NOT NULL,
			`expires` date NOT NULL,
			`uses` int(11) NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `code` (`code`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_discount_codes_levels (
			`code_id` bigint(20) unsigned NOT NULL,
			`level_id` int(11) unsigned NOT NULL,
			`initial_payment` decimal(18,8) NOT NULL DEFAULT '0.00',
			`billing_amount` decimal(18,8) NOT NULL DEFAULT '0.00',
			`cycle_number` int(11) NOT NULL DEFAULT '0',
			`cycle_period` varchar(10) DEFAULT 'Month',
			`billing_limit` int(11) NOT NULL,
			`trial_amount` decimal(18,8) NOT NULL DEFAULT '0.00',
			`trial_limit` int(11) NOT NULL DEFAULT '0',
			`expiration_number` int(10) unsigned NOT NULL,
			`expiration_period` varchar(10) NOT NULL,
			PRIMARY KEY (`code_id`,`level_id`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_discount_codes_uses (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`code_id` bigint(20) unsigned NOT NULL,
			`user_id` bigint(20) unsigned NOT NULL,
			`order_id` bigint(20) unsigned NOT NULL,
			`timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_subscriptions (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) unsigned NOT NULL,
			`membership_level_id` int(11) unsigned NOT NULL,
			`gateway` varchar(64) NOT NULL,
			`gateway_environment` varchar(64) NOT NULL,
			`subscription_transaction_id` varchar(32) NOT NULL,
			`status` varchar(20) NOT NULL DEFAULT 'active',
			`startdate` datetime DEFAULT NULL,
			`enddate` datetime DEFAULT NULL,
			`next_payment_date` datetime DEFAULT NULL,
			`billing_amount` decimal(18,8) NOT NULL DEFAULT '0.00',
			`cycle_number` int(11) NOT NULL DEFAULT '0',
			`cycle_period` varchar(10) NOT NULL DEFAULT 'Month',
			`billing_limit` int(11) NOT NULL DEFAULT '0',
			`trial_amount` decimal(18,8) NOT NULL DEFAULT '0.00',
			`trial_limit` int(11) NOT NULL DEFAULT '0',
			`modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_groups (
			`id` int unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(255) NOT NULL,
			`allow_multiple_selections` tinyint NOT NULL DEFAULT '1',
			`displayorder` int,
			PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE {$p}pmpro_membership_levels_groups (
			`id` int unsigned NOT NULL AUTO_INCREMENT,
			`level` int unsigned NOT NULL DEFAULT '0',
			`group` int unsigned NOT NULL DEFAULT '0',
			PRIMARY KEY (`id`)
		) $c" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	function wpmcp_test_drop_pmpro_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_pmpro_tables() as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		}
	}

	/** One level row as PMPro's level screen saves it; returns its id. */
	function wpmcp_test_pmpro_level( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'pmpro_membership_levels', $row + [
			'name'              => 'Gold',
			'description'       => 'The gold level',
			'confirmation'      => '',
			'initial_payment'   => '99.00',
			'billing_amount'    => '99.00',
			'cycle_number'      => 1,
			'cycle_period'      => 'Year',
			'billing_limit'     => 0,
			'trial_amount'      => '0.00',
			'trial_limit'       => 0,
			'allow_signups'     => 1,
			'expiration_number' => 0,
			'expiration_period' => '',
		] );
		return (int) $wpdb->insert_id;
	}

	/** One membership row the way pmpro_changeMembershipLevel() inserts it; returns its id. */
	function wpmcp_test_pmpro_membership( int $user_id, int $level_id, string $status = 'active', string $start = '2026-10-01 09:00:00' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'pmpro_memberships_users', [
			'user_id'         => $user_id,
			'membership_id'   => $level_id,
			'code_id'         => 0,
			'initial_payment' => '0',
			'billing_amount'  => '0',
			'cycle_number'    => 0,
			'cycle_period'    => '0',
			'billing_limit'   => 0,
			'trial_amount'    => '0',
			'trial_limit'     => 0,
			'status'          => $status,
			'startdate'       => $start,
			'enddate'         => 'active' === $status ? null : '2026-10-15 09:00:00',
		] );
		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- stands in for Paid Memberships Pro's own function.
	function pmpro_changeMembershipLevel( $level, $user_id = null, $old_level_status = 'inactive', $cancel_level = null ) {
		global $wpdb;
		$level_id = (int) $level;
		$table    = $wpdb->prefix . 'pmpro_memberships_users';
		$held     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND membership_id = %d AND status = %s', $table, $user_id, $level_id, 'active' ) );
		if ( $held > 0 ) {
			return null;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, enddate = %s WHERE user_id = %d AND status = %s', $table, $old_level_status, current_time( 'mysql' ), $user_id, 'active' ) );
		wpmcp_test_pmpro_membership( (int) $user_id, $level_id, 'active', current_time( 'mysql' ) );
		do_action( 'pmpro_after_change_membership_level', $level_id, (int) $user_id, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PMPro's own hook.
		return true;
	}
}
