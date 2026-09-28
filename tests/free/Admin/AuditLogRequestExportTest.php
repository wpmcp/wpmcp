<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #303 on the audit screen: the Requests tab filters, and the CSV
 * export needs manage_options and a valid nonce.
 */
class AuditLogRequestExportTest extends \WP_UnitTestCase
{
    private const KEYS = [ 'tab', 'page', 'tool', 'outcome', 'user_id', 'date_from', 'date_to', '_wpnonce' ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Request_Log::OPTION);
        foreach (self::KEYS as $key) {
            unset($_GET[ $key ]);
        }
        Request_Log::record([ 'tool' => 'wpmcp/get-page', 'client' => 'user:1', 'ok' => true ]);
        Request_Log::record([ 'tool' => 'wpmcp/update-post', 'client' => 'user:1', 'ok' => false, 'error_code' => 'boom' ]);
    }

    protected function tearDown(): void
    {
        delete_option(Request_Log::OPTION);
        foreach (self::KEYS as $key) {
            unset($_GET[ $key ]);
        }
        parent::tearDown();
    }

    private function admin(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    private function render(): string
    {
        $_GET['tab'] = Audit_Log_Page::TAB_REQUESTS;
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    public function test_requests_tab_applies_filters_from_the_query_string(): void
    {
        $this->admin();
        $_GET['outcome'] = 'error';

        $html = $this->render();

        $this->assertStringContainsString('wpmcp/update-post', $html);
        $this->assertStringNotContainsString('<td>wpmcp/get-page</td>', $html);
        $this->assertStringContainsString('name="outcome"', $html);
        $this->assertStringContainsString('name="date_from"', $html);
        $this->assertStringContainsString('value="requests"', $html, 'The filter form stays on the Requests tab');
    }

    public function test_requests_tab_offers_a_nonced_export_link_carrying_the_filters(): void
    {
        $this->admin();
        $_GET['tool'] = 'update';

        $html = $this->render();

        $this->assertMatchesRegularExpression('/admin-post\.php\?[^"]*action=wpmcp_export_request_log/', $html);
        $this->assertStringContainsString('tool=update', $html);
        $this->assertStringContainsString('_wpnonce=', $html);
    }

    public function test_get_requests_accepts_filters(): void
    {
        $rows = (new Audit_Log_Page())->get_requests(100, [ 'tool' => 'get-page' ]);

        $this->assertCount(1, $rows);
        $this->assertSame('wpmcp/get-page', $rows[0]['tool']);
    }

    public function test_export_rejects_a_missing_or_bad_nonce(): void
    {
        $this->admin();
        $sent = null;
        $result = (new Audit_Log_Page())->export_requests(function (string $csv) use (&$sent) {
            $sent = $csv;
        });
        $this->assertInstanceOf(\WP_Error::class, $result);

        $_GET['_wpnonce'] = 'bogus';
        $result = (new Audit_Log_Page())->export_requests(function (string $csv) use (&$sent) {
            $sent = $csv;
        });
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertNull($sent);
    }

    public function test_export_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $_GET['_wpnonce'] = wp_create_nonce(Audit_Log_Page::EXPORT_ACTION);
        $sent = null;

        $result = (new Audit_Log_Page())->export_requests(function (string $csv) use (&$sent) {
            $sent = $csv;
        });

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertNull($sent);
    }

    public function test_export_sends_filtered_csv_to_an_admin(): void
    {
        $this->admin();
        $_GET['_wpnonce'] = wp_create_nonce(Audit_Log_Page::EXPORT_ACTION);
        $_GET['outcome']  = 'error';
        $sent = null;
        $name = null;

        $result = (new Audit_Log_Page())->export_requests(function (string $csv, string $filename) use (&$sent, &$name) {
            $sent = $csv;
            $name = $filename;
        });

        $this->assertNull($result);
        $this->assertStringStartsWith('time_utc,tool,', (string) $sent);
        $this->assertStringContainsString('wpmcp/update-post', (string) $sent);
        $this->assertStringNotContainsString('wpmcp/get-page', (string) $sent);
        $this->assertMatchesRegularExpression('/^wpmcp-request-log-\d{8}-\d{6}\.csv$/', (string) $name);
    }

    public function test_the_export_handler_is_registered_for_admin_post(): void
    {
        $this->assertNotFalse(has_action('admin_post_wpmcp_export_request_log'));
    }
}
