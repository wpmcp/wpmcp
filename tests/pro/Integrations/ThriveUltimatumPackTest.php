<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Thrive_Ultimatum_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/thrive-ultimatum-stubs.php';

/**
 * Thrive Ultimatum countdown campaigns (issue #828): paid-tier ops on the
 * plugin-data dispatcher pair, so they add no top-level tools.
 *
 * Thrive Ultimatum is not loaded in the shared test core. Presence is driven
 * through wpmcp_thrive_ultimatum_active and the storage is seeded the way
 * the plugin keeps it (tests/support/thrive-ultimatum-stubs.php).
 *
 * Every write is off until the site opts in
 * (wpmcp_enable_thrive_ultimatum_write) and is undoable: campaign metas are
 * a post snapshot, the timeline and the display settings are
 * plugin_table_rows images keyed by the campaign, and a created campaign is
 * trashed again. The per-visitor evergreen table is never read.
 */
class ThriveUltimatumPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'ultimatum-list-campaigns'          => 'read',
        'ultimatum-get-campaign'            => 'read',
        'ultimatum-create-campaign'         => 'write',
        'ultimatum-update-campaign-schedule' => 'write',
        'ultimatum-update-display-rules'    => 'write',
        'ultimatum-pause-campaign'          => 'write',
        'ultimatum-resume-campaign'         => 'write',
    ];

    private const ABSOLUTE = [
        'start'   => [ 'date' => '1 January 2030', 'time' => '00:00' ],
        'end'     => [ 'date' => '4 January 2030', 'time' => '06:00' ],
        'trigger' => [ 'type' => '', 'ids' => '' ],
    ];

    private const SHOW_FRONT_PAGE = '{"tabs":[{"options":["front_page"]},{"options":[]},{"options":[]},{"options":[]},{"options":[]},{"options":[]},{"options":[]},{"options":[]}]}';

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_ultimatum_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_ultimatum_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_ultimatum_type();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_thrive_ultimatum_active', '__return_true');
        add_filter('wpmcp_enable_thrive_ultimatum_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_thrive_ultimatum_active');
        remove_all_filters('wpmcp_enable_thrive_ultimatum_write');
        wpmcp_test_unregister_ultimatum_type();
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

    private function events(int $campaign): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT type, days, hours FROM %i WHERE campaign_id = %d ORDER BY id', $wpdb->prefix . 'tve_ult_events', $campaign), ARRAY_A);
    }

    private function display_row(int $campaign): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT show_options, hide_options FROM %i WHERE campaign_id = %d', $wpdb->prefix . 'tve_ult_settings_campaign', $campaign), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** A campaign that passes every check the plugin makes before it runs one. */
    private function ready_campaign(string $title = 'Launch'): int
    {
        $id = wpmcp_test_ultimatum_campaign($title, [
            'tve_ult_campaign_type'     => 'absolute',
            'tve_ult_campaign_settings' => self::ABSOLUTE,
        ]);
        wpmcp_test_ultimatum_event($id, 'start', 3, 6);
        wpmcp_test_ultimatum_design($id, 'header-bar', 'Bar', [ 'tpl' => 'ribbon|set_01' ]);
        wpmcp_test_ultimatum_display($id, self::SHOW_FRONT_PAGE);
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_ultimatum_op_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_ultimatum_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse(Thrive_Ultimatum_Pack::active());
    }

    public function test_while_the_plugin_is_missing_its_ops_answer_plugin_missing_with_the_next_step(): void
    {
        remove_all_filters('wpmcp_thrive_ultimatum_active');
        add_filter('wpmcp_thrive_ultimatum_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $out = $this->read('ultimatum-list-campaigns');
        remove_all_filters('wpmcp_buddypress_active');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('Install and activate Thrive Ultimatum', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constants_class(): void
    {
        remove_all_filters('wpmcp_thrive_ultimatum_active');
        $this->assertSame(class_exists('TVE_Ult_Const', false), Thrive_Ultimatum_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_thrive_ultimatum_write');
        $out = $this->write('ultimatum-create-campaign', [ 'title' => 'Sale' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_thrive_ultimatum_write', $out['error']['message']);
        $this->assertSame([], get_posts([ 'post_type' => 'tve_ult_campaign', 'post_status' => 'any' ]));
    }

    public function test_an_editor_is_refused_every_op(): void
    {
        $id = $this->ready_campaign();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('ultimatum-list-campaigns')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('ultimatum-get-campaign', [ 'campaign_id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('ultimatum-pause-campaign', [ 'campaign_id' => $id ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_campaigns_returns_type_status_and_counts_in_the_plugin_order(): void
    {
        global $wpdb;
        $b = wpmcp_test_ultimatum_campaign('Black Friday', [ 'tve_ult_campaign_type' => 'absolute', 'tve_ult_status' => 'running', 'tve_campaign_order' => 2 ]);
        $a = wpmcp_test_ultimatum_campaign('Welcome', [ 'tve_ult_campaign_type' => 'evergreen', 'tve_campaign_order' => 1 ]);
        self::factory()->post->create([ 'post_title' => 'Not a campaign' ]);
        wpmcp_test_ultimatum_design($b, 'header-bar', 'Bar', [ 'tpl' => 'ribbon|set_01' ]);
        foreach ([ 1, 1, 1, 2 ] as $type) {
            $wpdb->insert($wpdb->prefix . 'tve_ult_event_log', [ 'campaign_id' => $b, 'date' => '2026-11-01 10:00:00', 'type' => $type ]);
        }

        $out = $this->ok($this->read('ultimatum-list-campaigns'))['result'];
        $this->assertSame([ $a, $b ], array_column($out['campaigns'], 'id'));
        $bf = $out['campaigns'][1];
        $this->assertSame('Black Friday', $bf['title']);
        $this->assertSame('absolute', $bf['type']);
        $this->assertSame('running', $bf['status']);
        $this->assertSame(1, $bf['designs']);
        $this->assertSame(3, $bf['impressions']);
        $this->assertSame(1, $bf['conversions']);

        $running = $this->ok($this->read('ultimatum-list-campaigns', [ 'status' => 'running' ]))['result'];
        $this->assertSame([ $b ], array_column($running['campaigns'], 'id'));
        $evergreen = $this->ok($this->read('ultimatum-list-campaigns', [ 'type' => 'evergreen' ]))['result'];
        $this->assertSame([ $a ], array_column($evergreen['campaigns'], 'id'));
    }

    public function test_get_campaign_reads_schedule_display_rules_designs_and_timeline_but_no_visitor_data(): void
    {
        global $wpdb;
        $id     = $this->ready_campaign();
        $design = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE post_parent = %d', $wpdb->prefix . 'tve_ult_designs', $id));
        wpmcp_test_ultimatum_design($id, 'header-bar', 'Last day', [], $design);
        wpmcp_test_ultimatum_event($id, 'time', 1, 0, [ [ 'key' => 'design', 'design' => $design ] ]);
        $wpdb->insert($wpdb->prefix . 'tve_ult_emails', [ 'campaign_id' => $id, 'email' => md5('visitor@example.com'), 'started' => '2026-11-01 10:00:00' ]);

        $out = $this->ok($this->read('ultimatum-get-campaign', [ 'campaign_id' => $id ]))['result']['campaign'];
        $this->assertSame('absolute', $out['type']);
        $this->assertSame('paused', $out['status']);
        $this->assertSame('4 January 2030', $out['schedule']['end']['date']);
        $this->assertSame([ 'front_page' ], $out['display_rules']['show']['other_screens']);
        $this->assertSame([], $out['display_rules']['hide']['pages']);
        $this->assertCount(1, $out['designs']);
        $this->assertSame('ribbon|set_01', $out['designs'][0]['template']);
        $this->assertSame('header-bar', $out['designs'][0]['type']);
        $this->assertSame(1, $out['designs'][0]['states']);
        $this->assertSame([ 'start', 'time' ], array_column($out['timeline'], 'type'));
        $this->assertSame(3, $out['timeline'][0]['days']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString(md5('visitor@example.com'), $json);
        $this->assertStringNotContainsString('email', $json);
    }

    public function test_get_campaign_refuses_a_post_that_is_not_a_campaign(): void
    {
        $post = self::factory()->post->create();
        $out  = $this->read('ultimatum-get-campaign', [ 'campaign_id' => $post ]);
        $this->assertSame('campaign_not_found', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_campaign_stores_a_paused_campaign_with_its_start_event_and_rollback_session_removes_it(): void
    {
        wpmcp_test_ultimatum_campaign('Older', [ 'tve_campaign_order' => 4 ]);
        $out = $this->ok($this->write('ultimatum-create-campaign', [ 'title' => 'Spring sale', 'type' => 'absolute', 'schedule' => self::ABSOLUTE ]));
        $this->assertTrue($out['recoverable']);
        $id = (int) $out['result']['campaign']['id'];

        $this->assertSame('tve_ult_campaign', get_post_type($id));
        $this->assertSame('publish', get_post_status($id), 'the plugin keeps every campaign published');
        $this->assertSame('paused', get_post_meta($id, 'tve_ult_status', true));
        $this->assertSame('absolute', get_post_meta($id, 'tve_ult_campaign_type', true));
        $this->assertSame('5', (string) get_post_meta($id, 'tve_campaign_order', true), 'listed after the existing campaigns');
        $this->assertSame('4 January 2030', get_post_meta($id, 'tve_ult_campaign_settings', true)['end']['date']);
        $this->assertSame([ [ 'type' => 'start', 'days' => '3', 'hours' => '6' ] ], $this->events($id));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame([], $this->events($id));
    }

    public function test_create_campaign_without_a_type_writes_no_timeline(): void
    {
        $out = $this->ok($this->write('ultimatum-create-campaign', [ 'title' => 'Draft idea' ]));
        $id  = (int) $out['result']['campaign']['id'];
        $this->assertSame('', get_post_meta($id, 'tve_ult_campaign_type', true));
        $this->assertSame([], $this->events($id));
    }

    public function test_update_schedule_rewrites_the_start_event_trims_the_timeline_and_rolls_back(): void
    {
        $id = $this->ready_campaign();
        wpmcp_test_ultimatum_event($id, 'time', 1, 0);
        wpmcp_test_ultimatum_event($id, 'time', 3, 0);

        $out = $this->ok($this->write('ultimatum-update-campaign-schedule', [
            'campaign_id' => $id,
            'schedule'    => [ 'end' => [ 'date' => '2 January 2030', 'time' => '12:00' ] ],
        ]));
        $this->assertTrue($out['recoverable']);
        $settings = get_post_meta($id, 'tve_ult_campaign_settings', true);
        $this->assertSame('2 January 2030', $settings['end']['date']);
        $this->assertSame('1 January 2030', $settings['start']['date'], 'keys not given keep their value');
        $this->assertSame(
            [ [ 'type' => 'start', 'days' => '1', 'hours' => '12' ], [ 'type' => 'time', 'days' => '1', 'hours' => '0' ] ],
            $this->events($id),
            'the start event takes the new length and time events past it go, as the plugin does'
        );

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('4 January 2030', get_post_meta($id, 'tve_ult_campaign_settings', true)['end']['date']);
        $this->assertSame([ 'start', 'time', 'time' ], array_column($this->events($id), 'type'));
    }

    public function test_update_schedule_switches_to_an_evergreen_campaign(): void
    {
        $id  = $this->ready_campaign();
        $out = $this->ok($this->write('ultimatum-update-campaign-schedule', [
            'campaign_id' => $id,
            'type'        => 'evergreen',
            'schedule'    => [ 'duration' => 7, 'end_after' => 30, 'trigger' => [ 'type' => 'first' ] ],
        ]));
        $this->assertSame('evergreen', get_post_meta($id, 'tve_ult_campaign_type', true));
        $settings = get_post_meta($id, 'tve_ult_campaign_settings', true);
        $this->assertSame('7', $settings['duration']);
        $this->assertSame('30', $settings['end']);
        $this->assertSame('first', $settings['trigger']['type']);
        $this->assertSame([ [ 'type' => 'start', 'days' => '7', 'hours' => '0' ] ], $this->events($id));
        $this->assertSame('evergreen', $out['result']['campaign']['type']);
    }

    public function test_update_schedule_refuses_an_end_before_the_start_before_any_snapshot(): void
    {
        $id     = $this->ready_campaign();
        $before = Snapshot_Store::row_count();
        $out    = $this->write('ultimatum-update-campaign-schedule', [
            'campaign_id' => $id,
            'schedule'    => [ 'end' => [ 'date' => '1 December 2029', 'time' => '00:00' ] ],
        ]);
        $this->assertSame('invalid_schedule', $out['error']['code'] ?? null);
        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame('4 January 2030', get_post_meta($id, 'tve_ult_campaign_settings', true)['end']['date']);
    }

    public function test_update_display_rules_replaces_one_side_and_rollback_operation_restores_the_row(): void
    {
        $id  = $this->ready_campaign();
        $out = $this->ok($this->write('ultimatum-update-display-rules', [
            'campaign_id' => $id,
            'show'        => [ 'other_screens' => [ 'all_post' ], 'pages' => [ '42' ], 'others' => [ 'logged_in', 'https://example.com/sale/' ] ],
        ]));
        $this->assertTrue($out['recoverable']);
        $rules = $out['result']['display_rules'];
        $this->assertSame([ 'all_post' ], $rules['show']['other_screens']);
        $this->assertSame([ '42' ], $rules['show']['pages']);
        $this->assertSame([ 'logged_in', 'https://example.com/sale/' ], $rules['show']['others']);
        $row  = $this->display_row($id);
        $tabs = json_decode(stripcslashes((string) $row['show_options']), true)['tabs'];
        $this->assertCount(8, $tabs, 'one entry per tab of the display settings screen');
        $this->assertSame([ 'https://example.com/sale/' ], array_values(array_diff($tabs[7]['options'], [ 'logged_in' ])), 'readable by the plugin after its stripcslashes()');
        $this->assertSame('{"tabs":[]}', $row['hide_options'], 'the side not given is kept');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame(self::SHOW_FRONT_PAGE, $this->display_row($id)['show_options']);
    }

    public function test_update_display_rules_on_a_campaign_without_settings_is_undone_by_removing_the_row(): void
    {
        $id  = wpmcp_test_ultimatum_campaign('Bare');
        $out = $this->ok($this->write('ultimatum-update-display-rules', [ 'campaign_id' => $id, 'hide' => [ 'others' => [ 'logged_out' ] ] ]));
        $this->assertNotNull($this->display_row($id));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull($this->display_row($id));
    }

    public function test_update_display_rules_refuses_a_value_the_plugin_could_not_read_back(): void
    {
        $id  = $this->ready_campaign();
        $out = $this->write('ultimatum-update-display-rules', [ 'campaign_id' => $id, 'show' => [ 'others' => [ 'https://example.com/?q="x"' ] ] ]);
        $this->assertSame('invalid_display_rule', $out['error']['code'] ?? null);
        $this->assertSame(self::SHOW_FRONT_PAGE, $this->display_row($id)['show_options']);
    }

    public function test_pause_and_resume_change_the_status_and_roll_back(): void
    {
        $id = $this->ready_campaign();
        update_post_meta($id, 'tve_ult_status', 'running');

        $out = $this->ok($this->write('ultimatum-pause-campaign', [ 'campaign_id' => $id ]));
        $this->assertSame('paused', get_post_meta($id, 'tve_ult_status', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('running', get_post_meta($id, 'tve_ult_status', true));

        update_post_meta($id, 'tve_ult_status', 'paused');
        $out = $this->ok($this->write('ultimatum-resume-campaign', [ 'campaign_id' => $id ]));
        $this->assertSame('running', get_post_meta($id, 'tve_ult_status', true));
        $this->assertSame('running', $out['result']['campaign']['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('paused', get_post_meta($id, 'tve_ult_status', true));
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public function not_ready_cases(): array
    {
        global $wpdb;
        return [
            'no type'           => [ static fn (int $id) => delete_post_meta($id, 'tve_ult_campaign_type'), 'settings_error' ],
            'ended'             => [ static fn (int $id) => update_post_meta($id, 'tve_ult_campaign_settings', [ 'start' => [ 'date' => '1 January 2020', 'time' => '00:00' ], 'end' => [ 'date' => '2 January 2020', 'time' => '00:00' ], 'trigger' => [ 'type' => '' ] ]), 'end_date_in_past' ],
            'no display rules'  => [ static fn (int $id) => $wpdb->delete($wpdb->prefix . 'tve_ult_settings_campaign', [ 'campaign_id' => $id ]), 'display_settings_error' ],
            'no design'         => [ static fn (int $id) => $wpdb->delete($wpdb->prefix . 'tve_ult_designs', [ 'post_parent' => $id ]), 'design_error' ],
            'no template'       => [ static fn (int $id) => $wpdb->update($wpdb->prefix . 'tve_ult_designs', [ 'tcb_fields' => serialize([]) ], [ 'post_parent' => $id ]), 'design_tpl_error' ],
        ];
    }

    /** @dataProvider not_ready_cases */
    public function test_resume_refuses_what_the_plugin_refuses_before_any_snapshot(callable $break, string $reason): void
    {
        $id = $this->ready_campaign();
        $break($id);
        $before = Snapshot_Store::row_count();
        $out = $this->write('ultimatum-resume-campaign', [ 'campaign_id' => $id ]);
        $this->assertSame('campaign_not_ready', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame($reason, $out['error']['data']['reason'] ?? null);
        $this->assertSame('paused', get_post_meta($id, 'tve_ult_status', true));
        $this->assertSame($before, Snapshot_Store::row_count());
    }
}
