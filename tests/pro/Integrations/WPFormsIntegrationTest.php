<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\WPForms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * The WPForms dispatcher (forms adapter pack, pro tier), exercised against
 * doubles of wpforms()->form and of the WPForms entry handler over a real
 * wpforms_entries table (tests/support/forms-stubs.php). Live WPForms stays
 * production-verified.
 */
class WPFormsIntegrationTest extends \WP_UnitTestCase
{
    private WPForms_Integration $integration;
    private int $entry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        \WPMCP_WPForms_Entry_Stub::install(); // DDL, outside any test transaction
    }

    public static function tear_down_after_class(): void
    {
        \WPMCP_WPForms_Entry_Stub::uninstall();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \WPMCP_WPForms_Stub::$lite = false;
        wpforms()->form->forms = [
            3 => new \WP_Post((object) [
                'ID'           => 3,
                'post_title'   => 'Newsletter',
                'post_type'    => 'wpforms',
                'post_content' => wp_json_encode([
                    'fields'   => [
                        [ 'id' => 0, 'type' => 'email', 'label' => 'Email', 'required' => '1' ],
                        [ 'id' => 1, 'type' => 'radio', 'label' => 'Plan', 'choices' => [ 1 => [ 'label' => 'Free' ], 2 => [ 'label' => 'Paid' ] ] ],
                    ],
                    'settings' => [
                        'notification_enable' => '1',
                        'notifications'       => [
                            1 => [ 'notification_name' => 'Default Notification', 'email' => '{admin_email}', 'subject' => 'New Entry', 'message' => '{all_fields}' ],
                            2 => [ 'notification_name' => 'Off', 'enable' => '0' ],
                        ],
                    ],
                ]),
            ]),
        ];
        $this->entry = \WPMCP_WPForms_Entry_Stub::seed([ 'form_id' => 3, 'fields' => [ [ 'name' => 'Email', 'value' => 'ada@example.test' ] ], 'ip_address' => '203.0.113.5' ]);
        \WPMCP_WPForms_Entry_Stub::seed([ 'form_id' => 3, 'status' => 'spam' ]);
        $this->integration = new WPForms_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        \WPMCP_WPForms_Stub::$lite = false;
        parent::tearDown();
    }

    public function test_lists_and_reads_forms(): void
    {
        $this->assertTrue($this->integration->is_available());
        $this->assertSame('pro', $this->integration->tier());

        $forms = $this->integration->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $forms['result']['total']);
        $this->assertSame('Newsletter', $forms['result']['forms'][0]['title']);

        $form = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 3 ] ]);
        $this->assertSame('Newsletter', $form['result']['form']['title']);
        $this->assertSame('email', $form['result']['form']['fields'][0]['type']);
    }

    public function test_list_fields_and_notifications(): void
    {
        $fields = $this->integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 3 ] ])['result']['fields'];
        $this->assertTrue($fields[0]['required']);
        $this->assertSame([ 'Free', 'Paid' ], $fields[1]['choices']);

        $out = $this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => 3 ] ])['result'];
        $this->assertTrue($out['notifications_enabled']);
        $this->assertSame('Default Notification', $out['notifications'][0]['name']);
        $this->assertTrue($out['notifications'][0]['enabled']);
        $this->assertFalse($out['notifications'][1]['enabled']);
    }

    public function test_entries_list_filter_and_read(): void
    {
        $all = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 3 ] ])['result'];
        $this->assertSame(2, $all['total']);

        $spam = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 3, 'status' => 'spam' ] ])['result'];
        $this->assertSame(1, $spam['total']);
        $this->assertSame('spam', $spam['entries'][0]['status']);

        $entry = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => $this->entry ] ])['result']['entry'];
        $this->assertSame('active', $entry['status']);
        $this->assertSame('ada@example.test', $entry['fields'][0]['value']);
        $this->assertSame('203.0.113.5', $entry['ip_address']);
    }

    public function test_status_change_goes_through_the_entry_handler_and_rolls_back(): void
    {
        $out = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $this->entry, 'status' => 'trash' ] ]);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('trash', wpforms()->obj('entry')->get($this->entry)->status);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('', wpforms()->obj('entry')->get($this->entry)->status, 'Back to WPForms\' own empty "active" status');
    }

    public function test_entry_ops_refuse_on_wpforms_lite_while_form_ops_keep_working(): void
    {
        \WPMCP_WPForms_Stub::$lite = true;

        foreach ([ [ 'handle_read', 'list-entries', [ 'form_id' => 3 ] ], [ 'handle_read', 'get-entry', [ 'entry_id' => $this->entry ] ], [ 'handle_write', 'update-entry-status', [ 'entry_id' => $this->entry, 'status' => 'spam' ] ] ] as [ $half, $op, $args ]) {
            $out = $this->integration->{$half}([ 'operation' => $op, 'args' => $args ]);
            $this->assertSame('wpforms_entries_unavailable', $out['error']['code'], $op);
        }

        $catalog = array_column($this->integration->catalog()['operations'], null, 'name');
        $this->assertFalse($catalog['list-entries']['dependency_met']);
        $this->assertTrue($catalog['list-forms']['dependency_met']);
        $this->assertArrayNotHasKey('error', $this->integration->handle_read([ 'operation' => 'list-forms' ]));
    }
}
