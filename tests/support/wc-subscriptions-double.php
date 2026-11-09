<?php
/**
 * A stand-in for WooCommerce Subscriptions (issue #658) for the stub-backed
 * suite, where the commercial plugin is not installed.
 * bin/test-local.sh --live-wc-subscriptions runs the same ops through the
 * real plugin (WPMCP_WC_SUBSCRIPTIONS_SRC).
 *
 * A stand-in subscription is a real WooCommerce order whose class is
 * WPMCP_Test_Subscription (through WooCommerce's own woocommerce_order_class
 * filter), so the order CRUD, both order stores, the item rows and the order
 * snapshot all run for real. On top it implements the slice of
 * WC_Subscription the ops use, following WooCommerce Subscriptions 9.4.1:
 * its status list (wcs_get_subscription_statuses()), its transition rules
 * (can_be_updated_to()), what update_status() does to the schedule on
 * pending-cancel, cancelled and on-hold, the date ordering update_dates()
 * enforces, and the payment method support checks. Dates are kept in the
 * order meta keys Subscriptions uses (_schedule_next_payment and so on);
 * related orders in a test-only meta key. It is not Subscriptions.
 *
 * Call wpmcp_test_wc_subscriptions_double() from setUp() and
 * wpmcp_test_wc_subscriptions_reset() from tearDown().
 */

use WPMCP\Safety\Wc_Subscription_Snapshot;

if ( ! class_exists( 'WC_Order' ) ) {
	return;
}

