<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Email_Log_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;

require_once __DIR__ . '/../../support/email-log-stubs.php';

/**
 * Email logs (issue #905): read-only free-tier ops on the plugin-data
 * dispatcher pair, so they add no top-level tool.
 *
 * None of the three plugins is loaded in the shared test core. Presence is
 * driven through wpmcp_wp_mail_logging_active, wpmcp_check_email_active and
 * wpmcp_email_log_active, and the storage is built the way the plugins
 * create it (tests/support/email-log-stubs.php).
 */
class EmailLogPackTest extends \WP_UnitTestCase
{
    private const RESET = 'https://example.com/wp-login.php?action=rp&key=SENTINEL-RESET-KEY&login=ada';

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_email_log_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_email_log_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        global $wpdb;
        foreach (wpmcp_test_email_log_tables() as $table) {
            $wpdb->query("DELETE FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        add_filter('wpmcp_wp_mail_logging_active', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wp_mail_logging_active');
        remove_all_filters('wpmcp_check_email_active');
        remove_all_filters('wpmcp_email_log_active');
        parent::tearDown();
    }

    private function only(string $filter): void
    {
        foreach ([ 'wpmcp_wp_mail_logging_active', 'wpmcp_check_email_active', 'wpmcp_email_log_active' ] as $name) {
            remove_all_filters($name);
            add_filter($name, $name === $filter ? '__return_true' : '__return_false');
        }
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'] ?? $out;
    }

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? $out['code'] ?? null;
    }

    public function test_the_ops_are_free_tier_reads_that_need_manage_options(): void
    {
        $ops = Email_Log_Pack::operations();
        $this->assertSame([ 'emaillog-list-emails', 'emaillog-email-stats', 'emaillog-get-email' ], array_keys($ops));
        foreach ($ops as $def) {
            $this->assertSame('read', $def['mode']);
            $this->assertSame('manage_options', $def['capability']);
            $this->assertArrayNotHasKey('tier', $def);
        }
    }

    public function test_a_lower_role_is_refused_every_op(): void
    {
        $id = wpmcp_test_wpml_mail();
        foreach ([ 'editor', 'author', 'subscriber' ] as $role) {
            wp_set_current_user(self::factory()->user->create([ 'role' => $role ]));
            foreach ([ [ 'emaillog-list-emails', [] ], [ 'emaillog-get-email', [ 'id' => $id ] ] ] as [ $op, $args ]) {
                $this->assertSame('operation_denied', $this->code($this->read($op, $args)), $role . ' ' . $op);
            }
        }
    }

    public function test_wp_mail_logging_status_comes_from_the_error_column(): void
    {
        $sent   = wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-10 09:30:00' ]);
        $failed = wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-11 09:30:00', 'error' => 'SMTP Error: Could not connect to SMTP host.', 'attachments' => "wp-content/uploads/a/invoice.pdf,\nwp-content/uploads/b/terms.pdf" ]);

        $list = $this->ok($this->read('emaillog-list-emails'));
        $this->assertSame('wp-mail-logging', $list['source']);
        $this->assertSame(2, $list['total']);
        $this->assertTrue($list['masked']);
        $this->assertSame([ $failed, $sent ], array_column($list['emails'], 'id'), 'newest first');
        $this->assertSame([ 'failed', 'sent' ], array_column($list['emails'], 'status'));
        $this->assertSame([ true, false ], array_column($list['emails'], 'has_attachments'));
        $this->assertSame('SMTP Error: Could not connect to SMTP host.', $list['emails'][0]['error']);
        $this->assertSame('2026-11-11 09:30:00', $list['emails'][0]['time']);

        $this->assertSame([ $failed ], array_column($this->ok($this->read('emaillog-list-emails', [ 'status' => 'failed' ]))['emails'], 'id'));
        $this->assertSame([ $sent ], array_column($this->ok($this->read('emaillog-list-emails', [ 'status' => 'sent' ]))['emails'], 'id'));
    }

