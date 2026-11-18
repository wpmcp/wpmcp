<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Thrive_Leads_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Thrive Leads (issue #825): paid-tier ops on the plugin-data pair, so they
 * add no top-level tools.
 *
 * Thrive Leads is a licensed plugin and is not loaded in the shared test
 * core. Its post types are registered here the way 10.9.2 does in
 * inc/hooks.php (tve_lead_group, tve_form_type under a group,
 * tve_lead_shortcode), and its own tables are created from its install and
 * later migrations: {prefix}tve_leads_group_options (a lead group's display
 * rules as JSON), {prefix}tve_leads_form_variations (one row per form design
 * with its trigger, trigger_config, display_frequency, position and
 * display_animation) and {prefix}tve_leads_form_summary (daily impression and
 * conversion counts). Presence is driven through wpmcp_thrive_leads_active.
 *
 * The writes are off until the site opts in (wpmcp_enable_thrive_leads_write)
 * and each is undone in a test.
 */
class ThriveLeadsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'thriveleads-list-lead-groups'                => 'read',
        'thriveleads-get-lead-group'                  => 'read',
        'thriveleads-list-forms'                      => 'read',
        'thriveleads-get-form'                        => 'read',
        'thriveleads-list-shortcodes'                 => 'read',
        'thriveleads-get-conversion-stats'            => 'read',
        'thriveleads-update-form-settings'            => 'write',
        'thriveleads-toggle-form'                     => 'write',
        'thriveleads-update-lead-group-display-rules' => 'write',
    ];

    private const SHOW = '{"tabs":[{"options":[{"id":"front_page","type":"other_screens","isChecked":true}]},{"options":[]}]}';

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'tve_leads_';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}group_options (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `group` BIGINT(20) UNSIGNED NOT NULL,
            `description` VARCHAR(255),
            `show_group_options` LONGTEXT NULL,
            `hide_group_options` LONGTEXT NULL,
            PRIMARY KEY (`id`)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}form_variations` (
            `key` BIGINT(20) AUTO_INCREMENT,
            `date_added` DATETIME NULL,
            `date_modified` DATETIME NULL,
            `post_parent` BIGINT(20),
            `post_status` VARCHAR(20) NULL DEFAULT 'publish',
            `post_title` TEXT NULL DEFAULT NULL,
            `content` LONGTEXT NULL DEFAULT NULL,
            `trigger` VARCHAR(64) NULL DEFAULT NULL,
            `trigger_config` TEXT NULL DEFAULT NULL,
            `tcb_fields` LONGTEXT NULL DEFAULT NULL,
            `display_frequency` INT(11) NULL DEFAULT '0',
            `display_animation` VARCHAR(64) NULL DEFAULT 'instant',
            `position` VARCHAR(32) NULL DEFAULT 'bot_right',
            `cache_impressions` BIGINT(20) NULL DEFAULT NULL,
            `cache_conversions` BIGINT(20) NULL DEFAULT NULL,
            `form_state` VARCHAR(64) NULL DEFAULT NULL,
            `parent_id` INT(11) NULL DEFAULT '0',
            `state_order` INT(11) NULL DEFAULT '0',
            PRIMARY KEY (`key`)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}form_summary (
            `id` INT(11) AUTO_INCREMENT,
            `date` VARCHAR(10) NULL DEFAULT NULL,
            `main_group_id` INT(11) NULL DEFAULT NULL,
            `form_type_id` INT(11) NULL,
            `variation_key` INT(11) NULL,
            `impression_count` INT(11) NULL DEFAULT 0,
            `unique_visitor_count` INT(11) NULL DEFAULT 0,
            `conversion_count` INT(11) NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        )");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ 'group_options', 'form_variations', 'form_summary' ] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tve_leads_' . $t);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        foreach ([ 'tve_lead_group', 'tve_form_type', 'tve_lead_shortcode' ] as $type) {
            register_post_type($type, [ 'public' => false, 'hierarchical' => true, 'map_meta_cap' => true ]);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_thrive_leads_active', '__return_true');
        add_filter('wpmcp_enable_thrive_leads_write', '__return_true');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([ 'group_options', 'form_variations', 'form_summary' ] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'tve_leads_' . $t);
        }
        remove_all_filters('wpmcp_thrive_leads_active');
        remove_all_filters('wpmcp_enable_thrive_leads_write');
        foreach ([ 'tve_lead_group', 'tve_form_type', 'tve_lead_shortcode' ] as $type) {
            unregister_post_type($type);
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

    /** A lead group as tve_leads_save_group() stores it. */
    private function group(string $title = 'Main group', int $order = 0): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'tve_lead_group', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, 'tve_group_order', $order);
        return $id;
    }

    /** A form type under a group as tve_leads_save_form_type() stores it. */
    private function form(int $group, string $type = 'slide_in', array $meta = []): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'tve_form_type', 'post_title' => ucfirst($type), 'post_status' => 'publish', 'post_parent' => $group ]);
        update_post_meta($id, 'tve_form_type', $type);
        foreach ($meta as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        return $id;
    }

    /** A form variation row as Thrive_Leads_DB::save_form_variation() writes it. */
    private function variation(int $form, array $fields = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tve_leads_form_variations', $fields + [
            'date_added'        => '2026-11-01 10:00:00',
            'date_modified'     => '2026-11-01 10:00:00',
            'post_parent'       => $form,
            'post_status'       => 'publish',
            'post_title'        => 'Design A',
            'content'           => '<div class="thrv_lead_generation" data-api="mailchimp">form</div>',
            'trigger'           => 'time',
            'trigger_config'    => serialize([ 's' => 10, 'exi' => '' ]),
            'tcb_fields'        => serialize([ 'tve_custom_css' => '.x{}' ]),
            'display_frequency' => 0,
            'display_animation' => 'slide_right',
            'position'          => 'bot_right',
            'parent_id'         => 0,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function variation_row(int $key): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'tve_leads_form_variations WHERE `key` = %d', $key), ARRAY_A);
    }

    private function rules(int $group): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'tve_leads_group_options WHERE `group` = %d ORDER BY id', $group), ARRAY_A);
    }

    private function summary(string $date, int $group, int $form, int $key, int $imp, int $conv): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'tve_leads_form_summary', [ 'date' => $date, 'main_group_id' => $group, 'form_type_id' => $form, 'variation_key' => $key, 'impression_count' => $imp, 'unique_visitor_count' => $imp, 'conversion_count' => $conv ]);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_the_paid_tier(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        foreach (Thrive_Leads_Pack::operations() as $name => $def) {
            $this->assertSame('pro', $def['tier'] ?? null, $name);
        }
    }

    public function test_while_thrive_leads_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_thrive_leads_active');
        add_filter('wpmcp_thrive_leads_active', '__return_false');
        $out = $this->read('thriveleads-list-lead-groups');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Thrive Leads', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_thrive_leads_active');
        $this->assertSame(defined('TVE_LEADS_VERSION'), Thrive_Leads_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $group = $this->group();
        $form  = $this->form($group);
        $key   = $this->variation($form);
        remove_all_filters('wpmcp_enable_thrive_leads_write');
        foreach ([
            [ 'thriveleads-update-form-settings', [ 'id' => $form, 'trigger' => 'page_load' ] ],
            [ 'thriveleads-toggle-form', [ 'id' => $form, 'desktop' => false ] ],
            [ 'thriveleads-update-lead-group-display-rules', [ 'id' => $group, 'show' => [ 'tabs' => [] ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_thrive_leads_write', $out['error']['message']);
        }
        $this->assertSame('time', $this->variation_row($key)['trigger']);
        $this->assertSame('', get_post_meta($form, 'display_status', true));
    }

    public function test_only_thrive_leads_managers_may_read_or_write(): void
    {
        $group = $this->group();
        $form  = $this->form($group);
        $this->variation($form);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('thriveleads-list-lead-groups')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('thriveleads-get-conversion-stats')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('thriveleads-toggle-form', [ 'id' => $form, 'desktop' => false ])['error']['code'] ?? null);

        $editor = wp_get_current_user();
        $editor->add_cap('tve-use-tl');
        $this->ok($this->read('thriveleads-list-lead-groups'));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_lead_groups_returns_groups_in_order_with_forms_rules_and_counts(): void
    {
        global $wpdb;
        $second = $this->group('Second', 2);
        $first  = $this->group('First', 1);
        $form   = $this->form($first, 'lightbox');
        $key    = $this->variation($form);
        $wpdb->insert($wpdb->prefix . 'tve_leads_group_options', [ 'group' => $first, 'description' => '', 'show_group_options' => self::SHOW, 'hide_group_options' => '{"tabs":[]}' ]);
        $this->summary('2026-11-10', $first, $form, $key, 40, 4);

        $out    = $this->ok($this->read('thriveleads-list-lead-groups'))['result'];
        $groups = $out['lead_groups'];
        $this->assertSame([ $first, $second ], array_column($groups, 'id'));
        $this->assertSame('First', $groups[0]['title']);
        $this->assertSame(1, $groups[0]['forms']);
        $this->assertTrue($groups[0]['has_display_rules']);
        $this->assertFalse($groups[1]['has_display_rules']);
        $this->assertSame(40, $groups[0]['impressions']);
        $this->assertSame(4, $groups[0]['conversions']);
        $this->assertEquals(10.0, $groups[0]['conversion_rate']);
    }

    public function test_get_lead_group_returns_its_forms_and_decoded_display_rules(): void
    {
        global $wpdb;
        $group = $this->group('Main');
        $this->form($group, 'slide_in');
        $this->form($group, 'ribbon', [ 'display_status' => '0' ]);
        $wpdb->insert($wpdb->prefix . 'tve_leads_group_options', [ 'group' => $group, 'description' => 'Blog only', 'show_group_options' => self::SHOW, 'hide_group_options' => '{"tabs":[]}' ]);

        $g = $this->ok($this->read('thriveleads-get-lead-group', [ 'id' => $group ]))['result']['lead_group'];
        $this->assertSame('Main', $g['title']);
        $this->assertEqualsCanonicalizing([ 'slide_in', 'ribbon' ], array_column($g['forms'], 'type'));
        $this->assertSame(json_decode(self::SHOW, true), $g['display_rules']['show']);
        $this->assertSame([ 'tabs' => [] ], $g['display_rules']['hide']);
        $this->assertSame('Blog only', $g['display_rules']['description']);
        $ribbon = array_values(array_filter($g['forms'], static fn ($f) => 'ribbon' === $f['type']))[0];
        $this->assertFalse($ribbon['desktop']);
        $this->assertTrue($ribbon['mobile'], 'unset means shown, as Thrive Leads reads it');

        $this->assertSame('lead_group_not_found', $this->read('thriveleads-get-lead-group', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_list_forms_filters_by_group_and_type(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $this->form($a, 'slide_in');
        $this->form($a, 'lightbox');
        $this->form($b, 'lightbox');

        $all = $this->ok($this->read('thriveleads-list-forms'))['result'];
        $this->assertSame(3, $all['total']);
        $in_a = $this->ok($this->read('thriveleads-list-forms', [ 'group_id' => $a ]))['result'];
        $this->assertSame(2, $in_a['total']);
        $this->assertSame([ $a ], array_values(array_unique(array_column($in_a['forms'], 'group_id'))));
        $boxes = $this->ok($this->read('thriveleads-list-forms', [ 'type' => 'lightbox' ]))['result'];
        $this->assertSame(2, $boxes['total']);
    }

    public function test_get_form_returns_type_display_settings_and_variations_without_design_markup(): void
    {
        $group = $this->group();
        $form  = $this->form($group, 'slide_in', [ 'display_on_mobile' => '0' ]);
        $key   = $this->variation($form);
        $this->variation($form, [ 'post_title' => 'Old', 'post_status' => 'archived' ]);
        $this->summary('2026-11-10', $group, $form, $key, 20, 5);

        $f = $this->ok($this->read('thriveleads-get-form', [ 'id' => $form ]))['result']['form'];
        $this->assertSame('slide_in', $f['type']);
        $this->assertSame($group, $f['group_id']);
        $this->assertTrue($f['desktop']);
        $this->assertFalse($f['mobile']);
        $this->assertCount(1, $f['variations'], 'archived designs are left out');
        $v = $f['variations'][0];
        $this->assertSame($key, $v['key']);
        $this->assertSame('time', $v['trigger']);
        $this->assertSame([ 's' => 10, 'exi' => '' ], $v['trigger_config']);
        $this->assertSame('slide_right', $v['display_animation']);
        $this->assertSame('bot_right', $v['position']);
        $this->assertSame(0, $v['display_frequency']);
        $this->assertTrue($v['is_control']);
        $this->assertTrue($v['has_design']);
        $this->assertSame(20, $v['impressions']);
        $this->assertSame(5, $v['conversions']);
        $json = (string) wp_json_encode($f);
        $this->assertStringNotContainsString('mailchimp', $json, 'the design markup is not returned');
        $this->assertStringNotContainsString('tve_custom_css', $json);
        $this->assertContains('page_load', $f['allowed']['triggers']);
        $this->assertContains('bot_left', $f['allowed']['positions']);
    }

    public function test_list_shortcodes_returns_the_shortcode_to_paste(): void
    {
        $plain = self::factory()->post->create([ 'post_type' => 'tve_lead_shortcode', 'post_title' => 'Inline', 'post_status' => 'publish' ]);
        $lock  = self::factory()->post->create([ 'post_type' => 'tve_lead_shortcode', 'post_title' => 'Locker', 'post_status' => 'publish' ]);
        update_post_meta($lock, 'tve_content_locking', '1');
        $this->variation($plain);

        $list = array_column($this->ok($this->read('thriveleads-list-shortcodes'))['result']['shortcodes'], null, 'id');
        $this->assertSame("[thrive_leads id='" . $plain . "']", $list[ $plain ]['shortcode']);
        $this->assertFalse($list[ $plain ]['content_locking']);
        $this->assertSame(1, $list[ $plain ]['variations']);
        $this->assertSame("[thrive_lead_lock id='" . $lock . "']Hidden Content[/thrive_lead_lock]", $list[ $lock ]['shortcode']);
        $this->assertTrue($list[ $lock ]['content_locking']);
    }

    public function test_get_conversion_stats_sums_the_daily_summary_by_form_within_dates(): void
    {
        $group = $this->group();
        $a     = $this->form($group, 'slide_in');
        $b     = $this->form($group, 'lightbox');
        $this->summary('2026-11-01', $group, $a, 1, 100, 10);
        $this->summary('2026-11-02', $group, $a, 1, 100, 20);
        $this->summary('2026-11-02', $group, $b, 2, 50, 0);
        $this->summary('2026-10-01', $group, $b, 2, 999, 999);

        $out = $this->ok($this->read('thriveleads-get-conversion-stats', [ 'group_id' => $group, 'date_from' => '2026-11-01', 'date_to' => '2026-11-30' ]))['result'];
        $this->assertSame(250, $out['totals']['impressions']);
        $this->assertSame(30, $out['totals']['conversions']);
        $this->assertEquals(12.0, $out['totals']['conversion_rate']);
        $by = array_column($out['forms'], null, 'form_id');
        $this->assertSame(200, $by[ $a ]['impressions']);
        $this->assertEquals(15.0, $by[ $a ]['conversion_rate']);
        $this->assertSame('slide_in', $by[ $a ]['type']);
        $this->assertSame(0, $by[ $b ]['conversions']);

        $one = $this->ok($this->read('thriveleads-get-conversion-stats', [ 'form_id' => $b ]))['result'];
        $this->assertSame(1049, $one['totals']['impressions']);

        $this->assertSame('invalid_date', $this->read('thriveleads-get-conversion-stats', [ 'date_from' => 'yesterday' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_update_form_settings_changes_the_trigger_and_display_and_rolls_back_exactly(): void
    {
        $form   = $this->form($this->group(), 'slide_in');
        $key    = $this->variation($form);
        $before = $this->variation_row($key);

        $out = $this->ok($this->write('thriveleads-update-form-settings', [
            'id'                => $form,
            'trigger'           => 'scroll_percent',
            'trigger_config'    => [ 'p' => 50, 'exi' => true ],
            'display_frequency' => 7,
            'position'          => 'top_left',
            'display_animation' => 'zoom_in',
        ]));
        $this->assertTrue($out['recoverable']);
        $row = $this->variation_row($key);
        $this->assertSame('scroll_percent', $row['trigger']);
        $this->assertSame([ 'p' => 50, 'exi' => '1' ], unserialize($row['trigger_config']));
        $this->assertSame('7', (string) $row['display_frequency']);
        $this->assertSame('top_left', $row['position']);
        $this->assertSame('zoom_in', $row['display_animation']);
        $this->assertSame($before['content'], $row['content'], 'the design is untouched');
        $this->assertSame($before['tcb_fields'], $row['tcb_fields']);
        $this->assertSame('scroll_percent', $out['result']['variation']['trigger']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->variation_row($key));
    }

    public function test_update_form_settings_validates_against_the_form_type(): void
    {
        $form  = $this->form($this->group(), 'in_content');
        $key   = $this->variation($form, [ 'trigger' => 'page_load', 'trigger_config' => serialize([]), 'position' => '2' ]);
        $other = $this->variation($this->form($this->group('Other'), 'lightbox'));

        $this->assertSame('trigger_not_supported', $this->write('thriveleads-update-form-settings', [ 'id' => $form, 'trigger' => 'exit_intent' ])['error']['code'] ?? null);
        $this->assertSame('position_not_supported', $this->write('thriveleads-update-form-settings', [ 'id' => $form, 'position' => 'top_left' ])['error']['code'] ?? null);
        $this->assertSame('invalid_trigger_config', $this->write('thriveleads-update-form-settings', [ 'id' => $form, 'trigger' => 'viewport', 'trigger_config' => [ 's' => 3 ] ])['error']['code'] ?? null);
        $this->assertSame('variation_not_found', $this->write('thriveleads-update-form-settings', [ 'id' => $form, 'variation_key' => $other, 'position' => '3' ])['error']['code'] ?? null);
        $this->assertSame('form_not_found', $this->write('thriveleads-update-form-settings', [ 'id' => self::factory()->post->create(), 'position' => '3' ])['error']['code'] ?? null);
        $this->ok($this->write('thriveleads-update-form-settings', [ 'id' => $form, 'position' => '4' ]));
        $this->assertSame('4', $this->variation_row($key)['position']);
    }

    public function test_update_form_settings_needs_a_variation_key_when_the_form_has_several_designs(): void
    {
        $form = $this->form($this->group(), 'lightbox');
        $a    = $this->variation($form);
        $b    = $this->variation($form, [ 'post_title' => 'Design B' ]);

        $out = $this->write('thriveleads-update-form-settings', [ 'id' => $form, 'trigger' => 'page_load' ]);
        $this->assertSame('variation_required', $out['error']['code'] ?? null);
        $this->assertSame([ $a, $b ], $out['error']['data']['variation_keys'] ?? null);

        $this->ok($this->write('thriveleads-update-form-settings', [ 'id' => $form, 'variation_key' => $b, 'trigger' => 'page_load' ]));
        $this->assertSame('page_load', $this->variation_row($b)['trigger']);
        $this->assertSame([], unserialize($this->variation_row($b)['trigger_config']), 'a new trigger starts from its defaults');
        $this->assertSame('time', $this->variation_row($a)['trigger']);
    }

    public function test_toggle_form_sets_desktop_and_mobile_display_and_rolls_back(): void
    {
        $form = $this->form($this->group(), 'ribbon', [ 'display_status' => '1' ]);

        $out = $this->ok($this->write('thriveleads-toggle-form', [ 'id' => $form, 'desktop' => false, 'mobile' => false ]));
        $this->assertSame('0', get_post_meta($form, 'display_status', true));
        $this->assertSame('0', get_post_meta($form, 'display_on_mobile', true));
        $this->assertFalse($out['result']['form']['desktop']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('1', get_post_meta($form, 'display_status', true));
        $this->assertSame('', get_post_meta($form, 'display_on_mobile', true));

        $this->assertSame('toggle_required', $this->write('thriveleads-toggle-form', [ 'id' => $form ])['error']['code'] ?? null);
    }

    public function test_update_lead_group_display_rules_replaces_the_rules_and_rolls_back_exactly(): void
    {
        global $wpdb;
        $group = $this->group();
        $wpdb->insert($wpdb->prefix . 'tve_leads_group_options', [ 'group' => $group, 'description' => 'Blog only', 'show_group_options' => self::SHOW, 'hide_group_options' => '{"tabs":[]}' ]);
        $before = $this->rules($group);

        $show = [ 'tabs' => [ [ 'options' => [] ], [ 'options' => [ [ 'id' => 'post', 'type' => 'post_types', 'isChecked' => true ] ] ] ] ];
        $out  = $this->ok($this->write('thriveleads-update-lead-group-display-rules', [ 'id' => $group, 'show' => $show ]));
        $this->assertTrue($out['recoverable']);
        $rows = $this->rules($group);
        $this->assertCount(1, $rows);
        $this->assertSame($show, json_decode($rows[0]['show_group_options'], true));
        $this->assertSame('{"tabs":[]}', $rows[0]['hide_group_options'], 'hide is kept when not given');
        $this->assertSame('Blog only', $rows[0]['description']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->rules($group));
    }

    public function test_display_rules_on_a_group_without_rules_are_removed_again_by_the_undo(): void
    {
        $group = $this->group();
        $out   = $this->ok($this->write('thriveleads-update-lead-group-display-rules', [ 'id' => $group, 'show' => [ 'tabs' => [] ], 'hide' => [ 'tabs' => [] ] ]));
        $this->assertCount(1, $this->rules($group));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([], $this->rules($group));

        $this->assertSame('invalid_display_rules', $this->write('thriveleads-update-lead-group-display-rules', [ 'id' => $group, 'show' => [ 'tabs' => 'all' ] ])['error']['code'] ?? null);
        $this->assertSame('lead_group_not_found', $this->write('thriveleads-update-lead-group-display-rules', [ 'id' => self::factory()->post->create(), 'show' => [ 'tabs' => [] ] ])['error']['code'] ?? null);
    }
}
