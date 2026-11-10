<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Diagnostics\Mail_Check;

/**
 * The WP Mail SMTP ops on the theme pair against the real WP Mail SMTP: its
 * Options class (the encrypted SMTP password, constants that win over
 * settings, set() merging), its phpmailer_init and from filters, and its
 * container.
 *
 * It runs only in the local gate's live WP Mail SMTP leg (bin/test-local.sh,
 * or bin/test-local.sh --live-wp-mail-smtp alone), which sets
 * WPMCP_LIVE_WP_MAIL_SMTP=1, installs WP Mail SMTP from wordpress.org on a
 * separate WordPress install and loads it in tests/bootstrap.php. Everywhere
 * else the plugin is absent and the test is skipped; the live leg runs with
 * --fail-on-skipped so it cannot pass vacuously.
 *
 * No mail leaves the machine: the mailer is "Other SMTP" pointed at port 1
 * on 127.0.0.1, which refuses every connection, the one confirmed send is
 * short-circuited through pre_wp_mail before PHPMailer runs, and every other
 * test send is unconfirmed. The set-from write is rolled back and the option
 * compared byte for byte.
 *
 * @group wp-mail-smtp-live
 */
class WpMailSmtpLiveTest extends \WP_UnitTestCase
{
    private const PASSWORD = 'Live-Smtp-Password-42';

    private int $admin;

