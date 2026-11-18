<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #824: Thrive Theme Builder templates, sections and theme settings as
 * ops on the theme pair. The theme's storage is recorded from Thrive Theme
 * Builder 10.8.6.3: thrive_template posts in the active skin (a
 * thrive_skin_tax term with is_active 1) whose meta names the template
 * (primary_template, secondary_template, variable_template, format,
 * default), its layout (layout, a thrive_layout id) and its sections
 * (sections, keyed header, footer, top, content, sidebar, bottom, each
 * ['id' => ..., 'content' => ...]); thrive_section posts holding a shared
 * section in their type and content meta; header and footer symbols
 * (tcb_symbol in the headers or footers term of tcb_symbols_tax) holding
 * their markup in tve_updated_post; a page's own template in its
 * thrive_post_template meta; and the logo link, favicon and default
 * featured image options.
 */
class ThriveThemePackTest extends \WP_UnitTestCase
{
    private const POST_TYPES = [ 'thrive_template', 'thrive_section', 'thrive_layout', 'tcb_symbol' ];

    /** @var callable */
    private $template;

    private int $skin = 0;

    private int $other_skin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_enable_theme_write', '__return_true');
        add_filter('wpmcp_thrive_theme_active', '__return_true');
        $this->template = static fn () => 'thrive-theme';
        add_filter('template', $this->template);
        foreach (self::POST_TYPES as $type) {
            register_post_type($type, [ 'public' => false ]);
        }
        register_taxonomy('thrive_skin_tax', [ 'thrive_template', 'thrive_section', 'thrive_layout' ], [ 'public' => false ]);
        register_taxonomy('tcb_symbols_tax', [ 'tcb_symbol' ], [ 'public' => false ]);
        $this->skin = (int) self::factory()->term->create([ 'taxonomy' => 'thrive_skin_tax', 'name' => 'Shapeshift' ]);
        update_term_meta($this->skin, 'is_active', 1);
        $this->other_skin = (int) self::factory()->term->create([ 'taxonomy' => 'thrive_skin_tax', 'name' => 'Old skin' ]);
        update_term_meta($this->other_skin, 'is_active', 0);
    }

    protected function tearDown(): void
    {
        remove_filter('template', $this->template);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        remove_filter('wpmcp_thrive_theme_active', '__return_true');
        remove_filter('wpmcp_thrive_theme_active', '__return_false');
        unregister_taxonomy('thrive_skin_tax');
        unregister_taxonomy('tcb_symbols_tax');
        foreach (self::POST_TYPES as $type) {
            unregister_post_type($type);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function symbol(string $kind, string $title, string $html): int
    {
        $id = (int) self::factory()->post->create([ 'post_type' => 'tcb_symbol', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, 'tve_updated_post', $html);
        wp_set_object_terms($id, $kind, 'tcb_symbols_tax');
        return $id;
    }

    private function section(string $type, string $title, string $html, ?int $skin = null): int
    {
        $id = (int) self::factory()->post->create([ 'post_type' => 'thrive_section', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, 'type', $type);
        update_post_meta($id, 'content', $html);
        wp_set_object_terms($id, [ $skin ?? $this->skin ], 'thrive_skin_tax');
        return $id;
    }

    private function layout(string $title, ?int $skin = null): int
    {
        $id = (int) self::factory()->post->create([ 'post_type' => 'thrive_layout', 'post_title' => $title, 'post_status' => 'publish' ]);
        wp_set_object_terms($id, [ $skin ?? $this->skin ], 'thrive_skin_tax');
        return $id;
    }

    /** A template the way Thrive_Template::default_values() creates one. */
    private function tpl(string $title, string $primary, string $secondary, array $sections = [], array $meta = [], ?int $skin = null): int
    {
        $id = (int) self::factory()->post->create([ 'post_type' => 'thrive_template', 'post_title' => $title, 'post_status' => 'publish' ]);
        $meta += [
            'primary_template'   => $primary,
            'secondary_template' => $secondary,
            'variable_template'  => '',
            'format'             => 'standard',
            'default'            => 1,
            'layout'             => 0,
            'sections'           => $sections,
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        wp_set_object_terms($id, [ $skin ?? $this->skin ], 'thrive_skin_tax');
        return $id;
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    // Registration and availability.

    public function test_ops_are_on_the_theme_pair(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'list-thrive-templates', 'get-thrive-template', 'list-thrive-sections', 'get-thrive-theme-settings' ] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
        }
        foreach ([ 'update-thrive-template-settings', 'assign-thrive-template', 'update-thrive-section', 'update-thrive-theme-settings' ] as $op) {
            $this->assertSame('write', $ops[ $op ]['mode'], $op);
        }
    }

    public function test_ops_are_pro_tier(): void
    {
        Gate::set_pro_for_tests(false);
        $names = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        $this->assertNotContains('list-thrive-templates', $names);
        $this->assertNotContains('update-thrive-section', $names);
    }

    public function test_missing_theme_is_refused_with_the_install_hint(): void
    {
        remove_filter('wpmcp_thrive_theme_active', '__return_true');
        add_filter('wpmcp_thrive_theme_active', '__return_false');
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['list-thrive-templates']['dependency_met']);

        $out = $this->read('list-thrive-templates');
        $this->assertSame('plugin_missing', $out['error']['code']);
        $this->assertStringContainsString('Install and activate Thrive Theme Builder', $out['error']['message']);
        $this->assertStringContainsString(admin_url('themes.php'), $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $tpl = $this->tpl('Page', 'singular', 'page');
        $out = $this->write('update-thrive-template-settings', [ 'template_id' => $tpl, 'title' => 'New' ]);
        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString('wpmcp_enable_theme_write', $out['error']['message']);
    }

    // Reads.

    public function test_list_templates_keeps_to_the_active_skin_and_filters(): void
    {
        $header = $this->symbol('headers', 'Main header', '<div>h</div>');
        $page   = $this->tpl('Page', 'singular', 'page', [ 'header' => [ 'id' => $header, 'content' => '' ] ]);
        $post   = $this->tpl('Standard Post', 'singular', 'post');
        $arch   = $this->tpl('Category', 'archive', 'category');
        $this->tpl('Old page', 'singular', 'page', [], [], $this->other_skin);

        $out = $this->ok($this->read('list-thrive-templates'))['result'];
        $this->assertSame(3, $out['total']);
        $this->assertSame([ $page, $post, $arch ], array_column($out['templates'], 'id'));
        $first = $out['templates'][0];
        $this->assertSame('singular', $first['primary']);
        $this->assertSame('page', $first['secondary']);
        $this->assertTrue($first['default']);
        $this->assertSame($header, $first['header']['id']);
        $this->assertArrayNotHasKey('sections', $first, 'Lists stay small; get-thrive-template has the sections');

        $only = $this->ok($this->read('list-thrive-templates', [ 'primary' => 'archive' ]))['result'];
        $this->assertSame([ $arch ], array_column($only['templates'], 'id'));
    }

    public function test_get_template_has_sections_layout_and_assigned_posts(): void
    {
        $layout = $this->layout('Wide');
        $top    = $this->section('top', 'Hero', '<div>hero</div>');
        $tpl    = $this->tpl('Landing', 'singular', 'page', [
            'top'     => [ 'id' => $top, 'content' => '' ],
            'content' => [ 'id' => 0, 'content' => '<div>inline</div>' ],
        ], [ 'layout' => $layout, 'default' => 0 ]);
        $page   = (int) self::factory()->post->create([ 'post_type' => 'page' ]);
        update_post_meta($page, 'thrive_post_template', $tpl);

        $out = $this->ok($this->read('get-thrive-template', [ 'template_id' => $tpl ]))['result']['template'];
        $this->assertSame($layout, $out['layout_id']);
        $this->assertFalse($out['default']);
        $this->assertSame($top, $out['sections']['top']['id']);
        $this->assertTrue($out['sections']['top']['shared']);
        $this->assertArrayNotHasKey('content', $out['sections']['top'], 'A shared section is read with list-thrive-sections');
        $this->assertFalse($out['sections']['content']['shared']);
        $this->assertSame('<div>inline</div>', $out['sections']['content']['content']);
        $this->assertSame(hash('sha256', '<div>inline</div>'), $out['sections']['content']['hash']);
        $this->assertSame([ $page ], $out['assigned_posts']);
    }

    public function test_get_template_refuses_another_post_type_or_skin(): void
    {
        $post = (int) self::factory()->post->create();
        $this->assertSame('template_not_found', $this->read('get-thrive-template', [ 'template_id' => $post ])['error']['code']);
        $old = $this->tpl('Old', 'singular', 'page', [], [], $this->other_skin);
        $this->assertSame('template_not_found', $this->read('get-thrive-template', [ 'template_id' => $old ])['error']['code']);
    }

    public function test_list_sections_has_headers_footers_and_shared_sections_with_their_users(): void
    {
        $header = $this->symbol('headers', 'Main header', '<div>h</div>');
        $footer = $this->symbol('footers', 'Main footer', '<div>f</div>');
        $this->symbol('other', 'Some symbol', '<div>s</div>');
        $side   = $this->section('sidebar', 'Blog sidebar', '<div>side</div>');
        $this->section('sidebar', 'Old sidebar', '<div>old</div>', $this->other_skin);
        $tpl    = $this->tpl('Post', 'singular', 'post', [ 'header' => [ 'id' => $header, 'content' => '' ], 'sidebar' => [ 'id' => $side, 'content' => '' ] ]);

        $out  = $this->ok($this->read('list-thrive-sections'))['result'];
        $rows = array_column($out['sections'], null, 'id');
        $this->assertSame([ $header, $footer, $side ], array_keys($rows));
        $this->assertSame('header', $rows[ $header ]['type']);
        $this->assertSame('footer', $rows[ $footer ]['type']);
        $this->assertSame('sidebar', $rows[ $side ]['type']);
        $this->assertSame([ $tpl ], $rows[ $header ]['used_by']);
        $this->assertSame([ $tpl ], $rows[ $side ]['used_by']);
        $this->assertSame(hash('sha256', '<div>side</div>'), $rows[ $side ]['hash']);
        $this->assertArrayNotHasKey('content', $rows[ $side ]);

        $one = $this->ok($this->read('list-thrive-sections', [ 'type' => 'footer', 'with_content' => true ]))['result'];
        $this->assertSame([ $footer ], array_column($one['sections'], 'id'));
        $this->assertSame('<div>f</div>', $one['sections'][0]['content']);
    }

    public function test_get_theme_settings(): void
    {
        update_option('thrive-theme-logo-url', 'https://example.org/home');
        update_option('thrive-theme-favicon', 12);
        $out = $this->ok($this->read('get-thrive-theme-settings'))['result'];
        $this->assertSame([ 'id' => $this->skin, 'name' => 'Shapeshift' ], $out['skin']);
        $this->assertSame('https://example.org/home', $out['settings']['logo_url']);
        $this->assertSame(12, $out['settings']['favicon']);
        $this->assertSame(0, $out['settings']['default_featured_image']);
        $this->assertSame([ 'logo_url', 'favicon', 'default_featured_image' ], $out['writable']);
    }

    // Writes.

    public function test_update_template_settings_writes_what_thrive_reads_and_rolls_back(): void
    {
        $old_header = $this->symbol('headers', 'Old header', '<div>a</div>');
        $new_header = $this->symbol('headers', 'New header', '<div>b</div>');
        $footer     = $this->symbol('footers', 'Footer', '<div>f</div>');
        $layout     = $this->layout('Narrow');
        $sections   = [
            'header'  => [ 'id' => $old_header, 'content' => '', 'inherit_content_size' => 1 ],
            'content' => [ 'id' => 0, 'content' => '<div>c</div>' ],
        ];
        $tpl        = $this->tpl('Page', 'singular', 'page', $sections);

        $out = $this->ok($this->write('update-thrive-template-settings', [
            'template_id' => $tpl,
            'title'       => 'Page v2',
            'layout_id'   => $layout,
            'header_id'   => $new_header,
            'footer_id'   => $footer,
        ]));
        $this->assertNotEmpty($out['operation_id']);
        $stored = get_post_meta($tpl, 'sections', true);
        $this->assertSame([ 'id' => $new_header, 'content' => '', 'inherit_content_size' => 1 ], $stored['header'], 'Other keys of the section entry are kept');
        $this->assertSame($footer, $stored['footer']['id']);
        $this->assertSame('<div>c</div>', $stored['content']['content']);
        $this->assertSame($layout, (int) get_post_meta($tpl, 'layout', true));
        $this->assertSame('Page v2', get_post($tpl)->post_title);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($sections, get_post_meta($tpl, 'sections', true));
        $this->assertSame(0, (int) get_post_meta($tpl, 'layout', true));
        $this->assertSame('Page', get_post($tpl)->post_title);
    }

    public function test_update_template_settings_refuses_before_any_snapshot(): void
    {
        $tpl    = $this->tpl('Page', 'singular', 'page');
        $footer = $this->symbol('footers', 'Footer', '<div>f</div>');
        $before = $this->snapshot_count();

        $this->assertSame('invalid_header', $this->write('update-thrive-template-settings', [ 'template_id' => $tpl, 'header_id' => $footer ])['error']['code']);
        $foreign = $this->layout('Other skin layout', $this->other_skin);
        $this->assertSame('invalid_layout', $this->write('update-thrive-template-settings', [ 'template_id' => $tpl, 'layout_id' => $foreign ])['error']['code']);
        $this->assertSame('template_not_found', $this->write('update-thrive-template-settings', [ 'template_id' => 999999, 'title' => 'x' ])['error']['code']);
        $this->assertSame($before, $this->snapshot_count());
    }

    public function test_assign_template_sets_and_clears_the_page_template(): void
    {
        $tpl  = $this->tpl('Landing', 'singular', 'page', [], [ 'default' => 0 ]);
        $page = (int) self::factory()->post->create([ 'post_type' => 'page' ]);

        $set = $this->ok($this->write('assign-thrive-template', [ 'post_id' => $page, 'template_id' => $tpl ]));
        $this->assertSame($tpl, (int) get_post_meta($page, 'thrive_post_template', true));
        $this->assertSame($tpl, $set['result']['template_id']);

        $clear = $this->ok($this->write('assign-thrive-template', [ 'post_id' => $page, 'template_id' => 0 ]));
        $this->assertSame('', get_post_meta($page, 'thrive_post_template', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $clear['operation_id']));
        $this->assertSame($tpl, (int) get_post_meta($page, 'thrive_post_template', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $set['operation_id']));
        $this->assertSame('', get_post_meta($page, 'thrive_post_template', true));
    }

    public function test_assign_template_refuses_a_template_thrive_would_not_offer(): void
    {
        $archive = $this->tpl('Category', 'archive', 'category');
        $post_t  = $this->tpl('Standard Post', 'singular', 'post');
        $this->tpl('Page', 'singular', 'page');
        $page    = (int) self::factory()->post->create([ 'post_type' => 'page' ]);
        $before  = $this->snapshot_count();

        $out = $this->write('assign-thrive-template', [ 'post_id' => $page, 'template_id' => $archive ]);
        $this->assertSame('template_not_assignable', $out['error']['code']);
        $out = $this->write('assign-thrive-template', [ 'post_id' => $page, 'template_id' => $post_t ]);
        $this->assertSame('template_not_assignable', $out['error']['code'], 'Pages get page templates when the skin has any, as Thrive\'s own picker does');
        $this->assertSame($before, $this->snapshot_count());
    }

    public function test_update_shared_section_content_is_pinned_to_its_hash_and_rolls_back(): void
    {
        $side = $this->section('sidebar', 'Blog sidebar', '<div>side</div>');

        $stale = $this->write('update-thrive-section', [ 'section_id' => $side, 'content' => '<div>x</div>', 'expected_hash' => hash('sha256', 'nope') ]);
        $this->assertSame('content_changed', $stale['error']['code']);
        $this->assertSame('missing_expected_hash', $this->write('update-thrive-section', [ 'section_id' => $side, 'content' => '<div>x</div>' ])['error']['code']);

        $out = $this->ok($this->write('update-thrive-section', [
            'section_id'    => $side,
            'title'         => 'Sidebar v2',
            'content'       => '<div class="thrv_wrapper">new</div>',
            'expected_hash' => hash('sha256', '<div>side</div>'),
        ]));
        $this->assertSame('<div class="thrv_wrapper">new</div>', get_post_meta($side, 'content', true));
        $this->assertSame('Sidebar v2', get_post($side)->post_title);
        $this->assertSame(hash('sha256', '<div class="thrv_wrapper">new</div>'), $out['result']['section']['hash']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('<div>side</div>', get_post_meta($side, 'content', true));
        $this->assertSame('Blog sidebar', get_post($side)->post_title);
    }

    public function test_update_header_symbol_content_rolls_back(): void
    {
        $header = $this->symbol('headers', 'Main header', '<div>h</div>');
        $out    = $this->ok($this->write('update-thrive-section', [
            'section_id'    => $header,
            'content'       => '<div>h2</div>',
            'expected_hash' => hash('sha256', '<div>h</div>'),
        ]));
        $this->assertSame('<div>h2</div>', get_post_meta($header, 'tve_updated_post', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('<div>h</div>', get_post_meta($header, 'tve_updated_post', true));
    }

    public function test_update_inline_template_section_rolls_back(): void
    {
        $sections = [ 'top' => [ 'id' => 0, 'content' => '<div>top</div>', 'hide' => 0 ] ];
        $tpl      = $this->tpl('Page', 'singular', 'page', $sections);
        $out      = $this->ok($this->write('update-thrive-section', [
            'template_id'   => $tpl,
            'type'          => 'top',
            'content'       => '<div>top2</div>',
            'expected_hash' => hash('sha256', '<div>top</div>'),
        ]));
        $this->assertSame([ 'id' => 0, 'content' => '<div>top2</div>', 'hide' => 0 ], get_post_meta($tpl, 'sections', true)['top']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($sections, get_post_meta($tpl, 'sections', true));
    }

    public function test_update_section_refuses_a_shared_slot_and_raw_markup_without_unfiltered_html(): void
    {
        $top = $this->section('top', 'Hero', '<div>hero</div>');
        $tpl = $this->tpl('Page', 'singular', 'page', [ 'top' => [ 'id' => $top, 'content' => '' ] ]);
        $out = $this->write('update-thrive-section', [ 'template_id' => $tpl, 'type' => 'top', 'content' => '<p>x</p>', 'expected_hash' => hash('sha256', '') ]);
        $this->assertSame('section_is_shared', $out['error']['code']);
        $this->assertSame($top, $out['error']['data']['section_id']);

        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        $user   = get_user_by('id', $editor);
        $user->add_cap('edit_theme_options');
        $user->remove_cap('unfiltered_html');
        if (is_multisite()) {
            $this->markTestSkipped('Only super admins hold unfiltered_html on a network.');
        }
        add_filter('map_meta_cap', $deny = static fn ($caps, $cap) => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2);
        wp_set_current_user($editor);
        $out = $this->write('update-thrive-section', [ 'section_id' => $top, 'content' => '<p>x</p>', 'expected_hash' => hash('sha256', '<div>hero</div>') ]);
        remove_filter('map_meta_cap', $deny, 10);
        $this->assertSame('no_unfiltered_html', $out['error']['code']);
    }

    public function test_update_theme_settings_is_one_undo_point(): void
    {
        $image = (int) self::factory()->attachment->create_object([ 'file' => 'thrive-default.png', 'post_mime_type' => 'image/png' ]);
        update_option('thrive-theme-logo-url', 'https://example.org/old');

        $out = $this->ok($this->write('update-thrive-theme-settings', [ 'settings' => [ 'logo_url' => 'https://example.org/new', 'default_featured_image' => $image ] ]));
        $this->assertSame('https://example.org/new', get_option('thrive-theme-logo-url'));
        $this->assertSame($image, (int) get_option('thrive-theme-default-featured-image'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('https://example.org/old', get_option('thrive-theme-logo-url'));
        $this->assertFalse(get_option('thrive-theme-default-featured-image'));
    }

    public function test_update_theme_settings_refuses_unknown_keys_and_non_images(): void
    {
        $doc    = (int) self::factory()->attachment->create_object([ 'file' => 'terms.pdf', 'post_mime_type' => 'application/pdf' ]);
        $before = $this->snapshot_count();
        $this->assertSame('setting_not_writable', $this->write('update-thrive-theme-settings', [ 'settings' => [ 'thrive_sidebars' => [] ] ])['error']['code']);
        $this->assertSame('invalid_setting_value', $this->write('update-thrive-theme-settings', [ 'settings' => [ 'favicon' => $doc ] ])['error']['code']);
        $this->assertSame('invalid_setting_value', $this->write('update-thrive-theme-settings', [ 'settings' => [ 'logo_url' => 'javascript:alert(1)' ] ])['error']['code']);
        $this->assertSame($before, $this->snapshot_count());
    }
}
