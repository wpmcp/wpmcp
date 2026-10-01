<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\List_Operations;

/**
 * The operation log and named sessions stay with the user who wrote them
 * (issue #461, following #450's rollback-session ownership).
 *
 * - list-operations lists a caller's own undo points only, unless they may
 *   manage the site (manage_options); total_count counts the same rows.
 * - A write naming a session another user started is refused below
 *   manage_options with the reason, and nothing is changed: otherwise the
 *   owner's rollback-session would also undo the joiner's writes. The rule
 *   is the one rollback-session applies, so a caller may write into exactly
 *   the named sessions they could roll back. The shared 'default' session,
 *   a session id nobody has used yet, and a tool that generates its own
 *   session id keep working for everyone.
 *
 * Checked as Contributor, Author, Editor and Administrator.
 */
class SessionScopeTest extends \WP_UnitTestCase
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

    /** A snapshotted title edit of $post_id by the current user under $session. */
    private static function edit(int $post_id, string $session, string $title): array
    {
        return Safe_Mutation::run(
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

    private function draft_of(int $user): int
    {
        return self::factory()->post->create(['post_author' => $user, 'post_status' => 'draft', 'post_title' => 'original']);
    }

    /** A named session started by the creator. */
    private function creators_session(): string
    {
        $session = wp_generate_uuid4();
        wp_set_current_user($this->creator);
        self::edit($this->draft_of($this->creator), $session, 'creator change');
        return $session;
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
     * @dataProvider all_roles
     */
    public function test_list_operations_shows_non_administrators_only_their_own_rows(string $role): void
    {
        $session = $this->creators_session();
        $caller  = $this->users[ $role ];
        wp_set_current_user($caller);
        $mine = self::edit($this->draft_of($caller), wp_generate_uuid4(), 'my change');

        $out = (new List_Operations())->handle(['limit' => 100]);
        $ids = array_column($out['operations'], 'operation_id');
        $this->assertContains($mine['operation_id'], $ids);

        $others = array_values(array_filter($out['operations'], static fn (array $op): bool => $op['user_id'] !== $caller));
        if ('administrator' === $role) {
            $this->assertNotSame([], $others, 'An administrator sees every user\'s rows');
            $this->assertContains($session, array_column($out['operations'], 'session_id'));
        } else {
            $this->assertSame([], $others, 'A ' . $role . ' sees only their own rows');
            $this->assertSame(count($out['operations']), $out['total_count']);
        }

        // Filters narrow within the caller's own rows, never past them.
        $theirs = (new List_Operations())->handle(['session_id' => $session]);
        $this->assertSame('administrator' === $role ? 1 : 0, $theirs['total_count']);
        $by_user = (new List_Operations())->handle(['user_id' => $this->creator]);
        $this->assertSame('administrator' === $role, $by_user['total_count'] > 0);
    }

    /**
     * @dataProvider non_admin_roles
     */
    public function test_writing_into_another_users_session_is_refused(string $role): void
    {
        $session = $this->creators_session();
        $caller  = $this->users[ $role ];
        wp_set_current_user($caller);
        $post = $this->draft_of($caller);

        try {
            self::edit($post, $session, 'joined');
            $this->fail('Writing into another user\'s session was not refused for ' . $role);
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
        }
        $this->assertSame('original', get_post_field('post_title', $post), 'Nothing was changed');
        $this->assertCount(1, Snapshot_Store::list_by_session($session), 'No row joined the session');
    }

    /**
     * @dataProvider non_admin_roles
     */
    public function test_the_tools_report_the_refusal_and_leave_nothing_behind(string $role): void
    {
        $session = $this->creators_session();
        $caller  = $this->users[ $role ];
        wp_set_current_user($caller);
        $post = $this->draft_of($caller);

        try {
            (new Update_Post())->handle(['post_id' => $post, 'title' => 'joined', 'session_id' => $session]);
            $this->fail('update-post into another user\'s session was not refused');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
        }
        $this->assertSame('original', get_post_field('post_title', $post));

        $before = (int) wp_count_posts('post')->draft;
        try {
            (new Create_Post())->handle(['title' => 'joined', 'status' => 'draft', 'session_id' => $session]);
            $this->fail('create-post into another user\'s session was not refused');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
        }
        wp_cache_delete(_count_posts_cache_key('post'), 'counts');
        $this->assertSame($before, (int) wp_count_posts('post')->draft, 'The created post was removed again');
        $this->assertCount(1, Snapshot_Store::list_by_session($session));
    }

    public function test_an_administrator_may_write_into_any_session(): void
    {
        $session = $this->creators_session();
        wp_set_current_user($this->users['administrator']);
        $post = $this->draft_of($this->users['administrator']);

        self::edit($post, $session, 'joined');
        $this->assertSame('joined', get_post_field('post_title', $post));
        $this->assertCount(2, Snapshot_Store::list_by_session($session));
    }

    /**
     * @dataProvider all_roles
     */
    public function test_own_new_and_default_sessions_keep_working(string $role): void
    {
        $this->creators_session();
        wp_set_current_user($this->creator);
        self::edit($this->draft_of($this->creator), 'default', 'creator in default');

        $caller = $this->users[ $role ];
        wp_set_current_user($caller);
        $post = $this->draft_of($caller);

        // A session id nobody has used, then continued by its owner.
        $session = wp_generate_uuid4();
        self::edit($post, $session, 'first');
        self::edit($post, $session, 'second');
        $this->assertCount(2, Snapshot_Store::list_by_session($session));

        // The shared catch-all session, which other users have written to.
        self::edit($post, 'default', 'third');
        $this->assertSame('third', get_post_field('post_title', $post));

        // A tool that generates its own session id for a multi-object write.
        $pack = new Session_Scope_Fixture_Pack();
        $out  = $pack->handle_write(['operation' => 'retitle-two', 'args' => ['a' => $post, 'b' => $this->draft_of($caller)]]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertNotEmpty($out['session_id']);
        $this->assertSame('fixture', get_post_field('post_title', $post));
    }

    public function test_code_running_with_no_user_may_continue_a_users_session(): void
    {
        $session = $this->creators_session();
        wp_set_current_user(0);
        self::edit($this->draft_of($this->creator), $session, 'background step');
        $this->assertCount(2, Snapshot_Store::list_by_session($session));
    }
}

/**
 * A pack whose write op names two posts and so runs under a session id the
 * dispatcher generates.
 */
class Session_Scope_Fixture_Pack extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'session-scope-fixture';
    }

    public function is_available(): bool
    {
        return true;
    }

    protected function summary(): string
    {
        return 'Session scope fixture';
    }

    protected function operations(): array
    {
        return [
            'retitle-two' => [
                'mode'         => 'write',
                'description'  => 'Retitle two posts',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']],
                ],
                'self_snapshotting' => true,
                'handler'      => static function (array $args, array $context): array {
                    $ids = [];
                    foreach ([(int) $args['a'], (int) $args['b']] as $id) {
                        $ids[] = Safe_Mutation::run(
                            ['object_type' => 'post', 'object_id' => $id, 'session_id' => $context['session_id'], 'tool_name' => $context['tool_name'], 'args' => $args],
                            static fn () => wp_update_post(['ID' => $id, 'post_title' => 'fixture'])
                        )['operation_id'];
                    }
                    return ['result' => ['ok' => true], 'operation_ids' => $ids];
                },
            ],
        ];
    }
}
