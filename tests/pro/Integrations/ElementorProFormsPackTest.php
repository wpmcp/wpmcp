<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Elementor_Pro_Forms_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/elementor-pro-stubs.php';

/**
 * Elementor Pro form submissions (issue #654): paid-tier ops on the
 * plugin-data dispatcher pair, so they add no top-level tools.
 *
 * Elementor Pro is not loaded in the shared test core. Presence is driven
 * through wpmcp_elementor_pro_active, and the storage is built and seeded the
 * way Elementor Pro 4.3.3 keeps it (tests/support/elementor-pro-stubs.php):
 * {prefix}e_submissions, {prefix}e_submissions_values and
 * {prefix}e_submissions_actions_log, plus the __elementor_forms_snapshot post
 * meta and the form widget inside _elementor_data.
 *
 * Every write is off until the site opts in (wpmcp_enable_elementor_pro_write)
 * and is undone in a test: a status change is a db_rows before-image of the
 * row, and a delete captures every row of the three tables in one session.
 */
class ElementorProFormsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'elementor-list-forms'               => 'read',
        'elementor-list-submissions'         => 'read',
        'elementor-get-submission'           => 'read',
        'elementor-export-submissions'       => 'read',
        'elementor-update-submission-status' => 'write',
        'elementor-delete-submission'        => 'destructive',
    ];

    private const FIELDS = [
        [ 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Your name' ],
        [ 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Your email', 'required' => 'true' ],
        [ 'custom_id' => 'message', 'field_type' => 'textarea', 'field_label' => 'Message' ],
    ];

    private int $page;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_elementor_pro_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_elementor_pro_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_elementor_pro_active', '__return_true');
        add_filter('wpmcp_enable_elementor_pro_write', '__return_true');
        $this->page = wpmcp_test_elementor_form_page('a1b2c3d', [ 'form_name' => 'Contact form', 'form_fields' => self::FIELDS ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_elementor_pro_active');
        remove_all_filters('wpmcp_enable_elementor_pro_write');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function submit(string $email, array $row = []): int
    {
        return wpmcp_test_elementor_submission($this->page, 'a1b2c3d', [
            [ 'name', 'text', 'Ada' ],
            [ 'email', 'email', $email ],
            [ 'message', 'textarea', 'Hello there' ],
        ], $row);
    }

    /** @return array<string, int> table => row count for one submission. */
    private function rows(int $id): array
    {
        global $wpdb;
        return [
            'submissions' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id = %d', $wpdb->prefix . 'e_submissions', $id)),
            'values'      => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE submission_id = %d', $wpdb->prefix . 'e_submissions_values', $id)),
            'actions_log' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE submission_id = %d', $wpdb->prefix . 'e_submissions_actions_log', $id)),
        ];
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertTrue($ops['elementor-delete-submission']['requires_confirm']);
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
        $this->assertFalse(Elementor_Pro_Forms_Pack::active());
    }

    public function test_presence_defaults_to_the_elementor_pro_version_constant(): void
    {
        remove_all_filters('wpmcp_elementor_pro_active');
        $this->assertSame(defined('ELEMENTOR_PRO_VERSION'), Elementor_Pro_Forms_Pack::active());
    }

    public function test_alone_it_makes_the_plugin_data_pair_available(): void
    {
        foreach ([ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_memberpress_active' ] as $filter) {
            add_filter($filter, '__return_false');
        }
        $available = (new Plugin_Data_Integration())->is_available();
        foreach ([ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_memberpress_active' ] as $filter) {
            remove_all_filters($filter);
        }
        $this->assertTrue($available);
    }

    public function test_while_elementor_pro_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_elementor_pro_active');
        add_filter('wpmcp_elementor_pro_active', '__return_false');
        $out = $this->read('elementor-list-submissions');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Elementor Pro', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $this->assertSame('elementor-list-submissions', $out['error']['data']['operation'] ?? null);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['elementor-list-forms']['dependency_met']);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_elementor_pro_active');
        add_filter('wpmcp_elementor_pro_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/elementor-pro';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/elementor-pro.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: Elementor Pro\n");
        }
        try {
            $out = $this->read('elementor-get-submission', [ 'id' => 1 ]);
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->submit('ada@example.org');
        remove_all_filters('wpmcp_enable_elementor_pro_write');
        $out = $this->write('elementor-update-submission-status', [ 'id' => $id, 'status' => 'trash' ]);
        $this->assertSame('operation_disabled', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_elementor_pro_write', $out['error']['message']);
        $out = $this->write('elementor-delete-submission', [ 'id' => $id ], true);
        $this->assertSame('operation_disabled', $out['error']['code'] ?? null);
        $this->assertSame(1, $this->rows($id)['submissions']);
    }

    public function test_every_op_takes_manage_options(): void
    {
        $id = $this->submit('ada@example.org');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'elementor-list-forms' => [], 'elementor-list-submissions' => [], 'elementor-get-submission' => [ 'id' => $id ], 'elementor-export-submissions' => [ 'form' => $this->page . '_a1b2c3d' ] ] as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('elementor-update-submission-status', [ 'id' => $id, 'is_read' => true ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_forms_finds_the_widget_with_its_fields_and_counts(): void
    {
        $this->submit('ada@example.org');
        $this->submit('bob@example.org', [ 'is_read' => 1 ]);
        $this->submit('eve@example.org', [ 'status' => 'trash' ]);
        $defaults = wpmcp_test_elementor_form_page('f00d001', [], false);

        $out   = $this->ok($this->read('elementor-list-forms'))['result'];
        $forms = array_column($out['forms'], null, 'form');

        $form = $forms[ $this->page . '_a1b2c3d' ];
        $this->assertSame('widget', $form['kind']);
        $this->assertSame('Contact form', $form['name']);
        $this->assertSame([ 'name', 'email', 'message' ], array_column($form['fields'], 'id'));
        $this->assertTrue($form['fields'][1]['required']);
        $this->assertTrue($form['saves_submissions']);
        $this->assertTrue($form['in_page']);
        $this->assertSame([ 'total' => 2, 'unread' => 1, 'trash' => 1 ], $form['submissions']);
        $this->assertSame($this->page, $form['page']['id']);

        // A widget left at its defaults stores no settings: Elementor's defaults apply.
        $plain = $forms[ $defaults . '_f00d001' ];
        $this->assertSame('New Form', $plain['name']);
        $this->assertSame([ 'name', 'email', 'message' ], array_column($plain['fields'], 'id'));
        $this->assertSame([ 'total' => 0, 'unread' => 0, 'trash' => 0 ], $plain['submissions']);

        $one = $this->ok($this->read('elementor-list-forms', [ 'post_id' => $defaults ]))['result'];
        $this->assertSame([ $defaults . '_f00d001' ], array_column($one['forms'], 'form'));
    }

    public function test_list_forms_reports_a_removed_form_that_still_has_submissions(): void
    {
        $this->submit('ada@example.org');
        update_post_meta($this->page, '_elementor_data', wp_slash(wp_json_encode([])));
        $forms = array_column($this->ok($this->read('elementor-list-forms', [ 'post_id' => $this->page ]))['result']['forms'], null, 'form');
        $this->assertFalse($forms[ $this->page . '_a1b2c3d' ]['in_page']);
        $this->assertSame('Your email', $forms[ $this->page . '_a1b2c3d' ]['fields'][1]['label']);
        $this->assertSame(1, $forms[ $this->page . '_a1b2c3d' ]['submissions']['total']);
    }

    public function test_list_submissions_filters_like_the_submissions_screen(): void
    {
        $a = $this->submit('ada@example.org', [ 'created_at_gmt' => '2026-01-05 10:00:00' ]);
        $b = $this->submit('bob@example.org', [ 'created_at_gmt' => '2026-02-05 10:00:00', 'is_read' => 1 ]);
        $c = $this->submit('eve@example.org', [ 'created_at_gmt' => '2026-03-05 10:00:00', 'status' => 'trash' ]);

        $all = $this->ok($this->read('elementor-list-submissions'))['result'];
        $this->assertSame([ $b, $a ], array_column($all['submissions'], 'id'), 'newest first, trash left out');
        $this->assertSame([ 'all' => 2, 'unread' => 1, 'read' => 1, 'trash' => 1 ], $all['counts']);
        $this->assertSame([ 'key' => 'email', 'value' => 'bob@example.org' ], $all['submissions'][0]['main']);
        $this->assertSame($this->page . '_a1b2c3d', $all['submissions'][0]['form']);

        $ids = fn (array $args): array => array_column($this->ok($this->read('elementor-list-submissions', $args))['result']['submissions'], 'id');
        $this->assertSame([ $a ], $ids([ 'status' => 'unread' ]));
        $this->assertSame([ $b ], $ids([ 'status' => 'read' ]));
        $this->assertSame([ $c ], $ids([ 'status' => 'trash' ]));
        $this->assertSame([ $b ], $ids([ 'search' => 'bob@' ]));
        $this->assertSame([ $a ], $ids([ 'before' => '2026-01-31' ]));
        $this->assertSame([ $b ], $ids([ 'after' => '2026-02-01', 'before' => '2026-02-28' ]));
        $this->assertSame([], $ids([ 'form' => $this->page . '_zzz9999' ]));
        $this->assertSame([ $b ], $ids([ 'per_page' => 1 ]));
        $this->assertSame([ $a ], $ids([ 'per_page' => 1, 'page' => 2 ]));
    }

    public function test_get_submission_returns_values_with_labels_and_the_action_log(): void
    {
        $id  = $this->submit('ada@example.org');
        $out = $this->ok($this->read('elementor-get-submission', [ 'id' => $id ]))['result']['submission'];
        $this->assertSame($id, $out['id']);
        $this->assertSame('Contact form', $out['form_name']);
        $this->assertSame([ 'name', 'email', 'message' ], array_column($out['values'], 'key'));
        $this->assertSame('Your email', $out['values'][1]['label']);
        $this->assertSame('email', $out['values'][1]['type']);
        $this->assertSame('ada@example.org', $out['values'][1]['value']);
        $this->assertSame([ 'key' => 'email', 'value' => 'ada@example.org' ], $out['main']);
        $this->assertSame('email', $out['actions'][0]['name']);
        $this->assertSame('success', $out['actions'][0]['status']);
        $this->assertSame('203.0.113.7', $out['user_ip']);

        $missing = $this->read('elementor-get-submission', [ 'id' => 999999 ]);
        $this->assertSame('submission_not_found', $missing['error']['code'] ?? null);
    }

    public function test_export_gives_csv_with_labelled_columns_and_neutralized_formulas(): void
    {
        $this->submit('ada@example.org');
        wpmcp_test_elementor_submission($this->page, 'a1b2c3d', [ [ 'name', 'text', '=HYPERLINK("x")' ], [ 'email', 'email', 'eve@example.org' ] ]);

        $out = $this->ok($this->read('elementor-export-submissions', [ 'form' => $this->page . '_a1b2c3d' ]))['result'];
        $this->assertSame(2, $out['count']);
        $this->assertNull($out['next_offset']);
        $this->assertSame([ 'Your name', 'Your email', 'Message', 'Form Name (ID)', 'Submission ID', 'Created At', 'User ID', 'User Agent', 'User IP', 'Referrer' ], $out['columns']);
        $lines = explode("\r\n", trim($out['csv']));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('"Your name","Your email","Message"', $lines[0]);
        $this->assertStringContainsString('"\'=HYPERLINK(""x"")"', $out['csv']);
        $this->assertStringContainsString('"ada@example.org"', $out['csv']);
        $this->assertStringContainsString('"Contact form (a1b2c3d)"', $out['csv']);

        $json = $this->ok($this->read('elementor-export-submissions', [ 'form' => $this->page . '_a1b2c3d', 'format' => 'json', 'limit' => 1 ]))['result'];
        $this->assertSame(1, $json['next_offset']);
        $this->assertSame('=HYPERLINK("x")', $json['rows'][0]['fields']['name'], 'JSON keeps the raw value');
    }

    // ---------------------------------------------------------------
    // Writes, each undone
    // ---------------------------------------------------------------

    public function test_update_status_trashes_and_marks_read_and_rolls_back(): void
    {
        global $wpdb;
        $id     = $this->submit('ada@example.org', [ 'created_at_gmt' => '2026-01-05 10:00:00' ]);
        $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'e_submissions', $id), ARRAY_A);

        $out = $this->ok($this->write('elementor-update-submission-status', [ 'id' => $id, 'status' => 'trash', 'is_read' => true ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('trash', $out['result']['submission']['status']);
        $this->assertTrue($out['result']['submission']['is_read']);
        $this->assertNotSame('2026-01-05 10:00:00', $out['result']['submission']['updated_at_gmt'], 'updated_at moves like Query::update_submission()');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'e_submissions', $id), ARRAY_A));
    }

    public function test_update_status_refuses_a_missing_submission_and_an_empty_change(): void
    {
        $this->assertSame('submission_not_found', $this->write('elementor-update-submission-status', [ 'id' => 999999, 'status' => 'new' ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('elementor-update-submission-status', [ 'id' => $this->submit('a@example.org') ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('elementor-update-submission-status', [ 'id' => 1, 'status' => 'spam' ])['error']['code'] ?? null);
    }

    public function test_delete_asks_for_confirmation_with_a_preview_and_touches_nothing(): void
    {
        $id  = $this->submit('ada@example.org');
        $out = $this->write('elementor-delete-submission', [ 'id' => $id ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('3 field values and 1 action log rows', $out['error']['message']);
        $this->assertSame([ 'submissions' => 1, 'values' => 3, 'actions_log' => 1 ], $this->rows($id));
    }

    public function test_delete_removes_every_row_and_rollback_session_puts_them_back_with_their_ids(): void
    {
        global $wpdb;
        $id    = $this->submit('ada@example.org');
        $other = $this->submit('bob@example.org');
        $dump  = static fn (string $t, string $col, int $sid): array => $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i = %d ORDER BY id', $wpdb->prefix . $t, $col, $sid), ARRAY_A);
        $before = [ $dump('e_submissions', 'id', $id), $dump('e_submissions_values', 'submission_id', $id), $dump('e_submissions_actions_log', 'submission_id', $id) ];

        $out = $this->ok($this->write('elementor-delete-submission', [ 'id' => $id ], true));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(3, $out['operation_ids']);
        $this->assertSame($id, $out['result']['deleted']['id']);
        $this->assertSame([ 'submissions' => 0, 'values' => 0, 'actions_log' => 0 ], $this->rows($id));
        $this->assertSame([ 'submissions' => 1, 'values' => 3, 'actions_log' => 1 ], $this->rows($other), 'another submission is untouched');

        $this->assertSame(3, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame($before, [ $dump('e_submissions', 'id', $id), $dump('e_submissions_values', 'submission_id', $id), $dump('e_submissions_actions_log', 'submission_id', $id) ]);
        $this->assertSame('ada@example.org', $this->ok($this->read('elementor-get-submission', [ 'id' => $id ]))['result']['submission']['main']['value']);
    }
}
