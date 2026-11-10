<?php
/**
 * A stand-in for WooCommerce Bookings (issue #659) for the stub-backed
 * suite, where the commercial plugin is not installed.
 * bin/test-local.sh --live-wc-bookings runs the same ops through the real
 * plugin (WPMCP_WC_BOOKINGS_SRC).
 *
 * Bookings are stored exactly as WooCommerce Bookings 3.14.0 stores them
 * (WC_Booking_Data_Store): 'wc_booking' posts whose status is the booking
 * status, whose parent is the order and whose author is the customer, with
 * the _booking_* meta keys, start and end as local 'YmdHis'. A bookable
 * product is a WooCommerce product whose product_type term is 'booking',
 * with Bookings' _wc_booking_* meta. On top it implements the slice of the
 * plugin the ops call through Wc_Booking_Snapshot, following 3.14.0:
 *
 *  - plan(): the booking form's start, duration and persons fields become
 *    a start and end (fixed or customer-chosen blocks of the product's
 *    duration unit, all day for day units), checked against the product's
 *    capacity (_wc_booking_qty) over the bookings already holding that
 *    block, and priced as base cost plus block cost per block;
 *  - set_status(): WC_Booking::update_status() and what the order manager
 *    does on the transition: cancelled stamps _booking_date_cancelled and
 *    marks the booking unattended, and a cancelled, paid or unpaid booking
 *    moves an order holding only it to cancelled, processing or pending;
 *    each transition fires woocommerce_booking_<to>, which is where the
 *    plugin's emails hang;
 *  - set_rules(), slots(), remove(), refresh().
 *
 * It is not Bookings. Call wpmcp_test_wc_bookings_double() from setUp() and
 * wpmcp_test_wc_bookings_reset() from tearDown().
 */

use WPMCP\Safety\Wc_Booking_Snapshot;

if ( ! class_exists( 'WC_Order' ) ) {
	return;
}

