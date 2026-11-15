<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;

/**
 * Issue #905: the email log ops against the real WP Mail Logging, Check and
 * Log Email and Email Log, all free on wordpress.org.
 *
 * It runs only in the local gate's live email log leg
 * (bin/test-local.sh --live-email-log), which sets WPMCP_LIVE_EMAIL_LOG=1,
 * installs the three plugins from wordpress.org onto a separate WordPress
 * install and loads them in tests/bootstrap.php. Mails go through wp_mail(),
 * so each plugin logs them with its own code, and the ops read them back.
 * The test case replaces PHPMailer, so a failed send is reported the way
 * wp_mail() does it: pre_wp_mail answers false and wp_mail_failed fires with
 * the mail as its error data.
 *
 * Everywhere else the plugins are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group email-log-live
 */
class EmailLogLiveTest extends \WP_UnitTestCase
{
    private const RESET = 'https://example.com/wp-login.php?action=rp&key=LIVE-RESET-SENTINEL&login=ada';

    private const SOURCES = [ 'wp-mail-logging', 'check-email', 'email-log' ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WP_MAIL_LOGGING_PLUGIN_DIR') || ! defined('CK_MAIL_VERSION') || ! defined('EMAIL_LOG_FILE') || ! function_exists('email_log') || ! function_exists('wpchill_check_email')) {
            $this->markTestSkipped('Needs the real WP Mail Logging, Check and Log Email and Email Log (bin/test-local.sh --live-email-log).');
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_wp_mail');
        unset($GLOBALS['wpml_current_mail_id']);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    /** Sends three mails through wp_mail(): two that go out, one that fails. */
    private function send_three(): void
    {
        add_filter('pre_wp_mail', static fn ($pre) => true);
        wp_mail('ada@example.com', 'Welcome Ada', 'Reset your password: ' . self::RESET . ' Your verification code is 482913.', [ 'Content-Type: text/plain', 'X-Api-Key: LIVE-HEADER-SENTINEL' ]);
        wp_mail('bob.smith@example.org', 'Receipt 42', 'Thanks for your order.');
        remove_all_filters('pre_wp_mail');

        add_filter('pre_wp_mail', static fn ($pre) => false);
        $atts = [ 'to' => [ 'carol@example.net' ], 'subject' => 'Failing mail', 'message' => 'This one fails.', 'headers' => '', 'attachments' => [] ];
        wp_mail($atts['to'][0], $atts['subject'], $atts['message']);
        do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', 'SMTP connect() failed.', $atts));
        remove_all_filters('pre_wp_mail');
    }

    public function test_every_plugin_lists_the_three_mails_with_the_right_status_and_masked_recipients(): void
    {
        $this->send_three();

        foreach (self::SOURCES as $source) {
            $list = $this->read('emaillog-list-emails', [ 'source' => $source ]);
            $this->assertSame(3, $list['total'], $source . ' ' . wp_json_encode($list));
            $this->assertTrue($list['masked']);
            $by = array_column($list['emails'], null, 'subject');
            $this->assertSame('sent', $by['Welcome Ada']['status'], $source);
            $this->assertSame('sent', $by['Receipt 42']['status'], $source);
            $this->assertSame('failed', $by['Failing mail']['status'], $source);
            $this->assertNotSame('', $by['Failing mail']['error'], $source);
            $this->assertSame('a***@example.com', $by['Welcome Ada']['recipient'], $source);
            $this->assertSame('b***@example.org', $by['Receipt 42']['recipient'], $source);

            $failed = $this->read('emaillog-list-emails', [ 'source' => $source, 'status' => 'failed' ]);
            $this->assertSame([ 'Failing mail' ], array_column($failed['emails'], 'subject'), $source);
            $this->assertSame(2, $this->read('emaillog-list-emails', [ 'source' => $source, 'status' => 'sent' ])['total'], $source);
            $this->assertSame(1, $this->read('emaillog-list-emails', [ 'source' => $source, 'recipient' => 'bob.smith' ])['total'], $source);

            $json = (string) wp_json_encode($list);
            foreach ([ 'ada@example.com', 'bob.smith', 'carol@example.net', 'LIVE-RESET-SENTINEL', '203.0.113.7', 'Reset your password' ] as $leak) {
                $this->assertStringNotContainsString($leak, $json, $source . ' ' . $leak);
            }
        }
    }

    public function test_every_plugin_returns_one_mail_with_links_and_credentials_removed(): void
    {
        $this->send_three();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'email_log', [ 'ip_address' => '203.0.113.7' ], [ 'subject' => 'Welcome Ada' ]);

        foreach (self::SOURCES as $source) {
            $list = $this->read('emaillog-list-emails', [ 'source' => $source, 'subject' => 'Welcome' ]);
            $this->assertSame(1, $list['total'], $source);
            $one = $this->read('emaillog-get-email', [ 'source' => $source, 'id' => $list['emails'][0]['id'] ])['email'];
            $this->assertStringContainsString('ada@example.com', $one['recipient'], $source);
            $this->assertSame('sent', $one['status'], $source);
            $this->assertStringContainsString('[redacted-link]', $one['body'], $source);
            $this->assertStringContainsString('Reset your password', $one['body'], $source);
            $this->assertStringNotContainsString('482913', $one['body'], $source);
            $json = (string) wp_json_encode($one);
            foreach ([ 'LIVE-RESET-SENTINEL', 'LIVE-HEADER-SENTINEL', '203.0.113.7' ] as $leak) {
                $this->assertStringNotContainsString($leak, $json, $source . ' ' . $leak);
            }
        }
    }

    public function test_several_plugins_active_ask_for_a_source(): void
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => 'emaillog-list-emails', 'args' => [] ]);
        $this->assertSame('source_required', $out['error']['code'] ?? $out['code'] ?? null, (string) wp_json_encode($out));
    }
}
