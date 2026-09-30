<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Blocks\Update_Block;
use WPMCP\Tools\Builders\Avada_Content;
use WPMCP\Tools\Builders\Divi_Content;
use WPMCP\Tools\Builders\WPBakery_Content;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Export\Content_Mirror;
use WPMCP\Tools\Export\Export_Content;
use WPMCP\Tools\Export\Import_Content;
use WPMCP\Tools\Migration\Find_Replace_Content;
use WPMCP\Tools\SiteEditor\Site_Templates_Write;
use WPMCP\Tools\Sync\Change_Set_Applier;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Update_Blocks;

/**
 * Issue #440: the writers that change only a post's content (or a few of its
 * text columns) re-saved the whole row through wp_update_post(), so for a
 * user without unfiltered_html kses rewrote the title and excerpt they never
 * touched. Each must leave the columns it does not change byte for byte, and
 * still filter the content it does write exactly as before.
 */
class ContentWriteFilterTest extends \WP_UnitTestCase
{
    use Raw_Post_Columns;

    private const THEME = 'twentytwentyfour';

    /** @var string[] The save filters that ran, in order. */
    private array $filtered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->assert_kses_would_change_it();
    }

    protected function tearDown(): void
    {
        remove_filter('stylesheet', [$this, 'block_theme_slug']);
        remove_filter('template', [$this, 'block_theme_slug']);
        \WP_Theme_JSON_Resolver::clean_cached_data();
        wp_clean_theme_json_cache();
        remove_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10);
        $this->wipe_mirror();
        parent::tearDown();
    }

    public function block_theme_slug(): string
    {
        return self::THEME;
    }

    private function use_block_theme(): void
    {
        add_filter('stylesheet', [$this, 'block_theme_slug']);
        add_filter('template', [$this, 'block_theme_slug']);
        \WP_Theme_JSON_Resolver::clean_cached_data();
        wp_clean_theme_json_cache();
    }

    private function wipe_mirror(): void
    {
        $dir = Content_Mirror::dir();
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            unlink($dir . '/' . $entry);
        }
        rmdir($dir);
    }

    /** Records every run of the content save filter, so a write that must still filter the content can prove it did. */
    private function spy_content_filter(): void
    {
        $this->filtered = [];
        add_filter('content_save_pre', [$this, 'on_content_save_pre'], 99);
    }

    /** @param mixed $value */
    public function on_content_save_pre($value)
    {
        $this->filtered[] = 'content_save_pre';
        return $value;
    }

    private function assert_content_filtered(): void
    {
        remove_filter('content_save_pre', [$this, 'on_content_save_pre'], 99);
        $this->assertContains('content_save_pre', $this->filtered, 'The content written must still run through the save filters.');
        $this->assert_post_save_filters_hooked();
    }

    private function assert_kses_stripped(int $post_id): void
    {
        clean_post_cache($post_id);
        $content = (string) get_post($post_id)->post_content;
        $this->assertStringNotContainsString('<script', $content, 'The written content is still filtered.');
        $this->assertStringNotContainsString('<iframe', $content, 'The written content is still filtered.');
        $this->assert_post_save_filters_hooked();
    }

    private function authored_post(int $author, string $content = '', string $type = 'post'): int
    {
        $id = (int) self::factory()->post->create(['post_author' => $author, 'post_type' => $type, 'post_status' => 'publish']);
        $this->make_raw($id);
        if ('' !== $content) {
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $id]);
            clean_post_cache($id);
        }
        return $id;
    }

    public function test_update_blocks_leaves_title_and_excerpt_alone(): void
    {
        $author = $this->author();
        $id     = $this->authored_post($author);
        $this->act_as($author);

        (new Update_Blocks())->handle(['id' => $id, 'blocks' => self::raw_markup() . "\n<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->"]);

        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('Updated', get_post($id)->post_content);
        $this->assert_kses_stripped($id);
    }

    public function test_surgical_block_edit_leaves_title_and_excerpt_alone(): void
    {
        $author  = $this->author();
        $content = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->";
        $id      = $this->authored_post($author, $content);
        $this->act_as($author);
        $this->spy_content_filter();

        (new Update_Block())->handle([
            'id'            => $id,
            'expected_hash' => hash('sha256', $content),
            'path'          => [0],
            'inner_html'    => "\n<p>World</p>\n",
        ]);

        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('<p>World</p>', get_post($id)->post_content);
        $this->assert_content_filtered();
    }

    /** @return array<string, array{0: class-string}> */
    public static function builder_writers(): array
    {
        return [
            'divi'     => [Divi_Content::class],
            'wpbakery' => [WPBakery_Content::class],
            'avada'    => [Avada_Content::class],
        ];
    }

    /** @dataProvider builder_writers */
    public function test_builder_content_save_leaves_title_and_excerpt_alone(string $writer): void
    {
        $author = $this->author();
        $id     = $this->authored_post($author, '', 'page');
        $this->act_as($author);

        $writer::save($id, '[et_pb_section][vc_row][fusion_builder_container]' . self::raw_markup() . '[/fusion_builder_container][/vc_row][/et_pb_section]');

        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('[et_pb_section]', get_post($id)->post_content);
        $this->assert_kses_stripped($id);
    }

    /**
     * find-replace verifies each written column against the planned bytes,
     * so content kses rewrites is rolled back (as before); a clean edit is
     * applied and the columns it leaves unchanged stay byte for byte.
     */
    public function test_find_replace_writes_only_the_columns_it_changes(): void
    {
        $author = $this->author();
        $clean  = $this->authored_post($author, '<p>one two three</p>');
        $unsafe = $this->authored_post($author);
        $this->act_as($this->admin_without_unfiltered_html());
        $this->spy_content_filter();

        $out = (new Find_Replace_Content())->handle([
            'search'  => 'three',
            'replace' => 'four',
            'fields'  => ['content', 'title'],
            'dry_run' => false,
        ]);

        $this->assertSame([$clean], array_column($out['applied'], 'post_id'));
        $this->assertSame('<p>one two four</p>', get_post($clean)->post_content);
        $this->assert_raw($clean, ['post_title', 'post_excerpt']);

        $this->assertSame([$unsafe], array_column($out['failed'], 'post_id'));
        $this->assertStringContainsString('filters altered', $out['failed'][0]['reason']);
        $this->assert_raw($unsafe);
        $this->assert_content_filtered();
    }

    public function test_content_mirror_restore_leaves_title_and_excerpt_alone(): void
    {
        $this->act_as($this->admin_without_unfiltered_html());
        $id = (int) self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->make_raw($id);

        (new Export_Content())->handle(['mirror' => true, 'post_id' => $id]);
        $doc = json_decode((string) file_get_contents(Content_Mirror::file_for($id)), true);
        $this->assertSame('gutenberg', $doc['builder']);

        $out = (new Import_Content())->handle(['mirror' => true, 'post_id' => $id]);

        $this->assertNotEmpty($out['operation_id']);
        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        $this->assert_kses_stripped($id);
    }

    public function test_site_template_update_leaves_title_and_description_alone(): void
    {
        $this->use_block_theme();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $write = new Site_Templates_Write();
        $first = $write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => "<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->"]);
        $wp_id = (int) $first['wp_id'];
        $this->make_raw($wp_id, ['post_title', 'post_excerpt']);

        $this->act_as($this->admin_without_unfiltered_html());
        $this->spy_content_filter();
        $second = $write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => "<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->"]);

        $this->assertSame($wp_id, (int) $second['wp_id']);
        $this->assert_raw($wp_id, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('Second', get_post($wp_id)->post_content);
        $this->assert_content_filtered();
    }

    public function test_global_styles_update_leaves_title_and_excerpt_alone(): void
    {
        $this->use_block_theme();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $write   = new Site_Templates_Write();
        $first   = $write->handle(['entity' => 'global_styles', 'attrs' => ['settings.color.palette.theme.base.color' => '#123456']]);
        $post_id = (int) $first['user_post_id'];
        $this->make_raw($post_id, ['post_title', 'post_excerpt']);

        $this->act_as($this->admin_without_unfiltered_html());
        $this->spy_content_filter();
        $second = $write->handle(['entity' => 'global_styles', 'attrs' => ['settings.color.palette.theme.base.color' => '#654321']]);

        $this->assertSame($post_id, (int) $second['user_post_id']);
        $this->assert_raw($post_id, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('#654321', get_post($post_id)->post_content);
        $this->assert_content_filtered();
    }

    /**
     * A change set carries the whole post row: title, content and excerpt
     * every time. The columns the target already holds byte for byte are
     * not written, so kses never sees them; a column that does change is
     * filtered as before.
     */
    public function test_change_set_writes_only_the_text_columns_that_differ(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $id = (int) self::factory()->post->create(['post_status' => 'publish']);
        $this->make_raw($id);

        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => 'build-440',
                'tool_name'   => 'update-post',
                'args'        => [],
            ],
            static fn () => wp_update_post(wp_slash(['ID' => $id, 'post_content' => self::raw_markup() . '<p>new</p>']))
        );
        $set = (new Change_Set_Builder())->build(['session_id' => 'build-440']);

        // Put the target back to the base the change set was built from.
        $this->make_raw($id);

        $this->act_as($this->admin_without_unfiltered_html());
        $report = (new Change_Set_Applier())->apply($set, ['dry_run' => false, 'session_id' => 'sync-440']);

        $this->assertSame(1, $report['summary']['applied'], (string) wp_json_encode($report));
        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('<p>new</p>', get_post($id)->post_content);
        $this->assert_kses_stripped($id);
    }

    public function test_publishing_a_stage_leaves_the_unchanged_title_and_excerpt_alone(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $original = (int) self::factory()->post->create(['post_status' => 'publish']);
        $this->make_raw($original);
        $stage = (int) (new Duplicate_Post())->handle(['post_id' => $original, 'stage' => true])['post_id'];
        $this->make_raw($stage);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => self::raw_markup() . '<p>staged</p>'], ['ID' => $stage]);
        clean_post_cache($stage);

        $this->act_as($this->admin_without_unfiltered_html());
        (new Duplicate_Post())->handle(['publish_stage' => $stage]);

        $this->assert_raw($original, ['post_title', 'post_excerpt']);
        $this->assertStringContainsString('<p>staged</p>', get_post($original)->post_content);
        $this->assert_kses_stripped($original);
    }
}
