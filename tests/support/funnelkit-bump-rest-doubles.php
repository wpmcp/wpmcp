<?php
/**
 * Stand-ins for the FunnelKit order bump REST routes (issue #663), for the
 * stub-backed suite, where Funnel Builder Pro is not installed. Call
 * wpmcp_test_funnelkit_bump_rest_doubles() just before
 * wpmcp_test_funnelkit_rest_doubles() in setUp(), and
 * wpmcp_test_funnelkit_bump_rest_reset() in tearDown().
 *
 * Each answers at the path and method Funnel Builder Pro 3.x registers and
 * writes the storage it writes, read from its source:
 *  - POST funnels/{funnel_id}/steps/{step_id}/substeps (free
 *    admin/rest-api/class-wffn-rest-substeps.php create_substep(), with the
 *    order bump add_substep() of
 *    modules/order-bumps/compatibilities/funnel-builder/class-wffn-substep-wc-order_bump.php):
 *    a published wfob_bump post carrying _bwf_in_funnel, its id appended to
 *    the checkout step's substeps.wc_order_bump in the funnel's steps JSON;
 *  - DELETE funnels/{funnel_id}/steps/{step_id}/substeps/{substep_id}
 *    (WFFN_Step_Base::delete_substep() and WFFN_Funnel::delete_substep()):
 *    the post trashed (wp_delete_post without force), its id taken out of
 *    the substeps list, the group dropped once empty;
 *  - POST funnel-bump/save-state/{step_id} (free
 *    class-wffn-rest-funnel-modules.php save_state()): JSON body, status
 *    publishes or drafts the post, title renames it;
 *  - POST, DELETE funnel-bump/{step_id}/products and POST
 *    funnel-bump/{step_id}/products/save-layout
 *    (modules/order-bumps/compatibilities/funnel-builder/class-wffn-rest-bump-api-endpoint.php):
 *    entries of _wfob_selected_products keyed by a uniqid('wfob_') key,
 *    _wfob_product_settings and _wfob_settings.
 *
 * They follow the source closely enough to exercise the ops; they are not
 * FunnelKit.
 */

