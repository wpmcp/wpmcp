<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Diagnostics\Mail_Check;

/**
 * The FluentSMTP ops on the theme pair against the real FluentSMTP: its own
 * fluentMailGetSettings() and fluentMailSetSettings() (encryption at rest),
 * its SecretMasker lists and its email log table.
 *
 * It runs only in the local gate's live FluentSMTP leg (bin/test-local.sh, or
 * bin/test-local.sh --live-fluent-smtp alone), which sets
 * WPMCP_LIVE_FLUENT_SMTP=1, downloads FluentSMTP from wordpress.org onto a
 * separate WordPress install and loads it in tests/bootstrap.php. Everywhere
 * else the plugin is absent and the test is skipped; the live leg runs with
 * --fail-on-skipped so it cannot pass vacuously.
 *
 * No mail leaves the machine: the connection is "Other SMTP" pointed at port 1
 * on 127.0.0.1, and no test sends.
 *
 * @group fluent-smtp-live
 */
class FluentSmtpLiveTest extends \WP_UnitTestCase
{
    private const PASSWORD = 'Live-Fluent-Password-42';
    private const USERNAME = 'live-mailer@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('FLUENTMAIL') || ! function_exists('fluentMailSetSettings') || ! class_exists('FluentMail\\App\\Services\\SecretMasker')) {
            $this->markTestSkipped('Needs the real FluentSMTP (bin/test-local.sh --live-fluent-smtp, WPMCP_LIVE_FLUENT_SMTP=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'ada@example.test' ]));
        Mail_Check::reset_limit(get_current_user_id());

        fluentMailSetSettings([
            'connections' => [
                'live-key' => [ 'title' => 'Other SMTP', 'provider_settings' => [
                    'provider' => 'smtp', 'sender_email' => 'hello@example.test', 'sender_name' => 'Example Site', 'force_from_email' => 'yes',
                    'host' => '127.0.0.1', 'port' => '1', 'encryption' => 'none', 'auto_tls' => 'no', 'auth' => 'yes', 'key_store' => 'db',
                    'username' => self::USERNAME, 'password' => self::PASSWORD,
                ] ],
            ],
            'mappings' => [ 'hello@example.test' => 'live-key' ],
            'misc'     => [ 'default_connection' => 'live-key', 'log_emails' => 'yes' ],
        ]);

        global $wpdb;
        $table = $wpdb->prefix . FLUENT_MAIL_DB_PREFIX . 'email_logs';
        if (! $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) && class_exists('FluentMailMigrations\\EmailLogs')) {
            \FluentMailMigrations\EmailLogs::migrate();
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
        $result = $this->read('get-fluent-smtp-config');

        $this->assertSame('live-key', $result['default_connection']);
        $this->assertTrue($result['encrypted_at_rest'], 'the real plugin encrypts the password at rest');
        $this->assertSame('smtp', $result['connections'][0]['provider']);
        $this->assertSame('127.0.0.1', $result['connections'][0]['provider_settings']['host']);
        $this->assertSame([ 'password' ], $result['connections'][0]['secrets_set']);

        $json   = (string) wp_json_encode($result);
        $stored = (string) wp_json_encode(get_option('fluentmail-settings'));
        $this->assertStringNotContainsString(self::PASSWORD, $json);
        $this->assertStringNotContainsString(self::USERNAME, $json);
        $this->assertStringNotContainsString(self::PASSWORD, $stored, 'the real plugin keeps it encrypted in the option');
        foreach ((array) get_option('fluentmail-settings')['connections'] as $connection) {
            $this->assertStringNotContainsString((string) $connection['provider_settings']['password'], $json, 'the ciphertext is not returned either');
        }
    }

    public function test_the_log_reads_the_real_table_without_the_body(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . FLUENT_MAIL_DB_PREFIX . 'email_logs', [
            'to' => serialize([ [ 'email' => 'jane.doe@example.org' ] ]), 'from' => 'hello@example.test', 'subject' => 'Live row', 'body' => 'LIVE BODY TEXT',
            'status' => 'failed', 'response' => serialize([ 'message' => 'refused for ' . self::PASSWORD ]), 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->read('get-fluent-smtp-log');
        $json   = (string) wp_json_encode($result);

        $this->assertSame('Live row', $result['emails'][0]['subject']);
        $this->assertSame([ 'j***@example.org' ], $result['emails'][0]['to']);
        $this->assertStringNotContainsString('LIVE BODY TEXT', $json);
        $this->assertStringNotContainsString(self::PASSWORD, $json);
    }

    public function test_set_from_goes_through_the_real_writer_and_rolls_back_exactly(): void
    {
        add_filter('wpmcp_enable_smtp_write', '__return_true');
        $before = get_option('fluentmail-settings');

        $out = $this->write('set-fluent-smtp-from', [ 'from_email' => 'hello@example.test', 'sender_name' => 'Live Name' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('Live Name', fluentMailGetSettings([], false)['connections']['live-key']['provider_settings']['sender_name']);
        $this->assertSame(self::PASSWORD, fluentMailGetSettings([], false)['connections']['live-key']['provider_settings']['password'], 'the password survives the real re-encryption');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('fluentmail-settings'));

        remove_all_filters('wpmcp_enable_smtp_write');
    }

    public function test_the_test_send_and_the_write_are_off_by_default_and_unconfirmed_sends_nothing(): void
    {
        $this->assertSame('opt_in_required', $this->write('send-fluent-smtp-test', [ 'recipient' => 'ada@example.test' ], true)['error']['code'] ?? null);
        $this->assertSame('opt_in_required', $this->write('set-fluent-smtp-from', [ 'from_email' => 'hello@example.test', 'sender_name' => 'X' ])['error']['code'] ?? null);

        add_filter('wpmcp_enable_smtp_write', '__return_true');
        $this->assertSame('confirmation_required', $this->write('send-fluent-smtp-test', [ 'recipient' => 'ada@example.test' ])['error']['code'] ?? null);
        remove_all_filters('wpmcp_enable_smtp_write');
    }
}
