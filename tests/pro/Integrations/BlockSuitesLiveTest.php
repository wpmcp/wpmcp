<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #287: "Inserted blocks render correctly on the front end", checked
 * against the real block suites rather than the stand-ins the rest of the
 * block suite tests register.
 *
 * It runs only in the local gate's live blocks leg (bin/test-local.sh, or
 * bin/test-local.sh --live-blocks alone), which sets WPMCP_LIVE_BLOCKS=1,
 * installs Kadence Blocks, GenerateBlocks, Spectra, Otter Blocks, Stackable
 * and Ultimate Blocks (issue #649) from wordpress.org on a separate WordPress install and loads them in
 * tests/bootstrap.php. Each test inserts a block through the block-suites
 * tools, renders the post as a front-end request (the wp action, wp_head,
 * the_content and wp_footer, which is where the suites build and print their
 * CSS), and asserts that the rendered markup and the CSS the suite generated
 * are both keyed on the unique id the tool gave the block. The suites with a
 * per-post CSS cache are then edited and rolled back, and the page must show
 * the new style and then the original one, which fails if a stale cache is
 * served.
 *
 * Everywhere else the suites are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group blocks-live
 */
class BlockSuitesLiveTest extends \WP_UnitTestCase
{
    /** @var array<string,\WP_Hook> the hooks as the suites registered them, for a fresh request per render */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([ 'KADENCE_BLOCKS_VERSION', 'GENERATEBLOCKS_VERSION', 'UAGB_VER', 'OTTER_BLOCKS_VERSION', 'STACKABLE_VERSION', 'ULTIMATE_BLOCKS_VERSION' ] as $constant) {
            if (! defined($constant)) {
                $this->markTestSkipped('Needs the real block suites (bin/test-local.sh --live-blocks, WPMCP_LIVE_BLOCKS=1).');
            }
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach ($GLOBALS['wp_filter'] as $name => $hook) {
            $this->hooks[ $name ] = clone $hook;
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function write(string $op, array $args): array
    {
        $out = (new Block_Suites_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A published page holding one paragraph, and its content hash. */
    private function page(): array
    {
        $id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->',
        ]);
        return [ $id, $this->hash($id) ];
    }

    private function hash(int $id): string
    {
        clean_post_cache($id);
        return hash('sha256', (string) get_post($id)->post_content);
    }

    /**
     * Insert markup as the page's second block and return the unique id the
     * tool assigned to it.
     *
     * @return array{0:string,1:array} unique id, write result
     */
    private function insert(string $suite, int $id, string $hash, string $markup): array
    {
        $out = $this->write('insert-block', [ 'suite' => $suite, 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $markup ]);
        $ids = $out['result']['unique_ids'];
        $this->assertNotEmpty($ids, 'the tool assigned a unique id');
        return [ (string) $ids[0]['unique_id'], $out ];
    }

    private function update(string $suite, int $id, array $attrs): array
    {
        return $this->write('update-block', [ 'suite' => $suite, 'id' => $id, 'expected_hash' => $this->hash($id), 'path' => [ 1 ], 'attrs' => $attrs ]);
    }

    /**
     * The page as a visitor gets it: a fresh front-end request for the
     * permalink, then wp_head, the loop and wp_footer.
     *
     * Several renders share one PHP process here, so what a real request
     * starts without is reset first: the hooks the suites add while a page
     * renders, the style and script queues, the counts of the page actions,
     * and GenerateBlocks' in-memory lists of blocks whose CSS it already
     * printed (one per block class for the 1.x blocks). The view also comes more than five seconds after
     * GenerateBlocks last wrote a stylesheet: its writer runs at most once
     * per five seconds and, inside that window, enqueues the per-post file
     * without writing it.
     */
    private function render(int $id): string
    {
        foreach ($this->hooks as $name => $hook) {
            $GLOBALS['wp_filter'][ $name ] = clone $hook;
        }
        foreach (array_diff(array_keys($GLOBALS['wp_filter']), array_keys($this->hooks)) as $name) {
            unset($GLOBALS['wp_filter'][ $name ]);
        }
        foreach ([ 'wp', 'template_redirect', 'wp_head', 'wp_enqueue_scripts', 'wp_print_styles', 'wp_footer' ] as $action) {
            unset($GLOBALS['wp_actions'][ $action ]);
        }
        $GLOBALS['wp_styles']  = null;
        $GLOBALS['wp_scripts'] = null;
        // The 1.x block classes (issue #794) keep their own private lists,
        // and a flag for the CSS they print once per page.
        $gb_classes = [
            'GenerateBlocks_Block', 'GenerateBlocks_Block_Text', 'GenerateBlocks_Block_Element',
            'GenerateBlocks_Block_Container', 'GenerateBlocks_Block_Grid', 'GenerateBlocks_Block_Button_Container',
            'GenerateBlocks_Block_Headline', 'GenerateBlocks_Block_Button', 'GenerateBlocks_Block_Image',
        ];
        foreach ($gb_classes as $class) {
            foreach ([ 'block_ids' => [], 'singular_css_added' => false ] as $name => $fresh) {
                if (class_exists($class) && property_exists($class, $name)) {
                    $property = new \ReflectionProperty($class, $name);
                    if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class) {
                        $property->setValue(null, $fresh);
                    }
                }
            }
        }
        if (function_exists('uagb')) {
            uagb()->post_assets_objs = [];
        }
        update_option('generateblocks_dynamic_css_time', time() - 10);
        wp_set_current_user(0);
        $this->go_to((string) get_permalink($id));
        $this->assertTrue(is_singular(), 'the permalink resolves to the page');

        ob_start();
        wp_head();
        while (have_posts()) {
            the_post();
            the_content();
        }
        wp_footer();
        $html = (string) ob_get_clean();

        wp_reset_postdata();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        return $html;
    }

    /** Every CSS rule of the page (inline style elements and enqueued local stylesheets). */
    private function css(string $html): string
    {
        $css = '';
        if (preg_match_all('#<style[^>]*>(.*?)</style>#s', $html, $m)) {
            $css .= implode("\n", $m[1]);
        }
        $uploads = wp_upload_dir(null, false);
        if (preg_match_all('#<link[^>]+href=[\'"]([^\'"]+\.css)(?:\?[^\'"]*)?[\'"]#', $html, $m)) {
            foreach ($m[1] as $url) {
                if (0 === strpos($url, $uploads['baseurl'])) {
                    $file = $uploads['basedir'] . substr($url, strlen($uploads['baseurl']));
                    $css .= "\n" . (is_readable($file) ? (string) file_get_contents($file) : '');
                }
            }
        }
        return $css;
    }

    /** Assert a rule whose selector names $key sets $value. */
    private function assertRule(string $css, string $key, string $value, string $message): void
    {
        $this->assertMatchesRegularExpression(
            '/[^{}]*' . preg_quote($key, '/') . '[^{}]*\{[^}]*' . preg_quote($value, '/') . '/i',
            $css,
            $message . "\nCSS:\n" . $css
        );
    }

    public function test_kadence_heading_renders_with_css_keyed_on_its_unique_id(): void
    {
        [ $id, $hash ] = $this->page();
        [ $unique ]    = $this->insert('kadence-blocks', $id, $hash,
            '<!-- wp:kadence/advancedheading {"level":2,"color":"#a1b2c3"} -->'
            . '<h2 class="kt-adv-heading__UNIQUE_ID__ wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading__UNIQUE_ID__">Kadence live</h2>'
            . '<!-- /wp:kadence/advancedheading -->');
        $this->assertMatchesRegularExpression('/^' . $id . '_[0-9a-f-]{9}$/', $unique, 'a post-scoped Kadence id');

        $html = $this->render($id);
        $this->assertStringContainsString('Kadence live', $html);
        $this->assertStringContainsString('kt-adv-heading' . $unique, $html, 'the markup carries the id');
        $this->assertStringContainsString('data-kb-block="kb-adv-heading' . $unique . '"', $html);
        $this->assertStringNotContainsString('__UNIQUE_ID__', $html);
        $this->assertRule($this->css($html), '.kt-adv-heading' . $unique, '#a1b2c3', 'Kadence printed CSS for the block id');
    }

    public function test_generateblocks_text_renders_with_css_keyed_on_its_unique_id_and_follows_edits(): void
    {
        [ $id, $hash ]  = $this->page();
        [ $unique, $w ] = $this->insert('generateblocks', $id, $hash,
            '<!-- wp:generateblocks/text {"tagName":"p","styles":{"color":"#1a2b3c","fontSize":"21px"}} -->'
            . '<p class="gb-text gb-text-__UNIQUE_ID__">GenerateBlocks live</p>'
            . '<!-- /wp:generateblocks/text -->');
        $original = $this->content_of($id, $w);

        $html = $this->render($id);
        $this->assertStringContainsString('GenerateBlocks live', $html);
        $this->assertStringContainsString('gb-text-' . $unique, $html, 'the markup carries the id');
        $this->assertRule($this->css($html), '.gb-text-' . $unique, '#1a2b3c', 'GenerateBlocks printed the compiled CSS for the block id');

        $this->assert_edit_and_rollback('generateblocks', $id, $unique, '.gb-text-', [ 'styles' => [ 'color' => '#4d5e6f', 'fontSize' => '21px' ] ], '#4d5e6f', '#1a2b3c', $original);
    }

    /**
     * Issue #794: a GenerateBlocks 1.x Headline, as GenerateBlocks 1.x
     * saved it, takes an attribute edit and a text edit, and its CSS follows
     * the edit and the rollback.
     */
    public function test_generateblocks_legacy_headline_takes_edits_and_its_css_follows(): void
    {
        $saved = '<!-- wp:generateblocks/headline {"uniqueId":"a1b2c3d4","element":"h3","textColor":"#c0392b"} -->'
            . '<h3 class="gb-headline gb-headline-a1b2c3d4 gb-headline-text">GB legacy headline</h3>'
            . '<!-- /wp:generateblocks/headline -->';
        $id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->' . $saved,
        ]);
        $original = (string) get_post($id)->post_content;

        $html = $this->render($id);
        $this->assertStringContainsString('gb-headline-a1b2c3d4', $html);
        $this->assertRule($this->css($html), '.gb-headline-a1b2c3d4', '#c0392b', 'GenerateBlocks printed CSS for the legacy block');

        $schema = (new Block_Suites_Integration())->handle_read([ 'operation' => 'get-block-schemas', 'args' => [ 'suite' => 'generateblocks', 'name' => 'generateblocks/headline' ] ]);
        $this->assertSame('allowed', $schema['result']['blocks'][0]['unknown_attributes']);
        $this->assertArrayHasKey('textColor', $schema['result']['blocks'][0]['attributes'], 'typed from the real GenerateBlocks defaults');

        $this->assert_edit_and_rollback('generateblocks', $id, 'a1b2c3d4', '.gb-headline-', [ 'textColor' => '#1e8449' ], '#1e8449', '#c0392b', $original);
        $this->assertStringContainsString('"uniqueId":"a1b2c3d4"', (string) get_post($id)->post_content);

        $text = $this->write('update-block', [
            'suite'         => 'generateblocks',
            'id'            => $id,
            'expected_hash' => $this->hash($id),
            'path'          => [ 1 ],
            'inner_html'    => '<h3 class="gb-headline gb-headline-a1b2c3d4 gb-headline-text">GB legacy edited</h3>',
        ]);
        $this->assertStringContainsString('GB legacy edited', $this->render($id));
        $this->assertTrue(Rollback_Service::restore_operation($text['operation_ids'][0]));
    }

    public function test_generateblocks_legacy_container_inserts_with_a_new_id_and_renders_its_css(): void
    {
        [ $id, $hash ]  = $this->page();
        [ $unique ]     = $this->insert('generateblocks', $id, $hash,
            '<!-- wp:generateblocks/container {"uniqueId":"__UNIQUE_ID__","backgroundColor":"#2c3e50"} -->'
            . '<div class="gb-container gb-container-__UNIQUE_ID__"></div>'
            . '<!-- /wp:generateblocks/container -->');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $unique);

        $html = $this->render($id);
        $this->assertStringContainsString('gb-container-' . $unique, $html);
        $this->assertRule($this->css($html), '.gb-container-' . $unique, '#2c3e50', 'GenerateBlocks printed CSS for the inserted legacy container');
    }

    public function test_spectra_heading_renders_with_css_keyed_on_its_block_id_and_follows_edits(): void
    {
        [ $id, $hash ]  = $this->page();
        [ $unique, $w ] = $this->insert('spectra', $id, $hash,
            '<!-- wp:uagb/advanced-heading {"headingColor":"#2b3c4d"} -->'
            . '<div class="wp-block-uagb-advanced-heading uagb-block-__UNIQUE_ID__"><h2 class="uagb-heading-text">Spectra live</h2></div>'
            . '<!-- /wp:uagb/advanced-heading -->');
        $original = $this->content_of($id, $w);

        $html = $this->render($id);
        $this->assertStringContainsString('Spectra live', $html);
        $this->assertStringContainsString('uagb-block-' . $unique, $html, 'the markup carries the id');
        $this->assertRule($this->css($html), '.uagb-block-' . $unique, '#2b3c4d', 'Spectra generated CSS for the block id');

        $this->assert_edit_and_rollback('spectra', $id, $unique, '.uagb-block-', [ 'headingColor' => '#5e6f70' ], '#5e6f70', '#2b3c4d', $original);
    }

    /**
     * Issue #648: get-page-styles reads the CSS Spectra stored for the page,
     * and rebuild-page-styles drops it so the next view builds it again.
     */
    public function test_spectra_page_styles_read_the_stored_css_and_rebuild_regenerates_it(): void
    {
        [ $id, $hash ] = $this->page();
        [ $unique ]    = $this->insert('spectra', $id, $hash,
            '<!-- wp:uagb/advanced-heading {"headingColor":"#3c4d5e"} -->'
            . '<div class="wp-block-uagb-advanced-heading uagb-block-__UNIQUE_ID__"><h2 class="uagb-heading-text">Spectra styles</h2></div>'
            . '<!-- /wp:uagb/advanced-heading -->');
        $this->render($id);

        $read   = fn (): array => (new Block_Suites_Integration())->handle_read([ 'operation' => 'get-page-styles', 'args' => [ 'suite' => 'spectra', 'id' => $id ] ]);
        $styles = $read();
        $this->assertArrayNotHasKey('error', $styles, (string) wp_json_encode($styles));
        $this->assertTrue($styles['result']['cached'], 'Spectra stored the page assets: ' . (string) wp_json_encode($styles));
        $this->assertRule((string) $styles['result']['css'], '.uagb-block-' . $unique, '#3c4d5e', 'the stored CSS is Spectra\'s for the block');

        $out = $this->write('rebuild-page-styles', [ 'suite' => 'spectra', 'id' => $id ]);
        $this->assertFalse($read()['result']['cached'], 'the cache is dropped');

        $this->assertRule($this->css($this->render($id)), '.uagb-block-' . $unique, '#3c4d5e', 'the next view builds the CSS again');
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
    }

    /**
     * Issue #648: the settings ops use the option names the real plugins
     * register and read.
     */
    public function test_block_suite_settings_round_trip_through_the_real_plugins(): void
    {
        add_filter('wpmcp_enable_block_suite_settings_write', '__return_true');
        $this->assertArrayHasKey('kadence_blocks_settings', get_registered_settings(), 'Kadence Blocks registers the store');
        $out = $this->write('update-settings', [ 'suite' => 'kadence-blocks', 'settings' => [ 'settings' => [ 'performance_optimizer_enabled' => false ] ] ]);
        $read = (new Block_Suites_Integration())->handle_read([ 'operation' => 'get-settings', 'args' => [ 'suite' => 'kadence-blocks' ] ]);
        $this->assertFalse($read['result']['settings']['settings']['performance_optimizer_enabled']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $out = $this->write('update-settings', [ 'suite' => 'spectra', 'settings' => [ 'enable_templates_button' => 'no' ] ]);
        $this->assertSame('no', \UAGB_Admin_Helper::get_admin_settings_option('uag_enable_templates_button', 'yes'), 'Spectra reads the written value');
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('yes', \UAGB_Admin_Helper::get_admin_settings_option('uag_enable_templates_button', 'yes'));
        remove_all_filters('wpmcp_enable_block_suite_settings_write');
    }

    public function test_otter_heading_renders_with_css_keyed_on_its_id_and_follows_edits(): void
    {
        [ $id, $hash ]  = $this->page();
        [ $unique, $w ] = $this->insert('otter-blocks', $id, $hash,
            '<!-- wp:themeisle-blocks/advanced-heading {"headingColor":"#3c4d5e"} -->'
            . '<h2 id="__UNIQUE_ID__" class="wp-block-themeisle-blocks-advanced-heading">Otter live</h2>'
            . '<!-- /wp:themeisle-blocks/advanced-heading -->');
        $this->assertMatchesRegularExpression('/^wp-block-themeisle-blocks-advanced-heading-[0-9a-f]{8}$/', $unique);
        $original = $this->content_of($id, $w);

        $html = $this->render($id);
        $this->assertStringContainsString('Otter live', $html);
        $this->assertStringContainsString('id="' . $unique . '"', $html, 'the markup carries the id');
        $this->assertRule($this->css($html), '#' . $unique, '#3c4d5e', 'Otter generated CSS for the block id');

        $this->assert_edit_and_rollback('otter-blocks', $id, $unique, '#', [ 'headingColor' => '#6f7081' ], '#6f7081', '#3c4d5e', $original);
    }

    /**
     * Stackable saves each block's CSS in a style tag in its markup. After a
     * write the optimized CSS meta is gone, so the inline style renders; once
     * Stackable rebuilds the meta on a save it serves the rule from the head;
     * a rollback drops the meta again, so nothing stale is served for the
     * restored content.
     */
    public function test_stackable_text_renders_its_inline_css_and_rolls_back_clean(): void
    {
        [ $id, $hash ]  = $this->page();
        [ $unique, $w ] = $this->insert('stackable', $id, $hash,
            '<!-- wp:stackable/text {"textColor1":"#7a8b9c"} -->'
            . '<div class="wp-block-stackable-text stk-block-text stk-block stk-__UNIQUE_ID__" data-block-id="__UNIQUE_ID__">'
            . '<style>.stk-__UNIQUE_ID__ .stk-block-text__text{color:#7a8b9c !important}</style>'
            . '<p class="stk-block-text__text has-text-color">Stackable live</p></div>'
            . '<!-- /wp:stackable/text -->');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $unique);
        $this->assertSame('', get_post_meta($id, 'stackable_optimized_css', true), 'the write leaves no optimized CSS to strip the new style');

        $html = $this->render($id);
        $this->assertStringContainsString('Stackable live', $html);
        $this->assertStringContainsString('stk-' . $unique, $html, 'the markup carries the id');
        $this->assertRule($this->css($html), '.stk-' . $unique, '#7a8b9c', 'the block style renders');

        // A later save in the editor: Stackable builds its optimized CSS.
        wp_update_post([ 'ID' => $id, 'post_title' => 'Saved again' ]);
        $this->assertStringContainsString('stk-' . $unique, (string) get_post_meta($id, 'stackable_optimized_css', true));
        $this->assertRule($this->css($this->render($id)), '.stk-' . $unique, '#7a8b9c', 'the optimized CSS carries the block style');

        $this->assertTrue(Rollback_Service::restore_operation($w['operation_ids'][0]));
        $this->assertSame('', get_post_meta($id, 'stackable_optimized_css', true), 'the rollback drops the CSS built for the undone content');
        $html = $this->render($id);
        $this->assertStringNotContainsString('stk-' . $unique, $html);
        $this->assertStringContainsString('Intro', $html);
    }

    public function test_ultimate_blocks_heading_renders_with_its_block_id_and_follows_edits(): void
    {
        [ $id, $hash ]  = $this->page();
        // Ultimate Blocks' asset loader reads fontFamily from the saved
        // attributes, so it goes in as its editor saves it: the default stack.
        $attrs          = [
            'content'    => 'UB live',
            'level'      => 'h2',
            'textColor'  => '#4d5e6f',
            'fontFamily' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
        ];
        [ $unique, $w ] = $this->insert('ultimate-blocks', $id, $hash, '<!-- wp:ub/advanced-heading ' . serialize_block_attributes($attrs) . ' /-->');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $unique);
        $original = $this->content_of($id, $w);

        $html = $this->render($id);
        $this->assertStringContainsString('UB live', $html);
        $this->assertStringContainsString('id="ub-advanced-heading-' . $unique . '"', $html, 'the markup carries the id');
        $this->assertStringContainsString('color: #4d5e6f', $html);

        $out  = $this->update('ultimate-blocks', $id, [ 'textColor' => '#8091a2' ]);
        $html = $this->render($id);
        $this->assertStringContainsString('color: #8091a2', $html);
        $this->assertStringNotContainsString('#4d5e6f', $html);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        clean_post_cache($id);
        $this->assertSame($original, (string) get_post($id)->post_content, 'the rollback restores the exact bytes');
        $this->assertStringContainsString('color: #4d5e6f', $this->render($id));
    }

    /** The post content right before the write in $out, for the rollback check. */
    private function content_of(int $id, array $out): string
    {
        $this->assertCount(1, $out['operation_ids']);
        clean_post_cache($id);
        return (string) get_post($id)->post_content;
    }

    /**
     * Edit the block's style, render, then roll the edit back and render
     * again. A suite that served its cached CSS would show the old color
     * after the edit or the new one after the rollback.
     */
    private function assert_edit_and_rollback(string $suite, int $id, string $unique, string $prefix, array $attrs, string $new, string $old, string $inserted): void
    {
        $out  = $this->update($suite, $id, $attrs);
        $html = $this->render($id);
        $css  = $this->css($html);
        $this->assertRule($css, $prefix . $unique, $new, $suite . ' serves the edited style');
        $this->assertStringNotContainsString($old, $css, $suite . ' no longer serves the old style');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        clean_post_cache($id);
        $this->assertSame($inserted, (string) get_post($id)->post_content, 'the rollback restores the exact bytes');
        $css = $this->css($this->render($id));
        $this->assertRule($css, $prefix . $unique, $old, $suite . ' serves the original style after the rollback');
        $this->assertStringNotContainsString($new, $css, $suite . ' no longer serves the edited style after the rollback');
    }

    /**
     * Issue #647: the Otter settings ops agree with the options and defaults
     * the real Otter registers, and rebuild-otter-page-styles has Otter
     * write the page's stylesheet again with the block's style in it.
     */
    public function test_otter_settings_match_its_registered_defaults_and_rebuild_writes_the_stylesheet(): void
    {
        $registered = get_registered_settings();
        $out        = (new Block_Suites_Integration())->handle_read([ 'operation' => 'get-otter-settings', 'args' => [] ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        foreach ([ 'features', 'settings' ] as $group) {
            foreach ($out['result'][ $group ] as $key => $value) {
                $option = 'disabled_blocks' === $key ? 'themeisle_disabled_blocks' : 'themeisle_blocks_settings_' . $key;
                $this->assertArrayHasKey($option, $registered, 'Otter registers ' . $option);
                if (is_bool($value) && false === get_option($option, false)) {
                    $this->assertSame((bool) $registered[ $option ]['default'], $value, $option . ' reads as Otter\'s default');
                }
            }
        }

        [ $id, $hash ] = $this->page();
        $this->insert('otter-blocks', $id, $hash,
            '<!-- wp:themeisle-blocks/advanced-heading {"headingColor":"#2b3c4d"} -->'
            . '<h2 id="__UNIQUE_ID__" class="wp-block-themeisle-blocks-advanced-heading">Otter rebuild</h2>'
            . '<!-- /wp:themeisle-blocks/advanced-heading -->');
        $out = $this->write('rebuild-otter-page-styles', [ 'id' => $id ]);
        $this->assertTrue($out['result']['rebuilt'], 'Otter\'s CSS handler generated the stylesheet');
        $this->assertTrue($out['result']['file_exists'], (string) wp_json_encode($out['result']));
        $this->assertStringContainsString('#2b3c4d', strtolower($out['result']['css']));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
    }
}
