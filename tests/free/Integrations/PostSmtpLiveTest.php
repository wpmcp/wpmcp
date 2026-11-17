<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Diagnostics\Mail_Check;

/**
 * The Post SMTP ops on the theme pair against the real Post SMTP: its
 * PostmanOptions singleton (the option it reads and the reload after a write),
 * its base64 credential storage and its post_smtp_logs table.
 *
 * It runs only in the local gate's live Post SMTP leg (bin/test-local.sh, or
 * bin/test-local.sh --live-post-smtp alone), which sets WPMCP_LIVE_POST_SMTP=1,
 * downloads Post SMTP from wordpress.org onto a separate WordPress install and
 * loads it in tests/bootstrap.php. Everywhere else the plugin is absent and the
 * test is skipped; the live leg runs with --fail-on-skipped so it cannot pass
 * vacuously.
 *
 * No mail leaves the machine: the transport is "smtp" pointed at port 1 on
 * 127.0.0.1, and no test sends.
 *
 * @group post-smtp-live
 */
class PostSmtpLiveTest extends \WP_UnitTestCase
{
    private const PASSWORD = 'Live-Post-Password-42';
    private const API_KEY  = 'SG.live-post-smtp-api-key-0123';

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('POST_SMTP_VER') || ! class_exists('PostmanOptions')) {
            $this->markTestSkipped('Needs the real Post SMTP (bin/test-local.sh --live-post-smtp, WPMCP_LIVE_POST_SMTP=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'ada@example.test' ]));
        Mail_Check::reset_limit(get_current_user_id());

        update_option('postman_options', [
            'transport_type' => 'smtp', 'sender_email' => 'hello@example.test', 'sender_name' => 'Example Site',
            'hostname' => '127.0.0.1', 'port' => '1', 'enc_type' => 'none', 'auth_type' => 'login',
            'basic_auth_username' => 'live-mailer@example.test', 'basic_auth_password' => base64_encode(self::PASSWORD),
            'sendgrid_api_key' => base64_encode(self::API_KEY), 'mail_log_enabled' => 'true',
        ]);
        \PostmanOptions::getInstance()->reload();

        global $wpdb;
        $table = $wpdb->prefix . 'post_smtp_logs';
        if (! $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) && class_exists('PostmanEmailLogs')) {
            \PostmanEmailLogs::get_instance()->install_table();
        }
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []));
    }

    public function test_the_real_plugin_is_detected_and_its_config_is_read_without_a_secret(): void
    {
        $result = $this->read('get-post-smtp-config');

        $this->assertTrue($result['configured']);
        $this->assertSame('smtp', $result['transport']);
        $this->assertSame('127.0.0.1', $result['settings']['hostname']);
        $this->assertContains('basic_auth_password', $result['secrets_set']);

        $json = (string) wp_json_encode($result);
        foreach ([ self::PASSWORD, base64_encode(self::PASSWORD), self::API_KEY, base64_encode(self::API_KEY), 'live-mailer@example.test' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_the_log_reads_the_real_table_without_the_body_or_transcript(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'post_smtp_logs', [
            'success' => 'refused for ' . self::PASSWORD, 'from_header' => 'hello@example.test', 'to_header' => 'jane.doe@example.org',
            'original_subject' => 'Live row', 'original_message' => 'LIVE BODY TEXT', 'session_transcript' => 'AUTH ' . base64_encode(self::PASSWORD),
            'transport_uri' => 'smtp://127.0.0.1:1', 'time' => time(),
        ]);

        $result = $this->read('get-post-smtp-log');
        $json   = (string) wp_json_encode($result);

        $this->assertSame('Live row', $result['emails'][0]['subject']);
        $this->assertSame('failed', $result['emails'][0]['status']);
        $this->assertSame([ 'j***@example.org' ], $result['emails'][0]['to']);
        foreach ([ 'LIVE BODY TEXT', 'AUTH ', self::PASSWORD ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_set_from_changes_the_real_option_the_plugin_reads_and_rolls_back_exactly(): void
    {
        add_filter('wpmcp_enable_smtp_write', '__return_true');
        $before = get_option('postman_options');

        $out = $this->write('set-post-smtp-from', [ 'from_email' => 'new@example.test', 'from_name' => 'Live Name' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('new@example.test', \PostmanOptions::getInstance()->getMessageSenderEmail(), 'the plugin re-read its option');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('postman_options'));

        remove_all_filters('wpmcp_enable_smtp_write');
    }

    public function test_the_test_send_and_the_write_are_off_by_default_and_unconfirmed_sends_nothing(): void
    {
        $this->assertSame('opt_in_required', $this->write('send-post-smtp-test', [ 'recipient' => 'ada@example.test' ], true)['error']['code'] ?? null);
        $this->assertSame('opt_in_required', $this->write('set-post-smtp-from', [ 'from_name' => 'X' ])['error']['code'] ?? null);

        add_filter('wpmcp_enable_smtp_write', '__return_true');
        $this->assertSame('confirmation_required', $this->write('send-post-smtp-test', [ 'recipient' => 'ada@example.test' ])['error']['code'] ?? null);
        remove_all_filters('wpmcp_enable_smtp_write');
    }
}
