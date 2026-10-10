<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\I18n\I18n_Adapter;

/**
 * Issue #498: the page translation ops against the real Polylang, with the
 * real Elementor and Yoast SEO from the shared install.
 *
 * It runs only in the local gate's live Polylang leg (bin/test-local.sh, or
 * bin/test-local.sh --live-polylang alone), which sets WPMCP_LIVE_POLYLANG=1
 * so tests/bootstrap.php boots Polylang the way wp-admin does. This class
 * then adds three languages and starts the parts Polylang only starts on a
 * site that has languages: its post and term handlers and its sync between
 * translations, with every sync option switched on, so a write that leaked
 * from a translation into its source would show here.
 *
 * Everywhere else Polylang's API is absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group polylang-live
 */
class I18nPolylangLiveTest extends \WP_UnitTestCase
{
    private static bool $booted = false;

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        if (! getenv('WPMCP_LIVE_POLYLANG') || ! function_exists('pll_save_post_translations') || ! function_exists('PLL')) {
            return;
        }
        $model = PLL()->model;
        foreach ([ [ 'English', 'en', 'en_US' ], [ 'Deutsch', 'de', 'de_DE' ], [ 'Francais', 'fr', 'fr_FR' ] ] as [ $name, $slug, $locale ]) {
            if (! $model->get_language($slug)) {
                $model->add_language([ 'name' => $name, 'slug' => $slug, 'locale' => $locale, 'rtl' => false, 'term_group' => 0 ]);
            }
        }
        $model->clean_languages_cache();
        if (! self::$booted) {
            $polylang            = PLL();
            $polylang->links     = new \PLL_Admin_Links($polylang);
            $polylang->pref_lang = $model->get_language('en');
            $polylang->posts     = new \PLL_CRUD_Posts($polylang);
            $polylang->terms = new \PLL_CRUD_Terms($polylang);
            $polylang->sync  = new \PLL_Admin_Sync($polylang);
            self::$booted    = true;
        }
        PLL()->options['sync'] = array_keys(\PLL_Settings_Sync::list_metas_to_sync());
        PLL()->options['media_support'] = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('WPMCP_LIVE_POLYLANG') || ! function_exists('pll_save_post_translations')) {
            $this->markTestSkipped('Needs the booted Polylang (bin/test-local.sh --live-polylang, WPMCP_LIVE_POLYLANG=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        PLL()->model->clean_languages_cache();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args, array $extra = []): array
    {
        $call = [ 'operation' => $op, 'args' => $args ] + $extra;
        $out  = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function page(string $lang, array $fields = []): int
    {
        $id = self::factory()->post->create($fields + [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ]);
        pll_set_post_language($id, $lang);
        return $id;
    }

    private function elementor_page(): int
    {
        $id   = $this->page('en', [ 'post_content' => '<p>Rendered copy</p>' ]);
        $tree = [
            [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
                [ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Welcome home' ], 'elements' => [] ],
                [ 'id' => 'tx1', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>We build <strong>houses</strong>.</p>' ], 'elements' => [] ],
                [ 'id' => 'bt1', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Contact us', 'link' => [ 'url' => 'https://example.org/contact' ] ], 'elements' => [] ],
            ] ],
        ];
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($tree)));
        update_post_meta($id, '_wp_page_template', 'elementor_header_footer');
        update_post_meta($id, '_yoast_wpseo_title', 'Home | Acme');
        update_post_meta($id, '_yoast_wpseo_metadesc', 'We build houses.');
        update_post_meta($id, 'custom_note', 'synced field');
        return $id;
    }

    /** Everything a post stores, plus its translation group as Polylang keeps it. */
    private function stored(int $post_id): array
    {
        global $wpdb;
        clean_post_cache($post_id);
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id), ARRAY_A);
        $meta  = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id), ARRAY_A);
        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_taxonomy_id, tt.taxonomy, tt.description FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d ORDER BY tt.term_taxonomy_id",
            $post_id
        ), ARRAY_A);
        return [ 'row' => $row, 'meta' => $meta, 'terms' => $terms ];
    }

    private function category(string $name, string $lang): int
    {
        $id = self::factory()->category->create([ 'name' => $name ]);
        pll_set_term_language($id, $lang);
        return $id;
    }

    public function test_the_pair_registers_on_polylang_alone_within_the_tools_list_budget(): void
    {
        $this->assertSame('polylang', I18n_Adapter::active_plugin());
        // Polylang alone: every other plugin-data plugin reads as absent.
        $others = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active', 'wpmcp_amelia_active' ];
        foreach ($others as $filter) {
            add_filter($filter, '__return_false');
        }
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $prop->setValue($plugin, new Registrar());
        try {
            $plugin->register_abilities();
            $abilities = $plugin->registrar()->all();
        } finally {
            $prop->setValue($plugin, $original);
            foreach ($others as $filter) {
                remove_filter($filter, '__return_false');
            }
        }
        $this->assertContains('wpmcp/plugin-data-read', array_map(static fn ($a) => $a->name, $abilities));
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
        $this->assertLessThanOrEqual($budget, strlen((string) wp_json_encode($payload)));
    }

    public function test_create_translation_through_polylang_and_rollback_operation_undoes_it_exactly(): void
    {
        $source = $this->elementor_page();
        $news    = $this->category('News', 'en');
        $news_de = $this->category('Nachrichten', 'de');
        pll_save_term_translations([ 'en' => $news, 'de' => $news_de ]);
        register_taxonomy_for_object_type('category', 'page');
        wp_set_object_terms($source, [ $news ], 'category');
        $thumb = self::factory()->attachment->create_object('photo.jpg', 0, [ 'post_mime_type' => 'image/jpeg' ]);
        pll_set_post_language($thumb, 'en');
        set_post_thumbnail($source, $thumb);
        $before = $this->stored($source);

        $out = $this->call('write', 'create-translation', [ 'post_id' => $source, 'language' => 'de' ]);
        $new = (int) $out['result']['post_id'];

        $this->assertSame('draft', get_post_status($new));
        $this->assertSame('de', pll_get_post_language($new));
        $this->assertSame([ 'en' => $source, 'de' => $new ], pll_get_post_translations($source));
        $this->assertSame(get_post_meta($source, '_elementor_data', true), get_post_meta($new, '_elementor_data', true));
        $this->assertSame('elementor_header_footer', get_post_meta($new, '_wp_page_template', true));
        $this->assertSame('Home | Acme', get_post_meta($new, '_yoast_wpseo_title', true));
        $this->assertSame($thumb, (int) get_post_thumbnail_id($new));
        $this->assertSame([ $news_de ], wp_get_object_terms($new, 'category', [ 'fields' => 'ids' ]));
        $this->assertSame([ $news ], wp_get_object_terms($source, 'category', [ 'fields' => 'ids' ]));
        $this->assertSame($before['row'], $this->stored($source)['row']);
        $this->assertSame($before['meta'], $this->stored($source)['meta']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($new));
        $this->assertSame($before, $this->stored($source), 'the source and its (absent) group are back exactly');
        $this->assertSame([ 'en' => $source ], pll_get_post_translations($source));
    }

    public function test_rolling_back_a_translation_added_to_an_existing_group_restores_the_group_exactly(): void
    {
        $source = $this->page('en');
        $fr     = $this->page('fr', [ 'post_title' => 'Accueil' ]);
        pll_save_post_translations([ 'en' => $source, 'fr' => $fr ]);
        $before    = $this->stored($source);
        $before_fr = $this->stored($fr);

        $out = $this->call('write', 'create-translation', [ 'post_id' => $source, 'language' => 'de' ]);
        $new = (int) $out['result']['post_id'];
        $group = pll_get_post_translations($fr);
        ksort($group);
        $this->assertSame([ 'de' => $new, 'en' => $source, 'fr' => $fr ], $group);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($before, $this->stored($source));
        $this->assertSame($before_fr, $this->stored($fr));
        $group = pll_get_post_translations($fr);
        ksort($group);
        $this->assertSame([ 'en' => $source, 'fr' => $fr ], $group);
    }

    public function test_get_and_apply_page_text_on_a_translation_leave_the_source_byte_identical_and_roll_back(): void
    {
        $source = $this->elementor_page();
        $new    = (int) $this->call('write', 'create-translation', [ 'post_id' => $source, 'language' => 'de' ])['result']['post_id'];
        $source_state = $this->stored($source);
        $before       = $this->stored($new);

        $text = $this->call('read', 'get-page-text', [ 'post_id' => $new ])['result'];
        $this->assertSame('elementor', $text['builder']);
        $this->assertSame('de', $text['language']);
        $this->assertSame([ 'title', 'excerpt', 'seo.title', 'seo.description', 'el:h1:title', 'el:tx1:editor', 'el:bt1:text' ], array_column($text['segments'], 'key'));

        $out = $this->call('write', 'apply-page-text', [
            'post_id'  => $new,
            'hash'     => $text['hash'],
            'segments' => [
                [ 'key' => 'title', 'text' => 'Startseite' ],
                [ 'key' => 'excerpt', 'text' => 'Kurzfassung' ],
                [ 'key' => 'seo.title', 'text' => 'Startseite | Acme' ],
                [ 'key' => 'seo.description', 'text' => 'Wir bauen Haeuser.' ],
                [ 'key' => 'el:h1:title', 'text' => 'Willkommen' ],
                [ 'key' => 'el:tx1:editor', 'text' => '<p>Wir bauen <strong>Haeuser</strong>.</p>' ],
                [ 'key' => 'el:bt1:text', 'text' => 'Kontakt' ],
            ],
        ]);
        $this->assertSame(7, $out['result']['written']);
        $this->assertSame('Startseite', get_the_title($new));
        $this->assertSame('Kurzfassung', get_post($new)->post_excerpt);
        $this->assertSame('Wir bauen Haeuser.', get_post_meta($new, '_yoast_wpseo_metadesc', true));
        $this->assertStringContainsString('Willkommen', (string) get_post_meta($new, '_elementor_data', true));
        $this->assertSame($source_state, $this->stored($source), 'Polylang synced nothing back into the source');

        $stale = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'apply-page-text', 'args' => [ 'post_id' => $new, 'hash' => $text['hash'], 'segments' => [ [ 'key' => 'title', 'text' => 'X' ] ] ] ]);
        $this->assertSame('stale_hash', $stale['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->stored($new));
        $this->assertSame($source_state, $this->stored($source));
    }

    public function test_find_missing_translations_through_polylang(): void
    {
        $done    = $this->page('en', [ 'post_title' => 'Done' ]);
        $done_de = $this->page('de', [ 'post_title' => 'Fertig' ]);
        pll_save_post_translations([ 'en' => $done, 'de' => $done_de ]);
        $todo = $this->page('en', [ 'post_title' => 'Todo' ]);
        $fr   = $this->page('fr', [ 'post_title' => 'A faire' ]);

        $out = $this->call('read', 'find-missing-translations', [ 'language' => 'de', 'post_type' => 'page' ])['result'];
        $ids = array_column($out['items'], 'post_id');
        sort($ids);
        $this->assertSame([ $todo, $fr ], $ids);
        $this->assertSame(2, $out['total']);
    }
}
