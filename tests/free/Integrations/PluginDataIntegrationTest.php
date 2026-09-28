<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/plugin-data-stubs.php';

/**
 * The plugin-data dispatcher pair (issue #299): JetEngine and Pods custom
 * field values and TranslatePress string translations, as free ops on one
 * pair that registers only while at least one of the three plugins is
 * loaded.
 *
 * Presence is driven through the wpmcp_jetengine_active, wpmcp_pods_active
 * and wpmcp_translatepress_active filters, the field registries through the
 * doubles in tests/support/plugin-data-stubs.php, and the Pods table-storage
 * and TranslatePress dictionary tables are created from the plugins' own
 * schemas, so none of the plugins is installed into the shared test core.
 *
 * Each plugin gets a round trip, every write is snapshotted and rolled back
 * exactly, and an inactive plugin's ops answer <plugin>_inactive without
 * touching anything.
 */
class PluginDataIntegrationTest extends \WP_UnitTestCase
{
    private const ACTIVE_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active' ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_pods_table('book');
        wpmcp_test_create_trp_table('en_US', 'de_DE');
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_pods_table('book');
        wpmcp_test_drop_trp_table('en_US', 'de_DE');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::ACTIVE_FILTERS as $filter) {
            add_filter($filter, '__return_true');
        }
        register_post_type('book', [ 'public' => true ]);

        \Wpmcp_Test_JetEngine_Meta_Boxes::$fields = [
            'post' => [
                [ 'name' => 'main_tab', 'title' => 'Main', 'type' => '', 'object_type' => 'tab' ],
                [ 'name' => 'subtitle', 'title' => 'Subtitle', 'type' => 'text', 'object_type' => 'field' ],
                [ 'name' => 'features', 'title' => 'Features', 'type' => 'checkbox', 'object_type' => 'field' ],
                [ 'name' => 'notice', 'title' => 'Notice', 'type' => 'html', 'object_type' => 'field' ],
            ],
        ];

        global $wpdb;
        \Wpmcp_Test_Pods_API::$pods = [
            'post' => [
                'name'    => 'post',
                'type'    => 'post_type',
                'storage' => 'meta',
                'fields'  => [
                    'tagline' => [ 'name' => 'tagline', 'label' => 'Tagline', 'type' => 'text' ],
                    'rating'  => [ 'name' => 'rating', 'label' => 'Rating', 'type' => 'number' ],
                    'related' => [ 'name' => 'related', 'label' => 'Related', 'type' => 'pick' ],
                ],
            ],
            'book' => [
                'name'      => 'book',
                'type'      => 'post_type',
                'storage'   => 'table',
                'pod_table' => $wpdb->prefix . 'pods_book',
                'fields'    => [
                    'isbn'     => [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ],
                    'pages'    => [ 'name' => 'pages', 'label' => 'Pages', 'type' => 'number' ],
                    'in_print' => [ 'name' => 'in_print', 'label' => 'In print', 'type' => 'boolean' ],
                    'author'   => [ 'name' => 'author', 'label' => 'Author', 'type' => 'pick' ],
                ],
            ],
        ];

        update_option('trp_settings', [
            'default-language'      => 'en_US',
            'translation-languages' => [ 'en_US', 'de_DE' ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::ACTIVE_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_enable_jetengine_write');
        remove_all_filters('wpmcp_enable_pods_write');
        unregister_post_type('book');
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

    private function error_code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    // Registration and inactive plugins.

    public function test_pair_is_free_and_registers_only_while_a_plugin_is_loaded(): void
    {
        $integration = new Plugin_Data_Integration();
        $this->assertSame('plugin-data', $integration->integration());
        $this->assertSame('free', $integration->tier());
        $this->assertTrue($integration->registers_only_when_available());
        $this->assertTrue($integration->should_register());

        foreach (self::ACTIVE_FILTERS as $filter) {
            remove_all_filters($filter);
            add_filter($filter, '__return_false');
        }
        $this->assertFalse($integration->is_available());
        $this->assertFalse($integration->should_register());

        remove_all_filters('wpmcp_pods_active');
        add_filter('wpmcp_pods_active', '__return_true');
        $this->assertTrue($integration->should_register());
    }

    public function test_catalog_lists_every_op_for_each_plugin(): void
    {
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach ([
            'jetengine-list-fields', 'jetengine-get-fields', 'jetengine-update-fields',
            'pods-list-fields', 'pods-get-fields', 'pods-update-fields',
            'translatepress-list-languages', 'translatepress-get-strings', 'translatepress-update-strings',
        ] as $op) {
            $this->assertContains($op, $names);
        }
    }

    public function test_inactive_plugin_ops_are_skipped_cleanly(): void
    {
        remove_all_filters('wpmcp_jetengine_active');
        add_filter('wpmcp_jetengine_active', '__return_false');
        add_filter('wpmcp_enable_jetengine_write', '__return_true');
        $post_id = self::factory()->post->create();

        $this->assertSame('jetengine_inactive', $this->error_code($this->read('jetengine-list-fields', [ 'post_type' => 'post' ])));
        $out = $this->write('jetengine-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'subtitle' => 'x' ] ]);
        $this->assertSame('jetengine_inactive', $this->error_code($out));
        $this->assertSame('', get_post_meta($post_id, 'subtitle', true));

        $catalog = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($catalog['jetengine-get-fields']['dependency_met']);
        $this->assertTrue($catalog['pods-get-fields']['dependency_met']);

        remove_all_filters('wpmcp_translatepress_active');
        add_filter('wpmcp_translatepress_active', '__return_false');
        $this->assertSame('translatepress_inactive', $this->error_code($this->read('translatepress-list-languages')));
        remove_all_filters('wpmcp_pods_active');
        add_filter('wpmcp_pods_active', '__return_false');
        $this->assertSame('pods_inactive', $this->error_code($this->read('pods-list-fields', [ 'post_type' => 'post' ])));
    }

    // JetEngine.

    public function test_jetengine_list_fields_returns_only_value_fields(): void
    {
        $out = $this->read('jetengine-list-fields', [ 'post_type' => 'post' ]);
        $this->assertSame([ 'subtitle', 'features' ], array_column($out['result']['fields'], 'name'));
        $this->assertSame('text', $out['result']['fields'][0]['type']);
        $this->assertSame('Subtitle', $out['result']['fields'][0]['title']);
    }

    public function test_jetengine_round_trip_and_rollback(): void
    {
        add_filter('wpmcp_enable_jetengine_write', '__return_true');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'subtitle', 'Before');

        $out = $this->write('jetengine-update-fields', [
            'post_id' => $post_id,
            'fields'  => [ 'subtitle' => 'After', 'features' => [ 'wifi' => 'true', 'pool' => 'false' ] ],
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);

        $read = $this->read('jetengine-get-fields', [ 'post_id' => $post_id ]);
        $this->assertSame('After', $read['result']['fields']['subtitle']);
        $this->assertSame([ 'wifi' => 'true', 'pool' => 'false' ], $read['result']['fields']['features']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Before', get_post_meta($post_id, 'subtitle', true));
        $this->assertFalse(metadata_exists('post', $post_id, 'features'));
    }

    public function test_jetengine_write_is_disabled_by_default(): void
    {
        $post_id = self::factory()->post->create();
        $out     = $this->write('jetengine-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'subtitle' => 'x' ] ]);
        $this->assertSame('operation_disabled', $this->error_code($out));
    }

    public function test_jetengine_write_refuses_undefined_and_layout_fields(): void
    {
        add_filter('wpmcp_enable_jetengine_write', '__return_true');
        $post_id = self::factory()->post->create();

        $out = $this->write('jetengine-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'not_a_field' => 'x' ] ]);
        $this->assertSame('unknown_field', $this->error_code($out));
        $out = $this->write('jetengine-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'notice' => 'x' ] ]);
        $this->assertSame('field_not_writable', $this->error_code($out));
        $this->assertFalse(metadata_exists('post', $post_id, 'not_a_field'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_jetengine_write_needs_edit_post_on_the_target(): void
    {
        add_filter('wpmcp_enable_jetengine_write', '__return_true');
        $post_id = self::factory()->post->create([ 'post_author' => self::factory()->user->create() ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));

        $out = $this->write('jetengine-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'subtitle' => 'x' ] ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame('', get_post_meta($post_id, 'subtitle', true));
    }

    // Pods.

    public function test_pods_list_fields_reports_storage_and_writability(): void
    {
        $out    = $this->read('pods-list-fields', [ 'post_type' => 'book' ]);
        $fields = array_column($out['result']['fields'], null, 'name');
        $this->assertSame('table', $out['result']['storage']);
        $this->assertTrue($fields['isbn']['writable']);
        $this->assertFalse($fields['author']['writable']);

        $this->assertSame('no_pod', $this->error_code($this->read('pods-list-fields', [ 'post_type' => 'page' ])));
    }

    public function test_pods_meta_round_trip_and_rollback(): void
    {
        add_filter('wpmcp_enable_pods_write', '__return_true');
        $post_id = self::factory()->post->create();
        update_post_meta($post_id, 'tagline', 'Old line');

        $out = $this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'tagline' => 'New line', 'rating' => 4 ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);

        $read = $this->read('pods-get-fields', [ 'post_id' => $post_id, 'keys' => [ 'tagline', 'rating' ] ]);
        $this->assertSame('New line', $read['result']['fields']['tagline']);
        $this->assertSame('4', (string) $read['result']['fields']['rating']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Old line', get_post_meta($post_id, 'tagline', true));
        $this->assertFalse(metadata_exists('post', $post_id, 'rating'));
    }

    public function test_pods_write_refuses_relationship_fields_and_arrays(): void
    {
        add_filter('wpmcp_enable_pods_write', '__return_true');
        $post_id = self::factory()->post->create();

        $this->assertSame('field_not_writable', $this->error_code($this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'related' => 5 ] ])));
        $this->assertSame('invalid_value', $this->error_code($this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'tagline' => [ 'a' ] ] ])));
        $this->assertSame('unknown_field', $this->error_code($this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'nope' => 'x' ] ])));
        $this->assertFalse(metadata_exists('post', $post_id, 'related'));
    }

    public function test_pods_write_is_disabled_by_default(): void
    {
        $post_id = self::factory()->post->create();
        $this->assertSame('operation_disabled', $this->error_code($this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'tagline' => 'x' ] ])));
    }

    public function test_pods_table_storage_insert_round_trip_and_rollback_removes_the_row(): void
    {
        add_filter('wpmcp_enable_pods_write', '__return_true');
        $post_id = self::factory()->post->create([ 'post_type' => 'book' ]);

        $out = $this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'isbn' => '978-3', 'in_print' => true ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);

        $rows = wpmcp_test_pods_rows('book', $post_id);
        $this->assertCount(1, $rows);
        $this->assertSame('978-3', $rows[0]['isbn']);
        $this->assertSame('1', (string) $rows[0]['in_print']);
        $this->assertFalse(metadata_exists('post', $post_id, 'isbn'), 'table storage never writes post meta');

        $read = $this->read('pods-get-fields', [ 'post_id' => $post_id, 'keys' => [ 'isbn' ] ]);
        $this->assertSame('978-3', $read['result']['fields']['isbn']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([], wpmcp_test_pods_rows('book', $post_id));
    }

    public function test_pods_table_storage_update_rolls_back_the_row_exactly(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_pods_write', '__return_true');
        $post_id = self::factory()->post->create([ 'post_type' => 'book' ]);
        $wpdb->insert($wpdb->prefix . 'pods_book', [ 'id' => $post_id, 'isbn' => 'old', 'pages' => 120, 'in_print' => 0 ]);
        $before = wpmcp_test_pods_rows('book', $post_id);

        $out = $this->write('pods-update-fields', [ 'post_id' => $post_id, 'fields' => [ 'pages' => 300 ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $after = wpmcp_test_pods_rows('book', $post_id);
        $this->assertSame('300', (string) $after[0]['pages']);
        $this->assertSame('old', $after[0]['isbn'], 'only the named column changes');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_pods_rows('book', $post_id));
    }

    // TranslatePress.

    public function test_translatepress_lists_languages(): void
    {
        $out = $this->read('translatepress-list-languages');
        $this->assertSame('en_US', $out['result']['default_language']);
        $this->assertSame([ 'de_DE' ], $out['result']['languages']);
    }

    public function test_translatepress_get_strings_filters_and_pages(): void
    {
        wpmcp_test_trp_insert('en_US', 'de_DE', 'Hello world', 'Hallo Welt', 2);
        wpmcp_test_trp_insert('en_US', 'de_DE', 'Goodbye', null, 0);

        $all = $this->read('translatepress-get-strings', [ 'language' => 'de_DE' ]);
        $this->assertSame(2, $all['result']['total']);

        $hit = $this->read('translatepress-get-strings', [ 'language' => 'de_DE', 'search' => 'Hello' ]);
        $this->assertSame(1, $hit['result']['total']);
        $this->assertSame('Hallo Welt', $hit['result']['strings'][0]['translated']);

        $untranslated = $this->read('translatepress-get-strings', [ 'language' => 'de_DE', 'status' => 0 ]);
        $this->assertSame([ 'Goodbye' ], array_column($untranslated['result']['strings'], 'original'));

        $this->assertSame('unknown_language', $this->error_code($this->read('translatepress-get-strings', [ 'language' => 'fr_FR' ])));
        $this->assertSame('unknown_language', $this->error_code($this->read('translatepress-get-strings', [ 'language' => 'en_US' ])));
    }

    public function test_translatepress_round_trip_and_rollback_restores_rows_exactly(): void
    {
        $a      = wpmcp_test_trp_insert('en_US', 'de_DE', 'Hello world', null, 0);
        $b      = wpmcp_test_trp_insert('en_US', 'de_DE', 'Goodbye', 'Tschuess', 1);
        $before = wpmcp_test_trp_rows('en_US', 'de_DE');

        $out = $this->write('translatepress-update-strings', [
            'language'     => 'de_DE',
            'translations' => [
                [ 'id' => $a, 'translated' => 'Hallo Welt' ],
                [ 'id' => $b, 'translated' => 'Auf Wiedersehen' ],
            ],
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);

        $read = $this->read('translatepress-get-strings', [ 'language' => 'de_DE' ]);
        $rows = array_column($read['result']['strings'], null, 'id');
        $this->assertSame('Hallo Welt', $rows[ $a ]['translated']);
        $this->assertSame(2, $rows[ $a ]['status'], 'a written translation is human reviewed');
        $this->assertSame('Auf Wiedersehen', $rows[ $b ]['translated']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_trp_rows('en_US', 'de_DE'));
    }

    public function test_translatepress_write_refuses_unknown_rows_and_needs_manage_options(): void
    {
        $a = wpmcp_test_trp_insert('en_US', 'de_DE', 'Hello world');

        $out = $this->write('translatepress-update-strings', [ 'language' => 'de_DE', 'translations' => [ [ 'id' => $a + 999, 'translated' => 'x' ] ] ]);
        $this->assertSame('unknown_string', $this->error_code($out));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->write('translatepress-update-strings', [ 'language' => 'de_DE', 'translations' => [ [ 'id' => $a, 'translated' => 'x' ] ] ]);
        $this->assertSame('operation_denied', $this->error_code($out));
        $this->assertNull(wpmcp_test_trp_rows('en_US', 'de_DE')[0]['translated']);
    }
}
