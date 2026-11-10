<?php
/**
 * Post types, capabilities and tables for the LMS ops (issue #394) on the
 * plugin-data pair, for tests that run without Tutor LMS or LifterLMS loaded.
 *
 * Copied from the plugins' own source:
 *  - Tutor LMS 4.1.0: classes/Post_types.php (courses, topics, lesson,
 *    tutor_quiz, tutor_assignments, tutor_enrolled, with the course, lesson
 *    and quiz capability maps) and the tutor_quiz_questions DDL in
 *    classes/Tutor.php.
 *  - LifterLMS 10.2.1: includes/class.llms.post-types.php (course, section,
 *    lesson, llms_quiz, llms_question, with the capability maps
 *    LLMS_Post_Types::get_post_type_caps() builds) and the
 *    lifterlms_user_postmeta DDL in includes/class.llms.install.php.
 *
 * LearnDash (commercial, not in the test plugin set) is modelled on its
 * documented storage instead: the sfwd-* post types on its course
 * capabilities, the course builder's ld_course_steps meta, and the learner
 * user meta (course_<id>_access_from, _sfwd-course_progress,
 * course_completed_<id>, _sfwd-quizzes). Unverified against a real
 * LearnDash.
 *
 * Tutor LMS and LifterLMS both register a post type named "lesson", so a test registers one
 * plugin's set in setUp() and removes it in tearDown(), never both at once.
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_tutor_caps' ) ) {
	/** Tutor LMS capability map for one singular/plural pair, as Post_types.php spells it. */
	function wpmcp_test_tutor_caps( string $singular, string $plural ): array {
		return [
			'edit_post'          => "edit_tutor_{$singular}",
			'read_post'          => "read_tutor_{$singular}",
			'delete_post'        => "delete_tutor_{$singular}",
			'delete_posts'       => "delete_tutor_{$plural}",
			'edit_posts'         => "edit_tutor_{$plural}",
			'edit_others_posts'  => "edit_others_tutor_{$plural}",
			'publish_posts'      => "publish_tutor_{$plural}",
			'read_private_posts' => "read_private_tutor_{$plural}",
			'create_posts'       => "edit_tutor_{$plural}",
		];
	}

	/** The capabilities Tutor LMS grants administrators for its own types. */
	function wpmcp_test_tutor_admin_caps(): array {
		$caps = [];
		foreach ( [ [ 'course', 'courses' ], [ 'lesson', 'lessons' ], [ 'quiz', 'quizzes' ] ] as $pair ) {
			$caps = array_merge( $caps, array_values( wpmcp_test_tutor_caps( $pair[0], $pair[1] ) ) );
		}
		return array_values( array_unique( $caps ) );
	}

	function wpmcp_test_register_tutor_types(): void {
		register_post_type( 'courses', [
			'public'          => true,
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author' ],
			'capabilities'    => wpmcp_test_tutor_caps( 'course', 'courses' ),
		] );
		register_post_type( 'topics', [ 'public' => false ] );
		register_post_type( 'lesson', [
			'public'          => true,
			'capability_type' => 'post',
			'supports'        => [ 'title', 'editor', 'comments' ],
			'capabilities'    => wpmcp_test_tutor_caps( 'lesson', 'lessons' ),
		] );
		register_post_type( 'tutor_quiz', [
			'public'          => true,
			'capability_type' => 'post',
			'supports'        => [ 'title', 'editor' ],
			'capabilities'    => wpmcp_test_tutor_caps( 'quiz', 'quizzes' ),
		] );
		register_post_type( 'tutor_assignments', [ 'public' => false, 'capability_type' => 'post' ] );
		register_post_type( 'tutor_enrolled', [ 'public' => false ] );
	}

	function wpmcp_test_unregister_tutor_types(): void {
		foreach ( [ 'courses', 'topics', 'lesson', 'tutor_quiz', 'tutor_assignments', 'tutor_enrolled' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}
}

if ( ! function_exists( 'wpmcp_test_llms_caps' ) ) {
	/** LLMS_Post_Types::get_post_type_caps() for one singular/plural pair. */
	function wpmcp_test_llms_caps( string $singular, string $plural ): array {
		return [
			'read_post'              => "read_{$singular}",
			'read_private_posts'     => "read_private_{$plural}",
			'edit_post'              => "edit_{$singular}",
			'edit_posts'             => "edit_{$plural}",
			'edit_others_posts'      => "edit_others_{$plural}",
			'edit_private_posts'     => "edit_private_{$plural}",
			'edit_published_posts'   => "edit_published_{$plural}",
			'publish_posts'          => "publish_{$plural}",
			'delete_post'            => "delete_{$singular}",
			'delete_posts'           => "delete_{$plural}",
			'delete_private_posts'   => "delete_private_{$plural}",
			'delete_published_posts' => "delete_published_{$plural}",
			'delete_others_posts'    => "delete_others_{$plural}",
			'create_posts'           => "create_{$plural}",
		];
	}

	/** The plural capabilities LLMS_Roles grants administrators for these types. */
	function wpmcp_test_llms_admin_caps(): array {
		$caps = [];
		foreach ( [ [ 'course', 'courses' ], [ 'lesson', 'lessons' ], [ 'quiz', 'quizzes' ], [ 'question', 'questions' ] ] as $pair ) {
			foreach ( wpmcp_test_llms_caps( $pair[0], $pair[1] ) as $meta => $cap ) {
				if ( ! in_array( $meta, [ 'read_post', 'edit_post', 'delete_post' ], true ) ) {
					$caps[] = $cap;
				}
			}
		}
		return array_values( array_unique( $caps ) );
	}

	function wpmcp_test_register_lifterlms_types(): void {
		register_post_type( 'course', [
			'public'       => true,
			'map_meta_cap' => true,
			'supports'     => [ 'title', 'author', 'editor', 'excerpt' ],
			'capabilities' => wpmcp_test_llms_caps( 'course', 'courses' ),
		] );
		register_post_type( 'section', [ 'public' => false, 'map_meta_cap' => true, 'supports' => [ 'title' ] ] );
		register_post_type( 'lesson', [
			'public'       => true,
			'map_meta_cap' => true,
			'supports'     => [ 'title', 'editor', 'excerpt' ],
			'capabilities' => wpmcp_test_llms_caps( 'lesson', 'lessons' ),
		] );
		register_post_type( 'llms_quiz', [
			'public'       => true,
			'map_meta_cap' => true,
			'supports'     => [ 'title', 'editor', 'author' ],
			'capabilities' => wpmcp_test_llms_caps( 'quiz', 'quizzes' ),
		] );
		register_post_type( 'llms_question', [
			'public'       => false,
			'map_meta_cap' => true,
			'supports'     => [ 'title', 'editor' ],
			'capabilities' => wpmcp_test_llms_caps( 'question', 'questions' ),
		] );
	}

	function wpmcp_test_unregister_lifterlms_types(): void {
		foreach ( [ 'course', 'section', 'lesson', 'llms_quiz', 'llms_question' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}
}

if ( ! function_exists( 'wpmcp_test_register_learndash_types' ) ) {
	/**
	 * LearnDash's course capability map, which its course, lesson, topic,
	 * quiz and question types all share (capability_type course, with
	 * map_meta_cap), as LearnDash 4.x registers them and grants them to
	 * administrators. Written from LearnDash's documented storage, not
	 * copied from its source: LearnDash is commercial and not in the test
	 * plugin set.
	 */
	function wpmcp_test_learndash_caps(): array {
		return [
			'read_post'              => 'read_course',
			'edit_post'              => 'edit_course',
			'delete_post'            => 'delete_course',
			'edit_posts'             => 'edit_courses',
			'edit_others_posts'      => 'edit_others_courses',
			'publish_posts'          => 'publish_courses',
			'read_private_posts'     => 'read_private_courses',
			'delete_posts'           => 'delete_courses',
			'delete_others_posts'    => 'delete_others_courses',
			'delete_private_posts'   => 'delete_private_courses',
			'delete_published_posts' => 'delete_published_courses',
			'edit_private_posts'     => 'edit_private_courses',
			'edit_published_posts'   => 'edit_published_courses',
			'create_posts'           => 'edit_courses',
		];
	}

	/** The primitive course capabilities LearnDash grants administrators. */
	function wpmcp_test_learndash_admin_caps(): array {
		$caps = [];
		foreach ( wpmcp_test_learndash_caps() as $meta => $cap ) {
			if ( ! in_array( $meta, [ 'read_post', 'edit_post', 'delete_post' ], true ) ) {
				$caps[] = $cap;
			}
		}
		return array_values( array_unique( $caps ) );
	}

	/**
	 * LearnDash post types: sfwd-courses, sfwd-lessons, sfwd-topic,
	 * sfwd-quiz and sfwd-question, all on the course capabilities above.
	 */
	function wpmcp_test_register_learndash_types(): void {
		foreach ( [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question' ] as $type ) {
			register_post_type( $type, [
				'public'          => 'sfwd-question' !== $type,
				'capability_type' => 'course',
				'capabilities'    => wpmcp_test_learndash_caps(),
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'page-attributes' ],
			] );
		}
	}

	function wpmcp_test_unregister_learndash_types(): void {
		foreach ( [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}

	/**
	 * A course's ld_course_steps value as LearnDash's course builder stores
	 * it: the step tree under steps.h (lessons > topics > quizzes, with the
	 * lesson's quizzes beside its topics and course-level quizzes at the
	 * top), plus the builder's bookkeeping keys.
	 *
	 * @param array<int, array{topics?: array<int, int[]>, quizzes?: int[]}> $lessons lesson id => its topics (topic id => quiz ids) and quizzes.
	 * @param int[] $finals course-level quiz ids.
	 */
	function wpmcp_test_learndash_steps( int $course, array $lessons, array $finals = [] ): array {
		$h     = [ 'sfwd-lessons' => [], 'sfwd-quiz' => [] ];
		$count = 0;
		foreach ( $lessons as $lesson => $children ) {
			$node = [ 'sfwd-topic' => [], 'sfwd-quiz' => [] ];
			++$count;
			foreach ( (array) ( $children['topics'] ?? [] ) as $topic => $quizzes ) {
				$node['sfwd-topic'][ $topic ] = [ 'sfwd-quiz' => array_fill_keys( (array) $quizzes, [] ) ];
				$count += 1 + count( (array) $quizzes );
			}
			foreach ( (array) ( $children['quizzes'] ?? [] ) as $quiz ) {
				$node['sfwd-quiz'][ $quiz ] = [];
				++$count;
			}
			$h['sfwd-lessons'][ $lesson ] = $node;
		}
		foreach ( $finals as $quiz ) {
			$h['sfwd-quiz'][ $quiz ] = [];
			++$count;
		}
		return [
			'steps'                       => [ 'h' => $h ],
			'course_id'                   => $course,
			'version'                     => '4.20.0',
			'empty'                       => 0 === $count,
			'course_builder_enabled'      => true,
			'course_shared_steps_enabled' => false,
			'steps_count'                 => $count,
		];
	}
}

if ( ! function_exists( 'wpmcp_test_create_lms_tables' ) ) {
	function wpmcp_test_create_lms_tables(): void {
		global $wpdb;

		wpmcp_test_drop_lms_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}tutor_quiz_questions (
			question_id bigint(20) NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) DEFAULT NULL,
			question_title text,
			question_description longtext,
			answer_explanation longtext DEFAULT '',
			question_type varchar(50) DEFAULT NULL,
			question_mark decimal(9,2) DEFAULT NULL,
			question_settings longtext,
			question_order int(11) DEFAULT NULL,
			PRIMARY KEY (question_id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}lifterlms_user_postmeta (
			meta_id bigint(20) NOT NULL auto_increment,
			user_id bigint(20) NOT NULL,
			post_id bigint(20) NOT NULL,
			meta_key varchar(255) NULL,
			meta_value longtext NULL,
			updated_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (meta_id),
			KEY user_id (user_id),
			KEY post_id (post_id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_lms_tables(): void {
		global $wpdb;
		foreach ( [ 'tutor_quiz_questions', 'lifterlms_user_postmeta' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table name.
		}
	}
}
