<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Elementor\Atomic_Element;
use WPMCP\Tools\Elementor\Batch_Update;
use WPMCP\Tools\Elementor\Create_Global_Class;
use WPMCP\Tools\Elementor\Create_Popup;
use WPMCP\Tools\Builders\Elementor_Cache;
use WPMCP\Tools\Elementor\Elementor_Page_Data;
use WPMCP\Tools\Elementor\Get_Global_Settings;
use WPMCP\Tools\Elementor\Global_Classes_Store;
use WPMCP\Tools\Elementor\Set_Popup_Settings;
use WPMCP\Tools\Elementor\Update_Global_Colors;
use WPMCP\Tools\Elementor\Update_Page_Settings;
use WPMCP\Tools\Rollback_Operation;

/**
 * Elementor keeps two derived caches per document: the element render cache
 * (Document::CACHE_META_KEY, `_elementor_element_cache`) and the generated
 * post CSS (`_elementor_css` meta plus uploads/elementor/css/post-{id}.css).
 * Every wpmcp write that changes a document's Elementor data, including a
 * rollback that restores an earlier snapshot of it, must leave neither cache
 * describing content the document no longer has.
 */
class RenderCacheInvalidationTest extends Structural_Harness
{
    private const RED   = '#ff0000';
    private const GREEN = '#00ff00';

    protected function tearDown(): void
    {
        Elementor_Cache::set_available_for_tests(null);
        parent::tearDown();
    }

    // ---- helpers ------------------------------------------------------------

