<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Tools\Database\Delete_Rows;

class DeleteRowsTest extends \WP_UnitTestCase
{
    public function test_disabled_by_default(): void
    {
        global $wpdb;

        $this->expectException(\RuntimeException::class);
        (new Delete_Rows())->handle([
            'table'   => $wpdb->options,
            'where'   => ['option_name' => 'x'],
            'confirm' => true,
        ]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Rows())->handle([
            'table' => $wpdb->options,
            'where' => ['option_name' => 'x'],
        ]);
    }

    public function test_requires_non_empty_where_after_confirm(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Rows())->handle([
            'table'   => $wpdb->options,
            'where'   => [],
            'confirm' => true,
        ]);
    }

    public function test_refuses_protected_table_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Delete_Rows())->handle([
            'table'   => $wpdb->users,
            'where'   => ['ID' => 1],
            'confirm' => true,
        ]);
    }

    public function test_deletes_rows_and_returns_before_image_when_enabled(): void
    {
        global $wpdb;
        add_filter('wpmcp_enable_db_writes', '__return_true');
        add_option('wpmcp_delete_rows_test', 'gone-soon');

        $result = (new Delete_Rows())->handle([
            'table'   => $wpdb->options,
            'where'   => ['option_name' => 'wpmcp_delete_rows_test'],
            'confirm' => true,
        ]);

        $this->assertSame($wpdb->options, $result['table']);
        $this->assertSame(1, $result['affected']);
        // wp_options has a primary key, so since issue #82 this delete is
        // snapshot-backed and genuinely restorable.
        $this->assertTrue($result['recoverable']);
        $this->assertNotEmpty($result['operation_id']);
        $this->assertNotEmpty($result['before_image']);
        $this->assertSame('gone-soon', $result['before_image'][0]['option_value']);

        wp_cache_delete('alloptions', 'options');
        $this->assertFalse(get_option('wpmcp_delete_rows_test'));
    }

    public function test_rejects_unknown_table_when_enabled(): void
    {
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $this->expectException(\RuntimeException::class);
        (new Delete_Rows())->handle([
            'table'   => 'wp_this_table_does_not_exist',
            'where'   => ['a' => 1],
            'confirm' => true,
        ]);
    }
}
