<?php

namespace WPMCP\Tests\Free\Settings;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Settings\Get_Settings;
use WPMCP\Tools\Settings\Update_Settings;

/**
 * Issue #472: the site language, timezone and date and time formats are on
 * the settings allowlist, so get-settings reads them and update-settings
 * writes them, each change snapshotted and undoable through
 * rollback-operation.
 *
 * Nothing here touches the network. The translations list is served through
 * core's translations_api filter, the language pack download through
 * upgrader_pre_download (a zip built on disk), and every other outbound
 * request is refused and recorded so a test that strays onto the wire fails.
 */
class SiteLocaleSettingsTest extends \WP_UnitTestCase
{
    /** A locale no real site has, so an installed test pack never collides with a real one. */
    private const TEST_LOCALE = 'xx_WPMCP';

    private const KEYS = [
        'WPLANG', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'blogname',
    ];

    private array $original = [];

    /** @var string[] URLs any code tried to reach during the test. */
    private array $http_attempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        foreach (self::KEYS as $key) {
            $this->original[ $key ] = get_option($key);
        }
        delete_site_transient('available_translations');
        add_filter('pre_http_request', [ $this, 'block_http' ], 1, 3);
        $this->remove_test_pack();
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'block_http' ], 1);
        foreach ($this->original as $key => $value) {
            update_option($key, $value);
        }
        delete_site_transient('available_translations');
        $this->remove_test_pack();
        $attempts = $this->http_attempts;
        parent::tearDown();
        $this->assertSame([], $attempts, 'A settings test reached for the network.');
    }

    /** @return \WP_Error */
    public function block_http($pre, $args, $url)
    {
        $this->http_attempts[] = (string) $url;
        return new \WP_Error('http_blocked', 'Network disabled in tests.');
    }

    private function admin(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
    }

    private function skipped(array $out): array
    {
        $map = [];
        foreach ($out['skipped'] as $row) {
            $map[ $row['key'] ] = $row['reason'];
        }
        return $map;
    }

    private function rollback(string $operation_id): void
    {
        $this->admin();
        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);
        $this->assertTrue($out['restored']);
    }

    /** Serve a translations list without the network. */
    private function translations(array $locales): void
    {
        add_filter('translations_api', function ($result, $type) use ($locales) {
            if ('core' !== $type) {
                return $result;
            }
            $rows = [];
            foreach ($locales as $locale) {
                $rows[] = [
                    'language'     => $locale,
                    'version'      => $GLOBALS['wp_version'],
                    'updated'      => '2026-01-01 00:00:00',
                    'english_name' => 'Test ' . $locale,
                    'native_name'  => 'Test ' . $locale,
                    'package'      => 'https://downloads.wordpress.org/translation/core/' . $GLOBALS['wp_version'] . '/' . $locale . '.zip',
                    'iso'          => [ 1 => 'xx' ],
                    'strings'      => [ 'continue' => 'Continue' ],
                ];
            }
            return [ 'translations' => $rows ];
        }, 10, 2);
    }

    /** Serve the language pack download from a zip on disk. */
    private function language_pack(string $locale): void
    {
        add_filter('upgrader_pre_download', function ($reply, $package) use ($locale) {
            if (! str_contains((string) $package, '/translation/core/')) {
                return $reply;
            }
            $zip_path = wp_tempnam('wpmcp-lang') . '.zip';
            $zip      = new \ZipArchive();
            $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString($locale . '.po', "msgid \"\"\nmsgstr \"\"\n");
            $zip->addFromString($locale . '.mo', 'not a real catalog');
            $zip->close();
            return $zip_path;
        }, 10, 2);
    }

    private function remove_test_pack(): void
    {
        foreach ([ '.po', '.mo', '.l10n.php' ] as $ext) {
            $file = WP_LANG_DIR . '/' . self::TEST_LOCALE . $ext;
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->forget_language_files();
    }

    /** Core caches the language file listing; the upgrader clears it the same way after an install. */
    private function forget_language_files(): void
    {
        $GLOBALS['wp_textdomain_registry']->invalidate_mo_files_cache(null, [ 'type' => 'translation', 'translations' => [ [ 'type' => 'core' ] ] ]);
    }

    public function test_get_settings_returns_the_locale_keys_in_general(): void
    {
        update_option('date_format', 'F j, Y');
        update_option('start_of_week', '1');

        $out  = (new Get_Settings())->handle([ 'keys' => [ 'WPLANG', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week' ] ]);
        $rows = array_column($out['settings'], null, 'key');

        $this->assertCount(6, $rows);
        foreach ($rows as $key => $row) {
            $this->assertSame('general', $row['group'], $key);
            $this->assertTrue($row['writable'], $key);
        }
        $this->assertSame('F j, Y', $rows['date_format']['value']);
        $this->assertSame(1, $rows['start_of_week']['value']);
    }

    public function test_timezone_identifier_is_set_and_rolls_back(): void
    {
        update_option('timezone_string', 'America/Chicago');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'timezone_string' => 'Europe/Berlin' ] ]);

        $this->assertSame('Europe/Berlin', $out['updated']['timezone_string']);
        $this->assertSame('Europe/Berlin', get_option('timezone_string'));
        $this->assertCount(1, $out['operation_ids']);

        $this->rollback($out['operation_ids'][0]);
        $this->assertSame('America/Chicago', get_option('timezone_string'));
    }

    public function test_utc_offset_maps_to_gmt_offset_and_rolls_back_both(): void
    {
        update_option('timezone_string', 'Europe/Berlin');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'timezone_string' => 'UTC+5.5' ] ]);

        $this->assertSame([], $out['skipped']);
        $this->assertSame('', get_option('timezone_string'));
        $this->assertEquals(5.5, (float) get_option('gmt_offset'));
        $this->assertEquals(5.5, $out['updated']['gmt_offset']);

        $this->rollback($out['operation_ids'][0]);
        $this->assertSame('Europe/Berlin', get_option('timezone_string'));
    }

    public function test_negative_utc_offset_and_gmt_offset_key(): void
    {
        update_option('timezone_string', 'Europe/Berlin');

        (new Update_Settings())->handle([ 'settings' => [ 'timezone_string' => 'UTC-3' ] ]);
        $this->assertSame('', get_option('timezone_string'));
        $this->assertEquals(-3, (float) get_option('gmt_offset'));

        update_option('timezone_string', 'Europe/Berlin');
        $out = (new Update_Settings())->handle([ 'settings' => [ 'gmt_offset' => 9 ] ]);
        $this->assertSame([], $out['skipped']);
        $this->assertSame('', get_option('timezone_string'));
        $this->assertEquals(9, (float) get_option('gmt_offset'));
    }

    public function test_invalid_timezones_are_refused_while_the_valid_subset_applies(): void
    {
        update_option('timezone_string', 'Europe/Berlin');
        update_option('gmt_offset', '0');

        $out = (new Update_Settings())->handle([
            'settings' => [
                'timezone_string' => 'Mars/Olympus_Mons',
                'gmt_offset'      => 20,
                'blogname'        => 'Still Applied',
            ],
        ]);
        $skipped = $this->skipped($out);

        $this->assertArrayHasKey('timezone_string', $skipped);
        $this->assertArrayHasKey('gmt_offset', $skipped);
        $this->assertSame('Still Applied', get_option('blogname'));
        $this->assertSame('Europe/Berlin', get_option('timezone_string'));

        $out = (new Update_Settings())->handle([ 'settings' => [ 'timezone_string' => 'UTC+banana' ] ]);
        $this->assertArrayHasKey('timezone_string', $this->skipped($out));
        $this->assertSame('Europe/Berlin', get_option('timezone_string'));
    }

    public function test_start_of_week_accepts_0_to_6_and_refuses_the_rest(): void
    {
        update_option('start_of_week', '0');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'start_of_week' => 1 ] ]);
        $this->assertSame(1, $out['updated']['start_of_week']);
        $this->assertSame(1, (int) get_option('start_of_week'));

        foreach ([ 7, -1, 'monday' ] as $bad) {
            $out = (new Update_Settings())->handle([ 'settings' => [ 'start_of_week' => $bad ] ]);
            $this->assertArrayHasKey('start_of_week', $this->skipped($out), var_export($bad, true));
            $this->assertSame(1, (int) get_option('start_of_week'));
        }
    }

    public function test_date_and_time_formats_are_set_and_roll_back(): void
    {
        update_option('date_format', 'F j, Y');
        update_option('time_format', 'g:i a');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'date_format' => 'd/m/Y', 'time_format' => 'H:i' ] ]);

        $this->assertSame('d/m/Y', get_option('date_format'));
        $this->assertSame('H:i', get_option('time_format'));
        $this->assertCount(2, $out['operation_ids']);

        $this->rollback($out['operation_ids'][0]);
        $this->assertSame('F j, Y', get_option('date_format'));
    }

    public function test_unsafe_format_strings_are_refused(): void
    {
        update_option('date_format', 'F j, Y');

        foreach ([ '<script>alert(1)</script>', '', '   ', "Y\0m" ] as $bad) {
            $out = (new Update_Settings())->handle([ 'settings' => [ 'date_format' => $bad ] ]);
            $this->assertArrayHasKey('date_format', $this->skipped($out), var_export($bad, true));
            $this->assertSame('F j, Y', get_option('date_format'));
        }

        // Escaped literals are a normal part of a PHP date format.
        (new Update_Settings())->handle([ 'settings' => [ 'date_format' => 'l \t\h\e jS' ] ]);
        $this->assertSame('l \t\h\e jS', get_option('date_format'));
    }

    public function test_english_us_is_stored_as_empty(): void
    {
        $this->admin();
        update_option('WPLANG', '');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => 'en_US' ] ]);

        $this->assertSame([], $out['skipped']);
        $this->assertSame('', $out['updated']['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
    }

    /**
     * An installed locale is selected with no lookup and no install, so a
     * user without install_languages (a site admin on multisite) can still
     * pick any language the network already has.
     */
    public function test_installed_locale_is_selected_without_install_or_network(): void
    {
        update_option('WPLANG', '');
        wp_mkdir_p(WP_LANG_DIR);
        file_put_contents(WP_LANG_DIR . '/' . self::TEST_LOCALE . '.mo', 'installed');
        $this->forget_language_files();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertSame([], $out['skipped']);
        $this->assertSame(self::TEST_LOCALE, get_option('WPLANG'));

        $this->rollback($out['operation_ids'][0]);
        $this->assertSame('', get_option('WPLANG'));
    }

    /**
     * On multisite core maps install_languages to do_not_allow for anyone
     * but a super admin, so a site admin cannot install a network-wide pack.
     * The same mapping is applied here to an administrator.
     */
    public function test_install_refused_when_install_languages_is_mapped_away_as_on_multisite(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        $this->translations([ self::TEST_LOCALE ]);
        $this->language_pack(self::TEST_LOCALE);
        add_filter('map_meta_cap', static function ($caps, $cap) {
            return 'install_languages' === $cap ? [ 'do_not_allow' ] : $caps;
        }, 10, 2);

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertStringContainsString('install_languages', $this->skipped($out)['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
        $this->assertNotContains(self::TEST_LOCALE, get_available_languages());
    }

    public function test_uninstalled_locale_is_installed_then_selected_and_rolls_back(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        $this->translations([ 'de_DE', self::TEST_LOCALE ]);
        $this->language_pack(self::TEST_LOCALE);

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertSame([], $out['skipped']);
        $this->assertSame(self::TEST_LOCALE, $out['updated']['WPLANG']);
        $this->assertContains(self::TEST_LOCALE, get_available_languages());
        $this->assertSame(self::TEST_LOCALE, get_option('WPLANG'));

        $this->rollback($out['operation_ids'][0]);
        $this->assertSame('', get_option('WPLANG'));
        // The pack stays installed; only the selection is undone.
        $this->assertContains(self::TEST_LOCALE, get_available_languages());
    }

    public function test_unknown_locale_writes_nothing(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        $this->translations([ 'de_DE' ]);
        $before = count(Snapshot_Store::recent(50));

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => 'zz_NOWHERE' ] ]);

        $this->assertStringContainsString('unknown locale', $this->skipped($out)['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
        $this->assertSame($before, count(Snapshot_Store::recent(50)));
    }

    public function test_malformed_locale_is_refused_before_any_lookup(): void
    {
        $this->admin();
        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => '../../evil' ] ]);
        $this->assertArrayHasKey('WPLANG', $this->skipped($out));
    }

    public function test_unreachable_translations_service_writes_nothing(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        add_filter('translations_api', static function () {
            return new \WP_Error('offline', 'Could not reach the translations service.');
        });

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertStringContainsString('could not', strtolower($this->skipped($out)['WPLANG']));
        $this->assertSame('', get_option('WPLANG'));
    }

    public function test_failed_install_writes_nothing(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        $this->translations([ self::TEST_LOCALE ]);
        add_filter('upgrader_pre_download', static function () {
            return new \WP_Error('download_failed', 'Download failed.');
        });

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertStringContainsString('install', $this->skipped($out)['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
        $this->assertNotContains(self::TEST_LOCALE, get_available_languages());
    }

    public function test_install_refused_when_file_changes_are_not_allowed(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        $this->translations([ self::TEST_LOCALE ]);
        add_filter('file_mod_allowed', '__return_false');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertStringContainsString('filesystem', $this->skipped($out)['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
    }

    public function test_install_refused_without_filesystem_access(): void
    {
        $this->admin();
        update_option('WPLANG', '');
        $this->translations([ self::TEST_LOCALE ]);
        add_filter('filesystem_method', static fn () => 'ftpext');

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertStringContainsString('filesystem', $this->skipped($out)['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
    }

    public function test_install_needs_the_install_languages_capability(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        update_option('WPLANG', '');
        $this->translations([ self::TEST_LOCALE ]);
        $this->language_pack(self::TEST_LOCALE);

        $out = (new Update_Settings())->handle([ 'settings' => [ 'WPLANG' => self::TEST_LOCALE ] ]);

        $this->assertStringContainsString('install_languages', $this->skipped($out)['WPLANG']);
        $this->assertSame('', get_option('WPLANG'));
        $this->assertNotContains(self::TEST_LOCALE, get_available_languages());
    }
}
