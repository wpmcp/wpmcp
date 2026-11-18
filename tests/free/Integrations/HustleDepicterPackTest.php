<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Depicter_Pack;
use WPMCP\Integrations\Hustle_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;

/**
 * Hustle and Depicter (issue #898): read-only free-tier ops on the
 * plugin-data dispatcher pair.
 *
 * Neither plugin is loaded in the shared test core. Presence is driven
 * through wpmcp_hustle_active and wpmcp_depicter_active, and their tables
 * are created here with the columns the plugins create: Hustle 7.8
 * (inc/class-hustle-db.php: hustle_modules, hustle_modules_meta,
 * hustle_tracking) and Depicter 4.8 (app/src/Database/Migration.php:
 * depicter_documents, depicter_meta, depicter_document_analytics). Meta
 * values are JSON, as Hustle_Model::update_meta() and Depicter's
 * DisplayRules::set() store them.
 */
class HustleDepicterPackTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::drop();
        $p = $wpdb->prefix;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE {$p}hustle_modules (module_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, blog_id bigint(20) UNSIGNED NOT NULL DEFAULT '0', module_name varchar(255) NOT NULL, module_type varchar(100) NOT NULL, active tinyint DEFAULT 1, module_mode varchar(100) NOT NULL, PRIMARY KEY (module_id), KEY active (active))");
        $wpdb->query("CREATE TABLE {$p}hustle_modules_meta (meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, module_id bigint(20) UNSIGNED NOT NULL DEFAULT '0', meta_key varchar(191) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY module_id (module_id), KEY meta_key (meta_key))");
        $wpdb->query("CREATE TABLE {$p}hustle_tracking (tracking_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, module_id bigint(20) UNSIGNED NOT NULL, page_id bigint(20) UNSIGNED NOT NULL, module_type varchar(100) NOT NULL, action varchar(100) NOT NULL, ip varchar(191) DEFAULT NULL, counter mediumint(8) UNSIGNED NOT NULL DEFAULT 1, date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00', date_updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (tracking_id))");
        $wpdb->query("CREATE TABLE {$p}depicter_documents (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name text NOT NULL, slug varchar(100) NOT NULL, type varchar(50) NOT NULL DEFAULT 'custom', author bigint(20) unsigned NOT NULL DEFAULT '0', sections_count mediumint NOT NULL DEFAULT '0', created_at datetime DEFAULT NULL, modified_at datetime DEFAULT NULL, thumbnail varchar(255) NOT NULL, content longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft', parent bigint(20) unsigned NOT NULL DEFAULT '0', password varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (id))");
        $wpdb->query("CREATE TABLE {$p}depicter_meta (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, relation varchar(30) NOT NULL DEFAULT '', relation_id bigint(20) NOT NULL, meta_key varchar(30) NOT NULL DEFAULT '', meta_value text NOT NULL, PRIMARY KEY (id))");
        $wpdb->query("CREATE TABLE {$p}depicter_document_analytics (id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, source_id BIGINT(20) UNSIGNED NOT NULL, event_type VARCHAR(20) NOT NULL, session_id CHAR(32) NOT NULL, user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0, ip_address VARBINARY(16) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop();
    }

    private static function drop(): void
    {
        global $wpdb;
        foreach ([ 'hustle_modules', 'hustle_modules_meta', 'hustle_tracking', 'depicter_documents', 'depicter_meta', 'depicter_document_analytics' ] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $t); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixture teardown of literal names.
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        foreach ([ 'hustle_modules', 'hustle_modules_meta', 'hustle_tracking', 'depicter_documents', 'depicter_meta', 'depicter_document_analytics' ] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixture reset of literal names.
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_hustle_active', '__return_true');
        add_filter('wpmcp_depicter_active', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_hustle_active');
        remove_all_filters('wpmcp_depicter_active');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A Hustle module as Hustle_Module_Model::save() stores it. */
    private function module(string $name, string $type = 'popup', bool $active = true, bool $scheduled = false): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'hustle_modules', [ 'blog_id' => 0, 'module_name' => $name, 'module_type' => $type, 'active' => $active ? 1 : 0, 'module_mode' => 'informational' ]);
        $id    = (int) $wpdb->insert_id;
        $metas = [
            'content'               => [ 'module_name' => $name, 'title' => $name . ' title', 'sub_title' => 'Limited time', 'main_content' => '<p>Use code SAVE10</p>', 'show_cta' => '1', 'cta_label' => 'Shop', 'cta_url' => 'https://shop.example.com' ],
            'settings'              => [
                'triggers'    => [ 'trigger' => [ 'time', 'exit_intent' ], 'on_time_delay' => '3', 'on_time_unit' => 'seconds' ],
                'is_schedule' => $scheduled ? '1' : '0',
                'schedule'    => [ 'not_schedule_start' => '0', 'start_date' => '11/20/2026', 'start_hour' => '12', 'start_minute' => '00', 'start_meridiem_offset' => 'am', 'not_schedule_end' => '0', 'end_date' => '11/30/2026', 'end_hour' => '11', 'end_minute' => '59', 'end_meridiem_offset' => 'pm', 'time_to_use' => 'server' ],
            ],
            'visibility'            => [ 'conditions' => [ 'group-1' => [ 'show_or_hide_conditions' => 'show', 'pages' => [ 'filter_type' => 'only', 'pages' => [ '12' ] ] ] ] ],
            'shortcode_id'          => 'spring-' . $id,
            'integrations_settings' => [ 'mailchimp' => [ 'api_key' => 'secret-api-key-123' ] ],
            'emails'                => [ 'form_elements' => [ 'email' => [ 'placeholder' => 'you@example.com' ] ] ],
        ];
        foreach ($metas as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'hustle_modules_meta', [ 'module_id' => $id, 'meta_key' => $key, 'meta_value' => is_array($value) ? wp_json_encode($value) : $value ]);
        }
        return $id;
    }

    private function track(int $module, string $action, int $counter): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'hustle_tracking', [ 'module_id' => $module, 'page_id' => 12, 'module_type' => 'popup', 'action' => $action, 'ip' => '203.0.113.9', 'counter' => $counter, 'date_created' => '2026-11-18 10:00:00', 'date_updated' => '2026-11-18 10:00:00' ]);
    }

    private function document(string $name, string $type = 'popup', string $status = 'publish', int $parent = 0): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'depicter_documents', [ 'name' => $name, 'slug' => sanitize_title($name), 'type' => $type, 'author' => 1, 'sections_count' => 2, 'created_at' => '2026-11-01 10:00:00', 'modified_at' => '2026-11-0' . (string) (1 + $parent % 8) . ' 11:00:00', 'thumbnail' => '', 'content' => '{"sections":{}}', 'status' => $status, 'parent' => $parent, 'password' => 'hunter2' ]);
        return (int) $wpdb->insert_id;
    }

    // ---------------------------------------------------------------
    // Hustle
    // ---------------------------------------------------------------

    public function test_hustle_ops_are_read_only_catalog_entries(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'hustle-list-modules', 'hustle-get-module', 'depicter-list-documents', 'depicter-get-document' ] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'] ?? null, $op);
        }
    }

    public function test_hustle_missing_answers_plugin_missing_and_presence_defaults_to_its_constant(): void
    {
        remove_all_filters('wpmcp_hustle_active');
        $this->assertSame(defined('HUSTLE_BASE_FILE'), Hustle_Pack::active());
        add_filter('wpmcp_hustle_active', '__return_false');
        $out = $this->read('hustle-list-modules');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Install and activate Hustle', $out['error']['message']);
    }

    public function test_hustle_list_modules_returns_switch_triggers_schedule_and_counts(): void
    {
        $a = $this->module('Spring popup', 'popup', true, true);
        $b = $this->module('Footer slide', 'slidein', false);
        $this->track($a, 'view', 40);
        $this->track($a, 'view', 2);
        $this->track($a, 'cta_conversion', 5);
        $this->track($a, 'optin_conversion', 1);

        $out = $this->ok($this->read('hustle-list-modules'));
        $this->assertSame(2, $out['total']);
        $rows = array_column($out['modules'], null, 'id');
        $this->assertTrue($rows[ $a ]['active']);
        $this->assertFalse($rows[ $b ]['active']);
        $this->assertSame('Spring popup title', $rows[ $a ]['title']);
        $this->assertSame([ 'time', 'exit_intent' ], $rows[ $a ]['triggers']);
        $this->assertSame([ 'enabled' => true, 'start' => '11/20/2026 12:00 am', 'end' => '11/30/2026 11:59 pm', 'timezone' => 'server' ], $rows[ $a ]['schedule']);
        $this->assertFalse($rows[ $b ]['schedule']['enabled']);
        $this->assertNull($rows[ $b ]['schedule']['start']);
        $this->assertSame(42, $rows[ $a ]['views']);
        $this->assertSame(6, $rows[ $a ]['conversions']);
        $this->assertSame(0, $rows[ $b ]['views']);

        $this->assertSame([ $b ], array_column($this->read('hustle-list-modules', [ 'type' => 'slidein' ])['modules'], 'id'));
        $this->assertSame([ $b ], array_column($this->read('hustle-list-modules', [ 'active' => false ])['modules'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('hustle-list-modules', [ 'search' => 'Spring' ])['modules'], 'id'));
    }

    public function test_hustle_get_module_reads_content_and_conditions_but_never_keys_emails_or_ips(): void
    {
        $id = $this->module('Spring popup');
        $this->track($id, 'cta_conversion', 3);

        $out    = $this->ok($this->read('hustle-get-module', [ 'module_id' => $id ]));
        $module = $out['module'];
        $this->assertSame('<p>Use code SAVE10</p>', $module['content']['main_content']);
        $this->assertSame('https://shop.example.com', $module['content']['cta_url']);
        $this->assertSame('only', $module['visibility']['conditions']['group-1']['pages']['filter_type']);
        $this->assertSame([ 'cta_conversion' => 3 ], $module['conversions_by']);
        $this->assertSame('[wd_hustle id="spring-' . $id . '" type="popup"]', $module['shortcode']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('secret-api-key-123', $json);
        $this->assertStringNotContainsString('you@example.com', $json);
        $this->assertStringNotContainsString('203.0.113.9', $json);
        $this->assertSame('module_not_found', $this->read('hustle-get-module', [ 'module_id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_hustle_reads_need_its_capability(): void
    {
        $this->module('Spring popup');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('hustle-list-modules')['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Depicter
    // ---------------------------------------------------------------

    public function test_depicter_missing_answers_plugin_missing_and_presence_defaults_to_its_constant(): void
    {
        remove_all_filters('wpmcp_depicter_active');
        $this->assertSame(defined('DEPICTER_PLUGIN_URL'), Depicter_Pack::active());
        add_filter('wpmcp_depicter_active', '__return_false');
        $out = $this->read('depicter-list-documents');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Install and activate Depicter', $out['error']['message']);
    }

    public function test_depicter_list_documents_skips_revisions_and_counts_events(): void
    {
        global $wpdb;
        $popup  = $this->document('Exit offer', 'popup');
        $slider = $this->document('Home slider', 'custom', 'draft');
        $this->document('Exit offer revision', 'popup', 'publish', $popup);
        foreach ([ 'view', 'view', 'impression', 'click', 'submission' ] as $event) {
            $wpdb->insert($wpdb->prefix . 'depicter_document_analytics', [ 'source_id' => $popup, 'event_type' => $event, 'session_id' => str_repeat('a', 32), 'user_id' => 0, 'ip_address' => inet_pton('203.0.113.9'), 'created_at' => '2026-11-18 10:00:00' ]);
        }

        $out = $this->ok($this->read('depicter-list-documents'));
        $this->assertSame(2, $out['total'], 'a revision (parent set) is not a document');
        $rows = array_column($out['documents'], null, 'id');
        $this->assertSame(2, $rows[ $popup ]['views']);
        $this->assertSame(1, $rows[ $popup ]['impressions']);
        $this->assertSame(1, $rows[ $popup ]['clicks']);
        $this->assertSame(1, $rows[ $popup ]['submissions']);
        $this->assertSame(0, $rows[ $slider ]['views']);
        $this->assertSame('[depicter id="' . $popup . '"]', $rows[ $popup ]['shortcode']);
        $this->assertArrayNotHasKey('password', $rows[ $popup ]);
        $this->assertSame([ $popup ], array_column($this->read('depicter-list-documents', [ 'type' => 'popup' ])['documents'], 'id'));
        $this->assertSame([ $slider ], array_column($this->read('depicter-list-documents', [ 'status' => 'draft' ])['documents'], 'id'));
        $this->assertSame([ $slider ], array_column($this->read('depicter-list-documents', [ 'search' => 'Home' ])['documents'], 'id'));
    }

    public function test_depicter_get_document_returns_display_rules_without_content_or_password(): void
    {
        global $wpdb;
        $id = $this->document('Exit offer');
        $wpdb->insert($wpdb->prefix . 'depicter_meta', [ 'relation' => 'document', 'relation_id' => $id, 'meta_key' => 'rules', 'meta_value' => wp_json_encode([ 'displayConditions' => [ [ 'type' => 'WP_Page', 'value' => [ 12 ], 'mode' => 'include' ] ] ]) ]);

        $out = $this->ok($this->read('depicter-get-document', [ 'document_id' => $id ]));
        $doc = $out['document'];
        $this->assertSame('include', $doc['display_rules']['displayConditions'][0]['mode']);
        $this->assertSame(strlen('{"sections":{}}'), $doc['content_length']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('sections":{', $json);
        $this->assertSame('document_not_found', $this->read('depicter-get-document', [ 'document_id' => 999999 ])['error']['code'] ?? null);
        $revision = $this->document('Rev', 'popup', 'publish', $id);
        $this->assertSame('document_not_found', $this->read('depicter-get-document', [ 'document_id' => $revision ])['error']['code'] ?? null);
    }

    public function test_depicter_reads_need_its_capability(): void
    {
        $this->document('Exit offer');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('depicter-list-documents')['error']['code'] ?? null);
    }
}
