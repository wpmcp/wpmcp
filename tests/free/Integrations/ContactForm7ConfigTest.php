<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * Issue #674 on Contact Form 7: editing a form's mail templates and system
 * messages. Every write is opt-in, snapshotted (the form post and its meta)
 * and undone with rollback-operation; the recipient change needs confirm:true
 * and shows before and after.
 */
class ContactForm7ConfigTest extends \WP_UnitTestCase
{
    private Contact_Form_7_Integration $integration;
    private int $form;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \WPCF7_ContactForm::$registry = [];
        $this->form = self::factory()->post->create([ 'post_type' => 'wpcf7_contact_form', 'post_title' => 'Contact' ]);
        $mail       = [
            'active'             => true,
            'recipient'          => '[_site_admin_email]',
            'sender'             => 'Site <wordpress@example.test>',
            'subject'            => 'New message',
            'body'               => 'From: [your-name]',
            'additional_headers' => "Reply-To: [your-email]\nCc: old@example.test",
        ];
        \WPCF7_ContactForm::seed($this->form, 'Contact', 'contact', [
            'mail'     => $mail,
            'mail_2'   => [ 'active' => false, 'recipient' => '[your-email]', 'sender' => 'Site <wordpress@example.test>', 'subject' => 'Thanks', 'body' => 'Thank you', 'additional_headers' => '' ],
            'messages' => [ 'mail_sent_ok' => 'Thank you for your message.', 'validation_error' => 'One or more fields have an error.' ],
        ]);
        // Saved once, the way a real form is: properties now live in post meta.
        \WPCF7_ContactForm::get_instance($this->form)->save();
        $this->integration = new Contact_Form_7_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_forms_write');
        remove_all_filters('wpmcp_forms_allowed_recipient_domains');
        parent::tearDown();
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $payload = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $payload['confirm'] = true;
        }
        return $this->integration->handle_write($payload);
    }

    private function opt_in(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
    }

    private function mail(string $key = 'mail'): array
    {
        return (array) get_post_meta($this->form, '_' . $key, true);
    }

    public function test_every_write_is_off_until_the_site_opts_in(): void
    {
        foreach ([
            [ 'update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'subject' => 'X' ] ],
            [ 'set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => 'a@example.test' ] ],
            [ 'update-messages', [ 'form_id' => $this->form, 'messages' => [ 'mail_sent_ok' => 'Done' ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args, true);
            $this->assertSame('opt_in_required', $out['error']['code'], $op);
            $this->assertStringContainsString('wpmcp_enable_forms_write', $out['error']['message'], $op);
        }
        $this->assertSame('New message', $this->mail()['subject']);
    }

    public function test_update_notification_changes_fields_and_undoes(): void
    {
        $this->opt_in();
        $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'subject' => 'Hello', 'body' => 'New body', 'reply_to' => '[your-email-2]' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['changed']);
        $this->assertSame([ 'before' => 'New message', 'after' => 'Hello' ], $out['result']['changes']['subject']);
        $this->assertSame('Hello', $this->mail()['subject']);
        $this->assertStringContainsString('Reply-To: [your-email-2]', $this->mail()['additional_headers']);
        $this->assertStringContainsString('Cc: old@example.test', $this->mail()['additional_headers'], 'Other headers stay');
        $this->assertSame('[_site_admin_email]', $this->mail()['recipient'], 'Recipients are not touched here');

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('New message', $this->mail()['subject']);
        $this->assertStringContainsString('Reply-To: [your-email]', $this->mail()['additional_headers']);
    }

    public function test_update_notification_reports_no_change_and_refuses_bad_input(): void
    {
        $this->opt_in();
        $same = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'subject' => 'New message' ]);
        $this->assertFalse($same['result']['changed']);

        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'subject' => '  ' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'enabled' => false ])['error']['code'], 'Primary mail cannot be switched off');
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'sender' => "a@example.test\nBcc: x@example.test" ])['error']['code']);
        $this->assertSame('form_not_found', $this->write('update-notification', [ 'form_id' => 999999, 'notification_id' => 'mail', 'subject' => 'X' ])['error']['code']);
    }

    public function test_mail_2_can_be_switched_on(): void
    {
        $this->opt_in();
        $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail_2', 'enabled' => true ]);
        $this->assertTrue($out['result']['changes']['enabled']['after']);
        $this->assertTrue($this->mail('mail_2')['active']);
    }

    public function test_recipients_need_confirm_show_before_and_after_and_undo(): void
    {
        $this->opt_in();
        $args    = [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => 'boss@example.test', 'cc' => '', 'bcc' => 'audit@example.test' ];
        $preview = $this->write('set-notification-recipients', $args);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame('[_site_admin_email]', $preview['error']['data']['before']['to']);
        $this->assertSame('boss@example.test', $preview['error']['data']['after']['to']);
        $this->assertSame('[_site_admin_email]', $this->mail()['recipient'], 'Nothing changed without confirm');

        $out = $this->write('set-notification-recipients', $args, true);
        $this->assertTrue($out['result']['recipient_changed']);
        $this->assertSame('old@example.test', $out['result']['before']['cc']);
        $this->assertSame('', $out['result']['after']['cc']);
        $this->assertSame('boss@example.test', $this->mail()['recipient']);
        $this->assertStringNotContainsString('Cc:', $this->mail()['additional_headers']);
        $this->assertStringContainsString('Bcc: audit@example.test', $this->mail()['additional_headers']);
        $this->assertStringContainsString('Reply-To: [your-email]', $this->mail()['additional_headers']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('[_site_admin_email]', $this->mail()['recipient']);
        $this->assertStringContainsString('Cc: old@example.test', $this->mail()['additional_headers']);
        $this->assertStringNotContainsString('Bcc:', $this->mail()['additional_headers']);
    }

    public function test_recipients_are_validated(): void
    {
        $this->opt_in();
        foreach ([ 'not-an-email', 'a@example.test,', "a@example.test\r\nBcc: x@example.test", '' ] as $bad) {
            $out = $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => $bad ], true);
            $this->assertSame('invalid_recipients', $out['error']['code'], var_export($bad, true));
        }
        $ok = $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => 'Boss <boss@example.test>, [your-email]' ], true);
        $this->assertArrayNotHasKey('error', $ok);
    }

    public function test_a_site_can_limit_recipient_domains(): void
    {
        $this->opt_in();
        add_filter('wpmcp_forms_allowed_recipient_domains', static fn () => [ 'example.test' ]);
        $out = $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => 'x@evil.test' ], true);
        $this->assertSame('invalid_recipients', $out['error']['code']);
        $this->assertArrayNotHasKey('error', $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => 'x@example.test' ], true));
    }

    public function test_messages_are_listed_validated_written_and_undone(): void
    {
        $list = $this->integration->handle_read([ 'operation' => 'list-messages', 'args' => [ 'form_id' => $this->form ] ])['result'];
        $this->assertSame('Thank you for your message.', $list['messages']['mail_sent_ok']);

        $this->opt_in();
        $this->assertSame('unknown_message', $this->write('update-messages', [ 'form_id' => $this->form, 'messages' => [ 'nope' => 'x' ] ])['error']['code']);

        $out = $this->write('update-messages', [ 'form_id' => $this->form, 'messages' => [ 'mail_sent_ok' => 'Sent <b>ok</b>' ] ]);
        $this->assertSame('Sent ok', $out['result']['changes']['mail_sent_ok']['after'], 'Markup is stripped');
        $this->assertSame('Sent ok', get_post_meta($this->form, '_messages', true)['mail_sent_ok']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('Thank you for your message.', get_post_meta($this->form, '_messages', true)['mail_sent_ok']);
    }

    public function test_writes_need_manage_options(): void
    {
        $this->opt_in();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'subject' => 'X' ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame('New message', $this->mail()['subject']);
    }

    public function test_update_notification_cannot_change_recipients(): void
    {
        $this->opt_in();
        foreach ([ 'to', 'cc', 'bcc', 'recipient' ] as $key) {
            $out = $this->write('update-notification', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'subject' => 'x', $key => 'evil@example.test' ], true);
            $this->assertSame('invalid_args', $out['error']['code'], $key);
        }
        $this->assertSame('[_site_admin_email]', $this->mail()['recipient']);
        $this->assertStringNotContainsString('evil', $this->mail()['additional_headers']);
        $this->assertSame('New message', $this->mail()['subject'], 'Nothing was written');
    }

    public function test_merge_tag_recipients_are_accepted_and_broken_tags_refused(): void
    {
        $this->opt_in();
        foreach ([ '[_site_admin_email]', '[your-email]', 'Boss <boss@example.test>, [your-email]' ] as $to) {
            $this->assertArrayNotHasKey('error', $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => $to ], true), $to);
        }
        foreach ([ '[your-email', 'a b@example.test', '[x y]' ] as $bad) {
            $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'form_id' => $this->form, 'notification_id' => 'mail', 'to' => $bad ], true)['error']['code'], $bad);
        }
    }
}
