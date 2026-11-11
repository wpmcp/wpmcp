<?php
/**
 * Fixtures for the knowledge base and support desk ops (issue #708), for
 * tests that run without BetterDocs, Fluent Support or Awesome Support in
 * the shared test core.
 *
 *  - The Fluent Support tables the ticket ops read and write, copied from
 *    the CREATE TABLE statements of Fluent Support 2.5.0's migrators
 *    (database/Migrations), plain indexes left out. DDL commits implicitly,
 *    so callers create them in wpSetUpBeforeClass() and drop them in
 *    wpTearDownAfterClass(); rows written inside a test roll back with it.
 *  - BetterDocs 4.9.6's docs post type (capability type doc/docs, mapped
 *    meta caps) and its doc_category and doc_tag taxonomies.
 *  - Awesome Support 6.4.2's ticket post type with its capability map, its
 *    ticket_reply and ticket_history types and its post statuses.
 *  - Adapter doubles that replace only the calls into each plugin's API,
 *    writing rows and posts the way the plugin does
 *    (ResponseService::createResponse() and applyToTicket(),
 *    TicketService::close(); wpas_add_reply() and wpas_close_ticket()).
 */

namespace {

	if ( ! function_exists( 'wpmcp_test_create_fluent_support_tables' ) ) {
		function wpmcp_test_create_fluent_support_tables(): void {
			global $wpdb;
			$p = $wpdb->prefix . 'fs_';
			$c = $wpdb->get_charset_collate();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tickets (
				`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`customer_id` BIGINT(20) UNSIGNED NULL, `agent_id` BIGINT(20) UNSIGNED NULL,
				`mailbox_id` BIGINT(20) UNSIGNED NULL, `product_id` BIGINT(20) UNSIGNED NULL,
				`product_source` VARCHAR(192) NULL, `privacy` VARCHAR(100) DEFAULT 'private',
				`priority` VARCHAR(100) DEFAULT 'normal', `client_priority` VARCHAR(100) DEFAULT 'normal',
				`status` VARCHAR(100) DEFAULT 'new', `title` VARCHAR(192) NULL, `slug` VARCHAR(192) NULL,
				`hash` VARCHAR(192) NULL, `content_hash` VARCHAR(192) NULL, `message_id` VARCHAR(192) NULL,
				`source` VARCHAR(192) NULL, `content` LONGTEXT NULL, `secret_content` LONGTEXT NULL,
				`last_agent_response` TIMESTAMP NULL, `last_customer_response` TIMESTAMP NULL,
				`waiting_since` TIMESTAMP NULL, `response_count` INT(11) DEFAULT 0,
				`first_response_time` INT(11) NULL, `total_close_time` INT(11) NULL,
				`resolved_at` TIMESTAMP NULL, `closed_by` BIGINT(20) UNSIGNED NULL,
				`created_by` BIGINT(20) UNSIGNED NULL, `serial_number` BIGINT UNSIGNED NULL,
				`ticket_number` VARCHAR(192) NULL DEFAULT NULL,
				`created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
				UNIQUE KEY `uniq_serial_number` (`serial_number`)
			) $c" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}conversations (
				`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`serial` INT(11) UNSIGNED DEFAULT 1, `ticket_id` BIGINT(20) UNSIGNED NOT NULL,
				`person_id` BIGINT(20) UNSIGNED NOT NULL, `conversation_type` VARCHAR(100) DEFAULT 'response',
				`content` LONGTEXT NULL, `source` VARCHAR(100) DEFAULT 'web', `content_hash` VARCHAR(192) NULL,
				`message_id` VARCHAR(192) NULL, `is_important` ENUM('yes', 'no') DEFAULT 'no',
				`created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL
			) $c" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}persons (
				`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`first_name` VARCHAR(192) NULL, `last_name` VARCHAR(192) NULL, `email` VARCHAR(192) NULL,
				`title` VARCHAR(192) NULL, `avatar` VARCHAR(192) NULL, `person_type` VARCHAR(192) DEFAULT 'customer',
				`status` VARCHAR(192) DEFAULT 'active', `ip_address` VARCHAR(20) NULL, `last_ip_address` VARCHAR(20) NULL,
				`address_line_1` VARCHAR(192) NULL, `address_line_2` VARCHAR(192) NULL, `city` VARCHAR(192) NULL,
				`zip` VARCHAR(192) NULL, `state` VARCHAR(192) NULL, `country` VARCHAR(192) NULL, `note` LONGTEXT NULL,
				`hash` VARCHAR(192) NULL, `user_id` BIGINT(20) UNSIGNED NULL, `description` MEDIUMTEXT NULL,
				`remote_uid` BIGINT(20) UNSIGNED NULL, `last_response_at` TIMESTAMP NULL,
				`created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL
			) $c" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}meta (
				`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`object_type` VARCHAR(100) NOT NULL, `object_id` BIGINT(20) UNSIGNED NULL,
				`key` VARCHAR(192) NOT NULL, `value` LONGTEXT NULL,
				`created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL
			) $c" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}attachments (
				`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`ticket_id` BIGINT(20) UNSIGNED NULL, `person_id` BIGINT(20) UNSIGNED NULL,
				`conversation_id` BIGINT(20) UNSIGNED NULL, `file_type` VARCHAR(100) NULL,
				`file_path` TEXT NULL, `full_url` TEXT NULL, `settings` TEXT NULL, `title` VARCHAR(192) NULL,
				`file_hash` VARCHAR(192) NULL, `driver` VARCHAR(100) DEFAULT 'local',
				`status` VARCHAR(100) DEFAULT 'active', `file_size` VARCHAR(100) NULL,
				`created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL
			) $c" );
			// phpcs:enable
		}

		function wpmcp_test_drop_fluent_support_tables(): void {
			global $wpdb;
			foreach ( [ 'tickets', 'conversations', 'persons', 'meta', 'attachments' ] as $t ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fs_{$t}" ); // phpcs:ignore WordPress.DB
			}
		}

		/** BetterDocs's docs post type and its two taxonomies, as BetterDocs registers them. */
		function wpmcp_test_register_betterdocs_types(): void {
			register_post_type( 'docs', [
				'public'          => true,
				'capability_type' => [ 'doc', 'docs' ],
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields' ],
			] );
			register_taxonomy( 'doc_category', [ 'docs' ], [ 'hierarchical' => true, 'public' => true ] );
			register_taxonomy( 'doc_tag', [ 'docs' ], [ 'hierarchical' => false, 'public' => true ] );
		}

		function wpmcp_test_unregister_betterdocs_types(): void {
			unregister_taxonomy( 'doc_category' );
			unregister_taxonomy( 'doc_tag' );
			unregister_post_type( 'docs' );
		}

		/** The doc capabilities BetterDocs grants an administrator on activation. */
		function wpmcp_test_grant_doc_caps( \WP_User $user ): void {
			foreach ( [ 'edit_docs', 'edit_others_docs', 'publish_docs', 'read_private_docs', 'delete_docs', 'edit_published_docs', 'edit_private_docs', 'delete_published_docs', 'delete_others_docs', 'delete_private_docs' ] as $cap ) {
				$user->add_cap( $cap );
			}
		}

		/** Awesome Support's ticket types, statuses and capability map (includes/functions-post-type.php). */
		function wpmcp_test_register_awesome_support_types(): void {
			register_post_type( 'ticket', [
				'public'       => false,
				'show_ui'      => true,
				'capabilities' => [
					'read'                   => 'view_ticket',
					'read_post'              => 'view_ticket',
					'read_private_posts'     => 'view_private_ticket',
					'edit_post'              => 'edit_ticket',
					'edit_posts'             => 'edit_ticket',
					'edit_others_posts'      => 'edit_other_ticket',
					'edit_private_posts'     => 'edit_private_ticket',
					'edit_published_posts'   => 'edit_ticket',
					'publish_posts'          => 'create_ticket',
					'delete_post'            => 'delete_ticket',
					'delete_posts'           => 'delete_ticket',
					'delete_private_posts'   => 'delete_private_ticket',
					'delete_published_posts' => 'delete_ticket',
					'delete_others_posts'    => 'delete_other_ticket',
				],
			] );
			register_post_type( 'ticket_reply', [ 'public' => false ] );
			register_post_type( 'ticket_history', [ 'public' => false ] );
			foreach ( [ 'queued', 'processing', 'hold', 'read', 'unread' ] as $status ) {
				register_post_status( $status, [ 'public' => true ] );
			}
		}

		function wpmcp_test_unregister_awesome_support_types(): void {
			unregister_post_type( 'ticket' );
			unregister_post_type( 'ticket_reply' );
			unregister_post_type( 'ticket_history' );
		}

		/** Awesome Support's agent capabilities (includes/install.php). */
		function wpmcp_test_grant_ticket_caps( \WP_User $user ): void {
			foreach ( [ 'view_ticket', 'view_private_ticket', 'edit_ticket', 'edit_other_ticket', 'edit_private_ticket', 'close_ticket', 'reply_ticket', 'view_all_tickets' ] as $cap ) {
				$user->add_cap( $cap );
			}
		}
	}
}

