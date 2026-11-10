<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMailSMTP\Options;
use WPMailSMTP\Pro\Emails\Logs\EmailsCollection;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Diagnostics\Mail_Check;

require_once __DIR__ . '/../../support/wp-mail-smtp-double.php';

/**
 * WP Mail SMTP status, test send and from identity (WP_Mail_SMTP_Status):
 * free ops on the theme dispatcher pair, through Ops_Status_Packs.
 *
 * WP Mail SMTP is not loaded in the shared test core. Presence is driven
 * through wpmcp_wp_mail_smtp_active, and the plugin is the stand-in in
 * tests/support/wp-mail-smtp-double.php, kept the way WP Mail SMTP 4.10
 * keeps its settings (one wp_mail_smtp option, the SMTP password encrypted,
 * constants winning when enabled, set() replacing or merging). The same ops
 * run against the real plugin in WpMailSmtpLiveTest.
 */
class WpMailSmtpStatusTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-wp-mail-smtp-config' => 'read',
        'get-wp-mail-smtp-log'    => 'read',
        'send-wp-mail-smtp-test'  => 'read',
        'set-wp-mail-smtp-from'   => 'write',
    ];

    private const PASSWORD = 'SuperSecretPassword123';
    private const API_KEY  = 'SG.abcdefghijklmnopqrstuvwxyz';
    private const SECRET   = 'client-secret-value';
    private const TOKEN    = 'oauth-access-token';
    private const LICENSE  = 'lic-0123456789abcdef';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Options::reset();
        \WPMCP_Test_WP_Mail_SMTP::$pro          = false;
        \WPMCP_Test_WP_Mail_SMTP::$logs_enabled = false;
        EmailsCollection::$rows                 = [];
        EmailsCollection::$built                = [];
        update_option('wp_mail_smtp', [
            'mail'     => [ 'mailer' => 'smtp', 'from_email' => 'hello@example.test', 'from_name' => 'Example Site', 'from_email_force' => true, 'from_name_force' => false, 'return_path' => true ],
            'smtp'     => [ 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'autotls' => true, 'auth' => true, 'user' => 'mailer@example.test', 'pass' => Options::encrypt(self::PASSWORD) ],
            'sendgrid' => [ 'api_key' => self::API_KEY, 'domain' => 'example.test' ],
            'outlook'  => [ 'client_id' => 'client-id-value', 'client_secret' => self::SECRET, 'access_token' => [ 'access_token' => self::TOKEN, 'expires' => 3600 ] ],
            'general'  => [ 'do_not_send' => false ],
            'license'  => [ 'key' => self::LICENSE ],
        ]);
        add_filter('wpmcp_wp_mail_smtp_active', '__return_true');
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'ada@example.test' ]);
        wp_set_current_user($this->admin);
        Mail_Check::reset_limit($this->admin);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wp_mail_smtp_active');
        remove_all_filters('wp_mail_smtp_options_is_const_enabled');
        remove_all_filters('wp_mail_smtp_options_use_global_plugin_options');
        remove_all_filters('pre_wp_mail');
        Options::reset();
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

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function assertLeaksNothing(array $payload): void
    {
        $json = (string) wp_json_encode($payload);
        foreach ([ self::PASSWORD, Options::encrypt(self::PASSWORD), self::API_KEY, self::SECRET, self::TOKEN, self::LICENSE, 'mailer@example.test' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the answer must not carry {$needle}");
        }
    }

    // ---------------------------------------------------------------
    // Catalog and presence
    // ---------------------------------------------------------------

    public function test_every_op_is_a_free_manage_options_op_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame($mode, $ops[ $name ]['mode']);
            $this->assertSame('manage_options', $ops[ $name ]['capability']);
            $this->assertTrue($ops[ $name ]['dependency_met']);
        }
    }

    public function test_every_op_is_skipped_cleanly_while_wp_mail_smtp_is_inactive(): void
    {
        remove_all_filters('wpmcp_wp_mail_smtp_active');
        add_filter('wpmcp_wp_mail_smtp_active', '__return_false');
        $before = get_option('wp_mail_smtp');
        $rows   = Snapshot_Store::row_count();

        foreach (self::OPS as $op => $mode) {
            $this->assertFalse($this->catalog()[ $op ]['dependency_met'], "{$op} must be flagged while WP Mail SMTP is inactive");
            $args = 'send-wp-mail-smtp-test' === $op ? [ 'recipient' => 'ada@example.test', 'confirm' => true ] : ('set-wp-mail-smtp-from' === $op ? [ 'from_name' => 'X' ] : []);
            $out  = 'write' === $mode ? $this->write($op, $args) : $this->read($op, $args);
            $this->assertSame('wp_mail_smtp_inactive', $out['error']['code'] ?? null, "{$op} must answer wp_mail_smtp_inactive");
            $this->assertArrayNotHasKey('result', $out);
        }

        $this->assertSame($before, get_option('wp_mail_smtp'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_the_plugins_version_constant(): void
    {
        remove_all_filters('wpmcp_wp_mail_smtp_active');

        $this->assertSame(defined('WPMS_PLUGIN_VER'), $this->catalog()['get-wp-mail-smtp-config']['dependency_met']);
    }

    public function test_every_op_refuses_a_user_without_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach (self::OPS as $op => $mode) {
            $out = 'write' === $mode ? $this->write($op, [ 'from_name' => 'X' ]) : $this->read($op, [ 'recipient' => 'ada@example.test' ]);
            $this->assertContains($out['error']['code'] ?? null, [ 'operation_denied', 'forbidden' ], "{$op} runs at manage_options");
        }
        $this->assertSame('hello@example.test', Options::init()->get('mail', 'from_email'));
    }

    // ---------------------------------------------------------------
    // get-wp-mail-smtp-config
    // ---------------------------------------------------------------

    public function test_config_reports_the_mailer_identity_and_non_secret_settings(): void
    {
        $out = $this->read('get-wp-mail-smtp-config');

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $config = $out['result'];
        $this->assertSame('smtp', $config['mailer']);
        $this->assertSame('Other SMTP', $config['mailer_title']);
        $this->assertSame('hello@example.test', $config['from_email']);
        $this->assertSame('Example Site', $config['from_name']);
        $this->assertTrue($config['from_email_forced']);
        $this->assertFalse($config['from_name_forced']);
        $this->assertTrue($config['return_path']);
        $this->assertFalse($config['do_not_send']);
        $this->assertFalse($config['pro']);
        $this->assertSame([], $config['constants']);
        $this->assertSame('smtp.example.test', $config['mailer_settings']['host']);
        $this->assertSame(587, $config['mailer_settings']['port']);
        $this->assertSame('tls', $config['mailer_settings']['encryption']);
        $this->assertTrue($config['mailer_settings']['authentication'], 'the SMTP auth switch survives masking');
        $this->assertSame('[redacted]', $config['mailer_settings']['pass']);
        $this->assertSame('[redacted]', $config['mailer_settings']['user']);
        $this->assertArrayNotHasKey('other_mailers', $config);
    }

    public function test_config_masks_every_credential_and_never_leaks_their_values(): void
    {
        $out = $this->read('get-wp-mail-smtp-config', [ 'all_mailers' => true ]);

        $others = $out['result']['other_mailers'];
        $this->assertSame([ 'sendgrid', 'outlook' ], array_keys($others), 'only mailer groups, never license or general');
        $this->assertSame('[redacted]', $others['sendgrid']['api_key']);
        $this->assertSame('example.test', $others['sendgrid']['domain']);
        $this->assertSame('[redacted]', $others['outlook']['client_secret']);
        $this->assertSame('[redacted]', $others['outlook']['access_token']);
        $this->assertLeaksNothing($out);
    }

    public function test_a_credential_echoed_under_a_harmless_key_is_scrubbed_by_value(): void
    {
        $stored                              = get_option('wp_mail_smtp');
        $stored['smtp']['host']              = 'smtp.example.test?p=' . self::PASSWORD;
        $stored['sendgrid']['domain']        = self::API_KEY;
        update_option('wp_mail_smtp', $stored);
        Options::reset();

        $out = $this->read('get-wp-mail-smtp-config', [ 'all_mailers' => true ]);

        $this->assertSame('smtp.example.test?p=[redacted]', $out['result']['mailer_settings']['host']);
        $this->assertSame('[redacted]', $out['result']['other_mailers']['sendgrid']['domain']);
        $this->assertLeaksNothing($out);
    }

    public function test_config_names_the_settings_constants_override_and_never_leaks_a_constant_password(): void
    {
        add_filter('wp_mail_smtp_options_is_const_enabled', '__return_true');
        Options::$constants = [
            'mail' => [ 'from_email' => 'const@example.test' ],
            'smtp' => [ 'pass' => 'ConstantPassword99' ],
        ];

        $out = $this->read('get-wp-mail-smtp-config');

        $this->assertSame('const@example.test', $out['result']['from_email']);
        $this->assertSame([ 'mail.from_email', 'smtp.pass' ], $out['result']['constants']);
        $this->assertStringNotContainsString('ConstantPassword99', (string) wp_json_encode($out));
    }

    // ---------------------------------------------------------------
    // get-wp-mail-smtp-log
    // ---------------------------------------------------------------

    public function test_log_reports_logging_off_without_pro(): void
    {
        $out = $this->read('get-wp-mail-smtp-log');

        $this->assertArrayNotHasKey('error', $out);
        $this->assertFalse($out['result']['logging_enabled']);
        $this->assertFalse($out['result']['pro']);
        $this->assertSame([], $out['result']['emails']);
        $this->assertSame([], EmailsCollection::$built, 'the Pro log is not queried without Pro');
    }

    public function test_log_reports_logging_off_when_pro_has_the_log_turned_off(): void
    {
        \WPMCP_Test_WP_Mail_SMTP::$pro = true;

        $out = $this->read('get-wp-mail-smtp-log');

        $this->assertFalse($out['result']['logging_enabled']);
        $this->assertTrue($out['result']['pro']);
        $this->assertSame([], EmailsCollection::$built);
    }

    public function test_log_returns_the_last_n_sends_with_status_and_scrubbed_error(): void
    {
        \WPMCP_Test_WP_Mail_SMTP::$pro          = true;
        \WPMCP_Test_WP_Mail_SMTP::$logs_enabled = true;
        EmailsCollection::$rows                 = [
            [ 'id' => 1, 'subject' => 'Old', 'people' => [ 'to' => [ 'a@example.test' ] ], 'status' => 1, 'mailer' => 'smtp', 'date_sent' => '2026-10-01 10:00:00' ],
            [ 'id' => 2, 'subject' => 'Failed', 'people' => [ 'to' => [ 'b@example.test' ] ], 'status' => 0, 'error_text' => 'SMTP Error: Could not authenticate with ' . self::PASSWORD, 'mailer' => 'smtp', 'date_sent' => '2026-10-02 10:00:00' ],
            [ 'id' => 3, 'subject' => 'Newest', 'people' => [ 'to' => [ 'c@example.test', 'd@example.test' ] ], 'status' => 3, 'mailer' => 'smtp', 'date_sent' => '2026-10-03 10:00:00' ],
        ];

        $out = $this->read('get-wp-mail-smtp-log', [ 'limit' => 2 ]);

        $this->assertTrue($out['result']['logging_enabled']);
        $this->assertSame(2, EmailsCollection::$built[0]['per_page']);
        $emails = $out['result']['emails'];
        $this->assertCount(2, $emails);
        $this->assertSame([ 3, 2 ], array_column($emails, 'id'));
        $this->assertSame([ 'delivered', 'failed' ], array_column($emails, 'status'));
        $this->assertSame([ 'c@example.test', 'd@example.test' ], $emails[0]['to']);
        $this->assertSame('2026-10-03T10:00:00Z', $emails[0]['date_sent']);
        $this->assertSame('SMTP Error: Could not authenticate with [redacted]', $emails[1]['error']);
    }

    public function test_log_limit_is_bounded_by_the_schema(): void
    {
        $out = $this->read('get-wp-mail-smtp-log', [ 'limit' => 500 ]);

        $this->assertSame('invalid_args', $out['error']['code']);
    }

    // ---------------------------------------------------------------
    // send-wp-mail-smtp-test
    // ---------------------------------------------------------------

    public function test_send_test_without_confirm_reports_the_transport_and_sends_nothing(): void
    {
        $sent = 0;
        add_filter('wp_mail', static function ($args) use (&$sent) {
            $sent++;
            return $args;
        });

        $out = $this->read('send-wp-mail-smtp-test', [ 'recipient' => 'ada@example.test' ]);

        remove_all_filters('wp_mail');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertArrayHasKey('transport', $out['result']);
        $this->assertSame('confirmation_required', $out['result']['test']['status']);
        $this->assertSame('ada@example.test', $out['result']['test']['recipient']);
        $this->assertSame(0, $sent, 'an unconfirmed test must not send');
    }

    public function test_send_test_with_confirm_sends_one_message_to_the_caller(): void
    {
        $to = [];
        add_filter('pre_wp_mail', static function ($return, array $atts) use (&$to) {
            $to[] = $atts['to'];
            return true;
        }, 10, 2);

        $out = $this->read('send-wp-mail-smtp-test', [ 'recipient' => 'ada@example.test', 'confirm' => true ]);

        $this->assertTrue($out['result']['test']['sent'], (string) wp_json_encode($out));
        $this->assertSame([ 'ada@example.test' ], $to);
    }

    public function test_send_test_refuses_any_other_recipient_before_sending(): void
    {
        $sent = 0;
        add_filter('pre_wp_mail', static function () use (&$sent) {
            $sent++;
            return true;
        });

        $out = $this->read('send-wp-mail-smtp-test', [ 'recipient' => 'stranger@example.test', 'confirm' => true ]);

        $this->assertSame('mail_test_recipient_refused', $out['error']['code'] ?? null);
        $this->assertSame(0, $sent);
    }

    public function test_send_test_shares_the_hourly_limit_with_the_mail_check(): void
    {
        add_filter('pre_wp_mail', '__return_true');
        for ($i = 0; $i < Mail_Check::LIMIT; $i++) {
            $this->assertTrue($this->read('send-wp-mail-smtp-test', [ 'recipient' => 'ada@example.test', 'confirm' => true ])['result']['test']['sent']);
        }

        $out = $this->read('send-wp-mail-smtp-test', [ 'recipient' => 'ada@example.test', 'confirm' => true ]);

        $this->assertSame('mail_test_refused', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // set-wp-mail-smtp-from
    // ---------------------------------------------------------------

    public function test_set_from_merges_the_identity_keeps_every_other_setting_and_rolls_back_exactly(): void
    {
        $before = get_option('wp_mail_smtp');

        $out = $this->write('set-wp-mail-smtp-from', [ 'from_email' => 'news@example.test', 'from_name' => 'Newsroom' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'from_email' => 'news@example.test', 'from_name' => 'Newsroom' ], $out['result']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);

        $options = Options::init();
        $this->assertSame('news@example.test', $options->get('mail', 'from_email'));
        $this->assertSame('smtp', $options->get('mail', 'mailer'), 'the mailer is kept');
        $this->assertSame('smtp.example.test', $options->get('smtp', 'host'));
        $this->assertSame(self::PASSWORD, $options->get('smtp', 'pass'), 'the SMTP password still decrypts to itself');
        $this->assertSame(self::API_KEY, $options->get('sendgrid', 'api_key'));
        $this->assertSame(self::LICENSE, $options->get('license', 'key'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('wp_mail_smtp'), 'rollback restores the option exactly');
    }

    public function test_set_from_name_only_leaves_the_address(): void
    {
        $out = $this->write('set-wp-mail-smtp-from', [ 'from_name' => 'Only Name' ]);

        $this->assertSame([ 'from_email' => 'hello@example.test', 'from_name' => 'Only Name' ], $out['result']);
    }

    public function test_set_from_rejects_an_invalid_address_without_side_effects(): void
    {
        $before = get_option('wp_mail_smtp');
        $rows   = Snapshot_Store::row_count();

        $out = $this->write('set-wp-mail-smtp-from', [ 'from_email' => 'not-an-email' ]);

        $this->assertSame('invalid_args', $out['error']['code'] ?? null);
        $this->assertSame($before, get_option('wp_mail_smtp'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_set_from_refuses_a_field_a_constant_defines_without_side_effects(): void
    {
        add_filter('wp_mail_smtp_options_is_const_enabled', '__return_true');
        Options::$constants = [ 'mail' => [ 'from_email' => 'const@example.test' ] ];
        $before             = get_option('wp_mail_smtp');
        $rows               = Snapshot_Store::row_count();

        $out = $this->write('set-wp-mail-smtp-from', [ 'from_email' => 'news@example.test' ]);

        $this->assertSame('wp_mail_smtp_constant_override', $out['error']['code'] ?? null);
        $this->assertStringContainsString('WPMS_MAIL_FROM', $out['error']['message']);
        $this->assertSame($before, get_option('wp_mail_smtp'));
        $this->assertSame($rows, Snapshot_Store::row_count());

        $name = $this->write('set-wp-mail-smtp-from', [ 'from_name' => 'Name Is Free' ]);
        $this->assertSame('Name Is Free', $name['result']['from_name'] ?? null, 'a field no constant defines can still change');
    }

    public function test_set_from_refuses_a_subsite_when_the_network_keeps_one_settings_record(): void
    {
        add_filter('wp_mail_smtp_options_use_global_plugin_options', '__return_true');
        if (! is_multisite()) {
            $out = $this->write('set-wp-mail-smtp-from', [ 'from_name' => 'Single Site' ]);
            $this->assertSame('Single Site', $out['result']['from_name'] ?? null, 'a single site has only its own record');
            return;
        }

        // A plugin in the shared install still hooks the deprecated blog creation action.
        $this->setExpectedDeprecated('wpmu_new_blog');
        grant_super_admin($this->admin);
        switch_to_blog(self::factory()->blog->create());
        try {
            $out = $this->write('set-wp-mail-smtp-from', [ 'from_name' => 'Subsite' ]);
        } finally {
            restore_current_blog();
        }

        $this->assertSame('wp_mail_smtp_network_settings', $out['error']['code'] ?? null);
    }
}