if ( ! class_exists( 'WPMCP_Test_Subscription' ) ) {
	class WPMCP_Test_Subscription extends WC_Order {
		/** wcs_get_subscription_statuses() keys in Subscriptions 9.4.1. */
		public const STATUSES = [ 'wc-pending', 'wc-active', 'wc-on-hold', 'wc-cancelled', 'wc-switched', 'wc-expired', 'wc-pending-cancel' ];

		/** wcs_get_subscription_ended_statuses(). */
		public const ENDED = [ 'cancelled', 'trash', 'expired', 'switched', 'pending-cancel' ];

		/** Payment gateway features the stand-in's payment method supports, by feature. */
		public static array $supports = [];

		/** Ids of stand-in subscriptions, for the order class filter. */
		public static array $ids = [];

		public function get_valid_statuses() {
			return array_values( array_unique( array_merge( array_keys( wc_get_order_statuses() ), self::STATUSES ) ) );
		}

		public function payment_method_supports( $feature ) {
			return self::$supports[ $feature ] ?? true;
		}

		public function get_billing_period( $context = 'view' ) {
			return (string) $this->get_meta( '_billing_period' );
		}

		public function get_billing_interval( $context = 'view' ) {
			return (int) $this->get_meta( '_billing_interval' );
		}

		public function get_trial_period( $context = 'view' ) {
			return (string) $this->get_meta( '_trial_period' );
		}

		public function get_requires_manual_renewal( $context = 'view' ) {
			return 'true' === $this->get_meta( '_requires_manual_renewal' );
		}

		public function get_suspension_count( $context = 'view' ) {
			return (int) $this->get_meta( '_suspension_count' );
		}

		public function set_suspension_count( $count ) {
			$this->update_meta_data( '_suspension_count', (int) $count );
		}

		public function get_date( $type, $timezone = 'gmt' ) {
			$value = $this->get_meta( '_schedule_' . $type );
			return is_string( $value ) && '' !== $value ? $value : 0;
		}

		public function get_time( $type ) {
			$date = $this->get_date( $type );
			return 0 === $date ? 0 : (int) strtotime( $date . ' UTC' );
		}

		public function validate_date_updates( array $dates ): array {
			$times = [];
			foreach ( [ 'start', 'trial_end', 'next_payment', 'cancelled', 'end' ] as $type ) {
				$value          = array_key_exists( $type, $dates ) ? $dates[ $type ] : $this->get_date( $type );
				$times[ $type ] = empty( $value ) ? 0 : (int) strtotime( $value . ' UTC' );
			}
			$messages = [];
			foreach ( [ 'trial_end', 'next_payment' ] as $type ) {
				if ( $times[ $type ] && $times[ $type ] <= $times['start'] ) {
					$messages[] = "The {$type} date must occur after the start date.";
				}
			}
			if ( $times['next_payment'] && $times['trial_end'] && $times['next_payment'] < $times['trial_end'] ) {
				$messages[] = 'The next_payment date must occur after the trial end date.';
			}
			foreach ( [ 'cancelled', 'end' ] as $type ) {
				if ( $times[ $type ] && $times['next_payment'] && $times[ $type ] <= $times['next_payment'] ) {
					$messages[] = "The {$type} date must occur after the next payment date.";
				}
			}
			if ( $times['end'] && $times['cancelled'] && $times['end'] < $times['cancelled'] ) {
				$messages[] = 'The end date must occur after the cancellation date.';
			}
			if ( $messages ) {
				throw new Exception( sprintf( 'Subscription #%d: ', $this->get_id() ) . implode( ' ', $messages ) );
			}
			return $dates;
		}

		public function update_dates( $dates, $timezone = 'gmt' ): bool {
			$this->validate_date_updates( $dates );
			foreach ( $dates as $type => $value ) {
				if ( empty( $value ) ) {
					$this->delete_meta_data( '_schedule_' . $type );
				} else {
					$this->update_meta_data( '_schedule_' . $type, $value );
				}
			}
			$this->save();
			return true;
		}

		public function delete_date( $type ) {
			$this->delete_meta_data( '_schedule_' . $type );
			$this->save();
		}

		public function can_date_be_updated( $type ) {
			if ( in_array( $type, [ 'next_payment', 'end' ], true ) ) {
				return ! $this->has_status( self::ENDED ) && ( $this->has_status( 'pending' ) || $this->payment_method_supports( 'subscription_date_changes' ) );
			}
			return false;
		}

		public function can_be_updated_to( $new_status ) {
			switch ( $new_status ) {
				case 'active':
					if ( $this->payment_method_supports( 'subscription_reactivation' ) && $this->has_status( 'on-hold' ) ) {
						return true;
					}
					return $this->has_status( [ 'pending', 'pending-cancel' ] );
				case 'on-hold':
					return $this->payment_method_supports( 'subscription_suspension' ) && $this->has_status( [ 'active', 'pending' ] );
				case 'cancelled':
					return $this->payment_method_supports( 'subscription_cancellation' ) && ( $this->has_status( 'pending-cancel' ) || ! $this->has_status( self::ENDED ) );
				case 'pending-cancel':
					return $this->payment_method_supports( 'subscription_cancellation' ) && $this->has_status( 'active' );
			}
			return false;
		}

		public function update_status( $new_status, $note = '', $manual = false ) {
			$old = $this->get_status();
			if ( $new_status === $old ) {
				return true;
			}
			if ( ! $this->can_be_updated_to( $new_status ) ) {
				throw new Exception( sprintf( 'Unable to change subscription status to "%s".', $new_status ) );
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			switch ( $new_status ) {
				case 'pending-cancel':
					$this->update_meta_data( 'end_date_pre_cancellation', $this->get_date( 'end' ) );
					$this->update_meta_data( 'trial_end_pre_cancellation', $this->get_date( 'trial_end' ) );
					$end = $this->get_date( 'next_payment' ) ?: $now;
					$this->delete_meta_data( '_schedule_trial_end' );
					$this->delete_meta_data( '_schedule_next_payment' );
					$this->update_meta_data( '_schedule_cancelled', $now );
					$this->update_meta_data( '_schedule_end', $end );
					break;
				case 'cancelled':
					$this->delete_meta_data( '_schedule_trial_end' );
					$this->delete_meta_data( '_schedule_next_payment' );
					$this->update_meta_data( '_schedule_end', $now );
					if ( 0 === $this->get_date( 'cancelled' ) ) {
						$this->update_meta_data( '_schedule_cancelled', $now );
					}
					break;
				case 'on-hold':
					$this->set_suspension_count( $this->get_suspension_count() + 1 );
					break;
			}
			do_action( 'wpmcp_test_subscription_status', $this->get_id(), $old, $new_status );
			return parent::update_status( $new_status, $note, $manual );
		}

		public function get_related_orders( $return_fields = 'ids', $order_types = [ 'parent', 'renewal', 'switch' ] ) {
			$ids = [];
			foreach ( (array) $order_types as $type ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) ( $this->get_meta( '_test_related_' . $type ) ?: [] ) ) );
			}
			return $ids;
		}
	}
}

