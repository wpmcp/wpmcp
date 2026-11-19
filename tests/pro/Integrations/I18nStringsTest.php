<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\I18n_Strings;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\I18n\I18n_Adapter;

/**
 * Theme and plugin string translation for Polylang (issue #689):
 * list-string-domains, get-strings and update-strings, paid-tier ops on the
 * plugin-data pair.
 *
 * The shared suite loads Polylang without booting it, so here the languages
 * are real terms of a 'language' taxonomy holding Polylang's own term meta
 * (_pll_strings_translations, a list of [original, translation] pairs), and
 * the strings a theme registers come through the test seam. The same ops run
 * through the real Polylang in I18nStringsPolylangLiveTest.
 */
class I18nStringsTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active', 'wpmcp_amelia_active', 'wpmcp_action_scheduler_active' ];

    private bool $registered_taxonomy = false;

    /** @var array<string, int> code => term id */
    private array $terms = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        add_filter('wpmcp_multilingual_active', '__return_true');
        add_filter(I18n_Strings::OPT_IN_FILTER, '__return_true');
        I18n_Adapter::use_plugin_for_tests('polylang');

        if (! taxonomy_exists('language')) {
            register_taxonomy('language', [ 'post', 'page' ], [ 'public' => false ]);
            $this->registered_taxonomy = true;
        }
        $languages = [];
        foreach ([ [ 'en', 'English', true ], [ 'de', 'Deutsch', false ], [ 'fr', 'Francais', false ] ] as [ $code, $name, $default ]) {
            $term                 = wp_insert_term($name, 'language', [ 'slug' => $code ]);
            $this->terms[ $code ] = (int) $term['term_id'];
            $languages[]          = [ 'code' => $code, 'name' => $name, 'is_default' => $default, 'term_id' => (int) $term['term_id'] ];
        }
        I18n_Strings::use_languages_for_tests($languages);
        I18n_Strings::use_registered_for_tests([
            md5('Read more')        => [ 'name' => 'read_more', 'string' => 'Read more', 'context' => 'my-theme', 'multiline' => false ],
            md5('Call us today')    => [ 'name' => 'cta', 'string' => 'Call us today', 'context' => 'my-theme', 'multiline' => false ],
            md5('Just another site') => [ 'name' => 'blogdescription', 'string' => 'Just another site', 'context' => 'WordPress', 'multiline' => true ],
        ]);

        // A German translation already stored, the way Polylang stores it.
        update_term_meta($this->terms['de'], '_pll_strings_translations', [ [ 'Read more', 'Weiterlesen' ], [ 'Old footer', 'Alte Fusszeile' ] ]);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
    }

    protected function tearDown(): void
    {
        I18n_Strings::use_languages_for_tests(null);
        I18n_Strings::use_registered_for_tests(null);
        I18n_Adapter::use_plugin_for_tests(null);
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_multilingual_active');
        remove_all_filters(I18n_Strings::OPT_IN_FILTER);
        if ($this->registered_taxonomy) {
            unregister_taxonomy('language');
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A read's result. */
    private function got(string $op, array $args = []): array
    {
        return (array) $this->ok($this->read($op, $args))['result'];
    }

    private function stored(string $code): array
    {
        wp_cache_delete($this->terms[ $code ], 'term_meta');
        return (array) get_term_meta($this->terms[ $code ], '_pll_strings_translations', true);
    }

    public function test_list_string_domains_counts_translated_and_missing_per_language(): void
    {
        $out     = $this->got('list-string-domains');
        $domains = array_column($out['domains'], null, 'domain');

        $this->assertSame([ 'my-theme', 'WordPress', '' ], array_keys($domains));
        $this->assertSame(2, $domains['my-theme']['strings']);
        $this->assertSame([ 'de' => 1, 'fr' => 0 ], $domains['my-theme']['translated']);
        $this->assertSame([ 'de' => 1, 'fr' => 2 ], $domains['my-theme']['missing']);
        // A stored translation whose string is not registered in this request.
        $this->assertSame(1, $domains['']['strings']);
        $this->assertSame([ 'de', 'fr' ], $out['languages']);
        $this->assertSame('en', $out['default_language']);
    }

    public function test_get_strings_lists_translations_and_missing_languages(): void
    {
        $out  = $this->got('get-strings', [ 'domain' => 'my-theme' ]);
        $rows = array_column($out['strings'], null, 'string');

        $this->assertSame(2, $out['total']);
        $this->assertSame(md5('Read more'), $rows['Read more']['key']);
        $this->assertSame('read_more', $rows['Read more']['name']);
        $this->assertSame('Weiterlesen', $rows['Read more']['translations']['de']);
        $this->assertNull($rows['Read more']['translations']['fr']);
        $this->assertSame([ 'fr' ], $rows['Read more']['missing']);
        $this->assertSame([ 'de', 'fr' ], $rows['Call us today']['missing']);
        $this->assertTrue($rows['Read more']['registered']);
    }

    public function test_get_strings_missing_only_in_one_language_with_search_and_paging(): void
    {
        $out = $this->got('get-strings', [ 'language' => 'de', 'missing_only' => true ]);
        $this->assertSame([ 'Call us today', 'Just another site' ], array_column($out['strings'], 'string'));
        $this->assertSame([ 'de' ], array_keys($out['strings'][0]['translations']));

        $out = $this->got('get-strings', [ 'search' => 'footer' ]);
        $this->assertSame([ 'Old footer' ], array_column($out['strings'], 'string'));
        $this->assertFalse($out['strings'][0]['registered']);

        $out = $this->got('get-strings', [ 'per_page' => 2, 'page' => 2 ]);
        $this->assertSame(4, $out['total']);
        $this->assertSame(2, $out['pages']);
        $this->assertCount(2, $out['strings']);
    }

    public function test_get_strings_refuses_an_unknown_language(): void
    {
        $out = $this->read('get-strings', [ 'language' => 'xx' ]);
        $this->assertSame('unknown_language', $out['error']['code'] ?? null);
    }

    public function test_update_strings_writes_polylang_format_and_rolls_back(): void
    {
        $before = $this->stored('de');
        $out    = $this->ok($this->write('update-strings', [
            'language' => 'de',
            'strings'  => [
                [ 'key' => md5('Call us today'), 'translation' => ' Rufen Sie uns an ' ],
                [ 'key' => md5('Read more'), 'translation' => 'Mehr lesen' ],
            ],
        ]));

        $this->assertSame(2, $out['result']['written'], (string) wp_json_encode($out));
        $this->assertNotEmpty($out['operation_id']);
        $pairs = array_column($this->stored('de'), 1, 0);
        $this->assertSame('Mehr lesen', $pairs['Read more']);
        $this->assertSame('Rufen Sie uns an', $pairs['Call us today']);
        $this->assertSame('Alte Fusszeile', $pairs['Old footer'], 'strings the call did not name stay');
        $this->assertSame([], array_filter($this->stored('fr')), 'other languages stay');

        $read = $this->got('get-strings', [ 'language' => 'de', 'domain' => 'my-theme' ]);
        $this->assertSame([ 'Mehr lesen', 'Rufen Sie uns an' ], array_column(array_column($read['strings'], 'translations'), 'de'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->stored('de'));
    }

    public function test_update_strings_empty_translation_clears_it(): void
    {
        $out = $this->ok($this->write('update-strings', [
            'language' => 'de',
            'strings'  => [ [ 'key' => md5('Read more'), 'translation' => '' ] ],
        ]));
        $this->assertSame(1, $out['result']['cleared']);
        $this->assertArrayNotHasKey('Read more', array_column($this->stored('de'), 1, 0));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Weiterlesen', array_column($this->stored('de'), 1, 0)['Read more']);
    }

    public function test_update_strings_into_a_language_without_translations_rolls_back_to_none(): void
    {
        $out = $this->ok($this->write('update-strings', [
            'language' => 'fr',
            'strings'  => [ [ 'key' => md5('Read more'), 'translation' => 'Lire la suite' ] ],
        ]));
        $this->assertSame('Lire la suite', array_column($this->stored('fr'), 1, 0)['Read more']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        wp_cache_delete($this->terms['fr'], 'term_meta');
        $this->assertSame([], get_term_meta($this->terms['fr'], '_pll_strings_translations'));
    }

    public function test_update_strings_strips_unsafe_markup_without_unfiltered_html(): void
    {
        add_filter('map_meta_cap', $deny = static fn ($caps, $cap) => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2);
        try {
            $this->ok($this->write('update-strings', [
                'language' => 'de',
                'strings'  => [ [ 'key' => md5('Call us today'), 'translation' => 'Ruf an<script>alert(1)</script>' ] ],
            ]));
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
        }
        $this->assertSame('Ruf analert(1)', array_column($this->stored('de'), 1, 0)['Call us today']);
    }

    public function test_update_strings_refusals(): void
    {
        $unknown = $this->write('update-strings', [ 'language' => 'de', 'strings' => [ [ 'key' => md5('nope'), 'translation' => 'x' ] ] ]);
        $this->assertSame('unknown_string', $unknown['error']['code'] ?? null);

        $twice = $this->write('update-strings', [ 'language' => 'de', 'strings' => [
            [ 'key' => md5('Read more'), 'translation' => 'a' ],
            [ 'key' => md5('Read more'), 'translation' => 'b' ],
        ] ]);
        $this->assertSame('duplicate_string', $twice['error']['code'] ?? null);

        $language = $this->write('update-strings', [ 'language' => 'xx', 'strings' => [ [ 'key' => md5('Read more'), 'translation' => 'a' ] ] ]);
        $this->assertSame('unknown_language', $language['error']['code'] ?? null);

        $this->assertSame([ [ 'Read more', 'Weiterlesen' ], [ 'Old footer', 'Alte Fusszeile' ] ], $this->stored('de'));
    }

    public function test_update_strings_is_off_until_the_site_opts_in(): void
    {
        remove_all_filters(I18n_Strings::OPT_IN_FILTER);
        $out = $this->write('update-strings', [ 'language' => 'de', 'strings' => [ [ 'key' => md5('Read more'), 'translation' => 'a' ] ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString(I18n_Strings::OPT_IN_FILTER, $out['error']['message']);
    }

    public function test_wpml_answers_that_string_translation_needs_polylang(): void
    {
        I18n_Adapter::use_plugin_for_tests('wpml');
        foreach ([ 'list-string-domains', 'get-strings' ] as $op) {
            $out = $this->read($op);
            $this->assertSame('string_translation_unsupported', $out['error']['code'] ?? null, $op);
        }
    }

    public function test_without_a_multilingual_plugin_the_ops_answer_inactive(): void
    {
        remove_all_filters('wpmcp_multilingual_active');
        add_filter('wpmcp_multilingual_active', '__return_false');
        add_filter('wpmcp_jetengine_active', '__return_true');
        $out = $this->read('get-strings');
        $this->assertSame('multilingual_inactive', $out['error']['code'] ?? null);
    }
}
