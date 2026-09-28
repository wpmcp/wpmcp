<?php

namespace WPMCP\Tests\Free\Portable;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Code\Create_Php_Snippet;
use WPMCP\Tools\Code\Php_Snippet_Bundle_Kind;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Portable\Bundle;
use WPMCP\Tools\Portable\Bundle_Kinds;
use WPMCP\Tools\Portable\Export_Bundle;
use WPMCP\Tools\Portable\Import_Bundle;
use WPMCP\Tools\Rollback_Session;

/**
 * Portable export and import bundles (issue #297), free half: the PHP snippet
 * store, which is the one store every build ships.
 *
 * The invariants: a bundle is versioned and checksummed, every imported item
 * is re-validated by its own store and lands inactive, a name collision is
 * refused unless the caller asked for a rename, and the whole import sits
 * under one session so rollback-session takes it back out.
 */
class PortableBundleTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Php_Snippet_Store::OPTION_NAME);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        delete_option(Php_Snippet_Store::OPTION_NAME);
        parent::tearDown();
    }

    private function kinds(): array
    {
        return [new Php_Snippet_Bundle_Kind()];
    }

    private function export(array $args = []): array
    {
        return (new Export_Bundle($this->kinds()))->handle($args);
    }

    private function import(array $args)
    {
        return (new Import_Bundle($this->kinds()))->handle($args);
    }

    private function snippet(string $name, string $code = '<?php return 1;'): string
    {
        return (new Create_Php_Snippet())->handle(['name' => $name, 'code' => $code])['snippet']['id'];
    }

    public function test_export_builds_a_versioned_checksummed_bundle_of_every_snippet(): void
    {
        $this->snippet('alpha', '<?php return "a";');
        $this->snippet('beta', '<?php return "b";');

        $out    = $this->export();
        $bundle = $out['bundle'];

        $this->assertSame(Bundle::FORMAT, $bundle['format']);
        $this->assertSame(Bundle::VERSION, $bundle['version']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $bundle['checksum']);
        $this->assertSame(['snippet' => 2], $out['counts']);

        $names = wp_list_pluck($bundle['items'], 'name');
        sort($names);
        $this->assertSame(['alpha', 'beta'], $names);
        foreach ($bundle['items'] as $item) {
            $this->assertSame('snippet', $item['type']);
            $this->assertArrayHasKey('code', $item);
            $this->assertArrayNotHasKey('status', $item, 'A bundle never carries an active flag');
            $this->assertArrayNotHasKey('id', $item, 'Store ids are site-local');
        }
    }

    public function test_export_can_select_items_by_id(): void
    {
        $keep = $this->snippet('keep');
        $this->snippet('drop');

        $bundle = $this->export(['ids' => [$keep]])['bundle'];

        $this->assertCount(1, $bundle['items']);
        $this->assertSame('keep', $bundle['items'][0]['name']);
    }

    public function test_export_can_select_by_type(): void
    {
        $this->snippet('only');

        $this->assertSame([], $this->export(['types' => ['block']])['bundle']['items']);
        $this->assertCount(1, $this->export(['types' => ['snippet']])['bundle']['items']);
    }

    public function test_round_trip_recreates_every_snippet_inactive(): void
    {
        $this->snippet('alpha', '<?php return "a";');
        $active = $this->snippet('beta', '<?php return "b";');
        Php_Snippet_Store::set_status($active, Php_Snippet_Store::STATUS_ACTIVE);

        $bundle = $this->export()['bundle'];
        delete_option(Php_Snippet_Store::OPTION_NAME);

        $out = $this->import(['bundle' => $bundle]);

        $this->assertCount(2, $out['imported']);
        $this->assertSame([], $out['skipped']);
        $stored = Php_Snippet_Store::all();
        $this->assertCount(2, $stored);
        $codes = [];
        foreach ($stored as $snippet) {
            $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $snippet['status'], 'Every imported item lands inactive');
            $codes[ $snippet['name'] ] = $snippet['code'];
        }
        ksort($codes);
        $this->assertSame(['alpha' => '<?php return "a";', 'beta' => '<?php return "b";'], $codes);
    }

    public function test_a_bundle_passed_as_a_json_string_is_accepted(): void
    {
        $this->snippet('alpha');
        $bundle = $this->export()['bundle'];
        delete_option(Php_Snippet_Store::OPTION_NAME);

        $out = $this->import(['bundle' => wp_json_encode($bundle)]);

        $this->assertCount(1, $out['imported']);
    }

    public function test_a_tampered_bundle_is_refused_whole(): void
    {
        $this->snippet('alpha');
        $bundle                     = $this->export()['bundle'];
        $bundle['items'][0]['code'] = '<?php return 2;';
        delete_option(Php_Snippet_Store::OPTION_NAME);

        $out = $this->import(['bundle' => $bundle]);

        $this->assertWPError($out);
        $this->assertSame('bundle_checksum_mismatch', $out->get_error_code());
        $this->assertSame([], Php_Snippet_Store::all(), 'Nothing is written from a bundle that fails its checksum');
    }

    public function test_an_unknown_format_or_newer_version_is_refused(): void
    {
        $bundle = Bundle::build([]);

        $newer            = $bundle;
        $newer['version'] = Bundle::VERSION + 1;
        $this->assertSame('bundle_version_unsupported', $this->import(['bundle' => $newer])->get_error_code());

        $foreign           = $bundle;
        $foreign['format'] = 'something-else';
        $this->assertSame('bundle_invalid', $this->import(['bundle' => $foreign])->get_error_code());

        $this->assertSame('bundle_invalid', $this->import(['bundle' => 'not json'])->get_error_code());
        $this->assertSame('bundle_invalid', $this->import([])->get_error_code());
    }

    public function test_the_checksum_ignores_key_order(): void
    {
        $items = [['type' => 'snippet', 'name' => 'a', 'code' => '<?php return 1;']];
        $swap  = [['code' => '<?php return 1;', 'name' => 'a', 'type' => 'snippet']];

        $this->assertSame(Bundle::checksum($items), Bundle::checksum($swap));
    }

    public function test_an_invalid_on_conflict_value_is_refused(): void
    {
        $out = $this->import(['bundle' => Bundle::build([]), 'on_conflict' => 'overwrite']);

        $this->assertWPError($out);
        $this->assertSame('invalid_on_conflict', $out->get_error_code());
    }

    public function test_a_name_collision_is_refused_by_default(): void
    {
        $this->snippet('alpha', '<?php return "original";');
        $bundle = $this->export()['bundle'];

        $out = $this->import(['bundle' => $bundle]);

        $this->assertSame([], $out['imported']);
        $this->assertCount(1, $out['skipped']);
        $this->assertSame('alpha', $out['skipped'][0]['name']);
        $this->assertStringContainsString('already exists', $out['skipped'][0]['reason']);
        $this->assertCount(1, Php_Snippet_Store::all());
    }

    public function test_a_name_collision_is_renamed_when_asked(): void
    {
        $this->snippet('alpha');
        $bundle = $this->export()['bundle'];

        $first  = $this->import(['bundle' => $bundle, 'on_conflict' => 'rename']);
        $second = $this->import(['bundle' => $bundle, 'on_conflict' => 'rename']);

        $this->assertSame('alpha (2)', $first['imported'][0]['name']);
        $this->assertSame('alpha', $first['imported'][0]['renamed_from']);
        $this->assertSame('alpha (3)', $second['imported'][0]['name']);
        $this->assertCount(3, Php_Snippet_Store::all());
    }

    public function test_a_snippet_with_a_critical_finding_is_blocked_and_the_rest_import(): void
    {
        $items = [
            ['type' => 'snippet', 'name' => 'ok', 'code' => '<?php return 1;'],
            ['type' => 'snippet', 'name' => 'bad', 'code' => '<?php eval($x);'],
            ['type' => 'snippet', 'name' => 'broken', 'code' => '<?php return (;'],
        ];

        $out = $this->import(['bundle' => Bundle::build($items)]);

        $this->assertSame(['ok'], wp_list_pluck($out['imported'], 'name'));
        $skipped = [];
        foreach ($out['skipped'] as $row) {
            $skipped[ $row['name'] ] = $row['reason'];
        }
        $this->assertStringContainsString('eval', $skipped['bad']);
        $this->assertStringContainsString('parse', $skipped['broken']);
        $this->assertCount(1, Php_Snippet_Store::all());
    }

    public function test_malformed_and_unknown_items_are_skipped_not_fatal(): void
    {
        $items = [
            'not an item',
            ['type' => 'snippet', 'name' => '', 'code' => '<?php return 1;'],
            ['type' => 'snippet', 'name' => 'no-code'],
            ['type' => 'gadget', 'name' => 'mystery'],
            ['type' => 'snippet', 'name' => 'fine', 'code' => '<?php return 1;'],
        ];

        $out = $this->import(['bundle' => Bundle::build($items)]);

        $this->assertSame(['fine'], wp_list_pluck($out['imported'], 'name'));
        $this->assertCount(4, $out['skipped']);
        $this->assertStringContainsString('not available', $out['skipped'][3]['reason']);
    }

    public function test_the_import_is_one_session_and_rollback_session_removes_it(): void
    {
        $existing = $this->snippet('existing');
        $bundle   = Bundle::build([
            ['type' => 'snippet', 'name' => 'one', 'code' => '<?php return 1;'],
            ['type' => 'snippet', 'name' => 'two', 'code' => '<?php return 2;'],
        ]);

        $out = $this->import(['bundle' => $bundle]);

        $this->assertNotSame('', $out['session_id']);
        $this->assertNotSame('default', $out['session_id'], 'An import gets its own session unless the caller names one');
        $this->assertCount(2, Snapshot_Store::list_by_session($out['session_id']));

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);

        $this->assertSame([$existing], array_keys(Php_Snippet_Store::all()), 'Only the imported snippets are removed');
    }

    public function test_a_session_id_longer_than_the_ledger_column_is_refused(): void
    {
        $out = $this->import(['bundle' => Bundle::build([]), 'session_id' => str_repeat('s', 37)]);

        $this->assertWPError($out);
        $this->assertSame('invalid_session_id', $out->get_error_code());
    }

    public function test_a_caller_session_id_is_used(): void
    {
        $out = $this->import([
            'bundle'     => Bundle::build([['type' => 'snippet', 'name' => 'one', 'code' => '<?php return 1;']]),
            'session_id' => 'agency-move',
        ]);

        $this->assertSame('agency-move', $out['session_id']);
        $this->assertCount(1, Snapshot_Store::list_by_session('agency-move'));
    }

    public function test_the_default_kinds_include_the_snippet_store(): void
    {
        wp_get_abilities();
        $this->assertArrayHasKey('snippet', Bundle_Kinds::all());
    }

    public function test_the_bundle_abilities_are_registered_free_with_the_right_operations(): void
    {
        wp_get_abilities();
        $abilities = [];
        foreach (\WPMCP\Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $export = $abilities['wpmcp/export-bundle'];
        $import = $abilities['wpmcp/import-bundle'];

        $this->assertSame('free', $export->tier);
        $this->assertSame('free', $import->tier);
        $this->assertSame('read', $export->operation);
        $this->assertTrue($export->read_only_hint);
        $this->assertSame('create', $import->operation);
        $this->assertSame('manage_options', $export->capability);
        $this->assertSame('manage_options', $import->capability);
        $this->assertArrayHasKey('session_id', $import->input_schema['properties']);
        $this->assertSame(['bundle'], $import->input_schema['required']);
    }
}
