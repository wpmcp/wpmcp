<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\Post_Stage;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Terms\Update_Term;
use WPMCP\Tools\Users\Update_User;

/**
 * Issue #428: a rollback writes a captured row back through wp_update_comment(),
 * wp_update_term() or wp_update_user(), and those run the save filters (kses,
 * balanceTags, sanitize_text_field, the note mention class sanitizer) over it.
 * The captured row is what was stored, so filtering it again changes it. The
 * restore must be byte for byte, the filters must stay in place for normal
 * writes, and they must be back even when the restore fails.
 */
class RestoreFilterBypassTest extends \WP_UnitTestCase
{
    /** HTML, a lone less-than and block comment markup. */
    private const MARKUP = "<!-- wp:paragraph {\"className\":\"x\"} -->\n<p>1 < 2 &amp; <em>three</em></p>\n<!-- /wp:paragraph -->\n<div class=\"mention\">open";

    /** A name that the text sanitizers would rewrite. */
    private const NAME = 'Ann <b>B</b> & 1 < 2';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public static function mark(string $value): string
    {
        return $value . ' [filtered]';
    }

    public static function explode_on_update(): void
    {
        throw new \RuntimeException('update refused');
    }

    private function comment(): int
    {
        return (int) wp_insert_comment(wp_slash([
            'comment_post_ID' => self::factory()->post->create(),
            'comment_content' => self::MARKUP,
            'comment_author'  => self::NAME,
        ]));
    }