namespace WPMCP\Tests\Support {

	use WPMCP\Integrations\Helpdesk_Awesome_Support;
	use WPMCP\Integrations\Helpdesk_Fluent_Support;

	/**
	 * Fluent Support with its ResponseService and TicketService replaced by
	 * what they write: a reply is a conversation row plus the ticket columns
	 * applyToTicket() moves, and a close the columns applyClose() sets plus
	 * the internal_info line logClose() adds.
	 */
	class Fake_Fluent_Support extends Helpdesk_Fluent_Support {

		/** @var array<int, array{ticket: int, type: string}> replies the plugin would have mailed */
		public array $mailed = [];

		protected function scope(): ?array {
			return current_user_can( 'manage_options' ) ? [ 'all' => true ] : null;
		}

		protected function may_manage(): bool {
			return current_user_can( 'manage_options' );
		}

		private function insert( int $ticket, string $type, string $content ): int {
			global $wpdb;
			$now = current_time( 'mysql' );
			$wpdb->insert( $wpdb->prefix . 'fs_conversations', [
				'ticket_id'         => $ticket,
				'person_id'         => $this->agent_id(),
				'conversation_type' => $type,
				'content'           => wp_kses_post( $content ),
				'content_hash'      => md5( $content ),
				'created_at'        => $now,
				'updated_at'        => $now,
			] );
			return (int) $wpdb->insert_id;
		}

