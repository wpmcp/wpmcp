<?php

namespace WPMCP\Tests\Free\Migration;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Migration\Find_Replace_Content;
use WPMCP\Tools\Rollback_Session;

/**
 * Site-wide find-and-replace over post content, titles, excerpts and
 * selected post meta. The contract under test: a dry run writes nothing, an
 * applied pass is one rollback-session away from the exact prior state,
 * serialized and JSON meta never come out corrupted, builder-managed data is
 * skipped and reported, and the regex, cap and confirmation guards refuse
 * before anything is written.
 */
class FindReplaceContentTest extends \WP_UnitTestCase
{
    private Find_Replace_Content $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new Find_Replace_Content();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
    }

    private function post(string $content, array $extra = []): int
    {
        return (int) self::factory()->post->create(array_merge([
            'post_content' => wp_slash($content),
            'post_status'  => 'publish',
        ], $extra));
    }

    /** @return array{0:string,1:string,2:string} */
    private function fields(int $id): array
    {
        clean_post_cache($id);
        $p = get_post($id);
        return [(string) $p->post_title, (string) $p->post_content, (string) $p->post_excerpt];
    }

    private function raw_meta(int $id, string $key): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
            $id,
            $key
        ));
    }

    public function test_refuses_empty_search(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['search' => '', 'replace' => 'x']);
    }

    public function test_refuses_a_no_op_replacement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['search' => 'same', 'replace' => 'same']);
    }

    public function test_refuses_protected_meta_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('_elementor_data');
        $this->tool->handle(['search' => 'a', 'replace' => 'b', 'meta_keys' => ['_elementor_data']]);
    }

    public function test_refuses_internal_post_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['search' => 'a', 'replace' => 'b', 'post_types' => ['wp_template']]);
    }

    public function test_refuses_unknown_fields_and_statuses(): void
    {
        foreach ([['fields' => ['guid']], ['statuses' => ['trash']]] as $bad) {
            try {
                $this->tool->handle(array_merge(['search' => 'a', 'replace' => 'b'], $bad));
                $this->fail('Expected refusal for ' . wp_json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $a = $this->post('Visit Acme Corp today. Acme Corp is great.', ['post_title' => 'About Acme Corp']);
        $b = $this->post('Nothing to see.');
        $before_a = $this->fields($a);
        $before_b = $this->fields($b);
        $rows     = Snapshot_Store::row_count();

        $out = $this->tool->handle([
            'search'  => 'Acme Corp',
            'replace' => 'Acme Inc',
            'fields'  => ['content', 'title'],
        ]);

        $this->assertTrue($out['dry_run']);
        $this->assertSame(3, $out['total_matches']);
        $this->assertSame(1, $out['posts_matched']);
        $this->assertCount(1, $out['matches']);
        $this->assertSame($a, $out['matches'][0]['post_id']);
        $this->assertSame(3, $out['matches'][0]['count']);
        $this->assertSame(['content' => 2, 'title' => 1], $out['matches'][0]['fields']);
        $this->assertNotEmpty($out['matches'][0]['snippets']);
        $this->assertStringContainsString('Acme Corp', $out['matches'][0]['snippets'][0]);
        $this->assertArrayNotHasKey('session_id', $out);

        $this->assertSame($before_a, $this->fields($a));
        $this->assertSame($before_b, $this->fields($b));
        $this->assertSame($rows, Snapshot_Store::row_count(), 'A dry run must not take snapshots');
    }

    public function test_string_false_dry_run_stays_a_dry_run(): void
    {
        $a      = $this->post('Acme Corp');
        $before = $this->fields($a);

        $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'dry_run' => 'false']);

        $this->assertTrue($out['dry_run']);
        $this->assertSame($before, $this->fields($a));
    }

    public function test_apply_then_rollback_session_restores_every_post_exactly(): void
    {
        $ids = [
            $this->post('Acme Corp builds <strong>Acme Corp</strong> things.', ['post_title' => 'Acme Corp', 'post_excerpt' => 'By Acme Corp']),
            $this->post('<!-- wp:paragraph {"className":"x"} --><p>Acme Corp \\u003c slash \\\\ kept</p><!-- /wp:paragraph -->'),
            $this->post('Plain Acme Corp.', ['post_status' => 'draft', 'post_type' => 'page']),
        ];
        $untouched = $this->post('No match here.');
        $before    = array_combine($ids, array_map([$this, 'fields'], $ids));
        $before_u  = $this->fields($untouched);

        $out = $this->tool->handle([
            'search'     => 'Acme Corp',
            'replace'    => 'Acme Inc',
            'fields'     => ['content', 'title', 'excerpt'],
            'post_types' => ['post', 'page'],
            'dry_run'    => false,
        ]);

        $this->assertFalse($out['dry_run']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['session_id']);
        $this->assertCount(3, $out['applied']);
        $this->assertSame([], $out['failed']);

        foreach ($ids as $id) {
            [$title, $content, $excerpt] = $this->fields($id);
            $this->assertStringNotContainsString('Acme Corp', $title . $content . $excerpt);
        }
        $this->assertStringContainsString('Acme Inc \\u003c slash \\\\ kept', $this->fields($ids[1])[1]);
        $this->assertSame($before_u, $this->fields($untouched));

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);

        foreach ($ids as $id) {
            $this->assertSame($before[ $id ], $this->fields($id), "Post {$id} must be restored exactly");
        }
    }

    public function test_serialized_meta_stays_valid_and_rolls_back(): void
    {
        $id  = $this->post('body');
        $val = ['label' => 'Acme Corp', 'nested' => ['Acme Corp HQ', 7], 'Acme Corp' => true];
        update_post_meta($id, 'company_info', $val);
        $raw_before = $this->raw_meta($id, 'company_info');

        $out = $this->tool->handle([
            'search'    => 'Acme Corp',
            'replace'   => 'Acme International',
            'fields'    => ['meta'],
            'meta_keys' => ['company_info'],
            'dry_run'   => false,
        ]);

        $this->assertCount(1, $out['applied']);
        $raw_after = $this->raw_meta($id, 'company_info');
        $this->assertTrue(is_serialized($raw_after));
        $decoded = unserialize($raw_after, ['allowed_classes' => false]);
        $this->assertSame('Acme International', $decoded['label']);
        $this->assertSame(['Acme International HQ', 7], $decoded['nested']);
        $this->assertArrayHasKey('Acme Corp', $decoded, 'Array keys are never rewritten');
        wp_cache_delete($id, 'post_meta');
        $this->assertSame('Acme International', get_post_meta($id, 'company_info', true)['label']);

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
        $this->assertSame($raw_before, $this->raw_meta($id, 'company_info'));
    }

    public function test_serialized_meta_holding_an_object_is_skipped_and_reported(): void
    {
        global $wpdb;
        $id = $this->post('body');
        $wpdb->insert($wpdb->postmeta, [
            'post_id'    => $id,
            'meta_key'   => 'obj_meta',
            'meta_value' => 'O:8:"stdClass":1:{s:4:"name";s:9:"Acme Corp";}',
        ]);
        $raw = $this->raw_meta($id, 'obj_meta');

        $out = $this->tool->handle([
            'search'    => 'Acme Corp',
            'replace'   => 'X',
            'fields'    => ['meta'],
            'meta_keys' => ['obj_meta'],
            'dry_run'   => false,
        ]);

        $this->assertSame([], $out['applied']);
        $this->assertSame('meta:obj_meta', $out['skipped'][0]['field']);
        $this->assertSame($raw, $this->raw_meta($id, 'obj_meta'));
    }

    public function test_json_meta_stays_valid_json_or_is_skipped(): void
    {
        $ok  = $this->post('a');
        $bad = $this->post('b');
        update_post_meta($ok, 'layout_json', wp_slash('{"title":"Acme Corp","n":1}'));
        update_post_meta($bad, 'layout_json', wp_slash('{"title":"Acme Corp"}'));

        $out = $this->tool->handle([
            'search'    => 'Acme Corp',
            'replace'   => 'Acme "Inc"',
            'fields'    => ['meta'],
            'meta_keys' => ['layout_json'],
            'post_ids'  => [$bad],
            'dry_run'   => false,
        ]);
        $this->assertSame([], $out['applied']);
        $this->assertSame($bad, $out['skipped'][0]['post_id']);
        $this->assertStringContainsString('JSON', $out['skipped'][0]['reason']);
        $this->assertSame('{"title":"Acme Corp"}', $this->raw_meta($bad, 'layout_json'));

        $out = $this->tool->handle([
            'search'    => 'Acme Corp',
            'replace'   => 'Acme Inc',
            'fields'    => ['meta'],
            'meta_keys' => ['layout_json'],
            'post_ids'  => [$ok],
            'dry_run'   => false,
        ]);
        $this->assertCount(1, $out['applied']);
        $this->assertSame(['title' => 'Acme Inc', 'n' => 1], json_decode($this->raw_meta($ok, 'layout_json'), true));
    }

    public function test_block_attribute_json_is_never_broken(): void
    {
        $id     = $this->post('<!-- wp:acme/box {"label":"Acme Corp"} --><div>Acme Corp</div><!-- /wp:acme/box -->');
        $before = $this->fields($id);

        $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'Acme "Inc"', 'dry_run' => false]);

        $this->assertSame([], $out['applied']);
        $this->assertSame('content', $out['skipped'][0]['field']);
        $this->assertSame($before, $this->fields($id));
    }

    public function test_builder_managed_content_is_skipped_and_reported(): void
    {
        $id = $this->post('Acme Corp rendered by the builder');
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        $before = $this->fields($id);

        $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'dry_run' => false]);

        $this->assertSame([], $out['applied']);
        $this->assertSame($id, $out['skipped'][0]['post_id']);
        $this->assertStringContainsString('builder', $out['skipped'][0]['reason']);
        $this->assertSame($before, $this->fields($id));
    }

    public function test_case_insensitive_match(): void
    {
        $id = $this->post('acme corp and ACME CORP and Acme Corp');

        $sensitive = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'Z', 'post_ids' => [$id]]);
        $this->assertSame(1, $sensitive['total_matches']);

        $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'Z', 'case_sensitive' => false, 'post_ids' => [$id], 'dry_run' => false]);
        $this->assertSame(3, $out['total_matches']);
        $this->assertSame('Z and Z and Z', $this->fields($id)[1]);
    }

    public function test_plain_search_treats_regex_metacharacters_literally(): void
    {
        $id = $this->post('Price: $5.00 (a+b) $1');

        $this->tool->handle(['search' => '$5.00 (a+b)', 'replace' => '$1 \\0', 'post_ids' => [$id], 'dry_run' => false]);

        $this->assertSame('Price: $1 \\0 $1', $this->fields($id)[1]);
    }

    public function test_regex_mode_with_backreferences(): void
    {
        $id = $this->post('Call 555-1234 or 555-9876.');

        $out = $this->tool->handle([
            'search'   => '(\d{3})-(\d{4})',
            'replace'  => '$1.$2',
            'regex'    => true,
            'post_ids' => [$id],
            'dry_run'  => false,
        ]);

        $this->assertSame(2, $out['total_matches']);
        $this->assertSame('Call 555.1234 or 555.9876.', $this->fields($id)[1]);
    }

    public function test_regex_guard_refuses_dangerous_or_invalid_patterns(): void
    {
        $cases = [
            'invalid'           => '(unclosed',
            'nested quantifier' => '(a+)+b',
            'nested star'       => '(\w+\s?)*$',
            'matches empty'     => 'x*',
            'too long'          => str_repeat('a', 300),
        ];
        foreach ($cases as $label => $pattern) {
            try {
                $this->tool->handle(['search' => $pattern, 'replace' => 'y', 'regex' => true]);
                $this->fail("Expected the regex guard to refuse: {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
    }

    public function test_max_matches_truncates_dry_run_and_blocks_apply(): void
    {
        $id = $this->post(str_repeat('Acme Corp ', 5));
        $this->post(str_repeat('Acme Corp ', 5));
        $before = $this->fields($id);

        $dry = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'max_matches' => 3]);
        $this->assertTrue($dry['truncated']);

        try {
            $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'max_matches' => 3, 'dry_run' => false, 'confirm' => true]);
            $this->fail('A truncated scan must not be applied');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('max_matches', $e->getMessage());
        }
        $this->assertSame($before, $this->fields($id));
    }

    public function test_apply_beyond_the_confirm_threshold_requires_confirm(): void
    {
        $ids = [];
        for ($i = 0; $i <= Find_Replace_Content::CONFIRM_THRESHOLD; $i++) {
            $ids[] = $this->post("Acme Corp {$i}");
        }

        try {
            $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'dry_run' => false]);
            $this->fail('Expected confirm:true to be required');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('confirm', $e->getMessage());
        }
        $this->assertStringContainsString('Acme Corp', $this->fields($ids[0])[1]);

        $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'dry_run' => false, 'confirm' => true]);
        $this->assertCount(count($ids), $out['applied']);
    }

    public function test_apply_is_refused_when_the_snapshot_history_cannot_hold_the_pass(): void
    {
        $limit = static fn () => 2;
        add_filter('wpmcp_snapshot_history_limit', $limit);
        $ids = [$this->post('Acme Corp'), $this->post('Acme Corp'), $this->post('Acme Corp')];

        try {
            $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'dry_run' => false, 'confirm' => true]);
            $this->fail('A pass larger than the snapshot history must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('wpmcp_snapshot_history_limit', $e->getMessage());
        } finally {
            remove_filter('wpmcp_snapshot_history_limit', $limit);
        }
        $this->assertSame('Acme Corp', $this->fields($ids[0])[1]);
    }

    public function test_meta_field_requires_meta_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('meta_keys');
        $this->tool->handle(['search' => 'a', 'replace' => 'b', 'fields' => ['meta']]);
    }

    public function test_undecodable_serialized_meta_is_skipped_and_reported(): void
    {
        global $wpdb;
        $id = $this->post('body');
        $wpdb->insert($wpdb->postmeta, [
            'post_id'    => $id,
            'meta_key'   => 'broken_meta',
            'meta_value' => 'a:1:{s:4:"name";s:99:"Acme Corp";}',
        ]);

        $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'fields' => ['meta'], 'meta_keys' => ['broken_meta']]);

        $this->assertSame(1, $out['total_matches']);
        $this->assertStringContainsString('does not decode', $out['skipped'][0]['reason']);
    }

    /**
     * When a save filter rewrites the content (kses for a user without
     * unfiltered_html, or any content_save_pre callback), the stored bytes
     * are not the planned ones. That post is restored from its snapshot,
     * reported as failed, and its undo point is voided.
     */
    public function test_a_save_filter_that_alters_content_rolls_that_post_back(): void
    {
        $id     = $this->post('Acme Corp');
        $before = $this->fields($id);
        $filter = static fn ($content) => false !== strpos((string) $content, 'X') ? $content . ' [altered]' : $content;
        add_filter('content_save_pre', $filter);

        try {
            $out = $this->tool->handle(['search' => 'Acme Corp', 'replace' => 'X', 'post_ids' => [$id], 'dry_run' => false]);
        } finally {
            remove_filter('content_save_pre', $filter);
        }

        $this->assertSame([], $out['applied']);
        $this->assertSame($id, $out['failed'][0]['post_id']);
        $this->assertSame($before[1], $this->fields($id)[1]);
        $this->assertSame([], Snapshot_Store::list_by_session($out['session_id']));
    }

    public function test_catastrophic_regex_is_aborted_before_any_write(): void
    {
        $id     = $this->post(str_repeat('a', 5000) . 'cb');
        $before = $this->fields($id);

        try {
            $this->tool->handle(['search' => 'a*a*a*a*a*a*a*b', 'replace' => 'x', 'regex' => true, 'post_ids' => [$id], 'dry_run' => false]);
            $this->fail('A regex that exhausts the backtrack budget must abort the pass');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nothing was written', $e->getMessage());
        }
        $this->assertSame($before, $this->fields($id));
        $this->assertNotSame('100000', ini_get('pcre.backtrack_limit'), 'The lowered backtrack limit must be restored');
    }
}