if ( ! function_exists( 'wpmcp_test_funnelkit_bump_rest_doubles' ) ) {
	/** Add the bump routes to the next REST server; call before wpmcp_test_funnelkit_rest_doubles(). */
	function wpmcp_test_funnelkit_bump_rest_doubles(): void {
		add_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_bump_routes' );
	}

	function wpmcp_test_funnelkit_bump_rest_reset(): void {
		remove_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_bump_routes' );
	}

	/** The raw steps list of a funnel, substeps included. */
	function wpmcp_test_funnelkit_raw_steps( int $funnel_id ): array {
		global $wpdb;
		$steps = $wpdb->get_var( $wpdb->prepare( "SELECT steps FROM {$wpdb->prefix}bwf_funnels WHERE id = %d", $funnel_id ) );
		return array_values( array_filter( (array) json_decode( (string) $steps, true ), 'is_array' ) );
	}

	function wpmcp_test_funnelkit_register_bump_routes(): void {
		$ns   = 'funnelkit-app';
		$perm = static fn (): bool => current_user_can( 'manage_options' );

		register_rest_route( $ns, '/funnels/(?P<funnel_id>[\d]+)/steps/(?P<step_id>[\d]+)/substeps', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				$funnel_id = (int) $request['funnel_id'];
				$step_id   = (int) $request['step_id'];
				if ( 'wc_order_bump' !== (string) $request['type'] ) {
					return [ 'status' => false ];
				}
				$bump_id = wp_insert_post( [ 'post_type' => 'wfob_bump', 'post_title' => (string) $request['title'], 'post_status' => 'publish' ] );
				update_post_meta( $bump_id, '_bwf_in_funnel', $funnel_id );
				update_post_meta( $bump_id, '_wfob_version', '3.0.0' );
				$steps = wpmcp_test_funnelkit_raw_steps( $funnel_id );
				foreach ( $steps as $i => $step ) {
					if ( (int) $step['id'] === $step_id ) {
						$steps[ $i ]['substeps']['wc_order_bump'][] = $bump_id;
					}
				}
				wpmcp_test_funnelkit_save_steps( $funnel_id, $steps );
				return [ 'status' => true, 'data' => [ 'id' => $bump_id ] ];
			},
		] );

		register_rest_route( $ns, '/funnels/(?P<funnel_id>[\d]+)/steps/(?P<step_id>[\d]+)/substeps/(?P<substep_id>[\d]+)', [
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				$funnel_id = (int) $request['funnel_id'];
				$step_id   = (int) $request['step_id'];
				$bump_id   = (int) $request['substep_id'];
				wp_delete_post( $bump_id );
				$steps = wpmcp_test_funnelkit_raw_steps( $funnel_id );
				foreach ( $steps as $i => $step ) {
					if ( (int) $step['id'] !== $step_id ) {
						continue;
					}
					$left = array_values( array_filter( array_map( 'intval', (array) ( $step['substeps']['wc_order_bump'] ?? [] ) ), static fn ( int $id ): bool => $id !== $bump_id ) );
					if ( [] === $left ) {
						unset( $steps[ $i ]['substeps']['wc_order_bump'] );
					} else {
						$steps[ $i ]['substeps']['wc_order_bump'] = $left;
					}
				}
				wpmcp_test_funnelkit_save_steps( $funnel_id, $steps );
				return [ 'status' => true ];
			},
		] );

		register_rest_route( $ns, '/funnel-bump/save-state/(?P<step_id>[\d]+)', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				$id   = (int) $request['step_id'];
				$body = (array) json_decode( $request->get_body(), true );
				if ( isset( $body['status'] ) ) {
					wp_update_post( [ 'ID' => $id, 'post_status' => wp_validate_boolean( $body['status'] ) ? 'publish' : 'draft' ] );
				}
				if ( isset( $body['title'] ) ) {
					wp_update_post( [ 'ID' => $id, 'post_title' => (string) $body['title'], 'post_name' => sanitize_title( (string) $body['title'] ) ] );
				}
				return [ 'success' => true ];
			},
		] );

		register_rest_route( $ns, '/funnel-bump/(?P<step_id>[\d]+)/products', [
			[
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id       = (int) $request['step_id'];
					$body     = (array) json_decode( $request->get_body(), true );
					$existing = get_post_meta( $id, '_wfob_selected_products', true );
					$existing = is_array( $existing ) ? $existing : [];
					foreach ( (array) ( $body['products'] ?? [] ) as $product_id ) {
						$product = wc_get_product( (int) $product_id );
						if ( $product instanceof WC_Product ) {
							$existing[ uniqid( 'wfob_' ) ] = [
								'title'           => $product->get_title(),
								'discount_type'   => 'percent_discount_sale',
								'discount_amount' => 0,
								'discount_price'  => 0,
								'quantity'        => 1,
								'type'            => $product->get_type(),
								'id'              => $product->get_id(),
							];
						}
					}
					update_post_meta( $id, '_wfob_selected_products', $existing );
					return [ 'success' => true ];
				},
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id       = (int) $request['step_id'];
					$body     = (array) json_decode( $request->get_body(), true );
					$existing = (array) get_post_meta( $id, '_wfob_selected_products', true );
					foreach ( (array) ( $body['product_key'] ?? [] ) as $key ) {
						unset( $existing[ $key ] );
					}
					update_post_meta( $id, '_wfob_selected_products', $existing );
					return [ 'success' => true ];
				},
			],
		] );

		register_rest_route( $ns, '/funnel-bump/(?P<step_id>[\d]+)/products/save-layout', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				$id       = (int) $request['step_id'];
				$body     = (array) json_decode( $request->get_body(), true );
				$settings = (array) ( $body['settings'] ?? [] );
				$products = [];
				foreach ( (array) ( $body['products'] ?? [] ) as $product ) {
					$key           = $product['id'];
					$product['id'] = $product['key'];
					unset( $product['key'] );
					$products[ $key ] = $product;
				}
				$product_settings = [
					'bump_action_type'         => $settings['bump_action_type'] ?? [],
					'bump_replace_type'        => $settings['bump_replace_type'] ?? 'all',
					'selected_replace_product' => $settings['selected_replace_product'] ?? '',
				];
				unset( $settings['bump_action_type'] );
				update_post_meta( $id, '_wfob_selected_products', $products );
				update_post_meta( $id, '_wfob_product_settings', $product_settings );
				update_post_meta( $id, '_wfob_settings', $settings );
				return [ 'success' => true ];
			},
		] );
	}
}
