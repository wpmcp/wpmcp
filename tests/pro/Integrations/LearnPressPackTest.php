<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/learnpress-stubs.php';

/**
 * LearnPress course structure and enrollments (issue #672): paid-tier ops on
 * the plugin-data dispatcher pair, the same op names as the other LMS
 * adapters.
 *
 * LearnPress is not loaded in the shared test core. Presence is driven
 * through wpmcp_learnpress_active, and the storage is seeded the way
 * LearnPress 4.4.15 keeps it (tests/support/learnpress-stubs.php): an
 * lp_course post; its sections are rows of {prefix}learnpress_sections
 * (section_course_id, 1-based section_order), not posts; lesson and quiz
 * posts are placed in a section by rows of {prefix}learnpress_section_items
 * (section_id, item_id, item_order, item_type); quiz questions are lp_question
 * posts linked by {prefix}learnpress_quiz_questions (question_order), with
 * _lp_type and _lp_mark meta; enrollment is the latest lp_course row per
 * student in {prefix}learnpress_user_items.
 */
class LearnPressPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'learnpress-list-courses'     => 'read',
        'learnpress-get-course'       => 'read',
        'learnpress-list-enrollments' => 'read',
        'learnpress-create-course'    => 'write',
        'learnpress-add-section'      => 'write',
        'learnpress-add-lesson'       => 'write',
        'learnpress-add-quiz'         => 'write',
        'learnpress-update-item'      => 'write',
        'learnpress-move-item'        => 'write',
    ];

    /** The other plugin-data plugins, off so only this LMS decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_action_scheduler_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_learndash_active' ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_learnpress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_learnpress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_learnpress_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        foreach (wpmcp_test_learnpress_admin_caps() as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_learnpress_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_learnpress_active');
        wpmcp_test_unregister_learnpress_types();
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

    private function post(string $type, string $title, array $meta = []): int
    {
        $id = self::factory()->post->create([ 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish' ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function section(int $course, string $name, int $order): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'learnpress_sections', [ 'section_name' => $name, 'section_course_id' => $course, 'section_order' => $order, 'section_description' => '' ]);
        return (int) $wpdb->insert_id;
    }

    private function place(int $section, int $item, int $order): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'learnpress_section_items', [ 'section_id' => $section, 'item_id' => $item, 'item_order' => $order, 'item_type' => get_post_type($item) ]);
    }

    /** section id => [section_order, section_name] of a course, by id. */
    private function sections_of(int $course): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT section_id, section_order, section_name FROM {$wpdb->prefix}learnpress_sections WHERE section_course_id = %d ORDER BY section_id", $course), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['section_id'] ] = [ (int) $row['section_order'], (string) $row['section_name'] ];
        }
        return $out;
    }

    /** item id => [section_id, item_order] for the items of the given sections. */
    private function items_in(array $sections): array
    {
        global $wpdb;
        $in   = implode(',', array_map('intval', $sections));
        $rows = $wpdb->get_results("SELECT section_id, item_id, item_order FROM {$wpdb->prefix}learnpress_section_items WHERE section_id IN ({$in}) ORDER BY item_id", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['item_id'] ] = [ (int) $row['section_id'], (int) $row['item_order'] ];
        }
        return $out;
    }

    /** [course, s1, s2, l1, l2, quiz, l3, q1, q2]: s1 holds l1, l2 and the quiz; s2 holds l3. */
    private function seed_course(): array
    {
        global $wpdb;
        $course = $this->post('lp_course', 'Bread Baking');
        $s2     = $this->section($course, 'Shaping', 2);
        $s1     = $this->section($course, 'Starters', 1);
        $l2     = $this->post('lp_lesson', 'Feeding');
        $l1     = $this->post('lp_lesson', 'Capturing yeast');
        $quiz   = $this->post('lp_quiz', 'Starter check');
        $l3     = $this->post('lp_lesson', 'Boules');
        $this->place($s1, $l2, 2);
        $this->place($s1, $l1, 1);
        $this->place($s1, $quiz, 3);
        $this->place($s2, $l3, 1);
        $q2 = $this->post('lp_question', 'How often to feed?', [ '_lp_type' => 'single_choice', '_lp_mark' => 2 ]);
        $q1 = $this->post('lp_question', 'What is a starter?', [ '_lp_type' => 'true_or_false', '_lp_mark' => 1 ]);
        $wpdb->insert($wpdb->prefix . 'learnpress_quiz_questions', [ 'quiz_id' => $quiz, 'question_id' => $q2, 'question_order' => 2 ]);
        $wpdb->insert($wpdb->prefix . 'learnpress_quiz_questions', [ 'quiz_id' => $quiz, 'question_id' => $q1, 'question_order' => 1 ]);
        return [ $course, $s1, $s2, $l1, $l2, $quiz, $l3, $q1, $q2 ];
    }

    private function enrollment(int $user, int $course, string $status, string $start): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'learnpress_user_items', [ 'user_id' => $user, 'item_id' => $course, 'item_type' => 'lp_course', 'status' => $status, 'start_time' => $start, 'ref_type' => 'lp_order' ]);
    }

    public function test_the_catalog_lists_every_learnpress_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
        }
        $this->assertSame('list_users', $ops['learnpress-list-enrollments']['capability']);
    }

    public function test_while_learnpress_is_inactive_its_ops_answer_learnpress_inactive(): void
    {
        remove_all_filters('wpmcp_learnpress_active');
        add_filter('wpmcp_learnpress_active', '__return_false');
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('learnpress-list-courses');
        $this->assertSame('learnpress_inactive', $out['error']['code'] ?? null);
    }

    public function test_get_course_reads_sections_and_items_from_the_learnpress_tables_in_order(): void
    {
        [$course, $s1, $s2, $l1, $l2, $quiz, $l3, $q1, $q2] = $this->seed_course();

        $out = $this->ok($this->read('learnpress-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $s1, $s2 ], array_column($out['sections'], 'id'));
        $this->assertSame([ 'section', 'section' ], array_column($out['sections'], 'kind'));
        $this->assertSame([ 'Starters', 'Shaping' ], array_column($out['sections'], 'title'));
        $this->assertSame([ 1, 2 ], array_column($out['sections'], 'order'));
        $this->assertSame([ $l1, $l2, $quiz ], array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame([ 'lesson', 'lesson', 'quiz' ], array_column($out['sections'][0]['items'], 'kind'));
        $this->assertSame([ 1, 2, 3 ], array_column($out['sections'][0]['items'], 'order'));
        $this->assertSame([ $l3 ], array_column($out['sections'][1]['items'], 'id'));

        $questions = $out['sections'][0]['items'][2]['questions'];
        $this->assertSame([ $q1, $q2 ], array_column($questions, 'id'));
        $this->assertSame([ 'true_or_false', 'single_choice' ], array_column($questions, 'type'));
        $this->assertEquals([ 1, 2 ], array_column($questions, 'points'));
        $this->assertSame([ 'sections' => 2, 'lessons' => 3, 'quizzes' => 1, 'assignments' => 0, 'questions' => 2 ], $out['counts']);

        $listed = array_column($this->ok($this->read('learnpress-list-courses'))['result']['courses'], null, 'id');
        $this->assertSame([ 2, 3, 1 ], [ $listed[ $course ]['sections'], $listed[ $course ]['lessons'], $listed[ $course ]['quizzes'] ]);
    }

    public function test_a_draft_lesson_of_another_author_is_not_listed_to_a_contributor(): void
    {
        [$course, $s1, , $l1] = $this->seed_course();
        wp_update_post([ 'ID' => $l1, 'post_status' => 'draft' ]);
        wp_update_post([ 'ID' => $course, 'post_author' => $this->admin ]);
        $reader = self::factory()->user->create([ 'role' => 'subscriber' ]);
        wp_set_current_user($reader);
        get_userdata($reader)->add_cap('read');

        $out = (new \WPMCP\Integrations\LMS_LearnPress())->operations()['learnpress-get-course']['handler']([ 'id' => $course, 'questions' => false ]);
        $this->assertNotContains($l1, array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame($s1, $out['sections'][0]['id']);
    }

    public function test_list_enrollments_takes_the_latest_row_per_student_and_masks_it(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create([ 'display_name' => 'Alice', 'user_email' => 'alice@example.com' ]);
        $bob      = self::factory()->user->create([ 'display_name' => 'Bob', 'user_email' => 'bob@example.com' ]);
        $carol    = self::factory()->user->create([ 'display_name' => 'Carol', 'user_email' => 'carol@example.com' ]);
        $this->enrollment($alice, $course, 'enrolled', '2026-09-01 10:00:00');
        $this->enrollment($bob, $course, 'enrolled', '2026-09-02 10:00:00');
        $this->enrollment($bob, $course, 'cancel', '2026-09-05 10:00:00');
        $this->enrollment($carol, $course, 'finished', '2026-09-03 10:00:00');

        $out  = $this->ok($this->read('learnpress-list-enrollments', [ 'course_id' => $course ]))['result'];
        $rows = array_column($out['enrollments'], null, 'user_id');
        $this->assertSame(3, $out['total']);
        $this->assertSame('enrolled', $rows[ $alice ]['status']);
        $this->assertSame('cancelled', $rows[ $bob ]['status']);
        $this->assertSame('enrolled', $rows[ $carol ]['status'], 'a finished student is still enrolled');
        $this->assertSame('2026-09-01 10:00:00', $rows[ $alice ]['enrolled_at']);
        $this->assertSame('b***@example.com', $rows[ $bob ]['email'], 'a list masks the email');
        $this->assertTrue($out['masked']);

        $only = $this->ok($this->read('learnpress-list-enrollments', [ 'course_id' => $course, 'status' => 'enrolled' ]))['result'];
        $this->assertEqualsCanonicalizing([ $alice, $carol ], array_column($only['enrollments'], 'user_id'));

        $listed = array_column($this->ok($this->read('learnpress-list-courses'))['result']['courses'], null, 'id');
        $this->assertSame(2, $listed[ $course ]['enrolled']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('learnpress-list-enrollments', [ 'course_id' => $course ])['error']['code'] ?? null);
    }

    public function test_building_a_course_writes_learnpress_rows_and_rollback_session_undoes_all_of_it(): void
    {
        global $wpdb;
        $session = wp_generate_uuid4();
        $course  = $this->ok($this->write('learnpress-create-course', [ 'title' => 'Knife Skills' ], $session))['result']['item']['id'];
        $section = $this->ok($this->write('learnpress-add-section', [ 'course_id' => $course, 'title' => 'Grips', 'content' => 'Hold it right' ], $session))['result']['item'];
        $lesson  = $this->ok($this->write('learnpress-add-lesson', [ 'parent_id' => $section['id'], 'title' => 'Pinch grip' ], $session))['result']['item']['id'];
        $quiz    = $this->ok($this->write('learnpress-add-quiz', [ 'parent_id' => $section['id'], 'title' => 'Grip quiz', 'position' => 0 ], $session))['result']['item']['id'];

        $this->assertSame('lp_course', get_post_type($course));
        $this->assertSame('section', $section['kind']);
        $this->assertSame([ $section['id'] => [ 1, 'Grips' ] ], $this->sections_of($course));
        $this->assertSame('Hold it right', $wpdb->get_var($wpdb->prepare("SELECT section_description FROM {$wpdb->prefix}learnpress_sections WHERE section_id = %d", $section['id'])));
        $this->assertSame('lp_lesson', get_post_type($lesson));
        $this->assertSame('lp_quiz', get_post_type($quiz));
        $this->assertEquals([ $lesson => [ $section['id'], 2 ], $quiz => [ $section['id'], 1 ] ], $this->items_in([ $section['id'] ]));
        $this->assertSame('lp_quiz', $wpdb->get_var($wpdb->prepare("SELECT item_type FROM {$wpdb->prefix}learnpress_section_items WHERE item_id = %d", $quiz)));

        $tree = $this->ok($this->read('learnpress-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $quiz, $lesson ], array_column($tree['sections'][0]['items'], 'id'));

        Rollback_Service::restore_session($session);
        foreach ([ $course, $lesson, $quiz ] as $id) {
            $this->assertSame('trash', get_post_status($id), "post {$id}");
        }
        $this->assertSame([], $this->sections_of($course), 'the section row is gone');
        $this->assertSame([], $this->items_in([ $section['id'] ]), 'the item rows are gone');
    }

    public function test_add_section_at_a_position_renumbers_the_others_and_its_session_undoes_that(): void
    {
        [$course, $s1, $s2] = $this->seed_course();
        $out                = $this->ok($this->write('learnpress-add-section', [ 'course_id' => $course, 'title' => 'Tools', 'position' => 0 ]));
        $new                = $out['result']['item']['id'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $s2 => [ 3, 'Shaping' ], $s1 => [ 2, 'Starters' ], $new => [ 1, 'Tools' ] ], $this->sections_of($course));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([ $s2 => [ 2, 'Shaping' ], $s1 => [ 1, 'Starters' ] ], $this->sections_of($course));
    }

    public function test_add_lesson_refuses_a_parent_that_is_not_a_learnpress_section(): void
    {
        [$course] = $this->seed_course();
        $out      = $this->write('learnpress-add-lesson', [ 'parent_id' => 999999, 'title' => 'Orphan' ]);
        $this->assertSame('invalid_parent', $out['error']['code'] ?? null);
        $out = $this->write('learnpress-add-section', [ 'course_id' => $this->post('post', 'Not a course'), 'title' => 'X' ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
        $this->assertCount(2, $this->sections_of($course));
    }

    public function test_an_author_cannot_add_to_a_course_they_cannot_edit(): void
    {
        [$course, $s1] = $this->seed_course();
        $author        = self::factory()->user->create([ 'role' => 'author' ]);
        foreach ([ 'edit_lp_lessons', 'publish_lp_lessons', 'edit_lp_courses' ] as $cap) {
            get_userdata($author)->add_cap($cap);
        }
        wp_set_current_user($author);
        $this->assertSame('operation_denied', $this->write('learnpress-add-lesson', [ 'parent_id' => $s1, 'title' => 'Sneaky' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('learnpress-update-item', [ 'section_id' => $s1, 'title' => 'Mine' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('learnpress-move-item', [ 'section_id' => $s1, 'position' => 1 ])['error']['code'] ?? null);
        $this->assertSame('Starters', $this->sections_of($course)[ $s1 ][1]);
    }

    public function test_move_item_moves_a_lesson_between_sections_and_rollback_puts_both_back(): void
    {
        [$course, $s1, $s2, $l1, $l2, $quiz, $l3] = $this->seed_course();

        $out = $this->ok($this->write('learnpress-move-item', [ 'id' => $l2, 'parent_id' => $s2, 'position' => 0 ]));
        $this->assertEquals([ $l1 => [ $s1, 1 ], $l2 => [ $s2, 1 ], $quiz => [ $s1, 2 ], $l3 => [ $s2, 2 ] ], $this->items_in([ $s1, $s2 ]));

        $tree = $this->ok($this->read('learnpress-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $l1, $quiz ], array_column($tree['sections'][0]['items'], 'id'));
        $this->assertSame([ $l2, $l3 ], array_column($tree['sections'][1]['items'], 'id'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertEquals([ $l1 => [ $s1, 1 ], $l2 => [ $s1, 2 ], $quiz => [ $s1, 3 ], $l3 => [ $s2, 1 ] ], $this->items_in([ $s1, $s2 ]));
    }

    public function test_move_item_refuses_a_section_of_another_course(): void
    {
        [, , , , $l2] = $this->seed_course();
        $other        = $this->post('lp_course', 'Other');
        $foreign      = $this->section($other, 'Elsewhere', 1);
        $out          = $this->write('learnpress-move-item', [ 'id' => $l2, 'parent_id' => $foreign, 'position' => 0 ]);
        $this->assertSame('cross_course_move', $out['error']['code'] ?? null);
    }

    public function test_move_item_reorders_sections_and_rollback_restores_the_order(): void
    {
        [$course, $s1, $s2] = $this->seed_course();
        $out                = $this->ok($this->write('learnpress-move-item', [ 'section_id' => $s2, 'position' => 0 ]));
        $this->assertSame([ $s2 => [ 1, 'Shaping' ], $s1 => [ 2, 'Starters' ] ], $this->sections_of($course));
        $this->assertSame([ $s2, $s1 ], $out['result']['order']);

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([ $s2 => [ 2, 'Shaping' ], $s1 => [ 1, 'Starters' ] ], $this->sections_of($course));
    }

    public function test_update_item_renames_a_section_and_rollback_operation_restores_it(): void
    {
        [$course, $s1, $s2] = $this->seed_course();
        $out                = $this->ok($this->write('learnpress-update-item', [ 'section_id' => $s1, 'title' => 'Wild yeast', 'content' => 'Catch it' ]));
        $this->assertSame('Wild yeast', $this->sections_of($course)[ $s1 ][1]);
        $this->assertSame('Wild yeast', $out['result']['item']['title']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ $s2 => [ 2, 'Shaping' ], $s1 => [ 1, 'Starters' ] ], $this->sections_of($course));

        $this->assertSame('invalid_argument', $this->write('learnpress-update-item', [ 'section_id' => $s1, 'status' => 'draft' ])['error']['code'] ?? null);
        $this->assertSame('invalid_argument', $this->write('learnpress-update-item', [ 'section_id' => $s1, 'id' => $course, 'title' => 'Both' ])['error']['code'] ?? null);
        $this->assertSame('item_not_found', $this->write('learnpress-update-item', [ 'section_id' => 999999, 'title' => 'Ghost' ])['error']['code'] ?? null);
    }

    public function test_update_item_edits_a_lesson_under_a_post_snapshot(): void
    {
        [, , , $l1] = $this->seed_course();
        $out        = $this->ok($this->write('learnpress-update-item', [ 'id' => $l1, 'title' => 'Catching yeast' ]));
        $this->assertSame('Catching yeast', get_the_title($l1));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Capturing yeast', get_the_title($l1));
    }

    public function test_a_lesson_in_no_learnpress_section_is_not_an_item(): void
    {
        $stray = $this->post('lp_lesson', 'Stray');
        $out   = $this->write('learnpress-update-item', [ 'id' => $stray, 'title' => 'Claimed' ]);
        $this->assertSame('item_not_found', $out['error']['code'] ?? null);
    }

    public function test_section_item_rows_are_keyed_by_section_and_restore_needs_edit_on_the_course(): void
    {
        global $wpdb;
        [$course, $s1, $s2, $l1, $l2, $quiz, $l3] = $this->seed_course();
        $key      = Plugin_Table_Rows_Snapshot::key('learnpress_section_items', 'section_items', [ $s1 ]);
        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, $key);
        $this->assertCount(3, $snapshot['data']['rows']);
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('learnpress_section_items', 'user_items'), 'only the section items table');

        $wpdb->delete($wpdb->prefix . 'learnpress_section_items', [ 'section_id' => $s1 ]);
        Rollback_Service::apply_snapshot($snapshot);
        $this->assertEquals([ $l1 => [ $s1, 1 ], $l2 => [ $s1, 2 ], $quiz => [ $s1, 3 ], $l3 => [ $s2, 1 ] ], $this->items_in([ $s1, $s2 ]));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Plugin_Table_Rows_Snapshot::restore($snapshot);
        unset($course);
    }
}
