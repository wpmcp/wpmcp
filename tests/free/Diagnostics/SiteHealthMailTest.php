<?php

namespace WPMCP\Tests\Free\Diagnostics;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Confirmation_Required;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Diagnostics\Get_Site_Health;

/**
 * get-site-health mail_test (issue #415): report how the site sends mail
 * (transport, the plugin hooking wp_mail, the effective From) with no
 * credentials, and send one test message to the caller's own address or the
 * site admin email, confirmed, rate limited and audit-logged.
 *
 * Nothing here sends real mail: wp_mail() runs against the test framework's
 * MockPHPMailer, and the transport report only inspects a scratch PHPMailer
 * that is never sent.
 */
class SiteHealthMailTest extends \WP_UnitTestCase
{
    private const FIXTURE_SLUG = 'wpmcp-fixture-mailer';

    /** @var float Fake clock read by the tool under test. */
    private float $now = 1000.0;

    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        reset_phpmailer_instance();
        delete_option(Governance_Audit_Log::OPTION);
        $this->admin_id = self::factory()->user->create(['role' => 'administrator', 'user_email' => 'caller@example.org']);
        wp_set_current_user($this->admin_id);
        update_option('admin_email', 'owner@example.org');
        Get_Site_Health::reset_mail_test_limit($this->admin_id);
        // Changing admin_email mails a notice; start each test with no mail sent.
        reset_phpmailer_instance();
    }

    protected function tearDown(): void
    {
        remove_all_actions('phpmailer_init');
        remove_all_filters('pre_wp_mail');
        remove_all_filters('wp_mail_from');
        remove_all_filters('wp_mail_from_name');
        $dir = WP_PLUGIN_DIR . '/' . self::FIXTURE_SLUG;
        @unlink($dir . '/' . self::FIXTURE_SLUG . '.php');
        @rmdir($dir);
        wp_cache_delete('plugins', 'plugins');
        reset_phpmailer_instance();
        parent::tearDown();
    }

    private function tool(): Get_Site_Health
    {
        return new Get_Site_Health(fn (): float => $this->now);
    }

    /** @return array<int, array<string, mixed>> */
    private static function sent(): array
    {
        return tests_retrieve_phpmailer_instance()->mock_sent;
    }

    /**
     * Install a plugin under WP_PLUGIN_DIR whose phpmailer_init callback
     * switches PHPMailer to SMTP with credentials, the way an SMTP plugin
     * does, and hook it.
     */
    private function install_smtp_plugin(): void
    {
        $dir  = WP_PLUGIN_DIR . '/' . self::FIXTURE_SLUG;
        $file = $dir . '/' . self::FIXTURE_SLUG . '.php';
        if (! is_dir($dir)) {
            $this->assertTrue(mkdir($dir, 0777, true));
        }
        $code = <<<'PHP'
<?php
/**
 * Plugin Name: Fixture SMTP Mailer
 */
if (! function_exists('wpmcp_fixture_mailer_configure')) {
    function wpmcp_fixture_mailer_configure($phpmailer) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.mail-relay.test';
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = 'relay-user-7731';
        $phpmailer->Password   = 'hunter2-secret-password';
    }
}
PHP;
        $this->assertNotFalse(file_put_contents($file, $code));
        require_once $file;
        wp_cache_delete('plugins', 'plugins');
        add_action('phpmailer_init', 'wpmcp_fixture_mailer_configure');
    }

    public function test_schema_advertises_mail_test_and_confirm(): void
    {
        $found = null;
        foreach (RegisteredAbilities::all() as $ability) {
            if ('wpmcp/get-site-health' === $ability->name) {
                $found = $ability;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('string', $found->input_schema['properties']['mail_test']['type']);
        $this->assertSame('boolean', $found->input_schema['properties']['confirm']['type']);
        $this->assertStringContainsString('mail_test', $found->description);
    }

    public function test_report_names_smtp_transport_and_plugin_without_secrets(): void
    {
        $this->install_smtp_plugin();

        $out  = $this->tool()->handle(['mail_test' => '']);
        $mail = $out['mail'];

        $this->assertArrayNotHasKey('results', $out, 'mail_test does not run the Site Health tests');
        $this->assertSame('smtp', $mail['transport']);
        $this->assertSame('smtp.mail-relay.test', $mail['smtp']['host']);
        $this->assertSame(587, $mail['smtp']['port']);
        $this->assertSame('tls', $mail['smtp']['encryption']);
        $this->assertTrue($mail['smtp']['auth']);

        $plugins = array_column($mail['plugins'], 'name', 'plugin');
        $this->assertSame('Fixture SMTP Mailer', $plugins[self::FIXTURE_SLUG] ?? null, 'the hooking plugin is named');
        $this->assertNull($mail['test'], 'an empty mail_test only reports');

        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('hunter2-secret-password', $json);
        $this->assertStringNotContainsString('relay-user-7731', $json);
        $this->assertSame([], self::sent(), 'a report sends nothing');
    }

    public function test_default_transport_is_php_mail_and_suggests_a_mailer(): void
    {
        $mail = $this->tool()->handle(['mail_test' => ''])['mail'];

        $this->assertSame('mail', $mail['transport']);
        $this->assertSame([], $mail['plugins']);
        $host = (string) wp_parse_url(network_home_url(), PHP_URL_HOST);
        $this->assertSame('wordpress@' . preg_replace('#^www\.#', '', $host), $mail['from']['email']);
        $this->assertSame('WordPress', $mail['from']['name']);
        $this->assertStringContainsString('SMTP', implode(' ', $mail['next_steps']));
    }

    public function test_reports_filtered_from_and_flags_a_foreign_domain(): void
    {
        add_filter('wp_mail_from', static fn (): string => 'noreply@unrelated-domain.test');
        add_filter('wp_mail_from_name', static fn (): string => 'Shop Robot');

        $mail = $this->tool()->handle(['mail_test' => ''])['mail'];

        $this->assertSame('noreply@unrelated-domain.test', $mail['from']['email']);
        $this->assertSame('Shop Robot', $mail['from']['name']);
        $this->assertStringContainsString('unrelated-domain.test', implode(' ', $mail['next_steps']));
    }

    public function test_pre_wp_mail_hook_reports_a_plugin_transport(): void
    {
        add_filter('pre_wp_mail', static fn ($pre) => $pre);

        $mail = $this->tool()->handle(['mail_test' => ''])['mail'];

        $this->assertSame('plugin', $mail['transport']);
    }

    public function test_sends_to_the_callers_own_address_when_confirmed(): void
    {
        $mail = $this->tool()->handle(['mail_test' => 'Caller@Example.org', 'confirm' => true])['mail'];

        $this->assertTrue($mail['test']['sent']);
        $this->assertSame('caller@example.org', $mail['test']['recipient']);
        $this->assertNull($mail['test']['error']);

        $sent = self::sent();
        $this->assertCount(1, $sent);
        $this->assertSame('caller@example.org', $sent[0]['to'][0][0]);

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame('wpmcp/get-site-health', $entry['ability']);
        $this->assertTrue($entry['allowed']);
        $this->assertSame('mail-test:sent:self', $entry['reason']);
    }

    public function test_sends_to_the_site_admin_email(): void
    {
        $mail = $this->tool()->handle(['mail_test' => 'owner@example.org', 'confirm' => true])['mail'];

        $this->assertTrue($mail['test']['sent']);
        $this->assertCount(1, self::sent());
        $this->assertSame('mail-test:sent:admin', Governance_Audit_Log::list(1)[0]['reason']);
    }

    public function test_refuses_any_other_recipient_and_logs_it(): void
    {
        try {
            $this->tool()->handle(['mail_test' => 'stranger@example.net', 'confirm' => true]);
            $this->fail('an arbitrary recipient must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotInstanceOf(Confirmation_Required::class, $e);
            $this->assertStringContainsString('admin email', $e->getMessage());
        }

        $this->assertSame([], self::sent());
        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertFalse($entry['allowed']);
        $this->assertSame('mail-test:refused-recipient', $entry['reason']);
    }

    public function test_requires_confirm_before_sending(): void
    {
        $this->expectException(Confirmation_Required::class);
        try {
            $this->tool()->handle(['mail_test' => 'caller@example.org']);
        } finally {
            $this->assertSame([], self::sent());
        }
    }

    public function test_requires_manage_options_to_send(): void
    {
        $user = self::factory()->user->create(['role' => 'editor', 'user_email' => 'editor@example.org']);
        wp_set_current_user($user);

        try {
            $this->tool()->handle(['mail_test' => 'editor@example.org', 'confirm' => true]);
            $this->fail('sending needs manage_options');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('manage_options', $e->getMessage());
        }

        $this->assertSame([], self::sent());
    }

    public function test_returns_the_exact_failure_reason(): void
    {
        add_filter('wp_mail_from', static fn (): string => 'not-an-address');

        $mail = $this->tool()->handle(['mail_test' => 'caller@example.org', 'confirm' => true])['mail'];

        $this->assertFalse($mail['test']['sent']);
        $this->assertStringContainsString('not-an-address', (string) $mail['test']['error']);
        $this->assertSame('mail-test:failed:self', Governance_Audit_Log::list(1)[0]['reason']);
        $this->assertFalse(Governance_Audit_Log::list(1)[0]['allowed']);
    }

    public function test_test_sends_are_rate_limited_per_user(): void
    {
        for ($i = 0; $i < Get_Site_Health::MAIL_TEST_LIMIT; $i++) {
            $this->tool()->handle(['mail_test' => 'caller@example.org', 'confirm' => true]);
            $this->now += 1;
        }
        $this->assertCount(Get_Site_Health::MAIL_TEST_LIMIT, self::sent());

        try {
            $this->tool()->handle(['mail_test' => 'caller@example.org', 'confirm' => true]);
            $this->fail('the send past the limit must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
        $this->assertCount(Get_Site_Health::MAIL_TEST_LIMIT, self::sent());
        $this->assertSame('mail-test:rate-limited', Governance_Audit_Log::list(1)[0]['reason']);

        $this->now += Get_Site_Health::MAIL_TEST_WINDOW;
        $mail = $this->tool()->handle(['mail_test' => 'caller@example.org', 'confirm' => true])['mail'];
        $this->assertTrue($mail['test']['sent'], 'the window slides');
    }
}
