<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Core_Patterns_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #952: the synced pattern ops live on the theme pair, but they are
 * gated by the wp_block post type's own capabilities, not the theme's
 * edit_theme_options. An Author or Editor reaches them through the MCP
 * ability; every other theme op still needs edit_theme_options on top of
 * its own capability.
 */
class CorePatternsAbilityCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(false);
    }

    protected function tearDown(): void
    {
        remove_all_filters(Core_Patterns_Pack::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function as_role(string $role): int
    {
        $user = self::factory()->user->create([ 'role' => $role ]);
        wp_set_current_user($user);
        return $user;
    }

    /** @return array<string, mixed>|\WP_Error */
    private function call(string $half, string $op, array $args = [])
    {
        $ability = wp_get_ability('wpmcp/theme-' . $half);
        $this->assertNotNull($ability, 'wpmcp/theme-' . $half . ' is registered');
        return $ability->execute([ 'operation' => $op, 'args' => (object) $args ]);
    }

    /** The error code of a call: a permission denial, or the dispatcher's own refusal. */
    private function code($out): string
    {
        if ($out instanceof \WP_Error) {
            return 'denied:' . $out->get_error_code();
        }
        return (string) ($out['error']['code'] ?? '');
    }

    private function ref(int $id): string
    {
        return '<!-- wp:block {"ref":' . $id . '} /-->';
    }

    private function pattern(): int
    {
        return self::factory()->post->create([ 'post_type' => 'wp_block', 'post_title' => 'Promo banner', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ]);
    }

    private function page_by(int $author, int $pattern): int
    {
        return self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $author, 'post_status' => 'publish', 'post_content' => $this->ref($pattern) ]);
    }

    private function draft_by(int $author, int $pattern): int
    {
        return self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $author, 'post_status' => 'draft', 'post_content' => $this->ref($pattern) ]);
    }

    public function test_an_author_runs_find_pattern_usage_through_the_ability(): void
    {
        $admin   = self::factory()->user->create([ 'role' => 'administrator' ]);
        $pattern = $this->pattern();
        $this->draft_by($admin, $pattern);
        $this->draft_by($admin, $pattern);
        $author = $this->as_role('author');
        $this->page_by($author, $pattern);

        $out = $this->call('read', 'find-pattern-usage', [ 'id' => $pattern ]);

        $this->assertIsArray($out, $out instanceof \WP_Error ? $out->get_error_message() : '');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame(3, $out['result']['total']);
        $this->assertSame(1, $out['result']['readable']);
    }

    public function test_an_author_lists_and_reads_patterns_through_the_ability(): void
    {
        $pattern = $this->pattern();
        $this->as_role('author');

        $this->assertSame('', $this->code($this->call('read', 'list-synced-patterns')));
        $this->assertSame('', $this->code($this->call('read', 'get-synced-pattern', [ 'id' => $pattern ])));
    }

    public function test_an_author_creates_a_pattern_and_is_refused_one_placed_on_pages_they_cannot_edit(): void
    {
        add_filter(Core_Patterns_Pack::ENABLE_FILTER, '__return_true');
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->as_role('author');

        $out = $this->call('write', 'create-synced-pattern', [ 'title' => 'Mine', 'content' => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' ]);
        $this->assertSame('', $this->code($out), (string) wp_json_encode($out));
        $mine = (int) $out['result']['pattern']['id'];
        self::factory()->post->create([ 'post_author' => get_current_user_id(), 'post_status' => 'publish', 'post_content' => $this->ref($mine) ]);
        $this->assertSame('', $this->code($this->call('write', 'update-synced-pattern', [ 'id' => $mine, 'title' => 'Mine 2' ])));

        $this->page_by($admin, $mine);
        $this->assertSame('pattern_in_use_elsewhere', $this->code($this->call('write', 'update-synced-pattern', [ 'id' => $mine, 'title' => 'Mine 3' ])));
        $this->assertSame('Mine 2', get_post($mine)->post_title);
    }

    public function test_an_editor_updates_a_pattern_placed_on_other_users_pages_through_the_ability(): void
    {
        add_filter(Core_Patterns_Pack::ENABLE_FILTER, '__return_true');
        $admin   = self::factory()->user->create([ 'role' => 'administrator' ]);
        $pattern = $this->pattern();
        $this->page_by($admin, $pattern);
        $this->as_role('editor');

        $out = $this->call('write', 'update-synced-pattern', [ 'id' => $pattern, 'title' => 'Editor edit' ]);

        $this->assertSame('', $this->code($out), (string) wp_json_encode($out));
        $this->assertSame('Editor edit', get_post($pattern)->post_title);
    }

    public function test_pattern_ops_take_the_wp_block_post_type_capabilities(): void
    {
        $caps = get_post_type_object('wp_block')->cap;
        $ops  = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        foreach ([ 'list-synced-patterns', 'get-synced-pattern', 'find-pattern-usage', 'update-synced-pattern', 'detach-pattern-instance' ] as $op) {
            $this->assertSame($caps->edit_posts, $ops[ $op ]['capability'], $op);
        }
        $this->assertSame($caps->create_posts, $ops['create-synced-pattern']['capability']);
    }

    public function test_every_other_theme_op_still_needs_edit_theme_options(): void
    {
        $this->as_role('editor');

        $this->assertSame('operation_denied', $this->code($this->call('read', 'get-mods')));
        $this->assertSame('operation_denied', $this->code($this->call('read', 'get-theme-context')));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertSame('operation_denied', $this->code($this->call('write', 'set-mods', [ 'values' => [ 'header_textcolor' => '000000' ] ])));
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertFalse(get_theme_mod('header_textcolor'));
    }

    public function test_an_op_with_its_own_capability_still_needs_edit_theme_options_too(): void
    {
        // A role with manage_options but no edit_theme_options reached no
        // theme op before; lowering the pair floor must not change that.
        add_role('wpmcp_options_only', 'Options only', [ 'read' => true, 'edit_posts' => true, 'manage_options' => true ]);
        $user = self::factory()->user->create([ 'role' => 'wpmcp_options_only' ]);
        wp_set_current_user($user);

        $ops = array_filter(
            (new Theme_Integration())->catalog()['operations'],
            static fn (array $op): bool => 'manage_options' === $op['capability'] && 'read' === $op['mode']
        );
        $this->assertNotEmpty($ops);
        $op = reset($ops)['name'];

        $this->assertSame('operation_denied', $this->code($this->call('read', $op)), $op);
        remove_role('wpmcp_options_only');
    }

    public function test_an_administrator_keeps_every_theme_op(): void
    {
        $this->as_role('administrator');

        $this->assertSame('', $this->code($this->call('read', 'get-mods')));
        $this->assertSame('', $this->code($this->call('read', 'get-theme-context')));
    }

    public function test_a_subscriber_still_cannot_reach_the_theme_pair(): void
    {
        $this->as_role('subscriber');

        $this->assertStringStartsWith('denied:', $this->code($this->call('read', 'list-synced-patterns')));
    }
}