		protected function send_reply( int $id, string $content ): int {
			global $wpdb;
			$reply  = $this->insert( $id, 'response', $content );
			$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fs_tickets WHERE id = %d", $id ), ARRAY_A );
			$set    = [
				'last_agent_response' => current_time( 'mysql' ),
				'waiting_since'       => current_time( 'mysql' ),
				'response_count'      => (int) $ticket['response_count'] + 1,
				'updated_at'          => current_time( 'mysql' ),
			];
			if ( 'new' === $ticket['status'] ) {
				$set['status']              = 'active';
				$set['first_response_time'] = 60;
			}
			if ( empty( $ticket['agent_id'] ) ) {
				$set['agent_id'] = $this->agent_id();
			}
			$wpdb->update( $wpdb->prefix . 'fs_tickets', $set, [ 'id' => $id ] );
			$this->mailed[] = [ 'ticket' => $id, 'type' => 'response' ];
			return $reply;
		}

		protected function send_note( int $id, string $content ): int {
			return $this->insert( $id, 'note', $content );
		}

		protected function apply_status( int $id, string $status ): void {
			global $wpdb;
			if ( 'closed' === $status ) {
				$wpdb->update( $wpdb->prefix . 'fs_tickets', [ 'status' => 'closed', 'resolved_at' => current_time( 'mysql' ), 'closed_by' => $this->agent_id(), 'total_close_time' => 3600 ], [ 'id' => $id ] );
				$this->insert( $id, 'internal_info', 'closed this ticket' );
				return;
			}
			$set = [ 'status' => $status ];
			if ( 'closed' === $this->current_status( $id ) ) {
				$set += [ 'waiting_since' => current_time( 'mysql' ), 'resolved_at' => null ];
				$this->insert( $id, 'internal_info', 'reopened this ticket' );
			}
			$wpdb->update( $wpdb->prefix . 'fs_tickets', $set, [ 'id' => $id ] );
		}
	}

