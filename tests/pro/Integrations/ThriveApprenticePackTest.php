<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\LMS_Thrive_Apprentice;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/thrive-apprentice-stubs.php';

/**
 * Thrive Apprentice courses, modules, chapters and lessons (issue #826) as
 * paid-tier ops on the plugin-data pair, next to the other LMS ops.
 *
 * Thrive Apprentice is not loaded in the shared test core: presence is
 * driven through wpmcp_thrive_apprentice_active and the storage is seeded
 * the way Thrive Apprentice 10.9.1.4 keeps it
 * (tests/support/thrive-apprentice-stubs.php): a course is a tva_courses
 * term, its content tva_* posts under that term nested by post_parent and
 * ordered by order-string meta.
 *
 * Writes are off until wpmcp_enable_thrive_apprentice_write; each is
 * snapshotted (the course as a term snapshot, every post it touches as a
 * post snapshot, a created lesson recorded so its undo trashes it) and one
 * call is one session.
 */
class ThriveApprenticePackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'thrive-apprentice-list-courses'           => 'read',
        'thrive-apprentice-get-course'             => 'read',
        'thrive-apprentice-create-course'          => 'write',
        'thrive-apprentice-update-course'          => 'write',
        'thrive-apprentice-create-lesson'          => 'write',
        'thrive-apprentice-update-lesson'          => 'write',
        'thrive-apprentice-reorder-course-content' => 'write',
    ];

    private const OTHER_FILTERS = [ 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_learndash_active', 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_action_scheduler_active' ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_thrive_apprentice();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($this->admin)->add_cap('tve-edit-cpt');
        wp_set_current_user($this->admin);
        add_filter('wpmcp_thrive_apprentice_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_thrive_apprentice_active');
        remove_all_filters(LMS_Thrive_Apprentice::WRITE_FILTER);
        wpmcp_test_unregister_thrive_apprentice();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** The op's result, or the error payload. */
    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['error']) ? $out : (array) $out['result'];
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function enable_writes(): void
    {
        add_filter(LMS_Thrive_Apprentice::WRITE_FILTER, '__return_true');
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function course(string $name, string $status = 'publish'): int
    {
        $term = wp_insert_term($name, 'tva_courses');
        $id   = (int) $term['term_id'];
        update_term_meta($id, 'tva_status', $status);
        return $id;
    }

    private function node(int $course, string $type, string $title, int $parent, string $order, string $status = 'publish', int $author = 0): int
    {
        $meta = 'tva_assessment' === $type ? 'tva_lesson_order' : $type . '_order';
        $id   = self::factory()->post->create([
            'post_type'   => $type,
            'post_title'  => $title,
            'post_parent' => $parent,
            'post_status' => $status,
            'post_author' => $author > 0 ? $author : $this->admin,
            'meta_input'  => [ $meta => $order ],
        ]);
        wp_set_object_terms($id, $course, 'tva_courses');
        return $id;
    }

    /**
     * Course > module A > chapter A1 > lessons L1, L2; module B > lesson L3.
     *
     * @return array{course: int, ma: int, mb: int, ch: int, l1: int, l2: int, l3: int}
     */
    private function seed(): array
    {
        $course = $this->course('Photo Basics');
        $ma     = $this->node($course, 'tva_module', 'Module A', 0, '0');
        $mb     = $this->node($course, 'tva_module', 'Module B', 0, '1');
        $ch     = $this->node($course, 'tva_chapter', 'Chapter A1', $ma, '00');
        $l1     = $this->node($course, 'tva_lesson', 'Lesson 1', $ch, '000');
        $l2     = $this->node($course, 'tva_lesson', 'Lesson 2', $ch, '001');
        $l3     = $this->node($course, 'tva_lesson', 'Lesson 3', $mb, '10');
        return compact('course', 'ma', 'mb', 'ch', 'l1', 'l2', 'l3');
    }

    private function order(int $id): string
    {
        $type = get_post_type($id);
        $key  = in_array($type, [ 'tva_lesson', 'tva_assessment' ], true) ? 'tva_lesson_order' : $type . '_order';
        return (string) get_post_meta($id, $key, true);
    }

    public function test_the_ops_are_paid_tier_plugin_data_ops(): void
    {
        $ops = (new LMS_Thrive_Apprentice())->operations();
        $this->assertSame(array_keys(self::OPS), array_keys($ops));
        foreach (self::OPS as $name => $mode) {
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertSame('pro', $ops[ $name ]['tier'], $name);
        }
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_an_inactive_plugin_is_refused_by_name(): void
    {
        remove_all_filters('wpmcp_thrive_apprentice_active');
        add_filter('wpmcp_thrive_apprentice_active', '__return_false');
        add_filter('wpmcp_tutor_active', '__return_true', 20);

        $out = $this->read('thrive-apprentice-list-courses');
        $this->assertSame('thrive_apprentice_inactive', $out['error']['code']);
        $this->assertStringContainsString('Thrive Apprentice', $out['error']['message']);
    }

    public function test_list_courses_counts_the_structure_and_hides_drafts_from_non_managers(): void
    {
        $seed  = $this->seed();
        $draft = $this->course('Unreleased', 'draft');

        $out  = $this->ok($this->read('thrive-apprentice-list-courses'));
        $rows = array_column($out['courses'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame('Photo Basics', $rows[ $seed['course'] ]['title']);
        $this->assertSame('publish', $rows[ $seed['course'] ]['status']);
        $this->assertSame(2, $rows[ $seed['course'] ]['modules']);
        $this->assertSame(1, $rows[ $seed['course'] ]['chapters']);
        $this->assertSame(3, $rows[ $seed['course'] ]['lessons']);
        $this->assertSame('draft', $rows[ $draft ]['status']);

        $only = $this->ok($this->read('thrive-apprentice-list-courses', [ 'status' => 'draft' ]));
        $this->assertSame([ $draft ], array_column($only['courses'], 'id'));

        $author = self::factory()->user->create([ 'role' => 'author' ]);
        wp_set_current_user($author);
        $handler = (new LMS_Thrive_Apprentice())->operations()['thrive-apprentice-list-courses']['handler'];
        $this->assertSame([ $seed['course'] ], array_column($handler([])['courses'], 'id'));
    }

    public function test_get_course_returns_the_ordered_tree(): void
    {
        $seed = $this->seed();
        // Stored out of insertion order: the order meta decides.
        update_post_meta($seed['l1'], 'tva_lesson_order', '002');

        $out = $this->ok($this->read('thrive-apprentice-get-course', [ 'id' => $seed['course'] ]));
        $this->assertSame('Photo Basics', $out['course']['title']);
        $this->assertSame([ 'Module A', 'Module B' ], array_column($out['items'], 'title'));
        $this->assertSame('module', $out['items'][0]['kind']);
        $chapter = $out['items'][0]['items'][0];
        $this->assertSame('chapter', $chapter['kind']);
        $this->assertSame([ 'Lesson 2', 'Lesson 1' ], array_column($chapter['items'], 'title'));
        $this->assertSame([ 'Lesson 3' ], array_column($out['items'][1]['items'], 'title'));
        $this->assertSame([ 'modules' => 2, 'chapters' => 1, 'lessons' => 3, 'assessments' => 0 ], $out['counts']);

        $missing = $this->read('thrive-apprentice-get-course', [ 'id' => 999999 ]);
        $this->assertSame('course_not_found', $missing['error']['code']);
    }

    public function test_get_course_hides_another_users_draft_lesson(): void
    {
        $seed   = $this->seed();
        $hidden = $this->node($seed['course'], 'tva_lesson', 'Secret draft', $seed['mb'], '11', 'draft');
        $author = self::factory()->user->create([ 'role' => 'author' ]);
        wp_set_current_user($author);

        $handler = (new LMS_Thrive_Apprentice())->operations()['thrive-apprentice-get-course']['handler'];
        $tree    = $handler([ 'id' => $seed['course'] ]);
        $this->assertNotContains($hidden, array_column($tree['items'][1]['items'], 'id'));
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $out = $this->write('thrive-apprentice-create-course', [ 'title' => 'New' ]);
        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString(LMS_Thrive_Apprentice::WRITE_FILTER, $out['error']['message']);
    }

    public function test_create_course_is_a_draft_term_and_undoes(): void
    {
        $this->enable_writes();
        $out = $this->ok($this->write('thrive-apprentice-create-course', [ 'title' => 'Night Skies', 'description' => 'Stars', 'excerpt' => 'Short' ]));

        $id = (int) $out['result']['course']['id'];
        $this->assertSame('Night Skies', get_term($id, 'tva_courses')->name);
        $this->assertSame('draft', get_term_meta($id, 'tva_status', true));
        $this->assertSame('Stars', get_term_meta($id, 'tva_description', true));
        $this->assertSame('Short', get_term_meta($id, 'tva_excerpt', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull(get_term($id, 'tva_courses'));
    }

    public function test_update_course_changes_name_and_status_and_undoes(): void
    {
        $this->enable_writes();
        $course = $this->course('Old Name', 'draft');

        $out = $this->ok($this->write('thrive-apprentice-update-course', [ 'id' => $course, 'title' => 'New Name', 'status' => 'publish' ]));
        $this->assertSame('New Name', get_term($course, 'tva_courses')->name);
        $this->assertSame('publish', get_term_meta($course, 'tva_status', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        clean_term_cache([ $course ], 'tva_courses');
        $this->assertSame('Old Name', get_term($course, 'tva_courses')->name);
        $this->assertSame('draft', get_term_meta($course, 'tva_status', true));

        $none = $this->write('thrive-apprentice-update-course', [ 'id' => $course ]);
        $this->assertSame('nothing_to_update', $none['error']['code']);
    }

    public function test_create_lesson_inserts_at_a_position_publishes_its_parents_and_undoes(): void
    {
        $this->enable_writes();
        $course = $this->course('Fresh');
        $module = $this->node($course, 'tva_module', 'M', 0, '0', 'draft');
        $chap   = $this->node($course, 'tva_chapter', 'C', $module, '00', 'draft');
        $first  = $this->node($course, 'tva_lesson', 'First', $chap, '000', 'draft');

        $out = $this->ok($this->write('thrive-apprentice-create-lesson', [
            'course_id' => $course,
            'parent_id' => $chap,
            'title'     => 'Intro',
            'content'   => '<p>Hello</p>',
            'status'    => 'publish',
            'position'  => 0,
        ]));
        $id = (int) $out['result']['lesson']['id'];

        $this->assertSame('tva_lesson', get_post_type($id));
        $this->assertSame($chap, (int) get_post($id)->post_parent);
        $this->assertSame([ $course ], wp_get_object_terms($id, 'tva_courses', [ 'fields' => 'ids' ]));
        $this->assertSame('000', $this->order($id));
        $this->assertSame('001', $this->order($first));
        $this->assertSame('text', get_post_meta($id, 'tva_lesson_type', true));
        $this->assertSame('publish', get_post_status($chap));
        $this->assertSame('publish', get_post_status($module));

        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame('000', $this->order($first));
        $this->assertSame('draft', get_post_status($chap));
        $this->assertSame('draft', get_post_status($module));
    }

    public function test_create_lesson_refuses_a_parent_that_holds_chapters_or_another_course(): void
    {
        $this->enable_writes();
        $seed  = $this->seed();
        $other = $this->course('Other');

        $module = $this->write('thrive-apprentice-create-lesson', [ 'course_id' => $seed['course'], 'parent_id' => $seed['ma'], 'title' => 'X' ]);
        $this->assertSame('invalid_parent', $module['error']['code']);

        $top = $this->write('thrive-apprentice-create-lesson', [ 'course_id' => $seed['course'], 'title' => 'X' ]);
        $this->assertSame('invalid_parent', $top['error']['code']);

        $cross = $this->write('thrive-apprentice-create-lesson', [ 'course_id' => $other, 'parent_id' => $seed['ch'], 'title' => 'X' ]);
        $this->assertSame('invalid_parent', $cross['error']['code']);

        $empty = $this->ok($this->write('thrive-apprentice-create-lesson', [ 'course_id' => $other, 'title' => 'Top level' ]));
        $this->assertSame('0', $this->order((int) $empty['result']['lesson']['id']));
    }

    public function test_update_lesson_edits_fields_reviews_parent_status_and_undoes(): void
    {
        $this->enable_writes();
        $seed = $this->seed();

        $out = $this->ok($this->write('thrive-apprentice-update-lesson', [ 'id' => $seed['l3'], 'title' => 'Renamed', 'status' => 'draft' ]));
        $this->assertSame('Renamed', get_the_title($seed['l3']));
        $this->assertSame('draft', get_post_status($seed['l3']));
        $this->assertSame('draft', get_post_status($seed['mb']), 'A module with no published lesson left goes to draft, as Thrive Apprentice does.');

        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame('Lesson 3', get_the_title($seed['l3']));
        $this->assertSame('publish', get_post_status($seed['l3']));
        $this->assertSame('publish', get_post_status($seed['mb']));

        $module = $this->write('thrive-apprentice-update-lesson', [ 'id' => $seed['ma'], 'title' => 'Module renamed' ]);
        $this->assertArrayNotHasKey('error', $module);
        $status = $this->write('thrive-apprentice-update-lesson', [ 'id' => $seed['ma'], 'status' => 'draft' ]);
        $this->assertSame('status_derived', $status['error']['code']);

        $stray = self::factory()->post->create();
        $this->assertSame('item_not_found', $this->write('thrive-apprentice-update-lesson', [ 'id' => $stray, 'title' => 'x' ])['error']['code']);
    }

    public function test_reorder_rewrites_the_order_strings_of_the_level_and_below_and_undoes(): void
    {
        $this->enable_writes();
        $seed = $this->seed();

        $out = $this->ok($this->write('thrive-apprentice-reorder-course-content', [ 'course_id' => $seed['course'], 'order' => [ $seed['mb'], $seed['ma'] ] ]));
        $this->assertSame('0', $this->order($seed['mb']));
        $this->assertSame('00', $this->order($seed['l3']));
        $this->assertSame('1', $this->order($seed['ma']));
        $this->assertSame('10', $this->order($seed['ch']));
        $this->assertSame('100', $this->order($seed['l1']));
        $this->assertSame('101', $this->order($seed['l2']));

        $tree = $this->ok($this->read('thrive-apprentice-get-course', [ 'id' => $seed['course'] ]));
        $this->assertSame([ 'Module B', 'Module A' ], array_column($tree['items'], 'title'));

        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame('0', $this->order($seed['ma']));
        $this->assertSame('000', $this->order($seed['l1']));
        $this->assertSame('1', $this->order($seed['mb']));
        $this->assertSame('10', $this->order($seed['l3']));

        $inner = $this->ok($this->write('thrive-apprentice-reorder-course-content', [ 'course_id' => $seed['course'], 'parent_id' => $seed['ch'], 'order' => [ $seed['l2'], $seed['l1'] ] ]));
        $this->assertSame('000', $this->order($seed['l2']));
        $this->assertSame('001', $this->order($seed['l1']));
        $this->assertCount(2, $inner['operation_ids']);

        $short = $this->write('thrive-apprentice-reorder-course-content', [ 'course_id' => $seed['course'], 'order' => [ $seed['ma'] ] ]);
        $this->assertSame('order_mismatch', $short['error']['code']);
    }
}
