<?php
/**
 * The Redirection plugin's redirect and group tables, for tests that exercise
 * the Redirection pack without the plugin installed in the shared test core.
 *
 * Both are copied from the free plugin's own schema
 * (includes/database/schema/class-latest.php, create_items_sql() and
 * create_groups_sql(), plugin 5.10.1, database version 4.2).
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass(), outside the
 * per-test transaction, the way the AIOSEO table tests do. Rows written inside
 * a test still roll back with the test's transaction.
 */

if ( ! function_exists( 'wpmcp_test_create_redirection_tables' ) ) {
	function wpmcp_test_create_redirection_tables(): void {
		global $wpdb;

		wpmcp_test_drop_redirection_tables();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}redirection_items` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`url` mediumtext NOT NULL,
			`match_url` VARCHAR(2000) DEFAULT NULL,
			`match_data` TEXT DEFAULT NULL,
			`regex` INT(11) unsigned NOT NULL DEFAULT 0,
			`position` INT(11) unsigned NOT NULL DEFAULT 0,
			`last_count` INT(10) unsigned NOT NULL DEFAULT 0,
			`last_access` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			`group_id` INT(11) NOT NULL DEFAULT 0,
			`status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
			`action_type` VARCHAR(20) NOT NULL,
			`action_code` INT(11) unsigned NOT NULL,
			`action_data` MEDIUMTEXT DEFAULT NULL,
			`match_type` VARCHAR(20) NOT NULL,
			`title` TEXT DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `url` (`url`(191)),
			KEY `status` (`status`),
			KEY `regex` (`regex`),
			KEY `group_idpos` (`group_id`,`position`),
			KEY `group` (`group_id`),
			KEY `match_url` (`match_url`(191))
		) {$wpdb->get_charset_collate()}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}redirection_groups` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`name` VARCHAR(50) NOT NULL,
			`tracking` INT(11) NOT NULL DEFAULT 1,
			`module_id` INT(11) unsigned NOT NULL DEFAULT 0,
			`status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
			`position` INT(11) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `module_id` (`module_id`),
			KEY `status` (`status`)
		) {$wpdb->get_charset_collate()}" );
	}
}

if ( ! function_exists( 'wpmcp_test_drop_redirection_tables' ) ) {
	function wpmcp_test_drop_redirection_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}redirection_items, {$wpdb->prefix}redirection_groups" );
	}
}

if ( ! function_exists( 'wpmcp_test_redirection_group' ) ) {
	/** Insert a group the way Red_Group::create() does and return its id. */
	function wpmcp_test_redirection_group( string $name, int $module_id = 1, string $status = 'enabled' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'redirection_groups',
			[
				'name'      => $name,
				'module_id' => $module_id,
				'status'    => $status,
				'position'  => 0,
			]
		);

		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'wpmcp_test_redirection_item' ) ) {
	/**
	 * Insert a plain URL redirect row the way Red_Item::create() stores one
	 * and return its id. $columns overrides any column.
	 */
	function wpmcp_test_redirection_item( string $source, string $target, int $group_id, array $columns = [] ): int {
		global $wpdb;
		$match = strtolower( rtrim( $source, '/' ) );
		$wpdb->insert(
			$wpdb->prefix . 'redirection_items',
			array_merge(
				[
					'url'         => $source,
					'match_url'   => '' === $match ? '/' : $match,
					'match_data'  => null,
					'regex'       => 0,
					'position'    => 0,
					'group_id'    => $group_id,
					'status'      => 'enabled',
					'action_type' => 'url',
					'action_code' => 301,
					'action_data' => $target,
					'match_type'  => 'url',
					'title'       => null,
				],
				$columns
			)
		);

		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'wpmcp_test_redirection_row' ) ) {
	/** One redirect row exactly as the database holds it, or null. */
	function wpmcp_test_redirection_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'redirection_items', $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