	/**
	 * Awesome Support with wpas_add_reply(), wpas_close_ticket() and
	 * wpas_update_ticket_status() replaced by what they write: a ticket_reply
	 * post, the reply counters and last reply meta, other replies marked
	 * read, ticket_history lines, and the assignee's open ticket counter.
	 */
	class Fake_Awesome_Support extends Helpdesk_Awesome_Support {

		/** @var int[] tickets the plugin would have mailed a reply for */
		public array $mailed = [];

		protected function states(): array {
			return [ 'queued', 'processing', 'hold' ];
		}

		protected function sees_all(): bool {
			return current_user_can( 'view_all_tickets' ) || current_user_can( 'manage_options' );
		}

		private static function history( int $id, string $line ): void {
			wp_insert_post( [ 'post_type' => 'ticket_history', 'post_parent' => $id, 'post_status' => 'publish', 'post_title' => $line, 'post_author' => get_current_user_id() ] );
		}

		protected function send_reply( int $id, string $content ): int {
			$reply = (int) wp_insert_post( [ 'post_type' => 'ticket_reply', 'post_parent' => $id, 'post_status' => 'unread', 'post_content' => $content, 'post_title' => 'Reply to ticket #' . $id, 'post_author' => get_current_user_id() ] );
			foreach ( get_posts( [ 'post_type' => 'ticket_reply', 'post_parent' => $id, 'post_status' => 'unread', 'fields' => 'ids', 'numberposts' => -1 ] ) as $other ) {
				wp_update_post( [ 'ID' => $other, 'post_status' => 'read' ] );
			}
			update_post_meta( $id, '_wpas_ttl_replies', (int) get_post_meta( $id, '_wpas_ttl_replies', true ) + 1 );
			update_post_meta( $id, '_wpas_ttl_replies_by_agent', (int) get_post_meta( $id, '_wpas_ttl_replies_by_agent', true ) + 1 );
			update_post_meta( $id, '_wpas_last_reply_date', current_time( 'mysql' ) );
			update_post_meta( $id, '_wpas_is_waiting_client_reply', false );
			$this->mailed[] = $id;
			return $reply;
		}

		protected function apply_status( int $id, string $status ): void {
			if ( 'closed' === $status ) {
				update_post_meta( $id, '_wpas_status', 'closed' );
				update_post_meta( $id, '_ticket_closed_on', current_time( 'mysql' ) );
				$agent = (int) get_post_meta( $id, '_wpas_assignee', true );
				if ( $agent > 0 ) {
					update_user_option( $agent, 'wpas_open_tickets', max( 0, (int) get_user_option( 'wpas_open_tickets', $agent ) - 1 ) );
				}
				self::history( $id, 'The ticket was closed.' );
				return;
			}
			if ( 'closed' === get_post_meta( $id, '_wpas_status', true ) ) {
				update_post_meta( $id, '_wpas_status', 'open' );
				self::history( $id, 'The ticket was re-opened.' );
			}
			if ( get_post_status( $id ) !== $status ) {
				wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
				self::history( $id, 'Ticket state changed to ' . $status );
			}
		}
	}
}
