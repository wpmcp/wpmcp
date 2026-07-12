<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Insert_Row;

class InsertRowTest extends \WP_UnitTestCase
{
    public function test_disabled_by_default(): void
    {
        global $wpdb;

        $this->expectException(\RuntimeException::class);
        (new Insert_Row())->handle(['table' => $wpdb->options, 'data' => ['option_name' => 'x', 'option_value' => '1']]);
    }

    public function test_inserts_a_row_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $result = (new Insert_Row())->handle([
            'table' => $wpdb->options,
            'data'  => ['option_name' => 'wpmcp_insert_row_test', 'option_value' => 'hello', 'autoload' => 'no'],
        ]);

        $this->assertSame($wpdb->options, $result['table']);
        $this->assertTrue($result['affected'] >= 1);
        $this->assertSame('hello', get_option('wpmcp_insert_row_test'));
    }

    public function test_requires_non_empty_data_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Insert_Row())->handle(['table' => $wpdb->options, 'data' => []]);
    }

    public function test_refuses_protected_table_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Insert_Row())->handle(['table' => $wpdb->users, 'data' => ['user_login' => 'x']]);
    }

    public function test_rejects_unknown_table_when_enabled(): void
    {
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Insert_Row())->handle(['table' => 'wp_this_table_does_not_exist', 'data' => ['a' => 1]]);
    }
}
