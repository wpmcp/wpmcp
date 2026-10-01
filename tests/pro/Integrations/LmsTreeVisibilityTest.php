<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;

require_once __DIR__ . '/../../support/lms-stubs.php';

/**
 * Issue #465: the LMS course reads (list-courses and the get-course tree:
 * sections, their items and LifterLMS quiz questions) keep the rows to what
 * core's read_post allows the caller, as list-posts does. Another user's
 * draft or private course, section, lesson or question is left out below
 * the LMS's own edit_others and read_private capabilities, and list-courses
 * counts only the visible courses.
 *
 * Checked as Contributor, Author, Editor and Administrator. Editor and
 * Administrator are given the LMS's own capabilities the way a site grants
 * them to the people who manage courses.
 */
class LmsTreeVisibilityTest extends \WP_UnitTestCase
{
    /** The other plugin-data plugins, off so only the LMS decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active' ];

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
        Gate::set_pro_for_tests(true);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_tutor_active');
        remove_all_filters('wpmcp_lifterlms_active');
        wpmcp_test_unregister_tutor_types();
        wpmcp_test_unregister_lifterlms_types();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: bool}> role => [role, may see others' drafts and private rows] */
    public static function roles(): array
    {
        return [
            'contributor'   => [ 'contributor', false ],
            'author'        => [ 'author', false ],
            'editor'        => [ 'editor', true ],
            'administrator' => [ 'administrator', true ],
        ];
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    /** @param string[] $caps */
    private function caller(string $role, bool $elevated, array $caps): int
    {
        $id = self::factory()->user->create([ 'role' => $role ]);
        if ($elevated) {
            $user = get_userdata($id);
            foreach ($caps as $cap) {
                $user->add_cap($cap);
            }
        }
        return $id;
    }

    private function post(string $type, int $author, string $status, string $title, int $parent = 0, int $order = 0, array $meta = []): int
    {
        $id = self::factory()->post->create([
            'post_type'   => $type,
            'post_author' => $author,
            'post_status' => $status,
            'post_title'  => $title,
            'post_parent' => $parent,
            'menu_order'  => $order,
        ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    /**
     * @dataProvider roles
     */
    public function test_tutor_courses_and_course_tree_show_only_rows_the_caller_may_read(string $role, bool $elevated): void
    {
        wpmcp_test_register_tutor_types();
        add_filter('wpmcp_tutor_active', '__return_true');
        add_filter('wpmcp_lifterlms_active', '__return_false');

        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = $this->caller($role, $elevated, wpmcp_test_tutor_admin_caps());

        $course  = $this->post('courses', $owner, 'publish', 'Photography 101');
        $draft   = $this->post('courses', $owner, 'draft', 'Unannounced course');
        $private = $this->post('courses', $owner, 'private', 'Staff only course');
        $mine    = $this->post('courses', $caller, 'draft', 'My course draft');

        $t1       = $this->post('topics', $owner, 'publish', 'Basics', $course, 1);
        $t2       = $this->post('topics', $owner, 'draft', 'Unfinished topic', $course, 2);
        $t3       = $this->post('topics', $caller, 'draft', 'My topic', $course, 3);
        $l1       = $this->post('lesson', $owner, 'publish', 'Holding the camera', $t1, 1);
        $l2       = $this->post('lesson', $owner, 'draft', 'Draft lesson', $t1, 2);
        $l3       = $this->post('lesson', $owner, 'private', 'Private lesson', $t1, 3);
        $quiz     = $this->post('tutor_quiz', $owner, 'draft', 'Draft quiz', $t1, 4);

        wp_set_current_user($caller);

        $list     = $this->read('tutor-list-courses');
        $listed   = array_column($list['courses'], 'id');
        $expected = $elevated ? [ $course, $draft, $private, $mine ] : [ $course, $mine ];
        sort($listed);
        sort($expected);
        $this->assertSame($expected, $listed, "list-courses as {$role}");
        $this->assertSame(count($expected), $list['total'], "list-courses total as {$role}");

        $paged = $this->read('tutor-list-courses', [ 'status' => 'draft', 'per_page' => 1 ]);
        $this->assertSame($elevated ? 2 : 1, $paged['total'], "draft course total as {$role}");
        $this->assertCount(1, $paged['courses']);

        $tree = $this->read('tutor-get-course', [ 'id' => $course ]);
        $this->assertSame($elevated ? [ $t1, $t2, $t3 ] : [ $t1, $t3 ], array_column($tree['sections'], 'id'), "topics as {$role}");
        $this->assertSame($elevated ? [ $l1, $l2, $l3, $quiz ] : [ $l1 ], array_column($tree['sections'][0]['items'], 'id'), "topic items as {$role}");
        $this->assertSame($elevated ? 3 : 2, $tree['counts']['sections']);
        $this->assertSame($elevated ? 3 : 1, $tree['counts']['lessons']);
        $this->assertSame($elevated ? 1 : 0, $tree['counts']['quizzes']);

        // The list's per-course counts come from the same filtered tree.
        $row = array_column($list['courses'], null, 'id')[ $course ];
        $this->assertSame($elevated ? 3 : 2, $row['sections'], "list-courses section count as {$role}");
        $this->assertSame($elevated ? 3 : 1, $row['lessons'], "list-courses lesson count as {$role}");
    }

    /**
     * @dataProvider roles
     */
    public function test_lifterlms_course_tree_and_questions_show_only_rows_the_caller_may_read(string $role, bool $elevated): void
    {
        wpmcp_test_register_lifterlms_types();
        add_filter('wpmcp_lifterlms_active', '__return_true');
        add_filter('wpmcp_tutor_active', '__return_false');

        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = $this->caller($role, $elevated, wpmcp_test_llms_admin_caps());

        $course = $this->post('course', $owner, 'publish', 'Bread Baking');
        $draft  = $this->post('course', $owner, 'draft', 'Unannounced course');
        $s1     = $this->post('section', $owner, 'publish', 'Starters', 0, 0, [ '_llms_parent_course' => $course, '_llms_order' => 1 ]);
        $s2     = $this->post('section', $owner, 'draft', 'Unfinished section', 0, 0, [ '_llms_parent_course' => $course, '_llms_order' => 2 ]);
        $l1     = $this->post('lesson', $owner, 'publish', 'Capturing yeast', 0, 0, [ '_llms_parent_course' => $course, '_llms_parent_section' => $s1, '_llms_order' => 1 ]);
        $l2     = $this->post('lesson', $owner, 'private', 'Private lesson', 0, 0, [ '_llms_parent_course' => $course, '_llms_parent_section' => $s1, '_llms_order' => 2 ]);
        $quiz   = $this->post('llms_quiz', $owner, 'publish', 'Starter check', 0, 0, [ '_llms_lesson_id' => $l1 ]);
        update_post_meta($l1, '_llms_quiz', $quiz);
        update_post_meta($l1, '_llms_quiz_enabled', 'yes');
        $q1 = $this->post('llms_question', $owner, 'publish', 'What is a starter?', 0, 1, [ '_llms_parent_id' => $quiz ]);
        $q2 = $this->post('llms_question', $owner, 'draft', 'Draft question', 0, 2, [ '_llms_parent_id' => $quiz ]);

        wp_set_current_user($caller);

        $list = $this->read('lifterlms-list-courses');
        $this->assertSame($elevated ? 2 : 1, $list['total'], "list-courses total as {$role}");
        $this->assertSame($elevated, in_array($draft, array_column($list['courses'], 'id'), true));

        $tree = $this->read('lifterlms-get-course', [ 'id' => $course ]);
        $this->assertSame($elevated ? [ $s1, $s2 ] : [ $s1 ], array_column($tree['sections'], 'id'), "sections as {$role}");
        $this->assertSame($elevated ? [ $l1, $l2 ] : [ $l1 ], array_column($tree['sections'][0]['items'], 'id'), "lessons as {$role}");
        $this->assertSame($elevated ? [ $q1, $q2 ] : [ $q1 ], array_column($tree['sections'][0]['items'][0]['quiz']['questions'], 'id'), "questions as {$role}");
        $this->assertSame($elevated ? 2 : 1, $tree['counts']['questions']);
    }
}