if ( ! class_exists( 'WPMCP_Test_Bookings_Double' ) ) {
	class WPMCP_Test_Bookings_Double {
		/** get_wc_booking_statuses() 'fully_booked' context: statuses that hold a block. */
		public const HOLDING = [ 'unpaid', 'pending-confirmation', 'confirmed', 'paid', 'complete', 'in-cart' ];

		/** Ids refresh() was called with. */
		public array $refreshed = [];

		public function product( int $id ) {
			$terms = get_the_terms( $id, 'product_type' );
			if ( ! is_array( $terms ) || 'booking' !== ( $terms[0]->slug ?? '' ) ) {
				return null;
			}
			return wc_get_product( $id ) ?: null;
		}

		public function resource_ids( WC_Product $product ): array {
			return array_map( 'intval', (array) ( $product->get_meta( '_test_resource_ids' ) ?: [] ) );
		}

		public function plan( WC_Product $product, array $posted ) {
			$id    = $product->get_id();
			$unit  = (string) ( get_post_meta( $id, '_wc_booking_duration_unit', true ) ?: 'hour' );
			$size  = max( 1, (int) ( get_post_meta( $id, '_wc_booking_duration', true ) ?: 1 ) );
			$type  = (string) ( get_post_meta( $id, '_wc_booking_duration_type', true ) ?: 'fixed' );
			$qty   = max( 1, (int) ( get_post_meta( $id, '_wc_booking_qty', true ) ?: 1 ) );

			$blocks = 1;
			if ( 'customer' === $type ) {
				$blocks = (int) ( $posted['wc_bookings_field_duration'] ?? 0 );
				if ( $blocks < 1 ) {
					return new WP_Error( 'Error', 'Duration is required - please enter a duration greater than zero' );
				}
			}
			$day = sprintf( '%04d-%02d-%02d', $posted['wc_bookings_field_start_date_year'], $posted['wc_bookings_field_start_date_month'], $posted['wc_bookings_field_start_date_day'] );
			if ( in_array( $unit, [ 'minute', 'hour' ], true ) && empty( $posted['wc_bookings_field_start_date_time'] ) ) {
				return new WP_Error( 'Error', 'Please choose a time' );
			}
			$start = empty( $posted['wc_bookings_field_start_date_time'] )
				? strtotime( $day . ' 00:00 UTC' )
				: strtotime( str_replace( 'T', ' ', $posted['wc_bookings_field_start_date_time'] ) . ' UTC' );
			$end   = strtotime( '+' . ( $blocks * $size ) . ' ' . $unit, $start );
			if ( 'day' === $unit ) {
				$end -= 1;
			}

			if ( count( $this->overlapping( $id, $start, $end ) ) >= $qty ) {
				return new WP_Error( 'Error', 'Sorry, the selected block is not available' );
			}

			$persons = [];
			foreach ( $posted as $key => $value ) {
				if ( 'wc_bookings_field_persons' === $key ) {
					$persons[0] = (int) $value;
				} elseif ( 0 === strpos( $key, 'wc_bookings_field_persons_' ) ) {
					$persons[ (int) substr( $key, strlen( 'wc_bookings_field_persons_' ) ) ] = (int) $value;
				}
			}

			$cost = (float) get_post_meta( $id, '_wc_booking_cost', true ) + $blocks * (float) get_post_meta( $id, '_wc_booking_block_cost', true );
			return [
				'data' => [
					'_start_date'  => $start,
					'_end_date'    => $end,
					'_all_day'     => 'day' === $unit ? 1 : 0,
					'_resource_id' => (int) ( $posted['wc_bookings_field_resource'] ?? 0 ) ?: '',
					'_persons'     => $persons,
					'_qty'         => 1,
				],
				'cost' => $cost,
			];
		}

		/** Ids of bookings that hold a block of a product overlapping [start, end]. */
		public function overlapping( int $product_id, int $start, int $end ): array {
			$out = [];
			foreach ( get_posts( [ 'post_type' => 'wc_booking', 'post_status' => self::HOLDING, 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_booking_product_id', 'meta_value' => (string) $product_id ] ) as $id ) {
				$s = strtotime( get_post_meta( $id, '_booking_start', true ) . ' UTC' );
				$e = strtotime( get_post_meta( $id, '_booking_end', true ) . ' UTC' );
				if ( $s < $end && $e > $start ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		public function make( array $props, string $status ): int {
			$id = wp_insert_post( [
				'post_type'   => 'wc_booking',
				'post_status' => $status,
				'post_title'  => 'Booking',
				'post_author' => (int) ( $props['customer_id'] ?? 0 ),
				'post_parent' => (int) ( $props['order_id'] ?? 0 ),
			] );
			if ( ! $id || is_wp_error( $id ) ) {
				return 0;
			}
			foreach ( Wc_Booking_Snapshot::META as $key => $prop ) {
				$value = $props[ $prop ] ?? '';
				if ( in_array( $prop, [ 'start', 'end' ], true ) ) {
					$value = $value ? gmdate( 'YmdHis', (int) $value ) : '';
				} elseif ( 'all_day' === $prop ) {
					$value = $value ? 1 : 0;
				}
				update_post_meta( $id, $key, $value );
			}
			if ( 'unpaid' !== $status ) {
				do_action( 'woocommerce_booking_' . $status, $id );
			}
			return (int) $id;
		}

		public function set_status( int $id, string $status ): bool {
			$from = get_post_status( $id );
			if ( false === $from ) {
				return false;
			}
			if ( $from === $status ) {
				return true;
			}
			wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
			if ( 'cancelled' === $status ) {
				update_post_meta( $id, '_booking_date_cancelled', time() );
				update_post_meta( $id, '_booking_attendance_status', 'unattended' );
			}
			$order_status = [ 'cancelled' => 'cancelled', 'paid' => 'processing', 'unpaid' => 'pending' ][ $status ] ?? null;
			$order        = wc_get_order( (int) wp_get_post_parent_id( $id ) );
			if ( null !== $order_status && $order instanceof WC_Order && 1 === count( $order->get_items() ) && ! ( 'processing' === $order_status && $order->has_status( 'completed' ) ) ) {
				$order->update_status( $order_status );
			}
			do_action( 'woocommerce_booking_' . $status, $id );
			do_action( 'woocommerce_booking_' . $from . '_to_' . $status, $id );
			return true;
		}

		public function set_rules( int $product_id, array $rules ): void {
			update_post_meta( $product_id, '_wc_booking_availability', $rules );
		}

		/** One slot per day from min_date to max_date: capacity less the bookings holding it. */
		public function slots( array $query ): array {
			$product_id = (int) $query['product_ids'];
			$qty        = max( 1, (int) ( get_post_meta( $product_id, '_wc_booking_qty', true ) ?: 1 ) );
			$records    = [];
			for ( $day = strtotime( $query['min_date'] . ' UTC' ); $day < strtotime( $query['max_date'] . ' UTC' ); $day += DAY_IN_SECONDS ) {
				$booked = count( $this->overlapping( $product_id, $day, $day + DAY_IN_SECONDS - 1 ) );
				if ( 'true' === $query['hide_unavailable'] && $booked >= $qty ) {
					continue;
				}
				$records[] = [ 'date' => gmdate( 'Y-m-d\TH:i', $day ), 'duration' => 1, 'available' => max( 0, $qty - $booked ), 'booked' => $booked ];
			}
			$page = max( 1, (int) $query['page'] );
			return [ 'records' => array_slice( $records, ( $page - 1 ) * (int) $query['limit'], (int) $query['limit'] ), 'count' => count( $records ) ];
		}

		public function remove( int $id ): void {
			wp_delete_post( $id, true );
		}

		public function refresh( int $id ): void {
			$this->refreshed[] = $id;
		}
	}

	/** Stand in for WooCommerce Bookings. Returns the stand-in. */
	function wpmcp_test_wc_bookings_double(): WPMCP_Test_Bookings_Double {
		if ( ! post_type_exists( 'wc_booking' ) ) {
			register_post_type( 'wc_booking', [ 'public' => false ] );
		}
		foreach ( [ 'unpaid', 'pending-confirmation', 'confirmed', 'paid', 'cancelled', 'complete', 'in-cart', 'was-in-cart' ] as $status ) {
			register_post_status( $status, [ 'internal' => true ] );
		}
		$double = new WPMCP_Test_Bookings_Double();
		Wc_Booking_Snapshot::set_double_for_tests( $double );
		return $double;
	}

	/** Stop standing in. */
	function wpmcp_test_wc_bookings_reset(): void {
		Wc_Booking_Snapshot::set_double_for_tests( null );
	}

	/**
	 * A bookable product as Bookings stores one: product_type booking and its
	 * _wc_booking_* meta (one-hour fixed blocks, base cost 20, block cost 5,
	 * capacity 1 unless given).
	 *
	 * @param array<string, mixed> $meta _wc_booking_* overrides, by key without the prefix.
	 */
	function wpmcp_test_bookable_product( array $meta = [] ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Kayak hire' );
		$id = (int) $product->save();
		wp_set_object_terms( $id, 'booking', 'product_type' );
		$meta += [ 'duration' => '1', 'duration_unit' => 'hour', 'duration_type' => 'fixed', 'cost' => '20', 'block_cost' => '5', 'qty' => '1', 'requires_confirmation' => 'no', 'has_persons' => 'no', 'has_resources' => 'no', 'default_date_availability' => 'available' ];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, '_wc_booking_' . $key, $value );
		}
		return $id;
	}

	/**
	 * A stored booking, as the plugin writes one.
	 *
	 * @param array<string, mixed> $args product_id, status, start, end ('YmdHis'), customer_id, order_id, order_item_id.
	 */
	function wpmcp_test_booking( array $args ): int {
		$id = wp_insert_post( [
			'post_type'   => 'wc_booking',
			'post_status' => (string) ( $args['status'] ?? 'confirmed' ),
			'post_title'  => 'Booking',
			'post_author' => (int) ( $args['customer_id'] ?? 0 ),
			'post_parent' => (int) ( $args['order_id'] ?? 0 ),
		] );
		$meta = [
			'_booking_product_id'        => (string) $args['product_id'],
			'_booking_resource_id'       => '0',
			'_booking_customer_id'       => (string) ( $args['customer_id'] ?? 0 ),
			'_booking_order_item_id'     => (string) ( $args['order_item_id'] ?? 0 ),
			'_booking_parent_id'         => '0',
			'_booking_start'             => (string) ( $args['start'] ?? gmdate( 'Ymd', time() + 3 * DAY_IN_SECONDS ) . '100000' ),
			'_booking_end'               => (string) ( $args['end'] ?? gmdate( 'Ymd', time() + 3 * DAY_IN_SECONDS ) . '110000' ),
			'_booking_all_day'           => '0',
			'_booking_cost'              => '25',
			'_booking_persons'           => [],
			'_booking_attendance_status' => '',
			'_local_timezone'            => '',
			'_booking_note'              => '',
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return (int) $id;
	}
}
