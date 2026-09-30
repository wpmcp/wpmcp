<?php

namespace WPMCP\Tests\Pro\Content;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Content\Slash_Payload;
use WPMCP\Tools\Analysis\Fix_Link_Text;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;
use WPMCP\Tools\WooCommerce\Catalog\Review_Ops;

/**
 * Issue #425 on the paid surface: every write path that hands WordPress a
 * value it will unslash must slash it first, so backslashes (block JSON
 * escapes, Elementor's \/, Windows paths) land byte for byte.
 */
class SlashRoundTripTest extends \WP_UnitTestCase
{
    use Slash_Payload;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_a_fix_pass_keeps_backslashes_elsewhere_in_the_post(): void
    {
        $target = self::factory()->post->create(['post_title' => 'How To Service A Bicycle Chain']);
        $link   = '<a href="' . esc_url((string) get_permalink($target)) . '">%s</a>';
        $before = self::body() . "\n<p>" . sprintf($link, 'click here') . '</p>';
        $id     = self::factory()->post->create(['post_content' => wp_slash($before)]);
        $this->assertSame($before, get_post($id)->post_content, 'Fixture stored as intended.');

        $out = (new Fix_Link_Text())->handle(['post_id' => $id, 'apply' => true]);
        clean_post_cache($id);

        $this->assertTrue($out['applied']);
        $this->assertSame(self::body() . "\n<p>" . sprintf($link, 'How To Service A Bicycle Chain') . '</p>', get_post($id)->post_content);
    }

    public function test_widget_specs_keep_backslashes_on_create_and_update(): void
    {
        $spec = [
            'name'     => 'slash-box',
            'title'    => self::line(),
            'controls' => [['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => self::line()]],
            'template' => '<div class="slash"><h3>{{heading}}</h3><p>' . self::line() . '</p></div>',
        ];

        $id = Widget_Spec_Store::create($spec);
        $this->assertIsInt($id);
        $stored = Widget_Spec_Store::get($id);
        $this->assertSame($spec['template'], $stored['template']);
        $this->assertSame(self::line(), $stored['controls'][0]['default']);
        $this->assertSame(self::line(), get_post($id)->post_title);

        $spec['template'] = '<div class="slash"><h3>{{heading}}</h3><p>' . self::line() . ' 2</p></div>';
        $this->assertTrue(Widget_Spec_Store::update($id, $spec));
        clean_post_cache($id);
        $this->assertSame($spec['template'], Widget_Spec_Store::get($id)['template']);
        $this->assertSame(self::line(), get_post($id)->post_title);
    }

    public function test_block_specs_keep_backslashes_on_create_and_update(): void
    {
        $spec = [
            'name'       => 'slash-callout',
            'title'      => self::line(),
            'category'   => 'widgets',
            'attributes' => [['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => self::line()]],
            'template'   => '<div class="slash"><h4>{{heading}}</h4><p>' . self::line() . '</p></div>',
        ];

        $id = Block_Spec_Store::create($spec);
        $this->assertIsInt($id);
        $stored = Block_Spec_Store::get($id);
        $this->assertSame($spec['template'], $stored['template']);
        $this->assertSame(self::line(), $stored['attributes'][0]['default']);
        $this->assertSame(self::line(), get_post($id)->post_title);

        $this->assertTrue(Block_Spec_Store::update($id, $spec));
        clean_post_cache($id);
        $this->assertSame(self::line(), get_post($id)->post_title);
    }

    public function test_seo_meta_keeps_backslashes_in_per_key_storage(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $id   = self::factory()->post->create();
        $keys = SEO_Adapter::meta_keys();

        SEO_Adapter::update_meta($id, ['title' => self::line(), 'description' => self::line()]);

        $this->assertSame(self::line(), get_post_meta($id, $keys['title'], true));
        $this->assertSame(self::line(), get_post_meta($id, $keys['description'], true));
    }

    public function test_seo_meta_keeps_backslashes_in_array_storage(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('surerank');
        $id = self::factory()->post->create();

        SEO_Adapter::update_meta($id, ['title' => self::line(), 'description' => self::line()]);
        $data = get_post_meta($id, '_surerank_meta', true);

        $this->assertSame(self::line(), $data['page_title']);
        $this->assertSame(self::line(), $data['page_description']);
    }

    public function test_review_update_keeps_backslashes(): void
    {
        $comment_id = self::factory()->comment->create(['comment_post_ID' => self::factory()->post->create(), 'comment_content' => 'plain']);

        Review_Ops::apply('review_update', $comment_id, ['content' => self::line()]);
        clean_comment_cache($comment_id);

        $this->assertSame(self::line(), get_comment($comment_id)->comment_content);
    }
}
