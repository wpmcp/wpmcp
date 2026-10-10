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

		/** @param array<string, mixed> $fields */
		private static function campaign_columns( array $fields ): array {
			$row = [];
			foreach ( [ 'title' => 'title', 'subject' => 'email_subject', 'preheader' => 'email_pre_header', 'body' => 'email_body' ] as $key => $column ) {
				if ( array_key_exists( $key, $fields ) ) {
					$row[ $column ] = (string) $fields[ $key ];
				}
			}
			if ( array_key_exists( 'lists', $fields ) ) {
				$row['settings'] = maybe_serialize( [ 'subscribers' => array_map( static fn ( int $list ): array => [ 'list' => $list, 'tag' => 'all' ], (array) $fields['lists'] ), 'sending_filter' => 'list_tag' ] );
			}
			return $row;
		}

		protected function api_create_campaign( array $fields ): int {
			global $wpdb;
			self::$calls[] = [ 'create_campaign', func_get_args() ];
			$fields += [ 'title' => (string) ( $fields['subject'] ?? '' ), 'lists' => [] ];
			$wpdb->insert( self::t( 'fc_campaigns' ), self::campaign_columns( $fields ) + [ 'type' => 'campaign', 'status' => 'draft', 'slug' => sanitize_title( (string) $fields['title'] ), 'email_body' => '', 'created_at' => self::now() ] );
			$id = (int) $wpdb->insert_id;
			$wpdb->insert( self::t( 'fc_meta' ), [ 'object_type' => 'FluentCrm\\App\\Models\\Campaign', 'object_id' => $id, 'key' => '_next_config_step', 'value' => '1' ] );
			return $id;
		}

		protected function api_update_campaign( int $id, array $fields ): void {
			global $wpdb;
			self::$calls[] = [ 'update_campaign', func_get_args() ];
			$wpdb->update( self::t( 'fc_campaigns' ), self::campaign_columns( $fields ) + [ 'updated_at' => self::now() ], [ 'id' => $id ] );
		}

		protected function api_duplicate_campaign( int $id ): int {
			global $wpdb;
			self::$calls[] = [ 'duplicate_campaign', func_get_args() ];
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t( 'fc_campaigns' ), $id ), ARRAY_A );
			unset( $row['id'] );
			$row['title']  = '[Duplicate] ' . $row['title'];
			$row['slug']   = $row['slug'] . '-copy';
			$row['status'] = 'draft';
			$wpdb->insert( self::t( 'fc_campaigns' ), $row );
			return (int) $wpdb->insert_id;
		}

		protected function api_send_test( int $id, string $email ): void {
			self::$calls[] = [ 'send_test', func_get_args() ];
		}

		protected function api_send_campaign( int $id, ?string $at ): void {
			global $wpdb;
			self::$calls[] = [ 'send_campaign', func_get_args() ];
			$wpdb->update( self::t( 'fc_campaigns' ), [ 'status' => null === $at ? 'processing' : 'pending-scheduled', 'scheduled_at' => $at ?? self::now(), 'recipients_count' => 0 ], [ 'id' => $id ] );
		}

		protected function api_set_automation_status( int $id, string $status ): void {
			global $wpdb;
			self::$calls[] = [ 'automation_status', func_get_args() ];
			$wpdb->update( self::t( 'fc_funnels' ), [ 'status' => $status ], [ 'id' => $id ] );
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

		/** @param array<string, mixed> $fields */
		private static function newsletter_columns( array $fields ): array {
			$row = [];
			foreach ( [ 'subject' => 'subject', 'preheader' => 'preheader' ] as $key => $column ) {
				if ( array_key_exists( $key, $fields ) ) {
					$row[ $column ] = (string) $fields[ $key ];
				}
			}
			if ( array_key_exists( 'body', $fields ) ) {
				$row['body'] = (string) wp_json_encode( [ 'content' => [ 'type' => 'container', 'blocks' => [ [ 'type' => 'text', 'text' => (string) $fields['body'] ] ] ] ] );
			}
			return $row;
		}

		/** @param int[] $lists */
		private static function newsletter_lists( int $id, array $lists ): void {
			global $wpdb;
			$wpdb->delete( self::t( 'mailpoet_newsletter_segment' ), [ 'newsletter_id' => $id ] );
			foreach ( $lists as $list ) {
				$wpdb->insert( self::t( 'mailpoet_newsletter_segment' ), [ 'newsletter_id' => $id, 'segment_id' => $list, 'created_at' => self::now() ] );
			}
		}

		protected function api_create_campaign( array $fields ): int {
			global $wpdb;
			self::$calls[] = [ 'create_campaign', func_get_args() ];
			$wpdb->insert( self::t( 'mailpoet_newsletters' ), self::newsletter_columns( $fields ) + [ 'type' => 'standard', 'status' => 'draft', 'hash' => wp_generate_password( 12, false ), 'created_at' => self::now() ] );
			$id = (int) $wpdb->insert_id;
			self::newsletter_lists( $id, array_map( 'intval', (array) ( $fields['lists'] ?? [] ) ) );
			return $id;
		}

		protected function api_update_campaign( int $id, array $fields ): void {
			global $wpdb;
			self::$calls[] = [ 'update_campaign', func_get_args() ];
			$columns = self::newsletter_columns( $fields );
			if ( [] !== $columns ) {
				$wpdb->update( self::t( 'mailpoet_newsletters' ), $columns, [ 'id' => $id ] );
			}
			if ( array_key_exists( 'lists', $fields ) ) {
				self::newsletter_lists( $id, array_map( 'intval', (array) $fields['lists'] ) );
			}
		}

		protected function api_duplicate_campaign( int $id ): int {
			global $wpdb;
			self::$calls[] = [ 'duplicate_campaign', func_get_args() ];
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t( 'mailpoet_newsletters' ), $id ), ARRAY_A );
			unset( $row['id'], $row['unsubscribe_token'] );
			$row['subject'] = 'Copy of ' . $row['subject'];
			$row['status']  = 'draft';
			$wpdb->insert( self::t( 'mailpoet_newsletters' ), $row );
			$copy = (int) $wpdb->insert_id;
			self::newsletter_lists( $copy, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT segment_id FROM %i WHERE newsletter_id = %d', self::t( 'mailpoet_newsletter_segment' ), $id ) ) ) );
			return $copy;
		}

		protected function api_send_test( int $id, string $email ): void {
			self::$calls[] = [ 'send_test', func_get_args() ];
		}

		protected function api_send_campaign( int $id, ?string $at ): void {
			global $wpdb;
			self::$calls[] = [ 'send_campaign', func_get_args() ];
			$wpdb->insert( self::t( 'mailpoet_scheduled_tasks' ), [ 'type' => 'sending', 'status' => null === $at ? null : 'scheduled', 'scheduled_at' => $at, 'created_at' => self::now() ] );
			$wpdb->insert( self::t( 'mailpoet_sending_queues' ), [ 'task_id' => (int) $wpdb->insert_id, 'newsletter_id' => $id, 'count_total' => $this->campaign_recipients( $id ), 'created_at' => self::now() ] );
			$wpdb->update( self::t( 'mailpoet_newsletters' ), [ 'status' => null === $at ? 'sending' : 'scheduled' ], [ 'id' => $id ] );
		}

		protected function api_set_automation_status( int $id, string $status ): void {
			global $wpdb;
			self::$calls[] = [ 'automation_status', func_get_args() ];
			$wpdb->update( self::t( 'mailpoet_automations' ), [ 'status' => $status ], [ 'id' => $id ] );
			$wpdb->insert( self::t( 'mailpoet_automation_versions' ), [ 'automation_id' => $id, 'steps' => '{}', 'created_at' => self::now() ] );
		}
	}
}
