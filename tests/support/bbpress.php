<?php

/**
 * Helpers for the bbPress tests: everything a bbPress write can change, as
 * one comparable array, so a rollback is exact when the dump matches.
 */

if ( ! function_exists( 'wpmcp_test_bbpress_dump' ) ) {
	/**
	 * Every forum, topic, reply and revision row with its meta, every _bbp_
	 * user meta row, every topic tag relationship and term count, and the
	 * super sticky option.
	 *
	 * @return array<string, mixed>
	 */
	function wpmcp_test_bbpress_dump(): array {
		global $wpdb;
		clean_post_cache( 0 );
		$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type IN ('forum','topic','reply','revision') ORDER BY ID ASC", ARRAY_A );
		$ids   = array_map( 'intval', array_column( $posts, 'ID' ) );
		$meta  = [] === $ids ? [] : $wpdb->get_results( 'SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode( ',', $ids ) . ') ORDER BY post_id ASC, meta_id ASC', ARRAY_A );
		return [
			'posts'   => $posts,
			'meta'    => $meta,
			'users'   => $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s ORDER BY user_id ASC, umeta_id ASC", '\_bbp\_%', $wpdb->esc_like( $wpdb->get_blog_prefix() . '_bbp_' ) . '%' ), ARRAY_A ),
			'tags'    => $wpdb->get_results( "SELECT tr.object_id, tt.term_id, tt.count FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'topic-tag' ORDER BY tr.object_id ASC, tt.term_id ASC", ARRAY_A ),
			'counts'  => $wpdb->get_results( "SELECT term_id, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'topic-tag' ORDER BY term_id ASC", ARRAY_A ),
			'options' => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = '_bbp_super_sticky_topics'", ARRAY_A ),
		];
	}
}
