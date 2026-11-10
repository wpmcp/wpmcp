<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\List_Operations;

/**
 * Issue #759: the Mutations tab pages through every undo point, list-table
 * style, shows the total, keeps the filters across pages, and offers
 * Restore on every page.
 */
class AuditLogPagingTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $_GET = [];
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    private function seed(int $count, string $tool = 'delete-post', string $prefix = 'op-'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Snapshot_Store::save(
                $prefix . $i,
                'sess-' . $prefix,
                ['object_type' => 'post', 'object_id' => $i, 'data' => ['post' => null, 'meta' => []]],
                $tool,
                str_repeat('a', 64)
            );
        }
    }

    private function render(): string
    {
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    /** @return string[] the operation ids that have a Restore button */
    private function restorable_ops(string $html): array
    {
        preg_match_all('#class="button wpmcp-restore" data-op="([^"]+)"#', $html, $m);
        return $m[1];
    }

    public function test_list_operations_honours_offset(): void
    {
        $this->seed(25);

        $out = (new List_Operations())->handle(['limit' => 20, 'offset' => 20]);

        $this->assertSame(25, $out['total_count']);
        $this->assertSame(['op-5', 'op-4', 'op-3', 'op-2', 'op-1'], array_column($out['operations'], 'operation_id'));
    }

    public function test_first_page_shows_the_total_and_a_link_to_the_next_page(): void
    {
        $this->seed(25);

        $html = $this->render();

        $this->assertCount(Audit_Log_Page::MUTATIONS_PER_PAGE, $this->restorable_ops($html));
        $this->assertStringContainsString('25 items', $html);
        $this->assertStringContainsString('tablenav-pages', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*paged=2[^"]*"#', $html);
    }

    public function test_second_page_lists_the_older_undo_points_with_restore(): void
    {
        $this->seed(25);
        $_GET['paged'] = '2';

        $html = $this->render();

        $this->assertSame(['op-5', 'op-4', 'op-3', 'op-2', 'op-1'], $this->restorable_ops($html));
        $this->assertStringContainsString('data-nonce="', $html);
        $this->assertStringContainsString('25 items', $html);
    }

    public function test_filters_are_kept_across_pages_and_counted(): void
    {
        $this->seed(22, 'update-post', 'up-');
        $this->seed(3, 'delete-post', 'del-');
        $_GET['tool_name'] = 'update-post';

        $html = $this->render();

        $this->assertStringContainsString('22 items', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*(tool_name=update-post[^"]*paged=2|paged=2[^"]*tool_name=update-post)[^"]*"#', $html);

        $_GET['paged'] = '2';
        $this->assertSame(['up-2', 'up-1'], $this->restorable_ops($this->render()));
    }

    public function test_a_page_past_the_end_shows_the_last_page(): void
    {
        $this->seed(25);
        $_GET['paged'] = '99';

        $this->assertSame(['op-5', 'op-4', 'op-3', 'op-2', 'op-1'], $this->restorable_ops($this->render()));
    }

    public function test_one_page_of_results_shows_the_total_without_page_links(): void
    {
        $this->seed(3);

        $html = $this->render();

        $this->assertStringContainsString('3 items', $html);
        $this->assertStringNotContainsString('paged=2', $html);
    }

    /** Below manage_options the total counts only the caller's own undo points (#461). */
    public function test_total_is_scoped_to_the_callers_own_rows_below_manage_options(): void
    {
        $this->seed(4);
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->seed(2, 'update-post', 'mine-');

        $html = $this->render();

        $this->assertStringContainsString('2 items', $html);
        $this->assertSame(['mine-2', 'mine-1'], $this->restorable_ops($html));
    }
}
