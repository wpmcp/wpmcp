<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Sensei LMS course structure and enrollments (issue #673): paid-tier ops on
 * the plugin-data dispatcher pair, so they add no top-level tools.
 *
 * Sensei LMS is not loaded in the shared test core. Presence is driven
 * through wpmcp_sensei_active, and the storage is seeded the way Sensei LMS
 * 4.27 keeps it (checked against its source):
 *  - a course is a `course` post; a lesson is a `lesson` post whose
 *    _lesson_course meta is the course;
 *  - modules are `module` terms on the course, ordered by the course's
 *    _module_order meta; a lesson in a module carries the term and its
 *    place in _order_module_{term};
 *  - a lesson outside every module is ordered by its _order_{course} meta,
 *    and the course keeps that list in _lesson_order (comma separated);
 *  - a quiz is a `quiz` post whose post_parent and _quiz_lesson are the
 *    lesson, which names it in _lesson_quiz;
 *  - a question is a `question` post whose _quiz_id meta is the quiz,
 *    ordered by _quiz_question_order{quiz}, its type a question-type term
 *    and its points _question_grade;
 *  - an enrollment is the student's sensei_learner term (slug user-{id})
 *    on the course.
 */
class SenseiLmsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'sensei-list-courses'     => 'read',
        'sensei-get-course'       => 'read',
        'sensei-list-enrollments' => 'read',
        'sensei-create-course'    => 'write',
        'sensei-add-lesson'       => 'write',
        'sensei-add-quiz'         => 'write',
        'sensei-update-item'      => 'write',
        'sensei-move-item'        => 'write',
    ];

    /** The other plugin-data plugins, off so only this LMS decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_action_scheduler_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_learndash_active' ];

    private const TYPES = [ 'course' => 'courses', 'lesson' => 'lessons', 'quiz' => 'quizzes', 'question' => 'questions' ];

    private const TAXONOMIES = [ 'module', 'sensei_learner', 'quiz-type', 'question-type' ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        self::register_sensei();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'display_name' => 'Ada Admin' ]);
        $user        = get_userdata($this->admin);
        foreach (self::TYPES as $plural) {
            foreach ([ 'edit_', 'edit_others_', 'publish_', 'read_private_', 'delete_', 'edit_published_', 'edit_private_', 'delete_published_', 'delete_others_', 'delete_private_' ] as $prefix) {
                $user->add_cap($prefix . $plural);
            }
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_sensei_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_sensei_active');
        foreach (self::TAXONOMIES as $taxonomy) {
            unregister_taxonomy($taxonomy);
        }
        foreach (array_keys(self::TYPES) as $type) {
            unregister_post_type($type);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** The post types and taxonomies as Sensei LMS registers them (Sensei_PostTypes, Sensei_Core_Modules). */
    private static function register_sensei(): void
    {
        foreach (self::TYPES as $type => $plural) {
            $args = [
                'public'          => true,
                'map_meta_cap'    => true,
                'capability_type' => [ $type, $plural ],
                'supports'        => [ 'title', 'editor', 'excerpt', 'author' ],
            ];
            if ('quiz' === $type) {
                // Sensei LMS: a published quiz is edited only through its lesson.
                $args['capabilities'] = [ 'edit_published_posts' => 'do_not_allow' ];
            }
            register_post_type($type, $args);
        }
        register_taxonomy('module', [ 'course', 'lesson' ], [ 'hierarchical' => true, 'public' => true ]);
        register_taxonomy('sensei_learner', [ 'course' ], [ 'public' => false ]);
        register_taxonomy('quiz-type', [ 'quiz' ], [ 'public' => false ]);
        register_taxonomy('question-type', [ 'question' ], [ 'public' => false ]);
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

    private function post(string $type, string $title, array $meta = [], int $parent = 0, string $status = 'publish'): int
    {
        return self::factory()->post->create([
            'post_type'   => $type,
            'post_title'  => $title,
            'post_parent' => $parent,
            'post_status' => $status,
            'meta_input'  => $meta,
        ]);
    }

    private function lesson(int $course, string $title, int $order, int $module = 0, string $status = 'publish'): int
    {
        $meta = [ '_lesson_course' => $course ];
        if ($module > 0) {
            $meta[ '_order_module_' . $module ] = $order;
        } else {
            $meta[ '_order_' . $course ] = $order;
        }
        $id = $this->post('lesson', $title, $meta, 0, $status);
        if ($module > 0) {
            wp_set_object_terms($id, [ $module ], 'module');
        }
        return $id;
    }

    private function quiz(int $lesson, string $title): int
    {
        $quiz = $this->post('quiz', $title, [ '_quiz_lesson' => $lesson ], $lesson);
        update_post_meta($lesson, '_lesson_quiz', $quiz);
        return $quiz;
    }

    private function question(int $quiz, string $title, int $order, string $type, int $grade): int
    {
        $id = $this->post('question', $title, [ '_quiz_id' => $quiz, '_quiz_question_order' . $quiz => $order, '_question_grade' => $grade ]);
        wp_set_object_terms($id, [ $type ], 'question-type');
        return $id;
    }

    private function enroll(int $course, int $user): void
    {
        wp_set_object_terms($course, [ 'user-' . $user ], 'sensei_learner', true);
    }

    /**
     * A course with two modules and one lesson outside them:
     * [course, moduleA, moduleB, a1, a2, b1, root, quiz].
     */
    private function seed_course(): array
    {
        $course = $this->post('course', 'Photography 101');
        $a      = (int) wp_insert_term('Basics', 'module')['term_id'];
        $b      = (int) wp_insert_term('Lighting', 'module')['term_id'];
        wp_set_object_terms($course, [ $a, $b ], 'module');
        update_post_meta($course, '_module_order', [ (string) $b, (string) $a ]);

        $a2   = $this->lesson($course, 'Aperture', 2, $a);
        $a1   = $this->lesson($course, 'Holding the camera', 1, $a);
        $b1   = $this->lesson($course, 'Natural light', 1, $b);
        $root = $this->lesson($course, 'Wrap up', 1);
        update_post_meta($course, '_lesson_order', (string) $root);

        $quiz = $this->quiz($a1, 'Basics quiz');
        $this->question($quiz, 'Second question', 2, 'boolean', 2);
        $this->question($quiz, 'First question', 1, 'multiple-choice', 1);

        return [ $course, $a, $b, $a1, $a2, $b1, $root, $quiz ];
    }

    /** @return int[] root lesson ids of $course in Sensei's order. */
    private function root_order(int $course): array
    {
        $ids = get_posts([ 'post_type' => 'lesson', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_lesson_course', 'meta_value' => $course, 'orderby' => 'ID', 'order' => 'ASC' ]);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => [] === wp_get_object_terms($id, 'module', [ 'fields' => 'ids' ])));
        usort($ids, static fn (int $x, int $y): int => (int) get_post_meta($x, '_order_' . $course, true) <=> (int) get_post_meta($y, '_order_' . $course, true));
        return $ids;
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_sensei_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertSame('list_users', $ops['sensei-list-enrollments']['capability']);
        $this->assertArrayNotHasKey('sensei-add-section', $ops, 'Sensei modules are terms; no module write ships');
    }

    public function test_without_pro_the_sensei_ops_are_not_in_the_catalog_and_the_pair_is_not_available(): void
    {
        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $ops         = array_column($integration->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse($integration->is_available());
    }

    public function test_while_sensei_is_inactive_its_ops_answer_sensei_inactive_naming_the_plugin(): void
    {
        remove_all_filters('wpmcp_sensei_active');
        add_filter('wpmcp_sensei_active', '__return_false');
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('sensei-list-courses');
        $this->assertSame('sensei_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Sensei LMS', (string) ($out['error']['message'] ?? ''));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_get_course_returns_modules_in_course_order_then_the_lessons_outside_them_with_quiz_questions(): void
    {
        [$course, $a, $b, $a1, $a2, $b1, $root, $quiz] = $this->seed_course();

        $out = $this->ok($this->read('sensei-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame($course, $out['course']['id']);
        $this->assertSame([ $b, $a ], array_column($out['sections'], 'id'));
        $this->assertSame([ 'module', 'module' ], array_column($out['sections'], 'kind'));
        $this->assertSame([ 'Lighting', 'Basics' ], array_column($out['sections'], 'title'));
        $this->assertSame([ $b1 ], array_column($out['sections'][0]['items'], 'id'));
        $this->assertSame([ $a1, $a2 ], array_column($out['sections'][1]['items'], 'id'));
        $this->assertSame([ 1, 2 ], array_column($out['sections'][1]['items'], 'order'));
        $this->assertSame([ $root ], array_column($out['lessons'], 'id'));

        $first = $out['sections'][1]['items'][0];
        $this->assertSame($quiz, $first['quiz']['id']);
        $this->assertSame('quiz', $first['quiz']['kind']);
        $this->assertSame([ 'First question', 'Second question' ], array_column($first['quiz']['questions'], 'title'));
        $this->assertSame([ 'multiple-choice', 'boolean' ], array_column($first['quiz']['questions'], 'type'));
        $this->assertSame([ 1, 2 ], array_column($first['quiz']['questions'], 'points'));
        $this->assertSame([ 'sections' => 2, 'lessons' => 4, 'quizzes' => 1, 'assignments' => 0, 'questions' => 2 ], $out['counts']);
    }

    public function test_get_course_leaves_questions_out_on_request(): void
    {
        [$course] = $this->seed_course();
        $out      = $this->ok($this->read('sensei-get-course', [ 'id' => $course, 'questions' => false ]))['result'];
        $this->assertArrayNotHasKey('questions', $out['sections'][1]['items'][0]['quiz']);
        $this->assertNull($out['counts']['questions']);
    }

    public function test_get_course_refuses_an_id_that_is_not_a_sensei_course(): void
    {
        $page = $this->post('page', 'Not a course');
        $out  = $this->read('sensei-get-course', [ 'id' => $page ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
    }

    public function test_get_course_hides_another_users_draft_lesson_from_a_reader_who_cannot_read_it(): void
    {
        [$course, , , , , , $root] = $this->seed_course();
        $other  = self::factory()->user->create([ 'role' => 'administrator' ]);
        $hidden = $this->lesson($course, 'Secret draft', 2, 0, 'draft');
        wp_update_post([ 'ID' => $hidden, 'post_author' => $other ]);

        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($editor)->add_cap('read_private_courses');
        wp_set_current_user($editor);
        $out = $this->ok($this->read('sensei-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $root ], array_column($out['lessons'], 'id'));
    }

    public function test_list_courses_pages_with_structure_and_enrollment_counts_and_search(): void
    {
        [$course] = $this->seed_course();
        $other    = $this->post('course', 'Watercolor', [], 0, 'draft');
        $this->enroll($course, self::factory()->user->create());
        $this->enroll($course, self::factory()->user->create());

        $out  = $this->ok($this->read('sensei-list-courses'))['result'];
        $rows = array_column($out['courses'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame([ 'sections' => 2, 'lessons' => 4, 'quizzes' => 1 ], array_intersect_key($rows[ $course ], [ 'sections' => 0, 'lessons' => 0, 'quizzes' => 0 ]));
        $this->assertSame(2, $rows[ $course ]['enrolled']);
        $this->assertSame('draft', $rows[ $other ]['status']);

        $found = $this->ok($this->read('sensei-list-courses', [ 'search' => 'Water' ]))['result'];
        $this->assertSame([ $other ], array_column($found['courses'], 'id'));
    }

    public function test_list_enrollments_needs_list_users_and_returns_masked_user_fields_only(): void
    {
        [$course] = $this->seed_course();
        $alice    = self::factory()->user->create([ 'display_name' => 'Alice Student', 'user_email' => 'alice@example.com' ]);
        $bob      = self::factory()->user->create([ 'display_name' => 'Bob Student', 'user_email' => 'bob@example.com' ]);
        $this->enroll($course, $alice);
        $this->enroll($course, $bob);
        $other = $this->post('course', 'Other');
        $this->enroll($other, self::factory()->user->create());

        $out  = $this->ok($this->read('sensei-list-enrollments', [ 'course_id' => $course ]))['result'];
        $rows = array_column($out['enrollments'], null, 'user_id');
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['masked']);
        $this->assertSame([ $bob, $alice ], array_column($out['enrollments'], 'user_id'), 'newest learner first');
        $this->assertSame('enrolled', $rows[ $alice ]['status']);
        $this->assertSame('a***@example.com', $rows[ $alice ]['email']);
        $this->assertSame([ 'user_id', 'display_name', 'email', 'status', 'enrolled_at' ], array_keys($rows[ $alice ]));

        $page = $this->ok($this->read('sensei-list-enrollments', [ 'course_id' => $course, 'per_page' => 1, 'page' => 2 ]))['result'];
        $this->assertSame([ $alice ], array_column($page['enrollments'], 'user_id'));
        $none = $this->ok($this->read('sensei-list-enrollments', [ 'course_id' => $course, 'status' => 'cancelled' ]))['result'];
        $this->assertSame([ 0, [] ], [ $none['total'], $none['enrollments'] ]);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $denied = $this->read('sensei-list-enrollments', [ 'course_id' => $course ]);
        $this->assertSame('operation_denied', $denied['error']['code'] ?? null);
    }

    public function test_enrollments_are_read_only_there_is_no_enrollment_write(): void
    {
        $ops    = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'mode', 'name');
        $enrols = array_filter($ops, static fn (string $name): bool => str_starts_with($name, 'sensei-') && str_contains($name, 'enrol'), ARRAY_FILTER_USE_KEY);
        $this->assertSame([ 'sensei-list-enrollments' => 'read' ], $enrols);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_building_a_course_stores_it_the_way_sensei_does_and_one_session_rollback_undoes_it(): void
    {
        $session   = wp_generate_uuid4();
        $course    = $this->ok($this->write('sensei-create-course', [ 'title' => 'Pottery', 'content' => 'Clay basics' ], $session));
        $course_id = $course['result']['item']['id'];
        $this->assertTrue($course['recoverable']);
        $this->assertSame('course', get_post_type($course_id));
        $this->assertSame('draft', get_post_status($course_id));

        $first  = $this->ok($this->write('sensei-add-lesson', [ 'parent_id' => $course_id, 'title' => 'Centering' ], $session))['result']['item']['id'];
        $second = $this->ok($this->write('sensei-add-lesson', [ 'parent_id' => $course_id, 'title' => 'Throwing' ], $session))['result']['item']['id'];
        $this->assertSame('lesson', get_post_type($first));
        $this->assertSame($course_id, (int) get_post_meta($first, '_lesson_course', true));
        $this->assertSame([ 1, 2 ], [ (int) get_post_meta($first, '_order_' . $course_id, true), (int) get_post_meta($second, '_order_' . $course_id, true) ]);
        $this->assertSame("{$first},{$second}", get_post_meta($course_id, '_lesson_order', true));

        $quiz = $this->ok($this->write('sensei-add-quiz', [ 'parent_id' => $first, 'title' => 'Centering quiz' ], $session))['result']['item']['id'];
        $this->assertSame('quiz', get_post_type($quiz));
        $this->assertSame($first, wp_get_post_parent_id($quiz));
        $this->assertSame($first, (int) get_post_meta($quiz, '_quiz_lesson', true));
        $this->assertSame($quiz, (int) get_post_meta($first, '_lesson_quiz', true));

        $tree = $this->ok($this->read('sensei-get-course', [ 'id' => $course_id ]))['result'];
        $this->assertSame([ $first, $second ], array_column($tree['lessons'], 'id'));
        $this->assertSame($quiz, $tree['lessons'][0]['quiz']['id']);

        Rollback_Service::restore_session($session);
        foreach ([ $course_id, $first, $second, $quiz ] as $id) {
            $this->assertSame('trash', get_post_status($id), "post {$id}");
        }
    }

    public function test_adding_a_lesson_at_a_position_renumbers_the_course_and_rollback_puts_it_back(): void
    {
        [$course, , , , , , $root] = $this->seed_course();
        $tail                       = $this->lesson($course, 'Bonus', 2);
        update_post_meta($course, '_lesson_order', "{$root},{$tail}");

        $out = $this->ok($this->write('sensei-add-lesson', [ 'parent_id' => $course, 'title' => 'Intro', 'position' => 0 ]));
        $new = $out['result']['item']['id'];
        $this->assertSame([ $new, $root, $tail ], $this->root_order($course));
        $this->assertSame("{$new},{$root},{$tail}", get_post_meta($course, '_lesson_order', true));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($new));
        $this->assertSame([ 1, 2 ], [ (int) get_post_meta($root, '_order_' . $course, true), (int) get_post_meta($tail, '_order_' . $course, true) ]);
        $this->assertSame("{$root},{$tail}", get_post_meta($course, '_lesson_order', true));
    }

    public function test_add_quiz_refuses_a_lesson_that_already_has_one_and_a_parent_that_is_not_a_lesson(): void
    {
        [$course, , , $a1, $a2] = $this->seed_course();
        $this->assertSame('quiz_exists', $this->write('sensei-add-quiz', [ 'parent_id' => $a1, 'title' => 'Again' ])['error']['code'] ?? null);
        $this->assertSame('invalid_parent', $this->write('sensei-add-quiz', [ 'parent_id' => $course, 'title' => 'Course quiz' ])['error']['code'] ?? null);
        $this->ok($this->write('sensei-add-quiz', [ 'parent_id' => $a2, 'title' => 'Aperture quiz' ]));
    }

    public function test_add_lesson_refuses_a_parent_that_is_not_a_sensei_course(): void
    {
        [, , , $a1] = $this->seed_course();
        $out        = $this->write('sensei-add-lesson', [ 'parent_id' => $a1, 'title' => 'Orphan' ]);
        $this->assertSame('course_not_found', $out['error']['code'] ?? null);
    }

    public function test_update_item_is_snapshotted_and_rollback_operation_restores_it(): void
    {
        [, , , $a1] = $this->seed_course();
        $out        = $this->ok($this->write('sensei-update-item', [ 'id' => $a1, 'title' => 'Gripping the camera', 'status' => 'draft' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Gripping the camera', get_the_title($a1));
        $this->assertSame('draft', get_post_status($a1));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Holding the camera', get_the_title($a1));
        $this->assertSame('publish', get_post_status($a1));
    }

    public function test_update_item_refuses_a_lesson_of_another_lms_and_a_plain_page(): void
    {
        $page   = $this->post('page', 'About');
        $orphan = $this->post('lesson', 'Not in a course');
        $this->assertSame('item_not_found', $this->write('sensei-update-item', [ 'id' => $page, 'title' => 'Hijacked' ])['error']['code'] ?? null);
        $this->assertSame('item_not_found', $this->write('sensei-update-item', [ 'id' => $orphan, 'title' => 'Hijacked' ])['error']['code'] ?? null);
        $this->assertSame('About', get_the_title($page));
    }

    public function test_move_item_reorders_lessons_inside_their_module_and_rollback_session_restores_them(): void
    {
        [$course, $a, , $a1, $a2] = $this->seed_course();

        $out = $this->ok($this->write('sensei-move-item', [ 'id' => $a2, 'position' => 0 ]));
        $this->assertSame([ 1, 2 ], [ (int) get_post_meta($a2, '_order_module_' . $a, true), (int) get_post_meta($a1, '_order_module_' . $a, true) ]);
        $this->assertSame('', get_post_meta($a2, '_order_' . $course, true), 'a module lesson keeps no course order');
        $tree = $this->ok($this->read('sensei-get-course', [ 'id' => $course ]))['result'];
        $this->assertSame([ $a2, $a1 ], array_column($tree['sections'][1]['items'], 'id'));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([ 1, 2 ], [ (int) get_post_meta($a1, '_order_module_' . $a, true), (int) get_post_meta($a2, '_order_module_' . $a, true) ]);
    }

    public function test_move_item_reorders_lessons_outside_modules_and_rewrites_the_course_lesson_order(): void
    {
        [$course, , , , , , $root] = $this->seed_course();
        $tail                       = $this->lesson($course, 'Bonus', 2);
        update_post_meta($course, '_lesson_order', "{$root},{$tail}");

        $out = $this->ok($this->write('sensei-move-item', [ 'id' => $tail, 'parent_id' => $course, 'position' => 0 ]));
        $this->assertSame([ $tail, $root ], $this->root_order($course));
        $this->assertSame("{$tail},{$root}", get_post_meta($course, '_lesson_order', true));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame([ $root, $tail ], $this->root_order($course));
        $this->assertSame("{$root},{$tail}", get_post_meta($course, '_lesson_order', true));
    }

    public function test_move_item_refuses_another_course_and_a_quiz_without_touching_anything(): void
    {
        [, , , $a1, , , , $quiz] = $this->seed_course();
        $other                   = $this->post('course', 'Other');
        $count                   = Snapshot_Store::row_count();

        $this->assertSame('cross_course_move', $this->write('sensei-move-item', [ 'id' => $a1, 'parent_id' => $other, 'position' => 0 ])['error']['code'] ?? null);
        $this->assertSame('not_movable', $this->write('sensei-move-item', [ 'id' => $quiz, 'position' => 0 ])['error']['code'] ?? null);
        $this->assertSame($count, Snapshot_Store::row_count());
    }

    public function test_a_user_without_sensei_lesson_capabilities_cannot_add_a_lesson(): void
    {
        [$course] = $this->seed_course();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $out = $this->write('sensei-add-lesson', [ 'parent_id' => $course, 'title' => 'Sneaky' ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertSame([], get_posts([ 'post_type' => 'lesson', 'title' => 'Sneaky', 'post_status' => 'any', 'fields' => 'ids' ]));
    }
}
