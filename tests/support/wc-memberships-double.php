<?php
/**
 * A stand-in for WooCommerce Memberships (PR #573) for the stub-backed
 * suite. The plugin is commercial and not available to the test gate, so
 * there is no live leg: this double is modelled on the plugin's documented
 * storage and public API and has NOT been checked against the real plugin.
 *
 * Storage as Memberships keeps it: plans are published 'wc_membership_plan'
 * posts with _access_method, _access_length, _access_start_date,
 * _access_end_date and _product_ids meta; access rules are rows of the
 * 'wc_memberships_rules' option keyed by membership_plan_id; a user
 * membership is a 'wc_user_membership' post whose status is 'wcm-<status>',
 * whose parent is the plan and whose author is the member, with UTC MySQL
 * _start_date, _end_date, _paused_date and _cancelled_date meta, a
 * _paused_intervals map and _order_id / _product_id links. On top it
 * implements the slice of the plugin the ops call through
 * Wc_User_Membership_Snapshot:
 *
 *  - create(): wc_memberships_create_user_membership(): an active
 *    membership starting now, ending after the plan's access length (none
 *    when the plan has no length), with a note; fires
 *    wc_memberships_user_membership_created;
 *  - transition(): pause_membership() stamps _paused_date; activate_membership()
 *    records the paused interval and clears _paused_date; cancel_membership()
 *    stamps _cancelled_date. Each adds a membership note and fires
 *    wc_memberships_user_membership_status_changed, where the plugin's
 *    emails hang;
 *  - set_end_date(), remove(), refresh().
 *
 * It is not Memberships. Call wpmcp_test_wc_memberships_double() from
 * setUp() and wpmcp_test_wc_memberships_reset() from tearDown().
 */

use WPMCP\Safety\Wc_User_Membership_Snapshot;

