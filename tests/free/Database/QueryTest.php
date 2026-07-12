<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Query;

class QueryTest extends \WP_UnitTestCase
{
    public function test_runs_a_read_only_select(): void
    {
        global $wpdb;

        $result = (new Query())->handle(['sql' => "SELECT option_name FROM {$wpdb->options} WHERE option_name = 'siteurl'"]);

        $this->assertSame(1, $result['row_count']);
        $this->assertFalse($result['truncated']);
        $this->assertSame('siteurl', $result['rows'][0]['option_name']);
    }

    public function test_rejects_writes(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Query())->handle(['sql' => 'DELETE FROM wp_options']);
    }

    public function test_rejects_empty_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->handle(['sql' => '']);
    }

    public function test_requires_sql_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->handle([]);
    }

    public function test_truncates_to_the_requested_limit(): void
    {
        global $wpdb;

        for ($i = 0; $i < 5; $i++) {
            $wpdb->insert($wpdb->options, ['option_name' => "wpmcp_query_test_{$i}", 'option_value' => '1', 'autoload' => 'no']);
        }

        $result = (new Query())->handle([
            'sql'   => "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wpmcp_query_test_%'",
            'limit' => 2,
        ]);

        $this->assertSame(2, $result['row_count']);
        $this->assertTrue($result['truncated']);
    }

    public function test_caps_limit_at_max_rows(): void
    {
        global $wpdb;

        $result = (new Query())->handle([
            'sql'   => "SELECT option_name FROM {$wpdb->options}",
            'limit' => 999999,
        ]);

        $this->assertLessThanOrEqual(\WPMCP\Tools\Database\Database_Guard::MAX_ROWS, $result['row_count']);
    }
}
