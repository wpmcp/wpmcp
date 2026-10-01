<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Builders\Elementor_Cache;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Rollback_Operation;

/**
 * Staged edits (issue #417): duplicate-post stages a linked draft copy of a
 * published entry, the ordinary edit tools change the copy, and the copy is
 * then published over the original (same ID, URL, author, date and comments,
 * one undoable write) or discarded.
 */
class StagedEditsTest extends \WP_UnitTestCase
{
    private const CPT = 'wpmcp_stage_cpt';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        Elementor_Cache::set_available_for_tests(null);
        // Building the default kit instantiates the global REST server; drop
        // it so a later test gets a fresh one with every route registered.
        $GLOBALS['wp_rest_server'] = null;
        unregister_taxonomy_for_object_type('category', 'page');
        if (post_type_exists(self::CPT)) {
            unregister_post_type(self::CPT);
        }
        parent::tearDown();
    }

    private function published(array $args = []): int
    {
        return self::factory()->post->create(array_merge([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Live page',
            'post_name'    => 'live-page',
            'post_content' => "<!-- wp:paragraph -->\n<p>Old body</p>\n<!-- /wp:paragraph -->",
            'post_excerpt' => 'old excerpt',
        ], $args));
    }

    /** Everything a stage must leave alone on the original: the row and the whole meta map. */
    private function state(int $post_id): array
    {
        clean_post_cache($post_id);
        return [
            'post'  => get_post($post_id, ARRAY_A),
            'meta'  => get_post_meta($post_id),
            'terms' => wp_get_object_terms($post_id, get_object_taxonomies((string) get_post_type($post_id)), ['fields' => 'ids']),
        ];
    }

    private function stage(int $original): int
    {
        return (int) (new Duplicate_Post())->handle(['post_id' => $original, 'stage' => true])['post_id'];
    }

    private function snapshot_rows(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only row count on the plugin's own table.
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    public function test_stage_creates_one_linked_draft_and_never_changes_the_original(): void
    {
        $original = $this->published();
        add_post_meta($original, 'custom_key', 'custom_value');
        $before = $this->state($original);

        $out   = (new Duplicate_Post())->handle(['post_id' => $original, 'stage' => true]);
        $stage = get_post($out['post_id']);

        $this->assertNotSame($original, $stage->ID);
        $this->assertSame($original, $out['source_id']);
        $this->assertSame('draft', $stage->post_status);
        $this->assertSame('Live page', $stage->post_title, 'A stage is published back, so it keeps the real title.');
        $this->assertSame(get_post($original)->post_content, $stage->post_content);
        $this->assertSame('custom_value', get_post_meta($stage->ID, 'custom_key', true));
        $this->assertSame($original, (int) get_post_meta($stage->ID, Duplicate_Post::STAGE_OF_META, true));
        $this->assertSame(get_preview_post_link($stage), $out['preview_url']);
        $this->assertSame($before, $this->state($original), 'Staging must never change the original.');
    }

    public function test_a_second_stage_request_returns_the_existing_stage(): void
    {
        $original = $this->published();

        $first  = $this->stage($original);
        $second = (new Duplicate_Post())->handle(['post_id' => $original, 'stage' => true]);

        $this->assertSame($first, $second['post_id']);
        $this->assertCount(1, get_posts([
            'post_type'   => 'any',
            'post_status' => 'any',
            'meta_key'    => Duplicate_Post::STAGE_OF_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test-only lookup.
            'meta_value'  => (string) $original, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- test-only lookup.
            'fields'      => 'ids',
        ]));
    }

    public function test_a_custom_post_type_entry_can_be_staged_and_published(): void
    {
        register_post_type(self::CPT, ['public' => true, 'supports' => ['title', 'editor', 'excerpt']]);
        $original = $this->published(['post_type' => self::CPT, 'post_name' => 'cpt-entry']);
        $before   = $this->state($original);

        $stage = $this->stage($original);
        $this->assertSame(self::CPT, get_post_type($stage));
        $this->assertSame($before, $this->state($original));

        (new Update_Post())->handle(['post_id' => $stage, 'content' => 'New CPT body']);
        (new Duplicate_Post())->handle(['publish_stage' => $stage]);

        clean_post_cache($original);
        $this->assertSame('New CPT body', get_post($original)->post_content);
        $this->assertSame('cpt-entry', get_post($original)->post_name);
    }

    public function test_publishing_block_content_keeps_the_original_identity(): void
    {
        $author   = self::factory()->user->create(['role' => 'editor']);
        $original = $this->published([
            'post_author' => $author,
            'post_date'   => '2024-03-04 05:06:07',
        ]);
        $comment  = self::factory()->comment->create(['comment_post_ID' => $original]);
        add_post_meta($original, '_edit_last', (string) $author);
        add_post_meta($original, 'custom_key', 'old');
        add_post_meta($original, 'removed_key', 'gone soon');
        $old_cat = self::factory()->category->create();
        $new_cat = self::factory()->category->create();
        $thumb   = self::factory()->attachment->create_object('thumb.jpg', 0, ['post_mime_type' => 'image/jpeg']);
        register_taxonomy_for_object_type('category', 'page');
        wp_set_object_terms($original, [$old_cat], 'category');
        $url = get_permalink($original);

        $stage = $this->stage($original);

        // Block JSON escapes (<) are where a missing wp_slash() shows.
        $content = "<!-- wp:paragraph {\"className\":\"a\\u003cb\"} -->\n<p class=\"a&lt;b\">New body</p>\n<!-- /wp:paragraph -->";
        wp_update_post(wp_slash(['ID' => $stage, 'post_content' => $content]));
        $this->assertSame($content, get_post($stage)->post_content);
        (new Update_Post())->handle([
            'post_id'        => $stage,
            'title'          => 'Reworked page',
            'excerpt'        => 'new excerpt',
            'meta'           => ['custom_key' => 'new'],
            'featured_image' => ['id' => $thumb],
        ]);
        delete_post_meta($stage, 'removed_key');
        wp_set_object_terms($stage, [$new_cat], 'category');

        $out = (new Duplicate_Post())->handle(['publish_stage' => $stage]);

        clean_post_cache($original);
        $post = get_post($original);
        $this->assertSame($original, $out['post_id']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('Reworked page', $post->post_title);
        $this->assertSame($content, $post->post_content);
        $this->assertSame('new excerpt', $post->post_excerpt);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('live-page', $post->post_name);
        $this->assertSame($url, get_permalink($original));
        $this->assertSame($author, (int) $post->post_author);
        $this->assertSame('2024-03-04 05:06:07', $post->post_date);
        $this->assertSame($original, (int) get_comment($comment)->comment_post_ID);
        $this->assertSame('new', get_post_meta($original, 'custom_key', true));
        $this->assertFalse(metadata_exists('post', $original, 'removed_key'));
        $this->assertSame($thumb, (int) get_post_thumbnail_id($original));
        $this->assertSame((string) $author, get_post_meta($original, '_edit_last', true), 'Editor bookkeeping on the original is not content.');
        $this->assertSame([$new_cat], array_map('intval', wp_get_object_terms($original, 'category', ['fields' => 'ids'])));
        $this->assertFalse(metadata_exists('post', $original, Duplicate_Post::STAGE_OF_META));
        $this->assertContains(get_post_status($stage), [false, 'trash'], 'The stage is removed once published.');
        $this->assertSame('', (string) get_post_meta($stage, Duplicate_Post::STAGE_OF_META, true));
    }

    /** Elementor data for one heading widget, JSON-encoded the way Elementor stores it (\/ and \u escapes). */
    private function heading(string $title): string
    {
        return (string) wp_json_encode([
            [
                'id'         => 'wid0001',
                'elType'     => 'widget',
                'settings'   => ['title' => $title],
                'elements'   => [],
                'widgetType' => 'heading',
            ],
        ]);
    }

    public function test_publishing_carries_elementor_data_and_drops_its_caches(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
        // The framework deletes every post between tests, the default kit
        // included; Elementor needs one to touch a document's CSS.
        $kits = \Elementor\Plugin::instance()->kits_manager;
        if (! $kits->get_active_id() || ! get_post((int) $kits->get_active_id())) {
            update_option('elementor_active_kit', \Elementor\Core\Kits\Manager::create_default_kit());
        }
        $original = $this->published();
        // Elementor stores slashed JSON (\/ and \u escapes); add_post_meta
        // unslashes, so the fixtures go in slashed to land byte-for-byte.
        $old_data = $this->heading('Old https://old.test/');
        $new_data = $this->heading("Caf\u{e9} https://new.test/");
        $this->assertStringContainsString('\\/', $new_data, 'The fixture carries the escapes that unslashing destroys.');
        add_post_meta($original, '_elementor_data', wp_slash($old_data));
        add_post_meta($original, '_elementor_edit_mode', 'builder');
        add_post_meta($original, '_elementor_page_settings', ['hide_title' => 'yes']);
        add_post_meta($original, '_elementor_css', ['status' => 'file', 'time' => 1]);

        $stage = $this->stage($original);
        $this->assertSame($old_data, get_post_meta($stage, '_elementor_data', true), 'The stage copies builder data byte-for-byte.');

        update_post_meta($stage, '_elementor_data', wp_slash($new_data));
        update_post_meta($stage, '_elementor_page_settings', ['hide_title' => 'no']);
        update_post_meta($stage, '_elementor_css', ['status' => 'file', 'time' => 2]);

        (new Duplicate_Post())->handle(['publish_stage' => $stage]);

        $this->assertSame($new_data, get_post_meta($original, '_elementor_data', true));
        $this->assertSame('builder', get_post_meta($original, '_elementor_edit_mode', true));
        $this->assertSame(['hide_title' => 'no'], get_post_meta($original, '_elementor_page_settings', true));
        $this->assertNotSame(
            ['status' => 'file', 'time' => 2],
            get_post_meta($original, '_elementor_css', true),
            'The stage CSS cache was built for the stage document and must not land on the original.'
        );
        $this->assertNotSame(['status' => 'file', 'time' => 1], get_post_meta($original, '_elementor_css', true), 'The original CSS is stale after a publish.');
    }

    public function test_publishing_is_one_undoable_write_that_restores_the_original_exactly(): void
    {
        $original = $this->published();
        add_post_meta($original, 'custom_key', 'old');
        add_post_meta($original, '_elementor_data', wp_slash('[{"id":"x","settings":{"u":"a\/b"}}]'));
        $cat = self::factory()->category->create();
        register_taxonomy_for_object_type('category', 'page');
        wp_set_object_terms($original, [$cat], 'category');

        $stage = $this->stage($original);
        (new Update_Post())->handle(['post_id' => $stage, 'title' => 'Changed', 'content' => 'Changed body', 'meta' => ['custom_key' => 'new', 'added_key' => 'x']]);
        update_post_meta($stage, '_elementor_data', wp_slash('[{"id":"y"}]'));
        wp_set_object_terms($stage, [], 'category');

        $before = $this->state($original);
        $rows   = $this->snapshot_rows();
        $out    = (new Duplicate_Post())->handle(['publish_stage' => $stage]);
        $this->assertSame($rows + 1, $this->snapshot_rows(), 'Publishing a stage is one snapshot.');
        $this->assertSame('Changed', get_post($original)->post_title);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $after = $this->state($original);
        foreach (['post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_author', 'post_date'] as $field) {
            $this->assertSame($before['post'][ $field ], $after['post'][ $field ], $field);
        }
        $this->assertSame($before['meta'], $after['meta']);
        $this->assertSame($before['terms'], $after['terms']);
    }

    public function test_publishing_refuses_when_the_original_changed_unless_forced(): void
    {
        $original = $this->published();
        add_post_meta($original, 'custom_key', 'old');
        $stage = $this->stage($original);
        (new Update_Post())->handle(['post_id' => $stage, 'content' => 'Staged body']);

        wp_update_post(['ID' => $original, 'post_content' => 'Someone edited the live page']);
        update_post_meta($original, 'custom_key', 'edited live');
        $before = $this->state($original);

        try {
            (new Duplicate_Post())->handle(['publish_stage' => $stage]);
            $this->fail('Publishing over a changed original must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('content', $e->getMessage());
            $this->assertStringContainsString('custom_key', $e->getMessage());
            $this->assertStringContainsString('force', $e->getMessage());
        }
        $this->assertSame($before, $this->state($original));
        $this->assertSame('draft', get_post_status($stage), 'A refused publish keeps the stage.');

        (new Duplicate_Post())->handle(['publish_stage' => $stage, 'force' => true]);
        clean_post_cache($original);
        $this->assertSame('Staged body', get_post($original)->post_content);
    }

    public function test_viewing_the_original_does_not_count_as_a_change(): void
    {
        // Elementor writes its CSS cache and edit lock on view; neither is an edit.
        // The lock is the caller's own: another user's live lock refuses the
        // publish outright (issue #452, EditLockTest).
        $original = $this->published();
        $stage    = $this->stage($original);
        update_post_meta($original, '_elementor_css', ['status' => 'file', 'time' => 9]);
        update_post_meta($original, '_edit_lock', time() . ':' . get_current_user_id());

        $out = (new Duplicate_Post())->handle(['publish_stage' => $stage]);

        $this->assertSame($original, $out['post_id']);
    }

    public function test_discarding_removes_the_stage_and_the_link_and_leaves_the_original(): void
    {
        $original = $this->published();
        add_post_meta($original, 'custom_key', 'value');
        $stage = $this->stage($original);
        (new Update_Post())->handle(['post_id' => $stage, 'content' => 'thrown away']);
        $before = $this->state($original);

        $out = (new Duplicate_Post())->handle(['discard_stage' => $stage]);

        $this->assertSame($stage, $out['post_id']);
        $this->assertContains(get_post_status($stage), [false, 'trash']);
        $this->assertSame('', (string) get_post_meta($stage, Duplicate_Post::STAGE_OF_META, true));
        $this->assertSame($before, $this->state($original));
        $this->assertNotSame($stage, $this->stage($original), 'A discarded stage is not handed out again.');
    }

    public function test_publish_and_discard_refuse_a_post_that_is_not_a_stage(): void
    {
        $plain = self::factory()->post->create(['post_status' => 'draft']);

        foreach (['publish_stage', 'discard_stage'] as $arg) {
            try {
                (new Duplicate_Post())->handle([$arg => $plain]);
                $this->fail($arg . ' must refuse a post that is not a stage.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not a stage', $e->getMessage());
            }
        }
        $this->assertSame('draft', get_post_status($plain));
    }

    public function test_a_stage_cannot_itself_be_staged(): void
    {
        $stage = $this->stage($this->published());

        $this->expectException(\InvalidArgumentException::class);
        (new Duplicate_Post())->handle(['post_id' => $stage, 'stage' => true]);
    }

    public function test_a_stage_is_never_published_or_indexed(): void
    {
        $stage = $this->stage($this->published());

        wp_update_post(['ID' => $stage, 'post_status' => 'publish']);
        $this->assertSame('draft', get_post_status($stage), 'A stage only goes live through publish_stage.');

        $this->go_to(get_preview_post_link($stage));
        $robots = apply_filters('wp_robots', []);
        $this->assertTrue(! empty($robots['noindex']), 'A stage preview is noindex.');
    }

    public function test_a_plain_duplicate_of_a_stage_is_not_a_stage(): void
    {
        $stage = $this->stage($this->published());

        $copy = (new Duplicate_Post())->handle(['post_id' => $stage]);

        $this->assertFalse(metadata_exists('post', $copy['post_id'], Duplicate_Post::STAGE_OF_META));
    }
}
