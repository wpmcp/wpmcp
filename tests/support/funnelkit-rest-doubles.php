<?php
/**
 * Stand-ins for the FunnelKit Funnel Builder REST routes the funnel writes
 * call (issue #500), for the stub-backed suite, where FunnelKit itself is
 * not installed. bin/test-local.sh --live-funnelkit runs the same writes
 * through the real routes.
 *
 * Each stand-in answers at FunnelKit 3.16's own path and method, takes the
 * same parameters (the checkout ones a JSON body), applies FunnelKit's own
 * permission rule (its default funnel access is manage_options) and writes
 * the same storage FunnelKit writes: the bwf_funnels row and its steps JSON,
 * one post per step of the step's own post type carrying _bwf_in_funnel,
 * and a checkout's products in _wfacp_selected_products. They follow the
 * FunnelKit source closely enough to exercise the ops; they are not
 * FunnelKit.
 *
 * Call wpmcp_test_funnelkit_rest_doubles() from setUp() and
 * wpmcp_test_funnelkit_rest_reset() from tearDown().
 */

if ( ! function_exists( 'wpmcp_test_funnelkit_rest_doubles' ) ) {
	/** Register the stand-in routes on a fresh REST server. */
	function wpmcp_test_funnelkit_rest_doubles(): void {
		global $wp_rest_server;
		add_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_routes' );
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/** Drop the stand-in routes. */
	function wpmcp_test_funnelkit_rest_reset(): void {
		global $wp_rest_server;
		remove_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_routes' );
		$wp_rest_server = null;
	}

	/** A fresh REST server with no FunnelKit routes at all. */
	function wpmcp_test_funnelkit_rest_without_routes(): void {
		global $wp_rest_server;
		remove_action( 'rest_api_init', 'wpmcp_test_funnelkit_register_routes' );
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	function wpmcp_test_funnelkit_register_routes(): void {
		$ns    = 'funnelkit-app';
		$perm  = static fn (): bool => current_user_can( 'manage_options' );
		$types = [
			'landing'     => 'wffn_landing',
			'wc_checkout' => 'wfacp_checkout',
			'wc_thankyou' => 'wffn_ty',
			'optin'       => 'wffn_optin',
			'optin_ty'    => 'wffn_oty',
		];

		register_rest_route( $ns, '/funnels', [
			[
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;
					$name = (string) $request['funnel_name'];
					if ( '' === $name ) {
						return [ 'status' => false, 'msg' => 'Funnel creation failed' ];
					}
					$wpdb->insert( $wpdb->prefix . 'bwf_funnels', [ 'title' => $name, 'desc' => '', 'date_added' => current_time( 'mysql' ), 'steps' => '[]' ] );
					$id = (int) $wpdb->insert_id;
					$wpdb->insert( $wpdb->prefix . 'bwf_funnelmeta', [ 'bwf_funnel_id' => $id, 'meta_key' => '_version', 'meta_value' => '3.16.0' ] );
					return [ 'status' => true, 'funnel' => [ 'id' => $id ] ];
				},
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;
					foreach ( explode( ',', (string) $request['id'] ) as $id ) {
						$id = (int) $id;
						foreach ( wpmcp_test_funnelkit_steps( $id ) as $step ) {
							wp_delete_post( $step['id'], true );
						}
						$wpdb->delete( $wpdb->prefix . 'bwf_funnels', [ 'id' => $id ] );
					}
					return [ 'status' => true ];
				},
			],
		] );

		register_rest_route( $ns, '/funnels/(?P<funnel_id>[\d]+)/', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				global $wpdb;
				$id   = (int) $request['funnel_id'];
				$data = [ 'title' => (string) $request['title'], 'desc' => (string) $request['description'] ];
				if ( is_string( $request['steps'] ) ) {
					$order = [];
					foreach ( (array) json_decode( $request['steps'], true ) as $step ) {
						$order[] = [ 'type' => $step['type'], 'id' => (int) $step['id'], 'substeps' => [] ];
					}
					$data['steps'] = wp_json_encode( $order );
				}
				$wpdb->update( $wpdb->prefix . 'bwf_funnels', $data, [ 'id' => $id ] );
				return [ 'id' => $id ];
			},
		] );

		register_rest_route( $ns, '/funnels/(?P<funnel_id>[\d]+)/steps', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) use ( $types ) {
				$funnel_id = (int) $request['funnel_id'];
				$type      = (string) $request['type'];
				if ( ! isset( $types[ $type ] ) ) {
					return [ 'status' => false ];
				}
				$post_id = wp_insert_post( [ 'post_type' => $types[ $type ], 'post_title' => (string) $request['title'], 'post_status' => 'publish' ] );
				update_post_meta( $post_id, '_bwf_in_funnel', $funnel_id );
				update_post_meta( $post_id, '_experiment_status', 'not_active' );
				$steps   = wpmcp_test_funnelkit_steps( $funnel_id );
				$steps[] = [ 'type' => $type, 'id' => $post_id ];
				wpmcp_test_funnelkit_save_steps( $funnel_id, $steps );
				return [ 'status' => true, 'data' => [ 'id' => $post_id, 'type' => $type ] ];
			},
		] );

		register_rest_route( $ns, '/funnels/(?P<funnel_id>[\d]+)/steps/(?P<step_id>[\d]+)', [
			[
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = (int) $request['step_id'];
					wp_update_post( [ 'ID' => $id, 'post_status' => empty( $request['new_status'] ) ? 'draft' : 'publish' ] );
					return [ 'status' => true ];
				},
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$funnel_id = (int) $request['funnel_id'];
					$id        = (int) $request['step_id'];
					wp_delete_post( $id, true );
					wpmcp_test_funnelkit_save_steps( $funnel_id, array_values( array_filter( wpmcp_test_funnelkit_steps( $funnel_id ), static fn ( array $step ): bool => $step['id'] !== $id ) ) );
					return [ 'status' => true ];
				},
			],
		] );

		register_rest_route( $ns, '/funnels/(?P<funnel_id>[\d]+)/publish-all/', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				foreach ( (array) json_decode( (string) $request['steps'], true ) as $step ) {
					wp_update_post( [ 'ID' => (int) $step['id'], 'post_status' => 'publish' ] );
				}
				return [ 'status' => true ];
			},
		] );

		register_rest_route( $ns, '/funnel-checkout/(?P<step_id>[\d]+)/products', [
			[
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id       = (int) $request['step_id'];
					$body     = (array) json_decode( $request->get_body(), true );
					$existing = (array) get_post_meta( $id, '_wfacp_selected_products', true );
					foreach ( (array) ( $body['products'] ?? [] ) as $product_id ) {
						$product = wc_get_product( (int) $product_id );
						if ( $product instanceof WC_Product ) {
							$existing[ uniqid( 'wfacp_' ) ] = [
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
					update_post_meta( $id, '_wfacp_selected_products', $existing );
					return [ 'success' => true ];
				},
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $perm,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id       = (int) $request['step_id'];
					$body     = (array) json_decode( $request->get_body(), true );
					$existing = (array) get_post_meta( $id, '_wfacp_selected_products', true );
					foreach ( (array) ( $body['product_key'] ?? [] ) as $key ) {
						unset( $existing[ $key ] );
					}
					update_post_meta( $id, '_wfacp_selected_products', $existing );
					return [ 'success' => true ];
				},
			],
		] );

		register_rest_route( $ns, '/funnel-checkout/(?P<step_id>[\d]+)/products/save-layout', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $request ) {
				$id       = (int) $request['step_id'];
				$body     = (array) json_decode( $request->get_body(), true );
				$products = [];
				foreach ( (array) ( $body['products'] ?? [] ) as $product ) {
					$key           = $product['id'];
					$product['id'] = $product['key'];
					unset( $product['key'] );
					$products[ $key ] = $product;
				}
				update_post_meta( $id, '_wfacp_selected_products', $products );
				update_post_meta( $id, '_wfacp_selected_products_settings', (array) ( $body['settings'] ?? [] ) );
				return [ 'success' => true ];
			},
		] );
	}

	/** @return array<int, array{type: string, id: int}> */
	function wpmcp_test_funnelkit_steps( int $funnel_id ): array {
		global $wpdb;
		$steps = $wpdb->get_var( $wpdb->prepare( "SELECT steps FROM {$wpdb->prefix}bwf_funnels WHERE id = %d", $funnel_id ) );
		return array_map( static fn ( array $step ): array => [ 'type' => $step['type'], 'id' => (int) $step['id'] ], (array) json_decode( (string) $steps, true ) );
	}

	function wpmcp_test_funnelkit_save_steps( int $funnel_id, array $steps ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'bwf_funnels', [ 'steps' => wp_json_encode( $steps ) ], [ 'id' => $funnel_id ] );
	}
}
