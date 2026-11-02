<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMailSMTP\Options;
use WPMailSMTP\Pro\Emails\Logs\EmailsCollection;
use WPMailSMTP\Pro\Emails\Logs\Logs;
use WPMCP\Integrations\WP_Mail_SMTP_Integration;

require_once __DIR__ . '/../../support/wp-mail-smtp-fakes.php';

/**
 * WP Mail SMTP integration (wpmcp/wpmailsmtp-read and -write), exercised
 * against the test doubles in tests/support/wp-mail-smtp-fakes.php. The
 * contract under test: the active mailer and its configuration come back
 * with every credential masked, the Pro email log is read only when logging
 * is on, the test send reuses the shared Mail_Check, and the one write
 * (from name and address) is snapshot-wrapped on the wp_mail_smtp option.
 */
class WpMailSmtpIntegrationTest extends \WP_UnitTestCase
{
    private WP_Mail_SMTP_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Options::$data           = [
            'mail' => [
                'mailer'      => 'smtp',
                'from_email'  => 'hello@example.test',
                'from_name'   => 'Example Site',
                'return_path' => true,
            ],
            'smtp' => [
                'host'    => 'smtp.example.test',
                'port'    => 587,
                'encryption' => 'tls',
                'auth'    => true,
                'user'    => 'mailer@example.test',
                'pass'    => 'SuperSecretPassword123',
            ],
            'sendgrid' => [
                'api_key' => 'SG.abcdefghijklmnopqrstuvwxyz',
                'domain'  => 'example.test',
            ],
            'outlook' => [
                'client_id'     => 'client-id-value',
                'client_secret' => 'client-secret-value',
                'access_token'  => [ 'token' => 'oauth-access-token' ],
            ],
        ];
        Logs::$enabled           = false;
        EmailsCollection::$rows  = [];
        update_option('wp_mail_smtp', Options::$data);
        $this->integration = new WP_Mail_SMTP_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        Options::$data          = [];
        Logs::$enabled          = false;
        EmailsCollection::$rows = [];
        delete_option('wp_mail_smtp');
        parent::tearDown();
    }

    public function test_pair_is_named_wpmailsmtp_and_is_free(): void
    {
        $this->assertSame('wpmailsmtp', $this->integration->integration());
        $this->assertSame('free', $this->integration->tier());
    }

    public function test_available_when_container_function_exists(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_get_config_reports_mailer_and_from_identity(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-config' ]);

        $this->assertArrayNotHasKey('error', $out);
        $config = $out['result']['config'];
        $this->assertSame('smtp', $config['mailer']);
        $this->assertSame('hello@example.test', $config['from_email']);
        $this->assertSame('Example Site', $config['from_name']);
        $this->assertSame('smtp.example.test', $config['mailer_settings']['host']);
        $this->assertSame(587, (int) $config['mailer_settings']['port']);
        $this->assertSame('mailer@example.test', $config['mailer_settings']['user']);
    }

    public function test_get_config_masks_every_secret_and_never_leaks_their_values(): void
    {
        $out  = $this->integration->handle_read([ 'operation' => 'get-config', 'args' => [ 'all_mailers' => true ] ]);
        $json = wp_json_encode($out);

        $this->assertSame(WP_Mail_SMTP_Integration::MASK, $out['result']['config']['mailer_settings']['pass']);
        $this->assertSame(WP_Mail_SMTP_Integration::MASK, $out['result']['config']['all_mailers']['sendgrid']['api_key']);
        $this->assertSame(WP_Mail_SMTP_Integration::MASK, $out['result']['config']['all_mailers']['outlook']['client_secret']);
        $this->assertSame(WP_Mail_SMTP_Integration::MASK, $out['result']['config']['all_mailers']['outlook']['access_token']);
        // Non-secret keys of other mailers survive.
        $this->assertSame('example.test', $out['result']['config']['all_mailers']['sendgrid']['domain']);

        foreach ([ 'SuperSecretPassword123', 'SG.abcdefghijklmnopqrstuvwxyz', 'client-secret-value', 'oauth-access-token' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "secret {$secret} leaked");
        }
    }

    public function test_get_log_reports_logging_off_when_pro_logging_is_disabled(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-log' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertFalse($out['result']['logging_enabled']);
        $this->assertSame([], $out['result']['emails']);
    }

    public function test_get_log_returns_last_n_sends_with_status_and_error(): void
    {
        Logs::$enabled          = true;
        EmailsCollection::$rows = [
            [ 'id' => 1, 'subject' => 'Old', 'to' => [ 'a@example.test' ], 'status' => 1, 'date_sent' => '2026-10-01 10:00:00' ],
            [ 'id' => 2, 'subject' => 'Failed', 'to' => [ 'b@example.test' ], 'status' => 0, 'error_text' => 'SMTP connect() failed', 'date_sent' => '2026-10-02 10:00:00' ],
            [ 'id' => 3, 'subject' => 'Newest', 'to' => [ 'c@example.test' ], 'status' => 1, 'date_sent' => '2026-10-03 10:00:00' ],
        ];

        $out = $this->integration->handle_read([ 'operation' => 'get-log', 'args' => [ 'limit' => 2 ] ]);

        $this->assertTrue($out['result']['logging_enabled']);
        $this->assertCount(2, $out['result']['emails']);
        $this->assertSame(3, $out['result']['emails'][0]['id']);
        $this->assertSame('sent', $out['result']['emails'][0]['status']);
        $this->assertSame('failed', $out['result']['emails'][1]['status']);
        $this->assertSame('SMTP connect() failed', $out['result']['emails'][1]['error']);
    }

    public function test_send_test_without_confirm_reports_transport_but_does_not_send(): void
    {
        $sent = 0;
        add_filter('pre_wp_mail', static function () use (&$sent) {
            $sent++;
            return true;
        });

        $out = $this->integration->handle_read([ 'operation' => 'send-test', 'args' => [ 'recipient' => wp_get_current_user()->user_email ] ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertArrayHasKey('transport', $out['result']);
        $this->assertSame(0, $sent, 'an unconfirmed test must not send');
    }

    public function test_set_from_updates_option_and_snapshots_it(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'set-from',
            'args'      => [ 'from_email' => 'news@example.test', 'from_name' => 'Newsroom' ],
        ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('news@example.test', $out['result']['from_email']);
        $this->assertSame('Newsroom', $out['result']['from_name']);
        $this->assertNotEmpty($out['operation_id'] ?? null, 'a from change must be snapshot-wrapped');
        $this->assertSame('news@example.test', Options::init()->get('mail', 'from_email'));
    }

    public function test_set_from_rejects_invalid_email_without_side_effects(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'set-from',
            'args'      => [ 'from_email' => 'not-an-email' ],
        ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame('hello@example.test', Options::init()->get('mail', 'from_email'));
    }
}
