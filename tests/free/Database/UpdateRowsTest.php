<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Update_Rows;

class UpdateRowsTest extends \WP_UnitTestCase
{
    public function test_disabled_by_default(): void
    {
        global $wpdb;

        $this->expectException(\RuntimeException::class);
        (new Update_Rows())->handle([
            'table' => $wpdb->options,
            'data'  => ['option_value' => '2'],
            'where' => ['option_name' => 'x'],
        ]);
    }

    /**
     * update-rows self-reports recoverable:false (no generic-table rollback),
     * so it must confirm like delete-rows: without confirm:true it is refused
     * even when the write filter is enabled.
     */
    public function test_requires_confirm_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Rows())->handle([
            'table' => $wpdb->options,
            'data'  => ['option_value' => '2'],
            'where' => ['option_name' => 'x'],
        ]);
    }

    public function test_requires_non_empty_where_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => ['option_value' => '2'],
            'where'   => [],
            'confirm' => true,
        ]);
    }

    public function test_requires_non_empty_data_when_enabled(): void
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

    public function test_refuses_protected_table_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Update_Rows())->handle([
            'table'   => $wpdb->users,
            'data'    => ['user_email' => 'x@example.com'],
            'where'   => ['ID' => 1],
            'confirm' => true,
        ]);
    }

    public function test_updates_rows_and_returns_before_image_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');
        add_option('wpmcp_update_rows_test', 'before');

        $result = (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => ['option_value' => 'after'],
            'where'   => ['option_name' => 'wpmcp_update_rows_test'],
            'confirm' => true,
        ]);

        $this->assertSame($wpdb->options, $result['table']);
        $this->assertSame(1, $result['affected']);
        // wp_options has a primary key, so since issue #82 this write is
        // snapshot-backed and genuinely restorable.
        $this->assertTrue($result['recoverable']);
        $this->assertNotEmpty($result['operation_id']);
        $this->assertNotEmpty($result['before_image']);
        $this->assertSame('before', $result['before_image'][0]['option_value']);

        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('wpmcp_update_rows_test', 'options');
        $this->assertSame('after', get_option('wpmcp_update_rows_test'));
    }

    public function test_rejects_unknown_table_when_enabled(): void
    {
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Update_Rows())->handle([
            'table'   => 'wp_this_table_does_not_exist',
            'data'    => ['a' => 1],
            'where'   => ['b' => 1],
            'confirm' => true,
        ]);
    }
}
