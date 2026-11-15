<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Core_Patterns_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * WordPress core synced patterns (issue #711) as free ops on the theme
 * dispatcher pair: reads, and snapshotted create, update and detach writes
 * that stay off until the site opts in.
 */
class CorePatternsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-synced-patterns'    => 'read',
        'get-synced-pattern'      => 'read',
        'find-pattern-usage'      => 'read',
        'create-synced-pattern'   => 'write',
        'update-synced-pattern'   => 'write',
        'detach-pattern-instance' => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters(Core_Patterns_Pack::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function enable_writes(): void
    {
        add_filter(Core_Patterns_Pack::ENABLE_FILTER, '__return_true');
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function pattern(string $content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', bool $synced = true, string $title = 'Promo'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'wp_block', 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $content ]);
        if (! $synced) {
            update_post_meta($id, 'wp_pattern_sync_status', 'unsynced');
        }
        return $id;
    }

    private function ref(int $id): string
    {
        return '<!-- wp:block {"ref":' . $id . '} /-->';
    }

    private function page(string $content, string $status = 'publish'): int
    {
        return self::factory()->post->create([ 'post_type' => 'page', 'post_status' => $status, 'post_title' => 'P', 'post_content' => $content ]);
    }

    public function test_every_op_is_a_free_op_on_the_theme_pair_and_writes_are_off_until_opt_in(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, $name);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertSame('read' === $mode, $ops[ $name ]['enabled'], $name);
        }
        $out = $this->write('create-synced-pattern', [ 'title' => 'X' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString(Core_Patterns_Pack::ENABLE_FILTER, $out['error']['message']);

        $this->enable_writes();
        foreach (array_keys(self::OPS) as $name) {
            $this->assertTrue($this->catalog()[ $name ]['enabled'], $name);
        }
    }

    public function test_list_synced_patterns_filters_by_sync_state(): void
    {
        $synced   = $this->pattern('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->', true, 'Synced one');
        $unsynced = $this->pattern('<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->', false, 'Unsynced one');

        $ids = array_column($this->read('list-synced-patterns')['result']['patterns'], 'id');
        $this->assertContains($synced, $ids);
        $this->assertNotContains($unsynced, $ids);

        $ids = array_column($this->read('list-synced-patterns', [ 'sync' => 'unsynced' ])['result']['patterns'], 'id');
        $this->assertSame([ $unsynced ], $ids);

        $ids = array_column($this->read('list-synced-patterns', [ 'sync' => 'all' ])['result']['patterns'], 'id');
        $this->assertContains($synced, $ids);
        $this->assertContains($unsynced, $ids);
    }

    public function test_get_synced_pattern_returns_content_hash_and_usage_count_and_refuses_other_types(): void
    {
        $id = $this->pattern();
        $this->page($this->ref($id));

        $out = $this->read('get-synced-pattern', [ 'id' => $id ])['result'];
        $this->assertSame('Promo', $out['title']);
        $this->assertTrue($out['synced']);
        $this->assertSame(hash('sha256', $out['content']), $out['content_hash']);
        $this->assertSame(1, $out['used_in']);

        $this->assertSame('pattern_not_found', $this->code($this->read('get-synced-pattern', [ 'id' => $this->page('x') ])));
    }

    public function test_find_pattern_usage_counts_nested_placements_and_ignores_a_longer_id(): void
    {
        $id    = $this->pattern();
        $other = (int) ($id . '0');
        $flat  = $this->page($this->ref($id) . $this->ref($id));
        $deep  = $this->page('<!-- wp:group --><div class="wp-block-group">' . $this->ref($id) . '</div><!-- /wp:group -->');
        $none  = $this->page('<!-- wp:block {"ref":' . $other . '} /-->');

        $usage = array_column($this->read('find-pattern-usage', [ 'id' => $id ])['result']['usage'], 'placements', 'id');
        $this->assertSame([ $flat => 2, $deep => 1 ], $usage);
        $this->assertArrayNotHasKey($none, $usage);
    }

    public function test_find_pattern_usage_hides_other_users_drafts_from_an_author(): void
    {
        $id    = $this->pattern();
        $draft = $this->page($this->ref($id), 'draft');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));

        $usage = $this->read('find-pattern-usage', [ 'id' => $id ]);
        $this->assertNotContains($draft, array_column($usage['result']['usage'] ?? [], 'id'));
    }

    public function test_create_synced_pattern_is_recorded_and_undone_by_trashing_it(): void
    {
        $this->enable_writes();
        $out = $this->write('create-synced-pattern', [ 'title' => 'New one', 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $id = $out['result']['pattern']['id'];
        $this->assertSame('wp_block', get_post_type($id));
        $this->assertTrue($out['result']['pattern']['synced']);
        $this->assertSame('publish', get_post_status($id));

        (new Rollback_Session())->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_can_make_an_unsynced_draft_and_refuses_an_empty_title(): void
    {
        $this->enable_writes();
        $out = $this->write('create-synced-pattern', [ 'title' => 'Loose', 'synced' => false, 'status' => 'draft' ]);
        $this->assertFalse($out['result']['pattern']['synced']);
        $this->assertSame('draft', get_post_status($out['result']['pattern']['id']));

        $this->assertSame('invalid_title', $this->code($this->write('create-synced-pattern', [ 'title' => '  ' ])));
    }

    public function test_update_synced_pattern_is_snapshotted_and_undone(): void
    {
        $this->enable_writes();
        $id = $this->pattern();

        $out = $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'Renamed', 'content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->', 'synced' => false ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Renamed', get_post($id)->post_title);
        $this->assertSame('unsynced', get_post_meta($id, 'wp_pattern_sync_status', true));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        clean_post_cache($id);
        $this->assertSame('Promo', get_post($id)->post_title);
        $this->assertStringContainsString('Hello', get_post($id)->post_content);
        $this->assertNotSame('unsynced', get_post_meta($id, 'wp_pattern_sync_status', true));
    }

    public function test_update_refuses_a_stale_hash_a_non_pattern_and_an_empty_change(): void
    {
        $this->enable_writes();
        $id = $this->pattern();

        $this->assertSame('stale_hash', $this->code($this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'x', 'expected_hash' => 'nope' ])));
        $this->assertSame('pattern_not_found', $this->code($this->write('update-synced-pattern', [ 'id' => $this->page('x'), 'title' => 'x' ])));
        $this->assertSame('nothing_to_change', $this->code($this->write('update-synced-pattern', [ 'id' => $id ])));
        $this->assertSame('Promo', get_post($id)->post_title);

        $hash = hash('sha256', get_post($id)->post_content);
        $this->assertArrayNotHasKey('error', $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'ok', 'expected_hash' => $hash ]));
    }

    public function test_detach_replaces_placements_with_the_pattern_blocks_and_every_post_is_undone(): void
    {
        $this->enable_writes();
        $id    = $this->pattern('<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->');
        $flat  = $this->page('<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' . $this->ref($id));
        $inner = '<!-- wp:group --><div class="wp-block-group">' . $this->ref($id) . '</div><!-- /wp:group -->';
        $deep  = $this->page($inner);
        $skip  = $this->page('<!-- wp:paragraph --><p>Nothing</p><!-- /wp:paragraph -->');

        $out = $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $flat, $deep, $skip ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertCount(2, $out['operation_ids']);
        $this->assertSame([ $skip ], $out['result']['skipped']);
        foreach ([ $flat, $deep ] as $post_id) {
            $content = get_post($post_id)->post_content;
            $this->assertStringNotContainsString('wp:block', $content);
            $this->assertStringContainsString('One', $content);
            $this->assertStringContainsString('Two', $content);
        }
        $this->assertSame(serialize_blocks(parse_blocks(get_post($deep)->post_content)), get_post($deep)->post_content, 'the rewritten markup is valid block markup');
        $this->assertCount(2, parse_blocks(get_post($deep)->post_content)[0]['innerBlocks']);
        $this->assertSame([], $this->read('find-pattern-usage', [ 'id' => $id ])['result']['usage']);
        $this->assertSame($id, (int) get_post($id)->ID, 'the pattern itself is untouched');

        (new Rollback_Session())->handle([ 'session_id' => $out['session_id'] ]);
        clean_post_cache($flat);
        clean_post_cache($deep);
        $this->assertStringContainsString($this->ref($id), get_post($flat)->post_content);
        $this->assertSame($inner, get_post($deep)->post_content);
    }

    public function test_detach_refuses_before_writing_when_a_post_is_missing_unreadable_or_has_no_placement(): void
    {
        $this->enable_writes();
        $id   = $this->pattern();
        $good = $this->page($this->ref($id));

        $this->assertSame('post_not_found', $this->code($this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $good, 999999 ] ])));
        $this->assertSame('no_placements', $this->code($this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $this->page('x') ] ])));
        $this->assertSame('pattern_not_found', $this->code($this->write('detach-pattern-instance', [ 'pattern_id' => $good, 'post_ids' => [ $good ] ])));
        $this->assertStringContainsString($this->ref($id), get_post($good)->post_content, 'a refused call writes nothing');
    }

    public function test_detach_refuses_a_post_that_does_not_round_trip(): void
    {
        $this->enable_writes();
        $id  = $this->pattern();
        $bad = $this->page('x');
        // Written straight to the row: a multisite admin has no unfiltered_html, so kses would normalize it.
        global $wpdb;
        $wpdb->update($wpdb->posts, [ 'post_content' => '<!-- wp:paragraph {"align": "left"} --><p class="has-text-align-left">x</p><!-- /wp:paragraph -->' . $this->ref($id) ], [ 'ID' => $bad ]);
        clean_post_cache($bad);
        $this->assertNotSame(serialize_blocks(parse_blocks(get_post($bad)->post_content)), get_post($bad)->post_content, 'the sample must not round-trip');

        $this->assertSame('content_not_round_trippable', $this->code($this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $bad ] ])));
    }

    public function test_detach_needs_the_capability_to_edit_each_post(): void
    {
        $this->enable_writes();
        $id   = $this->pattern();
        $page = $this->page($this->ref($id));
        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));

        $out = $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $page ] ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString($this->ref($id), get_post($page)->post_content);
    }
}
