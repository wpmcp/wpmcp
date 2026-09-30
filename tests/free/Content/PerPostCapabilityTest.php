<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Content\List_Posts;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Search\Content_Indexer;
use WPMCP\Tools\Search\Search_Content;

/**
 * The content tools enforce WordPress's per-post meta capabilities (issue
 * #448). The tools are gated on edit_posts, which Contributors and Authors
 * hold, and that general capability does not entitle anyone to change or
 * read another user's post: core's editor and REST API check edit_post,
 * delete_post and read_post for the specific item, and so must every tool
 * that names a post by id.
 */
class PerPostCapabilityTest extends \WP_UnitTestCase
{
    /** @var array<string,int> role => user id */
    private array $users = [];

    private int $owner;
    private int $published;
    private int $draft;
    private int $private;
    private int $revision;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        foreach (['contributor', 'author', 'editor', 'administrator'] as $role) {
            $this->users[ $role ] = self::factory()->user->create(['role' => $role]);
        }
        // Another user's posts: a published one, a draft and a private one.
        $this->owner     = self::factory()->user->create(['role' => 'editor']);
        $this->published = self::factory()->post->create(['post_author' => $this->owner, 'post_title' => 'Owner published zanzibar']);
        $this->draft     = self::factory()->post->create(['post_author' => $this->owner, 'post_status' => 'draft', 'post_title' => 'Owner draft zanzibar']);
        $this->private   = self::factory()->post->create(['post_author' => $this->owner, 'post_status' => 'private', 'post_title' => 'Owner private zanzibar']);
        $this->revision  = $this->revision_of($this->published);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function revision_of(int $post_id): int
    {
        return (int) wp_insert_post([
            'post_type'    => 'revision',
            'post_status'  => 'inherit',
            'post_parent'  => $post_id,
            'post_author'  => (int) get_post_field('post_author', $post_id),
            'post_title'   => 'earlier title',
            'post_content' => 'earlier content',
            'post_name'    => $post_id . '-revision-v1',
        ]);
    }

    private function permits(string $ability, array $input): bool
    {
        $registrar = Plugin::instance()->registrar();
        $a         = $registrar->get($ability);
        $this->assertInstanceOf(Ability::class, $a, $ability . ' is not registered');
        return $registrar->would_permit($a, $input);
    }

