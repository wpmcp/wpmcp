<?php

namespace WPMCP\Tests\Safety;

use WPMCP\Safety\Snapshot_Store;

class SnapshotStoreInstallTest extends \WP_UnitTestCase {
    public function test_install_creates_table_with_columns(): void {
        global $wpdb;
        Snapshot_Store::install();
        $table = Snapshot_Store::table_name();
        $cols  = $wpdb->get_col( "DESC {$table}", 0 );
        foreach ( ['id','operation_id','session_id','object_type','object_id','tool_name','args_hash','before_blob','user_id','created_at'] as $c ) {
            $this->assertContains( $c, $cols, "missing column {$c}" );
        }
    }

    public function test_install_is_idempotent(): void {
        global $wpdb;
        Snapshot_Store::install();
        $table = Snapshot_Store::table_name();

        $wpdb->insert(
            $table,
            [
                'operation_id' => wp_generate_uuid4(),
                'session_id'   => wp_generate_uuid4(),
                'object_type'  => 'post',
                'object_id'    => 1,
                'tool_name'    => 'test_tool',
                'args_hash'    => str_repeat( 'a', 64 ),
                'before_blob'  => 'payload',
                'user_id'      => 0,
                'created_at'   => current_time( 'mysql' ),
            ]
        );

        // Running install() again must not error and must not drop existing data.
        Snapshot_Store::install();

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $this->assertSame( 1, $count, 'install() ran twice should not cause data loss' );

        $cols = $wpdb->get_col( "DESC {$table}", 0 );
        $this->assertContains( 'id', $cols );
    }
}
