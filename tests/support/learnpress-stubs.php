<?php
/**
 * Post types, capabilities and tables for the LearnPress ops (issue #672) on
 * the plugin-data pair, for tests that run without LearnPress loaded.
 *
 * Copied from LearnPress 4.4.15:
 *  - inc/custom-post-types/course.php, lesson.php and quiz.php: lp_course on
 *    capability_type lp_course, lp_lesson and lp_quiz on capability_type
 *    lp_lesson, all with map_meta_cap;
 *  - config/table/tables-v4.php: the learnpress_sections,
 *    learnpress_section_items, learnpress_quiz_questions and
 *    learnpress_user_items DDL.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_register_learnpress_types' ) ) {
	function wpmcp_test_register_learnpress_types(): void {
		register_post_type( 'lp_course', [
			'public'          => true,
			'capability_type' => 'lp_course',
			'map_meta_cap'    => true,
			'supports'        => [ 'title', 'editor', 'excerpt' ],
		] );
		foreach ( [ 'lp_lesson', 'lp_quiz', 'lp_question' ] as $type ) {
			register_post_type( $type, [
				'public'          => 'lp_question' !== $type,
				'capability_type' => 'lp_lesson',
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor', 'excerpt' ],
			] );
		}
	}

	function wpmcp_test_unregister_learnpress_types(): void {
		foreach ( [ 'lp_course', 'lp_lesson', 'lp_quiz', 'lp_question' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}

	/** The primitive capabilities LearnPress grants administrators for its types. */
	function wpmcp_test_learnpress_admin_caps(): array {
		$caps = [];
		foreach ( [ 'lp_course', 'lp_lesson' ] as $type ) {
			foreach ( (array) get_post_type_object( $type )->cap as $meta => $cap ) {
				if ( ! in_array( $meta, [ 'read_post', 'edit_post', 'delete_post', 'read' ], true ) ) {
					$caps[] = $cap;
				}
			}
		}
		return array_values( array_unique( $caps ) );
	}

	function wpmcp_test_create_learnpress_tables(): void {
		global $wpdb;

		wpmcp_test_drop_learnpress_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}learnpress_sections (
			section_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			section_name varchar(255) NOT NULL DEFAULT '',
			section_course_id bigint(20) unsigned NOT NULL DEFAULT '0',
			section_order bigint(10) unsigned NOT NULL DEFAULT '1',
			section_description longtext NOT NULL,
			PRIMARY KEY (section_id),
			KEY section_course_id (section_course_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}learnpress_section_items (
			section_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			section_id bigint(20) unsigned NOT NULL DEFAULT '0',
			item_id bigint(20) unsigned NOT NULL DEFAULT '0',
			item_order bigint(20) unsigned NOT NULL DEFAULT '0',
			item_type varchar(45),
			PRIMARY KEY (section_item_id),
			KEY section_item (section_id, item_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}learnpress_quiz_questions (
			quiz_question_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL DEFAULT '0',
			question_id bigint(20) unsigned NOT NULL DEFAULT '0',
			question_order bigint(20) unsigned NOT NULL DEFAULT '1',
			PRIMARY KEY (quiz_question_id),
			KEY quiz_id (quiz_id),
			KEY question_id (question_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}learnpress_user_items (
			user_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT '0',
			item_id bigint(20) unsigned NOT NULL DEFAULT '0',
			start_time datetime NULL DEFAULT NULL,
			end_time datetime NULL DEFAULT NULL,
			item_type varchar(45) NOT NULL DEFAULT '',
			status varchar(45) NOT NULL DEFAULT '',
			graduation varchar(20) NULL DEFAULT NULL,
			access_level int(3) NOT NULL DEFAULT 50,
			ref_id bigint(20) unsigned NOT NULL DEFAULT '0',
			ref_type varchar(45) DEFAULT '',
			parent_id bigint(20) unsigned NOT NULL DEFAULT '0',
			PRIMARY KEY (user_item_id),
			KEY parent_id (parent_id),
			KEY user_id (user_id),
			KEY item_id (item_id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_learnpress_tables(): void {
		global $wpdb;
		foreach ( [ 'learnpress_sections', 'learnpress_section_items', 'learnpress_quiz_questions', 'learnpress_user_items' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table name.
		}
	}
}
