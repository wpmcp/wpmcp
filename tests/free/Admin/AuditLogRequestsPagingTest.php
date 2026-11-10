<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #770: the Requests tab pages through every stored request, list-table
 * style like the Mutations tab (#759), shows the total, keeps the filters
 * across pages, and the CSV export still carries every matching row.
 */
class AuditLogRequestsPagingTest extends \WP_UnitTestCase
{
    private const KEYS = [ 'tab', 'page', 'paged', 'tool', 'outcome', 'user_id', 'date_from', 'date_to', '_wpnonce' ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Request_Log::OPTION);
        $this->clear_query();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        Request_Log::set_clock_for_tests(null);
        delete_option(Request_Log::OPTION);
        $this->clear_query();
        parent::tearDown();
    }

    private function clear_query(): void
    {
        foreach (self::KEYS as $key) {
            unset($_GET[ $key ]);
        }
    }

    /** Records $count rows, oldest first, named "$prefix1" to "$prefix$count". */
    private function seed(int $count, string $prefix = 'req-', bool $ok = true): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Request_Log::set_clock_for_tests(1700000000 + $i);
            Request_Log::record([ 'tool' => $prefix . $i, 'ok' => $ok, 'error_code' => $ok ? '' : 'boom' ]);
        }
        Request_Log::set_clock_for_tests(null);
    }

    private function render(): string
    {
        $_GET['tab'] = Audit_Log_Page::TAB_REQUESTS;
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    /** @return string[] the tool names in the rendered table, top to bottom */
    private function tools(string $html): array
    {
        preg_match_all('#<tr><td>[^<]*</td><td>([^<]+)</td>#', $html, $m);
        return $m[1];
    }

    public function test_query_honours_an_offset_and_counts_every_match(): void
    {
        $this->seed(25);

        $this->assertSame(25, Request_Log::count([]));
        $this->assertSame([ 'req-5', 'req-4', 'req-3', 'req-2', 'req-1' ], array_column(Request_Log::query([], 20, 20), 'tool'));
        $this->assertSame([ 'req-24', 'req-23' ], array_column(Request_Log::query([], 2, 1), 'tool'));
    }

    public function test_first_page_shows_the_total_and_a_link_to_the_next_page(): void
    {
        $this->seed(25);

        $html = $this->render();

        $this->assertCount(Audit_Log_Page::REQUESTS_PER_PAGE, $this->tools($html));
        $this->assertSame('req-25', $this->tools($html)[0]);
        $this->assertStringContainsString('25 items', $html);
        $this->assertStringContainsString('tablenav-pages', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*tab=requests[^"]*paged=2[^"]*"#', $html);
    }

    public function test_second_page_lists_the_older_requests(): void
    {
        $this->seed(25);
        $_GET['paged'] = '2';

        $html = $this->render();

        $this->assertSame([ 'req-5', 'req-4', 'req-3', 'req-2', 'req-1' ], $this->tools($html));
        $this->assertStringContainsString('25 items', $html);
    }

    public function test_filters_are_kept_across_pages_and_counted(): void
    {
        $this->seed(22, 'err-', false);
        $this->seed(3, 'ok-', true);
        $_GET['outcome'] = 'error';

        $html = $this->render();

        $this->assertStringContainsString('22 items', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*(outcome=error[^"]*paged=2|paged=2[^"]*outcome=error)[^"]*"#', $html);

        $_GET['paged'] = '2';
        $this->assertSame([ 'err-2', 'err-1' ], $this->tools($this->render()));
    }

    public function test_a_page_past_the_end_shows_the_last_page(): void
    {
        $this->seed(25);
        $_GET['paged'] = '99';

        $this->assertSame([ 'req-5', 'req-4', 'req-3', 'req-2', 'req-1' ], $this->tools($this->render()));
    }

    public function test_one_page_of_results_shows_the_total_without_page_links(): void
    {
        $this->seed(3);

        $html = $this->render();

        $this->assertStringContainsString('3 items', $html);
        $this->assertStringNotContainsString('paged=2', $html);
    }

    public function test_export_still_carries_every_matching_row(): void
    {
        $this->seed(25);
        $_GET['_wpnonce'] = wp_create_nonce(Audit_Log_Page::EXPORT_ACTION);
        $sent             = null;

        (new Audit_Log_Page())->export_requests(function (string $csv) use (&$sent) {
            $sent = $csv;
        });

        $this->assertNotNull($sent);
        $this->assertCount(26, array_filter(explode("\n", trim($sent))), 'a header and all 25 rows');
    }
}
