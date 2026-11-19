<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;

require_once __DIR__ . '/../../support/email-log-stubs.php';

/**
 * Email log counts (issue #905): emaillog-email-stats counts sent and failed
 * emails over a period and names the most recent failure, masked like a list.
 */
class EmailLogStatsTest extends \WP_UnitTestCase
{
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

    private function stats(array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => 'emaillog-email-stats', 'args' => $args ]);
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

    public function test_counts_sent_and_failed_in_the_period_and_names_the_latest_failure_masked(): void
    {
        wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-01 08:00:00', 'error' => 'Old failure outside the window' ]);
        wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-10 09:00:00' ]);
        wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-11 09:00:00' ]);
        wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-12 09:00:00', 'error' => 'SMTP Error: Could not connect to SMTP host.' ]);
        $latest = wpmcp_test_wpml_mail([ 'timestamp' => '2026-11-13 09:00:00', 'receiver' => 'Ada Lovelace <ada@example.com>', 'subject' => 'Reset for ada@example.com', 'error' => 'Could not send to ada@example.com: https://x.test/wp-login.php?action=rp&key=SENTINEL-KEY&login=ada' ]);

        $out = $this->ok($this->stats([ 'since' => '2026-11-05', 'until' => '2026-11-20' ]));
        $this->assertSame('wp-mail-logging', $out['source']);
        $this->assertSame('2026-11-05 00:00:00', $out['since']);
        $this->assertSame('2026-11-20 00:00:00', $out['until']);
        $this->assertSame(4, $out['total']);
        $this->assertSame(2, $out['sent']);
        $this->assertSame(2, $out['failed']);
        $this->assertTrue($out['masked']);
        $this->assertSame($latest, $out['last_failure']['id']);
        $this->assertSame('2026-11-13 09:00:00', $out['last_failure']['time']);
        $this->assertSame('a***@example.com', $out['last_failure']['recipient']);
        $this->assertSame('Reset for a***@example.com', $out['last_failure']['subject']);
        $this->assertStringContainsString('[redacted-link]', $out['last_failure']['error']);

        $json = (string) wp_json_encode($out);
        foreach ([ 'ada@example.com', 'Lovelace', 'SENTINEL-KEY', 'Old failure' ] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
    }

    public function test_without_a_period_it_covers_the_last_seven_days_and_no_failure_is_null(): void
    {
        $now = current_time('mysql');
        wpmcp_test_wpml_mail([ 'timestamp' => $now ]);
        wpmcp_test_wpml_mail([ 'timestamp' => gmdate('Y-m-d H:i:s', strtotime($now) - 10 * DAY_IN_SECONDS), 'error' => 'too old' ]);

        $out = $this->ok($this->stats());
        $this->assertSame(1, $out['total']);
        $this->assertSame(1, $out['sent']);
        $this->assertSame(0, $out['failed']);
        $this->assertNull($out['last_failure']);
        $this->assertSame(gmdate('Y-m-d', strtotime($now) - 7 * DAY_IN_SECONDS), substr($out['since'], 0, 10));
    }

    public function test_check_and_log_email_and_email_log_count_from_result(): void
    {
        foreach ([ 'wpmcp_check_email_active' => 'wpmcp_test_check_email_mail', 'wpmcp_email_log_active' => 'wpmcp_test_email_log_mail' ] as $filter => $make) {
            $this->only($filter);
            $make([ 'result' => 1 ]);
            $failed = $make([ 'result' => 0, 'error_message' => 'Mailer said no', 'sent_date' => '2026-11-11 10:00:00' ]);
            $out    = $this->ok($this->stats([ 'since' => '2026-11-01', 'until' => '2026-11-30' ]));
            $this->assertSame([ 2, 1, 1 ], [ $out['total'], $out['sent'], $out['failed'] ], $filter);
            $this->assertSame($failed, $out['last_failure']['id'], $filter);
            $this->assertSame('Mailer said no', $out['last_failure']['error'], $filter);
            $this->assertStringNotContainsString('203.0.113.7', (string) wp_json_encode($out), $filter);
        }
    }

    public function test_refusals_and_capability(): void
    {
        $this->assertSame('invalid_date', $this->code($this->stats([ 'since' => 'not a date at all' ])));
        add_filter('wpmcp_email_log_active', '__return_true');
        $this->assertSame('source_required', $this->code($this->stats()));
        remove_all_filters('wpmcp_email_log_active');

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->code($this->stats()));
    }
}
