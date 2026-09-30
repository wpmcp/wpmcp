<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #394: the Tutor LMS ops against the real Tutor LMS.
 *
 * It runs only in the local gate's live Tutor LMS leg (bin/test-local.sh, or
 * bin/test-local.sh --live-lms alone), which sets WPMCP_LIVE_LMS=tutor,
 * installs Tutor LMS from wordpress.org on a separate WordPress install,
 * loads it in tests/bootstrap.php and builds its tables and roles with its own
 * installer.
 *
 * A course tree built through the ops must read back through Tutor LMS's own
 * curriculum API (tutor_utils()->get_topics() and
 * get_course_contents_by_topic()) in the same order, an enrollment Tutor LMS
 * records itself must list, and rollback-session must take the built tree
 * back out of Tutor LMS's curriculum.
 *
 * Everywhere else Tutor LMS is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group tutor-live
 */
class TutorLmsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('TUTOR_VERSION') || ! function_exists('tutor_utils')) {
            $this->markTestSkipped('Needs the real Tutor LMS (bin/test-local.sh --live-lms, WPMCP_LIVE_LMS=tutor).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
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

    public function test_a_tree_built_through_the_ops_is_tutors_own_curriculum_and_rolls_back(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
        $session = wp_generate_uuid4();
        $course  = $this->call('write', 'tutor-create-course', [ 'title' => 'Live course', 'status' => 'publish' ], $session)['item']['id'];
        $t1      = $this->call('write', 'tutor-add-section', [ 'course_id' => $course, 'title' => 'One' ], $session)['item']['id'];
        $t2      = $this->call('write', 'tutor-add-section', [ 'course_id' => $course, 'title' => 'Two' ], $session)['item']['id'];
        $lesson  = $this->call('write', 'tutor-add-lesson', [ 'parent_id' => $t1, 'title' => 'Lesson A' ], $session)['item']['id'];
        $quiz    = $this->call('write', 'tutor-add-quiz', [ 'parent_id' => $t1, 'title' => 'Quiz A', 'position' => 0 ], $session)['item']['id'];
        $this->call('write', 'tutor-move-item', [ 'id' => $t2, 'position' => 0 ], $session);

        $topics = tutor_utils()->get_topics($course);
        $this->assertSame([ $t2, $t1 ], array_map('intval', wp_list_pluck($topics->posts, 'ID')));
        $contents = tutor_utils()->get_course_contents_by_topic($t1, -1);
        $this->assertSame([ $quiz, $lesson ], array_map('intval', wp_list_pluck($contents->posts, 'ID')));
        $this->assertSame($course, (int) tutor_utils()->get_course_id_by('lesson', $lesson));

        $tree = $this->call('read', 'tutor-get-course', [ 'id' => $course ]);
        $this->assertSame([ $t2, $t1 ], array_column($tree['sections'], 'id'));
        $this->assertSame([ $quiz, $lesson ], array_column($tree['sections'][1]['items'], 'id'));

        Rollback_Service::restore_session($session);
        $this->assertSame('trash', get_post_status($course));
        $this->assertSame([], wp_list_pluck(tutor_utils()->get_topics($course)->posts, 'ID'));
    }

    public function test_an_enrollment_tutor_records_is_listed_read_only(): void
    {
        $course  = $this->call('write', 'tutor-create-course', [ 'title' => 'Enroll me', 'status' => 'publish' ])['item']['id'];
        $student = self::factory()->user->create([ 'display_name' => 'Live Student', 'user_email' => 'live.student@example.com' ]);
        \Tutor\Models\EnrollmentModel::do_enroll($course, 0, $student, false);

        $out = $this->call('read', 'tutor-list-enrollments', [ 'course_id' => $course ]);
        $this->assertSame([ $student ], array_column($out['enrollments'], 'user_id'));
        $this->assertSame('enrolled', $out['enrollments'][0]['status']);
        $this->assertSame('live.student@example.com', $out['enrollments'][0]['email']);
    }
}
