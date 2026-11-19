<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Loco_Translate_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #691: Loco Translate's .po files as paid-tier ops on the
 * plugin-data pair: list the translation files by bundle, read a file's
 * strings untranslated first, set translations, and compile the .mo (and
 * refresh an existing .l10n.php cache).
 *
 * Loco Translate is not loaded in the shared test core; presence is driven
 * through wpmcp_loco_active. The ops never call Loco's own API: they read
 * and write the gettext files where Loco keeps them (WP_LANG_DIR, its custom
 * LOCO_LANG_DIR and the plugins' and themes' own language folders), so the
 * fixture is a real .po file in the shape Loco 2.8.10 saves.
 */
class LocoTranslatePackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'loco-list-bundles'     => 'read',
        'loco-get-po-strings'   => 'read',
        'loco-update-po-strings' => 'write',
        'loco-compile-mo'       => 'write',
    ];

    private const FIXTURE = __DIR__ . '/../../support/fixtures/loco/sample-de_DE.po';

    private const PLUGIN = 'wpmcp-loco-sample';

    /** @var string[] files this test created, removed in tearDown */
    private array $created = [];

    /** @var string[] folders this test created, removed in tearDown when empty */
    private array $dirs = [];

    private string $po;

    private string $rel;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            // On a network only a super admin may edit files, as core decides.
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        add_filter('wpmcp_loco_active', '__return_true');
        add_filter(Loco_Translate_Pack::ENABLE_FILTER, '__return_true');
        // The .l10n.php refresh asks the site check first; keep it offline.
        add_filter('pre_http_request', static fn () => new \WP_Error('offline', 'no loopback in tests'), 10, 0);

        // The fixture plugin's own language folder ('author') and Loco's
        // custom folder ('custom'), both inside the install like on a real
        // site: the test core's WP_LANG_DIR lies outside ABSPATH, where file
        // writes could not be undone, so the ops leave it out.
        if (! defined('LOCO_LANG_DIR')) {
            define('LOCO_LANG_DIR', WP_CONTENT_DIR . '/languages/loco');
        }
        $this->put(WP_PLUGIN_DIR . '/' . self::PLUGIN . '/' . self::PLUGIN . '.php', "<?php\n/*\nPlugin Name: WPMCP Loco Sample\nText Domain: sample\nDomain Path: /languages\n*/\n");
        wp_cache_delete('plugins', 'plugins');
        $this->po  = $this->put(WP_PLUGIN_DIR . '/' . self::PLUGIN . '/languages/sample-de_DE.po', (string) file_get_contents(self::FIXTURE));
        $this->rel = 'wp-content/plugins/' . self::PLUGIN . '/languages/sample-de_DE.po';
        $this->assertSame(wp_normalize_path(ABSPATH . $this->rel), wp_normalize_path($this->po));
        $this->put(LOCO_LANG_DIR . '/plugins/sample-fr_FR.po', str_replace('de_DE', 'fr_FR', (string) file_get_contents(self::FIXTURE)));
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_loco_active', Loco_Translate_Pack::ENABLE_FILTER, 'pre_http_request', 'map_meta_cap' ] as $hook) {
            remove_all_filters($hook);
        }
        foreach (array_reverse($this->created) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (array_reverse($this->dirs) as $dir) {
            if (is_dir($dir) && [] === array_diff((array) scandir($dir), [ '.', '..' ])) {
                rmdir($dir);
            }
        }
        wp_cache_delete('plugins', 'plugins');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function put(string $file, string $bytes): string
    {
        $missing = [];
        for ($dir = dirname($file); ! is_dir($dir); $dir = dirname($dir)) {
            $missing[] = $dir;
        }
        $this->dirs = array_merge($this->dirs, array_reverse($missing));
        wp_mkdir_p(dirname($file));
        file_put_contents($file, $bytes);
        $this->created[] = $file;
        foreach ([ '.mo', '.l10n.php' ] as $ext) {
            $this->created[] = substr($file, 0, -3) . $ext;
        }
        return $file;
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

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? null;
    }

    /** @return array<string, array> the strings of a get-po-strings answer keyed by msgid */
    private function strings(array $args = []): array
    {
        $out = $this->ok($this->read('loco-get-po-strings', [ 'path' => $this->rel, 'per_page' => 100 ] + $args))['result'];
        return array_column($out['strings'], null, 'msgid');
    }

    private function mo_translations(string $mo): array
    {
        require_once ABSPATH . WPINC . '/pomo/mo.php';
        $reader = new \MO();
        $this->assertTrue($reader->import_from_file($mo));
        $out = [];
        foreach ($reader->entries as $key => $entry) {
            $out[ $key ] = $entry->translations;
        }
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_options_on_the_paid_tier(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }

        Gate::set_pro_for_tests(false);
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $names);
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_loco(): void
    {
        remove_all_filters('wpmcp_loco_active');
        add_filter('wpmcp_loco_active', '__return_false');
        $out = $this->read('loco-list-bundles');
        $this->assertSame('plugin_missing', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Loco Translate', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_list_bundles_finds_system_and_custom_files_with_counts(): void
    {
        $out = $this->ok($this->read('loco-list-bundles', [ 'domain' => 'sample', 'with_counts' => true ]))['result'];
        $this->assertCount(1, $out['bundles']);
        $bundle = $out['bundles'][0];
        $this->assertSame('plugin', $bundle['type']);
        $this->assertSame('sample', $bundle['domain']);
        $this->assertSame('WPMCP Loco Sample', $bundle['name']);
        $files = array_column($bundle['files'], null, 'locale');
        $this->assertSame([ 'de_DE', 'fr_FR' ], array_keys($files));
        $this->assertSame($this->rel, $files['de_DE']['path']);
        $this->assertSame('author', $files['de_DE']['location']);
        $this->assertSame('custom', $files['fr_FR']['location']);
        $this->assertFalse($files['de_DE']['has_mo']);
        $this->assertSame([ 'total' => 6, 'translated' => 2, 'untranslated' => 3, 'fuzzy' => 1 ], $files['de_DE']['counts']);

        $none = $this->ok($this->read('loco-list-bundles', [ 'domain' => 'sample', 'locale' => 'es_ES' ]))['result'];
        $this->assertSame([], $none['bundles']);
    }

    public function test_get_po_strings_lists_untranslated_first_and_filters(): void
    {
        $out = $this->ok($this->read('loco-get-po-strings', [ 'path' => $this->rel ]))['result'];
        $this->assertSame('de_DE', $out['locale']);
        $this->assertSame('sample', $out['domain']);
        $this->assertSame(hash_file('sha256', $this->po), $out['hash']);
        $this->assertSame('nplurals=2; plural=n != 1;', $out['plural_forms']);
        $this->assertSame(6, $out['total']);
        $statuses = array_column($out['strings'], 'status');
        $this->assertSame([ 'untranslated', 'untranslated', 'untranslated', 'fuzzy', 'translated', 'translated' ], $statuses);
        $this->assertNotContains('Old string', array_column($out['strings'], 'msgid'), 'obsolete entries are not listed');

        $by = array_column($out['strings'], null, 'msgid');
        $this->assertSame('verb', $by['Post']['context']);
        $this->assertSame('%d items', $by['%d item']['msgid_plural']);
        $this->assertSame([ '', '' ], $by['%d item']['msgstr_plural']);
        $this->assertArrayNotHasKey('msgstr', $by['%d item']);
        $this->assertSame("Zeile eins\nZeile zwei", $by["Line one\nLine two"]['msgstr']);
        $this->assertSame([ 'sample.php:12' ], $by['Welcome, %s']['references']);
        $this->assertSame([ 'translators: %s: user name' ], $by['Welcome, %s']['comments']);

        $fuzzy = $this->ok($this->read('loco-get-po-strings', [ 'path' => $this->rel, 'status' => 'fuzzy' ]))['result'];
        $this->assertSame([ 'Save changes' ], array_column($fuzzy['strings'], 'msgid'));

        $hit = $this->ok($this->read('loco-get-po-strings', [ 'path' => $this->rel, 'search' => 'hallo' ]))['result'];
        $this->assertSame([ 'Hello' ], array_column($hit['strings'], 'msgid'), 'search matches the translation too, case-insensitively');

        $page = $this->ok($this->read('loco-get-po-strings', [ 'path' => $this->rel, 'per_page' => 2, 'page' => 2 ]))['result'];
        $this->assertSame(6, $page['total']);
        $this->assertSame([ 'untranslated', 'fuzzy' ], array_column($page['strings'], 'status'));
    }

    public function test_only_discovered_po_files_can_be_read(): void
    {
        foreach ([ 'wp-config.php', 'wp-content/languages/plugins/../../../wp-config.php', 'wp-content/languages/plugins/nope-de_DE.po', '../outside.po' ] as $path) {
            $this->assertSame('unknown_po_file', $this->code($this->read('loco-get-po-strings', [ 'path' => $path ])), $path);
        }
    }

    public function test_update_sets_strings_compiles_and_rolls_back_as_a_session(): void
    {
        $before = (string) file_get_contents($this->po);
        $out    = $this->ok($this->write('loco-update-po-strings', [
            'path'          => $this->rel,
            'expected_hash' => hash('sha256', $before),
            'strings'       => [
                [ 'msgid' => 'Welcome, %s', 'msgstr' => 'Willkommen, %s' ],
                [ 'msgid' => 'Save changes', 'msgstr' => 'Änderungen speichern' ],
                [ 'msgid' => 'Post', 'context' => 'verb', 'msgstr' => 'Veröffentlichen "jetzt"' ],
                [ 'msgid' => '%d item', 'msgstr_plural' => [ '%d Eintrag', '%d Einträge' ] ],
                [ 'msgid' => 'Hello', 'msgstr' => "Hallo\nWelt" ],
            ],
        ]));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(2, $out['operation_ids'], 'one undo point for the .po, one for the .mo');
        $this->assertSame(5, $out['result']['updated']);
        $this->assertSame([ 'po', 'mo' ], array_column($out['result']['files'], 'kind'));
        $this->assertSame(hash_file('sha256', $this->po), $out['result']['hash']);

        $after = (string) file_get_contents($this->po);
        $this->assertStringContainsString("msgid \"Welcome, %s\"\nmsgstr \"Willkommen, %s\"\n", $after);
        $this->assertStringContainsString("#: sample.php:14\nmsgid \"Save changes\"\nmsgstr \"Änderungen speichern\"\n", $after, 'the fuzzy flag is cleared');
        $this->assertStringContainsString("msgctxt \"verb\"\nmsgid \"Post\"\nmsgstr \"Veröffentlichen \\\"jetzt\\\"\"\n", $after);
        $this->assertStringContainsString("msgstr[0] \"%d Eintrag\"\nmsgstr[1] \"%d Einträge\"\n", $after);
        $this->assertStringContainsString("msgid \"Hello\"\nmsgstr \"\"\n\"Hallo\\n\"\n\"Welt\"\n", $after);
        $this->assertStringContainsString("#~ msgid \"Old string\"\n#~ msgstr \"Alter Text\"\n", $after, 'obsolete entries stay');
        $this->assertStringContainsString('"X-Generator: Loco https://localise.biz/\n"', $after, 'other headers stay');
        $this->assertStringNotContainsString('PO-Revision-Date: 2026-01-02 03:04+0000', $after, 'the revision date moves on');

        $strings = $this->strings();
        $this->assertSame('translated', $strings['Save changes']['status']);
        $this->assertSame('Veröffentlichen "jetzt"', $strings['Post']['msgstr']);

        $mo = substr($this->po, 0, -3) . '.mo';
        $translations = $this->mo_translations($mo);
        $this->assertSame([ 'Willkommen, %s' ], $translations['Welcome, %s']);
        $this->assertSame([ '%d Eintrag', '%d Einträge' ], $translations['%d item']);
        $this->assertSame([ 'Veröffentlichen "jetzt"' ], $translations["verb\4Post"]);

        $this->assertSame(2, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame($before, file_get_contents($this->po));
        $this->assertFileDoesNotExist($mo, 'the .mo the write created is removed again');
    }

    public function test_update_only_rewrites_the_lines_it_changes(): void
    {
        $before = (string) file_get_contents($this->po);
        $this->ok($this->write('loco-update-po-strings', [ 'path' => $this->rel, 'compile' => false, 'strings' => [ [ 'msgid' => 'Welcome, %s', 'msgstr' => 'Willkommen, %s' ] ] ]));
        $after = (string) file_get_contents($this->po);

        $expected = preg_replace('/PO-Revision-Date: [^\\\\]+/', 'X', str_replace("msgid \"Welcome, %s\"\nmsgstr \"\"\n", "msgid \"Welcome, %s\"\nmsgstr \"Willkommen, %s\"\n", $before));
        $this->assertSame($expected, preg_replace('/PO-Revision-Date: [^\\\\]+/', 'X', $after));
        $this->assertFileDoesNotExist(substr($this->po, 0, -3) . '.mo', 'compile:false leaves the .mo alone');
    }

    public function test_marking_a_string_fuzzy_keeps_it_out_of_the_mo(): void
    {
        $out = $this->ok($this->write('loco-update-po-strings', [
            'path'    => $this->rel,
            'strings' => [ [ 'msgid' => 'Welcome, %s', 'msgstr' => 'Willkommen, %s', 'fuzzy' => true ] ],
        ]));
        $this->assertStringContainsString("#, php-format, fuzzy\nmsgid \"Welcome, %s\"", (string) file_get_contents($this->po));
        $this->assertSame('fuzzy', $this->strings()['Welcome, %s']['status']);
        $translations = $this->mo_translations(substr($this->po, 0, -3) . '.mo');
        $this->assertArrayNotHasKey('Welcome, %s', $translations);
        $this->assertArrayNotHasKey('Save changes', $translations, 'a fuzzy string is not compiled');
        $this->assertSame([ 'Hallo' ], $translations['Hello']);
        $this->assertSame(2, Rollback_Service::restore_session($out['session_id']));
    }

    public function test_placeholder_mismatches_are_reported_not_refused(): void
    {
        $out = $this->ok($this->write('loco-update-po-strings', [ 'path' => $this->rel, 'compile' => false, 'strings' => [ [ 'msgid' => 'Welcome, %s', 'msgstr' => 'Willkommen' ] ] ]));
        $this->assertCount(1, $out['result']['warnings']);
        $this->assertStringContainsString('Welcome, %s', $out['result']['warnings'][0]);
    }

    public function test_update_refusals_leave_the_file_and_the_ledger_untouched(): void
    {
        $before = (string) file_get_contents($this->po);
        $cases  = [
            'stale_file'       => [ 'path' => $this->rel, 'expected_hash' => str_repeat('0', 64), 'strings' => [ [ 'msgid' => 'Hello', 'msgstr' => 'x' ] ] ],
            'unknown_string'   => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => 'Nope', 'msgstr' => 'x' ] ] ],
            'unknown_string '  => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => 'Old string', 'msgstr' => 'x' ] ] ],
            'duplicate_string' => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => 'Hello', 'msgstr' => 'a' ], [ 'msgid' => 'Hello', 'msgstr' => 'b' ] ] ],
            'plural_mismatch'  => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => '%d item', 'msgstr' => 'x' ] ] ],
            'plural_mismatch ' => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => '%d item', 'msgstr_plural' => [ 'a', 'b', 'c' ] ] ] ],
            'plural_mismatch  ' => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => 'Hello', 'msgstr_plural' => [ 'a', 'b' ] ] ] ],
            'unknown_po_file'  => [ 'path' => 'wp-config.php', 'strings' => [ [ 'msgid' => 'Hello', 'msgstr' => 'x' ] ] ],
        ];
        foreach ($cases as $code => $args) {
            $this->assertSame(trim($code), $this->code($this->write('loco-update-po-strings', $args)), $code);
        }
        $this->assertSame($before, file_get_contents($this->po));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Loco_Translate_Pack::ENABLE_FILTER);
        $before = (string) file_get_contents($this->po);
        foreach ([ 'loco-update-po-strings' => [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => 'Hello', 'msgstr' => 'x' ] ] ], 'loco-compile-mo' => [ 'path' => $this->rel ] ] as $op => $args) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $this->code($out), (string) wp_json_encode($out));
            $this->assertStringContainsString(Loco_Translate_Pack::ENABLE_FILTER, $out['error']['message']);
        }
        $this->assertSame($before, file_get_contents($this->po));
        $this->assertFileDoesNotExist(substr($this->po, 0, -3) . '.mo');
    }

    public function test_writes_need_file_editing_allowed(): void
    {
        add_filter('map_meta_cap', static fn (array $caps, string $cap): array => 'edit_files' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2);
        $out = $this->write('loco-compile-mo', [ 'path' => $this->rel ]);
        $this->assertSame('file_edit_disabled', $this->code($out), (string) wp_json_encode($out));
        $this->assertFileDoesNotExist(substr($this->po, 0, -3) . '.mo');
    }

    public function test_compile_mo_replaces_an_existing_mo_and_rolls_back_to_it(): void
    {
        $mo = substr($this->po, 0, -3) . '.mo';
        file_put_contents($mo, 'old mo bytes');

        $out = $this->ok($this->write('loco-compile-mo', [ 'path' => $this->rel ]));
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame(2, $out['result']['compiled'], 'Hello and the multi-line string; fuzzy and empty ones are left out');
        $this->assertSame([ 'Hallo' ], $this->mo_translations($mo)['Hello']);

        $this->assertSame(1, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame('old mo bytes', file_get_contents($mo));
    }

    public function test_compile_refreshes_an_existing_l10n_php_cache(): void
    {
        if (! class_exists('WP_Translation_File')) {
            $this->markTestSkipped('WordPress before 6.5 has no .l10n.php translation files.');
        }
        $php = substr($this->po, 0, -3) . '.l10n.php';
        file_put_contents($php, "<?php\nreturn ['messages' => ['Hello' => 'Alt']];\n");
        $before = (string) file_get_contents($php);

        $out = $this->ok($this->write('loco-update-po-strings', [ 'path' => $this->rel, 'strings' => [ [ 'msgid' => 'Hello', 'msgstr' => 'Servus' ] ] ]));
        $this->assertSame([ 'po', 'mo', 'l10n.php' ], array_column($out['result']['files'], 'kind'));
        $this->assertCount(3, $out['operation_ids']);
        $cache = include $php;
        $this->assertSame('Servus', $cache['messages']['Hello']);

        $this->assertSame(3, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame($before, file_get_contents($php));
    }
}
