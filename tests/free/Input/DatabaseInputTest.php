<?php

namespace WPMCP\Tests\Free\Input;

use WPMCP\Tools\Database\Query;
use WPMCP\Tools\Database\Update_Rows;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Database\Describe_Table;

/**
 * Input-boundary tests for the Database domain: missing/empty sql or where
 * clauses, unknown/protected tables, and disabled write gates must all fail
 * cleanly (InvalidArgumentException/RuntimeException), never execute against
 * the database.
 */
class DatabaseInputTest extends \WP_UnitTestCase
{
    public function test_query_rejects_empty_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->handle(['sql' => '']);
    }

    public function test_query_rejects_whitespace_only_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query())->handle(['sql' => '   ']);
    }

    public function test_query_rejects_a_write_statement(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Query())->handle(['sql' => 'DELETE FROM wp_options']);
    }

    public function test_query_clamps_a_negative_limit_instead_of_erroring(): void
    {
        $result = (new Query())->handle(['sql' => 'SELECT 1', 'limit' => -5]);
        $this->assertSame(1, $result['row_count']);
    }

    public function test_update_rows_rejects_when_writes_disabled(): void
    {
        global $wpdb;
        $this->expectException(\RuntimeException::class);
        (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => ['option_value' => 'x'],
            'where'   => ['option_name' => 'x'],
            'confirm' => true,
        ]);
    }

    public function test_update_rows_requires_confirm_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Rows())->handle([
            'table' => $wpdb->options,
            'data'  => ['option_value' => 'x'],
            'where' => ['option_name' => 'x'],
        ]);
    }

    public function test_update_rows_rejects_empty_data(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => [],
            'where'   => ['option_name' => 'x'],
            'confirm' => true,
        ]);
    }

    public function test_update_rows_rejects_empty_where(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => ['option_value' => 'x'],
            'where'   => [],
            'confirm' => true,
        ]);
    }

    public function test_update_rows_rejects_protected_table_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Update_Rows())->handle([
            'table'   => $wpdb->users,
            'data'    => ['user_login' => 'x'],
            'where'   => ['ID' => 1],
            'confirm' => true,
        ]);
    }

    public function test_delete_rows_rejects_unknown_table_when_enabled(): void
    {
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Delete_Rows())->handle([
            'table'   => 'wp_this_table_does_not_exist_at_all',
            'where'   => ['a' => 1],
            'confirm' => true,
        ]);
    }

    public function test_describe_table_rejects_missing_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Describe_Table())->handle([]);
    }

    public function test_describe_table_rejects_unknown_table(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Describe_Table())->handle(['table' => 'wp_this_table_does_not_exist_at_all']);
    }
}
