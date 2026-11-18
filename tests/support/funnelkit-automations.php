<?php
/**
 * FunnelKit Automations fixtures (issue #831): the tables its contact,
 * list, tag and automation ops read and write, and a stand-in for the calls
 * into FunnelKit's own API, for tests that run without the plugin installed.
 *
 * The tables are copied from the ones FunnelKit Automations 3.8.0 and its
 * bundled WooFunnels core create (bwf_contact, bwfan_terms,
 * bwfan_automations, bwfan_automation_contact,
 * bwfan_automation_complete_contact, bwfan_automation_step,
 * bwfan_message_unsubscribe), with the plain indexes left out.
 *
 * Fake_FunnelKit extends the shipped adapter, so its catalog, checks, reads
 * and snapshots are the ones under test; only the api_* calls are replaced,
 * by writes shaped the way FunnelKit writes them: a contact's tags and lists
 * as a JSON array of id strings on its bwf_contact row, an automation's
 * status as 1 (active) or 2 (inactive).
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass().
 */

namespace WPMCP\Tests\Support;

use WPMCP\Integrations\CRM_FunnelKit;

if ( ! function_exists( __NAMESPACE__ . '\\fka_tables' ) ) {
	/** @return string[] every table the fixture creates, unprefixed. */
	function fka_tables(): array {
		return [ 'bwf_contact', 'bwfan_terms', 'bwfan_automations', 'bwfan_automation_contact', 'bwfan_automation_complete_contact', 'bwfan_automation_step', 'bwfan_message_unsubscribe' ];
	}

	function fka_drop_tables(): void {
		global $wpdb;
		foreach ( fka_tables() as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $name ) );
		}
	}

	function fka_create_tables(): void {
		global $wpdb;

		fka_drop_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}bwf_contact (
			`id` int(12) unsigned NOT NULL AUTO_INCREMENT,
			`wpid` int(12) NOT NULL,
			`uid` varchar(35) NOT NULL DEFAULT '',
			`email` varchar(100) NOT NULL,
			`f_name` varchar(100),
			`l_name` varchar(100),
			`contact_no` varchar(20),
			`country` char(2),
			`state` varchar(100),
			`timezone` varchar(50) DEFAULT '',
			`type` varchar(20) DEFAULT 'lead',
			`source` varchar(100) DEFAULT '',
			`points` bigint(20) unsigned NOT NULL DEFAULT '0',
			`tags` longtext,
			`lists` longtext,
			`last_modified` DateTime NOT NULL,
			`creation_date` DateTime NOT NULL,
			`status` tinyint unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY (`id`),
			UNIQUE KEY `email` (`email`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bwfan_terms (
			`ID` bigint(20) unsigned NOT NULL auto_increment,
			`name` varchar(255) NOT NULL,
			`type` tinyint(2) unsigned NOT NULL,
			`data` longtext,
			`created_at` datetime,
			`updated_at` datetime,
			PRIMARY KEY (`ID`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bwfan_automations (
			`ID` bigint(20) unsigned NOT NULL auto_increment,
			`source` varchar(60) NOT NULL,
			`event` varchar(60) NOT NULL,
			`status` tinyint(1) NOT NULL default 0,
			`priority` tinyint(3) NOT NULL default 0,
			`start` bigint(10) UNSIGNED NOT NULL,
			`v` tinyint(1) UNSIGNED NOT NULL default 1,
			`benchmark` longtext,
			`title` varchar(255) NULL,
			`category` longtext,
			PRIMARY KEY (`ID`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bwfan_automation_contact (
			`ID` bigint(20) unsigned NOT NULL auto_increment,
			`cid` bigint(20) unsigned NOT NULL,
			`aid` bigint(10) unsigned NOT NULL,
			`event` varchar(120) NOT NULL,
			`c_date` datetime NOT NULL default '0000-00-00 00:00:00',
			`e_time` bigint(12) unsigned NOT NULL,
			`status` tinyint(1) UNSIGNED NOT NULL default 1,
			`last` bigint(10) UNSIGNED NOT NULL default 0,
			`last_time` bigint(12) UNSIGNED NOT NULL,
			`data` longtext,
			`claim_id` bigint(20) UNSIGNED NOT NULL default 0,
			`attempts` tinyint(1) UNSIGNED NOT NULL default 0,
			`trail` varchar(40) NULL,
			PRIMARY KEY (`ID`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bwfan_automation_complete_contact (
			`ID` bigint(20) unsigned NOT NULL auto_increment,
			`cid` bigint(20) unsigned NOT NULL,
			`aid` bigint(10) unsigned NOT NULL,
			`event` varchar(120) NOT NULL,
			`s_date` datetime NOT NULL default '0000-00-00 00:00:00',
			`c_date` datetime NOT NULL default '0000-00-00 00:00:00',
			`data` longtext,
			`trail` varchar(40) NULL,
			PRIMARY KEY (`ID`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bwfan_automation_step (
			`ID` bigint(10) UNSIGNED NOT NULL auto_increment,
			`aid` bigint(10) UNSIGNED NOT NULL,
			`type` tinyint(1) UNSIGNED NOT NULL default 1,
			`action` varchar(255) NULL,
			`status` tinyint(1) NOT NULL default 0,
			`data` longtext,
			`created_at` datetime NOT NULL default '0000-00-00 00:00:00',
			`updated_at` datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY (`ID`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}bwfan_message_unsubscribe (
			`ID` bigint(20) unsigned NOT NULL auto_increment,
			`recipient` varchar(255) default NULL,
			`mode` tinyint(1) NOT NULL default 1,
			`c_date` datetime NOT NULL default '0000-00-00 00:00:00',
			`automation_id` bigint(20) unsigned default '0',
			`c_type` tinyint(1) NOT NULL default '1',
			`sid` bigint(20) unsigned NOT NULL default 0,
			PRIMARY KEY (`ID`)
		) {$charset}" );
		// phpcs:enable
	}
}

if ( ! class_exists( Fake_FunnelKit::class ) ) {
	class Fake_FunnelKit extends CRM_FunnelKit {
		/** @var array<int, array{0: string, 1: array}> */
		public static array $calls = [];

		/** When set, the next attach also adds this tag id, the way an automation run by FunnelKit's hook would. */
		public static ?int $hook_adds_tag = null;

		private static function t( string $name ): string {
			global $wpdb;
			return $wpdb->prefix . $name;
		}

		/** @return string[] */
		private static function column( int $contact, string $column ): array {
			global $wpdb;
			$raw = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE id = %d', $column, self::t( 'bwf_contact' ), $contact ) );
			$ids = json_decode( $raw, true );
			return is_array( $ids ) ? array_map( 'strval', $ids ) : [];
		}

		private static function put( int $contact, string $column, array $ids ): void {
			global $wpdb;
			$wpdb->update( self::t( 'bwf_contact' ), [ $column => wp_json_encode( array_values( array_map( 'strval', $ids ) ) ), 'last_modified' => current_time( 'mysql', true ) ], [ 'id' => $contact ] );
		}

		protected function api_attach( int $contact, string $kind, array $ids ): void {
			self::$calls[] = [ 'attach', func_get_args() ];
			$column        = 'tag' === $kind ? 'tags' : 'lists';
			$now           = self::column( $contact, $column );
			self::put( $contact, $column, array_values( array_unique( array_merge( $now, array_map( 'strval', $ids ) ) ) ) );
			if ( null !== self::$hook_adds_tag ) {
				$tags = self::column( $contact, 'tags' );
				self::put( $contact, 'tags', array_values( array_unique( array_merge( $tags, [ (string) self::$hook_adds_tag ] ) ) ) );
				self::$hook_adds_tag = null;
			}
		}

		protected function api_detach( int $contact, string $kind, array $ids ): void {
			self::$calls[] = [ 'detach', func_get_args() ];
			$column        = 'tag' === $kind ? 'tags' : 'lists';
			$now           = self::column( $contact, $column );
			self::put( $contact, $column, array_values( array_diff( $now, array_map( 'strval', $ids ) ) ) );
		}

		protected function api_set_automation_status( int $id, int $status ): void {
			global $wpdb;
			self::$calls[] = [ 'automation_status', func_get_args() ];
			$wpdb->update( self::t( 'bwfan_automations' ), [ 'status' => $status ], [ 'ID' => $id ] );
		}
	}
}
