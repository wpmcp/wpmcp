<?php

namespace WPMCP\Tests\Pro\Safety;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Raw_Post_Columns;
use WPMCP\Tools\Analysis\Fix_Link_Text;
use WPMCP\Tools\Media\Stock\Insert_Stock_Image;

/**
 * Issue #440, the add-on half: the accessibility fix passes and the stock
 * image insert write only post_content. For a user without unfiltered_html
 * they must leave the title and excerpt byte for byte and still filter the
 * content they write.
 */
class ContentWriteFilterProTest extends \WP_UnitTestCase
{
    use Raw_Post_Columns;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->assert_kses_would_change_it();
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve_image'], 10);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Overwrites only post_content, bypassing every filter. */
    private function set_raw_content(int $post_id, string $content): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post_id]);
        clean_post_cache($post_id);
    }

    public function test_fix_pass_leaves_title_and_excerpt_alone(): void
    {
        $author = $this->author();
        $target = (int) self::factory()->post->create(['post_title' => 'How To Service A Bicycle Chain']);
        $link   = '<a href="' . esc_url((string) get_permalink($target)) . '">%s</a>';
        $id     = (int) self::factory()->post->create(['post_author' => $author, 'post_status' => 'publish']);
        $this->make_raw($id);
        $this->set_raw_content($id, '<p>' . sprintf($link, 'click here') . '</p><script>var a = 1 < 2;</script>');
        $this->act_as($author);

        $out = (new Fix_Link_Text())->handle(['post_id' => $id, 'apply' => true]);

        $this->assertTrue($out['applied']);
        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        clean_post_cache($id);
        $content = (string) get_post($id)->post_content;
        $this->assertStringContainsString('How To Service A Bicycle Chain</a>', $content);
        $this->assertStringNotContainsString('<script', $content, 'The written content is still filtered.');
        $this->assert_post_save_filters_hooked();
    }

    public function serve_image($preempt, $parsed_args, $url)
    {
        $body = (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => ['content-type' => 'image/jpeg', 'content-length' => (string) filesize(DIR_TESTDATA . '/images/canola.jpg')],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    public function test_insert_stock_image_leaves_title_and_excerpt_alone(): void
    {
        add_filter('pre_http_request', [$this, 'serve_image'], 10, 3);
        $author = $this->author();
        $id     = (int) self::factory()->post->create(['post_author' => $author, 'post_status' => 'publish']);
        $this->make_raw($id);
        $this->act_as($author);

        $out = (new Insert_Stock_Image())->handle([
            'post_id'   => $id,
            'image_url' => 'https://images.pexels.com/photos/123/field.jpeg',
            'alt'       => 'Golden field',
        ]);

        $this->assertNotEmpty($out['insert_operation_id']);
        $this->assert_raw($id, ['post_title', 'post_excerpt']);
        clean_post_cache($id);
        $content = (string) get_post($id)->post_content;
        $this->assertStringContainsString('wp-image-' . (int) $out['media_id'], $content);
        $this->assertStringNotContainsString('<script', $content, 'The written content is still filtered.');
        $this->assert_post_save_filters_hooked();
    }
}
