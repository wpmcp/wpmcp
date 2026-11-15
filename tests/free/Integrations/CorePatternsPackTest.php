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

    private function as_role(string $role): int
    {
        $user = self::factory()->user->create([ 'role' => $role ]);
        wp_set_current_user($user);
        return $user;
    }

    private function admin_page_placing(int $pattern_id): int
    {
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        return self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $admin, 'post_status' => 'publish', 'post_content' => $this->ref($pattern_id) ]);
    }

    public function test_author_can_create_and_update_a_pattern_nobody_else_places(): void
    {
        $this->enable_writes();
        $this->as_role('author');
        $out = $this->write('create-synced-pattern', [ 'title' => 'Mine', 'content' => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' ]);
        $this->assertArrayNotHasKey('error', $out);
        $id = $out['result']['pattern']['id'];

        $this->assertArrayNotHasKey('error', $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'Mine 2' ]));
        $own = self::factory()->post->create([ 'post_type' => 'post', 'post_author' => get_current_user_id(), 'post_status' => 'publish', 'post_content' => $this->ref($id) ]);
        $r = $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'Mine 3' ]);
        $this->assertArrayNotHasKey('error', $r, 'a pattern placed only in posts the author can edit: ' . wp_json_encode($r));
        $this->assertGreaterThan(0, $own);
    }

    public function test_author_cannot_update_a_pattern_an_admin_placed_in_a_page_and_nothing_changes(): void
    {
        $this->enable_writes();
        $this->as_role('author');
        $id   = $this->write('create-synced-pattern', [ 'title' => 'Mine', 'content' => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' ])['result']['pattern']['id'];
        $page = $this->admin_page_placing($id);

        $out = $this->write('update-synced-pattern', [ 'id' => $id, 'content' => '<!-- wp:paragraph --><p>defaced</p><!-- /wp:paragraph -->' ]);

        $this->assertSame('pattern_in_use_elsewhere', $this->code($out));
        $this->assertStringContainsString((string) $page, $out['error']['message']);
        $this->assertStringContainsString('>a<', get_post($id)->post_content);
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'a refused write snapshots nothing');
    }

    public function test_an_editor_may_update_a_pattern_placed_in_posts_they_cannot_edit_one_by_one(): void
    {
        $this->enable_writes();
        $id = $this->pattern();
        $this->admin_page_placing($id);
        $this->as_role('editor');

        $this->assertArrayNotHasKey('error', $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'Editor edit' ]), 'edit_others_posts covers every placing post');
    }

    public function test_contributor_cannot_create_or_update_a_pattern(): void
    {
        $this->enable_writes();
        $this->as_role('contributor');
        $this->assertSame('forbidden', $this->code($this->write('create-synced-pattern', [ 'title' => 'Pub' ])), 'core gives contributors no create_posts on patterns');
        $this->assertSame('forbidden', $this->code($this->write('create-synced-pattern', [ 'title' => 'Draft', 'status' => 'draft' ])));
        $published = $this->pattern();
        $this->assertArrayHasKey('error', $this->write('update-synced-pattern', [ 'id' => $published, 'title' => 'x' ]));
        $this->assertSame('Promo', get_post($published)->post_title);
    }

    public function test_author_cannot_roll_back_an_update_once_an_admin_placed_the_pattern(): void
    {
        $this->enable_writes();
        $this->as_role('author');
        $id  = $this->write('create-synced-pattern', [ 'title' => 'Mine', 'content' => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->' ])['result']['pattern']['id'];
        $out = $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'Changed' ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->admin_page_placing($id);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        clean_post_cache($id);
        $this->assertSame('Changed', get_post($id)->post_title, 'the restore was refused');
    }

    public function test_author_cannot_undo_a_create_once_an_admin_placed_the_pattern(): void
    {
        $this->enable_writes();
        $this->as_role('author');
        $made = $this->write('create-synced-pattern', [ 'title' => 'Mine' ]);
        $id   = $made['result']['pattern']['id'];
        $this->admin_page_placing($id);

        (new Rollback_Session())->handle([ 'session_id' => $made['session_id'] ]);

        $this->assertSame('publish', get_post_status($id), 'trashing it would blank the admin\'s page');
    }

    public function test_an_admin_can_roll_back_the_update_even_when_the_pattern_is_placed_elsewhere(): void
    {
        $this->enable_writes();
        $id = $this->pattern();
        $this->admin_page_placing($id);
        $out = $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'Changed' ]);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        clean_post_cache($id);
        $this->assertSame('Promo', get_post($id)->post_title);
    }

    public function test_author_detaches_in_their_own_post_but_not_in_an_admins_and_a_contributor_in_neither(): void
    {
        $this->enable_writes();
        $id    = $this->pattern();
        $admin = $this->admin_page_placing($id);
        $this->as_role('author');
        $mine = self::factory()->post->create([ 'post_type' => 'post', 'post_author' => get_current_user_id(), 'post_status' => 'publish', 'post_content' => $this->ref($id) ]);

        $this->assertSame('forbidden', $this->code($this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $mine, $admin ] ])));
        $this->assertStringContainsString($this->ref($id), get_post($mine)->post_content, 'a refused call writes nothing');
        $r = $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $mine ] ]);
        $this->assertArrayNotHasKey('error', $r, wp_json_encode($r));
        $this->assertStringNotContainsString('wp:block', get_post($mine)->post_content);

        $this->as_role('contributor');
        $this->assertArrayHasKey('error', $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $admin ] ]));
    }

    public function test_a_detach_that_fails_part_way_puts_the_earlier_posts_back(): void
    {
        $this->enable_writes();
        $id     = $this->pattern();
        $first  = $this->page($this->ref($id));
        $second = $this->page($this->ref($id));
        $before = get_post($first)->post_content;
        $once   = true;
        $tamper = static function (array $data, array $post) use ($second, &$once): array {
            if ($once && (int) ($post['ID'] ?? 0) === $second) {
                $once = false;
                return array_merge($data, [ 'post_content' => 'tampered' ]);
            }
            return $data;
        };
        add_filter('wp_insert_post_data', $tamper, 10, 2);

        $out = $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'post_ids' => [ $first, $second ] ]);
        remove_filter('wp_insert_post_data', $tamper, 10);

        $this->assertSame('detach_failed', $this->code($out));
        $this->assertArrayHasKey('session_id', $out['error']['data']);
        clean_post_cache($first);
        clean_post_cache($second);
        $this->assertSame($before, get_post($first)->post_content, 'the earlier post was put back');
        $this->assertStringContainsString($this->ref($id), get_post($second)->post_content);
    }

    public function test_usage_reports_totals_and_hides_unreadable_posts_from_the_readable_count(): void
    {
        $id = $this->pattern();
        $this->page($this->ref($id));
        $this->page($this->ref($id), 'draft');
        $this->as_role('author');

        $out = $this->read('find-pattern-usage', [ 'id' => $id ])['result'];
        $this->assertSame(2, $out['total']);
        $this->assertSame(1, $out['readable']);
        $this->assertFalse($out['truncated']);
    }

    public function test_usage_is_flagged_truncated_past_the_scan_limit_and_detach_all_refuses_over_fifty(): void
    {
        $this->enable_writes();
        $id = $this->pattern();
        foreach (range(1, 52) as $i) {
            $this->page($this->ref($id));
        }
        $out = $this->read('find-pattern-usage', [ 'id' => $id ])['result'];
        $this->assertSame(52, $out['total']);
        $this->assertFalse($out['truncated']);

        $refused = $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'all' => true ]);
        $this->assertSame('too_many_posts', $this->code($refused));
        $this->assertStringContainsString('52', $refused['error']['message']);
        $this->assertCount(52, $this->read('find-pattern-usage', [ 'id' => $id ])['result']['usage'], 'nothing was written');
    }

    public function test_detach_all_covers_every_placing_post_when_under_the_limit(): void
    {
        $this->enable_writes();
        $id = $this->pattern();
        $a  = $this->page($this->ref($id));
        $b  = $this->page($this->ref($id));

        $out = $this->write('detach-pattern-instance', [ 'pattern_id' => $id, 'all' => true ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertCount(2, $out['operation_ids']);
        $this->assertStringNotContainsString('wp:block', get_post($a)->post_content . get_post($b)->post_content);
        $this->assertSame('no_posts', $this->code($this->write('detach-pattern-instance', [ 'pattern_id' => $id ])));
    }

    public function test_a_pattern_placed_in_a_block_widget_needs_edit_theme_options_to_change(): void
    {
        $this->enable_writes();
        $this->as_role('author');
        $id = $this->write('create-synced-pattern', [ 'title' => 'Mine' ])['result']['pattern']['id'];
        update_option('widget_block', [ 2 => [ 'content' => $this->ref($id) ], '_multiwidget' => 1 ]);

        $this->assertSame('pattern_in_use_elsewhere', $this->code($this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'x' ])));
        $this->assertSame(1, $this->read('find-pattern-usage', [ 'id' => $id ])['result']['widget_placements']);

        $this->as_role('editor');
        $this->assertSame('pattern_in_use_elsewhere', $this->code($this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'x' ])), 'an editor has no edit_theme_options');
        $this->as_role('administrator');
        $this->assertArrayNotHasKey('error', $this->write('update-synced-pattern', [ 'id' => $id, 'title' => 'x' ]));
        delete_option('widget_block');
    }
}
