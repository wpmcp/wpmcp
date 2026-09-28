<?php
/**
 * The Duplicator and Solid Security tables the operations status adapters
 * read, for tests that exercise them without either plugin installed in the
 * shared test core.
 *
 * Copied from the free plugins' own schema:
 *  - Duplicator 5.0.4, src/Package/TraitPackagePersistence.php initTable()
 *    ({base_prefix}duplicator_backups), plus the pre-5 legacy table
 *    ({prefix}duplicator_packages) its migration reads;
 *  - Solid Security 10.0.4, core/lib/schema.php ({base_prefix}itsec_logs,
 *    itsec_lockouts and itsec_bans).
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass(), outside the
 * per-test transaction. Rows written inside a test still roll back with the
 * test's transaction.
 */

if ( ! function_exists( 'wpmcp_test_create_ops_status_tables' ) ) {
	function wpmcp_test_create_ops_status_tables(): void {
		global $wpdb;

		wpmcp_test_drop_ops_status_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE `{$wpdb->base_prefix}duplicator_backups` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`type` varchar(100) NOT NULL,
			`name` varchar(250) NOT NULL,
			`hash` varchar(50) NOT NULL,
			`archive_name` varchar(350) NOT NULL DEFAULT '',
			`status` int(11) NOT NULL,
			`flags` varchar(500) NOT NULL DEFAULT '',
			`package` longtext NOT NULL,
			`version` varchar(30) NOT NULL DEFAULT '',
			`created` TIMESTAMP NOT NULL DEFAULT '1970-01-02 00:00:00',
			`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `type_idx` (`type`),
			KEY `status` (`status`),
			KEY `created` (`created`)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->prefix}duplicator_packages` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(250) NOT NULL,
			`hash` varchar(50) NOT NULL,
			`status` int(11) NOT NULL,
			`created` datetime NOT NULL DEFAULT '1970-01-02 00:00:00',
			`owner` varchar(60) NOT NULL,
			`package` longtext NOT NULL,
			PRIMARY KEY (`id`),
			KEY `hash` (`hash`)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->base_prefix}itsec_logs` (
			id bigint(20) unsigned NOT NULL auto_increment,
			parent_id bigint(20) unsigned NOT NULL default '0',
			module varchar(50) NOT NULL default '',
			code varchar(100) NOT NULL default '',
			data longtext NOT NULL,
			type varchar(20) NOT NULL default 'notice',
			timestamp datetime NOT NULL default '1970-01-02 00:00:00',
			init_timestamp datetime NOT NULL default '1970-01-02 00:00:00',
			memory_current bigint(20) unsigned NOT NULL default '0',
			memory_peak bigint(20) unsigned NOT NULL default '0',
			url varchar(500) NOT NULL default '',
			blog_id bigint(20) NOT NULL default '0',
			user_id bigint(20) unsigned NOT NULL default '0',
			remote_ip varchar(50) NOT NULL default '',
			PRIMARY KEY  (id),
			KEY module (module),
			KEY code (code),
			KEY type (type),
			KEY timestamp (timestamp)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->base_prefix}itsec_lockouts` (
			lockout_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lockout_type varchar(25) NOT NULL,
			lockout_start datetime NOT NULL,
			lockout_start_gmt datetime NOT NULL,
			lockout_expire datetime NOT NULL,
			lockout_expire_gmt datetime NOT NULL,
			lockout_host varchar(40),
			lockout_user bigint(20) UNSIGNED,
			lockout_username varchar(60),
			lockout_active int(1) NOT NULL DEFAULT 1,
			lockout_context TEXT,
			PRIMARY KEY  (lockout_id),
			KEY lockout_expire_gmt (lockout_expire_gmt),
			KEY lockout_active (lockout_active)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE `{$wpdb->base_prefix}itsec_bans` (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			host varchar(64) NOT NULL,
			type varchar(20) NOT NULL default 'ip',
			created_at datetime NOT NULL,
			actor_type varchar(20),
			actor_id varchar(128),
			comment varchar(255) NOT NULL default '',
			PRIMARY KEY  (id),
			UNIQUE KEY host (host)
		) {$charset}" );
		// phpcs:enable
	}
}

if ( ! function_exists( 'wpmcp_test_drop_ops_status_tables' ) ) {
	function wpmcp_test_drop_ops_status_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}duplicator_backups, {$wpdb->prefix}duplicator_packages, {$wpdb->base_prefix}itsec_logs, {$wpdb->base_prefix}itsec_lockouts, {$wpdb->base_prefix}itsec_bans" );
	}
}

if ( ! function_exists( 'wpmcp_test_duplicator_backup' ) ) {
	/**
	 * Insert a Duplicator 5 backup row the way its persistence trait stores
	 * one (the package column is the model's JSON) and return its id.
	 */
	function wpmcp_test_duplicator_backup( string $name, int $status, string $created, array $flags = [ 'FULL_BACKUP', 'HAVE_LOCAL', 'ZIP_ARCHIVE' ] ): int {
		global $wpdb;
		$hash = 'b2f1c9d4e7a80361_20260901120000';
		$wpdb->insert(
			$wpdb->base_prefix . 'duplicator_backups',
			[
				'type'         => 'DUP_PACKAGE',
				'name'         => $name,
				'hash'         => $hash,
				'archive_name' => $name . '_' . $hash . '_archive.zip',
				'status'       => $status,
				'flags'        => implode( ',', $flags ),
				'package'      => wp_json_encode( [ 'Name' => $name, 'Hash' => $hash, 'Archive' => [ 'File' => $name . '_' . $hash . '_archive.zip' ] ] ),
				'version'      => '5.0.4',
				'created'      => $created,
			]
		);

		return (int) $wpdb->insert_id;
	}
}
