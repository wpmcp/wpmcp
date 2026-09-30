<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\LMS_Tutor;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/lms-stubs.php';

/**
 * Tutor LMS course structure and enrollments (issue #394): paid-tier ops on
 * the plugin-data dispatcher pair, so they add no top-level tools.
 *
 * Tutor LMS is not loaded in the shared test core. Presence is driven through
 * wpmcp_tutor_active, and the storage is seeded the way Tutor LMS 4.1.0
 * keeps it (tests/support/lms-stubs.php): a course is a `courses` post, its
 * topics are `topics` posts whose post_parent is the course, lessons, quizzes
 * and assignments are `lesson`, `tutor_quiz` and `tutor_assignments` posts
 * whose post_parent is the topic, all ordered by menu_order (Tutor numbers
 * from 1), quiz questions are rows of {prefix}tutor_quiz_questions ordered by
 * question_order, and an enrollment is a `tutor_enrolled` post whose
 * post_parent is the course and whose post_author is the student
 * (post_status completed, pending or cancel).
 *
 * Writes are snapshotted: a created node is recorded for rollback (it goes to
 * the trash), an updated or reordered one is a post snapshot, and one call is
 * one session, so rollback-session undoes it. Enrollments are read-only and
 * need list_users, the capability that lists users anywhere else.
 */
class TutorLmsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'tutor-list-courses'     => 'read',
        'tutor-get-course'       => 'read',
        'tutor-list-enrollments' => 'read',
        'tutor-create-course'    => 'write',
        'tutor-add-section'      => 'write',
        'tutor-add-lesson'       => 'write',
        'tutor-add-quiz'         => 'write',
        'tutor-update-item'      => 'write',
        'tutor-move-item'        => 'write',
    ];

    /** The other plugin-data plugins, off so only this LMS decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active' ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_lms_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_lms_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_tutor_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'display_name' => 'Ada Admin' ]);
        $user        = get_userdata($this->admin);
        foreach (wpmcp_test_tutor_admin_caps() as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_tutor_active', '__return_true');
        add_filter('wpmcp_lifterlms_active', '__return_false');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_tutor_active');
        remove_all_filters('wpmcp_lifterlms_active');
        wpmcp_test_unregister_tutor_types();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function post(string $type, string $title, int $parent = 0, int $order = 0, string $status = 'publish'): int
    {
        return self::factory()->post->create([
            'post_type'   => $type,
            'post_title'  => $title,
            'post_parent' => $parent,
            'menu_order'  => $order,
            'post_status' => $status,
        ]);
    }

    private function question(int $quiz_id, string $title, int $order, string $type = 'single_choice', float $mark = 1.0): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tutor_quiz_questions', [
            'quiz_id'           => $quiz_id,
            'question_title'    => $title,
            'question_type'     => $type,
            'question_mark'     => $mark,
            'question_order'    => $order,
            'question_settings' => maybe_serialize([ 'question_type' => $type ]),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** A course with two topics: [course, topic1, topic2, lesson1, quiz, assignment, lesson2]. */
    private function seed_course(): array
    {
        $course = $this->post('courses', 'Photography 101');
        $t2     = $this->post('topics', 'Lighting', $course, 2);
        $t1     = $this->post('topics', 'Basics', $course, 1);
        $quiz   = $this->post('tutor_quiz', 'Basics quiz', $t1, 2);
        $l1     = $this->post('lesson', 'Holding the camera', $t1, 1);
        $asg    = $this->post('tutor_assignments', 'Shoot a still life', $t1, 3);
        $l2     = $this->post('lesson', 'Natural light', $t2, 1);
        $this->question($quiz, 'Second question', 2, 'true_false', 2.0);
        $this->question($quiz, 'First question', 1);
        return [ $course, $t1, $t2, $l1, $quiz, $asg, $l2 ];
    }

    /** @return int[] ids of the topic's contents in menu_order. */
    private function children(int $parent, array $types): array
    {
        return array_map('intval', get_posts([
            'post_type'      => $types,
            'post_parent'    => $parent,
            'post_status'    => 'any',
            'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]));
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_tutor_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertSame('list_users', $ops['tutor-list-enrollments']['capability']);
    }

    public function test_without_pro_the_tutor_ops_are_not_in_the_catalog_and_the_pair_is_not_available(): void
    {
        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $ops         = array_column($integration->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse($integration->is_available());
    }

    public function test_while_tutor_is_inactive_its_ops_answer_tutor_inactive(): void
    {
        remove_all_filters('wpmcp_tutor_active');
        add_filter('wpmcp_tutor_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('tutor-list-courses');
        remove_all_filters('wpmcp_buddypress_active');
        $this->assertSame('tutor_inactive', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_get_course_returns_the_topic_tree_in_tutor_order_with_quiz_questions(): void
    {
        [$course, $t1, $t2, $l1, $quiz, $asg, $l2] = $this->seed_course();

        $out  = $this->ok($this->read('tutor-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame($course, $out['course']['id']);
        $this->assertSame('Photography 101', $out['course']['title']);
        $this->assertSame([ $t1, $t2 ], array_column($out['sections'], 'id'));
        $this->assertSame([ 'topic', 'topic' ], array_column($out['sections'], 'kind'));

        $items = $out['sections'][0]['items'];
        $this->assertSame([ $l1, $quiz, $asg ], array_column($items, 'id'));
        $this->assertSame([ 'lesson', 'quiz', 'assignment' ], array_column($items, 'kind'));
        $this->assertSame([ 'First question', 'Second question' ], array_column($items[1]['questions'], 'title'));
        $this->assertSame('true_false', $items[1]['questions'][1]['type']);
        $this->assertEquals(2.0, $items[1]['questions'][1]['points']);
        $this->assertSame([ $l2 ], array_column($out['sections'][1]['items'], 'id'));
        $this->assertSame([ 'sections' => 2, 'lessons' => 2, 'quizzes' => 1, 'assignments' => 1, 'questions' => 2 ], $out['counts']);
    }

    public function test_get_course_refuses_an_id_that_is_not_a_tutor_course(): void
    {
        $page = $this->post('page', 'Not a course');
        $out  = $this->read('tutor-get-course', [ 'id' => $page ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
    }

    public function test_list_courses_pages_with_structure_and_enrollment_counts_and_search(): void
    {
        [$course] = $this->seed_course();
        $other    = $this->post('courses', 'Watercolor', 0, 0, 'draft');
        $student  = self::factory()->user->create();
        $this->post('tutor_enrolled', 'Course Enrolled', $course, 0, 'completed');
        wp_update_post([ 'ID' => $this->post('tutor_enrolled', 'Course Enrolled', $course, 0, 'completed'), 'post_author' => $student ]);

        $out  = $this->ok($this->read('tutor-list-courses'))['result'];
        $rows = array_column($out['courses'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame([ 'sections' => 2, 'lessons' => 2, 'quizzes' => 1 ], array_intersect_key($rows[ $course ], [ 'sections' => 0, 'lessons' => 0, 'quizzes' => 0 ]));
        $this->assertSame(2, $rows[ $course ]['enrolled']);
        $this->assertSame('draft', $rows[ $other ]['status']);

        $found = $this->ok($this->read('tutor-list-courses', [ 'search' => 'Water' ]))['result'];
        $this->assertSame([ $other ], array_column($found['courses'], 'id'));
    }

    public function test_list_enrollments_needs_list_users_and_returns_only_the_listed_user_fields(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create([ 'display_name' => 'Alice Student', 'user_email' => 'alice@example.com' ]);
        $bob      = self::factory()->user->create([ 'display_name' => 'Bob Student', 'user_email' => 'bob@example.com' ]);
        foreach ([ [ $alice, 'completed' ], [ $bob, 'cancel' ] ] as [$user, $status]) {
            self::factory()->post->create([
                'post_type'   => 'tutor_enrolled',
                'post_title'  => 'Course Enrolled',
                'post_parent' => $course,
                'post_author' => $user,
                'post_status' => $status,
            ]);
        }

        $out  = $this->ok($this->read('tutor-list-enrollments', [ 'course_id' => $course ]))['result'];
        $rows = array_column($out['enrollments'], null, 'user_id');
        $this->assertSame(2, $out['total']);
        $this->assertSame('enrolled', $rows[ $alice ]['status']);
        $this->assertSame('cancelled', $rows[ $bob ]['status']);
        $this->assertSame('alice@example.com', $rows[ $alice ]['email']);
        $this->assertSame([ 'user_id', 'display_name', 'email', 'status', 'enrolled_at' ], array_keys($rows[ $alice ]));

        $only = $this->ok($this->read('tutor-list-enrollments', [ 'course_id' => $course, 'status' => 'enrolled' ]))['result'];
        $this->assertSame([ $alice ], array_column($only['enrollments'], 'user_id'));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $denied = $this->read('tutor-list-enrollments', [ 'course_id' => $course ]);
        $this->assertSame('operation_denied', $denied['error']['code'] ?? null);
    }

    public function test_enrollments_are_read_only_there_is_no_enrollment_write(): void
    {
        $ops    = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'mode', 'name');
        $enrols = array_filter($ops, static fn (string $name): bool => str_starts_with($name, 'tutor-') && str_contains($name, 'enrol'), ARRAY_FILTER_USE_KEY);
        $this->assertSame([ 'tutor-list-enrollments' => 'read' ], $enrols);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_building_a_course_stores_it_the_way_tutor_does_and_one_session_rollback_undoes_it(): void
    {
        $session = wp_generate_uuid4();
        $course  = $this->ok($this->write('tutor-create-course', [ 'title' => 'Pottery', 'content' => 'Clay basics' ], $session));
        $this->assertTrue($course['recoverable']);
        $this->assertNotEmpty($course['operation_ids']);
        $course_id = $course['result']['item']['id'];
        $this->assertSame('courses', get_post_type($course_id));
        $this->assertSame('draft', get_post_status($course_id));

        $topic    = $this->ok($this->write('tutor-add-section', [ 'course_id' => $course_id, 'title' => 'Wheel' ], $session));
        $topic_id = $topic['result']['item']['id'];
        $this->assertSame('topics', get_post_type($topic_id));
        $this->assertSame($course_id, wp_get_post_parent_id($topic_id));
        $this->assertSame(1, (int) get_post_field('menu_order', $topic_id));

        $lesson = $this->ok($this->write('tutor-add-lesson', [ 'parent_id' => $topic_id, 'title' => 'Centering' ], $session))['result']['item']['id'];
        $quiz   = $this->ok($this->write('tutor-add-quiz', [ 'parent_id' => $topic_id, 'title' => 'Wheel quiz' ], $session))['result']['item']['id'];
        $this->assertSame('lesson', get_post_type($lesson));
        $this->assertSame('tutor_quiz', get_post_type($quiz));
        $this->assertSame([ $lesson, $quiz ], $this->children($topic_id, [ 'lesson', 'tutor_quiz' ]));
        $this->assertSame([ 1, 2 ], [ (int) get_post_field('menu_order', $lesson), (int) get_post_field('menu_order', $quiz) ]);

        $tree = $this->ok($this->read('tutor-get-course', [ 'id' => $course_id ]))['result'];
        $this->assertSame([ $lesson, $quiz ], array_column($tree['sections'][0]['items'], 'id'));

        Rollback_Service::restore_session($session);
        foreach ([ $course_id, $topic_id, $lesson, $quiz ] as $id) {
            $this->assertSame('trash', get_post_status($id), "post {$id}");
        }
    }

    public function test_adding_a_lesson_at_a_position_renumbers_its_siblings_and_rollback_puts_them_back(): void
    {
        [, $t1, , $l1, $quiz, $asg] = $this->seed_course();
        $before                     = array_map(static fn (int $id): int => (int) get_post_field('menu_order', $id), [ $l1, $quiz, $asg ]);

        $out = $this->ok($this->write('tutor-add-lesson', [ 'parent_id' => $t1, 'title' => 'Intro', 'position' => 0 ]));
        $new = $out['result']['item']['id'];
        $this->assertSame([ $new, $l1, $quiz, $asg ], $this->children($t1, [ 'lesson', 'tutor_quiz', 'tutor_assignments' ]));
        $this->assertSame([ 1, 2, 3, 4 ], array_map(static fn (int $id): int => (int) get_post_field('menu_order', $id), [ $new, $l1, $quiz, $asg ]));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($new));
        $this->assertSame($before, array_map(static fn (int $id): int => (int) get_post_field('menu_order', $id), [ $l1, $quiz, $asg ]));
    }

    public function test_update_item_is_snapshotted_and_rollback_operation_restores_it(): void
    {
        [, , , $l1] = $this->seed_course();
        $out        = $this->ok($this->write('tutor-update-item', [ 'id' => $l1, 'title' => 'Gripping the camera', 'status' => 'draft' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Gripping the camera', get_the_title($l1));
        $this->assertSame('draft', get_post_status($l1));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Holding the camera', get_the_title($l1));
        $this->assertSame('publish', get_post_status($l1));
    }

    public function test_update_item_refuses_a_post_that_is_not_part_of_a_tutor_course(): void
    {
        $page = $this->post('page', 'About');
        $out  = $this->write('tutor-update-item', [ 'id' => $page, 'title' => 'Hijacked' ]);
        $this->assertSame('item_not_found', $out['error']['code'] ?? null);
        $this->assertSame('About', get_the_title($page));
    }

    public function test_move_item_moves_a_lesson_to_another_topic_and_rollback_session_restores_both_topics(): void
    {
        [, $t1, $t2, $l1, $quiz, $asg, $l2] = $this->seed_course();

        $out = $this->ok($this->write('tutor-move-item', [ 'id' => $l1, 'parent_id' => $t2, 'position' => 0 ]));
        $this->assertSame($t2, wp_get_post_parent_id($l1));
        $this->assertSame([ $l1, $l2 ], $this->children($t2, [ 'lesson', 'tutor_quiz', 'tutor_assignments' ]));
        $this->assertSame([ $quiz, $asg ], $this->children($t1, [ 'lesson', 'tutor_quiz', 'tutor_assignments' ]));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($t1, wp_get_post_parent_id($l1));
        $this->assertSame([ $l1, $quiz, $asg ], $this->children($t1, [ 'lesson', 'tutor_quiz', 'tutor_assignments' ]));
        $this->assertSame([ $l2 ], $this->children($t2, [ 'lesson', 'tutor_quiz', 'tutor_assignments' ]));
    }

    public function test_move_item_reorders_topics_within_their_course(): void
    {
        [$course, $t1, $t2] = $this->seed_course();
        $this->ok($this->write('tutor-move-item', [ 'id' => $t2, 'position' => 0 ]));
        $this->assertSame([ $t2, $t1 ], $this->children($course, [ 'topics' ]));
    }

    public function test_move_item_refuses_a_move_into_another_course_without_touching_anything(): void
    {
        [, , , $l1] = $this->seed_course();
        $other      = $this->post('courses', 'Other');
        $foreign    = $this->post('topics', 'Elsewhere', $other, 1);
        $count      = Snapshot_Store::row_count();

        $out = $this->write('tutor-move-item', [ 'id' => $l1, 'parent_id' => $foreign, 'position' => 0 ]);
        $this->assertSame('cross_course_move', $out['error']['code'] ?? null);
        $this->assertNotSame($foreign, wp_get_post_parent_id($l1));
        $this->assertSame($count, Snapshot_Store::row_count());
    }

    public function test_add_lesson_refuses_a_parent_that_is_not_a_topic(): void
    {
        [$course] = $this->seed_course();
        $out      = $this->write('tutor-add-lesson', [ 'parent_id' => $course, 'title' => 'Orphan' ]);
        $this->assertSame('invalid_parent', $out['error']['code'] ?? null);
    }

    public function test_a_user_without_tutor_lesson_capabilities_cannot_add_a_lesson(): void
    {
        [, $t1] = $this->seed_course();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $out = $this->write('tutor-add-lesson', [ 'parent_id' => $t1, 'title' => 'Sneaky' ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertSame([], get_posts([ 'post_type' => 'lesson', 'title' => 'Sneaky', 'post_status' => 'any', 'fields' => 'ids' ]));
    }

    public function test_update_item_refuses_an_empty_change_and_publishing_without_the_capability(): void
    {
        [, , , $l1] = $this->seed_course();
        $this->assertSame('nothing_to_update', $this->write('tutor-update-item', [ 'id' => $l1 ])['error']['code'] ?? null);

        wp_get_current_user()->remove_cap('publish_tutor_lessons');
        wp_update_post([ 'ID' => $l1, 'post_status' => 'draft' ]);
        $out = $this->write('tutor-update-item', [ 'id' => $l1, 'status' => 'publish' ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertSame('draft', get_post_status($l1));
    }

    public function test_get_course_leaves_questions_out_on_request(): void
    {
        [$course] = $this->seed_course();
        $out      = $this->ok($this->read('tutor-get-course', [ 'id' => $course, 'questions' => false ]))['result'];
        $this->assertArrayNotHasKey('questions', $out['sections'][0]['items'][1]);
        $this->assertNull($out['counts']['questions']);
    }

    public function test_trashing_a_tutor_course_outside_an_admin_screen_does_not_redirect_and_exit(): void
    {
        // Tutor LMS hooks this on trashed_post to redirect and exit; the guard
        // Plugin::boot() adds ahead of it takes it off outside wp-admin.
        $redirect = 'TUTOR\\Course::redirect_to_course_list_page';
        $this->assertSame(1, has_action('trashed_post', [ LMS_Tutor::class, 'keep_request_alive' ]));

        [$course, $t1] = $this->seed_course();
        add_action('trashed_post', $redirect);
        LMS_Tutor::keep_request_alive($t1);
        $this->assertSame(10, has_action('trashed_post', $redirect), 'only a course trash is guarded');
        LMS_Tutor::keep_request_alive($course);
        $this->assertFalse(has_action('trashed_post', $redirect));
    }
}