    /** @return array<string, array<string, mixed>> ability => input, every write aimed at $post_id */
    private function writes(int $post_id, int $revision_id): array
    {
        return [
            'wpmcp/update-post'      => ['post_id' => $post_id, 'title' => 'changed'],
            'wpmcp/delete-post'      => ['post_id' => $post_id],
            'wpmcp/set-post-terms'   => ['post_id' => $post_id, 'taxonomy' => 'post_tag', 'terms' => ['x']],
            'wpmcp/set-post-meta'    => ['post_id' => $post_id, 'key' => 'k', 'value' => 'v'],
            'wpmcp/restore-revision' => ['post_id' => $post_id, 'revision_id' => $revision_id],
            'wpmcp/update-blocks'    => ['id' => $post_id],
            'wpmcp/add-block'        => ['id' => $post_id],
            'wpmcp/update-seo-meta'  => ['post_id' => $post_id],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function low_roles(): array
    {
        return ['contributor' => ['contributor'], 'author' => ['author']];
    }

    /** @return array<string, array{0: string}> */
    public static function high_roles(): array
    {
        return ['editor' => ['editor'], 'administrator' => ['administrator']];
    }

    /**
     * @dataProvider low_roles
     */
    public function test_contributors_and_authors_cannot_change_another_users_post(string $role): void
    {
        wp_set_current_user($this->users[ $role ]);
        foreach ($this->writes($this->published, $this->revision) as $ability => $input) {
            $this->assertFalse($this->permits($ability, $input), $ability . ' on another user\'s published post as ' . $role);
        }
    }

    /**
     * @dataProvider low_roles
     */
    public function test_contributors_and_authors_keep_full_access_to_their_own_draft(string $role): void
    {
        $own      = self::factory()->post->create(['post_author' => $this->users[ $role ], 'post_status' => 'draft']);
        $revision = $this->revision_of($own);
        wp_set_current_user($this->users[ $role ]);
        foreach ($this->writes($own, $revision) as $ability => $input) {
            $this->assertTrue($this->permits($ability, $input), $ability . ' on their own draft as ' . $role);
        }
        $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $own]));
        $this->assertTrue($this->permits('wpmcp/get-revision', ['revision_id' => $revision]));
    }

    public function test_an_author_keeps_full_access_to_their_own_published_post(): void
    {
        $own      = self::factory()->post->create(['post_author' => $this->users['author']]);
        $revision = $this->revision_of($own);
        wp_set_current_user($this->users['author']);
        foreach ($this->writes($own, $revision) as $ability => $input) {
            $this->assertTrue($this->permits($ability, $input), $ability . ' on their own published post');
        }
    }

    /**
     * @dataProvider high_roles
     */
    public function test_editors_and_administrators_keep_full_access(string $role): void
    {
        wp_set_current_user($this->users[ $role ]);
        foreach ([$this->published, $this->draft, $this->private] as $post_id) {
            $revision = $this->revision_of($post_id);
            foreach ($this->writes($post_id, $revision) as $ability => $input) {
                $this->assertTrue($this->permits($ability, $input), $ability . ' on ' . get_post_status($post_id) . ' as ' . $role);
            }
            $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $post_id]));
            $this->assertTrue($this->permits('wpmcp/get-revision', ['revision_id' => $revision]));
        }
    }

    /**
     * @dataProvider low_roles
     */
    public function test_another_users_draft_and_private_post_cannot_be_read(string $role): void
    {
        wp_set_current_user($this->users[ $role ]);
        foreach ([$this->draft, $this->private] as $post_id) {
            $this->assertFalse($this->permits('wpmcp/get-post', ['post_id' => $post_id]), 'get-post on ' . get_post_status($post_id));
            $this->assertFalse($this->permits('wpmcp/get-post-meta', ['post_id' => $post_id]));
            $this->assertFalse($this->permits('wpmcp/list-revisions', ['post_id' => $post_id]));
            $this->assertFalse($this->permits('wpmcp/duplicate-post', ['post_id' => $post_id]));
            $this->assertFalse($this->permits('wpmcp/get-revision', ['revision_id' => $this->revision_of($post_id)]));
        }
        // Published content stays readable, and copyable, as before.
        $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $this->published]));
        $this->assertTrue($this->permits('wpmcp/duplicate-post', ['post_id' => $this->published]));
    }

    public function test_the_governed_path_refuses_a_contributors_edit_and_leaves_the_post_alone(): void
    {
        wp_set_current_user($this->users['contributor']);
        $ability = wp_get_ability('wpmcp/update-post');
        $this->assertNotNull($ability);
        $result = $ability->execute(['post_id' => $this->published, 'title' => 'hijacked']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Owner published zanzibar', get_post_field('post_title', $this->published));
    }

    public function test_list_posts_returns_only_rows_a_contributor_may_read(): void
    {
        $own = self::factory()->post->create(['post_author' => $this->users['contributor'], 'post_status' => 'draft']);
        wp_set_current_user($this->users['contributor']);

        foreach (['any', 'draft', 'private'] as $status) {
            $out = (new List_Posts())->handle(['status' => $status, 'per_page' => 100]);
            $ids = array_column($out['posts'], 'post_id');
            $this->assertNotContains($this->draft, $ids, 'status ' . $status);
            $this->assertNotContains($this->private, $ids, 'status ' . $status);
            $this->assertSame(count($ids), $out['total'], 'total counts only readable rows, status ' . $status);
        }
        $ids = array_column((new List_Posts())->handle(['per_page' => 100])['posts'], 'post_id');
        $this->assertContains($this->published, $ids);
        $this->assertContains($own, $ids);

        $ids = array_column((new List_Posts())->handle(['post_type' => 'any', 'per_page' => 100])['posts'], 'post_id');
        $this->assertNotContains($this->draft, $ids);
        $this->assertNotContains($this->private, $ids);
        $this->assertContains($own, $ids);
    }

    public function test_list_posts_is_unchanged_for_an_editor(): void
    {
        wp_set_current_user($this->users['editor']);
        $ids = array_column((new List_Posts())->handle(['per_page' => 100])['posts'], 'post_id');
        foreach ([$this->published, $this->draft, $this->private] as $post_id) {
            $this->assertContains($post_id, $ids);
        }
    }

    public function test_search_content_hides_another_users_draft_and_private_post(): void
    {
        foreach ([$this->published, $this->draft, $this->private] as $post_id) {
            Content_Indexer::index_post($post_id);
        }

        wp_set_current_user($this->users['contributor']);
        $ids = array_column((new Search_Content())->handle(['query' => 'zanzibar'])['results'], 'object_id');
        $this->assertContains($this->published, $ids);
        $this->assertNotContains($this->draft, $ids);
        $this->assertNotContains($this->private, $ids);

        wp_set_current_user($this->users['editor']);
        $ids = array_column((new Search_Content())->handle(['query' => 'zanzibar'])['results'], 'object_id');
        $this->assertContains($this->draft, $ids);
        $this->assertContains($this->private, $ids);
    }

    public function test_a_contributor_cannot_roll_back_an_edit_of_another_users_post(): void
    {
        wp_set_current_user($this->owner);
        $out = (new Update_Post())->handle(['post_id' => $this->published, 'title' => 'Owner edit']);

        wp_set_current_user($this->users['contributor']);
        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotEmpty(Rollback_Service::take_warnings());
        $this->assertSame('Owner edit', get_post_field('post_title', $this->published));

        // The owner's own undo still works.
        wp_set_current_user($this->owner);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Owner published zanzibar', get_post_field('post_title', $this->published));
    }

    public function test_a_contributor_can_still_roll_back_their_own_edit(): void
    {
        $own = self::factory()->post->create(['post_author' => $this->users['contributor'], 'post_status' => 'draft', 'post_title' => 'mine']);
        wp_set_current_user($this->users['contributor']);
        $out = (new Update_Post())->handle(['post_id' => $own, 'title' => 'mine, edited']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('mine', get_post_field('post_title', $own));
    }
}
