<?php
/**
 * Thrive Apprentice storage for the LMS ops (issue #826), for tests that run
 * without the plugin loaded. Copied from Thrive Apprentice 10.9.1.4
 * (inc/functions.php tva_init(), class-tva-const.php, class-tva-manager.php):
 *  - a course is a term of the hierarchical `tva_courses` taxonomy, its
 *    status in the tva_status term meta (publish, draft, hidden, archived);
 *  - modules, chapters, lessons and assessments are `tva_module`,
 *    `tva_chapter`, `tva_lesson` and `tva_assessment` posts filed under the
 *    course term, nested by post_parent (a top-level one has post_parent 0);
 *  - the order is post meta: tva_module_order, tva_chapter_order and, for
 *    lessons and assessments, tva_lesson_order, each the parent's own order
 *    string followed by the 0-based position (a module "1", its chapter
 *    "10", that chapter's lesson "102"), sorted with meta_value_num;
 *  - the lesson type lets edit_others_posts and edit_published_posts take the
 *    Thrive Dashboard capability tve-edit-cpt.
 */

if ( ! function_exists( 'wpmcp_test_register_thrive_apprentice' ) ) {
	function wpmcp_test_register_thrive_apprentice(): void {
		register_post_type( 'tva_lesson', [
			'public'       => true,
			'hierarchical' => false,
			'taxonomies'   => [ 'tva_courses' ],
			'map_meta_cap' => true,
			'capabilities' => [
				'edit_others_posts'    => 'tve-edit-cpt',
				'edit_published_posts' => 'tve-edit-cpt',
			],
		] );
		foreach ( [ 'tva_chapter', 'tva_module', 'tva_assessment' ] as $type ) {
			register_post_type( $type, [
				'public'       => true,
				'hierarchical' => false,
				'taxonomies'   => [ 'tva_courses' ],
			] );
		}
		register_taxonomy( 'tva_courses', [ 'tva_lesson', 'tva_chapter', 'tva_module' ], [
			'hierarchical'       => true,
			'public'             => true,
			'publicly_queryable' => true,
		] );
		register_taxonomy_for_object_type( 'tva_courses', 'tva_assessment' );
	}

	function wpmcp_test_unregister_thrive_apprentice(): void {
		if ( taxonomy_exists( 'tva_courses' ) ) {
			unregister_taxonomy( 'tva_courses' );
		}
		foreach ( [ 'tva_lesson', 'tva_chapter', 'tva_module', 'tva_assessment' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}
}
