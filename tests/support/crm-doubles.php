<?php
/**
 * Stand-ins for the FluentCRM and MailPoet API calls the CRM ops make (issue
 * #477), for tests that run without either plugin installed. Each extends
 * the real adapter, so the catalog, checks, reads and snapshots under test
 * are the shipped ones; only the api_* calls are replaced, by direct writes
 * to the fixture tables (tests/support/crm-tables.php) shaped the way each
 * plugin writes them: FluentCRM's pivot rows and custom field meta,
 * MailPoet's per-list statuses and trash. Every call is recorded in $calls.
 *
 * The real API calls run in the live CRM group (CrmLiveTest), against the
 * real plugins.
 */

namespace WPMCP\Tests\Support;

use WPMCP\Integrations\CRM_FluentCRM;
use WPMCP\Integrations\CRM_MailPoet;

if ( ! class_exists( Fake_FluentCRM::class ) ) {
	class Fake_FluentCRM extends CRM_FluentCRM {
		/** @var array<int, array{0: string, 1: array}> */
		public static array $calls = [];

		private static function t( string $name ): string {
			global $wpdb;
			return $wpdb->prefix . $name;
		}

		private static function now(): string {
			return current_time( 'mysql' );
		}

		protected function api_create_contact( string $email, array $fields, array $custom, array $lists, array $tags, string $status ): int {
			global $wpdb;
			self::$calls[] = [ 'create', func_get_args() ];
			$wpdb->insert( self::t( 'fc_subscribers' ), [ 'email' => $email, 'hash' => md5( $email ), 'status' => $status, 'created_at' => self::now(), 'updated_at' => self::now() ] + $fields );
			$id = (int) $wpdb->insert_id;
			$this->api_update_contact( $id, [], $custom );
			$this->api_attach( $id, 'list', $lists );
			$this->api_attach( $id, 'tag', $tags );
			if ( 'subscribed' !== $status ) {
				$wpdb->insert( self::t( 'fc_subscriber_meta' ), [ 'subscriber_id' => $id, 'created_by' => 0, 'object_type' => 'option', 'key' => '_last_double_optin_timestamp', 'value' => (string) time() ] );
			}
			return $id;
		}

		protected function api_update_contact( int $id, array $fields, array $custom ): void {
			global $wpdb;
			self::$calls[] = [ 'update', func_get_args() ];
			if ( [] !== $fields ) {
				$wpdb->update( self::t( 'fc_subscribers' ), $fields + [ 'updated_at' => self::now() ], [ 'id' => $id ] );
			}
			foreach ( $custom as $key => $value ) {
				$row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE subscriber_id = %d AND object_type = 'custom_field' AND `key` = %s", self::t( 'fc_subscriber_meta' ), $id, $key ) );
				if ( null === $row ) {
					$wpdb->insert( self::t( 'fc_subscriber_meta' ), [ 'subscriber_id' => $id, 'created_by' => get_current_user_id(), 'object_type' => 'custom_field', 'key' => $key, 'value' => maybe_serialize( $value ) ] );
				} else {
					$wpdb->update( self::t( 'fc_subscriber_meta' ), [ 'value' => maybe_serialize( $value ) ], [ 'id' => (int) $row ] );
				}
			}
		}

		protected function api_unsubscribe( int $id ): void {
			global $wpdb;
			self::$calls[] = [ 'unsubscribe', func_get_args() ];
			$wpdb->update( self::t( 'fc_subscribers' ), [ 'status' => 'unsubscribed' ], [ 'id' => $id ] );
		}

		protected function api_delete_contact( int $id ): void {
			global $wpdb;
			self::$calls[] = [ 'delete', func_get_args() ];
			$wpdb->delete( self::t( 'fc_subscribers' ), [ 'id' => $id ] );
			foreach ( [ 'fc_subscriber_pivot', 'fc_subscriber_meta', 'fc_subscriber_notes', 'fc_funnel_subscribers', 'fc_funnel_metrics', 'fc_campaign_emails', 'fc_campaign_url_metrics' ] as $table ) {
				$wpdb->delete( self::t( $table ), [ 'subscriber_id' => $id ] );
			}
		}

		private static function type( string $kind ): string {
			return 'list' === $kind ? 'FluentCrm\\App\\Models\\Lists' : 'FluentCrm\\App\\Models\\Tag';
		}

		protected function api_attach( int $contact, string $kind, array $ids ): void {
			global $wpdb;
			self::$calls[] = [ 'attach', func_get_args() ];
			foreach ( $ids as $id ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (subscriber_id, object_id, object_type, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)', self::t( 'fc_subscriber_pivot' ), $contact, $id, self::type( $kind ), self::now(), self::now() ) );
			}
		}

		protected function api_detach( int $contact, string $kind, array $ids ): void {
			global $wpdb;
			self::$calls[] = [ 'detach', func_get_args() ];
			foreach ( $ids as $id ) {
				$wpdb->delete( self::t( 'fc_subscriber_pivot' ), [ 'subscriber_id' => $contact, 'object_id' => $id, 'object_type' => self::type( $kind ) ] );
			}
		}

		protected function api_create_group( string $kind, string $title, string $description ): int {
			global $wpdb;
			self::$calls[] = [ 'create_group', func_get_args() ];
			$wpdb->insert( self::t( 'list' === $kind ? 'fc_lists' : 'fc_tags' ), [ 'title' => $title, 'slug' => sanitize_title( $title ), 'description' => $description, 'created_at' => self::now() ] );
			return (int) $wpdb->insert_id;
		}

		protected function api_update_group( string $kind, int $id, array $changes ): void {
			global $wpdb;
			self::$calls[] = [ 'update_group', func_get_args() ];
			$wpdb->update( self::t( 'list' === $kind ? 'fc_lists' : 'fc_tags' ), $changes, [ 'id' => $id ] );
		}

		protected function api_delete_group( string $kind, int $id ): void {
			global $wpdb;
			self::$calls[] = [ 'delete_group', func_get_args() ];
			$wpdb->delete( self::t( 'list' === $kind ? 'fc_lists' : 'fc_tags' ), [ 'id' => $id ] );
			$wpdb->delete( self::t( 'fc_subscriber_pivot' ), [ 'object_id' => $id, 'object_type' => self::type( $kind ) ] );
		}
	}
}

