<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Formidable_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * Issue #674 on Formidable: the email and on_submit actions are real
 * frm_form_actions posts (the form model is a double), so the post snapshot
 * and its rollback run for real.
 */
class FormidableConfigTest extends \WP_UnitTestCase
{
    private Formidable_Integration $integration;
    private int $email;
    private int $other_email;
    private int $confirm;

    private function action(int $form, string $type, string $title, array $settings, string $status = 'publish'): int
    {
        return self::factory()->post->create([
            'post_type'    => 'frm_form_actions',
            'post_title'   => $title,
            'post_excerpt' => $type,
            'post_status'  => $status,
            'menu_order'   => $form,
            'post_content' => wp_slash(wp_json_encode($settings)),
        ]);
    }

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        \FrmEntry::install_tables(); // DDL, outside any test transaction
    }

    public static function tear_down_after_class(): void
    {
        \FrmEntry::uninstall_tables();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \FrmEntry::$entries = [
            50 => (object) [ 'id' => 50, 'form_id' => 5, 'item_key' => 'e50', 'created_at' => '2026-03-01 10:00:00' ],
            51 => (object) [ 'id' => 51, 'form_id' => 5, 'item_key' => 'e51', 'created_at' => '2026-03-02 10:00:00' ],
            52 => (object) [ 'id' => 52, 'form_id' => 5, 'item_key' => 'e52', 'created_at' => '2026-03-02 10:00:00' ],
        ];
        \FrmEntry::seed_rows(50, 5, [ 1 => 'Ada', 2 => 'ada@example.test' ]);
        \FrmEntry::seed_rows(51, 5, [ 1 => 'Child row' ], 50);
        \FrmEntry::seed_rows(52, 5, [ 1 => 'Other' ]);
        \FrmForm::$forms = [ 5 => (object) [ 'id' => 5, 'name' => 'Booking', 'form_key' => 'booking', 'options' => [] ], 6 => (object) [ 'id' => 6, 'name' => 'Legacy', 'form_key' => 'legacy', 'options' => [ 'success_msg' => 'Old msg', 'success_action' => 'message' ] ] ];
        $this->email       = $this->action(5, 'email', 'Admin email', [ 'email_to' => '[admin_email]', 'from' => '[sitename] <[admin_email]>', 'email_subject' => 'New booking', 'email_message' => '[default-message]', 'event' => [ 'create' ] ]);
        $this->other_email = $this->action(9, 'email', 'Other form', [ 'email_to' => 'x@example.test' ]);
        $this->confirm     = $this->action(5, 'on_submit', 'Confirmation', [ 'success_action' => 'message', 'success_msg' => 'Thanks', 'success_url' => '', 'success_page_id' => '' ]);
        \FrmFormAction::$actions = [ 5 => [
            (object) [ 'ID' => $this->email, 'post_excerpt' => 'email' ],
            (object) [ 'ID' => $this->confirm, 'post_excerpt' => 'on_submit' ],
        ], 6 => [] ];
        $this->integration = new Formidable_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_forms_write');
        \FrmFormAction::$actions = [];
        parent::tearDown();
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $payload = [ 'operation' => $op, 'args' => $args ];
        // update-confirmation always confirms: the dedicated test below covers the refusal without it.
        if ($confirm || 'update-confirmation' === $op) {
            $payload['confirm'] = true;
        }
        return $this->integration->handle_write($payload);
    }

    private function settings(int $id): array
    {
        clean_post_cache($id);
        return json_decode(get_post($id)->post_content, true);
    }

    public function test_writes_are_off_until_opted_in(): void
    {
        foreach ([
            [ 'update-notification', [ 'form_id' => 5, 'notification_id' => $this->email, 'subject' => 'x' ] ],
            [ 'set-notification-recipients', [ 'form_id' => 5, 'notification_id' => $this->email, 'to' => 'a@example.test' ] ],
            [ 'update-confirmation', [ 'form_id' => 5, 'confirmation_id' => $this->confirm, 'message' => 'x' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args, true);
            $this->assertSame('opt_in_required', $out['error']['code'], $op);
        }
        $this->assertSame('New booking', $this->settings($this->email)['email_subject']);
    }

    public function test_lists_confirmations_and_the_legacy_one(): void
    {
        $list = $this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => 5 ] ])['result']['confirmations'];
        $this->assertCount(1, $list);
        $this->assertSame($this->confirm, $list[0]['id']);
        $this->assertSame('Thanks', $list[0]['message']);

        $legacy = $this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => 6 ] ])['result']['confirmations'];
        $this->assertTrue($legacy[0]['legacy']);
        $this->assertSame('Old msg', $legacy[0]['message']);
    }

    public function test_update_notification_edits_and_undoes(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('update-notification', [ 'form_id' => 5, 'notification_id' => $this->email, 'subject' => 'Booked', 'enabled' => false, 'reply_to' => '[25]' ]);

        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'before' => true, 'after' => false ], $out['result']['changes']['enabled']);
        $s = $this->settings($this->email);
        $this->assertSame('Booked', $s['email_subject']);
        $this->assertSame('[25]', $s['reply_to']);
        $this->assertSame('[admin_email]', $s['email_to']);
        $this->assertSame('draft', get_post($this->email)->post_status);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('New booking', $this->settings($this->email)['email_subject']);
        $this->assertSame('publish', get_post($this->email)->post_status);
    }

    public function test_update_notification_refusals(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->assertSame('notification_not_found', $this->write('update-notification', [ 'form_id' => 5, 'notification_id' => $this->other_email, 'subject' => 'x' ])['error']['code'], 'An action of another form is not found');
        $this->assertSame('notification_not_found', $this->write('update-notification', [ 'form_id' => 5, 'notification_id' => $this->confirm, 'subject' => 'x' ])['error']['code'], 'A confirmation is not an email');
        $this->assertSame('form_not_found', $this->write('update-notification', [ 'form_id' => 99, 'notification_id' => $this->email, 'subject' => 'x' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => 5, 'notification_id' => $this->email, 'subject' => '' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => 5, 'notification_id' => $this->email, 'from' => 'nope' ])['error']['code']);
        $this->assertSame('x@example.test', $this->settings($this->other_email)['email_to']);
    }

    public function test_recipients_need_confirm_and_undo(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $args    = [ 'form_id' => 5, 'notification_id' => $this->email, 'to' => 'boss@example.test', 'bcc' => 'audit@example.test' ];
        $preview = $this->write('set-notification-recipients', $args);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame('[admin_email]', $preview['error']['data']['before']['to']);
        $this->assertSame('audit@example.test', $preview['error']['data']['after']['bcc']);
        $this->assertSame('[admin_email]', $this->settings($this->email)['email_to']);

        $out = $this->write('set-notification-recipients', $args, true);
        $this->assertTrue($out['result']['recipient_changed']);
        $this->assertSame('boss@example.test', $this->settings($this->email)['email_to']);
        $this->assertSame('audit@example.test', $this->settings($this->email)['bcc']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('[admin_email]', $this->settings($this->email)['email_to']);
        $this->assertArrayNotHasKey('bcc', $this->settings($this->email));

        $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'to' => 'bad' ] + $args, true)['error']['code']);
    }

    public function test_update_confirmation_validates_and_undoes(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);

        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => 5, 'confirmation_id' => $this->confirm, 'type' => 'redirect' ])['error']['code']);
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => 5, 'confirmation_id' => $this->confirm, 'type' => 'redirect', 'redirect' => 'javascript:alert(1)' ])['error']['code']);
        $this->assertSame('confirmation_not_found', $this->write('update-confirmation', [ 'form_id' => 6, 'confirmation_id' => 1, 'message' => 'x' ])['error']['code'], 'The legacy form-options confirmation is read-only');

        $out = $this->write('update-confirmation', [ 'form_id' => 5, 'confirmation_id' => $this->confirm, 'type' => 'redirect', 'redirect' => 'https://example.test/thanks' ]);
        $this->assertSame('redirect', $this->settings($this->confirm)['success_action']);
        $this->assertSame('https://example.test/thanks', $this->settings($this->confirm)['success_url']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('message', $this->settings($this->confirm)['success_action']);

        $ok = $this->write('update-confirmation', [ 'form_id' => 5, 'confirmation_id' => $this->confirm, 'type' => 'page', 'page' => $page ]);
        $this->assertSame((string) $page, $this->settings($this->confirm)['success_page_id']);
        $this->assertTrue($ok['result']['changed']);
    }

    // ---- #675: delete-entry ----------------------------------------------

    private function rows(string $table, string $column, int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $wpdb->prefix . $table, $column, $id));
    }

    public function test_delete_entry_is_off_until_opted_in_then_needs_confirm_and_previews_without_values(): void
    {
        $this->assertSame('opt_in_required', $this->write('delete-entry', [ 'entry_id' => 50 ], true)['error']['code']);

        add_filter('wpmcp_enable_forms_write', '__return_true');
        $preview = $this->write('delete-entry', [ 'entry_id' => 50 ]);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame(2, $preview['error']['data']['values']);
        $this->assertSame(1, $preview['error']['data']['child_entries']);
        $this->assertStringNotContainsString('ada@example.test', wp_json_encode($preview));
        $this->assertSame(1, $this->rows('frm_items', 'id', 50));
    }

    public function test_delete_entry_removes_entry_values_and_children_and_one_session_restores_them(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('delete-entry', [ 'entry_id' => 50 ], true);

        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable']);
        foreach ([ 50, 51 ] as $id) {
            $this->assertSame(0, $this->rows('frm_items', 'id', $id));
            $this->assertSame(0, $this->rows('frm_item_metas', 'item_id', $id));
        }
        $this->assertSame(1, $this->rows('frm_items', 'id', 52), 'Another entry stays');

        ( new Rollback_Session() )->handle([ 'session_id' => $out['session_id'] ]);
        $this->assertSame(1, $this->rows('frm_items', 'id', 50));
        $this->assertSame(2, $this->rows('frm_item_metas', 'item_id', 50));
        $this->assertSame(1, $this->rows('frm_items', 'id', 51));
        $this->assertSame(1, $this->rows('frm_item_metas', 'item_id', 51));
    }

    public function test_delete_entry_refuses_an_unknown_entry_and_needs_manage_options(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->assertSame('entry_not_found', $this->write('delete-entry', [ 'entry_id' => 999 ], true)['error']['code']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->write('delete-entry', [ 'entry_id' => 50 ], true));
        $this->assertSame(1, $this->rows('frm_items', 'id', 50));
    }

    // ---- review fixes ------------------------------------------------------

    public function test_update_notification_cannot_change_recipients(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        foreach ([ 'to', 'cc', 'bcc' ] as $key) {
            $out = $this->write('update-notification', [ 'form_id' => 5, 'notification_id' => $this->email, 'subject' => 'x', $key => 'evil@example.test' ], true);
            $this->assertSame('invalid_args', $out['error']['code'], $key);
        }
        $s = $this->settings($this->email);
        $this->assertSame('[admin_email]', $s['email_to']);
        $this->assertArrayNotHasKey('bcc', $s);
        $this->assertSame('New booking', $s['email_subject'], 'Nothing was written');
    }

    public function test_shortcode_and_named_recipients_are_accepted(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        foreach ([ '[admin_email]', '[25]', 'Boss <boss@example.test>, [25]', 'Site <[admin_email]>' ] as $to) {
            $this->assertArrayNotHasKey('error', $this->write('set-notification-recipients', [ 'form_id' => 5, 'notification_id' => $this->email, 'to' => $to ], true), $to);
        }
        foreach ([ '[admin_email', 'a b@example.test', '[x y]' ] as $bad) {
            $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'form_id' => 5, 'notification_id' => $this->email, 'to' => $bad ], true)['error']['code'], $bad);
        }
    }

    public function test_update_confirmation_needs_confirm_and_off_site_tag_redirects_are_refused(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $args = [ 'form_id' => 5, 'confirmation_id' => $this->confirm, 'type' => 'redirect' ];

        $preview = $this->integration->handle_write([ 'operation' => 'update-confirmation', 'args' => $args + [ 'redirect' => 'https://example.test/next' ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame('redirect', $preview['error']['data']['after']['type']);
        $this->assertSame('message', $this->settings($this->confirm)['success_action']);

        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', $args + [ 'redirect' => 'https://evil.test/?e=[25]' ])['error']['code']);
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', $args + [ 'redirect' => 'https://evil.test/?e={x}' ])['error']['code']);
        $this->assertArrayNotHasKey('error', $this->write('update-confirmation', $args + [ 'redirect' => home_url('/thanks?e=[25]') ]));
        $this->assertSame('message', get_post($this->confirm) ? 'message' : '', 'sanity');
    }

    public function test_delete_entry_refuses_an_entry_with_payment_records(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_forms_write', '__return_true');
        foreach ([ 'frm_payments', 'frm_subscriptions' ] as $table) {
            $wpdb->insert($wpdb->prefix . $table, [ 'item_id' => 50 ]);
            $out = $this->write('delete-entry', [ 'entry_id' => 50 ], true);
            $this->assertSame('entry_has_payments', $out['error']['code'], $table);
            $this->assertSame(1, $this->rows('frm_items', 'id', 50));
            $wpdb->delete($wpdb->prefix . $table, [ 'item_id' => 50 ]);
        }
        $this->assertArrayNotHasKey('error', $this->write('delete-entry', [ 'entry_id' => 50 ], true));
    }
}
