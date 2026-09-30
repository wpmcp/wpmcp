<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Memory\Memory_Store;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\Compose\Build_Page;
use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Export\Import_Content;
use WPMCP\Tools\Media\Update_Media;
use WPMCP\Tools\Media\Upload_Media;
use WPMCP\Tools\Menus\Add_Menu_Item;
use WPMCP\Tools\Menus\Update_Menu_Item;
use WPMCP\Tools\Meta\Set_Post_Meta;
use WPMCP\Tools\Terms\Create_Term;
use WPMCP\Tools\Terms\Set_Term_Meta;
use WPMCP\Tools\Terms\Update_Term;
use WPMCP\Tools\Update_Blocks;
use WPMCP\Tools\Users\Create_User;
use WPMCP\Tools\Users\Update_User;

/**
 * Issue #425: WordPress unslashes what wp_insert_post(), wp_update_post(),
 * wp_insert_term(), wp_update_term(), wp_insert_user(), wp_update_comment(),
 * wp_update_nav_menu_item() and the *_meta() writers are given. A write path
 * that hands them a raw value loses every backslash, which silently damages
 * block JSON escapes and Elementor data. Each test stores a value through one
 * write path and reads it back byte for byte.
 */
class SlashRoundTripTest extends \WP_UnitTestCase
{
    use Slash_Payload;

