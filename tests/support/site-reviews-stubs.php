<?php
/**
 * Tables and API stand-ins for the Site Reviews ops (issue #687), for tests
 * that run without the plugin loaded.
 *
 * The DDL is taken from the free plugin on wordpress.org (Site Reviews 8.3.8,
 * plugin/Database/Tables/TableRatings.php and TableAssignedPosts.php), every
 * column with the plugin's own type and default; the foreign keys are left
 * out because the shared test core's tables carry none of the plugin's
 * constraints. Reviews are `site-review` posts, registered by the test the
 * way Commands/RegisterPostType.php registers them.
 *
 * The two functions below stand in for the plugin's own behaviour the ops
 * rely on: glsr_update_review() (ReviewManager::update(): the status through
 * wp_update_post, the reply as the _response and _response_by meta) and
 * ReviewController::onAfterChangeStatus(), the transition_post_status hook
 * that keeps the ratings row's is_approved in step with the post status.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_site_reviews_tables' ) ) {
	function wpmcp_test_create_site_reviews_tables(): void {
		global $wpdb;

		wpmcp_test_drop_site_reviews_tables();
		$p       = $wpdb->get_blog_prefix();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}glsr_ratings (
			ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			review_id bigint(20) unsigned NOT NULL,
			rating int(11) NOT NULL DEFAULT '0',
			type varchar(20) DEFAULT 'local',
			is_approved tinyint(1) NOT NULL DEFAULT '0',
			is_pinned tinyint(1) NOT NULL DEFAULT '0',
			is_flagged tinyint(1) NOT NULL DEFAULT '0',
			is_verified tinyint(1) NOT NULL DEFAULT '0',
			name varchar(250) DEFAULT NULL,
			email varchar(100) DEFAULT NULL,
			avatar varchar(200) DEFAULT NULL,
			ip_address varchar(100) DEFAULT NULL,
			url varchar(250) DEFAULT NULL,
			terms tinyint(1) NOT NULL DEFAULT '1',
			score int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY  (ID),
			UNIQUE KEY glsr_ratings_review_id_unique (review_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}glsr_assigned_posts (
			rating_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			is_published tinyint(1) NOT NULL DEFAULT '1',
			PRIMARY KEY  (rating_id,post_id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_site_reviews_tables(): void {
		global $wpdb;
		foreach ( [ 'glsr_ratings', 'glsr_assigned_posts' ] as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->get_blog_prefix() . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/** glsr_update_review() as ReviewManager::update() does it, for the values the ops send. */
	function wpmcp_test_site_reviews_update( int $id, array $values ) {
		// UpdateReviewDefaults derives the status from is_approved; a bare status is ignored.
		if ( isset( $values['is_approved'], $values['status'] ) ) {
			$out = wp_update_post( [ 'ID' => $id, 'post_status' => wp_validate_boolean( $values['is_approved'] ) ? 'publish' : 'pending' ], true );
			if ( is_wp_error( $out ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'response', $values ) ) {
			update_post_meta( $id, '_response', wp_kses_post( (string) $values['response'] ) );
			update_post_meta( $id, '_response_by', get_current_user_id() );
		}
		return (object) [ 'ID' => $id ];
	}

	/** ReviewController::onAfterChangeStatus(): the ratings row follows the post status. */
	function wpmcp_test_site_reviews_transition( string $new, string $old, $post ): void {
		global $wpdb;
		if ( $post instanceof WP_Post && 'site-review' === $post->post_type && ! in_array( $old, [ 'new', $new ], true ) ) {
			$wpdb->update( $wpdb->get_blog_prefix() . 'glsr_ratings', [ 'is_approved' => 'publish' === $new ? 1 : 0 ], [ 'review_id' => $post->ID ] );
		}
	}
}
