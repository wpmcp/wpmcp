<?php
/**
 * All in One SEO's two custom tables, for tests that exercise the AIOSEO
 * adapter without the plugin installed in the shared test core.
 *
 * `aioseo_posts` is copied from the free plugin's own schema
 * (app/Common/Db/Schema.php, getPostsTableSchema(), plugin 5.0.2).
 * `aioseo_terms` only ships with the paid plugin, so it is the same row
 * shape keyed by term_id instead of post_id, with the columns the term
 * table carries: the title, description, canonical, social and robots
 * columns the adapter reads and writes are identical to the post table's.
 *
 * DDL commits implicitly, so callers create the tables in
 * wpSetUpBeforeClass() and drop them in wpTearDownAfterClass(), outside the
 * per-test transaction, the way ReversibleDbWritesTest does.
 */

if ( ! function_exists( 'wpmcp_test_create_aioseo_tables' ) ) {
	/** Create both AIOSEO tables, or only the post table when $with_terms is false. */
	function wpmcp_test_create_aioseo_tables( bool $with_terms = true ): void {
		global $wpdb;

		wpmcp_test_drop_aioseo_tables();

		$shared = "
			title text DEFAULT NULL,
			description text DEFAULT NULL,
			keywords mediumtext DEFAULT NULL,
			keyphrases longtext DEFAULT NULL,
			page_analysis longtext DEFAULT NULL,
			canonical_url text DEFAULT NULL,
			og_title text DEFAULT NULL,
			og_description text DEFAULT NULL,
			og_object_type varchar(64) DEFAULT 'default',
			og_image_type varchar(64) DEFAULT 'default',
			og_image_custom_url text DEFAULT NULL,
			og_image_custom_fields text DEFAULT NULL,
			og_image_url text DEFAULT NULL,
			og_image_width int(11) DEFAULT NULL,
			og_image_height int(11) DEFAULT NULL,
			og_video varchar(255) DEFAULT NULL,
			og_custom_url text DEFAULT NULL,
			og_article_section text DEFAULT NULL,
			og_article_tags text DEFAULT NULL,
			twitter_use_og tinyint(1) DEFAULT 0,
			twitter_card varchar(64) DEFAULT 'default',
			twitter_image_type varchar(64) DEFAULT 'default',
			twitter_image_custom_url text DEFAULT NULL,
			twitter_image_custom_fields text DEFAULT NULL,
			twitter_image_url text DEFAULT NULL,
			twitter_title text DEFAULT NULL,
			twitter_description text DEFAULT NULL,
			seo_score int(11) DEFAULT 0 NOT NULL,
			pillar_content tinyint(1) DEFAULT NULL,
			robots_default tinyint(1) DEFAULT 1 NOT NULL,
			robots_noindex tinyint(1) DEFAULT 0 NOT NULL,
			robots_noarchive tinyint(1) DEFAULT 0 NOT NULL,
			robots_nosnippet tinyint(1) DEFAULT 0 NOT NULL,
			robots_nofollow tinyint(1) DEFAULT 0 NOT NULL,
			robots_noimageindex tinyint(1) DEFAULT 0 NOT NULL,
			robots_noodp tinyint(1) DEFAULT 0 NOT NULL,
			robots_notranslate tinyint(1) DEFAULT 0 NOT NULL,
			robots_max_snippet int(11) DEFAULT NULL,
			robots_max_videopreview int(11) DEFAULT NULL,
			robots_max_imagepreview varchar(20) DEFAULT 'large',
			images longtext DEFAULT NULL,
			priority float DEFAULT NULL,
			frequency tinytext DEFAULT NULL,
			videos longtext DEFAULT NULL,
			video_scan_date datetime DEFAULT NULL,
			local_seo longtext DEFAULT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}aioseo_posts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			focus_keyword varchar(255) DEFAULT NULL,
			truseo longtext DEFAULT NULL,
			additional_keywords text DEFAULT NULL,
			truseo_locale varchar(20) DEFAULT NULL,
			primary_term longtext DEFAULT NULL,
			schema_type varchar(20) DEFAULT 'default',
			schema_type_options longtext DEFAULT NULL,
			`schema` longtext DEFAULT NULL,
			image_scan_date datetime DEFAULT NULL,
			video_thumbnail text DEFAULT NULL,
			limit_modified_date tinyint(1) NOT NULL DEFAULT 0,
			options longtext DEFAULT NULL,
			ai longtext DEFAULT NULL,
			breadcrumb_settings longtext DEFAULT NULL,
			seo_analyzer_scan_date datetime DEFAULT NULL,
			{$shared}
			PRIMARY KEY  (id),
			KEY ndx_aioseo_posts_post_id (post_id)
		) {$wpdb->get_charset_collate()}" );

		if ( $with_terms ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
			$wpdb->query( "CREATE TABLE {$wpdb->prefix}aioseo_terms (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				term_id bigint(20) unsigned NOT NULL,
				{$shared}
				PRIMARY KEY  (id),
				KEY ndx_aioseo_terms_term_id (term_id)
			) {$wpdb->get_charset_collate()}" );
		}
	}
}

if ( ! function_exists( 'wpmcp_test_drop_aioseo_tables' ) ) {
	function wpmcp_test_drop_aioseo_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aioseo_posts, {$wpdb->prefix}aioseo_terms" );
	}
}

if ( ! function_exists( 'wpmcp_test_aioseo_rows' ) ) {
	/** Every row for one post or term, oldest first, as the database holds it. */
	function wpmcp_test_aioseo_rows( string $kind, int $id ): array {
		global $wpdb;
		$table  = $wpdb->prefix . ( 'term' === $kind ? 'aioseo_terms' : 'aioseo_posts' );
		$column = 'term' === $kind ? 'term_id' : 'post_id';

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d ORDER BY id ASC', $table, $column, $id ),
			ARRAY_A
		);
	}
}
