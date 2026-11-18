<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Translation_Settings_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/gtranslate-double.php';

/**
 * Issue #690: Weglot and GTranslate language settings and exclusions as
 * paid-tier ops on the plugin-data pair.
 *
 * Neither plugin is loaded in the shared test core. Presence is driven
 * through wpmcp_weglot_active and wpmcp_gtranslate_active. Weglot's
 * settings come from its cloud project through weglot_get_options(); here
 * the wpmcp_weglot_options seam serves them in the shape Weglot 6.4.3 maps
 * them to (Schema_Option_V3). GTranslate's 'GTranslate' option is seeded in
 * the shape GTranslate 5.0.2 stores, read through the GTranslate double.
 */
class TranslationSettingsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'weglot-get-settings'      => 'read',
        'gtranslate-get-settings'  => 'read',
        'gtranslate-set-languages' => 'write',
    ];

    private const WEGLOT_PRIVATE_KEY = 'wg_private_0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_weglot_active', '__return_true');
        add_filter('wpmcp_gtranslate_active', '__return_true');
        add_filter(Translation_Settings_Pack::ENABLE_FILTER, '__return_true');
        add_filter('wpmcp_weglot_options', static fn (): array => [
            'api_key'              => 'wg_public_key_abcdef',
            'api_key_private'      => self::WEGLOT_PRIVATE_KEY,
            'allowed'              => true,
            'original_language'    => 'en',
            'translation_engine'   => 3,
            'auto_redirect'        => false,
            'destination_language' => [
                [ 'language_to' => 'fr', 'custom_code' => null, 'custom_name' => null, 'custom_local_name' => null, 'public' => true ],
                [ 'language_to' => 'de', 'custom_code' => 'de-at', 'custom_name' => 'Austrian', 'custom_local_name' => null, 'public' => false ],
            ],
            'exclude_urls'         => [
                [ 'type' => 'START_WITH', 'value' => '/checkout', 'excluded_languages' => [], 'exclusion_behavior' => 'NOT_TRANSLATED', 'language_button_displayed' => false ],
                [ 'type' => 'IS_EXACTLY', 'value' => '/legal', 'excluded_languages' => [ 'de' ], 'exclusion_behavior' => 'REDIRECT' ],
            ],
            'exclude_blocks'       => [ '.no-translate', '#footer-legal' ],
        ]);
        update_option('GTranslate', [
            'default_language' => 'en',
            'widget_look'      => 'dropdown',
            'incl_langs'       => [ 'en', 'fr', 'de' ],
            'fincl_langs'      => [ 'en', 'fr' ],
            'pro_version'      => '',
            'custom_css'       => '.gt { color: red; }',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_weglot_active', 'wpmcp_gtranslate_active', 'wpmcp_weglot_options', Translation_Settings_Pack::ENABLE_FILTER, 'update_option_GTranslate' ] as $hook) {
            remove_all_filters($hook);
        }
        delete_option('GTranslate');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_weglot_active');
        add_filter('wpmcp_weglot_active', '__return_false');
        $out = $this->read('weglot-get-settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Weglot', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);

        remove_all_filters('wpmcp_gtranslate_active');
        add_filter('wpmcp_gtranslate_active', '__return_false');
        $out = $this->write('gtranslate-set-languages', [ 'dropdown_languages' => [ 'en', 'es' ] ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Install and activate GTranslate', $out['error']['message']);
        $this->assertSame([ 'en', 'fr', 'de' ], get_option('GTranslate')['incl_langs']);
    }

    public function test_a_plugin_installed_but_off_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_gtranslate_active');
        add_filter('wpmcp_gtranslate_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/gtranslate';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/gtranslate.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: GTranslate\n");
        }
        try {
            $out = $this->read('gtranslate-get-settings');
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_weglot_read_reports_languages_exclusions_and_status_and_never_a_key(): void
    {
        $out = $this->ok($this->read('weglot-get-settings'))['result'];

        $this->assertSame('en', $out['original_language']);
        $this->assertSame([
            [ 'code' => 'fr', 'custom_code' => null, 'custom_name' => null, 'public' => true ],
            [ 'code' => 'de', 'custom_code' => 'de-at', 'custom_name' => 'Austrian', 'public' => false ],
        ], $out['languages']);
        $this->assertSame([
            [ 'type' => 'START_WITH', 'value' => '/checkout', 'languages' => [], 'behavior' => 'NOT_TRANSLATED' ],
            [ 'type' => 'IS_EXACTLY', 'value' => '/legal', 'languages' => [ 'de' ], 'behavior' => 'REDIRECT' ],
        ], $out['excluded_urls']);
        $this->assertSame([ '.no-translate', '#footer-legal' ], $out['excluded_blocks']);
        $this->assertTrue($out['status']['connected']);
        $this->assertTrue($out['status']['allowed']);
        $this->assertFalse($out['status']['auto_redirect']);
        $this->assertFalse($out['writable']);
        $this->assertStringContainsString('Weglot dashboard', $out['note']);

        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString(self::WEGLOT_PRIVATE_KEY, $json);
        $this->assertStringNotContainsString('wg_public_key_abcdef', $json);
    }

    public function test_weglot_read_without_a_connected_project_says_so(): void
    {
        remove_all_filters('wpmcp_weglot_options');
        add_filter('wpmcp_weglot_options', static fn (): array => [ 'api_key' => '', 'language_from' => 'en', 'languages' => [] ]);
        $out = $this->ok($this->read('weglot-get-settings'))['result'];
        $this->assertFalse($out['status']['connected']);
        $this->assertSame('en', $out['original_language']);
        $this->assertSame([], $out['languages']);
        $this->assertSame([], $out['excluded_urls']);
    }

    public function test_gtranslate_read_reports_the_lists_and_the_languages_its_look_shows(): void
    {
        $out = $this->ok($this->read('gtranslate-get-settings'))['result'];

        $this->assertSame('en', $out['default_language']);
        $this->assertSame('dropdown', $out['widget_look']);
        $this->assertSame([ 'en', 'fr', 'de' ], $out['dropdown_languages']);
        $this->assertSame([ 'en', 'fr' ], $out['flag_languages']);
        $this->assertSame([ 'en', 'fr', 'de' ], $out['active_languages'], 'the dropdown look shows incl_langs');
        $this->assertFalse($out['paid_plan']);
        $this->assertTrue($out['writes_enabled']);
        $this->assertStringContainsString('notranslate', $out['exclusions']);
        $this->assertArrayNotHasKey('custom_css', $out);

        update_option('GTranslate', [ 'widget_look' => 'flags_dropdown', 'incl_langs' => [ 'en', 'de' ], 'fincl_langs' => [ 'en', 'fr' ] ]);
        $out = $this->ok($this->read('gtranslate-get-settings'))['result'];
        $this->assertSame([ 'en', 'fr', 'de' ], $out['active_languages'], 'flags_dropdown shows both lists');
    }

    public function test_gtranslate_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Translation_Settings_Pack::ENABLE_FILTER);
        $out = $this->write('gtranslate-set-languages', [ 'dropdown_languages' => [ 'en', 'es' ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(Translation_Settings_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame([ 'en', 'fr', 'de' ], get_option('GTranslate')['incl_langs']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
        $this->assertFalse($this->ok($this->read('gtranslate-get-settings'))['result']['writes_enabled']);
    }

    public function test_gtranslate_set_languages_writes_the_option_and_rolls_back_exactly(): void
    {
        $before = get_option('GTranslate');
        $saves  = 0;
        add_action('update_option_GTranslate', static function () use (&$saves): void {
            ++$saves;
        });

        $out = $this->ok($this->write('gtranslate-set-languages', [ 'dropdown_languages' => [ 'en', 'es', 'es', 'it' ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'en', 'es', 'it' ], $out['result']['dropdown_languages']);
        $this->assertSame([ 'en', 'fr' ], $out['result']['flag_languages']);
        $this->assertSame([ 'en', 'es', 'it' ], $out['result']['active_languages']);
        $this->assertStringContainsString('front end', $out['result']['check']);

        $stored = get_option('GTranslate');
        $this->assertSame([ 'en', 'es', 'it' ], $stored['incl_langs']);
        $this->assertSame([ 'en', 'fr' ], $stored['fincl_langs'], 'the other list is untouched');
        $this->assertSame('.gt { color: red; }', $stored['custom_css'], 'other settings are untouched');
        $this->assertSame(1, $saves);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('GTranslate'));
    }

    public function test_gtranslate_set_languages_refuses_unknown_codes_a_missing_default_and_no_change(): void
    {
        $cases = [
            'unknown_language' => [ 'flag_languages' => [ 'en', 'xx' ] ],
            'default_language_required' => [ 'dropdown_languages' => [ 'fr', 'de' ] ],
            'no_change' => [ 'dropdown_languages' => [ 'en', 'fr', 'de' ] ],
            'invalid_args' => [],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('gtranslate-set-languages', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame([ 'en', 'fr', 'de' ], get_option('GTranslate')['incl_langs']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_an_editor_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->read('weglot-get-settings');
        $this->assertArrayHasKey('error', $out);
        $out = $this->write('gtranslate-set-languages', [ 'dropdown_languages' => [ 'en' ] ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame([ 'en', 'fr', 'de' ], get_option('GTranslate')['incl_langs']);
    }
}