if ( ! class_exists( 'WPMCP_Test_Memberships_Double' ) ) {
	class WPMCP_Test_Memberships_Double {
		/** Ids refresh() was called with. */
		public array $refreshed = [];

		/** Membership id => the end date its expiry was last scheduled for ('' when cleared). */
		public array $scheduled = [];

		public function create( int $user_id, int $plan_id ) {
			$now    = time();
			$length = (string) get_post_meta( $plan_id, '_access_length', true );
			$id     = wp_insert_post( [
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_parent' => $plan_id,
				'post_author' => $user_id,
				'post_title'  => 'Membership',
			], true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_post_meta( $id, '_start_date', gmdate( 'Y-m-d H:i:s', $now ) );
			if ( '' !== $length ) {
				$this->set_end_date( (int) $id, gmdate( 'Y-m-d H:i:s', (int) strtotime( '+' . $length, $now ) ) );
			}
			$this->note( (int) $id, 'Membership access granted from the admin.' );
			do_action( 'wc_memberships_user_membership_created', $plan_id, [ 'user_id' => $user_id, 'user_membership_id' => $id ] );
			return (int) $id;
		}

		public function transition( int $id, string $action ): bool {
			$post = get_post( $id );
			if ( ! $post ) {
				return false;
			}
			$from = substr( $post->post_status, 4 );
			$now  = gmdate( 'Y-m-d H:i:s' );
			switch ( $action ) {
				case 'pause':
					$to = 'paused';
					update_post_meta( $id, '_paused_date', $now );
					break;
				case 'resume':
					$to        = 'active';
					$intervals = (array) ( get_post_meta( $id, '_paused_intervals', true ) ?: [] );
					$paused    = (string) get_post_meta( $id, '_paused_date', true );
					if ( '' !== $paused ) {
						$intervals[ strtotime( $paused . ' UTC' ) ] = time();
						update_post_meta( $id, '_paused_intervals', $intervals );
					}
					delete_post_meta( $id, '_paused_date' );
					break;
				case 'cancel':
					$to = 'cancelled';
					update_post_meta( $id, '_cancelled_date', $now );
					break;
				default:
					return false;
			}
			wp_update_post( [ 'ID' => $id, 'post_status' => 'wcm-' . $to ] );
			$this->note( $id, "Membership status changed from {$from} to {$to}." );
			do_action( 'wc_memberships_user_membership_status_changed', $id, $from, $to );
			return true;
		}

		public function set_end_date( int $id, string $utc ): bool {
			if ( '' === $utc ) {
				delete_post_meta( $id, '_end_date' );
			} else {
				update_post_meta( $id, '_end_date', $utc );
			}
			$this->scheduled[ $id ] = $utc;
			return true;
		}

		public function remove( int $id ): void {
			wp_delete_post( $id, true );
		}

		public function refresh( int $id ): void {
			$this->refreshed[]      = $id;
			$this->scheduled[ $id ] = (string) get_post_meta( $id, '_end_date', true );
		}

		private function note( int $id, string $text ): void {
			wp_insert_comment( [
				'comment_post_ID'  => $id,
				'comment_content'  => $text,
				'comment_type'     => 'user_membership_note',
				'comment_approved' => 1,
			] );
		}
	}

	/** Stand in for WooCommerce Memberships. Returns the stand-in. */
	function wpmcp_test_wc_memberships_double(): WPMCP_Test_Memberships_Double {
		foreach ( [ 'wc_membership_plan', 'wc_user_membership' ] as $type ) {
			if ( ! post_type_exists( $type ) ) {
				register_post_type( $type, [ 'public' => false ] );
			}
		}
		foreach ( [ 'active', 'free_trial', 'delayed', 'complimentary', 'pending', 'paused', 'expired', 'cancelled' ] as $status ) {
			register_post_status( 'wcm-' . $status, [ 'internal' => true ] );
		}
		$double = new WPMCP_Test_Memberships_Double();
		Wc_User_Membership_Snapshot::set_double_for_tests( $double );
		return $double;
	}

	/** Stop standing in. */
	function wpmcp_test_wc_memberships_reset(): void {
		Wc_User_Membership_Snapshot::set_double_for_tests( null );
	}

	/**
	 * A membership plan as Memberships stores one, with its rules appended to
	 * the rules option.
	 *
	 * @param array<string, mixed>             $meta  _access_* / _product_ids overrides, by key without the underscore.
	 * @param array<int, array<string, mixed>> $rules Rule rows (membership_plan_id is filled in).
	 */
	function wpmcp_test_membership_plan( string $name = 'Gold', array $meta = [], array $rules = [] ): int {
		$id   = (int) wp_insert_post( [ 'post_type' => 'wc_membership_plan', 'post_status' => 'publish', 'post_title' => $name ] );
		$meta += [ 'access_method' => 'manual-only', 'access_length' => '', 'product_ids' => [] ];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, '_' . $key, $value );
		}
		if ( [] !== $rules ) {
			$stored = (array) get_option( 'wc_memberships_rules', [] );
			foreach ( $rules as $rule ) {
				$stored[] = [ 'id' => 'rule_' . wp_generate_password( 8, false ), 'membership_plan_id' => $id ] + $rule;
			}
			update_option( 'wc_memberships_rules', $stored );
		}
		return $id;
	}

	/**
	 * A stored user membership, as the plugin writes one.
	 *
	 * @param array<string, mixed> $args plan_id, user_id, status (no prefix), start, end (UTC MySQL), order_id, product_id.
	 */
	function wpmcp_test_user_membership( array $args ): int {
		$id = (int) wp_insert_post( [
			'post_type'   => 'wc_user_membership',
			'post_status' => 'wcm-' . ( $args['status'] ?? 'active' ),
			'post_parent' => (int) $args['plan_id'],
			'post_author' => (int) $args['user_id'],
			'post_title'  => 'Membership',
		] );
		update_post_meta( $id, '_start_date', (string) ( $args['start'] ?? gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
		if ( array_key_exists( 'end', $args ) ? null !== $args['end'] : true ) {
			update_post_meta( $id, '_end_date', (string) ( $args['end'] ?? gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
		}
		update_post_meta( $id, '_order_id', (string) ( $args['order_id'] ?? 0 ) );
		update_post_meta( $id, '_product_id', (string) ( $args['product_id'] ?? 0 ) );
		if ( 'paused' === ( $args['status'] ?? '' ) ) {
			update_post_meta( $id, '_paused_date', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		}
		return $id;
	}
}
