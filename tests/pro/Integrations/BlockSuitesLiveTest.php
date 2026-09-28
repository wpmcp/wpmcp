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
 * installs Kadence Blocks, GenerateBlocks, Spectra and Otter Blocks from
 * wordpress.org on a separate WordPress install and loads them in
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
        foreach ([ 'KADENCE_BLOCKS_VERSION', 'GENERATEBLOCKS_VERSION', 'UAGB_VER', 'OTTER_BLOCKS_VERSION' ] as $constant) {
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
     * and GenerateBlocks' in-memory list of blocks whose CSS it already
     * printed. The view also comes more than five seconds after
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
        foreach ([ 'GenerateBlocks_Block', 'GenerateBlocks_Block_Text', 'GenerateBlocks_Block_Element' ] as $class) {
            if (class_exists($class) && property_exists($class, 'block_ids')) {
                $property = new \ReflectionProperty($class, 'block_ids');
                $property->setAccessible(true);
                $property->setValue(null, []);
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
}
