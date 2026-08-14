<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Count_Content;
use WPMCP\Tools\Content\Diff_Revisions;
use WPMCP\Tools\Content\Duplicate_Post;

/**
 * duplicate-post, diff-revisions and count-content: three capabilities the
 * rest of the free MCP field has and this plugin did not.
 */
class FoundationContentToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_duplicate_copies_content_meta_and_terms(): void
    {
        $term_id = self::factory()->category->create(['name' => 'Copied Cat']);
        $source  = self::factory()->post->create([
            'post_title'   => 'Original',
            'post_content' => 'body text',
            'post_excerpt' => 'the excerpt',
            'post_status'  => 'publish',
        ]);
        add_post_meta($source, 'custom_key', 'custom_value');
        add_post_meta($source, '_elementor_data', '[{"id":"abc"}]');
        wp_set_object_terms($source, [$term_id], 'category');

        $out = (new Duplicate_Post())->handle(['post_id' => $source]);
        $copy = get_post($out['post_id']);

        $this->assertNotSame($source, $out['post_id']);
        $this->assertSame('Original (copy)', $copy->post_title);
        $this->assertSame('body text', $copy->post_content);
        $this->assertSame('the excerpt', $copy->post_excerpt);
        $this->assertSame('custom_value', get_post_meta($out['post_id'], 'custom_key', true));
        $this->assertSame(
            '[{"id":"abc"}]',
            get_post_meta($out['post_id'], '_elementor_data', true),
            'Builder data must be copied, or the duplicate of a built page has no layout.'
        );
        $this->assertContains(
            $term_id,
            array_map('intval', (array) wp_get_object_terms($out['post_id'], 'category', ['fields' => 'ids']))
        );
    }

    public function test_duplicate_of_a_published_post_is_a_draft(): void
    {
        // Silently publishing a clone is how a half-finished copy ends up
        // live; "make me a copy to work on" is the actual intent.
        $source = self::factory()->post->create(['post_status' => 'publish']);

        $out = (new Duplicate_Post())->handle(['post_id' => $source]);

        $this->assertSame('draft', $out['status']);
        $this->assertSame('draft', get_post($out['post_id'])->post_status);
    }

    public function test_duplicate_honours_an_explicit_status_and_title(): void
    {
        $source = self::factory()->post->create(['post_status' => 'publish']);

        $out = (new Duplicate_Post())->handle([
            'post_id' => $source,
            'status'  => 'pending',
            'title'   => 'My Chosen Title',
        ]);

        $this->assertSame('pending', get_post($out['post_id'])->post_status);
        $this->assertSame('My Chosen Title', get_post($out['post_id'])->post_title);
    }

    public function test_duplicate_omits_editor_bookkeeping_meta(): void
    {
        $source = self::factory()->post->create();
        add_post_meta($source, '_edit_lock', '1234:1');
        add_post_meta($source, '_wp_old_slug', 'a-previous-url');

        $out = (new Duplicate_Post())->handle(['post_id' => $source]);

        $this->assertSame('', get_post_meta($out['post_id'], '_edit_lock', true));
        $this->assertSame(
            '',
            get_post_meta($out['post_id'], '_wp_old_slug', true),
            'Redirect history belongs to the source URL, not the copy.'
        );
    }

    public function test_duplicate_can_include_children(): void
    {
        $parent = self::factory()->post->create(['post_type' => 'page']);
        self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent]);
        self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent]);

        $out = (new Duplicate_Post())->handle(['post_id' => $parent, 'include_children' => true]);

        $this->assertCount(2, $out['children']);
        foreach ($out['children'] as $child_id) {
            $this->assertSame($out['post_id'], (int) get_post($child_id)->post_parent);
        }
    }

    public function test_duplicate_without_children_leaves_them_alone(): void
    {
        $parent = self::factory()->post->create(['post_type' => 'page']);
        self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent]);

        $out = (new Duplicate_Post())->handle(['post_id' => $parent]);

        $this->assertSame([], $out['children']);
    }

    public function test_duplicate_rejects_a_missing_post(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Duplicate_Post())->handle(['post_id' => 99999999]);
    }

    public function test_diff_reports_only_the_changed_fields(): void
    {
        // Two updates, not one: creating a post stores no revision, and the
        // first update's revision holds the POST-update state, so a single
        // update leaves nothing that differs from the current post.
        $post_id = self::factory()->post->create([
            'post_title'   => 'First title',
            'post_content' => "line one\nline two",
        ]);

        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => 'First title',
            'post_content' => "line one\nline two",
        ]);
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => 'First title',
            'post_content' => "line one\nline two CHANGED",
        ]);

        $revisions = wp_get_post_revisions($post_id);
        $this->assertNotEmpty($revisions, 'The fixture must produce a revision to diff.');

        $oldest = end($revisions);
        $out    = (new Diff_Revisions())->handle(['from_revision_id' => (int) $oldest->ID]);

        $this->assertFalse($out['identical']);
        $this->assertArrayHasKey('post_content', $out['changes']);
        $this->assertArrayNotHasKey(
            'post_title',
            $out['changes'],
            'An unchanged field must be omitted entirely rather than returned as an empty diff.'
        );
        $this->assertNotEmpty($out['changes']['post_content']['diff']);
    }

    public function test_diff_marks_identical_revisions(): void
    {
        $post_id  = self::factory()->post->create(['post_content' => 'same']);
        $revision = wp_save_post_revision($post_id);

        if (! $revision) {
            $this->markTestSkipped('WordPress declined to store a revision for an unchanged post.');
        }

        $out = (new Diff_Revisions())->handle(['from_revision_id' => (int) $revision]);

        $this->assertTrue($out['identical']);
        $this->assertSame([], $out['changes']);
    }

    public function test_diff_refuses_revisions_from_different_posts(): void
    {
        // Diffing across posts produces a plausible-looking result that means
        // nothing at all.
        $a = self::factory()->post->create(['post_content' => 'a1']);
        wp_update_post(['ID' => $a, 'post_content' => 'a2']);
        $b = self::factory()->post->create(['post_content' => 'b1']);
        wp_update_post(['ID' => $b, 'post_content' => 'b2']);

        $ra = wp_get_post_revisions($a);
        $rb = wp_get_post_revisions($b);

        if ([] === $ra || [] === $rb) {
            $this->markTestSkipped('Revisions were not stored for the fixtures.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same post');

        (new Diff_Revisions())->handle([
            'from_revision_id' => (int) reset($ra)->ID,
            'to_revision_id'   => (int) reset($rb)->ID,
        ]);
    }

    public function test_diff_rejects_a_non_revision_id(): void
    {
        $post_id = self::factory()->post->create();

        $this->expectException(\InvalidArgumentException::class);

        (new Diff_Revisions())->handle(['from_revision_id' => $post_id]);
    }

    public function test_counts_report_posts_media_comments_terms_and_users(): void
    {
        self::factory()->post->create_many(3, ['post_status' => 'publish']);
        self::factory()->post->create(['post_status' => 'draft']);

        $out = (new Count_Content())->handle([]);

        $this->assertArrayHasKey('post', $out['posts']);
        $this->assertGreaterThanOrEqual(3, $out['posts']['post']['by_status']['publish']);
        $this->assertGreaterThanOrEqual(1, $out['posts']['post']['by_status']['draft']);
        $this->assertArrayHasKey('total', $out['media']);
        $this->assertArrayHasKey('approved', $out['comments']);
        $this->assertArrayHasKey('category', $out['terms']);
        $this->assertGreaterThan(0, $out['users']['total']);
    }

    public function test_counts_exclude_auto_drafts_from_the_total_but_still_show_them(): void
    {
        // auto-draft rows are editor scratch space; counting them as content
        // makes every "how many posts do I have" answer wrong.
        self::factory()->post->create(['post_status' => 'publish']);
        self::factory()->post->create(['post_status' => 'auto-draft']);

        $out   = (new Count_Content())->handle(['include' => ['posts'], 'post_type' => 'post']);
        $posts = $out['posts']['post'];

        $this->assertArrayHasKey('auto-draft', $posts['by_status']);
        $this->assertSame(
            array_sum(array_diff_key($posts['by_status'], ['auto-draft' => 0])),
            $posts['total']
        );
    }

    public function test_counts_can_be_narrowed_to_one_section(): void
    {
        $out = (new Count_Content())->handle(['include' => ['users']]);

        $this->assertArrayHasKey('users', $out);
        $this->assertArrayNotHasKey('posts', $out);
        $this->assertArrayNotHasKey('media', $out);
    }
}
