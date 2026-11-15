<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Fluent_SMTP_Status;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Diagnostics\Mail_Check;
use WPMCP\Tools\Meta\Secret_Options;

require_once __DIR__ . '/../../support/fluent-smtp-double.php';

/** A class whose unserialization would be visible: the log tests prove it is never instantiated. */
class Smtp_Injection_Probe
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}


/**
 * FluentSMTP config, log, test send and sender name (Fluent_SMTP_Status): ops
 * on the theme dispatcher pair, through Ops_Status_Packs (issue #696).
 *
 * FluentSMTP is not loaded in the shared test core. Presence is driven through
 * wpmcp_fluent_smtp_active, and the plugin is the stand-in in
 * tests/support/fluent-smtp-double.php, kept the way FluentSMTP 2.4 keeps its
 * settings (one fluentmail-settings option, secret fields encrypted). The same
 * ops run against the real plugin in FluentSmtpLiveTest.
 *
 * @group fluent-smtp
 */
class FluentSmtpStatusTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-fluent-smtp-config' => 'read',
        'get-fluent-smtp-log'    => 'read',
        'send-fluent-smtp-test'  => 'destructive',
        'set-fluent-smtp-from'   => 'write',
    ];

    private const PASSWORD   = 'SuperSecretPassword123';
    private const USERNAME   = 'mailer-user@example.test';
    private const SES_SECRET = 'ses-secret-key-value-0123';
    private const SES_ACCESS = 'AKIAEXAMPLEACCESS0001';
    private const TOKEN      = 'oauth-refresh-token-value';

    private int $admin;

    private static string $table = '';

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::$table = $wpdb->prefix . 'fsmpt_email_logs';
        $wpdb->query('DROP TABLE IF EXISTS `' . self::$table . '`');
        // The columns of FluentSMTP's own migration (database/migrations/EmailLogs.php).
        $wpdb->query('CREATE TABLE `' . self::$table . '` (
            `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT, `site_id` INT UNSIGNED NULL, `to` TEXT NULL, `from` VARCHAR(255),
            `subject` VARCHAR(255), `body` LONGTEXT NULL, `headers` LONGTEXT NULL, `attachments` LONGTEXT NULL,
            `status` VARCHAR(20) DEFAULT \'pending\', `response` TEXT NULL, `extra` TEXT NULL, `retries` INT UNSIGNED NULL DEFAULT 0,
            `resent_count` INT UNSIGNED NULL DEFAULT 0, `source` VARCHAR(255) NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL)');
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::$table . '`');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        global $wpdb;
        $wpdb->query('DELETE FROM `' . self::$table . '`');

        fluentMailSetSettings([
            'connections' => [
                'k-smtp' => [ 'title' => 'Other SMTP', 'provider_settings' => [
                    'provider' => 'smtp', 'sender_email' => 'hello@example.test', 'sender_name' => 'Example Site', 'force_from_email' => 'yes',
                    'host' => 'smtp.example.test', 'port' => '587', 'encryption' => 'tls', 'auto_tls' => 'yes', 'auth' => 'yes', 'key_store' => 'db',
                    'username' => self::USERNAME, 'password' => self::PASSWORD,
                ] ],
                'k-ses'  => [ 'title' => 'Amazon SES', 'provider_settings' => [
                    'provider' => 'ses', 'sender_email' => 'orders@example.test', 'sender_name' => 'Orders', 'region' => 'us-east-1',
                    'access_key' => self::SES_ACCESS, 'secret_key' => self::SES_SECRET,
                ] ],
                'k-gmail' => [ 'title' => 'Gmail', 'provider_settings' => [
                    'provider' => 'gmail', 'sender_email' => 'news@example.test', 'sender_name' => 'News', 'client_id' => 'gmail-client-id-value',
                    'client_secret' => 'gmail-client-secret-value', 'access_token' => 'gmail-access-token-value', 'refresh_token' => self::TOKEN,
                ] ],
            ],
            'mappings' => [ 'hello@example.test' => 'k-smtp', 'support@example.test' => 'k-smtp', 'orders@example.test' => 'k-ses', 'news@example.test' => 'k-gmail' ],
            'misc'     => [ 'default_connection' => 'k-smtp', 'log_emails' => 'yes', 'log_saved_interval_days' => '14' ],
        ]);
        update_option('_fluentsmtp_connection_health', [
            'k-smtp' => [ 'sender_email' => 'hello@example.test', 'provider' => 'smtp', 'status' => 'error', 'message' => 'Login failed for ' . self::USERNAME . ' with ' . self::PASSWORD, 'checked_at' => '2026-11-14 10:00:00' ],
        ]);

        add_filter('wpmcp_fluent_smtp_active', '__return_true');
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'ada@example.test' ]);
        wp_set_current_user($this->admin);
        Mail_Check::reset_limit($this->admin);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_fluent_smtp_active');
        remove_all_filters('wpmcp_enable_smtp_write');
        remove_all_filters('pre_wp_mail');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []));
    }

    private function enable_writes(): void
    {
        add_filter('wpmcp_enable_smtp_write', '__return_true');
    }

    private function assertLeaksNothing($payload): void
    {
        $json = (string) wp_json_encode($payload);
        $raw  = (array) get_option('fluentmail-settings');
        $needles = [ self::PASSWORD, self::SES_SECRET, self::SES_ACCESS, self::TOKEN, 'gmail-client-secret-value', 'gmail-access-token-value', 'gmail-client-id-value' ];
        foreach ($raw['connections'] as $connection) {
            foreach ([ 'password', 'secret_key', 'access_key', 'client_secret', 'access_token', 'refresh_token' ] as $field) {
                if (! empty($connection['provider_settings'][ $field ])) {
                    $needles[] = $connection['provider_settings'][ $field ];
                }
            }
        }
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the answer must not carry {$needle}");
        }
    }

    private function log_row(array $row): void
    {
        global $wpdb;
        $wpdb->insert(self::$table, $row + [ 'status' => 'sent', 'created_at' => '2026-11-15 09:00:00', 'subject' => 'Hello', 'from' => 'hello@example.test', 'to' => serialize([ [ 'email' => 'jane.doe@example.org', 'name' => 'Jane Doe' ] ]), 'body' => 'SECRET BODY TEXT', 'response' => '' ]);
    }

    // ---------------------------------------------------------------
    // Catalog and presence
    // ---------------------------------------------------------------

    public function test_every_op_is_a_manage_options_op_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame($mode, $ops[ $name ]['mode']);
            $this->assertSame('manage_options', $ops[ $name ]['capability']);
            $this->assertTrue($ops[ $name ]['dependency_met']);
        }
        $this->assertTrue($ops['send-fluent-smtp-test']['requires_confirm']);
    }

    public function test_every_op_names_fluentsmtp_and_changes_nothing_while_it_is_inactive(): void
    {
        remove_all_filters('wpmcp_fluent_smtp_active');
        add_filter('wpmcp_fluent_smtp_active', '__return_false');
        $this->enable_writes();
        $before = get_option('fluentmail-settings');
        $rows   = Snapshot_Store::row_count();

        foreach (self::OPS as $op => $mode) {
            $this->assertFalse($this->catalog()[ $op ]['dependency_met'], "{$op} must be flagged while FluentSMTP is inactive");
            $args = 'send-fluent-smtp-test' === $op ? [ 'recipient' => 'ada@example.test' ] : ('set-fluent-smtp-from' === $op ? [ 'from_email' => 'hello@example.test', 'sender_name' => 'X' ] : []);
            $out  = 'read' === $mode ? $this->read($op, $args) : $this->write($op, $args, true);
            $this->assertSame('fluent_smtp_inactive', $out['error']['code'] ?? null, "{$op} must answer fluent_smtp_inactive");
            $this->assertStringContainsString('FluentSMTP', $out['error']['message']);
        }

        $this->assertSame($before, get_option('fluentmail-settings'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_the_plugins_constant(): void
    {
        remove_all_filters('wpmcp_fluent_smtp_active');

        $this->assertSame(defined('FLUENTMAIL'), $this->catalog()['get-fluent-smtp-config']['dependency_met']);
    }

    public function test_every_op_refuses_a_user_without_manage_options(): void
    {
        $this->enable_writes();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach (self::OPS as $op => $mode) {
            $args = 'send-fluent-smtp-test' === $op ? [ 'recipient' => 'ada@example.test' ] : ('set-fluent-smtp-from' === $op ? [ 'from_email' => 'hello@example.test', 'sender_name' => 'X' ] : []);
            $out  = 'read' === $mode ? $this->read($op, $args) : $this->write($op, $args, true);
            $this->assertContains($out['error']['code'] ?? null, [ 'operation_denied', 'forbidden' ], "{$op} runs at manage_options");
        }
    }

    // ---------------------------------------------------------------
    // get-fluent-smtp-config
    // ---------------------------------------------------------------

    public function test_config_reports_connections_routing_and_health_without_a_secret(): void
    {
        $out = $this->read('get-fluent-smtp-config');

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $result = $out['result'];
        $this->assertSame('k-smtp', $result['default_connection']);
        $this->assertTrue($result['encrypted_at_rest']);
        $this->assertCount(3, $result['connections']);

        $smtp = array_column($result['connections'], null, 'id')['k-smtp'];
        $this->assertSame('smtp', $smtp['provider']);
        $this->assertSame('hello@example.test', $smtp['sender_email']);
        $this->assertSame('Example Site', $smtp['sender_name']);
        $this->assertSame([ 'support@example.test' ], $smtp['additional_senders']);
        $this->assertSame([ 'password' ], $smtp['secrets_set']);
        $this->assertSame('smtp.example.test', $smtp['provider_settings']['host']);
        $this->assertSame('587', $smtp['provider_settings']['port']);
        $this->assertTrue($smtp['provider_settings']['authentication']);
        $this->assertSame('database', $smtp['provider_settings']['stored_in']);
        $this->assertSame('error', $smtp['health']['status']);
        $this->assertSame('2026-11-14T10:00:00Z', $smtp['health']['checked_at']);

        $ses = array_column($result['connections'], null, 'id')['k-ses'];
        $this->assertEqualsCanonicalizing([ 'access_key', 'secret_key' ], $ses['secrets_set']);
        $this->assertSame('us-east-1', $ses['provider_settings']['region']);
        $this->assertNull($ses['health']);
        $this->assertSame('yes', $result['misc']['log_emails']);
    }

    public function test_config_never_leaks_a_credential_even_in_the_health_message(): void
    {
        $out = $this->read('get-fluent-smtp-config');

        $this->assertLeaksNothing($out);
        $smtp = array_column($out['result']['connections'], null, 'id')['k-smtp'];
        $this->assertStringNotContainsString(self::PASSWORD, $smtp['health']['message']);
        $this->assertStringContainsString('[redacted]', $smtp['health']['message']);
    }

    public function test_config_scrubs_a_credential_stored_under_a_new_unlisted_key_by_registry_or_heuristic(): void
    {
        $settings = fluentMailGetSettings([], false);
        $settings['connections']['k-smtp']['provider_settings']['webhook_secret'] = 'brand-new-secret-value-77';
        fluentMailSetSettings($settings);

        $json = (string) wp_json_encode($this->read('get-fluent-smtp-config'));

        $this->assertStringNotContainsString('brand-new-secret-value-77', $json, 'the key-name heuristic masks a key nobody registered');
    }

    public function test_the_option_is_in_the_secret_registry_and_masked_by_get_option(): void
    {
        $this->assertTrue(Secret_Options::is_registered('fluentmail-settings'));
        $this->assertTrue(Secret_Options::is_registered('_fluent_smtp_notify_settings'));

        $masked = (string) wp_json_encode(Secret_Options::mask_option('fluentmail-settings', fluentMailGetSettings([], false)));

        foreach ([ self::PASSWORD, self::SES_SECRET, self::SES_ACCESS, self::TOKEN ] as $needle) {
            $this->assertStringNotContainsString($needle, $masked);
        }
        $this->assertStringContainsString('hello@example.test', $masked, 'the rest stays readable');
    }

    public function test_the_notification_option_masks_its_channel_tokens_and_webhooks(): void
    {
        $value = [ 'enabled' => 'yes', 'telegram' => [ 'status' => 'yes', 'token' => '123456:telegram-token' ], 'slack' => [ 'status' => 'yes', 'webhook_url' => 'https://hooks.slack.test/T/B/XYZ' ], 'discord' => [ 'webhook_url' => 'https://discord.test/api/webhooks/1/abc' ] ];

        $masked = (string) wp_json_encode(Secret_Options::mask_option('_fluent_smtp_notify_settings', $value));

        foreach ([ '123456:telegram-token', 'hooks.slack.test', 'discord.test/api' ] as $needle) {
            $this->assertStringNotContainsString($needle, $masked);
        }
    }

    // ---------------------------------------------------------------
    // get-fluent-smtp-log
    // ---------------------------------------------------------------

    public function test_log_returns_the_last_n_sends_masked_and_without_the_body(): void
    {
        $this->log_row([ 'subject' => 'First', 'created_at' => '2026-11-15 08:00:00' ]);
        $this->log_row([ 'subject' => 'Second', 'status' => 'failed', 'response' => serialize([ 'code' => 535, 'message' => 'Auth failed for ' . self::USERNAME . ' password ' . self::PASSWORD ]) ]);
        $this->log_row([ 'subject' => 'Third' ]);

        $out = $this->read('get-fluent-smtp-log', [ 'limit' => 2 ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $emails = $out['result']['emails'];
        $this->assertTrue($out['result']['logging_enabled']);
        $this->assertCount(2, $emails);
        $this->assertSame([ 'Third', 'Second' ], array_column($emails, 'subject'), 'newest first');
        $this->assertSame([ 'j***@example.org' ], $emails[0]['to']);
        $this->assertSame([ 'h***@example.test' ], $emails[0]['from']);
        $this->assertSame('failed', $emails[1]['status']);
        $this->assertStringContainsString('Auth failed', $emails[1]['error']);
        $this->assertLeaksNothing($out);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('SECRET BODY TEXT', $json, 'the body is never returned');
        $this->assertStringNotContainsString('jane.doe@example.org', $json, 'recipients are masked');
        $this->assertStringNotContainsString('Jane Doe', $json);
    }

    public function test_log_filters_by_status_and_reports_logging_off(): void
    {
        $this->log_row([ 'subject' => 'Ok' ]);
        $this->log_row([ 'subject' => 'Bad', 'status' => 'failed' ]);
        $settings = fluentMailGetSettings([], false);
        $settings['misc']['log_emails'] = 'no';
        fluentMailSetSettings($settings);

        $out = $this->read('get-fluent-smtp-log', [ 'status' => 'failed' ]);

        $this->assertFalse($out['result']['logging_enabled']);
        $this->assertSame([ 'Bad' ], array_column($out['result']['emails'], 'subject'));
    }

    public function test_log_limit_and_status_are_bounded_by_the_schema(): void
    {
        $this->assertSame('invalid_args', $this->read('get-fluent-smtp-log', [ 'limit' => 101 ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->read('get-fluent-smtp-log', [ 'status' => 'x; DROP TABLE y' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // send-fluent-smtp-test
    // ---------------------------------------------------------------

    public function test_the_test_send_is_off_until_the_site_opts_in(): void
    {
        $sent = 0;
        add_filter('pre_wp_mail', static function () use (&$sent) {
            $sent++;
            return true;
        });

        $out = $this->write('send-fluent-smtp-test', [ 'recipient' => 'ada@example.test' ], true);

        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_smtp_write', $out['error']['message']);
        $this->assertSame(0, $sent);
    }

    public function test_the_test_send_needs_confirm_true(): void
    {
        $this->enable_writes();
        $sent = 0;
        add_filter('pre_wp_mail', static function () use (&$sent) {
            $sent++;
            return true;
        });

        $out = $this->write('send-fluent-smtp-test', [ 'recipient' => 'ada@example.test' ]);

        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertSame(0, $sent);
    }

    public function test_the_confirmed_test_send_mails_the_caller_once_and_is_not_recoverable(): void
    {
        $this->enable_writes();
        $to = [];
        add_filter('pre_wp_mail', static function ($return, array $atts) use (&$to) {
            $to[] = $atts['to'];
            return true;
        }, 10, 2);
        $rows = Snapshot_Store::row_count();

        $out = $this->write('send-fluent-smtp-test', [ 'recipient' => 'ada@example.test' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['result']['test']['sent']);
        $this->assertSame([ 'ada@example.test' ], $to);
        $this->assertFalse($out['recoverable'], 'a sent mail cannot be undone');
        $this->assertSame($rows, Snapshot_Store::row_count());
        $this->assertLeaksNothing($out);
    }

    public function test_the_test_send_refuses_any_other_recipient_before_sending(): void
    {
        $this->enable_writes();
        $sent = 0;
        add_filter('pre_wp_mail', static function () use (&$sent) {
            $sent++;
            return true;
        });

        $out = $this->write('send-fluent-smtp-test', [ 'recipient' => 'stranger@example.net' ], true);

        $this->assertSame('mail_test_recipient_refused', $out['error']['code'] ?? null);
        $this->assertSame(0, $sent);
    }

    // ---------------------------------------------------------------
    // set-fluent-smtp-from
    // ---------------------------------------------------------------

    public function test_set_from_is_off_until_the_site_opts_in_and_changes_nothing(): void
    {
        $before = get_option('fluentmail-settings');
        $rows   = Snapshot_Store::row_count();

        $out = $this->write('set-fluent-smtp-from', [ 'from_email' => 'hello@example.test', 'sender_name' => 'New Name' ]);

        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_smtp_write', $out['error']['message']);
        $this->assertSame($before, get_option('fluentmail-settings'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_set_from_renames_one_connection_through_the_plugins_writer_and_keeps_every_credential(): void
    {
        $this->enable_writes();
        $stored_before = get_option('fluentmail-settings');

        $out = $this->write('set-fluent-smtp-from', [ 'from_email' => 'support@example.test', 'sender_name' => 'Support Team' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'from_email' => 'hello@example.test', 'sender_name' => 'Support Team' ], $out['result'], 'an additional sender names the connection that owns it');
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);

        $after = fluentMailGetSettings([], false);
        $this->assertSame('Support Team', $after['connections']['k-smtp']['provider_settings']['sender_name']);
        $this->assertSame('Orders', $after['connections']['k-ses']['provider_settings']['sender_name']);
        $this->assertSame(self::PASSWORD, $after['connections']['k-smtp']['provider_settings']['password'], 'credentials survive the re-encryption');
        $this->assertSame(self::SES_SECRET, $after['connections']['k-ses']['provider_settings']['secret_key']);
        $this->assertSame($stored_before['mappings'], get_option('fluentmail-settings')['mappings']);
        $this->assertNotSame(self::PASSWORD, get_option('fluentmail-settings')['connections']['k-smtp']['provider_settings']['password'], 'still encrypted at rest');
        $this->assertLeaksNothing($out);
    }

    public function test_set_from_rolls_back_to_the_exact_stored_option(): void
    {
        $this->enable_writes();
        $before = get_option('fluentmail-settings');

        $out = $this->write('set-fluent-smtp-from', [ 'from_email' => 'orders@example.test', 'sender_name' => 'Renamed' ]);
        $this->assertNotSame($before, get_option('fluentmail-settings'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('fluentmail-settings'), 'rollback restores the option exactly, ciphertext and all');
    }

    public function test_the_snapshot_keeps_the_raw_before_image_and_nothing_readable_leaks_from_the_response(): void
    {
        $this->enable_writes();

        $out  = $this->write('set-fluent-smtp-from', [ 'from_email' => 'hello@example.test', 'sender_name' => 'Snap' ]);
        $json = (string) wp_json_encode($out);

        $this->assertStringNotContainsString('enc::', $json, 'no ciphertext either');
        $this->assertLeaksNothing($out);
    }

    public function test_set_from_refuses_an_address_no_connection_owns_without_side_effects(): void
    {
        $this->enable_writes();
        $before = get_option('fluentmail-settings');
        $rows   = Snapshot_Store::row_count();

        $out = $this->write('set-fluent-smtp-from', [ 'from_email' => 'nobody@example.test', 'sender_name' => 'X' ]);

        $this->assertSame('fluent_smtp_no_connection', $out['error']['code'] ?? null);
        $this->assertSame($before, get_option('fluentmail-settings'));
        $this->assertSame($rows, Snapshot_Store::row_count(), 'a refused write leaves no undo point');
    }

    public function test_set_from_matches_the_address_ignoring_case_as_the_plugin_routes_it(): void
    {
        $this->enable_writes();

        $out = $this->write('set-fluent-smtp-from', [ 'from_email' => 'HELLO@Example.test', 'sender_name' => 'Cased' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('Cased', fluentMailGetSettings([], false)['connections']['k-smtp']['provider_settings']['sender_name']);
    }

    public function test_set_from_rejects_an_invalid_address_and_an_empty_name(): void
    {
        $this->enable_writes();
        $before = get_option('fluentmail-settings');

        $this->assertSame('invalid_args', $this->write('set-fluent-smtp-from', [ 'from_email' => 'not-an-email', 'sender_name' => 'X' ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('set-fluent-smtp-from', [ 'from_email' => 'hello@example.test', 'sender_name' => '<b></b>' ])['error']['code'] ?? null);
        $this->assertSame($before, get_option('fluentmail-settings'));
    }

    public function test_a_serialized_object_in_a_visitor_controlled_subject_is_never_unserialized(): void
    {
        Smtp_Injection_Probe::$woken = false;
        $this->log_row([ 'subject' => serialize(new Smtp_Injection_Probe()) ]);

        $out = $this->read('get-fluent-smtp-log');

        $this->assertFalse(Smtp_Injection_Probe::$woken);
        $this->assertStringContainsString('Smtp_Injection_Probe', $out['result']['emails'][0]['subject'], 'the subject is returned as the literal string');
    }

    public function test_a_serialized_object_in_a_plugin_serialized_field_is_not_instantiated(): void
    {
        Smtp_Injection_Probe::$woken = false;
        $this->log_row([ 'status' => 'failed', 'response' => serialize([ 'message' => 'x', 'obj' => new Smtp_Injection_Probe() ]), 'to' => serialize([ new Smtp_Injection_Probe(), [ 'email' => 'a.b@example.org' ] ]) ]);

        $out = $this->read('get-fluent-smtp-log');

        $this->assertFalse(Smtp_Injection_Probe::$woken);
        $this->assertSame([ 'a***@example.org' ], $out['result']['emails'][0]['to']);
    }

    public function test_a_secret_straddling_the_text_cut_cannot_leak_as_a_prefix(): void
    {
        $pad = str_repeat('x', 290);
        $this->log_row([ 'status' => 'failed', 'response' => serialize([ 'message' => $pad . self::PASSWORD ]) ]);

        $json = (string) wp_json_encode($this->read('get-fluent-smtp-log'));

        $this->assertStringNotContainsString(substr(self::PASSWORD, 0, 6), $json, 'not even a prefix of the secret');
    }

    public function test_a_sender_address_equal_to_the_smtp_username_is_not_redacted(): void
    {
        $settings = fluentMailGetSettings([], false);
        $settings['connections']['k-smtp']['provider_settings']['username'] = 'hello@example.test';
        fluentMailSetSettings($settings);
        $this->log_row([ 'status' => 'failed', 'subject' => 'From hello@example.test' ]);

        $config = $this->read('get-fluent-smtp-config')['result'];
        $log    = $this->read('get-fluent-smtp-log')['result'];

        $smtp = array_column($config['connections'], null, 'id')['k-smtp'];
        $this->assertSame('hello@example.test', $smtp['sender_email']);
        $this->assertSame('[redacted]', $smtp['provider_settings']['username'], 'the user name itself stays hidden by key');
        $this->assertSame('From hello@example.test', $log['emails'][0]['subject']);
    }

    public function test_the_pack_class_exposes_the_four_ops(): void
    {
        $this->assertSame(array_keys(self::OPS), array_keys(Fluent_SMTP_Status::operations()));
    }
}
