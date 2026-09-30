<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/lms-stubs.php';

/**
 * LifterLMS course structure and enrollments (issue #394): paid-tier ops on
 * the plugin-data dispatcher pair.
 *
 * LifterLMS is not loaded in the shared test core. Presence is driven through
 * wpmcp_lifterlms_active, and the storage is seeded the way LifterLMS 10.2.1
 * keeps it (tests/support/lms-stubs.php): a `course` post; `section` posts
 * carrying _llms_parent_course and a 1-based _llms_order; `lesson` posts
 * carrying _llms_parent_section, _llms_parent_course and _llms_order, plus
 * _llms_quiz and _llms_quiz_enabled when a quiz is attached; an `llms_quiz`
 * post carrying _llms_lesson_id; `llms_question` posts carrying
 * _llms_parent_id, _llms_question_type and _llms_points, ordered by
 * menu_order. Enrollment lives in {prefix}lifterlms_user_postmeta: the latest
 * _status row per student and course (enrolled, expired, cancelled) is the
 * status, and the _start_date row's updated_date is the enrollment date.
 */
class LifterLmsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'lifterlms-list-courses'     => 'read',
        'lifterlms-get-course'       => 'read',
        'lifterlms-list-enrollments' => 'read',
        'lifterlms-create-course'    => 'write',
        'lifterlms-add-section'      => 'write',
        'lifterlms-add-lesson'       => 'write',
        'lifterlms-add-quiz'         => 'write',
        'lifterlms-update-item'      => 'write',
        'lifterlms-move-item'        => 'write',
    ];

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
        wpmcp_test_register_lifterlms_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        foreach (wpmcp_test_llms_admin_caps() as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_lifterlms_active', '__return_true');
        add_filter('wpmcp_tutor_active', '__return_false');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_lifterlms_active');
        remove_all_filters('wpmcp_tutor_active');
        wpmcp_test_unregister_lifterlms_types();
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

    private function post(string $type, string $title, array $meta = [], int $menu_order = 0): int
    {
        $id = self::factory()->post->create([ 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish', 'menu_order' => $menu_order ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    /** [course, s1, s2, l1, l2, l3, quiz, q1, q2]: s1 holds l1 (with the quiz) and l2, s2 holds l3. */
    private function seed_course(): array
    {
        $course = $this->post('course', 'Bread Baking');
        $s2     = $this->post('section', 'Shaping', [ '_llms_parent_course' => $course, '_llms_order' => 2 ]);
        $s1     = $this->post('section', 'Starters', [ '_llms_parent_course' => $course, '_llms_order' => 1 ]);
        $l2     = $this->post('lesson', 'Feeding', [ '_llms_parent_course' => $course, '_llms_parent_section' => $s1, '_llms_order' => 2 ]);
        $l1     = $this->post('lesson', 'Capturing yeast', [ '_llms_parent_course' => $course, '_llms_parent_section' => $s1, '_llms_order' => 1 ]);
        $l3     = $this->post('lesson', 'Boules', [ '_llms_parent_course' => $course, '_llms_parent_section' => $s2, '_llms_order' => 1 ]);
        $quiz   = $this->post('llms_quiz', 'Starter check', [ '_llms_lesson_id' => $l1 ]);
        update_post_meta($l1, '_llms_quiz', $quiz);
        update_post_meta($l1, '_llms_quiz_enabled', 'yes');
        $q2 = $this->post('llms_question', 'How often to feed?', [ '_llms_parent_id' => $quiz, '_llms_question_type' => 'choice', '_llms_points' => 2 ], 2);
        $q1 = $this->post('llms_question', 'What is a starter?', [ '_llms_parent_id' => $quiz, '_llms_question_type' => 'choice', '_llms_points' => 1 ], 1);
        return [ $course, $s1, $s2, $l1, $l2, $l3, $quiz, $q1, $q2 ];
    }

    private function status_row(int $user, int $course, string $key, ?string $value, string $date): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'lifterlms_user_postmeta', [
            'user_id'      => $user,
            'post_id'      => $course,
            'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- fixture row.
            'meta_value'   => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- fixture row.
            'updated_date' => $date,
        ]);
    }

    public function test_the_catalog_lists_every_lifterlms_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
        }
        $this->assertSame('list_users', $ops['lifterlms-list-enrollments']['capability']);
    }

    public function test_get_course_returns_sections_lessons_and_each_lessons_quiz_in_lifterlms_order(): void
    {
        [$course, $s1, $s2, $l1, $l2, $l3, $quiz, $q1, $q2] = $this->seed_course();

        $out = $this->ok($this->read('lifterlms-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $s1, $s2 ], array_column($out['sections'], 'id'));
        $this->assertSame([ 'section', 'section' ], array_column($out['sections'], 'kind'));
        $this->assertSame([ $l1, $l2 ], array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame([ $l3 ], array_column($out['sections'][1]['items'], 'id'));

        $attached = $out['sections'][0]['items'][0]['quiz'];
        $this->assertSame($quiz, $attached['id']);
        $this->assertTrue($attached['enabled']);
        $this->assertSame([ $q1, $q2 ], array_column($attached['questions'], 'id'));
        $this->assertSame([ 'choice', 'choice' ], array_column($attached['questions'], 'type'));
        $this->assertEquals([ 1, 2 ], array_column($attached['questions'], 'points'));
        $this->assertArrayNotHasKey('quiz', $out['sections'][0]['items'][1]);
        $this->assertSame([ 'sections' => 2, 'lessons' => 3, 'quizzes' => 1, 'assignments' => 0, 'questions' => 2 ], $out['counts']);
    }

    public function test_list_enrollments_takes_the_latest_status_row_per_student(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create([ 'display_name' => 'Alice', 'user_email' => 'alice@example.com' ]);
        $bob      = self::factory()->user->create([ 'display_name' => 'Bob', 'user_email' => 'bob@example.com' ]);
        $this->status_row($alice, $course, '_start_date', 'yes', '2026-09-01 10:00:00');
        $this->status_row($alice, $course, '_status', 'enrolled', '2026-09-01 10:00:00');
        $this->status_row($bob, $course, '_start_date', 'yes', '2026-09-02 10:00:00');
        $this->status_row($bob, $course, '_status', 'enrolled', '2026-09-02 10:00:00');
        $this->status_row($bob, $course, '_status', 'cancelled', '2026-09-05 10:00:00');

        $out  = $this->ok($this->read('lifterlms-list-enrollments', [ 'course_id' => $course ]))['result'];
        $rows = array_column($out['enrollments'], null, 'user_id');
        $this->assertSame(2, $out['total']);
        $this->assertSame('enrolled', $rows[ $alice ]['status']);
        $this->assertSame('cancelled', $rows[ $bob ]['status']);
        $this->assertSame('2026-09-01 10:00:00', $rows[ $alice ]['enrolled_at']);
        $this->assertSame('bob@example.com', $rows[ $bob ]['email']);

        $only = $this->ok($this->read('lifterlms-list-enrollments', [ 'course_id' => $course, 'status' => 'enrolled' ]))['result'];
        $this->assertSame([ $alice ], array_column($only['enrollments'], 'user_id'));

        $listed = array_column($this->ok($this->read('lifterlms-list-courses'))['result']['courses'], null, 'id');
        $this->assertSame(1, $listed[ $course ]['enrolled']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('lifterlms-list-enrollments', [ 'course_id' => $course ])['error']['code'] ?? null);
    }

    public function test_building_a_course_writes_lifterlms_meta_and_rollback_session_undoes_all_of_it(): void
    {
        $session   = wp_generate_uuid4();
        $course_id = $this->ok($this->write('lifterlms-create-course', [ 'title' => 'Knife Skills' ], $session))['result']['item']['id'];
        $section   = $this->ok($this->write('lifterlms-add-section', [ 'course_id' => $course_id, 'title' => 'Grips' ], $session))['result']['item']['id'];
        $lesson    = $this->ok($this->write('lifterlms-add-lesson', [ 'parent_id' => $section, 'title' => 'Pinch grip' ], $session))['result']['item']['id'];
        $quiz      = $this->ok($this->write('lifterlms-add-quiz', [ 'parent_id' => $lesson, 'title' => 'Grip quiz' ], $session))['result']['item']['id'];

        $this->assertSame('course', get_post_type($course_id));
        $this->assertSame($course_id, (int) get_post_meta($section, '_llms_parent_course', true));
        $this->assertSame(1, (int) get_post_meta($section, '_llms_order', true));
        $this->assertSame($section, (int) get_post_meta($lesson, '_llms_parent_section', true));
        $this->assertSame($course_id, (int) get_post_meta($lesson, '_llms_parent_course', true));
        $this->assertSame(1, (int) get_post_meta($lesson, '_llms_order', true));
        $this->assertSame('llms_quiz', get_post_type($quiz));
        $this->assertSame($lesson, (int) get_post_meta($quiz, '_llms_lesson_id', true));
        $this->assertSame($quiz, (int) get_post_meta($lesson, '_llms_quiz', true));
        $this->assertSame('yes', get_post_meta($lesson, '_llms_quiz_enabled', true));

        Rollback_Service::restore_session($session);
        foreach ([ $course_id, $section, $lesson, $quiz ] as $id) {
            $this->assertSame('trash', get_post_status($id), "post {$id}");
        }
        $this->assertFalse(metadata_exists('post', $lesson, '_llms_quiz'));
    }

    public function test_add_quiz_to_an_existing_lesson_is_undone_by_its_session_including_the_lesson_meta(): void
    {
        [, , , , $l2] = $this->seed_course();
        $out          = $this->ok($this->write('lifterlms-add-quiz', [ 'parent_id' => $l2, 'title' => 'Feeding quiz' ]));
        $this->assertTrue($out['recoverable']);
        $quiz = $out['result']['item']['id'];
        $this->assertSame($quiz, (int) get_post_meta($l2, '_llms_quiz', true));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($quiz));
        $this->assertFalse(metadata_exists('post', $l2, '_llms_quiz'));
        $this->assertFalse(metadata_exists('post', $l2, '_llms_quiz_enabled'));
    }

    public function test_add_quiz_refuses_a_lesson_that_already_has_one(): void
    {
        [, , , $l1, , , $quiz] = $this->seed_course();
        $out                   = $this->write('lifterlms-add-quiz', [ 'parent_id' => $l1, 'title' => 'Second quiz' ]);
        $this->assertSame('quiz_exists', $out['error']['code'] ?? null);
        $this->assertSame($quiz, (int) get_post_meta($l1, '_llms_quiz', true));
    }

    public function test_move_item_moves_a_lesson_between_sections_and_renumbers_both(): void
    {
        [$course, $s1, $s2, $l1, $l2, $l3] = $this->seed_course();

        $out = $this->ok($this->write('lifterlms-move-item', [ 'id' => $l2, 'parent_id' => $s2, 'position' => 0 ]));
        $this->assertSame($s2, (int) get_post_meta($l2, '_llms_parent_section', true));
        $this->assertSame($course, (int) get_post_meta($l2, '_llms_parent_course', true));
        $this->assertSame([ 1, 2 ], [ (int) get_post_meta($l2, '_llms_order', true), (int) get_post_meta($l3, '_llms_order', true) ]);

        $tree = $this->ok($this->read('lifterlms-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $l1 ], array_column($tree['sections'][0]['items'], 'id'));
        $this->assertSame([ $l2, $l3 ], array_column($tree['sections'][1]['items'], 'id'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($s1, (int) get_post_meta($l2, '_llms_parent_section', true));
        $this->assertSame(2, (int) get_post_meta($l2, '_llms_order', true));
        $this->assertSame(1, (int) get_post_meta($l3, '_llms_order', true));
    }

    public function test_a_quiz_is_attached_to_its_lesson_and_cannot_be_moved(): void
    {
        [, , , , , , $quiz] = $this->seed_course();
        $out                = $this->write('lifterlms-move-item', [ 'id' => $quiz, 'position' => 0 ]);
        $this->assertSame('not_movable', $out['error']['code'] ?? null);
    }

    public function test_update_item_renames_a_section_and_rollback_operation_restores_it(): void
    {
        [, $s1] = $this->seed_course();
        $out    = $this->ok($this->write('lifterlms-update-item', [ 'id' => $s1, 'title' => 'Wild yeast' ]));
        $this->assertSame('Wild yeast', get_the_title($s1));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Starters', get_the_title($s1));
    }

    public function test_a_tutor_lesson_is_not_a_lifterlms_item(): void
    {
        // A lesson post with no LifterLMS parent course is not part of any
        // LifterLMS course, whatever its post type says.
        $stray = $this->post('lesson', 'Stray');
        $out   = $this->write('lifterlms-update-item', [ 'id' => $stray, 'title' => 'Claimed' ]);
        $this->assertSame('item_not_found', $out['error']['code'] ?? null);
    }
}