if ( ! class_exists( Fake_MailPoet::class ) ) {
	class Fake_MailPoet extends CRM_MailPoet {
		/** @var array<int, array{0: string, 1: array}> */
		public static array $calls = [];

		/** MailPoet's sign-up confirmation setting, as the double sees it. */
		public static bool $confirmation = true;

		private static function t( string $name ): string {
			global $wpdb;
			return $wpdb->prefix . $name;
		}

		private static function now(): string {
			return current_time( 'mysql' );
		}

		protected function api_create_contact( string $email, array $fields, array $custom, array $lists, array $tags, string $status ): int {
			global $wpdb;
			self::$calls[] = [ 'create', func_get_args() ];
			$wpdb->insert( self::t( 'mailpoet_subscribers' ), [ 'email' => $email, 'status' => 'unconfirmed', 'source' => 'api', 'created_at' => self::now() ] + $fields );
			$id = (int) $wpdb->insert_id;
			$this->api_update_contact( $id, [], $custom );
			if ( [] !== $lists ) {
				$this->api_attach( $id, 'list', $lists );
			}
			$this->api_attach( $id, 'tag', $tags );
			return $id;
		}

		protected function api_update_contact( int $id, array $fields, array $custom ): void {
			global $wpdb;
			self::$calls[] = [ 'update', func_get_args() ];
			if ( [] !== $fields ) {
				$wpdb->update( self::t( 'mailpoet_subscribers' ), $fields, [ 'id' => $id ] );
			}
			foreach ( $custom as $key => $value ) {
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (subscriber_id, custom_field_id, value, created_at) VALUES (%d, %d, %s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value)', self::t( 'mailpoet_subscriber_custom_field' ), $id, (int) substr( (string) $key, 3 ), (string) $value, self::now() ) );
			}
		}

		protected function api_unsubscribe( int $id ): void {
			global $wpdb;
			self::$calls[] = [ 'unsubscribe', func_get_args() ];
			$wpdb->update( self::t( 'mailpoet_subscribers' ), [ 'status' => 'unsubscribed' ], [ 'id' => $id ] );
			$wpdb->update( self::t( 'mailpoet_subscriber_segment' ), [ 'status' => 'unsubscribed' ], [ 'subscriber_id' => $id ] );
		}

		protected function api_delete_contact( int $id ): void {
			global $wpdb;
			self::$calls[] = [ 'delete', func_get_args() ];
			$wpdb->update( self::t( 'mailpoet_subscribers' ), [ 'deleted_at' => self::now() ], [ 'id' => $id ] );
		}

		protected function api_attach( int $contact, string $kind, array $ids ): void {
			global $wpdb;
			self::$calls[] = [ 'attach', func_get_args() ];
			foreach ( $ids as $id ) {
				if ( 'list' === $kind ) {
					$wpdb->query( $wpdb->prepare( "INSERT INTO %i (subscriber_id, segment_id, status, created_at) VALUES (%d, %d, 'subscribed', %s) ON DUPLICATE KEY UPDATE status = 'subscribed'", self::t( 'mailpoet_subscriber_segment' ), $contact, $id, self::now() ) );
				} else {
					$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (subscriber_id, tag_id, created_at) VALUES (%d, %d, %s)', self::t( 'mailpoet_subscriber_tag' ), $contact, $id, self::now() ) );
				}
			}
			if ( 'list' === $kind && ! self::$confirmation ) {
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'subscribed' WHERE id = %d AND status = 'unconfirmed'", self::t( 'mailpoet_subscribers' ), $contact ) );
			}
		}

		protected function api_detach( int $contact, string $kind, array $ids ): void {
			global $wpdb;
			self::$calls[] = [ 'detach', func_get_args() ];
			foreach ( $ids as $id ) {
				if ( 'list' === $kind ) {
					$wpdb->update( self::t( 'mailpoet_subscriber_segment' ), [ 'status' => 'unsubscribed' ], [ 'subscriber_id' => $contact, 'segment_id' => $id ] );
				} else {
					$wpdb->delete( self::t( 'mailpoet_subscriber_tag' ), [ 'subscriber_id' => $contact, 'tag_id' => $id ] );
				}
			}
		}

		protected function api_create_group( string $kind, string $title, string $description ): int {
			global $wpdb;
			self::$calls[] = [ 'create_group', func_get_args() ];
			$row = [ 'name' => $title, 'description' => $description, 'created_at' => self::now() ];
			$wpdb->insert( self::t( 'list' === $kind ? 'mailpoet_segments' : 'mailpoet_tags' ), 'list' === $kind ? $row + [ 'type' => 'default' ] : $row );
			return (int) $wpdb->insert_id;
		}

		protected function api_update_group( string $kind, int $id, array $changes ): void {
			global $wpdb;
			self::$calls[] = [ 'update_group', func_get_args() ];
			$row = [];
			if ( isset( $changes['title'] ) ) {
				$row['name'] = $changes['title'];
			}
			if ( isset( $changes['description'] ) ) {
				$row['description'] = $changes['description'];
			}
			$wpdb->update( self::t( 'list' === $kind ? 'mailpoet_segments' : 'mailpoet_tags' ), $row, [ 'id' => $id ] );
		}

		protected function api_delete_group( string $kind, int $id ): void {
			global $wpdb;
			self::$calls[] = [ 'delete_group', func_get_args() ];
			$wpdb->delete( self::t( 'list' === $kind ? 'mailpoet_segments' : 'mailpoet_tags' ), [ 'id' => $id ] );
			$wpdb->delete( self::t( 'list' === $kind ? 'mailpoet_subscriber_segment' : 'mailpoet_subscriber_tag' ), [ 'list' === $kind ? 'segment_id' : 'tag_id' => $id ] );
		}
	}
}
