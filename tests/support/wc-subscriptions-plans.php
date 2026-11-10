<?php
/**
 * Subscription plan fixtures (issue #817): the product and storewide plan
 * data WooCommerce Subscriptions 9.x stores, recorded from 9.4.1.
 *
 * A simple or variable product is sold on subscription through plans:
 *
 *  - product meta _wcsatt_schemes_status: disable, override (own plans) or
 *    inherit (storewide plans);
 *  - product meta _wcsatt_schemes: the product's own plans, a list of the
 *    arrays below (a variation may carry its own list too);
 *  - option wcsatt_subscribe_to_cart_schemes: the storewide plans, the same
 *    shape;
 *  - product meta _wcsatt_storewide_selection_mode (all or specific) and
 *    _wcsatt_selected_storewide_plans (storewide plan ids) in inherit mode;
 *  - product meta _wcsatt_force_subscription: yes when the product cannot
 *    be bought once.
 *
 * Plans saved by 9.x carry a uuid id; plans saved by the standalone add-on
 * before 9.0 have none and are keyed "{interval}_{period}_{length}".
 * WooSubscriptionsLiveTest checks these shapes against what the real plugin
 * stores and reads.
 */

if (! function_exists('wpmcp_test_wc_plan_fixtures')) {
	/**
	 * @return array<string, array<string, mixed>>
	 */
	function wpmcp_test_wc_plan_fixtures(): array {
		return [
			// Monthly, 10% off the product price, a week's trial and a sign-up fee.
			'monthly_discount' => [
				'id'                           => '6f1c2b8e-1d3a-4c55-9a0e-0d6c1f2b3a41',
				'subscription_period'          => 'month',
				'subscription_period_interval' => 1,
				'subscription_length'          => 0,
				'subscription_trial_period'    => 'day',
				'subscription_trial_length'    => 7,
				'subscription_signup_fee'      => '5',
				'subscription_pricing_method'  => 'inherit',
				'subscription_discount'        => '10',
			],
			// Yearly for two years at the plan's own price, on sale.
			'yearly_override' => [
				'id'                           => '0b7a4d2c-8e1f-4f6a-b3c9-5e2d7a1c9f80',
				'subscription_period'          => 'year',
				'subscription_period_interval' => 1,
				'subscription_length'          => 2,
				'subscription_trial_period'    => '',
				'subscription_trial_length'    => 0,
				'subscription_signup_fee'      => '0',
				'subscription_pricing_method'  => 'override',
				'subscription_regular_price'   => '100',
				'subscription_sale_price'      => '90',
			],
			// Every two weeks, 3 off: saved before 9.0, so no id.
			'fortnightly_fixed_legacy' => [
				'subscription_period'          => 'week',
				'subscription_period_interval' => 2,
				'subscription_length'          => 0,
				'subscription_pricing_method'  => 'fixed_discount',
				'subscription_discount'        => '3',
			],
			// Storewide: weekly at the product price, and quarterly 5% off.
			'storewide_weekly' => [
				'id'                           => 'a3e9c7d1-2b4f-4e8a-9c6d-1f0e2d3c4b5a',
				'subscription_period'          => 'week',
				'subscription_period_interval' => 1,
				'subscription_length'          => 0,
				'subscription_trial_period'    => 'day',
				'subscription_trial_length'    => 0,
				'subscription_signup_fee'      => '0',
				'subscription_pricing_method'  => 'inherit',
				'subscription_discount'        => '',
			],
			'storewide_quarterly' => [
				'id'                           => 'c5d4e3f2-a1b0-4c9d-8e7f-6a5b4c3d2e1f',
				'subscription_period'          => 'month',
				'subscription_period_interval' => 3,
				'subscription_length'          => 0,
				'subscription_trial_period'    => 'day',
				'subscription_trial_length'    => 0,
				'subscription_signup_fee'      => '0',
				'subscription_pricing_method'  => 'inherit',
				'subscription_discount'        => '5',
			],
		];
	}

	/**
	 * Give a product plans the way Subscriptions stores them.
	 *
	 * @param string[] $plans fixture names; empty with mode inherit uses the storewide plans.
	 * @param array<string, mixed> $extra more product meta.
	 */
	function wpmcp_test_wc_set_plans( int $product_id, string $mode, array $plans = [], array $extra = [] ): void {
		$fixtures = wpmcp_test_wc_plan_fixtures();
		if ( '' !== $mode ) {
			update_post_meta( $product_id, '_wcsatt_schemes_status', $mode );
		}
		if ( [] !== $plans ) {
			update_post_meta( $product_id, '_wcsatt_schemes', array_map( static fn ( string $name ) => $fixtures[ $name ], $plans ) );
		}
		foreach ( $extra as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}
	}
}
