<?php
/**
 * Post types and taxonomies for the CartFlows ops (issue #662) on the theme
 * pair, for tests that run without CartFlows loaded.
 *
 * Taken from CartFlows 3.3.1's own source:
 *  - modules/flow/classes/class-cartflows-flow-post-type.php registers
 *    cartflows_flow (not public, capability_type post);
 *  - modules/flow/classes/class-cartflows-step-post-type.php registers
 *    cartflows_step (public, capability_type post, map_meta_cap) and the
 *    cartflows_step_type and cartflows_step_flow taxonomies on it;
 *  - admin-core/ajax/importer.php (create_flow, create_step) stores a
 *    flow's ordered steps in its wcf-steps meta as [id, title, type]
 *    entries, and each step's flow and type in wcf-flow-id and
 *    wcf-step-type;
 *  - admin-core/inc/meta-ops.php stores a checkout's products in
 *    wcf-checkout-products as rows of strings (product, quantity,
 *    discount_type, discount_value, unique_id, add_to_cart).
 */

if ( ! function_exists( 'wpmcp_test_register_cartflows_types' ) ) {
	function wpmcp_test_register_cartflows_types(): void {
		register_post_type( 'cartflows_flow', [
			'public'          => false,
			'show_ui'         => true,
			'capability_type' => 'post',
			'supports'        => [ 'title', 'thumbnail' ],
		] );
		register_post_type( 'cartflows_step', [
			'public'          => true,
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => [ 'title', 'editor', 'elementor', 'revisions' ],
		] );
		register_taxonomy( 'cartflows_step_type', 'cartflows_step', [ 'public' => false, 'hierarchical' => false ] );
		register_taxonomy( 'cartflows_step_flow', 'cartflows_step', [ 'public' => false, 'hierarchical' => false ] );
	}

	function wpmcp_test_unregister_cartflows_types(): void {
		foreach ( [ 'cartflows_step_type', 'cartflows_step_flow' ] as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		foreach ( [ 'cartflows_flow', 'cartflows_step' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}

	/**
	 * A flow seeded the way CartFlows' own create_flow() writes it: the flow
	 * post, one published step post per [type, title] with its meta and
	 * terms, and the flow's wcf-steps list.
	 *
	 * @param array<int, array{0: string, 1: string}> $steps
	 * @return array{flow: int, steps: int[]}
	 */
	function wpmcp_test_seed_cartflows_flow( string $title, array $steps, string $status = 'publish' ): array {
		$flow = wp_insert_post( [ 'post_type' => 'cartflows_flow', 'post_title' => $title, 'post_status' => $status ] );
		$ids  = [];
		$list = [];
		foreach ( $steps as [ $type, $step_title ] ) {
			$id = wp_insert_post( [ 'post_type' => 'cartflows_step', 'post_title' => $step_title, 'post_status' => 'publish' ] );
			update_post_meta( $id, 'wcf-flow-id', $flow );
			update_post_meta( $id, 'wcf-step-type', $type );
			wp_set_object_terms( $id, $type, 'cartflows_step_type' );
			wp_set_object_terms( $id, 'flow-' . $flow, 'cartflows_step_flow' );
			$ids[]  = $id;
			$list[] = [ 'id' => $id, 'title' => $step_title, 'type' => $type ];
		}
		update_post_meta( $flow, 'wcf-steps', $list );
		return [ 'flow' => $flow, 'steps' => $ids ];
	}
}