if ( ! class_exists( 'WPMCP_Test_Subscriptions_Double' ) ) {
	/** The Subscriptions API the ops reach through Wc_Subscription_Snapshot. */
	class WPMCP_Test_Subscriptions_Double {
		public function get( int $id ) {
			if ( ! in_array( $id, WPMCP_Test_Subscription::$ids, true ) ) {
				return null;
			}
			$found = wc_get_order( $id );
			return $found instanceof WPMCP_Test_Subscription ? $found : null;
		}

		public function query( array $args ): array {
			$out = [];
			foreach ( array_reverse( WPMCP_Test_Subscription::$ids ) as $id ) {
				$sub = $this->get( $id );
				if ( ! $sub ) {
					continue;
				}
				$statuses = (array) ( $args['subscription_status'] ?? [ 'any' ] );
				if ( ! in_array( 'any', $statuses, true ) && ! in_array( $sub->get_status(), $statuses, true ) ) {
					continue;
				}
				if ( ! empty( $args['customer_id'] ) && (int) $args['customer_id'] !== $sub->get_customer_id() ) {
					continue;
				}
				if ( ! empty( $args['product_id'] ) ) {
					$has = false;
					foreach ( $sub->get_items() as $item ) {
						$has = $has || in_array( (int) $args['product_id'], [ $item->get_product_id(), $item->get_variation_id() ], true );
					}
					if ( ! $has ) {
						continue;
					}
				}
				$out[] = $sub;
			}
			return array_slice( $out, (int) ( $args['offset'] ?? 0 ), (int) ( $args['subscriptions_per_page'] ?? 10 ) );
		}

		public function statuses(): array {
			return WPMCP_Test_Subscription::STATUSES;
		}
	}

	function wpmcp_test_wc_subscriptions_class( $classname, $order_type, $order_id ) {
		return in_array( (int) $order_id, WPMCP_Test_Subscription::$ids, true ) ? 'WPMCP_Test_Subscription' : $classname;
	}

	/** Stand in for WooCommerce Subscriptions. */
	function wpmcp_test_wc_subscriptions_double(): void {
		WPMCP_Test_Subscription::$ids      = [];
		WPMCP_Test_Subscription::$supports = [];
		add_filter( 'woocommerce_order_class', 'wpmcp_test_wc_subscriptions_class', 10, 3 );
		Wc_Subscription_Snapshot::set_double_for_tests( new WPMCP_Test_Subscriptions_Double() );
	}

	/** Stop standing in. */
	function wpmcp_test_wc_subscriptions_reset(): void {
		Wc_Subscription_Snapshot::set_double_for_tests( null );
		remove_filter( 'woocommerce_order_class', 'wpmcp_test_wc_subscriptions_class', 10 );
		WPMCP_Test_Subscription::$ids      = [];
		WPMCP_Test_Subscription::$supports = [];
	}

	/**
	 * A stand-in subscription: one product line, monthly, active, started a
	 * month ago, next payment in a week.
	 *
	 * @param array<string, mixed> $args product_id, quantity, customer_id, status, dates.
	 */
	function wpmcp_test_wc_subscription( array $args ): WPMCP_Test_Subscription {
		$sub = new WPMCP_Test_Subscription();
		$sub->set_customer_id( (int) ( $args['customer_id'] ?? 0 ) );
		$sub->set_currency( 'USD' );
		$sub->set_payment_method( 'manual' );
		$sub->add_product( wc_get_product( (int) $args['product_id'] ), (int) ( $args['quantity'] ?? 1 ) );
		$sub->set_address( [ 'first_name' => 'Ada', 'city' => 'London', 'email' => 'ada@example.com' ], 'billing' );
		$sub->update_meta_data( '_billing_period', 'month' );
		$sub->update_meta_data( '_billing_interval', 1 );
		$dates = ( $args['dates'] ?? [] ) + [
			'start'        => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
		];
		foreach ( $dates as $type => $value ) {
			$sub->update_meta_data( '_schedule_' . $type, $value );
		}
		$sub->calculate_totals();
		$sub->set_status( (string) ( $args['status'] ?? 'active' ) );
		$sub->save();
		WPMCP_Test_Subscription::$ids[] = $sub->get_id();

		return wc_get_order( $sub->get_id() );
	}
}
