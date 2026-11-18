<?php
/**
 * Thrive Ovation 10.9.1.4 as the Thrive Ovation pack sees it (issue #829),
 * recorded from the plugin's own code: inc/functions.php registers the
 * tvo_tags taxonomy and then the tvo_testimonials post type (capability type
 * page, not public, linked to tvo_tags through its 'taxonomies' arg), and
 * inc/data.php keeps a testimonial as a published post whose author details
 * live in the _tvo_testimonial_attributes meta array and whose Ovation
 * status and source are the _tvo_testimonial_status and
 * _tvo_testimonial_source metas ('0' ready for display, '1' awaiting
 * approval, '2' awaiting review, '3' rejected).
 *
 * The Thrive dashboard grants the tve-use-tvo capability to administrators
 * and editors; the tests grant it by hand.
 */

if ( ! function_exists( 'wpmcp_test_register_thrive_ovation_types' ) ) {
	function wpmcp_test_register_thrive_ovation_types(): void {
		register_taxonomy( 'tvo_tags', 'TVO Tags', [ 'hierarchical' => true, 'label' => 'TVO Tags', 'query_var' => true ] );
		register_post_type( 'tvo_testimonials', [
			'label'               => 'tvo_testimonials',
			'supports'            => [ 'title', 'editor', 'author', 'thumbnail' ],
			'taxonomies'          => [ 'tvo_tags', 'tvo_properties' ],
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'page',
		] );
	}

	function wpmcp_test_unregister_thrive_ovation_types(): void {
		unregister_post_type( 'tvo_testimonials' );
		unregister_taxonomy( 'tvo_tags' );
	}

	/**
	 * A testimonial as tvo_create_testimonial() stores it.
	 *
	 * @param array<string, mixed> $fields
	 */
	function wpmcp_test_ovation_testimonial( array $fields = [] ): int {
		$fields += [
			'title'   => 'Great service',
			'content' => 'They fixed everything in a day.',
			'name'    => 'Jane Doe',
			'email'   => 'jane.doe@example.com',
			'role'    => 'CEO',
			'website' => 'https://example.com',
			'status'  => '0',
			'source'  => '3',
			'post_status' => 'publish',
		];
		$id = wp_insert_post( [
			'post_type'    => 'tvo_testimonials',
			'post_title'   => $fields['title'],
			'post_content' => $fields['content'],
			'post_status'  => $fields['post_status'],
		] );
		update_post_meta( $id, '_tvo_testimonial_attributes', [
			'email'       => $fields['email'],
			'role'        => $fields['role'],
			'website_url' => $fields['website'],
			'picture_url' => '',
			'name'        => $fields['name'],
		] );
		update_post_meta( $id, '_tvo_testimonial_status', $fields['status'] );
		update_post_meta( $id, '_tvo_testimonial_source', $fields['source'] );
		return (int) $id;
	}
}
