<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\WPML_Double;
use WPMCP\Tools\I18n\I18n_Adapter;

require_once __DIR__ . '/../../support/wpml-double.php';

/**
 * Page translation in a few calls (issue #498): find-missing-translations,
 * create-translation, get-page-text and apply-page-text, paid-tier ops on
 * the plugin-data pair.
 *
 * The shared suite loads Polylang without booting it (no language, no
 * wp-admin request, so no API), and WPML is commercial, so here the
 * multilingual side is the WPML stand-in in tests/support/wpml-double.php,
 * which answers WPML's documented hooks over an in-memory translations
 * table. Elementor and Yoast SEO are the real plugins from the shared
 * install. The same ops run through the real Polylang in
 * I18nPolylangLiveTest.
 */
class I18nPackTest extends \WP_UnitTestCase
{
    /** Every other plugin-data plugin, off so only the multilingual plugin decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active', 'wpmcp_amelia_active' ];

    private const OPS = [
        'find-missing-translations' => 'read',
        'get-page-text'             => 'read',
        'create-translation'        => 'write',
        'apply-page-text'           => 'write',
    ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        WPML_Double::install();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        wpmcp_test_grant_network_admin(get_current_user_id());
    }

    protected function tearDown(): void
    {
        WPML_Double::uninstall();
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_multilingual_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? null;
    }

    /** A page in $lang, registered with the stand-in. */
    private function page(string $lang = 'en', array $fields = [], ?int $trid = null): int
    {
        $id = self::factory()->post->create($fields + [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About us' ]);
        WPML_Double::put($id, 'post_page', $lang, $trid);
        return $id;
    }

    /** Everything a page stores, to prove a source was not touched. */
    private function stored(int $post_id): array
    {
        global $wpdb;
        clean_post_cache($post_id);
        $row  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id), ARRAY_A);
        $meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id), ARRAY_A);
        $terms = wp_get_object_terms($post_id, get_object_taxonomies(get_post_type($post_id)), [ 'fields' => 'tt_ids' ]);
        sort($terms);
        return [ 'row' => $row, 'meta' => $meta, 'terms' => $terms ];
    }

    private static function elementor_tree(): array
    {
        return [
            [
                'id'       => 'sec1',
                'elType'   => 'section',
                'settings' => [ 'css_classes' => 'hero' ],
                'elements' => [
                    [
                        'id'       => 'col1',
                        'elType'   => 'column',
                        'settings' => [],
                        'elements' => [
                            [ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Welcome home', 'header_size' => 'h1' ], 'elements' => [] ],
                            [ 'id' => 'tx1', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>We build <strong>houses</strong>.</p>' ], 'elements' => [] ],
                            [ 'id' => 'bt1', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Contact us', 'link' => [ 'url' => 'https://example.org/contact' ], 'button_css_id' => 'cta' ], 'elements' => [] ],
                            [ 'id' => 'il1', 'elType' => 'widget', 'widgetType' => 'icon-list', 'settings' => [ 'icon_list' => [
                                [ '_id' => 'a1', 'text' => 'Fast', 'link' => [ 'url' => '' ] ],
                                [ '_id' => 'a2', 'text' => 'Friendly', 'link' => [ 'url' => '' ] ],
                            ] ], 'elements' => [] ],
                            [ 'id' => 'dy1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Dynamic', '__dynamic__' => [ 'title' => '[elementor-tag id="x" name="post-title" settings="%7B%7D"]' ] ], 'elements' => [] ],
                            [ 'id' => 'eh1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'escaped-html', 'value' => 'Atomic title' ], 'tag' => [ '$$type' => 'string', 'value' => 'h2' ] ], 'elements' => [] ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function elementor_page(string $lang = 'en', array $fields = []): int
    {
        $id = $this->page($lang, $fields + [ 'post_title' => 'Home', 'post_content' => '<p>Rendered copy</p>' ]);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode(self::elementor_tree())));
        update_post_meta($id, '_wp_page_template', 'elementor_canvas');
        return $id;
    }

    private const BLOCKS = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Our story</h2>\n<!-- /wp:heading -->\n\n"
        . "<!-- wp:paragraph -->\n<p>Founded in <em>1999</em> &amp; still going.</p>\n<!-- /wp:paragraph -->\n\n"
        . "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/shop\">Shop now</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n"
        . "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n"
        . "<!-- wp:latest-posts /-->\n\n"
        . "<!-- wp:html -->\n<script>var x = 'not text';</script>\n<!-- /wp:html -->";

    // ---------------------------------------------------------------
    // Catalog, gating and the tool surface
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_the_pair_is_available_with_a_multilingual_plugin_alone_and_not_without_it_or_without_pro(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());

        add_filter('wpmcp_multilingual_active', '__return_false');
        $this->assertFalse((new Plugin_Data_Integration())->is_available());
        remove_all_filters('wpmcp_multilingual_active');

        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $this->assertFalse($integration->is_available());
        $this->assertNotContains('get-page-text', array_column($integration->catalog()['operations'], 'name'));
    }

    public function test_with_neither_plugin_active_every_op_answers_multilingual_inactive(): void
    {
        WPML_Double::uninstall();
        I18n_Adapter::use_plugin_for_tests('');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);

        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['create-translation']['dependency_met']);
        $this->assertSame('multilingual_inactive', $this->code($this->read('find-missing-translations', [ 'language' => 'de' ])));
        $this->assertSame('multilingual_inactive', $this->code($this->read('get-page-text', [ 'post_id' => $page ])));
        $this->assertSame('multilingual_inactive', $this->code($this->write('create-translation', [ 'post_id' => $page, 'language' => 'de' ])));
        $this->assertSame('multilingual_inactive', $this->code($this->write('apply-page-text', [ 'post_id' => $page, 'hash' => 'x', 'segments' => [ [ 'key' => 'title', 'text' => 'X' ] ] ])));
        remove_all_filters('wpmcp_buddypress_active');
        I18n_Adapter::use_plugin_for_tests(null);
    }

    /**
     * On a site where Polylang is the only plugin-data plugin, the pair
     * registers (two tools) through the real registration path, and the
     * whole tools/list payload stays within the byte budget
     * ToolsListBudgetTest pins. The shared suite has Polylang installed;
     * the filter stands in for its booted API.
     */
    public function test_with_polylang_alone_the_pair_registers_and_tools_list_stays_within_budget(): void
    {
        WPML_Double::uninstall();
        add_filter('wpmcp_multilingual_active', '__return_true');
        $this->assertSame('polylang', I18n_Adapter::active_plugin());

        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $prop->setValue($plugin, new Registrar());
        try {
            $plugin->register_abilities();
            $abilities = $plugin->registrar()->all();
        } finally {
            $prop->setValue($plugin, $original);
        }
        $names = array_map(static fn ($a) => $a->name, $abilities);
        $this->assertContains('wpmcp/plugin-data-read', $names);
        $this->assertContains('wpmcp/plugin-data-write', $names);

        $payload = [];
        foreach ($abilities as $ability) {
            $payload[] = [
                'name'        => $ability->name,
                'description' => $ability->description,
                'inputSchema' => $ability->input_schema,
                'annotations' => [ 'readOnlyHint' => $ability->read_only_hint, 'destructiveHint' => $ability->destructive_hint, 'idempotentHint' => $ability->idempotent_hint ],
            ];
        }
        $budget = (new \ReflectionClassConstant(\WPMCP\Tests\Free\Platform\ToolsListBudgetTest::class, 'TOOLS_LIST_BYTE_BUDGET'))->getValue();
        $bytes  = strlen((string) wp_json_encode($payload));
        $this->assertLessThanOrEqual($budget, $bytes, sprintf('%d bytes over %d tools', $bytes, count($payload)));
    }

    // ---------------------------------------------------------------
    // find-missing-translations
    // ---------------------------------------------------------------

    public function test_find_missing_translations_lists_pages_with_a_language_and_no_translation_paged(): void
    {
        $translated = $this->page('en', [ 'post_title' => 'Translated' ]);
        $trid       = WPML_Double::$rows[ 'post_page:' . $translated ]['trid'];
        $german     = $this->page('de', [ 'post_title' => 'Uebersetzt' ], $trid);
        $missing_a  = $this->page('en', [ 'post_title' => 'Missing A' ]);
        $missing_b  = $this->page('fr', [ 'post_title' => 'Missing B' ]);
        $no_lang    = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);
        $draft      = $this->page('en', [ 'post_status' => 'draft' ]);
        $post       = self::factory()->post->create([ 'post_status' => 'publish' ]);
        WPML_Double::put($post, 'post_post', 'en');

        $out = $this->ok($this->read('find-missing-translations', [ 'language' => 'de', 'post_type' => 'page', 'per_page' => 1 ]))['result'];
        $this->assertSame(2, $out['total']);
        $this->assertSame(2, $out['pages']);
        $this->assertCount(1, $out['items']);
        $page2 = $this->ok($this->read('find-missing-translations', [ 'language' => 'de', 'post_type' => 'page', 'per_page' => 1, 'page' => 2 ]))['result'];
        $ids   = [ $out['items'][0]['post_id'], $page2['items'][0]['post_id'] ];
        sort($ids);
        $this->assertSame([ $missing_a, $missing_b ], $ids);
        $this->assertSame([ 'en' => $missing_a ], $out['items'][0]['post_id'] === $missing_a ? $out['items'][0]['translations'] : $page2['items'][0]['translations']);
        $this->assertNotContains($translated, $ids);
        $this->assertNotContains($german, $ids);
        $this->assertNotContains($no_lang, $ids);
        $this->assertNotContains($draft, $ids);

        $drafts = $this->ok($this->read('find-missing-translations', [ 'language' => 'de', 'post_type' => 'page', 'status' => 'draft' ]))['result'];
        $this->assertSame([ $draft ], array_column($drafts['items'], 'post_id'));

        $posts = $this->ok($this->read('find-missing-translations', [ 'language' => 'fr', 'post_type' => 'post' ]))['result'];
        $this->assertSame([ $post ], array_column($posts['items'], 'post_id'));

        $this->assertSame('unknown_language', $this->code($this->read('find-missing-translations', [ 'language' => 'xx' ])));
    }

    // ---------------------------------------------------------------
    // create-translation
    // ---------------------------------------------------------------

    public function test_create_translation_makes_a_linked_draft_with_builder_data_meta_image_and_mapped_terms(): void
    {
        $source = $this->elementor_page('en');
        $thumb  = self::factory()->attachment->create_object('photo.jpg', $source, [ 'post_mime_type' => 'image/jpeg' ]);
        $thumb_de = self::factory()->attachment->create_object('foto.jpg', 0, [ 'post_mime_type' => 'image/jpeg' ]);
        $t        = WPML_Double::put($thumb, 'post_attachment', 'en');
        WPML_Double::put($thumb_de, 'post_attachment', 'de', $t);
        set_post_thumbnail($source, $thumb);
        update_post_meta($source, 'custom_note', 'keep me');

        register_taxonomy_for_object_type('category', 'page');
        register_taxonomy_for_object_type('post_tag', 'page');
        $news    = self::factory()->category->create([ 'name' => 'News' ]);
        $news_de = self::factory()->category->create([ 'name' => 'Nachrichten' ]);
        $only_en = self::factory()->category->create([ 'name' => 'English only' ]);
        $trid    = WPML_Double::put($news, 'tax_category', 'en');
        WPML_Double::put($news_de, 'tax_category', 'de', $trid);
        WPML_Double::put($only_en, 'tax_category', 'en');
        wp_set_object_terms($source, [ $news, $only_en ], 'category');
        $before = $this->stored($source);

        $out = $this->ok($this->write('create-translation', [ 'post_id' => $source, 'language' => 'de' ]));
        $res = $out['result'];
        $new = (int) $res['post_id'];

        $this->assertTrue($out['recoverable']);
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame('draft', get_post_status($new));
        $this->assertSame('page', get_post_type($new));
        $this->assertSame('de', I18n_Adapter::get_post_language($new));
        $this->assertSame([ 'en' => $source, 'de' => $new ], array_map(static fn ($t) => $t['post_id'], I18n_Adapter::get_post_translations($source)));
        $this->assertSame('de', WPML_Double::$rows[ 'post_page:' . $new ]['lang']);
        $this->assertSame('en', WPML_Double::$rows[ 'post_page:' . $new ]['source']);
        $this->assertSame(get_post_meta($source, '_elementor_data', true), get_post_meta($new, '_elementor_data', true));
        $this->assertSame('builder', get_post_meta($new, '_elementor_edit_mode', true));
        $this->assertSame('elementor_canvas', get_post_meta($new, '_wp_page_template', true));
        $this->assertSame('keep me', get_post_meta($new, 'custom_note', true));
        $this->assertSame($thumb_de, (int) get_post_thumbnail_id($new));
        $this->assertSame([ $news_de ], wp_get_object_terms($new, 'category', [ 'fields' => 'ids' ]));
        $this->assertSame([ [ 'taxonomy' => 'category', 'term_id' => $only_en, 'name' => 'English only' ] ], $res['unmapped_terms']);
        $this->assertSame($before, $this->stored($source), 'the source is untouched');

        $this->assertSame('translation_exists', $this->code($this->write('create-translation', [ 'post_id' => $source, 'language' => 'de' ])));
    }

    public function test_rollback_operation_removes_the_translation_and_unlinks_it_from_the_group(): void
    {
        $source = $this->page('en');
        $fr     = $this->page('fr', [], WPML_Double::$rows[ 'post_page:' . $source ]['trid']);
        $before = $this->stored($source);
        $group  = I18n_Adapter::get_post_translations($source);

        $out = $this->ok($this->write('create-translation', [ 'post_id' => $source, 'language' => 'de', 'title' => 'Ueber uns' ]));
        $new = (int) $out['result']['post_id'];
        $this->assertSame('Ueber uns', get_the_title($new));
        $this->assertArrayHasKey('de', I18n_Adapter::get_post_translations($source));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($new));
        $this->assertSame($group, I18n_Adapter::get_post_translations($source));
        $this->assertSame($before, $this->stored($source));
        $this->assertArrayHasKey('fr', I18n_Adapter::get_post_translations($fr));
    }

    public function test_create_translation_refuses_bad_languages_a_source_without_one_and_missing_capabilities(): void
    {
        $source = $this->page('en');
        $this->assertSame('unknown_language', $this->code($this->write('create-translation', [ 'post_id' => $source, 'language' => 'xx' ])));
        $this->assertSame('same_language', $this->code($this->write('create-translation', [ 'post_id' => $source, 'language' => 'en' ])));

        $bare = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->assertSame('source_language_missing', $this->code($this->write('create-translation', [ 'post_id' => $bare, 'language' => 'de' ])));
        $this->assertSame('post_not_found', $this->code($this->write('create-translation', [ 'post_id' => 999999, 'language' => 'de' ])));

        // Creating a page needs the type's create capability (issue #482).
        $author = self::factory()->user->create([ 'role' => 'author' ]);
        $own    = $this->page('en', [ 'post_type' => 'post', 'post_author' => $author ]);
        WPML_Double::put($own, 'post_post', 'en');
        wp_set_current_user($author);
        $this->assertSame('operation_denied', $this->code($this->write('create-translation', [ 'post_id' => $source, 'language' => 'de' ])));
        $this->ok($this->write('create-translation', [ 'post_id' => $own, 'language' => 'de' ]));

        // A password-protected page another user cannot edit is withheld (issues #450, #483).
        $locked = $this->page('en', [ 'post_type' => 'post', 'post_password' => 'secret', 'post_author' => $this->admin ]);
        WPML_Double::put($locked, 'post_post', 'en');
        $this->assertSame('post_not_found', $this->code($this->write('create-translation', [ 'post_id' => $locked, 'language' => 'de' ])));
        $this->assertSame('post_not_found', $this->code($this->read('get-page-text', [ 'post_id' => $locked ])));
    }

    public function test_a_password_protected_source_gives_a_protected_translation(): void
    {
        $source = $this->page('en', [ 'post_password' => 'secret' ]);
        $new    = (int) $this->ok($this->write('create-translation', [ 'post_id' => $source, 'language' => 'de' ]))['result']['post_id'];
        $this->assertSame('secret', get_post($new)->post_password);
    }

    // ---------------------------------------------------------------
    // get-page-text
    // ---------------------------------------------------------------

    public function test_get_page_text_reads_an_elementor_page_as_segments(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor is not loaded.');
        }
        $page = $this->elementor_page('en', [ 'post_excerpt' => 'Short intro' ]);
        update_post_meta($page, '_yoast_wpseo_title', 'Home | Acme');
        update_post_meta($page, '_yoast_wpseo_metadesc', 'We build houses.');

        $out = $this->ok($this->read('get-page-text', [ 'post_id' => $page ]))['result'];
        $this->assertSame('elementor', $out['builder']);
        $this->assertSame('en', $out['language']);
        $by = array_column($out['segments'], null, 'key');
        $this->assertSame([ 'title', 'excerpt', 'seo.title', 'seo.description', 'el:h1:title', 'el:tx1:editor', 'el:bt1:text', 'el:il1:icon_list:0:text', 'el:il1:icon_list:1:text', 'el:eh1:title' ], array_keys($by));
        $this->assertSame([ 'key' => 'title', 'kind' => 'title', 'text' => 'Home', 'where' => 'post title' ], $by['title']);
        $this->assertSame('seo_description', $by['seo.description']['kind']);
        $this->assertSame('html', $by['el:tx1:editor']['kind']);
        $this->assertSame('<p>We build <strong>houses</strong>.</p>', $by['el:tx1:editor']['text']);
        $this->assertSame('text', $by['el:bt1:text']['kind']);
        $this->assertSame('Fast', $by['el:il1:icon_list:0:text']['text']);
        $this->assertSame('Atomic title', $by['el:eh1:title']['text']);
        $this->assertStringContainsString('heading', $by['el:h1:title']['where']);
        $this->assertSame(10, $out['total']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $out['hash']);

        $again = $this->ok($this->read('get-page-text', [ 'post_id' => $page, 'page' => 2, 'per_page' => 4 ]))['result'];
        $this->assertSame($out['hash'], $again['hash'], 'the hash is stable and covers the whole page');
        $this->assertSame([ 'el:h1:title', 'el:tx1:editor', 'el:bt1:text', 'el:il1:icon_list:0:text' ], array_column($again['segments'], 'key'));
        $this->assertSame(3, $again['pages']);
    }

    public function test_get_page_text_reads_block_and_classic_pages(): void
    {
        $blocks = $this->page('en', [ 'post_title' => 'Story', 'post_content' => self::BLOCKS ]);
        $out    = $this->ok($this->read('get-page-text', [ 'post_id' => $blocks ]))['result'];
        $this->assertSame('blocks', $out['builder']);
        $segments = array_values(array_filter($out['segments'], static fn ($s) => 0 === strpos($s['key'], 'content:')));
        $this->assertSame(
            [ 'Our story', 'Founded in <em>1999</em> &amp; still going.', 'Shop now', 'One', 'Two' ],
            array_column($segments, 'text')
        );
        $this->assertSame([ 'core/heading', 'core/paragraph', 'core/button', 'core/list-item', 'core/list-item' ], array_column($segments, 'where'));
        $this->assertSame([ 'text', 'html', 'text', 'text', 'text' ], array_column($segments, 'kind'));

        $classic = $this->page('en', [ 'post_content' => "<h2>Hours</h2>\n<p>Open <a href=\"/map\">daily</a>.</p>\n\nSecond line\n<style>p{color:red}</style>" ]);
        $out     = $this->ok($this->read('get-page-text', [ 'post_id' => $classic ]))['result'];
        $this->assertSame('classic', $out['builder']);
        $content = array_values(array_filter($out['segments'], static fn ($s) => 0 === strpos($s['key'], 'content:')));
        $this->assertSame([ 'Hours', 'Open <a href="/map">daily</a>.', 'Second line' ], array_column($content, 'text'));
        $this->assertSame([ 'classic', 'classic', 'classic' ], array_column($content, 'where'));
    }

    // ---------------------------------------------------------------
    // apply-page-text
    // ---------------------------------------------------------------

    public function test_apply_page_text_writes_every_segment_as_one_change_and_rollback_restores_it_exactly(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor is not loaded.');
        }
        $source = $this->elementor_page();
        update_post_meta($source, '_yoast_wpseo_metadesc', 'We build houses.');
        $new          = (int) $this->ok($this->write('create-translation', [ 'post_id' => $source, 'language' => 'de' ]))['result']['post_id'];
        // A draft's date floats (post_date_gmt stays zero) and core moves it
        // to now on every save unless told otherwise; an old one shows
        // whether the rollback puts it back.
        global $wpdb;
        $wpdb->update($wpdb->posts, [ 'post_date' => '2020-01-02 03:04:05' ], [ 'ID' => $new ]);
        clean_post_cache($new);
        $source_state = $this->stored($source);
        $before       = $this->stored($new);
        $text         = $this->ok($this->read('get-page-text', [ 'post_id' => $new ]))['result'];

        $out = $this->ok($this->write('apply-page-text', [
            'post_id'  => $new,
            'hash'     => $text['hash'],
            'segments' => [
                [ 'key' => 'title', 'text' => 'Startseite' ],
                [ 'key' => 'seo.description', 'text' => 'Wir bauen Haeuser.' ],
                [ 'key' => 'el:h1:title', 'text' => 'Willkommen' ],
                [ 'key' => 'el:tx1:editor', 'text' => '<p>Wir bauen <strong>Haeuser</strong>.</p>' ],
                [ 'key' => 'el:il1:icon_list:1:text', 'text' => 'Freundlich' ],
                [ 'key' => 'el:eh1:title', 'text' => 'Atomarer Titel' ],
            ],
        ]))['result'];

        $this->assertSame(6, $out['written']);
        $this->assertNotSame($text['hash'], $out['hash']);
        $after = array_column($this->ok($this->read('get-page-text', [ 'post_id' => $new ]))['result']['segments'], 'text', 'key');
        $this->assertSame('Startseite', $after['title']);
        $this->assertSame('Wir bauen Haeuser.', $after['seo.description']);
        $this->assertSame('Willkommen', $after['el:h1:title']);
        $this->assertSame('Freundlich', $after['el:il1:icon_list:1:text']);
        $this->assertSame('Fast', $after['el:il1:icon_list:0:text']);
        $this->assertSame('Atomarer Titel', $after['el:eh1:title']);
        $data = json_decode((string) get_post_meta($new, '_elementor_data', true), true);
        $this->assertSame('https://example.org/contact', $data[0]['elements'][0]['elements'][2]['settings']['link']['url']);
        $this->assertSame('Dynamic', $data[0]['elements'][0]['elements'][4]['settings']['title']);
        $this->assertSame($source_state, $this->stored($source), 'the source is byte-identical');

        $this->assertTrue(Rollback_Service::restore_operation($this->last_operation_id()));
        $this->assertSame($before, $this->stored($new));
    }

    public function test_apply_page_text_rewrites_block_runs_in_place(): void
    {
        $page = $this->page('en', [ 'post_content' => self::BLOCKS ]);
        $text = $this->ok($this->read('get-page-text', [ 'post_id' => $page ]))['result'];
        $keys = array_column($text['segments'], 'key', 'text');

        $this->ok($this->write('apply-page-text', [
            'post_id'  => $page,
            'hash'     => $text['hash'],
            'segments' => [
                [ 'key' => $keys['Founded in <em>1999</em> &amp; still going.'], 'text' => 'Gegruendet <em>1999</em> &amp; immer noch da.' ],
                [ 'key' => $keys['Shop now'], 'text' => 'Jetzt kaufen' ],
                [ 'key' => $keys['Two'], 'text' => 'Zwei' ],
            ],
        ]));

        $expected = str_replace(
            [ 'Founded in <em>1999</em> &amp; still going.', '>Shop now<', '<li>Two</li>' ],
            [ 'Gegruendet <em>1999</em> &amp; immer noch da.', '>Jetzt kaufen<', '<li>Zwei</li>' ],
            self::BLOCKS
        );
        $this->assertSame($expected, get_post($page)->post_content);
    }

    public function test_apply_page_text_writes_nothing_on_an_unknown_or_repeated_segment_or_a_stale_hash(): void
    {
        $page   = $this->page('en', [ 'post_content' => self::BLOCKS ]);
        $text   = $this->ok($this->read('get-page-text', [ 'post_id' => $page ]))['result'];
        $before = $this->stored($page);
        $ops    = $this->count_operations();

        $out = $this->write('apply-page-text', [ 'post_id' => $page, 'hash' => $text['hash'], 'segments' => [ [ 'key' => 'title', 'text' => 'Neu' ], [ 'key' => 'content:99', 'text' => 'X' ] ] ]);
        $this->assertSame('unknown_segment', $this->code($out));
        $this->assertSame([ 'content:99' ], $out['error']['data']['unknown']);

        $out = $this->write('apply-page-text', [ 'post_id' => $page, 'hash' => $text['hash'], 'segments' => [ [ 'key' => 'title', 'text' => 'A' ], [ 'key' => 'title', 'text' => 'B' ] ] ]);
        $this->assertSame('duplicate_segment', $this->code($out));

        $out = $this->write('apply-page-text', [ 'post_id' => $page, 'hash' => 'deadbeef', 'segments' => [ [ 'key' => 'title', 'text' => 'Neu' ] ] ]);
        $this->assertSame('stale_hash', $this->code($out));
        $this->assertSame($text['hash'], $out['error']['data']['hash']);

        $this->assertSame($before, $this->stored($page));
        $this->assertSame($ops, $this->count_operations(), 'no undo point was written');

        $forced = $this->ok($this->write('apply-page-text', [ 'post_id' => $page, 'hash' => 'deadbeef', 'force' => true, 'segments' => [ [ 'key' => 'title', 'text' => 'Neu' ] ] ]));
        $this->assertSame('Neu', get_the_title($page));
        $this->assertTrue($forced['recoverable']);
    }

    public function test_apply_page_text_keeps_markup_safe_for_a_user_without_unfiltered_html(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor is not loaded.');
        }
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        $page   = $this->elementor_page('en', [ 'post_author' => $editor ]);
        wp_set_current_user($editor);
        $filter = static function (array $caps, string $cap) {
            return 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
        };
        add_filter('map_meta_cap', $filter, 10, 2);
        try {
            $hash = $this->ok($this->read('get-page-text', [ 'post_id' => $page ]))['result']['hash'];
            $this->ok($this->write('apply-page-text', [ 'post_id' => $page, 'hash' => $hash, 'segments' => [ [ 'key' => 'el:tx1:editor', 'text' => '<p>Hi<script>alert(1)</script></p>' ] ] ]));
        } finally {
            remove_filter('map_meta_cap', $filter, 10);
        }
        $this->assertStringNotContainsString('<script>', (string) get_post_meta($page, '_elementor_data', true));
    }

    private function last_operation_id(): string
    {
        global $wpdb;
        return (string) $wpdb->get_var('SELECT operation_id FROM ' . Snapshot_Store::table_name() . ' ORDER BY id DESC LIMIT 1');
    }

    private function count_operations(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }
}