    private function colored_tree(string $color): array
    {
        return [
            [
                'id'       => 'cont001',
                'elType'   => 'container',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'wid0001',
                        'elType'     => 'widget',
                        'settings'   => ['title' => 'Hello', 'title_color' => $color],
                        'elements'   => [],
                        'widgetType' => 'heading',
                    ],
                ],
                'isInner'  => false,
            ],
        ];
    }

    /** What a front-end view leaves behind: a render cache entry and a generated CSS file. */
    private function simulate_front_end_view(int $post_id, string $marker): void
    {
        $this->seed_render_cache($post_id, $marker);
        \Elementor\Core\Files\CSS\Post::create($post_id)->update();
    }

    private function seed_render_cache(int $post_id, string $marker): void
    {
        update_post_meta(
            $post_id,
            \Elementor\Core\Base\Document::CACHE_META_KEY,
            wp_slash(wp_json_encode([
                'timeout' => time() + HOUR_IN_SECONDS,
                'value'   => ['content' => $marker, 'scripts' => [], 'styles' => []],
            ]))
        );
    }

    private function seed_probes(int $post_id): void
    {
        $this->seed_render_cache($post_id, 'stale-probe');
        update_post_meta($post_id, '_elementor_css', ['status' => 'stale-probe']);
    }

    private function render_cache(int $post_id)
    {
        return get_post_meta($post_id, \Elementor\Core\Base\Document::CACHE_META_KEY, true);
    }

    /**
     * The CSS a visitor would be served for $post_id right now, or null when
     * Elementor holds nothing and will regenerate from the stored data on
     * the next view (which is always fresh).
     */
    private function served_css(int $post_id): ?string
    {
        clean_post_cache($post_id);
        $css  = \Elementor\Core\Files\CSS\Post::create($post_id);
        $meta = $css->get_meta();

        if ('file' === $meta['status']) {
            return file_exists($css->get_path()) ? (string) file_get_contents($css->get_path()) : '';
        }
        if ('inline' === $meta['status']) {
            return (string) $meta['css'];
        }

        return null;
    }

    private function assert_invalidated(int $post_id, string $context): void
    {
        clean_post_cache($post_id);
        $this->assertEmpty($this->render_cache($post_id), "{$context}: the element render cache must be invalidated.");
        $this->assertEmpty(get_post_meta($post_id, '_elementor_css', true), "{$context}: the post CSS must be invalidated.");
    }

    // ---- the bug ------------------------------------------------------------

    public function test_rollback_does_not_serve_the_rolled_back_css_or_render_cache(): void
    {
        $post_id = $this->make_page($this->colored_tree(self::RED));
        $this->simulate_front_end_view($post_id, 'render-red');
        $this->assertStringContainsString(self::RED, (string) $this->served_css($post_id), 'Sanity: CSS generation works here.');

        $out = (new Batch_Update())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'updates'       => [['element_id' => 'wid0001', 'settings' => ['title_color' => self::GREEN]]],
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assert_invalidated($post_id, 'write');

        // A visitor views the edited page: Elementor caches the green render.
        $this->simulate_front_end_view($post_id, 'render-green');
        $this->assertStringContainsString(self::GREEN, (string) $this->served_css($post_id));

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        $this->assertStringContainsString(self::RED, $this->raw($post_id), 'Sanity: the data is red again.');

        $served = $this->served_css($post_id);
        $this->assertFalse(
            null !== $served && false !== strpos($served, self::GREEN),
            'After rollback the page must not be served the CSS generated for the rolled-back edit.'
        );
        $this->assert_invalidated($post_id, 'rollback');
    }

    // ---- every write path ---------------------------------------------------

    public function test_document_save_write_invalidates_both_caches(): void
    {
        $post_id = $this->make_page($this->colored_tree(self::RED));
        $this->seed_probes($post_id);

        $out = (new Batch_Update())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'updates'       => [['element_id' => 'wid0001', 'settings' => ['title' => 'B']]],
        ]);

        $this->assertIsArray($out);
        $this->assert_invalidated($post_id, 'batch-update');
    }

    public function test_raw_meta_write_invalidates_both_caches(): void
    {
        $post_id = $this->make_page();
        $this->seed_probes($post_id);

        Elementor_Page_Data::save($post_id, $this->colored_tree(self::GREEN));

        $this->assert_invalidated($post_id, 'raw write');
    }

    public function test_page_settings_write_invalidates_both_caches(): void
    {
        $post_id = $this->make_page();
        $this->seed_probes($post_id);

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['hide_title' => 'yes'],
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assert_invalidated($post_id, 'update-page-settings');
    }

    public function test_atomic_write_invalidates_both_caches(): void
    {
        $post_id = $this->make_page();
        $this->seed_probes($post_id);

        $out = Atomic_Element::write($post_id, $this->colored_tree(self::GREEN), 'test-atomic', []);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assert_invalidated($post_id, 'atomic write');
    }

    public function test_popup_settings_write_invalidates_both_caches(): void
    {
        $popup_id = (new Create_Popup())->handle(['title' => 'P', 'elements' => $this->colored_tree(self::RED)])['popup_id'];
        $this->seed_probes($popup_id);

        $out = (new Set_Popup_Settings())->handle([
            'post_id'  => $popup_id,
            'settings' => ['width' => ['unit' => 'px', 'size' => 400]],
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assert_invalidated($popup_id, 'set-popup-settings');
    }

    public function test_duplicate_does_not_carry_the_source_caches(): void
    {
        $source = $this->make_page($this->colored_tree(self::RED));
        $this->simulate_front_end_view($source, 'render-source');

        $out = (new Duplicate_Post())->handle(['post_id' => $source]);

        $this->assertSame($this->raw($source), $this->raw($out['post_id']), 'Sanity: the element tree is copied.');
        $this->assert_invalidated($out['post_id'], 'duplicate-post');
        $this->assertNotEmpty($this->render_cache($source), 'The source keeps its own caches.');
    }

    public function test_rollback_of_a_kit_write_clears_the_site_css(): void
    {
        $page = $this->make_page();

        $out = (new Update_Global_Colors())->handle([
            'expected_hash' => (new Get_Global_Settings())->handle([])['settings_hash'],
            'system_colors' => [['_id' => 'primary', 'color' => self::GREEN]],
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        // Pages viewed after the kit edit cache CSS built against it.
        $this->seed_probes($page);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        $this->assert_invalidated($page, 'kit rollback');
    }

    public function test_rollback_of_a_global_class_write_clears_the_site_css(): void
    {
        if (! Global_Classes_Store::is_supported()) {
            $this->markTestSkipped('Elementor v4 global classes are not available');
        }

        $page  = $this->make_page();
        $state = Global_Classes_Store::read();
        $out   = (new Create_Global_Class())->handle([
            'expected_hash' => Global_Classes_Store::state_hash($state['items'], $state['order']),
            'label'         => 'cache-probe',
            'styles'        => ['color' => '#111111'],
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        $this->seed_probes($page);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        $this->assert_invalidated($page, 'global class rollback');
    }

    // ---- the helper ---------------------------------------------------------

    public function test_invalidate_document_is_a_no_op_without_elementor(): void
    {
        $post_id = $this->make_page();
        $this->seed_probes($post_id);

        Elementor_Cache::set_available_for_tests(false);
        Elementor_Cache::invalidate_document($post_id);
        Elementor_Cache::clear_all();

        $this->assertNotEmpty($this->render_cache($post_id));
        $this->assertSame(['status' => 'stale-probe'], get_post_meta($post_id, '_elementor_css', true));
    }

    public function test_invalidate_document_touches_only_that_document(): void
    {
        $edited = $this->make_page();
        $other  = $this->make_page();
        $this->seed_probes($edited);
        $this->seed_probes($other);

        Elementor_Cache::invalidate_document($edited);

        $this->assert_invalidated($edited, 'edited');
        $this->assertNotEmpty($this->render_cache($other), 'Another page keeps its render cache.');
        $this->assertNotEmpty(get_post_meta($other, '_elementor_css', true), 'Another page keeps its CSS.');
    }
}