    /** @var array<string,mixed> */
    private array $configured = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WPMS_PLUGIN_VER') || ! class_exists('WPMailSMTP\\Options') || ! function_exists('wp_mail_smtp')) {
            $this->markTestSkipped('Needs the real WP Mail SMTP (bin/test-local.sh --live-wp-mail-smtp, WPMCP_LIVE_WP_MAIL_SMTP=1).');
        }
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_email' => 'ada@example.test' ]);
        wp_set_current_user($this->admin);
        Mail_Check::reset_limit($this->admin);

        // Configured through the plugin's own API, as its settings screen saves.
        \WPMailSMTP\Options::init()->set([
            'mail'    => [ 'mailer' => 'smtp', 'from_email' => 'hello@example.test', 'from_name' => 'Example Site', 'from_email_force' => true, 'from_name_force' => true, 'return_path' => false ],
            'smtp'    => [ 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'autotls' => false, 'auth' => true, 'user' => 'mailer@example.test', 'pass' => self::PASSWORD ],
            'general' => [ 'do_not_send' => false ],
        ], false, false);
        $this->configured = (array) get_option('wp_mail_smtp');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wp_mail_smtp_options_is_const_enabled');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    public function test_the_real_plugin_is_detected_by_its_own_marker(): void
    {
        // The stub-backed suite's double is loaded in this process too; the
        // real classes must be the ones that won.
        $this->assertStringContainsString('/wp-mail-smtp/', wp_normalize_path((string) (new \ReflectionClass('WPMailSMTP\\Options'))->getFileName()));
        $this->assertStringContainsString('/wp-mail-smtp/', wp_normalize_path((string) (new \ReflectionFunction('wp_mail_smtp'))->getFileName()));
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        foreach ([ 'get-wp-mail-smtp-config', 'get-wp-mail-smtp-log', 'send-wp-mail-smtp-test', 'set-wp-mail-smtp-from' ] as $op) {
            $this->assertTrue($ops[ $op ]['dependency_met'], "{$op} sees WP Mail SMTP " . WPMS_PLUGIN_VER);
        }
    }

    public function test_config_reads_the_plugins_options_and_never_returns_the_password_in_any_form(): void
    {
        $stored = (string) $this->configured['smtp']['pass'];
        $this->assertNotSame(self::PASSWORD, $stored, 'WP Mail SMTP stores the SMTP password encrypted');
        $this->assertSame(self::PASSWORD, \WPMailSMTP\Options::init()->get('smtp', 'pass'), 'and decrypts it on read');

        $config = $this->read('get-wp-mail-smtp-config', [ 'all_mailers' => true ]);

        $this->assertSame('smtp', $config['mailer']);
        $this->assertSame('Other SMTP', $config['mailer_title']);
        $this->assertSame('hello@example.test', $config['from_email']);
        $this->assertSame('Example Site', $config['from_name']);
        $this->assertTrue($config['from_email_forced']);
        $this->assertFalse($config['do_not_send']);
        $this->assertFalse($config['pro']);
        $this->assertSame([], $config['constants']);
        $this->assertSame('127.0.0.1', $config['mailer_settings']['host']);
        $this->assertSame(1, (int) $config['mailer_settings']['port']);
        $this->assertTrue($config['mailer_settings']['authentication']);
        $this->assertSame('[redacted]', $config['mailer_settings']['pass']);
        $this->assertSame('[redacted]', $config['mailer_settings']['user']);

        $json = (string) wp_json_encode($config);
        foreach ([ self::PASSWORD, $stored, 'mailer@example.test' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_constants_win_and_the_ops_say_so(): void
    {
        if (! defined('WPMS_MAIL_FROM')) {
            define('WPMS_MAIL_FROM', 'const@example.test');
        }
        if (! defined('WPMS_SMTP_PASS')) {
            define('WPMS_SMTP_PASS', 'Constant-Smtp-Password-77');
        }
        // WP Mail SMTP honours its constants only while WPMS_ON is true or
        // this filter of its own says so; other tests leave it off.
        add_filter('wp_mail_smtp_options_is_const_enabled', '__return_true');

        $config = $this->read('get-wp-mail-smtp-config');
        $this->assertSame('const@example.test', $config['from_email']);
        $this->assertContains('mail.from_email', $config['constants']);
        $this->assertContains('smtp.pass', $config['constants']);
        $this->assertStringNotContainsString('Constant-Smtp-Password-77', (string) wp_json_encode($config));

        $rows = Snapshot_Store::row_count();
        $out  = $this->write('set-wp-mail-smtp-from', [ 'from_email' => 'news@example.test' ]);
        $this->assertSame('wp_mail_smtp_constant_override', $out['error']['code'] ?? null);
        $this->assertSame($this->configured, get_option('wp_mail_smtp'));
        $this->assertSame($rows, Snapshot_Store::row_count());

        $name = $this->write('set-wp-mail-smtp-from', [ 'from_name' => 'Name Without Constant' ]);
        $this->assertSame('Name Without Constant', $name['result']['from_name'] ?? null, (string) wp_json_encode($name));
        $this->assertTrue(Rollback_Service::restore_operation((string) $name['operation_id']));
        $this->assertSame($this->configured, get_option('wp_mail_smtp'));
    }

    public function test_set_from_goes_through_the_plugin_keeps_the_mailer_and_rolls_back_exactly(): void
    {
        $out = $this->write('set-wp-mail-smtp-from', [ 'from_email' => 'news@example.test', 'from_name' => 'Newsroom' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'from_email' => 'news@example.test', 'from_name' => 'Newsroom' ], $out['result']);
        $this->assertTrue($out['recoverable']);

        $options = \WPMailSMTP\Options::init();
        $this->assertSame('smtp', $options->get('mail', 'mailer'), 'the mailer is kept');
        $this->assertSame('127.0.0.1', $options->get('smtp', 'host'));
        $this->assertSame(self::PASSWORD, $options->get('smtp', 'pass'), 'the SMTP password survives the merge');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, which WP Mail SMTP answers.
        $this->assertSame('news@example.test', apply_filters('wp_mail_from', 'wordpress@example.org'), 'WP Mail SMTP now sends from the new address');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, which WP Mail SMTP answers.
        $this->assertSame('Newsroom', apply_filters('wp_mail_from_name', 'WordPress'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($this->configured, get_option('wp_mail_smtp'), 'rollback restores the option exactly');
        $fresh = new \WPMailSMTP\Options();
        $this->assertSame('hello@example.test', $fresh->get('mail', 'from_email'), 'the plugin reads the prior address back');
        $this->assertSame(self::PASSWORD, $fresh->get('smtp', 'pass'));
    }

    public function test_send_test_reports_the_plugins_smtp_transport_without_sending(): void
    {
        $calls = 0;
        add_filter('wp_mail', static function ($args) use (&$calls) {
            $calls++;
            return $args;
        });

        $report = $this->read('send-wp-mail-smtp-test', [ 'recipient' => 'ada@example.test' ]);

        remove_all_filters('wp_mail');
        $this->assertSame(0, $calls, 'an unconfirmed test never calls wp_mail()');
        $this->assertSame('smtp', $report['transport'], (string) wp_json_encode($report));
        $this->assertSame('127.0.0.1', $report['smtp']['host']);
        $this->assertSame(1, $report['smtp']['port']);
        $this->assertTrue($report['smtp']['auth']);
        $this->assertContains('wp-mail-smtp', array_column($report['plugins'], 'plugin'));
        $this->assertSame([ 'email' => 'hello@example.test', 'name' => 'Example Site' ], $report['from']);
        $this->assertSame('confirmation_required', $report['test']['status']);
        $json = (string) wp_json_encode($report);
        $this->assertStringNotContainsString(self::PASSWORD, $json);
        $this->assertStringNotContainsString('mailer@example.test', $json);
    }

    public function test_a_confirmed_send_is_short_circuited_before_anything_leaves(): void
    {
        $sent = [];
        add_filter('pre_wp_mail', static function ($return, array $atts) use (&$sent) {
            $sent[] = $atts['to'];
            return true;
        }, 10, 2);

        $report = $this->read('send-wp-mail-smtp-test', [ 'recipient' => 'ada@example.test', 'confirm' => true ]);

        remove_all_filters('pre_wp_mail');
        $this->assertSame([ 'ada@example.test' ], $sent);
        $this->assertTrue($report['test']['sent']);
    }

    public function test_the_log_is_off_on_the_free_plugin(): void
    {
        $log = $this->read('get-wp-mail-smtp-log');

        $this->assertFalse($log['logging_enabled']);
        $this->assertFalse($log['pro']);
        $this->assertSame([], $log['emails']);
    }
}
