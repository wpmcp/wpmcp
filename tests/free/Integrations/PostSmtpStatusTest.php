<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Post_SMTP_Status;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Diagnostics\Mail_Check;
use WPMCP\Tools\Meta\Secret_Options;

require_once __DIR__ . '/../../support/post-smtp-double.php';

/** A class whose unserialization would be visible: the log tests prove it is never instantiated. */
class Post_Smtp_Injection_Probe
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}


/**
 * Post SMTP config, log, test send and from identity (Post_SMTP_Status): ops
 * on the theme dispatcher pair, through Ops_Status_Packs (issue #696).
 *
 * Post SMTP is not loaded in the shared test core. Presence is driven through
 * wpmcp_post_smtp_active, and the plugin is the stand-in in
 * tests/support/post-smtp-double.php, kept the way Post SMTP 4.0 keeps its
 * settings (one postman_options option, API keys and the basic auth password
 * base64 encoded, the log in the post_smtp_logs table). The same ops run
 * against the real plugin in PostSmtpLiveTest.
 *
 * @group post-smtp
 */
class PostSmtpStatusTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-post-smtp-config' => 'read',
        'get-post-smtp-log'    => 'read',
        'send-post-smtp-test'  => 'destructive',
        'set-post-smtp-from'   => 'write',
    ];

    private const PASSWORD = 'SuperSecretPassword123';
    private const USERNAME = 'mailer-user@example.test';
    private const API_KEY  = 'SG.abcdefghijklmnopqrstuvwxyz';
    private const SECRET   = 'oauth-client-secret-value';
    private const CLIENT   = 'oauth-client-id-value-123';
    private const MJ_KEY   = 'mailjet-api-key-value-99';
    private const MJ_SEC   = 'mailjet-secret-key-value-99';
    private const TOKEN    = 'oauth-access-token-value';
    private const REFRESH  = 'oauth-refresh-token-value';

    private int $admin;

    private static string $table = '';

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::$table = $wpdb->prefix . 'post_smtp_logs';
        $wpdb->query('DROP TABLE IF EXISTS `' . self::$table . '`');
        // The columns of Post SMTP's own table (Postman/PostmanEmailLogs.php).
        $wpdb->query('CREATE TABLE `' . self::$table . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT, `solution` longtext DEFAULT NULL, `success` longtext DEFAULT NULL, `from_header` longtext DEFAULT NULL,
            `to_header` longtext DEFAULT NULL, `cc_header` longtext DEFAULT NULL, `bcc_header` longtext DEFAULT NULL, `reply_to_header` longtext DEFAULT NULL,
            `transport_uri` longtext DEFAULT NULL, `original_to` longtext DEFAULT NULL, `original_subject` longtext DEFAULT NULL, `original_message` longtext DEFAULT NULL,
            `original_headers` longtext DEFAULT NULL, `session_transcript` longtext DEFAULT NULL, `time` BIGINT(20) DEFAULT NULL, PRIMARY KEY (`id`))');
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

        update_option('postman_options', [
            'transport_type'         => 'smtp',
            'sender_email'           => 'hello@example.test',
            'sender_name'            => 'Example Site',
            'hostname'               => 'smtp.example.test',
            'port'                   => '587',
            'enc_type'               => 'tls',
            'auth_type'              => 'login',
            'basic_auth_username'    => self::USERNAME,
            'basic_auth_password'    => base64_encode(self::PASSWORD),
            'oauth_client_id'        => self::CLIENT,
            'oauth_client_secret'    => self::SECRET,
            'sendgrid_api_key'       => base64_encode(self::API_KEY),
            'mailjet_api_key'        => base64_encode(self::MJ_KEY),
            'mailjet_secret_key'     => base64_encode(self::MJ_SEC),
            'run_mode'               => 'production',
            'mail_log_enabled'       => 'true',
            'prevent_sender_email_override' => '1',
            'fallback_smtp_enabled'  => 'yes',
            'fallback_smtp_use_auth' => 'yes',
            'headers'                => 'X-Api-Token: ' . self::TOKEN,
        ]);
        update_option('postman_auth_token', [ 'access_token' => self::TOKEN, 'refresh_token' => self::REFRESH, 'auth_token_expires' => 1800000000, 'vendor_name' => 'google', 'user_email' => 'oauth-user@example.test' ]);
        \PostmanOptions::forget();

        add_filter('wpmcp_post_smtp_active', '__return_true');
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'ada@example.test' ]);
        wp_set_current_user($this->admin);
        Mail_Check::reset_limit($this->admin);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_post_smtp_active');
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
        foreach ([ self::PASSWORD, base64_encode(self::PASSWORD), self::API_KEY, base64_encode(self::API_KEY), self::SECRET, self::CLIENT, self::MJ_KEY, self::MJ_SEC, self::TOKEN, self::REFRESH ] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the answer must not carry {$needle}");
        }
    }

    private function log_row(array $row): void
    {
        global $wpdb;
        $wpdb->insert(self::$table, $row + [
            'success'            => '1',
            'from_header'        => 'Example Site <hello@example.test>',
            'to_header'          => 'Jane Doe <jane.doe@example.org>, bob@example.net',
            'original_subject'   => 'Hello',
            'original_message'   => 'SECRET BODY TEXT',
            'session_transcript' => 'AUTH LOGIN ' . base64_encode(self::USERNAME) . ' ' . base64_encode(self::PASSWORD),
            'transport_uri'      => 'smtp://smtp.example.test:587',
            'time'               => 1800000000,
        ]);
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
        $this->assertTrue($ops['send-post-smtp-test']['requires_confirm']);
    }

    public function test_every_op_names_post_smtp_and_changes_nothing_while_it_is_inactive(): void
    {
        remove_all_filters('wpmcp_post_smtp_active');
        add_filter('wpmcp_post_smtp_active', '__return_false');
        $this->enable_writes();
        $before = get_option('postman_options');
        $rows   = Snapshot_Store::row_count();

        foreach (self::OPS as $op => $mode) {
            $this->assertFalse($this->catalog()[ $op ]['dependency_met'], "{$op} must be flagged while Post SMTP is inactive");
            $args = 'send-post-smtp-test' === $op ? [ 'recipient' => 'ada@example.test' ] : ('set-post-smtp-from' === $op ? [ 'from_name' => 'X' ] : []);
            $out  = 'read' === $mode ? $this->read($op, $args) : $this->write($op, $args, true);
            $this->assertSame('post_smtp_inactive', $out['error']['code'] ?? null, "{$op} must answer post_smtp_inactive");
            $this->assertStringContainsString('Post SMTP', $out['error']['message']);
        }

        $this->assertSame($before, get_option('postman_options'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_the_plugins_version_constant(): void
    {
        remove_all_filters('wpmcp_post_smtp_active');

        $this->assertSame(defined('POST_SMTP_VER'), $this->catalog()['get-post-smtp-config']['dependency_met']);
    }

    public function test_every_op_refuses_a_user_without_manage_options(): void
    {
        $this->enable_writes();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach (self::OPS as $op => $mode) {
            $args = 'send-post-smtp-test' === $op ? [ 'recipient' => 'ada@example.test' ] : ('set-post-smtp-from' === $op ? [ 'from_name' => 'X' ] : []);
            $out  = 'read' === $mode ? $this->read($op, $args) : $this->write($op, $args, true);
            $this->assertContains($out['error']['code'] ?? null, [ 'operation_denied', 'forbidden' ], "{$op} runs at manage_options");
        }
        $this->assertSame('hello@example.test', get_option('postman_options')['sender_email']);
    }

    // ---------------------------------------------------------------
    // get-post-smtp-config
    // ---------------------------------------------------------------

    public function test_config_reports_the_transport_identity_and_non_secret_settings(): void
    {
        $out = $this->read('get-post-smtp-config');

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $result = $out['result'];
        $this->assertTrue($result['configured']);
        $this->assertSame('smtp', $result['transport']);
        $this->assertSame('hello@example.test', $result['from_email']);
        $this->assertSame('Example Site', $result['from_name']);
        $this->assertSame('smtp.example.test', $result['settings']['hostname']);
        $this->assertSame('587', $result['settings']['port']);
        $this->assertSame('tls', $result['settings']['enc_type']);
        $this->assertSame('login', $result['settings']['authentication_type'], 'auth_type is reported, not redacted by its name');
        $this->assertSame('production', $result['settings']['run_mode']);
        $this->assertSame('yes', $result['settings']['fallback_smtp_use_authentication']);
        $this->assertEqualsCanonicalizing([ 'basic_auth_password', 'oauth_client_secret', 'sendgrid_api_key', 'mailjet_api_key', 'mailjet_secret_key' ], $result['secrets_set']);
        $this->assertTrue($result['oauth']['connected']);
        $this->assertSame('2027-01-15T08:00:00Z', $result['oauth']['expires_at']);
        $this->assertSame([], $result['constants']);
        $this->assertArrayNotHasKey('headers', $result['settings'], 'free-form headers can carry credentials');
    }

    public function test_config_masks_every_credential_in_both_forms_and_never_leaks_a_value(): void
    {
        $out = $this->read('get-post-smtp-config');

        $this->assertLeaksNothing($out);
        $this->assertStringNotContainsString('oauth-user@example.test', (string) wp_json_encode($out), 'the connected account is not returned');
    }

    public function test_a_credential_echoed_under_a_harmless_key_is_scrubbed_by_value(): void
    {
        $options = get_option('postman_options');
        $options['test_email'] = 'debug: ' . self::API_KEY . ' and ' . self::PASSWORD;
        update_option('postman_options', $options);

        $json = (string) wp_json_encode($this->read('get-post-smtp-config'));

        $this->assertStringNotContainsString(self::API_KEY, $json);
        $this->assertStringNotContainsString(self::PASSWORD, $json);
    }

    public function test_config_reports_an_unconfigured_install(): void
    {
        delete_option('postman_options');

        $out = $this->read('get-post-smtp-config');

        $this->assertFalse($out['result']['configured']);
        $this->assertSame([], $out['result']['secrets_set']);
    }

    public function test_the_options_are_in_the_secret_registry_and_masked_by_get_option(): void
    {
        foreach ([ 'postman_options', 'postman_auth_token', 'postman_office365_oauth', 'post_smtp_mobile_app_connection' ] as $name) {
            $this->assertTrue(Secret_Options::is_registered($name), $name);
        }

        $masked = (string) wp_json_encode(Secret_Options::mask_option('postman_options', get_option('postman_options')));
        $this->assertStringNotContainsString(base64_encode(self::PASSWORD), $masked);
        $this->assertStringNotContainsString(base64_encode(self::API_KEY), $masked);
        $this->assertStringNotContainsString(self::SECRET, $masked);
        $this->assertStringContainsString('smtp.example.test', $masked, 'the rest stays readable');

        $token = (string) wp_json_encode(Secret_Options::mask_option('postman_auth_token', get_option('postman_auth_token')));
        $this->assertStringNotContainsString(self::TOKEN, $token);
        $this->assertStringNotContainsString(self::REFRESH, $token);
    }

    // ---------------------------------------------------------------
    // get-post-smtp-log
    // ---------------------------------------------------------------

    public function test_log_returns_the_last_n_sends_masked_and_without_the_body_or_transcript(): void
    {
        $this->log_row([ 'original_subject' => 'First', 'time' => 1799990000 ]);
        $this->log_row([ 'original_subject' => 'Second', 'success' => 'Could not authenticate as ' . self::USERNAME . ' with ' . self::PASSWORD ]);
        $this->log_row([ 'original_subject' => 'Third', 'success' => 'In Queue' ]);

        $out = $this->read('get-post-smtp-log', [ 'limit' => 2 ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $emails = $out['result']['emails'];
        $this->assertTrue($out['result']['logging_enabled']);
        $this->assertSame([ 'Third', 'Second' ], array_column($emails, 'subject'));
        $this->assertSame([ 'queued', 'failed' ], array_column($emails, 'status'));
        $this->assertSame([ 'j***@example.org', 'b***@example.net' ], $emails[0]['to']);
        $this->assertSame([ 'h***@example.test' ], $emails[0]['from']);
        $this->assertStringContainsString('Could not authenticate', $emails[1]['error']);
        $this->assertSame('smtp://smtp.example.test:587', $emails[0]['transport']);
        $this->assertSame('2027-01-15 08:00:00', $emails[0]['time']);
        $this->assertLeaksNothing($out);
        $json = (string) wp_json_encode($out);
        foreach ([ 'SECRET BODY TEXT', 'AUTH LOGIN', 'jane.doe@example.org', 'Jane Doe' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "{$needle} must not be returned");
        }
    }

    public function test_log_filters_by_status_and_reports_logging_off(): void
    {
        $this->log_row([ 'original_subject' => 'Ok' ]);
        $this->log_row([ 'original_subject' => 'Fallback', 'success' => 'Sent ( ** Fallback ** )' ]);
        $this->log_row([ 'original_subject' => 'Bad', 'success' => 'Connection refused' ]);
        $options = get_option('postman_options');
        $options['mail_log_enabled'] = 'false';
        update_option('postman_options', $options);

        $failed = $this->read('get-post-smtp-log', [ 'status' => 'failed' ]);
        $sent   = $this->read('get-post-smtp-log', [ 'status' => 'sent' ]);

        $this->assertFalse($failed['result']['logging_enabled']);
        $this->assertSame([ 'Bad' ], array_column($failed['result']['emails'], 'subject'));
        $this->assertSame([ 'Fallback', 'Ok' ], array_column($sent['result']['emails'], 'subject'));
        $this->assertSame([ 'sent', 'sent' ], array_column($sent['result']['emails'], 'status'));
    }

    public function test_log_limit_and_status_are_bounded_by_the_schema(): void
    {
        $this->assertSame('invalid_args', $this->read('get-post-smtp-log', [ 'limit' => 0 ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->read('get-post-smtp-log', [ 'status' => "x' OR 1=1" ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // send-post-smtp-test
    // ---------------------------------------------------------------

    public function test_the_test_send_is_off_until_the_site_opts_in(): void
    {
        $sent = 0;
        add_filter('pre_wp_mail', static function () use (&$sent) {
            $sent++;
            return true;
        });

        $out = $this->write('send-post-smtp-test', [ 'recipient' => 'ada@example.test' ], true);

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

        $out = $this->write('send-post-smtp-test', [ 'recipient' => 'ada@example.test' ]);

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

        $out = $this->write('send-post-smtp-test', [ 'recipient' => 'ada@example.test' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['result']['test']['sent']);
        $this->assertSame([ 'ada@example.test' ], $to);
        $this->assertFalse($out['recoverable']);
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

        $out = $this->write('send-post-smtp-test', [ 'recipient' => 'stranger@example.net' ], true);

        $this->assertSame('mail_test_recipient_refused', $out['error']['code'] ?? null);
        $this->assertSame(0, $sent);
    }

    // ---------------------------------------------------------------
    // set-post-smtp-from
    // ---------------------------------------------------------------

    public function test_set_from_is_off_until_the_site_opts_in_and_changes_nothing(): void
    {
        $before = get_option('postman_options');
        $rows   = Snapshot_Store::row_count();

        $out = $this->write('set-post-smtp-from', [ 'from_email' => 'new@example.test' ]);

        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_smtp_write', $out['error']['message']);
        $this->assertSame($before, get_option('postman_options'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_set_from_changes_only_the_identity_keeps_every_credential_and_reloads_the_plugin(): void
    {
        $this->enable_writes();
        $before = get_option('postman_options');
        $before_reloads = \PostmanOptions::$reloads;

        $out = $this->write('set-post-smtp-from', [ 'from_email' => 'new@example.test', 'from_name' => 'New Name' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'from_email' => 'new@example.test', 'from_name' => 'New Name' ], $out['result']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);

        $after = get_option('postman_options');
        $this->assertSame('new@example.test', $after['sender_email']);
        $this->assertSame('New Name', $after['sender_name']);
        $this->assertSame(array_diff_key($before, [ 'sender_email' => 1, 'sender_name' => 1 ]), array_diff_key($after, [ 'sender_email' => 1, 'sender_name' => 1 ]), 'every other setting and credential is untouched');
        $this->assertSame($before_reloads + 1, \PostmanOptions::$reloads, 'the plugin re-reads its option');
        $this->assertSame('new@example.test', \PostmanOptions::getInstance()->getMessageSenderEmail());
        $this->assertLeaksNothing($out);
    }

    public function test_set_from_refuses_another_plugins_name_for_the_sender_and_records_nothing(): void
    {
        // Issue #956: sender_name is the FluentSMTP op's key; this op takes from_name.
        $this->enable_writes();
        $before = get_option('postman_options');
        $rows   = Snapshot_Store::row_count();

        $out = $this->write('set-post-smtp-from', [ 'from_email' => 'admin@example.com', 'sender_name' => 'WP MCP Test Mailer' ]);

        $this->assertSame('invalid_args', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame([ 'sender_name' ], $out['error']['data']['unknown']);
        $this->assertSame([ 'from_email', 'from_name' ], $out['error']['data']['allowed']);
        $this->assertSame($before, get_option('postman_options'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_set_from_name_only_leaves_the_address(): void
    {
        $this->enable_writes();

        $out = $this->write('set-post-smtp-from', [ 'from_name' => 'Only Name' ]);

        $this->assertSame([ 'from_email' => 'hello@example.test', 'from_name' => 'Only Name' ], $out['result']);
    }

    public function test_set_from_rolls_back_to_the_exact_stored_option(): void
    {
        $this->enable_writes();
        $before = get_option('postman_options');

        $out = $this->write('set-post-smtp-from', [ 'from_email' => 'new@example.test', 'from_name' => 'Renamed' ]);
        $this->assertNotSame($before, get_option('postman_options'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('postman_options'), 'rollback restores the option exactly, base64 credentials and all');
    }

    public function test_set_from_rejects_bad_input_and_an_unconfigured_install_without_side_effects(): void
    {
        $this->enable_writes();
        $before = get_option('postman_options');
        $rows   = Snapshot_Store::row_count();

        $this->assertSame('invalid_args', $this->write('set-post-smtp-from', [ 'from_email' => 'not-an-email' ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->write('set-post-smtp-from', [ 'from_name' => '<b></b>' ])['error']['code'] ?? null);
        $this->assertSame($before, get_option('postman_options'));

        delete_option('postman_options');
        $this->assertSame('post_smtp_not_configured', $this->write('set-post-smtp-from', [ 'from_name' => 'X' ])['error']['code'] ?? null);
        $this->assertFalse(get_option('postman_options'), 'nothing is created for an unconfigured plugin');
        $this->assertSame($rows, Snapshot_Store::row_count(), 'a refused write leaves no undo point');
    }

    public function test_a_serialized_object_in_a_visitor_controlled_subject_is_never_unserialized(): void
    {
        Post_Smtp_Injection_Probe::$woken = false;
        $this->log_row([ 'original_subject' => serialize(new Post_Smtp_Injection_Probe()) ]);

        $out = $this->read('get-post-smtp-log');

        $this->assertFalse(Post_Smtp_Injection_Probe::$woken);
        $this->assertStringContainsString('Post_Smtp_Injection_Probe', $out['result']['emails'][0]['subject'], 'the subject is returned as the literal string');
    }

    public function test_a_secret_straddling_the_text_cut_cannot_leak_as_a_prefix(): void
    {
        $this->log_row([ 'success' => str_repeat('x', 290) . self::PASSWORD ]);

        $json = (string) wp_json_encode($this->read('get-post-smtp-log'));

        $this->assertStringNotContainsString(substr(self::PASSWORD, 0, 6), $json, 'not even a prefix of the secret');
    }

    public function test_a_sender_address_equal_to_the_smtp_username_is_not_redacted(): void
    {
        $options = get_option('postman_options');
        $options['basic_auth_username'] = 'hello@example.test';
        update_option('postman_options', $options);
        $this->log_row([ 'original_subject' => 'From hello@example.test' ]);

        $config = $this->read('get-post-smtp-config')['result'];
        $log    = $this->read('get-post-smtp-log')['result'];

        $this->assertSame('hello@example.test', $config['from_email']);
        $this->assertSame('[redacted]', $config['settings']['basic_auth_username']);
        $this->assertSame('From hello@example.test', $log['emails'][0]['subject']);
    }

    public function test_the_pack_class_exposes_the_four_ops(): void
    {
        $this->assertSame(array_keys(self::OPS), array_keys(Post_SMTP_Status::operations()));
    }
}
