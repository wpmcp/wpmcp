<?php
/**
 * Post types, taxonomies and the GeoDirectory detail table for the
 * directory listing ops (issue #707), for tests that run without WP Job
 * Manager, GeoDirectory or Directorist loaded.
 *
 * Each post type and taxonomy is registered the way the plugin registers
 * it (WP Job Manager 2.4 WP_Job_Manager_Post_Types::register_post_types,
 * GeoDirectory 2.8 GeoDir_Post_types, Directorist 8.11
 * ATBDP_Custom_Post::register_new_post_types): the same names, capability
 * types and meta capability mapping, so the per-object checks are the real
 * ones.
 *
 * The GeoDirectory table keeps the columns of GeoDir_Admin_Install's
 * db_cpt_default_columns() plus the phone, email and website custom field
 * columns its default fields add. DDL commits implicitly, so it is created
 * in wpSetUpBeforeClass() and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_register_listing_types' ) ) {
	function wpmcp_test_register_listing_types(): void {
		register_post_type( 'job_listing', [ 'public' => true, 'capability_type' => 'job_listing', 'map_meta_cap' => true, 'supports' => [ 'title', 'editor', 'custom-fields', 'thumbnail', 'author' ] ] );
		register_taxonomy( 'job_listing_category', [ 'job_listing' ], [ 'hierarchical' => true, 'capabilities' => [ 'manage_terms' => 'manage_job_listings', 'edit_terms' => 'manage_job_listings', 'delete_terms' => 'manage_job_listings', 'assign_terms' => 'edit_job_listings' ] ] );
		register_taxonomy( 'job_listing_type', [ 'job_listing' ], [ 'hierarchical' => true, 'capabilities' => [ 'manage_terms' => 'manage_job_listings', 'edit_terms' => 'manage_job_listings', 'delete_terms' => 'manage_job_listings', 'assign_terms' => 'edit_job_listings' ] ] );
		register_post_status( 'expired', [ 'public' => true, 'protected' => true ] );

		register_post_type( 'gd_place', [ 'public' => true, 'capability_type' => 'post', 'map_meta_cap' => true, 'supports' => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt' ] ] );
		register_taxonomy( 'gd_placecategory', [ 'gd_place' ], [ 'hierarchical' => true ] );
		register_taxonomy( 'gd_place_tags', [ 'gd_place' ], [ 'hierarchical' => false ] );

		register_post_type( 'at_biz_dir', [ 'public' => true, 'capability_type' => 'at_biz_dir', 'map_meta_cap' => true, 'supports' => [ 'title', 'editor', 'author', 'excerpt' ] ] );
		register_taxonomy( 'at_biz_dir-category', [ 'at_biz_dir' ], [ 'hierarchical' => true ] );
		register_taxonomy( 'at_biz_dir-location', [ 'at_biz_dir' ], [ 'hierarchical' => true ] );
		register_taxonomy( 'at_biz_dir-tags', [ 'at_biz_dir' ], [ 'hierarchical' => false ] );
		register_taxonomy( 'atbdp_listing_types', [ 'at_biz_dir' ], [ 'hierarchical' => false, 'public' => false ] );
	}

	function wpmcp_test_unregister_listing_types(): void {
		foreach ( [ 'job_listing_category', 'job_listing_type', 'gd_placecategory', 'gd_place_tags', 'at_biz_dir-category', 'at_biz_dir-location', 'at_biz_dir-tags', 'atbdp_listing_types' ] as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}
		foreach ( [ 'job_listing', 'gd_place', 'at_biz_dir' ] as $type ) {
			unregister_post_type( $type );
		}
	}

	/** Every capability the three post types map to, as the plugins grant them to administrators. */
	function wpmcp_test_grant_listing_caps( WP_User $user ): void {
		foreach ( [ 'job_listing', 'at_biz_dir' ] as $type ) {
			foreach ( (array) get_post_type_object( $type )->cap as $cap ) {
				if ( ! str_contains( (string) $cap, '_post' ) || str_ends_with( (string) $cap, '_posts' ) ) {
					$user->add_cap( (string) $cap );
				}
			}
		}
		$user->add_cap( 'manage_job_listings' );
	}

	function wpmcp_test_create_geodirectory_table(): void {
		global $wpdb;
		wpmcp_test_drop_geodirectory_table();
		$t       = $wpdb->prefix . 'geodir_gd_place_detail';
		$charset = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$t} (
			post_id bigint(20) NOT NULL,
			post_title text NULL DEFAULT NULL,
			_search_title text NOT NULL,
			post_status varchar(20) NULL DEFAULT NULL,
			post_tags text NULL DEFAULT NULL,
			post_category text NULL DEFAULT NULL,
			default_category INT NULL DEFAULT NULL,
			featured tinyint(1) NOT NULL DEFAULT '0',
			featured_image varchar(254) NULL DEFAULT NULL,
			submit_ip varchar(100) NULL DEFAULT NULL,
			overall_rating float(11) DEFAULT '0',
			rating_count int(11) DEFAULT '0',
			street VARCHAR(254) NULL,
			street2 VARCHAR(254) NULL,
			city VARCHAR(50) NULL,
			region VARCHAR(50) NULL,
			country VARCHAR(50) NULL,
			zip VARCHAR(50) NULL,
			latitude VARCHAR(22) NULL,
			longitude VARCHAR(22) NULL,
			mapview VARCHAR(15) NULL,
			mapzoom VARCHAR(3) NULL,
			phone VARCHAR(254) NULL,
			email VARCHAR(254) NULL,
			website TEXT NULL,
			PRIMARY KEY  (post_id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_geodirectory_table(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'geodir_gd_place_detail' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test fixture.
	}
}
