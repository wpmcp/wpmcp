<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/lms-stubs.php';

/**
 * LearnDash course structure, enrollments and learner progress: paid-tier ops
 * on the plugin-data dispatcher pair (LMS_LearnDash), so they add no
 * top-level tools.
 *
 * LearnDash is not loaded in the shared test core. Presence is driven through
 * wpmcp_learndash_active and the storage is seeded the way LearnDash keeps
 * it (tests/support/lms-stubs.php): a course is a `sfwd-courses` post, its
 * lessons (the tree's sections) are `sfwd-lessons` posts whose course_id meta
 * is the course, topics (the tree's items) and quizzes are `sfwd-topic` and
 * `sfwd-quiz` posts whose course_id and lesson_id meta link them, all ordered
 * by menu_order, questions are `sfwd-question` posts whose quiz_id meta is
 * the quiz, and an enrollment is the user meta course_{id}_access_from.
 * Progress is the `_sfwd-course_progress` user meta.
 *
 * Writes are snapshotted: a created node is recorded for rollback (it goes
 * to the trash), an enrollment or progress change is a user snapshot, and one
 * call is one session, so rollback-session undoes it.
 */
class LearnDashIntegrationTest extends \WP_UnitTestCase
{
    private const OPS = [
        'learndash-list-courses'      => 'read',
        'learndash-get-course'        => 'read',
        'learndash-list-enrollments'  => 'read',
        'learndash-list-user-courses' => 'read',
        'learndash-create-course'     => 'write',
        'learndash-add-section'       => 'write',
        'learndash-add-lesson'        => 'write',
        'learndash-add-quiz'          => 'write',
        'learndash-update-item'       => 'write',
        'learndash-move-item'         => 'write',
        'learndash-enroll-user'       => 'write',
        'learndash-unenroll-user'     => 'write',
        'learndash-reset-progress'    => 'write',
    ];

    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active' ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_learndash_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'display_name' => 'Ada Admin' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_learndash_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_learndash_active');
        wpmcp_test_unregister_learndash_types();
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

    private function post(string $type, string $title, array $meta = [], int $order = 0, string $status = 'publish'): int
    {
        return self::factory()->post->create([
            'post_type'   => $type,
            'post_title'  => $title,
            'menu_order'  => $order,
            'post_status' => $status,
            'meta_input'  => $meta,
        ]);
    }

    /** A course with two lessons: [course, lesson1, lesson2, topic1, quiz, topic2, topic_quiz]. */
    private function seed_course(): array
    {
        $course = $this->post('sfwd-courses', 'Photography 101');
        $l2     = $this->post('sfwd-lessons', 'Lighting', [ 'course_id' => $course ], 2);
        $l1     = $this->post('sfwd-lessons', 'Basics', [ 'course_id' => $course ], 1);
        $quiz   = $this->post('sfwd-quiz', 'Basics quiz', [ 'course_id' => $course, 'lesson_id' => $l1 ], 2);
        $t1     = $this->post('sfwd-topic', 'Holding the camera', [ 'course_id' => $course, 'lesson_id' => $l1 ], 1);
        $t2     = $this->post('sfwd-topic', 'Natural light', [ 'course_id' => $course, 'lesson_id' => $l2 ], 1);
        $tq     = $this->post('sfwd-quiz', 'Camera quiz', [ 'course_id' => $course, 'lesson_id' => $t1 ], 1);
        $this->post('sfwd-question', 'Second question', [ 'quiz_id' => $quiz, 'question_type' => 'single', 'question_points' => 2 ], 2);
        $this->post('sfwd-question', 'First question', [ 'quiz_id' => $quiz, 'question_type' => 'multiple', 'question_points' => 1 ], 1);
        return [ $course, $l1, $l2, $t1, $quiz, $t2, $tq ];
    }

    private function enroll(int $user, int $course, int $when = 1700000000): void
    {
        update_user_meta($user, "course_{$course}_access_from", $when);
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_learndash_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertSame('list_users', $ops['learndash-list-enrollments']['capability']);
        $this->assertSame('list_users', $ops['learndash-list-user-courses']['capability']);
        $this->assertSame('edit_users', $ops['learndash-enroll-user']['capability']);
    }

    public function test_without_pro_the_learndash_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $ops         = array_column($integration->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse($integration->is_available());
    }

    public function test_while_learndash_is_inactive_its_ops_answer_learndash_inactive(): void
    {
        remove_all_filters('wpmcp_learndash_active');
        add_filter('wpmcp_learndash_active', '__return_false');
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('learndash-list-courses');
        $this->assertSame('learndash_inactive', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_get_course_returns_lessons_with_topics_and_quizzes_in_learndash_order(): void
    {
        [$course, $l1, $l2, $t1, $quiz, $t2, $tq] = $this->seed_course();

        $out = $this->ok($this->read('learndash-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame($course, $out['course']['id']);
        $this->assertSame([ $l1, $l2 ], array_column($out['sections'], 'id'));
        $this->assertSame([ 'lesson', 'lesson' ], array_column($out['sections'], 'kind'));

        $items = $out['sections'][0]['items'];
        $this->assertSame([ $t1, $quiz ], array_column($items, 'id'));
        $this->assertSame([ 'First question', 'Second question' ], array_column($items[1]['questions'], 'title'));
        $this->assertSame('single', $items[1]['questions'][1]['type']);
        $this->assertEquals(2.0, $items[1]['questions'][1]['points']);
        $this->assertSame([ $tq ], array_column($items[0]['quizzes'], 'id'), 'a topic quiz hangs under its topic');
        $this->assertSame([ $t2 ], array_column($out['sections'][1]['items'], 'id'));
    }

    public function test_get_course_refuses_an_id_that_is_not_a_learndash_course(): void
    {
        $page = $this->post('page', 'Not a course');
        $out  = $this->read('learndash-get-course', [ 'id' => $page ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
    }

    public function test_list_courses_reports_structure_and_enrollment_counts(): void
    {
        [$course] = $this->seed_course();
        $other    = $this->post('sfwd-courses', 'Watercolor', [], 0, 'draft');
        $this->enroll(self::factory()->user->create(), $course);
        $this->enroll(self::factory()->user->create(), $course);

        $out  = $this->ok($this->read('learndash-list-courses'))['result'];
        $rows = array_column($out['courses'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame([ 'sections' => 2, 'lessons' => 2 ], array_intersect_key($rows[ $course ], [ 'sections' => 0, 'lessons' => 0 ]));
        $this->assertSame(2, $rows[ $course ]['enrolled']);
        $this->assertSame('draft', $rows[ $other ]['status']);
    }

    public function test_list_enrollments_returns_only_the_listed_user_fields_newest_first(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create([ 'display_name' => 'Alice Student', 'user_email' => 'alice@example.com' ]);
        $bob      = self::factory()->user->create([ 'display_name' => 'Bob Student', 'user_email' => 'bob@example.com' ]);
        $this->enroll($alice, $course, 1700000000);
        $this->enroll($bob, $course, 1700000500);

        $out = $this->ok($this->read('learndash-list-enrollments', [ 'course_id' => $course ]))['result'];
        $this->assertSame([ $bob, $alice ], array_column($out['enrollments'], 'user_id'));
        $this->assertSame([ 'user_id', 'display_name', 'email', 'status', 'enrolled_at' ], array_keys($out['enrollments'][0]));
        $this->assertSame('enrolled', $out['enrollments'][0]['status']);
        $this->assertSame(2, $out['total']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $denied = $this->read('learndash-list-enrollments', [ 'course_id' => $course ]);
        $this->assertArrayHasKey('error', $denied);
    }

    public function test_list_user_courses_returns_enrollments_with_progress(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create();
        $this->enroll($alice, $course);
        update_user_meta($alice, '_sfwd-course_progress', [ $course => [ 'completed' => 1, 'total' => 4, 'lessons' => [], 'topics' => [] ] ]);

        $out = $this->ok($this->read('learndash-list-user-courses', [ 'user_id' => $alice ]))['result'];
        $this->assertSame($alice, $out['user_id']);
        $this->assertCount(1, $out['courses']);
        $row = $out['courses'][0];
        $this->assertSame($course, $row['id']);
        $this->assertSame('Photography 101', $row['title']);
        $this->assertSame(1, $row['progress']['completed']);
        $this->assertSame(4, $row['progress']['total']);
        $this->assertSame(25, $row['progress']['percentage']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_building_a_course_stores_it_the_way_learndash_does_and_rollback_undoes_it(): void
    {
        $session   = wp_generate_uuid4();
        $course    = $this->ok($this->write('learndash-create-course', [ 'title' => 'Pottery' ], $session));
        $course_id = $course['result']['item']['id'];
        $this->assertTrue($course['recoverable']);
        $this->assertSame('sfwd-courses', get_post_type($course_id));

        $lesson = $this->ok($this->write('learndash-add-section', [ 'course_id' => $course_id, 'title' => 'Wheel' ], $session))['result']['item']['id'];
        $this->assertSame('sfwd-lessons', get_post_type($lesson));
        $this->assertSame($course_id, (int) get_post_meta($lesson, 'course_id', true));
        $this->assertSame($course_id, (int) get_post_meta($lesson, "ld_course_{$course_id}", true));
        $this->assertSame(1, (int) get_post_field('menu_order', $lesson));

        $topic = $this->ok($this->write('learndash-add-lesson', [ 'parent_id' => $lesson, 'title' => 'Centering' ], $session))['result']['item']['id'];
        $quiz  = $this->ok($this->write('learndash-add-quiz', [ 'parent_id' => $lesson, 'title' => 'Wheel quiz' ], $session))['result']['item']['id'];
        $this->assertSame('sfwd-topic', get_post_type($topic));
        $this->assertSame('sfwd-quiz', get_post_type($quiz));
        $this->assertSame($lesson, (int) get_post_meta($quiz, 'lesson_id', true));
        $this->assertSame($course_id, (int) get_post_meta($topic, 'course_id', true));

        $tree = $this->ok($this->read('learndash-get-course', [ 'id' => $course_id ]))['result'];
        $this->assertSame([ $topic, $quiz ], array_column($tree['sections'][0]['items'], 'id'));

        Rollback_Service::restore_session($session);
        foreach ([ $course_id, $lesson, $topic, $quiz ] as $id) {
            $this->assertSame('trash', get_post_status($id), "post {$id}");
        }
    }

    public function test_enroll_and_unenroll_write_the_access_meta_under_a_user_snapshot(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create();

        $session = wp_generate_uuid4();
        $out     = $this->ok($this->write('learndash-enroll-user', [ 'user_id' => $alice, 'course_id' => $course ], $session));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['enrolled']);
        $this->assertNotEmpty(get_user_meta($alice, "course_{$course}_access_from", true));

        $again = $this->ok($this->write('learndash-enroll-user', [ 'user_id' => $alice, 'course_id' => $course ]));
        $this->assertFalse($again['result']['changed']);

        Rollback_Service::restore_session($session);
        $this->assertSame('', get_user_meta($alice, "course_{$course}_access_from", true), 'rollback removes the enrollment');

        $this->enroll($alice, $course);
        $session = wp_generate_uuid4();
        $this->ok($this->write('learndash-unenroll-user', [ 'user_id' => $alice, 'course_id' => $course ], $session));
        $this->assertSame('', get_user_meta($alice, "course_{$course}_access_from", true));
        Rollback_Service::restore_session($session);
        $this->assertSame('1700000000', (string) get_user_meta($alice, "course_{$course}_access_from", true), 'rollback restores the enrollment');
    }

    public function test_reset_progress_clears_one_course_and_rollback_restores_it(): void
    {
        [$course] = $this->seed_course();
        $other    = $this->post('sfwd-courses', 'Watercolor');
        $alice    = self::factory()->user->create();
        $this->enroll($alice, $course);
        $progress = [ $course => [ 'completed' => 2, 'total' => 4 ], $other => [ 'completed' => 1, 'total' => 1 ] ];
        update_user_meta($alice, '_sfwd-course_progress', $progress);
        update_user_meta($alice, "course_completed_{$course}", 1700000000);

        $session = wp_generate_uuid4();
        $out     = $this->ok($this->write('learndash-reset-progress', [ 'user_id' => $alice, 'course_id' => $course ], $session));
        $this->assertTrue($out['result']['reset']);
        $this->assertSame([ $other => [ 'completed' => 1, 'total' => 1 ] ], get_user_meta($alice, '_sfwd-course_progress', true));
        $this->assertSame('', get_user_meta($alice, "course_completed_{$course}", true));

        Rollback_Service::restore_session($session);
        $this->assertSame($progress, get_user_meta($alice, '_sfwd-course_progress', true));
    }

    public function test_enroll_refuses_an_unknown_user_and_a_non_course(): void
    {
        [$course] = $this->seed_course();
        $out      = $this->write('learndash-enroll-user', [ 'user_id' => 999999, 'course_id' => $course ]);
        $this->assertSame('user_not_found', $out['error']['code'] ?? null);

        $page = $this->post('page', 'Not a course');
        $out  = $this->write('learndash-enroll-user', [ 'user_id' => $this->admin, 'course_id' => $page ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
    }
}
