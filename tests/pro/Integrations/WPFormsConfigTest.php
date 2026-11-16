<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\WPForms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * Issue #674 on WPForms: list-confirmations and the three edits. The form is
 * a real wpforms post whose post_content is the JSON WPForms stores, so the
 * post snapshot and its rollback run for real.
 */
class WPFormsConfigTest extends \WP_UnitTestCase
{
    private WPForms_Integration $integration;
    private int $form;
    private int $legacy;

    private function seed(array $settings, string $title): int
    {
        $id = self::factory()->post->create([
            'post_type'    => 'wpforms',
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_content' => wp_slash(wp_json_encode([ 'fields' => [ [ 'id' => 0, 'type' => 'email', 'label' => 'Email' ] ], 'settings' => $settings ])),
        ]);
        wpforms()->form->forms[ $id ] = get_post($id);
        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \WPMCP_WPForms_Stub::$lite = false;
        wpforms()->form->forms = [];
        $this->form   = $this->seed([
            'notification_enable' => '1',
            'notifications'       => [
                1 => [ 'notification_name' => 'Default Notification', 'email' => '{admin_email}', 'subject' => 'New Entry', 'message' => '{all_fields}', 'sender_name' => 'Site', 'sender_address' => '{admin_email}' ],
                2 => [ 'notification_name' => 'Off', 'enable' => '0', 'email' => 'x@example.test', 'subject' => 'S', 'message' => 'M' ],
            ],
            'confirmations'       => [
                1 => [ 'name' => 'Default Confirmation', 'type' => 'message', 'message' => '<p>Thanks!</p>', 'message_scroll' => '1' ],
            ],
        ], 'Newsletter');
        $this->legacy = $this->seed([ 'confirmation_type' => 'message', 'confirmation_message' => 'Old thanks' ], 'Legacy');
        $this->integration = new WPForms_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_forms_write');
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

    private function settings(int $form): array
    {
        clean_post_cache($form);
        return json_decode(get_post($form)->post_content, true)['settings'];
    }

    public function test_writes_are_off_until_opted_in(): void
    {
        foreach ([
            [ 'update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', 'subject' => 'Hi' ] ],
            [ 'set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => '1', 'to' => 'a@example.test' ] ],
            [ 'update-confirmation', [ 'form_id' => $this->form, 'confirmation_id' => '1', 'message' => 'Hi' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args, true);
            $this->assertSame('opt_in_required', $out['error']['code'], $op);
            $this->assertStringContainsString('wpmcp_enable_forms_write', $out['error']['message']);
        }
        $this->assertSame('New Entry', $this->settings($this->form)['notifications'][1]['subject']);
    }

    public function test_lists_confirmations_including_the_legacy_one(): void
    {
        $list = $this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => $this->form ] ])['result']['confirmations'];
        $this->assertSame('Default Confirmation', $list[0]['name']);
        $this->assertSame('message', $list[0]['type']);

        $legacy = $this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => $this->legacy ] ])['result']['confirmations'];
        $this->assertSame('1', $legacy[0]['id']);
        $this->assertSame('Old thanks', $legacy[0]['message']);
    }

    public function test_update_notification_edits_one_field_set_and_undoes(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '2', 'enabled' => true, 'subject' => 'Back on', 'replyto' => '{field_id="0"}' ]);

        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['changed']);
        $this->assertSame([ 'before' => false, 'after' => true ], $out['result']['changes']['enabled']);
        $n = $this->settings($this->form)['notifications'];
        $this->assertSame('1', $n[2]['enable']);
        $this->assertSame('Back on', $n[2]['subject']);
        $this->assertSame('x@example.test', $n[2]['email'], 'Recipient untouched');
        $this->assertSame('New Entry', $n[1]['subject'], 'Other notifications untouched');

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $n = $this->settings($this->form)['notifications'];
        $this->assertSame('0', $n[2]['enable']);
        $this->assertSame('S', $n[2]['subject']);
    }

    public function test_update_notification_refusals(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->assertSame('notification_not_found', $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '9', 'subject' => 'x' ])['error']['code']);
        $this->assertSame('form_not_found', $this->write('update-notification', [ 'form_id' => 999999, 'notification_id' => '1', 'subject' => 'x' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', 'message' => ' ' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', 'sender_address' => 'nope' ])['error']['code']);
        $same = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', 'subject' => 'New Entry' ]);
        $this->assertFalse($same['result']['changed']);
    }

    public function test_recipients_need_confirm_preview_and_undo(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $args    = [ 'form_id' => $this->form, 'notification_id' => '1', 'to' => 'boss@example.test, {admin_email}' ];
        $preview = $this->write('set-notification-recipients', $args);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame('{admin_email}', $preview['error']['data']['before']['to']);
        $this->assertSame('boss@example.test, {admin_email}', $preview['error']['data']['after']['to']);
        $this->assertSame('{admin_email}', $this->settings($this->form)['notifications'][1]['email']);

        $out = $this->write('set-notification-recipients', $args, true);
        $this->assertTrue($out['result']['recipient_changed']);
        $this->assertSame('boss@example.test, {admin_email}', $this->settings($this->form)['notifications'][1]['email']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('{admin_email}', $this->settings($this->form)['notifications'][1]['email']);

        foreach ([ 'nope', '', "a@example.test\nBcc: b@example.test" ] as $bad) {
            $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'to' => $bad ] + $args, true)['error']['code'], var_export($bad, true));
        }
    }

    public function test_update_confirmation_switches_type_with_validation_and_undoes(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);
        $draft = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'draft' ]);

        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'page' ])['error']['code'], 'A page type needs a page');
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'page', 'page' => $draft ])['error']['code']);
        foreach ([ 'javascript:alert(1)', 'ftp://example.test/x', 'not a url' ] as $url) {
            $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'redirect', 'redirect' => $url ])['error']['code'], $url);
        }

        $out = $this->write('update-confirmation', [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'page', 'page' => $page ]);
        $this->assertSame([ 'before' => 'message', 'after' => 'page' ], $out['result']['changes']['type']);
        $c = $this->settings($this->form)['confirmations'][1];
        $this->assertSame('page', $c['type']);
        $this->assertSame((string) $page, $c['page']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('message', $this->settings($this->form)['confirmations'][1]['type']);
    }

    public function test_message_is_kses_filtered_and_a_legacy_form_gets_its_confirmation_written(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('update-confirmation', [ 'form_id' => $this->legacy, 'confirmation_id' => '1', 'message' => '<p>Hi</p><script>alert(1)</script>' ]);
        $this->assertSame('<p>Hi</p>alert(1)', $out['result']['changes']['message']['after']);
        $stored = $this->settings($this->legacy)['confirmations'];
        $this->assertSame('<p>Hi</p>alert(1)', $stored[1]['message']);
        $this->assertSame('message', $stored[1]['type']);
    }

    public function test_writes_need_manage_options(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', 'subject' => 'x' ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame('New Entry', $this->settings($this->form)['notifications'][1]['subject']);
    }

    // ---- review fixes: recipients only through their own op, confirmation gate ----

    public function test_update_notification_cannot_change_recipients(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', 'subject' => 'x', 'to' => 'evil@example.test' ], true);
        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertSame('{admin_email}', $this->settings($this->form)['notifications'][1]['email']);
        $this->assertSame('New Entry', $this->settings($this->form)['notifications'][1]['subject'], 'Nothing was written');
    }

    public function test_merge_tag_and_named_recipients_are_accepted(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        foreach ([ '{admin_email}', '{field_id="2"}', 'Boss <boss@example.test>, {field_id="2"}' ] as $to) {
            $out = $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => '1', 'to' => $to ], true);
            $this->assertArrayNotHasKey('error', $out, $to);
        }
        foreach ([ '{admin_email', 'a b@example.test', '{x y}z' ] as $bad) {
            $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => '1', 'to' => $bad ], true)['error']['code'], $bad);
        }
    }

    public function test_update_confirmation_needs_confirm_and_previews_before_and_after(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->integration->handle_write([ 'operation' => 'update-confirmation', 'args' => [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'redirect', 'redirect' => 'https://example.test/next' ] ]);
        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertSame('message', $out['error']['data']['before']['type']);
        $this->assertSame('redirect', $out['error']['data']['after']['type']);
        $this->assertSame('message', $this->settings($this->form)['confirmations'][1]['type'], 'Nothing changed without confirm');
    }

    public function test_an_off_site_redirect_with_smart_tags_is_refused_unless_a_site_allows_it(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $args = [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'redirect' ];

        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', $args + [ 'redirect' => 'https://evil.test/?e=[email]' ])['error']['code']);
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', $args + [ 'redirect' => 'https://evil.test/?e={field_id="1"}' ])['error']['code']);
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', $args + [ 'redirect' => 'https://[email]/' ])['error']['code'], 'A tag in the host');
        $same = $this->write('update-confirmation', $args + [ 'redirect' => home_url('/thanks?e=[email]') ]);
        $this->assertArrayNotHasKey('error', $same, wp_json_encode($same));

        add_filter('wpmcp_forms_allow_redirect_smart_tags', '__return_true');
        $this->assertArrayNotHasKey('error', $this->write('update-confirmation', $args + [ 'redirect' => 'https://partner.test/?e=[email]' ]));
        remove_all_filters('wpmcp_forms_allow_redirect_smart_tags');
    }

    public function test_redirect_bypass_forms_are_refused(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $args = [ 'form_id' => $this->form, 'confirmation_id' => '1', 'type' => 'redirect' ];
        foreach ([
            'https://evil.test\\@example.org/?e={field_id="1"}',
            'https://evil.test\\\\@example.org/?e=[email]',
            'https://user@example.org/?e=[email]',
            'https://example.org@evil.test/?e=[email]',
            '//evil.test/?e=[email]',
            'https:///evil.test/?e=[email]',
            'https://example.org.evil.test/?e=[email]',
            'https://example.org%40evil.test/?e=[email]',
            'https://example.org/?e=[email]<script>',
            'https://[email]/',
            'https://example.org/?e={bad tag',
        ] as $url) {
            $out = $this->write('update-confirmation', $args + [ 'redirect' => $url ]);
            $this->assertSame('invalid_confirmation', $out['error']['code'] ?? null, $url);
        }
        $this->assertSame('message', $this->settings($this->form)['confirmations'][1]['type']);
        $ok = $this->write('update-confirmation', $args + [ 'redirect' => home_url('/thanks?e={field_id="1"}') ]);
        $this->assertArrayNotHasKey('error', $ok, wp_json_encode($ok));
    }

    public function test_a_confirmation_that_already_redirects_with_tags_can_be_renamed_or_switched_off(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $form = $this->seed([ 'confirmations' => [ 1 => [ 'name' => 'Old', 'type' => 'redirect', 'redirect' => 'https://partner.test/?e={field_id="1"}' ] ] ], 'Tagged');
        $args = [ 'form_id' => $form, 'confirmation_id' => '1' ];

        $this->assertArrayNotHasKey('error', $this->write('update-confirmation', $args + [ 'name' => 'Renamed' ]));
        $this->assertArrayNotHasKey('error', $this->write('update-confirmation', $args + [ 'name' => 'Again', 'redirect' => 'https://partner.test/?e={field_id="1"}' ]), 'The same url given again is unchanged');
        $this->assertSame('Again', $this->settings($form)['confirmations'][1]['name']);
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', $args + [ 'redirect' => 'https://partner.test/?e={field_id="2"}' ])['error']['code'], 'A changed off-site tagged url is judged');
    }

    public function test_switching_a_dormant_tagged_url_on_is_judged(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $form = $this->seed([ 'confirmations' => [ 1 => [ 'name' => 'Msg', 'type' => 'message', 'message' => 'Thanks', 'redirect' => 'https://partner.test/?e={field_id="1"}' ] ] ], 'Dormant');
        $out  = $this->write('update-confirmation', [ 'form_id' => $form, 'confirmation_id' => '1', 'type' => 'redirect' ]);
        $this->assertSame('invalid_confirmation', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertSame('message', $this->settings($form)['confirmations'][1]['type']);
    }

    public function test_line_breaks_in_name_subject_and_sender_name_are_refused(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        foreach ([ 'name', 'subject', 'sender_name' ] as $field) {
            foreach ([ "x\nBcc: evil@example.test", "x\r\ny" ] as $value) {
                $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => '1', $field => $value ]);
                $this->assertSame('invalid_notification', $out['error']['code'], $field);
            }
        }
        $this->assertSame('New Entry', $this->settings($this->form)['notifications'][1]['subject']);
    }
}
