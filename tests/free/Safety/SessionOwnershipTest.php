<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;

/**
 * rollback-session acts on the sessions the caller started (issue #450).
 *
 * Every undo point records the user who wrote it, and a named session belongs
 * to the user who wrote its first undo point. Below site administrator
 * (manage_options) a caller may roll back only their own sessions; another
 * user's session, or one written with no user at all (cron, WP-CLI without
 * --user) and so owned by no one, is refused with the reason and nothing is
 * restored. The per-post checks still apply on top, row by row.
 *
 * The shared catch-all 'default' session is a stream of unrelated single
 * writes by everyone, not one run, so it is not refused as a whole: each of
 * its rows is judged by the per-post checks, as before.
 *
 * The victim post in each case is the caller's own draft, so the per-post
 * checks would let the caller restore it and only ownership refuses.
 */
class SessionOwnershipTest extends \WP_UnitTestCase
{
    /** @var array<string,int> role => user id */
    private array $users = [];

    private int $creator;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        foreach (['contributor', 'author', 'editor', 'administrator'] as $role) {
            $this->users[ $role ] = self::factory()->user->create(['role' => $role]);
        }
        $this->creator = self::factory()->user->create(['role' => 'editor']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** A snapshotted edit of $post_id by $user_id under $session. */
    private function edit(int $user_id, int $post_id, string $session, string $title): void
    {
        wp_set_current_user($user_id);
        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => $session,
                'tool_name'   => 'update-post',
                'args'        => ['title' => $title],
            ],
            static fn () => wp_update_post(['ID' => $post_id, 'post_title' => $title], true)
        );
    }

    private function draft_of(string $role): int
    {
        return self::factory()->post->create(['post_author' => $this->users[ $role ], 'post_status' => 'draft', 'post_title' => 'original']);
    }

    /** @return array<string, array{0: string}> */
    public static function non_admin_roles(): array
    {
        return ['contributor' => ['contributor'], 'author' => ['author'], 'editor' => ['editor']];
    }

    /** @return array<string, array{0: string}> */
    public static function all_roles(): array
    {
        return self::non_admin_roles() + ['administrator' => ['administrator']];
    }

    /**
     * @dataProvider non_admin_roles
     */
    public function test_another_users_session_is_refused_below_administrator(string $role): void
    {
        $post    = $this->draft_of($role);
        $session = wp_generate_uuid4();
        $this->edit($this->creator, $post, $session, 'changed by creator');

        wp_set_current_user($this->users[ $role ]);
        try {
            Rollback_Service::restore_session($session);
            $this->fail('Rolling back another user\'s session was not refused for ' . $role);
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
            $this->assertStringContainsString('Nothing was restored', $e->getMessage());
        }
        $this->assertSame('changed by creator', get_post_field('post_title', $post));
    }

    public function test_the_tool_reports_the_refusal(): void
    {
        $post    = $this->draft_of('author');
        $session = wp_generate_uuid4();
        $this->edit($this->creator, $post, $session, 'changed by creator');

        wp_set_current_user($this->users['author']);
        $this->expectException(Mutation_Failed::class);
        (new Rollback_Session())->handle(['session_id' => $session]);
    }

    public function test_an_administrator_may_roll_back_anyones_session(): void
    {
        $post    = $this->draft_of('contributor');
        $session = wp_generate_uuid4();
        $this->edit($this->creator, $post, $session, 'changed by creator');

        wp_set_current_user($this->users['administrator']);
        $this->assertSame(1, Rollback_Service::restore_session($session));
        $this->assertSame('original', get_post_field('post_title', $post));
    }

    /**
     * @dataProvider all_roles
     */
    public function test_every_role_keeps_rolling_back_its_own_session(string $role): void
    {
        $post    = $this->draft_of($role);
        $session = wp_generate_uuid4();
        $this->edit($this->users[ $role ], $post, $session, 'first');
        $this->edit($this->users[ $role ], $post, $session, 'second');

        wp_set_current_user($this->users[ $role ]);
        $this->assertSame(2, Rollback_Service::restore_session($session));
        $this->assertSame('original', get_post_field('post_title', $post));
    }

    public function test_the_session_belongs_to_whoever_started_it(): void
    {
        // The editor starts a session; the contributor then writes into it.
        $editors = self::factory()->post->create(['post_author' => $this->users['editor'], 'post_status' => 'draft', 'post_title' => 'original']);
        $session = wp_generate_uuid4();
        $this->edit($this->users['editor'], $editors, $session, 'editor change');
        $mine = $this->draft_of('contributor');
        $this->edit($this->users['contributor'], $mine, $session, 'contributor change');

        wp_set_current_user($this->users['contributor']);
        try {
            Rollback_Service::restore_session($session);
            $this->fail('Joining another user\'s session must not make it the joiner\'s');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
        }

        wp_set_current_user($this->users['editor']);
        $this->assertSame(2, Rollback_Service::restore_session($session));
        $this->assertSame('original', get_post_field('post_title', $editors));
    }

    /**
     * @dataProvider non_admin_roles
     */
    public function test_a_session_written_with_no_user_is_administrator_only(string $role): void
    {
        $post    = $this->draft_of($role);
        $session = wp_generate_uuid4();
        $this->edit(0, $post, $session, 'written by cron');

        wp_set_current_user($this->users[ $role ]);
        try {
            Rollback_Service::restore_session($session);
            $this->fail('An unowned session was not refused for ' . $role);
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('no recorded owner', $e->getMessage());
        }
        $this->assertSame('written by cron', get_post_field('post_title', $post));

        wp_set_current_user($this->users['administrator']);
        $this->assertSame(1, Rollback_Service::restore_session($session));
        $this->assertSame('original', get_post_field('post_title', $post));
    }

    /**
     * @dataProvider all_roles
     */
    public function test_the_default_session_keeps_working_row_by_row(string $role): void
    {
        $mine   = $this->draft_of($role);
        $theirs = self::factory()->post->create(['post_author' => $this->creator, 'post_status' => 'draft', 'post_title' => 'original']);
        $this->edit($this->creator, $mine, 'default', 'creator changed mine');
        $this->edit($this->creator, $theirs, 'default', 'creator changed theirs');

        wp_set_current_user($this->users[ $role ]);
        $restored = Rollback_Service::restore_session('default');

        // The caller's own post comes back whoever wrote the change; another
        // user's draft only for a caller who may edit it.
        $this->assertSame('original', get_post_field('post_title', $mine));
        $may_edit = current_user_can('edit_post', $theirs);
        $this->assertSame($may_edit ? 'original' : 'creator changed theirs', get_post_field('post_title', $theirs));
        $this->assertSame($may_edit ? 2 : 1, $restored);
    }

    public function test_an_unknown_session_is_still_reported_as_unknown(): void
    {
        wp_set_current_user($this->users['contributor']);
        $this->assertSame(0, Rollback_Service::restore_session(wp_generate_uuid4()));
        $this->assertNotEmpty(Rollback_Service::take_warnings());
    }
}
