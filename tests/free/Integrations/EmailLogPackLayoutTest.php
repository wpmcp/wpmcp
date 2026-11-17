<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Email_Log_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;

require_once __DIR__ . '/../../support/email-log-stubs.php';

/**
 * Email logs (issue #905), the table layouts the main test does not cover:
 * Check and Log Email before its own upgrade added result and error_message
 * (failures are then only in its error table), and a plugin whose table is
 * missing. Its own class because the Check and Log Email table is built in the
 * pre-upgrade layout for the whole class.
 *
 * None of the three plugins is loaded in the shared test core. Presence is
 * driven through wpmcp_wp_mail_logging_active, wpmcp_check_email_active and
 * wpmcp_email_log_active, and the storage is built the way the plugins
 * create it (tests/support/email-log-stubs.php).
 */
class EmailLogPackLayoutTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_email_log_tables(false);
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

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
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

    public function test_check_and_log_email_before_its_upgrade_is_judged_from_the_error_table(): void
    {
        $this->only('wpmcp_check_email_active');
        global $wpdb;
        $ok     = wpmcp_test_check_email_mail();
        $failed = wpmcp_test_check_email_mail([ 'sent_date' => '2026-11-11 10:00:00' ]);
        $wpdb->insert($wpdb->prefix . 'check_email_error_logs', [ 'check_email_log_id' => $failed, 'content' => 'x', 'initiator' => 'y' ]);

        $list = $this->ok($this->read('emaillog-list-emails'));
        $this->assertSame([ $failed, $ok ], array_column($list['emails'], 'id'));
        $this->assertSame([ 'failed', 'sent' ], array_column($list['emails'], 'status'));
        $this->assertSame([ $failed ], array_column($this->ok($this->read('emaillog-list-emails', [ 'status' => 'failed' ]))['emails'], 'id'));
        $this->assertSame([ $ok ], array_column($this->ok($this->read('emaillog-list-emails', [ 'status' => 'sent' ]))['emails'], 'id'));
        $this->assertSame('', $list['emails'][0]['error'], 'the error table holds the message body, not an error');
    }

    public function test_a_plugin_without_its_table_answers_email_log_tables_missing(): void
    {
        global $wpdb;
        // RENAME, not DROP: the test case turns a DROP TABLE into a no-op on a real table.
        $table = $wpdb->prefix . 'wpml_mails';
        $renamed = $wpdb->query("ALTER TABLE `{$table}` RENAME TO `{$table}_away`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $this->assertNotFalse($renamed, (string) $wpdb->last_error);
        try {
            $presence = Email_Log_Pack::tables_presence();
            $this->assertIsArray($presence);
            $this->assertSame('email_log_tables_missing', $presence['code']);
            $this->assertStringContainsString('WP Mail Logging', $presence['message']);
        } finally {
            $wpdb->query("ALTER TABLE `{$table}_away` RENAME TO `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }
    }
}
