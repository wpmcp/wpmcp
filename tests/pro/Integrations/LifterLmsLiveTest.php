<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Bridge\Execute_Site_Ability;

/**
 * Issue #394: the LifterLMS ops against the real LifterLMS.
 *
 * It runs only in the local gate's live LifterLMS leg (bin/test-local.sh, or
 * bin/test-local.sh --live-lms alone), which sets WPMCP_LIVE_LMS=lifterlms,
 * installs LifterLMS from wordpress.org on a separate WordPress install,
 * loads it in tests/bootstrap.php and builds its tables and roles with its own
 * installer.
 *
 * A course tree built through the ops must read back through LifterLMS's own
 * models (LLMS_Course::get_sections(), LLMS_Section::get_lessons(),
 * LLMS_Lesson::get_quiz()) in the same order, an enrollment LifterLMS
 * records itself must list, and rollback-session must take the tree back out.
 *
 * LifterLMS also registers abilities of its own (lifterlms/*, since 10.1).
 * Those are reached through the third-party ability bridge rather than
 * duplicated, so this leg also checks that the bridge runs one of them.
 *
 * Everywhere else LifterLMS is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group lifterlms-live
 */
class LifterLmsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('llms') || ! class_exists('LLMS_Course')) {
            $this->markTestSkipped('Needs the real LifterLMS (bin/test-local.sh --live-lms, WPMCP_LIVE_LMS=lifterlms).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_ability_bridge');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ] + (null === $session ? [] : [ 'session_id' => $session ]);
        $out  = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    public function test_a_tree_built_through_the_ops_is_lifterlms_own_structure_and_rolls_back(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
        $session = wp_generate_uuid4();
        $course  = $this->call('write', 'lifterlms-create-course', [ 'title' => 'Live course', 'status' => 'publish' ], $session)['item']['id'];
        $s1      = $this->call('write', 'lifterlms-add-section', [ 'course_id' => $course, 'title' => 'One' ], $session)['item']['id'];
        $s2      = $this->call('write', 'lifterlms-add-section', [ 'course_id' => $course, 'title' => 'Two' ], $session)['item']['id'];
        $l1      = $this->call('write', 'lifterlms-add-lesson', [ 'parent_id' => $s1, 'title' => 'Lesson A' ], $session)['item']['id'];
        $l2      = $this->call('write', 'lifterlms-add-lesson', [ 'parent_id' => $s1, 'title' => 'Lesson B', 'position' => 0 ], $session)['item']['id'];
        $quiz    = $this->call('write', 'lifterlms-add-quiz', [ 'parent_id' => $l1, 'title' => 'Quiz A' ], $session)['item']['id'];
        $this->call('write', 'lifterlms-move-item', [ 'id' => $s2, 'position' => 0 ], $session);

        $model = llms_get_post($course);
        $this->assertSame([ $s2, $s1 ], array_map(static fn ($s): int => (int) $s->get('id'), $model->get_sections()));
        $section = llms_get_post($s1);
        $this->assertSame([ $l2, $l1 ], array_map(static fn ($l): int => (int) $l->get('id'), $section->get_lessons()));
        $this->assertSame($quiz, (int) llms_get_post($l1)->get_quiz()->get('id'));
        $this->assertTrue(llms_get_post($l1)->is_quiz_enabled());
        $this->assertSame($course, (int) llms_get_post($l1)->get('parent_course'));

        Rollback_Service::restore_session($session);
        $this->assertSame('trash', get_post_status($course));
        $this->assertSame('trash', get_post_status($quiz));
    }

    public function test_an_enrollment_lifterlms_records_is_listed_read_only(): void
    {
        $course  = $this->call('write', 'lifterlms-create-course', [ 'title' => 'Enroll me', 'status' => 'publish' ])['item']['id'];
        $student = self::factory()->user->create([ 'display_name' => 'Live Student', 'user_email' => 'live.student@example.com' ]);
        $this->assertTrue((bool) llms_enroll_student($student, $course, 'admin_' . get_current_user_id()));

        $out = $this->call('read', 'lifterlms-list-enrollments', [ 'course_id' => $course ]);
        $this->assertSame([ $student ], array_column($out['enrollments'], 'user_id'));
        $this->assertSame('enrolled', $out['enrollments'][0]['status']);

        llms_unenroll_student($student, $course, 'cancelled', 'any');
        $out = $this->call('read', 'lifterlms-list-enrollments', [ 'course_id' => $course ]);
        $this->assertSame('cancelled', $out['enrollments'][0]['status']);
    }

    public function test_lifterlms_native_abilities_are_reached_through_the_ability_bridge(): void
    {
        wp_get_abilities();
        $this->assertTrue(wp_has_ability('lifterlms/get-course-content'));
        add_filter('wpmcp_enable_ability_bridge', '__return_true');

        $course = $this->call('write', 'lifterlms-create-course', [ 'title' => 'Bridged', 'status' => 'publish' ])['item']['id'];
        $this->call('write', 'lifterlms-add-section', [ 'course_id' => $course, 'title' => 'Only section' ]);

        $out = (new Execute_Site_Ability())->handle([ 'name' => 'lifterlms/get-course-content', 'arguments' => [ 'id' => $course ] ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertFalse($out['reversible']);
        $this->assertSame('lifterlms', $out['plugin']);
    }
}
