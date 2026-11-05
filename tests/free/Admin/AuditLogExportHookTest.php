<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\Guarded_Handler;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #587: Export CSV on the Requests tab opened a critical error page.
 * admin-post.php fires admin_post_{action} with no arguments, so WordPress
 * hands each callback one empty string; Guarded_Handler forwarded it, and
 * export_requests(?callable $sender) threw a TypeError on ''. These tests
 * go through the real admin_post_wpmcp_export_request_log hook, the way
 * admin-post.php calls it, not through the method.
 */
class AuditLogExportHookTest extends \WP_UnitTestCase
{
    private const HOOK = 'admin_post_' . Audit_Log_Page::EXPORT_ACTION;

    /** @var array{0: string, 1: string}|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Request_Log::OPTION);
        $_GET = [];
        Request_Log::record([ 'tool' => 'wpmcp/get-page', 'client' => 'user:1', 'ok' => true ]);
        Request_Log::record([ 'tool' => 'wpmcp/update-post', 'client' => 'user:1', 'ok' => false, 'error_code' => 'boom' ]);
        $this->sent = null;
        Audit_Log_Page::send_for_tests(function (string $csv, string $filename): void {
            $this->sent = [ $csv, $filename ];
        });
    }

    protected function tearDown(): void
    {
        Audit_Log_Page::send_for_tests(null);
        delete_option(Request_Log::OPTION);
        $_GET = [];
        parent::tearDown();
    }

    private function admin(): void
    {
        $id = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            grant_super_admin($id);
        }
        wp_set_current_user($id);
    }

    public function test_the_real_hook_downloads_the_filtered_csv(): void
    {
        $this->admin();
        $_GET['_wpnonce'] = wp_create_nonce(Audit_Log_Page::EXPORT_ACTION);
        $_GET['outcome']  = 'error';

        do_action(self::HOOK);

        $this->assertNotNull($this->sent, 'The export ran and produced a CSV');
        $this->assertStringStartsWith('time_utc,tool,', $this->sent[0]);
        $this->assertStringContainsString('wpmcp/update-post', $this->sent[0]);
        $this->assertStringNotContainsString('wpmcp/get-page', $this->sent[0]);
        $this->assertMatchesRegularExpression('/^wpmcp-request-log-\d{8}-\d{6}\.csv$/', $this->sent[1]);
    }

    public function test_the_real_hook_refuses_a_wrong_nonce_with_a_403(): void
    {
        $this->admin();
        $_GET['_wpnonce'] = 'bogus';

        try {
            do_action(self::HOOK);
            $this->fail('Expected a 403.');
        } catch (\WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertNull($this->sent);
    }

    public function test_the_real_hook_refuses_a_user_without_the_capability_with_a_403(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $_GET['_wpnonce'] = wp_create_nonce(Audit_Log_Page::EXPORT_ACTION);

        try {
            do_action(self::HOOK);
            $this->fail('Expected a 403.');
        } catch (\WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertNull($this->sent);
    }

    /**
     * The root cause, for every guarded handler: admin_post_* and wp_ajax_*
     * carry no arguments, so the '' WordPress passes is not forwarded to a
     * handler whose first parameter is a test seam.
     */
    public function test_guarded_handler_does_not_forward_the_empty_hook_argument(): void
    {
        $this->admin();
        $received = null;
        add_action('admin_post_wpmcp_test_587', new Guarded_Handler(static function (?callable $seam = null) use (&$received): void {
            $received = func_get_args();
        }));

        do_action('admin_post_wpmcp_test_587');

        $this->assertSame([], $received);
    }
}
