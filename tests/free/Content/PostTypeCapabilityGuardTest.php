<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Content\Content_Guard;
use WPMCP\Tools\Content\List_Posts;
use WPMCP\Tools\Search\Content_Indexer;
use WPMCP\Tools\Search\Search_Content;

/**
 * The generic content tools honour the capabilities a post type declares
 * (issue #446). They are gated on edit_posts, which every Contributor holds,
 * and used to accept any post type, so a Contributor could list or read the
 * records other plugins keep as non-public post types: enrollments, orders,
 * form entries. The permission decision now requires, for every post id or
 * post type in the input, the type's own capabilities; a non-public type
 * with no admin screen is refused outright below administrator.
 */
class PostTypeCapabilityGuardTest extends \WP_UnitTestCase
{
    use Hidden_Post_Types;

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
        $this->register_hidden_post_types();
    }

    protected function tearDown(): void
    {
        $this->unregister_hidden_post_types();
        remove_all_filters('wpmcp_agent_readable_hidden_post_types');
        remove_all_filters('wpmcp_search_indexable_post_types');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function permits(string $ability, array $input): bool
    {
        $registrar = Plugin::instance()->registrar();
        $a         = $registrar->get($ability);
        $this->assertInstanceOf(Ability::class, $a, $ability . ' is not registered');
        return $registrar->would_permit($a, $input);
    }

    /** @return array<string, array{0: string, 1: bool}> role => [role, may read the hidden types] */
    public static function roles(): array
    {
        return [
            'contributor'   => ['contributor', false],
            'author'        => ['author', false],
            'editor'        => ['editor', false],
            'administrator' => ['administrator', true],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_hidden_types_follow_their_own_capabilities_by_role(string $role, bool $may_read): void
    {
        wp_set_current_user($this->role_users[ $role ]);

        foreach (self::hidden_types() as $type) {
            $id = $this->hidden_rows[ $type ];
            foreach ([
                'wpmcp/get-post'        => ['post_id' => $id],
                'wpmcp/get-post-meta'   => ['post_id' => $id],
                'wpmcp/duplicate-post'  => ['post_id' => $id],
                'wpmcp/list-revisions'  => ['post_id' => $id],
                'wpmcp/list-posts'      => ['post_type' => $type],
                'wpmcp/count-content'   => ['post_type' => $type],
                'wpmcp/list-taxonomies' => ['post_type' => $type],
            ] as $ability => $input) {
                $this->assertSame(
                    $may_read,
                    $this->permits($ability, $input),
                    sprintf('%s on %s as %s', $ability, $type, $role)
                );
            }
        }
    }

    /**
     * @dataProvider roles
     */
    public function test_public_posts_and_pages_behave_exactly_as_before(string $role): void
    {
        $other = self::factory()->user->create(['role' => 'administrator']);
        $post  = self::factory()->post->create(['post_author' => $other]);
        $page  = self::factory()->post->create(['post_type' => 'page', 'post_author' => $other]);
        $draft = self::factory()->post->create(['post_author' => $other, 'post_status' => 'draft']);
        $media = self::factory()->attachment->create(['post_author' => $other]);
        wp_set_current_user($this->role_users[ $role ]);

        foreach ([$post, $page, $draft, $media] as $id) {
            $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $id]), $role . ' lost get-post on ' . get_post_type($id));
            $this->assertFalse(Content_Guard::input_targets_private_post('content', ['post_id' => $id]));
        }
        foreach (['post', 'page', 'attachment', 'any'] as $type) {
            $this->assertTrue($this->permits('wpmcp/list-posts', ['post_type' => $type]), $role . ' lost list-posts on ' . $type);
        }
        $this->assertTrue($this->permits('wpmcp/list-posts', []));
    }

    public function test_list_posts_any_leaves_out_types_the_caller_may_not_read(): void
    {
        $post = self::factory()->post->create(['post_title' => 'ordinary']);

        wp_set_current_user($this->role_users['contributor']);
        $ids = array_column((new List_Posts())->handle(['post_type' => 'any', 'per_page' => 100])['posts'], 'post_id');
        $this->assertContains($post, $ids);
        $this->assertNotContains($this->hidden_rows[ self::ENTRY ], $ids, 'A form entry leaked through post_type any.');

        wp_set_current_user($this->role_users['administrator']);
        $ids = array_column((new List_Posts())->handle(['post_type' => 'any', 'per_page' => 100])['posts'], 'post_id');
        $this->assertContains($this->hidden_rows[ self::ENTRY ], $ids);
    }

    public function test_the_list_posts_handler_refuses_a_hidden_type_on_its_own(): void
    {
        wp_set_current_user($this->role_users['contributor']);
        $out = (new List_Posts())->handle(['post_type' => self::ORDER]);
        $this->assertSame(0, $out['total']);
        $this->assertSame([], $out['posts']);
    }

    public function test_revisions_of_a_hidden_row_are_refused(): void
    {
        $revision = wp_insert_post([
            'post_type'   => 'revision',
            'post_status' => 'inherit',
            'post_parent' => $this->hidden_rows[ self::ORDER ],
            'post_title'  => 'private record revision',
            'post_name'   => $this->hidden_rows[ self::ORDER ] . '-revision-v1',
        ]);
        $public   = self::factory()->post->create();
        $public_r = wp_insert_post([
            'post_type'   => 'revision',
            'post_status' => 'inherit',
            'post_parent' => $public,
            'post_title'  => 'public revision',
            'post_name'   => $public . '-revision-v1',
        ]);

        wp_set_current_user($this->role_users['contributor']);
        $this->assertFalse($this->permits('wpmcp/get-revision', ['revision_id' => $revision]));
        $this->assertFalse($this->permits('wpmcp/diff-revisions', ['from_revision_id' => $revision]));
        $this->assertFalse($this->permits('wpmcp/diff-revisions', ['from_revision_id' => $public_r, 'to_revision_id' => $revision]));
        $this->assertTrue($this->permits('wpmcp/get-revision', ['revision_id' => $public_r]));

        wp_set_current_user($this->role_users['administrator']);
        $this->assertTrue($this->permits('wpmcp/get-revision', ['revision_id' => $revision]));
    }

    public function test_the_filter_opens_a_hidden_type_to_its_capability_holders(): void
    {
        wp_set_current_user($this->role_users['contributor']);
        $this->assertFalse($this->permits('wpmcp/list-posts', ['post_type' => self::ENROLLMENT]));

        add_filter('wpmcp_agent_readable_hidden_post_types', static fn (array $types) => array_merge($types, [self::ENROLLMENT, self::ORDER]));

        // The enrollment type declares plain post capabilities, which a
        // Contributor holds; the order type's own capabilities still apply.
        $this->assertTrue($this->permits('wpmcp/list-posts', ['post_type' => self::ENROLLMENT]));
        $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $this->hidden_rows[ self::ENROLLMENT ]]));
        $this->assertFalse($this->permits('wpmcp/list-posts', ['post_type' => self::ORDER]));
    }

    public function test_rows_of_an_unregistered_type_are_refused_below_administrator(): void
    {
        $orphan = $this->hidden_rows[ self::ENROLLMENT ];
        unregister_post_type(self::ENROLLMENT);

        wp_set_current_user($this->role_users['editor']);
        $this->assertFalse($this->permits('wpmcp/get-post', ['post_id' => $orphan]));

        wp_set_current_user($this->role_users['administrator']);
        $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $orphan]));
    }

    public function test_search_content_hides_rows_the_caller_may_not_read(): void
    {
        add_filter('wpmcp_search_indexable_post_types', static fn (array $types) => array_merge($types, [self::ENTRY]));
        Content_Indexer::index_post($this->hidden_rows[ self::ENTRY ]);

        wp_set_current_user($this->role_users['contributor']);
        $this->assertFalse($this->permits('wpmcp/search-content', ['query' => 'private record', 'post_types' => [self::ENTRY]]));
        $out = (new Search_Content())->handle(['query' => 'private record']);
        $this->assertNotContains($this->hidden_rows[ self::ENTRY ], array_column($out['results'], 'object_id'));

        wp_set_current_user($this->role_users['administrator']);
        $out = (new Search_Content())->handle(['query' => 'private record']);
        $this->assertContains($this->hidden_rows[ self::ENTRY ], array_column($out['results'], 'object_id'));
    }

    public function test_the_governed_path_returns_an_error_to_a_contributor(): void
    {
        wp_set_current_user($this->role_users['contributor']);
        $ability = wp_get_ability('wpmcp/get-post');
        $this->assertNotNull($ability);
        $result = $ability->execute(['post_id' => $this->hidden_rows[ self::ORDER ]]);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }
}
