<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Formidable_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * Issues #674 and #675 against the real Formidable Forms (bin/test-local.sh
 * --live-forms, WPMCP_LIVE_FORMS=1): the form, its email and on_submit actions
 * and an entry are made by Formidable's own models on its real tables, then
 * edited, deleted and restored by the adapter. Skipped elsewhere; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class FormidableLiveTest extends \WP_UnitTestCase
{
    private Formidable_Integration $integration;
    private int $form_id;
    private int $field_id;
    private int $email_id;
    private int $confirm_id;
    private int $entry_id;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (class_exists('FrmAppController')) {
            // Formidable's installer asks its own site for inbox messages and
            // probes its font file over HTTP; answer both as an offline site.
            $offline = static fn () => new \WP_Error('offline', 'Offline test run.');
            add_filter('pre_http_request', $offline);
            \FrmAppController::install(); // DDL (dbDelta), outside any test transaction
            remove_filter('pre_http_request', $offline);
        }
    }

    public static function tear_down_after_class(): void
    {
        global $wpdb;
        if (class_exists('FrmAppController')) {
            foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'frm_') . '%')) as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
            }
        }
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('FrmAppController') || ! class_exists('FrmFormActionsController')) {
            $this->markTestSkipped('Needs the real Formidable Forms (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_forms_write', '__return_true');

        $this->form_id  = (int) \FrmForm::create([ 'name' => 'Live Formidable', 'form_key' => 'live-formidable', 'description' => '' ]);
        $this->field_id = (int) \FrmField::create([ 'form_id' => $this->form_id, 'type' => 'text', 'name' => 'Name', 'field_key' => 'live-name', 'field_options' => [], 'options' => '' ]);
        $this->assertGreaterThan(0, $this->form_id);

        $record = static fn (string $type, string $title, array $settings, int $form) => [
            'post_type'    => 'frm_form_actions',
            'post_excerpt' => $type,
            'post_title'   => $title,
            'post_status'  => 'publish',
            'menu_order'   => $form,
            'post_content' => $settings,
        ];
        $this->email_id   = (int) \FrmFormActionsController::get_form_actions('email')->save_settings($record('email', 'Admin email', [ 'email_to' => '[admin_email]', 'email_subject' => 'Live subject', 'email_message' => '[default-message]', 'from' => '[admin_email]', 'event' => [ 'create' ] ], $this->form_id));
        $this->confirm_id = (int) \FrmFormActionsController::get_form_actions('on_submit')->save_settings($record('on_submit', 'Confirmation', [ 'success_action' => 'message', 'success_msg' => 'Thanks', 'event' => [ 'create' ] ], $this->form_id));
        $this->assertGreaterThan(0, $this->email_id);
        $this->assertGreaterThan(0, $this->confirm_id);
        // Issue #984: on a real site the action was created long before it is
        // edited. Dating the fixtures back makes that deterministic: the test
        // passed only when the edit ran within the fixture's own second.
        global $wpdb;
        foreach ([ $this->email_id, $this->confirm_id ] as $action_id) {
            $wpdb->update($wpdb->posts, [ 'post_date' => '2020-01-02 03:04:05', 'post_date_gmt' => '2020-01-02 03:04:05' ], [ 'ID' => $action_id ]);
            clean_post_cache($action_id);
        }

        $this->entry_id = (int) \FrmEntry::create([ 'form_id' => $this->form_id, 'item_meta' => [ $this->field_id => 'Ada' ] ]);
        $this->assertGreaterThan(0, $this->entry_id, 'Formidable stored the entry');
        $this->integration = new Formidable_Integration();
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        return $out;
    }

    private function settings(int $action): array
    {
        clean_post_cache($action);
        return (array) json_decode((string) get_post($action)->post_content, true);
    }

    public function test_notifications_and_confirmations_are_edited_through_formidable_and_undone(): void
    {
        $this->assertTrue($this->integration->is_available());
        $notes = $this->ok($this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => $this->form_id ] ]))['result']['notifications'];
        $this->assertSame($this->email_id, $notes[0]['id']);
        $confs = $this->ok($this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => $this->form_id ] ]))['result']['confirmations'];
        $this->assertSame($this->confirm_id, $confs[0]['id']);

        $edit = $this->ok($this->integration->handle_write([ 'operation' => 'update-notification', 'args' => [ 'form_id' => $this->form_id, 'notification_id' => $this->email_id, 'subject' => 'Edited subject', 'message' => 'Line one' . "\n" . 'Line "two" / three' ] ]));
        $this->assertTrue($edit['recoverable']);
        $s = $this->settings($this->email_id);
        $this->assertSame('Edited subject', $s['email_subject']);
        $this->assertSame("Line one\nLine \"two\" / three", $s['email_message'], 'Formidable\'s own encoding round-trips quotes, slashes and line breaks');
        $this->assertSame('[admin_email]', $s['email_to']);
        $this->assertSame('Edited subject', $this->ok($this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => $this->form_id ] ]))['result']['notifications'][0]['subject'], 'Formidable\'s own reader sees the edit');

        $to = $this->ok($this->integration->handle_write([ 'operation' => 'set-notification-recipients', 'confirm' => true, 'args' => [ 'form_id' => $this->form_id, 'notification_id' => $this->email_id, 'to' => 'boss@example.test', 'bcc' => 'audit@example.test' ] ]));
        $this->assertSame('boss@example.test', $this->settings($this->email_id)['email_to']);

        $conf = $this->ok($this->integration->handle_write([ 'operation' => 'update-confirmation', 'confirm' => true, 'args' => [ 'form_id' => $this->form_id, 'confirmation_id' => $this->confirm_id, 'type' => 'redirect', 'redirect' => 'https://example.test/thanks?a=1&b=2' ] ]));
        $this->assertSame('redirect', $this->settings($this->confirm_id)['success_action']);
        $this->assertStringStartsWith('https://example.test/thanks', $this->settings($this->confirm_id)['success_url']);

        foreach ([ $conf, $to, $edit ] as $out) {
            $undone = ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
            $this->assertTrue($undone['restored'], wp_json_encode($undone));
        }
        $this->assertSame('Live subject', $this->settings($this->email_id)['email_subject']);
        $this->assertSame('2020-01-02 03:04:05', get_post($this->email_id)->post_date_gmt, 'an edit keeps the action\'s creation date');
        global $wpdb;
        $actions = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND menu_order = %d", 'frm_form_actions', $this->form_id));
        $this->assertSame(2, $actions, 'undo leaves no duplicate action post');
        $this->assertSame('[admin_email]', $this->settings($this->email_id)['email_to']);
        $this->assertSame('message', $this->settings($this->confirm_id)['success_action']);
    }

    public function test_delete_entry_removes_the_real_rows_and_one_session_restores_them(): void
    {
        global $wpdb;
        $count = fn (string $table, string $column) => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . $table, $column, $this->entry_id));

        $this->assertSame(1, $count('frm_items', 'id'));
        $this->assertGreaterThan(0, $count('frm_item_metas', 'item_id'));
        $metas = $count('frm_item_metas', 'item_id');

        $preview = $this->integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->entry_id ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertStringNotContainsString('Ada', wp_json_encode($preview));

        $out = $this->ok($this->integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'entry_id' => $this->entry_id ] ]));
        $this->assertTrue($out['result']['deleted']);
        $this->assertSame(0, $count('frm_items', 'id'));
        $this->assertSame(0, $count('frm_item_metas', 'item_id'));
        $this->assertEmpty(\FrmEntry::getOne($this->entry_id), 'Formidable no longer finds the entry (its caches were cleared)');

        $undone = ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertNotEmpty($undone['restored'] ?? $undone, wp_json_encode($undone));
        $this->assertSame(1, $count('frm_items', 'id'));
        $this->assertSame($metas, $count('frm_item_metas', 'item_id'));
        $entry = \FrmEntry::getOne($this->entry_id, true);
        $this->assertIsObject($entry, 'Formidable finds the restored entry');
    }
}