    /** A valid 1x1 PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $wxr = '';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_import', '__return_true');
        remove_filter('wpmcp_enable_delete_post', '__return_true');
        if ('' !== $this->wxr && is_file($this->wxr)) {
            wp_delete_file($this->wxr);
        }
        parent::tearDown();
    }

    private function post(): int
    {
        return self::factory()->post->create(['post_content' => 'plain', 'post_title' => 'Plain']);
    }

    private function fresh(int $post_id): \WP_Post
    {
        clean_post_cache($post_id);
        return get_post($post_id);
    }

    // ---- posts ---------------------------------------------------------------

    public function test_update_post_keeps_backslashes_in_every_field_and_meta(): void
    {
        $id = $this->post();

        (new Update_Post())->handle([
            'post_id' => $id,
            'title'   => self::line(),
            'content' => self::body(),
            'excerpt' => self::body(),
            'meta'    => ['wpmcp_slash_text' => self::body(), 'wpmcp_slash_nested' => self::nested()],
        ]);

        $post = $this->fresh($id);
        $this->assertSame(self::body(), $post->post_content);
        $this->assertSame(self::body(), $post->post_excerpt);
        $this->assertSame(self::line(), $post->post_title);
        $this->assertSame(self::body(), get_post_meta($id, 'wpmcp_slash_text', true));
        $this->assertSame(self::nested(), get_post_meta($id, 'wpmcp_slash_nested', true));
    }

    public function test_update_post_does_not_double_slash_plain_content(): void
    {
        $id = $this->post();

        (new Update_Post())->handle(['post_id' => $id, 'content' => '<p>No escapes here</p>']);

        $this->assertSame('<p>No escapes here</p>', $this->fresh($id)->post_content);
    }

    public function test_update_post_leaves_existing_backslashes_alone_when_content_is_not_passed(): void
    {
        $id = self::factory()->post->create(['post_content' => wp_slash(self::body())]);
        $this->assertSame(self::body(), $this->fresh($id)->post_content, 'Fixture stored as intended.');

        (new Update_Post())->handle(['post_id' => $id, 'title' => 'Renamed']);

        $this->assertSame(self::body(), $this->fresh($id)->post_content);
    }

    public function test_create_post_keeps_backslashes_in_every_field_and_meta(): void
    {
        $out = (new Create_Post())->handle([
            'title'   => self::line(),
            'content' => self::body(),
            'excerpt' => self::body(),
            'meta'    => ['wpmcp_slash_text' => self::body(), 'wpmcp_slash_nested' => self::nested()],
        ]);
        $id = (int) $out['post_id'];

        $post = $this->fresh($id);
        $this->assertSame(self::body(), $post->post_content);
        $this->assertSame(self::body(), $post->post_excerpt);
        $this->assertSame(self::line(), $post->post_title);
        $this->assertSame(self::body(), get_post_meta($id, 'wpmcp_slash_text', true));
        $this->assertSame(self::nested(), get_post_meta($id, 'wpmcp_slash_nested', true));
    }

    public function test_update_blocks_keeps_backslashes(): void
    {
        $id = $this->post();

        (new Update_Blocks())->handle(['id' => $id, 'blocks' => self::body()]);

        $this->assertSame(self::body(), $this->fresh($id)->post_content);
    }

    public function test_set_post_meta_keeps_backslashes(): void
    {
        $id = $this->post();

        $out = (new Set_Post_Meta())->handle(['post_id' => $id, 'key' => 'wpmcp_slash_text', 'value' => self::body()]);
        (new Set_Post_Meta())->handle(['post_id' => $id, 'key' => 'wpmcp_slash_nested', 'value' => self::nested()]);

        $this->assertSame(self::body(), $out['value']);
        $this->assertSame(self::body(), get_post_meta($id, 'wpmcp_slash_text', true));
        $this->assertSame(self::nested(), get_post_meta($id, 'wpmcp_slash_nested', true));
    }

    public function test_import_content_keeps_backslashes_in_title_body_and_meta(): void
    {
        add_filter('wpmcp_enable_import', '__return_true');
        $title = esc_html(self::line());
        $body  = self::body();
        $meta  = self::body();
        $xml   = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/"
>
<channel>
    <title>Test Site</title>
    <item>
        <title>{$title}</title>
        <content:encoded><![CDATA[{$body}]]></content:encoded>
        <wp:post_type>post</wp:post_type>
        <wp:status>draft</wp:status>
        <wp:postmeta>
            <wp:meta_key>wpmcp_slash_text</wp:meta_key>
            <wp:meta_value><![CDATA[{$meta}]]></wp:meta_value>
        </wp:postmeta>
    </item>
</channel>
</rss>
XML;
        $path = wp_tempnam('wpmcp-slash-import');
        rename($path, $path . '.xml');
        $this->wxr = $path . '.xml';
        file_put_contents($this->wxr, $xml);

        $out = (new Import_Content())->handle(['file' => $this->wxr, 'confirm' => true]);
        $id  = (int) $out['created_post_ids'][0];

        $post = $this->fresh($id);
        $this->assertSame(self::body(), $post->post_content);
        $this->assertSame(self::line(), $post->post_title);
        $this->assertSame(self::body(), get_post_meta($id, 'wpmcp_slash_text', true));
    }

    public function test_build_page_keeps_backslashes_in_title_markup_and_menu_title(): void
    {
        $menu_id = wp_create_nav_menu('Slash Build Menu ' . wp_generate_uuid4());

        $out = (new Build_Page())->handle(['spec' => [
            'title'   => self::line(),
            'content' => [['type' => 'html', 'settings' => ['html' => self::body()]]],
            'menu'    => ['menu_id' => $menu_id, 'title' => self::line() . ' menu'],
        ]]);
        $post = $this->fresh((int) $out['post_id']);

        $this->assertSame(self::line(), $post->post_title);
        $this->assertStringContainsString(self::body(), $post->post_content);
        $items = wp_get_nav_menu_items($menu_id);
        $this->assertSame(self::line() . ' menu', $this->fresh((int) $items[0]->ID)->post_title);
    }

    // ---- media -----------------------------------------------------------------

    public function test_update_media_keeps_backslashes_in_every_field_and_alt(): void
    {
        $id = self::factory()->attachment->create_object(['post_title' => 'Sunset']);

        (new Update_Media())->handle([
            'media_id'    => $id,
            'title'       => self::line(),
            'caption'     => self::line(),
            'description' => self::body(),
            'alt'         => self::line(),
        ]);

        $post = $this->fresh($id);
        $this->assertSame(self::line(), $post->post_title);
        $this->assertSame(self::line(), $post->post_excerpt);
        $this->assertSame(self::body(), $post->post_content);
        $this->assertSame(self::line(), get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_upload_media_keeps_backslashes_in_title_caption_and_alt(): void
    {
        $out = (new Upload_Media())->handle([
            'filename' => 'pixel.png',
            'data'     => self::PNG_BASE64,
            'title'    => self::line(),
            'caption'  => self::line(),
            'alt'      => self::line(),
        ]);
        $id = (int) $out['media_id'];

        $post = $this->fresh($id);
        $this->assertSame(self::line(), $post->post_title);
        $this->assertSame(self::line(), $post->post_excerpt);
        $this->assertSame(self::line(), get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    // ---- terms -----------------------------------------------------------------

    public function test_create_term_keeps_backslashes_in_name_and_description(): void
    {
        $out  = (new Create_Term())->handle([
            'taxonomy'    => 'category',
            'name'        => self::line(),
            'slug'        => 'slash-create',
            'description' => self::line(),
        ]);
        $term = get_term((int) $out['term']['term_id'], 'category');

        $this->assertSame(self::line(), $term->name);
        $this->assertSame(self::line(), $term->description);
    }

    public function test_update_term_keeps_backslashes_in_name_and_description(): void
    {
        $term_id = self::factory()->category->create(['name' => 'Plain', 'slug' => 'slash-update']);

        (new Update_Term())->handle([
            'taxonomy'    => 'category',
            'term_id'     => $term_id,
            'name'        => self::line(),
            'description' => self::line(),
        ]);
        clean_term_cache([$term_id], 'category');
        $term = get_term($term_id, 'category');

        $this->assertSame(self::line(), $term->name);
        $this->assertSame(self::line(), $term->description);
    }

    public function test_set_term_meta_keeps_backslashes(): void
    {
        $term_id = self::factory()->category->create(['slug' => 'slash-meta']);

        (new Set_Term_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term_id, 'key' => 'wpmcp_slash_text', 'value' => self::body()]);
        (new Set_Term_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term_id, 'key' => 'wpmcp_slash_nested', 'value' => self::nested()]);

        $this->assertSame(self::body(), get_term_meta($term_id, 'wpmcp_slash_text', true));
        $this->assertSame(self::nested(), get_term_meta($term_id, 'wpmcp_slash_nested', true));
    }

    // ---- menus -----------------------------------------------------------------

    public function test_add_menu_item_keeps_backslashes_in_the_title(): void
    {
        $menu_id = wp_create_nav_menu('Slash Menu ' . wp_generate_uuid4());

        $out = (new Add_Menu_Item())->handle(['menu_id' => $menu_id, 'title' => self::line(), 'url' => 'https://example.test/a']);

        $this->assertSame(self::line(), $this->fresh((int) $out['item_id'])->post_title);
    }

    public function test_update_menu_item_keeps_backslashes_in_the_title(): void
    {
        $menu_id = wp_create_nav_menu('Slash Menu ' . wp_generate_uuid4());
        $item_id = (int) wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => 'Plain',
            'menu-item-url'    => 'https://example.test/a',
            'menu-item-status' => 'publish',
        ]);

        (new Update_Menu_Item())->handle(['item_id' => $item_id, 'title' => self::line()]);

        $this->assertSame(self::line(), $this->fresh($item_id)->post_title);
    }

    // ---- comments and users ------------------------------------------------------

    public function test_edit_comment_keeps_backslashes_in_content_and_author(): void
    {
        $comment_id = self::factory()->comment->create(['comment_post_ID' => $this->post(), 'comment_content' => 'plain']);

        (new Edit_Comment())->handle(['id' => $comment_id, 'content' => self::line(), 'author' => self::line()]);
        clean_comment_cache($comment_id);
        $comment = get_comment($comment_id);

        $this->assertSame(self::line(), $comment->comment_content);
        $this->assertSame(self::line(), $comment->comment_author);
    }

    public function test_update_user_keeps_backslashes_in_columns_and_profile_meta(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);

        (new Update_User())->handle([
            'id'           => $user_id,
            'display_name' => self::line(),
            'first_name'   => self::line(),
            'description'  => self::line(),
        ]);
        clean_user_cache($user_id);

        $this->assertSame(self::line(), get_userdata($user_id)->display_name);
        $this->assertSame(self::line(), get_user_meta($user_id, 'first_name', true));
        $this->assertSame(self::line(), get_user_meta($user_id, 'description', true));
    }

    public function test_create_user_keeps_backslashes_in_names(): void
    {
        $out     = (new Create_User())->handle([
            'username'     => 'slashy',
            'email'        => 'slashy@example.test',
            'display_name' => self::line(),
            'first_name'   => self::line(),
            'last_name'    => self::line(),
        ]);
        $user_id = (int) $out['id'];
        clean_user_cache($user_id);

        $this->assertSame(self::line(), get_userdata($user_id)->display_name);
        $this->assertSame(self::line(), get_user_meta($user_id, 'first_name', true));
        $this->assertSame(self::line(), get_user_meta($user_id, 'last_name', true));
    }

    // ---- memory ------------------------------------------------------------------

    public function test_memory_entries_keep_backslashes_on_propose_and_update(): void
    {
        $id = Memory_Store::propose(['title' => self::line(), 'text' => self::line()]);
        $this->assertIsInt($id);
        $this->assertSame(self::line(), Memory_Store::get($id)['title']);
        $this->assertSame(self::line(), Memory_Store::get($id)['text']);

        $text = self::line() . ' again';
        $this->assertTrue(Memory_Store::update_fields($id, ['title' => self::line(), 'text' => $text]));
        clean_post_cache($id);
        $this->assertSame($text, Memory_Store::get($id)['text']);
        $this->assertSame(self::line(), Memory_Store::get($id)['title']);
    }

    // ---- rollback restores backslashes exactly -------------------------------------

    public function test_rolling_back_a_user_edit_restores_backslashes(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_update_user(wp_slash(['ID' => $user_id, 'display_name' => self::line()]));
        update_user_meta($user_id, 'description', wp_slash(self::line()));
        update_user_meta($user_id, 'wpmcp_slash_nested', wp_slash(self::nested()));

        $out = (new Update_User())->handle(['id' => $user_id, 'display_name' => 'Plain', 'description' => 'Plain']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_user_cache($user_id);

        $this->assertSame(self::line(), get_userdata($user_id)->display_name);
        $this->assertSame(self::line(), get_user_meta($user_id, 'description', true));
        $this->assertSame(self::nested(), get_user_meta($user_id, 'wpmcp_slash_nested', true));
    }

    public function test_rolling_back_a_comment_edit_restores_backslashes(): void
    {
        // Plain text content: wp_update_comment() runs the comment content
        // filters on the restored row, which may normalize markup; the
        // backslashes are what this test is about.
        $comment_id = (int) wp_insert_comment(wp_slash([
            'comment_post_ID' => $this->post(),
            'comment_content' => self::line(),
            'comment_author'  => self::line(),
        ]));
        add_comment_meta($comment_id, 'wpmcp_slash_text', wp_slash(self::body()));

        $out = (new Edit_Comment())->handle(['id' => $comment_id, 'content' => 'plain']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_comment_cache($comment_id);

        $this->assertSame(self::line(), get_comment($comment_id)->comment_content);
        $this->assertSame(self::line(), get_comment($comment_id)->comment_author);
        $this->assertSame(self::body(), get_comment_meta($comment_id, 'wpmcp_slash_text', true));
    }

    public function test_rolling_back_a_term_edit_restores_backslashes(): void
    {
        $made    = wp_insert_term(wp_slash(self::line()), 'category', ['slug' => 'slash-rollback', 'description' => wp_slash(self::line())]);
        $term_id = (int) $made['term_id'];
        add_term_meta($term_id, 'wpmcp_slash_text', wp_slash(self::body()));

        $out = (new Update_Term())->handle(['taxonomy' => 'category', 'term_id' => $term_id, 'name' => 'Plain', 'description' => 'Plain']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_term_cache([$term_id], 'category');
        $term = get_term($term_id, 'category');

        $this->assertSame(self::line(), $term->name);
        $this->assertSame(self::line(), $term->description);
        $this->assertSame(self::body(), get_term_meta($term_id, 'wpmcp_slash_text', true));
    }

    public function test_rolling_back_a_post_delete_restores_comment_backslashes(): void
    {
        add_filter('wpmcp_enable_delete_post', '__return_true');
        $post_id    = $this->post();
        $comment_id = (int) wp_insert_comment(wp_slash([
            'comment_post_ID' => $post_id,
            'comment_content' => self::body(),
            'comment_author'  => self::line(),
        ]));
        add_comment_meta($comment_id, 'wpmcp_slash_text', wp_slash(self::body()));

        $out = (new Delete_Post())->handle(['post_id' => $post_id, 'force' => true, 'confirm' => true]);
        $this->assertNull(get_post($post_id));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $comments = get_comments(['post_id' => $post_id]);
        $this->assertCount(1, $comments);
        $this->assertSame(self::body(), $comments[0]->comment_content);
        $this->assertSame(self::line(), $comments[0]->comment_author);
        $this->assertSame(self::body(), get_comment_meta((int) $comments[0]->comment_ID, 'wpmcp_slash_text', true));
    }
}