    public function test_comment_rollback_restores_markup_byte_for_byte(): void
    {
        $comment_id = $this->comment();
        $this->assertSame(self::MARKUP, get_comment($comment_id)->comment_content);

        $out = (new Edit_Comment())->handle(['id' => $comment_id, 'content' => 'plain', 'author_name' => 'Plain']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $comment = get_comment($comment_id);
        $this->assertSame(self::MARKUP, $comment->comment_content);
        $this->assertSame(self::NAME, $comment->comment_author);
    }

    public function test_normal_comment_edits_still_run_the_content_filters(): void
    {
        add_filter('pre_comment_content', [self::class, 'mark']);
        $comment_id = $this->comment();

        $out = (new Edit_Comment())->handle(['id' => $comment_id, 'content' => 'edited']);
        clean_comment_cache($comment_id);
        $this->assertSame('edited [filtered]', get_comment($comment_id)->comment_content);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(self::MARKUP, get_comment($comment_id)->comment_content);

        // The filters are back for the next ordinary write.
        $this->assertSame(10, has_filter('pre_comment_content', [self::class, 'mark']));
        (new Edit_Comment())->handle(['id' => $comment_id, 'content' => 'again']);
        clean_comment_cache($comment_id);
        $this->assertSame('again [filtered]', get_comment($comment_id)->comment_content);
    }

    public function test_comment_filters_are_restored_when_the_rollback_fails(): void
    {
        add_filter('pre_comment_content', [self::class, 'mark']);
        $comment_id = $this->comment();
        $out        = (new Edit_Comment())->handle(['id' => $comment_id, 'content' => 'edited']);
        $before     = [
            has_filter('pre_comment_content', [self::class, 'mark']),
            has_filter('pre_comment_content', 'balanceTags'),
            has_filter('pre_comment_author_name', 'sanitize_text_field'),
        ];

        add_filter('wp_update_comment_data', [self::class, 'explode_on_update']);
        try {
            Rollback_Service::restore_operation($out['operation_id']);
            $this->fail('The restore should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('update refused', $e->getMessage());
        } finally {
            remove_filter('wp_update_comment_data', [self::class, 'explode_on_update']);
        }

        $this->assertSame($before, [
            has_filter('pre_comment_content', [self::class, 'mark']),
            has_filter('pre_comment_content', 'balanceTags'),
            has_filter('pre_comment_author_name', 'sanitize_text_field'),
        ]);
        $this->assertNotFalse($before[1]);
        // The restore's own pin on the update data is gone too.
        $this->assertFalse(has_filter('wp_update_comment_data'));
    }

    public function test_term_rollback_restores_name_and_description_byte_for_byte(): void
    {
        global $wpdb;
        $made    = wp_insert_term('Filtered', 'category', ['slug' => 'restore-filters']);
        $term_id = (int) $made['term_id'];
        // Stored as another writer (an import, a plugin) left it, unfiltered.
        $wpdb->update($wpdb->terms, ['name' => self::NAME], ['term_id' => $term_id]);
        $wpdb->update($wpdb->term_taxonomy, ['description' => self::MARKUP], ['term_id' => $term_id]);
        clean_term_cache([$term_id], 'category');

        $out = (new Update_Term())->handle(['taxonomy' => 'category', 'term_id' => $term_id, 'name' => 'Plain', 'description' => 'Plain']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_term_cache([$term_id], 'category');
        $term = get_term($term_id, 'category');

        $this->assertSame(self::NAME, $term->name);
        $this->assertSame(self::MARKUP, $term->description);
        $this->assertSame(10, has_filter('pre_term_name', 'sanitize_text_field'));
        $this->assertSame(10, has_filter('pre_term_description', 'wp_filter_kses'));
    }

    public function test_user_rollback_restores_the_profile_columns_byte_for_byte(): void
    {
        global $wpdb;
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wpdb->update($wpdb->users, ['display_name' => self::NAME], ['ID' => $user_id]);
        clean_user_cache($user_id);

        $out = (new Update_User())->handle(['id' => $user_id, 'display_name' => 'Plain']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_user_cache($user_id);

        $this->assertSame(self::NAME, get_userdata($user_id)->display_name);
        $this->assertSame(10, has_filter('pre_user_display_name', 'sanitize_text_field'));
    }

    /** Post HTML that kses rewrites for a user without unfiltered_html. */
    private const POST_MARKUP = self::MARKUP . "\n<iframe src=\"https://example.org/x\"></iframe>";

    /** A title that wp_filter_kses rewrites. */
    private const POST_TITLE = 'Title <b>B</b> & 1 < 2 <script>x</script>';

    /** Footnotes meta whose content kses rewrites, JSON encoded the way the editor stores it. */
    private const FOOTNOTES = '[{"content":"1 < 2 <iframe src=\"https:\/\/example.org\/x\"><\/iframe>","id":"fn-1"}]';

    private function author(): int
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $this->assertFalse(user_can($author, 'unfiltered_html'));
        return $author;
    }

    /** A post owned by $author holding markup kses would rewrite, stored verbatim. */
    private function raw_post(int $author): int
    {
        global $wpdb;
        $post_id = self::factory()->post->create(['post_author' => $author, 'post_status' => 'publish']);
        $wpdb->update($wpdb->posts, [
            'post_content' => self::POST_MARKUP,
            'post_excerpt' => self::POST_MARKUP,
            'post_title'   => self::POST_TITLE,
        ], ['ID' => $post_id]);
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => 'footnotes', 'meta_value' => self::FOOTNOTES]);
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');
        return $post_id;
    }

    private function assert_post_is_raw(int $post_id): void
    {
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');
        $post = get_post($post_id);
        $this->assertSame(self::POST_MARKUP, $post->post_content);
        $this->assertSame(self::POST_MARKUP, $post->post_excerpt);
        $this->assertSame(self::POST_TITLE, $post->post_title);
        $this->assertSame([self::FOOTNOTES], get_post_meta($post_id, 'footnotes'));
    }

    private function assert_post_filters_hooked(): void
    {
        $this->assertSame(10, has_filter('content_save_pre', 'wp_filter_post_kses'));
        $this->assertSame(10, has_filter('excerpt_save_pre', 'wp_filter_post_kses'));
        $this->assertSame(10, has_filter('title_save_pre', 'wp_filter_kses'));
        $this->assertSame(10, has_filter('sanitize_post_meta_footnotes', '_wp_filter_post_meta_footnotes'));
    }

    public function test_post_rollback_by_an_author_restores_markup_byte_for_byte(): void
    {
        $author  = $this->author();
        $post_id = $this->raw_post($author);
        wp_set_current_user($author);
        $this->assert_post_filters_hooked();
        // The markup is something kses would change, or this proves nothing.
        $this->assertNotSame(self::POST_MARKUP, wp_unslash(wp_filter_post_kses(wp_slash(self::POST_MARKUP))));
        $this->assertNotSame(self::POST_TITLE, wp_unslash(wp_filter_kses(wp_slash(self::POST_TITLE))));

        $out = (new Update_Post())->handle([
            'post_id' => $post_id,
            'title'   => 'Plain',
            'content' => 'plain',
            'excerpt' => 'plain',
            'meta'    => ['footnotes' => '[]'],
        ]);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assert_post_is_raw($post_id);
        $this->assert_post_filters_hooked();
    }

    public function test_force_deleted_post_rollback_by_an_author_restores_markup_byte_for_byte(): void
    {
        $author  = $this->author();
        $post_id = $this->raw_post($author);
        wp_set_current_user($author);
        add_filter('wpmcp_enable_delete_post', '__return_true');

        $out = (new Delete_Post())->handle(['post_id' => $post_id, 'force' => true, 'confirm' => true]);
        $this->assertNull(get_post($post_id));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assert_post_is_raw($post_id);
        $this->assert_post_filters_hooked();
    }

    public function test_stage_publish_rollback_by_an_author_restores_markup_byte_for_byte(): void
    {
        $author   = $this->author();
        $post_id  = $this->raw_post($author);
        $stage_id = self::factory()->post->create(['post_author' => $author, 'post_status' => 'draft', 'post_content' => 'staged']);
        Post_Stage::link($stage_id, $post_id);
        wp_set_current_user($author);

        $out = Post_Stage::publish($stage_id, ['force' => true]);
        $this->assertSame('staged', get_post($post_id)->post_content);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assert_post_is_raw($post_id);
    }

    public function test_normal_post_updates_by_the_author_are_still_filtered(): void
    {
        $author  = $this->author();
        $post_id = $this->raw_post($author);
        wp_set_current_user($author);

        $out = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'x']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assert_post_is_raw($post_id);

        // The very next ordinary write by the same user goes through kses.
        (new Update_Post())->handle([
            'post_id' => $post_id,
            'title'   => self::POST_TITLE,
            'content' => self::POST_MARKUP,
            'excerpt' => self::POST_MARKUP,
            'meta'    => ['footnotes' => self::FOOTNOTES],
        ]);
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');
        $post = get_post($post_id);
        $this->assertStringNotContainsString('<iframe', $post->post_content);
        $this->assertStringNotContainsString('<iframe', $post->post_excerpt);
        $this->assertStringNotContainsString('<script', $post->post_title);
        $this->assertStringNotContainsString('iframe', (string) get_post_meta($post_id, 'footnotes', true));
        $this->assertNotFalse(wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => self::POST_MARKUP])));
        $this->assertNotSame(self::POST_MARKUP, get_post($post_id)->post_content);
    }

    public function test_post_filters_are_restored_when_the_post_write_throws(): void
    {
        $author  = $this->author();
        $post_id = $this->raw_post($author);
        wp_set_current_user($author);
        $out = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'x']);

        add_filter('wp_insert_post_data', [self::class, 'explode_on_update']);
        try {
            Rollback_Service::restore_operation($out['operation_id']);
            $this->fail('The restore should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('update refused', $e->getMessage());
        } finally {
            remove_filter('wp_insert_post_data', [self::class, 'explode_on_update']);
        }

        $this->assert_post_filters_hooked();
    }

    public function test_post_meta_filters_are_restored_when_the_meta_write_throws(): void
    {
        $author  = $this->author();
        $post_id = $this->raw_post($author);
        wp_set_current_user($author);
        $out = (new Update_Post())->handle(['post_id' => $post_id, 'meta' => ['footnotes' => '[]']]);

        add_filter('add_post_metadata', [self::class, 'explode_on_update']);
        try {
            Rollback_Service::restore_operation($out['operation_id']);
            $this->fail('The restore should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('update refused', $e->getMessage());
        } finally {
            remove_filter('add_post_metadata', [self::class, 'explode_on_update']);
        }

        $this->assert_post_filters_hooked();
    }
}
