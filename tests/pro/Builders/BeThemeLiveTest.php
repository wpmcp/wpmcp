<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{BeTheme_Cache, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #643: the BeBuilder adapter against the real BeTheme theme.
 *
 * It runs only in the local gate's live BeTheme leg (bin/test-local.sh
 * --live-betheme), which sets WPMCP_LIVE_BETHEME=1, copies BeTheme from
 * WPMCP_BETHEME_SRC onto a separate WordPress install (BeTheme is a
 * commercial theme, not on wordpress.org) and makes it the active theme in
 * tests/bootstrap.php.
 *
 * A page stored the way BeTheme's save stores it must be detected, read,
 * edited by uid, still render through BeTheme's own front-end builder with
 * its local style file and SEO copy rebuilt by the theme, and rollback must
 * put back the exact layout and rebuild the style file. The item catalog
 * and the style field definitions must come from BeTheme's own fields.
 *
 * Everywhere else BeTheme is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group betheme-live
 */
class BeThemeLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('MFN_THEME_VERSION') || ! BeTheme_Cache::plugin_active()) {
            $this->markTestSkipped('Needs the real BeTheme theme (bin/test-local.sh --live-betheme, WPMCP_BETHEME_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function page(): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => '']);
        update_post_meta($id, 'mfn-page-items', wp_slash(BeThemeAdapterTest::LAYOUT));

        return $id;
    }

    /** The page as BeTheme's front-end builder renders it. */
    private function rendered(int $post_id): string
    {
        $GLOBALS['post'] = get_post($post_id);
        setup_postdata($GLOBALS['post']);
        ob_start();
        (new \Mfn_Builder_Front($post_id))->show();

        return (string) ob_get_clean();
    }

    private function css_file(int $post_id): string
    {
        $path = wp_upload_dir()['basedir'] . '/betheme/css/post-' . $post_id . '.css';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    public function test_a_page_saved_by_betheme_is_read_edited_rendered_and_rolled_back(): void
    {
        $id = $this->page();
        $this->assertStringContainsString('Welcome to BeTheme', $this->rendered($id));
        $this->assertSame('betheme', (new Detect_Builder())->handle(['post_id' => $id])['builder']);

        $read = $this->ok((new Get_Builder_Content())->handle(['post_id' => $id]));
        $this->assertTrue($read['plugin_active']);
        $this->assertSame(MFN_THEME_VERSION, $read['plugin_version']);
        $this->assertSame('c3d4e5f60', $read['tree'][0]['children'][0]['children'][0]['uid']);

        $session = wp_generate_uuid4();
        $out     = $this->ok((new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'betheme',
            'operation'     => 'update',
            'path'          => 'c3d4e5f60',
            'attrs'         => ['title' => 'Edited through wpmcp', 'css_heading_color' => '#ff0000'],
            'expected_hash' => $read['hash'],
            'session_id'    => $session,
        ]));
        $out = $this->ok((new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'betheme',
            'operation'     => 'add',
            'to'            => 'b2c3d4e5f',
            'element'       => ['type' => 'column', 'attr' => ['content' => '<p>Added by wpmcp</p>']],
            'expected_hash' => $out['hash'],
            'session_id'    => $session,
        ]));

        $html = $this->rendered($id);
        $this->assertStringContainsString('Edited through wpmcp', $html);
        $this->assertStringContainsString('Added by wpmcp', $html);
        $this->assertStringContainsString('mcb-item-' . $out['path'], $html);
        $this->assertStringNotContainsString('Welcome to BeTheme', $html);

        // The theme rebuilt the page's SEO copy and local styles.
        $this->assertStringContainsString('Edited through wpmcp', (string) get_post_meta($id, 'mfn-page-items-seo', true));
        $this->assertNotSame('', (string) get_post_meta($id, 'mfn-page-local-style', true));
        $this->assertStringContainsString('#ff0000', $this->css_file($id));
        $this->assertStringContainsString('c3d4e5f60', $this->css_file($id));

        Rollback_Service::restore_session($session);
        $this->assertSame(BeThemeAdapterTest::LAYOUT, BeTheme_Cache::decode($id));
        $this->assertStringContainsString('Welcome to BeTheme', $this->rendered($id));
        $this->assertStringNotContainsString('#ff0000', $this->css_file($id));
    }

    public function test_the_catalog_and_style_fields_come_from_betheme(): void
    {
        $catalog = $this->ok((new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'betheme']));
        $types   = array_column($catalog['elements'], 'type');
        $this->assertContains('heading', $types);
        $this->assertContains('column', $types);

        $heading = $this->ok((new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'betheme', 'element' => 'heading']));
        $ids     = array_column($heading['element']['fields'], 'id');
        $this->assertContains('title', $ids);
        $style = null;
        foreach ($heading['element']['fields'] as $field) {
            if (! empty($field['style']) && 0 === strpos($field['id'], 'css_')) {
                $style = $field['id'];
                break;
            }
        }
        $this->assertNotNull($style, 'heading has a style field');

        $id   = $this->page();
        $read = $this->ok((new Get_Builder_Content())->handle(['post_id' => $id]));
        $out  = $this->ok((new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'betheme',
            'operation'     => 'add',
            'to'            => 'b2c3d4e5f',
            'element'       => ['type' => 'heading', 'attr' => ['title' => 'Styled', $style => '12px']],
            'expected_hash' => $read['hash'],
        ]));
        $items = BeTheme_Cache::decode($id)[0]['wraps'][0]['items'];
        $added = end($items);
        $this->assertSame($out['path'], $added['uid']);
        $this->assertArrayHasKey('selector', $added['attr'][ $style ]);
        $this->assertSame('12px', $added['attr'][ $style ]['val']);

        $bad = (new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'betheme',
            'operation'     => 'add',
            'to'            => 'b2c3d4e5f',
            'element'       => ['type' => 'no_such_item'],
            'expected_hash' => $out['hash'],
        ]);
        $this->assertWPError($bad);
    }

    public function test_a_page_built_from_nothing_renders(): void
    {
        $id  = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => '']);
        $out = $this->ok((new Update_Builder_Content())->handle([
            'post_id'   => $id,
            'builder'   => 'betheme',
            'operation' => 'add',
            'to'        => '',
            'element'   => ['wraps' => [['items' => [['type' => 'heading', 'attr' => ['title' => 'Built by wpmcp']]]]]],
        ]));
        $this->assertSame('betheme', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $html = $this->rendered($id);
        $this->assertStringContainsString('Built by wpmcp', $html);
        $this->assertStringContainsString('mcb-section-' . $out['path'], $html);
    }

    public function test_theme_options_read_through_the_design_system_scope(): void
    {
        $out = $this->ok((new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'betheme']));
        $this->assertTrue($out['plugin_active']);
        $this->assertGreaterThan(0, $out['option_count']);
        $this->assertNotEmpty($out['colors']);
    }
}
