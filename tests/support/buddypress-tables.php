<?php
/**
 * The BuddyPress groups, activity and extended profile tables, for tests that
 * exercise the BuddyPress ops without the plugin installed in the shared test
 * core.
 *
 * Copied from BuddyPress 14.5.2, bp-core/admin/bp-core-admin-schema.php
 * (bp_core_install_groups(), bp_core_install_activity_streams(),
 * bp_core_install_extended_profiles(), bp_core_install_notifications() and
 * bp_core_install_invitations()), under bp_core_get_table_prefix(), which is
 * the network base prefix.
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass(), outside the
 * per-test transaction. Rows written inside a test still roll back with the
 * test's transaction.
 */

if ( ! function_exists( 'wpmcp_test_bp_tables' ) ) {
	/** @return string[] every BuddyPress table the fixture creates, unprefixed. */
	function wpmcp_test_bp_tables(): array {
		return [
			'bp_groups',
			'bp_groups_members',
			'bp_groups_groupmeta',
			'bp_activity',
			'bp_activity_meta',
			'bp_xprofile_groups',
			'bp_xprofile_fields',
			'bp_xprofile_meta',
			'bp_notifications',
			'bp_notifications_meta',
			'bp_invitations',
		];
	}
}

if ( ! function_exists( 'wpmcp_test_create_buddypress_tables' ) ) {
	function wpmcp_test_create_buddypress_tables(): void {
		global $wpdb;

		wpmcp_test_drop_buddypress_tables();
		$p       = $wpdb->base_prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}bp_groups (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			creator_id bigint(20) NOT NULL,
			name varchar(100) NOT NULL,
			slug varchar(200) NOT NULL,
			description longtext NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'public',
			parent_id bigint(20) NOT NULL DEFAULT 0,
			enable_forum tinyint(1) NOT NULL DEFAULT '1',
			date_created datetime NOT NULL,
			KEY creator_id (creator_id),
			KEY status (status),
			KEY parent_id (parent_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_groups_members (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			group_id bigint(20) NOT NULL,
			user_id bigint(20) NOT NULL,
			inviter_id bigint(20) NOT NULL,
			is_admin tinyint(1) NOT NULL DEFAULT '0',
			is_mod tinyint(1) NOT NULL DEFAULT '0',
			user_title varchar(100) NOT NULL,
			date_modified datetime NOT NULL,
			comments longtext NOT NULL,
			is_confirmed tinyint(1) NOT NULL DEFAULT '0',
			is_banned tinyint(1) NOT NULL DEFAULT '0',
			invite_sent tinyint(1) NOT NULL DEFAULT '0',
			KEY group_id (group_id),
			KEY user_id (user_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_groups_groupmeta (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			group_id bigint(20) NOT NULL,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext DEFAULT NULL,
			KEY group_id (group_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_activity (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			user_id bigint(20) NOT NULL,
			component varchar(75) NOT NULL,
			type varchar(75) NOT NULL,
			action text NOT NULL,
			content longtext NOT NULL,
			primary_link text NOT NULL,
			item_id bigint(20) NOT NULL,
			secondary_item_id bigint(20) DEFAULT NULL,
			date_recorded datetime NOT NULL,
			hide_sitewide tinyint(1) DEFAULT 0,
			mptt_left int(11) NOT NULL DEFAULT 0,
			mptt_right int(11) NOT NULL DEFAULT 0,
			is_spam tinyint(1) NOT NULL DEFAULT 0,
			KEY item_id (item_id),
			KEY secondary_item_id (secondary_item_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_activity_meta (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			activity_id bigint(20) NOT NULL,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext DEFAULT NULL,
			KEY activity_id (activity_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_xprofile_groups (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			name varchar(150) NOT NULL,
			description mediumtext NOT NULL,
			group_order bigint(20) NOT NULL DEFAULT '0',
			can_delete tinyint(1) NOT NULL
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_xprofile_fields (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			group_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL,
			type varchar(150) NOT NULL,
			name varchar(150) NOT NULL,
			description longtext NOT NULL,
			is_required tinyint(1) NOT NULL DEFAULT '0',
			is_default_option tinyint(1) NOT NULL DEFAULT '0',
			field_order bigint(20) NOT NULL DEFAULT '0',
			option_order bigint(20) NOT NULL DEFAULT '0',
			order_by varchar(15) NOT NULL DEFAULT '',
			can_delete tinyint(1) NOT NULL DEFAULT '1',
			KEY group_id (group_id),
			KEY parent_id (parent_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_xprofile_meta (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			object_id bigint(20) NOT NULL,
			object_type varchar(150) NOT NULL,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext DEFAULT NULL,
			KEY object_id (object_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_notifications (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			user_id bigint(20) NOT NULL,
			item_id bigint(20) NOT NULL,
			secondary_item_id bigint(20),
			component_name varchar(75) NOT NULL,
			component_action varchar(75) NOT NULL,
			date_notified datetime NOT NULL,
			is_new tinyint(1) NOT NULL DEFAULT 0,
			KEY item_id (item_id),
			KEY user_id (user_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_notifications_meta (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			notification_id bigint(20) NOT NULL,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext DEFAULT NULL,
			KEY notification_id (notification_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bp_invitations (
			id bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			user_id bigint(20) NOT NULL,
			inviter_id bigint(20) NOT NULL,
			invitee_email varchar(100) DEFAULT NULL,
			class varchar(120) NOT NULL,
			item_id bigint(20) NOT NULL,
			secondary_item_id bigint(20) DEFAULT NULL,
			type varchar(12) NOT NULL DEFAULT 'invite',
			content longtext DEFAULT '',
			date_modified datetime NOT NULL,
			invite_sent tinyint(1) NOT NULL DEFAULT '0',
			accepted tinyint(1) NOT NULL DEFAULT '0',
			KEY item_id (item_id)
		) {$charset}" );
		// phpcs:enable
	}
}

if ( ! function_exists( 'wpmcp_test_drop_buddypress_tables' ) ) {
	function wpmcp_test_drop_buddypress_tables(): void {
		global $wpdb;
		$tables = array_map( static fn ( string $t ): string => $wpdb->base_prefix . $t, wpmcp_test_bp_tables() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $tables ) );
	}
}

if ( ! function_exists( 'wpmcp_test_bp_insert' ) ) {
	/** Insert one row into a BuddyPress table (unprefixed name) and return its id. */
	function wpmcp_test_bp_insert( string $table, array $row ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->base_prefix . $table, $row );
		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'wpmcp_test_bp_group' ) ) {
	/** Insert a group the way BP_Groups_Group::save() stores one and return its id. */
	function wpmcp_test_bp_group( string $name, string $status = 'public', int $creator_id = 1, array $columns = [] ): int {
		return wpmcp_test_bp_insert(
			'bp_groups',
			array_merge(
				[
					'creator_id'   => $creator_id,
					'name'         => $name,
					'slug'         => sanitize_title( $name ),
					'description'  => 'About ' . $name,
					'status'       => $status,
					'parent_id'    => 0,
					'enable_forum' => 0,
					'date_created' => '2026-01-02 03:04:05',
				],
				$columns
			)
		);
	}
}

if ( ! function_exists( 'wpmcp_test_bp_member' ) ) {
	/** Insert a group membership row and return its id. */
	function wpmcp_test_bp_member( int $group_id, int $user_id, array $columns = [] ): int {
		return wpmcp_test_bp_insert(
			'bp_groups_members',
			array_merge(
				[
					'group_id'      => $group_id,
					'user_id'       => $user_id,
					'inviter_id'    => 0,
					'is_admin'      => 0,
					'is_mod'        => 0,
					'user_title'    => '',
					'date_modified' => '2026-01-02 03:04:05',
					'comments'      => 'Please let me in, reach me at private@example.com',
					'is_confirmed'  => 1,
					'is_banned'     => 0,
					'invite_sent'   => 0,
				],
				$columns
			)
		);
	}
}

if ( ! function_exists( 'wpmcp_test_bp_activity' ) ) {
	/** Insert an activity item and return its id. */
	function wpmcp_test_bp_activity( int $user_id, string $content, array $columns = [] ): int {
		return wpmcp_test_bp_insert(
			'bp_activity',
			array_merge(
				[
					'user_id'           => $user_id,
					'component'         => 'activity',
					'type'              => 'activity_update',
					'action'            => 'A member posted an update',
					'content'           => $content,
					'primary_link'      => 'https://example.org/members/someone/',
					'item_id'           => 0,
					'secondary_item_id' => 0,
					'date_recorded'     => '2026-01-02 03:04:05',
					'hide_sitewide'     => 0,
					'mptt_left'         => 1,
					'mptt_right'        => 2,
					'is_spam'           => 0,
				],
				$columns
			)
		);
	}
}

if ( ! function_exists( 'wpmcp_test_bp_dump' ) ) {
	/**
	 * Every row of every BuddyPress fixture table, id order, exactly as the
	 * database holds them: a rollback is exact when this matches.
	 *
	 * @return array<string, array<int, array<string, string|null>>>
	 */
	function wpmcp_test_bp_dump(): array {
		global $wpdb;
		$out = [];
		foreach ( wpmcp_test_bp_tables() as $table ) {
			$out[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $wpdb->base_prefix . $table ), ARRAY_A );
		}
		return $out;
	}
}