    public function test_a_list_masks_recipients_and_leaves_out_bodies_headers_and_ips(): void
    {
        wpmcp_test_wpml_mail([ 'receiver' => 'Ada Lovelace <ada@example.com>, bob.smith@example.org', 'subject' => 'Hi ada@example.com', 'message' => 'BODY-SENTINEL', 'headers' => "Bcc: hidden@example.net\nX-Api-Key: HEADER-SENTINEL" ]);
        wpmcp_test_wpml_mail([ 'receiver' => 'broken-address', 'error' => 'Could not send to carol@example.com: ' . self::RESET ]);

        $list = $this->ok($this->read('emaillog-list-emails'));
        $this->assertSame('***', $list['emails'][0]['recipient']);
        $this->assertSame('a***@example.com, b***@example.org', $list['emails'][1]['recipient']);
        $this->assertSame('Hi a***@example.com', $list['emails'][1]['subject']);
        $this->assertStringContainsString('c***@example.com', $list['emails'][0]['error']);
        $json = (string) wp_json_encode($list);
        foreach ([ 'ada@example.com', 'bob.smith', 'Lovelace', 'BODY-SENTINEL', 'hidden@example.net', 'HEADER-SENTINEL', 'SENTINEL-RESET-KEY', '127.0.0.1', 'carol@example.com' ] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
        $this->assertArrayNotHasKey('body', $list['emails'][0]);
        $this->assertArrayNotHasKey('headers', $list['emails'][0]);
    }

    public function test_get_email_returns_recipients_in_full_with_links_redacted_and_the_body_cut(): void
    {
        $body = 'Reset here: ' . self::RESET . ' or visit https://example.com/shop?page=2 . Your verification code is 482913. ' . str_repeat('x', 1500);
        $id   = wpmcp_test_wpml_mail([
            'message'     => $body,
            'headers'     => "Content-Type: text/html\nAuthorization: Bearer HEADER-SENTINEL\nX-Token: abc\nList-Unsubscribe: <https://example.com/unsubscribe?token=UNSUB-SENTINEL>\nMessage-ID: <abc@example.com>",
            'attachments' => "/var/www/private/path/contract.pdf",
        ]);

        $none = $this->ok($this->read('emaillog-get-email', [ 'id' => $id ]))['email'];
        $this->assertSame('', $none['body'], 'no body unless asked for');
        $this->assertFalse($none['body_included']);
        $this->assertStringNotContainsString('SENTINEL-RESET-KEY', (string) wp_json_encode($none));

        $email = $this->ok($this->read('emaillog-get-email', [ 'id' => $id, 'body' => 'truncated' ]))['email'];
        $this->assertSame('Ada Lovelace <ada@example.com>', $email['recipient']);
        $this->assertSame('sent', $email['status']);
        $this->assertSame([ 'contract.pdf' ], $email['attachments']);
        $this->assertStringContainsString('[redacted-link]', $email['body']);
        $this->assertStringContainsString('https://example.com/shop?page=2', $email['body'], 'a harmless link stays');
        $this->assertStringNotContainsString('482913', $email['body']);
        $this->assertTrue($email['body_truncated']);
        $this->assertLessThanOrEqual(1003, mb_strlen($email['body']));
        $this->assertGreaterThan(1500, $email['body_length']);
        $this->assertStringContainsString('Message-ID: <abc@example.com>', $email['headers']);
        $this->assertStringContainsString('Content-Type: text/html', $email['headers']);

        $json = (string) wp_json_encode($email);
        foreach ([ 'SENTINEL-RESET-KEY', 'HEADER-SENTINEL', 'UNSUB-SENTINEL', '/var/www/private' ] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }

        $full = $this->ok($this->read('emaillog-get-email', [ 'id' => $id, 'body' => 'full' ]))['email'];
        $this->assertFalse($full['body_truncated']);
        $this->assertStringContainsString(str_repeat('x', 1500), $full['body']);
        $this->assertStringNotContainsString('SENTINEL-RESET-KEY', (string) wp_json_encode($full));
    }

    public function test_the_redaction_patterns(): void
    {
        foreach ([
            'https://x.test/wp-login.php?action=rp&key=K&login=a',
            'https://x.test/my-account/lost-password/?show-reset-form=true&key=K',
            'https://x.test/?token=T',
            'https://x.test/verify/abc',
            'https://x.test/magic/abc',
            'https://x.test/confirm/abc',
            'https://x.test/reset/abc',
            'https://x.test/invoice?sig=S&id=1',
            'https://x.test/?action=resetpass',
            'https://x.test/download/file.zip',
        ] as $url) {
            $this->assertSame('See [redacted-link] now', Email_Log_Pack::redact_links('See ' . $url . ' now'), $url);
        }
        $this->assertSame('See https://x.test/blog?p=3 now', Email_Log_Pack::redact_links('See https://x.test/blog?p=3 now'));
        foreach ([
            'Your password is: P@ss!word99' => 'P@ss!word99',
            'Your temporary password is Hunter2!' => 'Hunter2',
            'password: Tr0ub4dor&3' => 'Tr0ub4dor',
            'Use code 482913 to log in' => '482913',
            'Your OTP is 99 12 77' => '99',
            'api key = sk_live_abcdef' => 'sk_live',
            'See https://x.test/auth/Zm9vYmFyYmF6cXV4 now' => 'Zm9vYmFy',
            'See https://x.test/a?k=Zm9vYmFyYmF6cXV4 now' => 'Zm9vYmFy',
            'See https://x.test/p/3f2504e0-4f89-11d3-9a0c-0305e82c3301 now' => '3f2504e0',
        ] as $text => $secret) {
            $out = Email_Log_Pack::redact_links($text);
            $this->assertStringNotContainsString($secret, $out, $text . ' => ' . $out);
        }
        $this->assertSame('See https://x.test/my-long-post-title-here now', Email_Log_Pack::redact_links('See https://x.test/my-long-post-title-here now'));
        $this->assertSame('a***@x.test, b***@y.test', Email_Log_Pack::mask_recipients('"A, B" <ada@x.test>; bob@y.test'));
        $this->assertSame('', Email_Log_Pack::mask_recipients(''));
    }

    public function test_a_link_after_a_secret_word_stays_a_redacted_link_and_later_secrets_are_still_redacted(): void
    {
        $body = 'Reset your password: https://x.test/wp-login.php?action=rp&key=SENTINEL-KEY&login=ada Your verification code is 482913.';
        $this->assertSame('Reset your password: [redacted-link] Your verification code is [redacted]', Email_Log_Pack::redact_links($body));
        $this->assertSame('Your password is [redacted-link] now', Email_Log_Pack::redact_links('Your password is https://x.test/?token=T now'));
        $this->assertSame('token=[redacted-link]', Email_Log_Pack::redact_links('token=https://x.test/reset/abc'));
        $this->assertSame('password: [redacted]', Email_Log_Pack::redact_links('password: Hunter2'));
    }

    /** #953: a word after "password" is only a secret after a label (":", "=", "is") or when it carries a digit. */
    public function test_password_reset_wording_stays_readable_and_labelled_values_are_still_redacted(): void
    {
        $this->assertSame('[WP MCP Test] Password Reset', Email_Log_Pack::redact_links('[WP MCP Test] Password Reset'));
        $body = "Someone has requested a password reset for the following account:\n\n"
            . "To reset your password, visit the following address:\n\n"
            . "https://x.test/wp-login.php?action=rp&key=SENTINEL-KEY&login=ada\n\n"
            . "This password reset request originated from the IP address 203.0.113.9.\n"
            . "Your password: Hunter2secret\nYour code token reset";
        $this->assertSame(
            "Someone has requested a password reset for the following account:\n\n"
            . "To reset your password, visit the following address:\n\n"
            . "[redacted-link]\n\n"
            . "This password reset request originated from the IP address 203.0.113.9.\n"
            . "Your password: [redacted]\nYour code token reset",
            Email_Log_Pack::redact_links($body)
        );
        $this->assertSame('password=[redacted] next', Email_Log_Pack::redact_links('password=s3cret next'));
        $this->assertSame('Your secret is [redacted]', Email_Log_Pack::redact_links('Your secret is opensesame'));
        $this->assertSame('Use code [redacted] to log in', Email_Log_Pack::redact_links('Use code 482913 to log in'));
        $this->assertSame('Use PIN [redacted] today', Email_Log_Pack::redact_links('Use PIN A9Z7 today'));
        $this->assertSame('Password protected post', Email_Log_Pack::redact_links('Password protected post'));
    }

    public function test_filters_search_recipient_and_subject_escape_like_wildcards_and_page(): void
    {
        wpmcp_test_wpml_mail([ 'receiver' => 'ada@example.com', 'subject' => 'Order 100% received', 'timestamp' => '2026-11-10 09:00:00' ]);
        wpmcp_test_wpml_mail([ 'receiver' => 'bob_smith@example.org', 'subject' => 'Order shipped', 'timestamp' => '2026-11-12 09:00:00' ]);
        wpmcp_test_wpml_mail([ 'receiver' => 'bobXsmith@example.org', 'subject' => 'Welcome', 'timestamp' => '2026-11-14 09:00:00' ]);

        $this->assertSame(1, $this->ok($this->read('emaillog-list-emails', [ 'recipient' => 'bob_smith' ]))['total'], 'an underscore is not a wildcard');
        $this->assertSame(1, $this->ok($this->read('emaillog-list-emails', [ 'subject' => '100%' ]))['total'], 'a percent sign is not a wildcard');
        $this->assertSame(2, $this->ok($this->read('emaillog-list-emails', [ 'subject' => 'Order' ]))['total']);
        $this->assertSame(1, $this->ok($this->read('emaillog-list-emails', [ 'since' => '2026-11-11', 'until' => '2026-11-13' ]))['total']);

        $page = $this->ok($this->read('emaillog-list-emails', [ 'page_size' => 2 ]));
        $this->assertCount(2, $page['emails']);
        $this->assertTrue($page['hasMore']);
        $rest = $this->ok($this->read('emaillog-list-emails', [ 'page_size' => 2, 'offset' => 2 ]));
        $this->assertCount(1, $rest['emails']);
        $this->assertFalse($rest['hasMore']);
    }

    public function test_check_and_log_email_uses_result_and_never_returns_the_ip(): void
    {
        $this->only('wpmcp_check_email_active');
        $ok     = wpmcp_test_check_email_mail([ 'result' => 1, 'ip_address' => '203.0.113.7' ]);
        $failed = wpmcp_test_check_email_mail([ 'result' => 0, 'error_message' => 'Mailer said no', 'attachments' => 'true', 'attachment_name' => '/srv/files/a.pdf,/srv/files/b.pdf', 'sent_date' => '2026-11-11 10:00:00' ]);

        $list = $this->ok($this->read('emaillog-list-emails'));
        $this->assertSame('check-email', $list['source']);
        $this->assertSame([ $failed, $ok ], array_column($list['emails'], 'id'));
        $this->assertSame([ 'failed', 'sent' ], array_column($list['emails'], 'status'));
        $this->assertSame([ true, false ], array_column($list['emails'], 'has_attachments'));
        $this->assertSame('Mailer said no', $list['emails'][0]['error']);
        $this->assertSame([ $failed ], array_column($this->ok($this->read('emaillog-list-emails', [ 'status' => 'failed' ]))['emails'], 'id'));

        $one = $this->ok($this->read('emaillog-get-email', [ 'id' => $failed ]))['email'];
        $this->assertSame([ 'a.pdf', 'b.pdf' ], $one['attachments']);
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($this->ok($this->read('emaillog-get-email', [ 'id' => $ok ]))));
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($list));
    }

    public function test_email_log_reads_result_and_never_returns_its_ip_address(): void
    {
        $this->only('wpmcp_email_log_active');
        $ok     = wpmcp_test_email_log_mail();
        $failed = wpmcp_test_email_log_mail([ 'result' => 0, 'error_message' => 'Could not instantiate mail function.', 'sent_date' => '2026-11-11 10:00:00' ]);

        $list = $this->ok($this->read('emaillog-list-emails'));
        $this->assertSame('email-log', $list['source']);
        $this->assertSame([ 'failed', 'sent' ], array_column($list['emails'], 'status'));
        $this->assertSame('Could not instantiate mail function.', $list['emails'][0]['error']);
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($list));
        $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($this->ok($this->read('emaillog-get-email', [ 'id' => $ok ]))));
        $this->assertSame('failed', $this->ok($this->read('emaillog-get-email', [ 'id' => $failed ]))['email']['status']);
    }

    public function test_several_plugins_active_need_a_source(): void
    {
        add_filter('wpmcp_email_log_active', '__return_true');
        wpmcp_test_wpml_mail();
        wpmcp_test_email_log_mail();

        $this->assertSame('source_required', $this->code($this->read('emaillog-list-emails')));
        $this->assertSame('source_required', $this->code($this->read('emaillog-get-email', [ 'id' => 1 ])));
        $this->assertSame('email-log', $this->ok($this->read('emaillog-list-emails', [ 'source' => 'email-log' ]))['source']);
        $this->assertSame('wp-mail-logging', $this->ok($this->read('emaillog-list-emails', [ 'source' => 'wp-mail-logging' ]))['source']);
    }

    public function test_refusals_name_what_is_wrong(): void
    {
        $out = $this->read('emaillog-get-email', [ 'id' => 99999 ]);
        $this->assertSame('email_not_found', $this->code($out), (string) wp_json_encode($out));

        $this->assertSame('invalid_date', $this->code($this->read('emaillog-list-emails', [ 'since' => 'not a date at all' ])));

        $out = $this->read('emaillog-list-emails', [ 'source' => 'email-log' ]);
        $this->assertSame('plugin_missing', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('Email Log', (string) wp_json_encode($out));
    }

    public function test_without_any_plugin_the_ops_answer_plugin_missing(): void
    {
        remove_all_filters('wpmcp_wp_mail_logging_active');
        add_filter('wpmcp_wp_mail_logging_active', '__return_false');
        $out = $this->read('emaillog-list-emails');
        $this->assertContains($this->code($out), [ 'plugin_missing', 'plugin_inactive' ], (string) wp_json_encode($out));
        $this->assertStringContainsString('WP Mail Logging', (string) wp_json_encode($out));
    }

    public function test_the_ops_never_read_an_option(): void
    {
        update_option('wpmcp_test_smtp_password', 'SMTP-SENTINEL');
        wpmcp_test_wpml_mail([ 'headers' => 'Content-Type: text/plain' ]);
        $json = (string) wp_json_encode([ $this->read('emaillog-list-emails'), $this->read('emaillog-get-email', [ 'id' => 1 ]) ]);
        $this->assertStringNotContainsString('SMTP-SENTINEL', $json);
        delete_option('wpmcp_test_smtp_password');
    }
}
