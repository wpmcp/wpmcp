<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\{File_Backup, Snapshot_Store, Snapshot};

class SnapshotStoreCrudTest extends \WP_UnitTestCase {
    protected function setUp(): void { parent::setUp(); Snapshot_Store::install(); }
    public function test_save_and_get_roundtrip(): void {
        $snap = [ 'object_type'=>'post','object_id'=>7,'data'=>['post'=>['post_content'=>'A'],'meta'=>[]] ];
        Snapshot_Store::save( 'op-1', 'sess-1', $snap, 'update-blocks', str_repeat('a',64) );
        $row = Snapshot_Store::get_by_operation( 'op-1' );
        $this->assertSame( 7, $row['snapshot']['object_id'] );
        $this->assertSame( 'A', $row['snapshot']['data']['post']['post_content'] );
    }
    public function test_prune_keeps_most_recent(): void {
        for ( $i = 0; $i < 25; $i++ ) {
            Snapshot_Store::save( "op-{$i}", 'sess', ['object_type'=>'post','object_id'=>$i,'data'=>['post'=>null,'meta'=>[]]], 'update-blocks', str_repeat('a',64) );
        }
        $this->assertSame( 5, Snapshot_Store::prune( 20 ) );
        $this->assertCount( 20, Snapshot_Store::recent( 100 ) );
    }

    /**
     * The change-set builder (issue #192) needs to know whether a session
     * lost rows to pruning, and the surviving ledger cannot say: prune
     * deletes by id and sessions interleave. So prune() records it.
     */
    public function test_prune_records_how_many_rows_each_session_lost(): void {
        for ( $i = 0; $i < 3; $i++ ) {
            Snapshot_Store::save( "early-{$i}", 'early', ['object_type'=>'post','object_id'=>$i,'data'=>['post'=>null,'meta'=>[]]], 'update-blocks', str_repeat('a',64) );
        }
        for ( $i = 0; $i < 22; $i++ ) {
            Snapshot_Store::save( "late-{$i}", 'late', ['object_type'=>'post','object_id'=>$i,'data'=>['post'=>null,'meta'=>[]]], 'update-blocks', str_repeat('a',64) );
        }

        $this->assertSame( 0, Snapshot_Store::pruned_rows_for_session( 'early' ), 'Nothing recorded before a prune' );
        $this->assertSame( 5, Snapshot_Store::prune( 20 ) );

        $this->assertSame( 3, Snapshot_Store::pruned_rows_for_session( 'early' ) );
        $this->assertSame( 2, Snapshot_Store::pruned_rows_for_session( 'late' ) );
        $this->assertSame( 0, Snapshot_Store::pruned_rows_for_session( 'never-pruned' ) );

        // Counts accumulate across prunes rather than being overwritten.
        Snapshot_Store::save( 'late-22', 'late', ['object_type'=>'post','object_id'=>99,'data'=>['post'=>null,'meta'=>[]]], 'update-blocks', str_repeat('a',64) );
        Snapshot_Store::prune( 20 );
        $this->assertSame( 3, Snapshot_Store::pruned_rows_for_session( 'late' ) );
    }

    /**
     * Regression guard for issue #24: pruning a snapshot must also delete
     * its attachment file backup directory (if any), so backups do not
     * accumulate forever under wp-content/uploads/.wpmcp-backups/. Rows
     * that survive the prune must keep their backup dir untouched.
     */
    public function test_prune_deletes_backup_dirs_for_pruned_operations_only(): void {
        for ( $i = 0; $i < 25; $i++ ) {
            $op_id = "op-backup-{$i}";
            Snapshot_Store::save( $op_id, 'sess', ['object_type'=>'post','object_id'=>$i,'data'=>['post'=>null,'meta'=>[]]], 'delete-media', str_repeat('a',64) );
            File_Backup::backup( $op_id, [] ); // no real files needed; just materialize the dir + .htaccess.
            wp_mkdir_p( File_Backup::operation_dir( $op_id ) );
            file_put_contents( File_Backup::operation_dir( $op_id ) . '/marker.txt', 'x' );
        }

        Snapshot_Store::prune( 20 );

        // The oldest 5 (op-backup-0 .. op-backup-4) were pruned: their
        // backup dirs must be gone.
        for ( $i = 0; $i < 5; $i++ ) {
            $this->assertDirectoryDoesNotExist( File_Backup::operation_dir( "op-backup-{$i}" ) );
        }
        // The most recent 20 survive the prune: their backup dirs (and the
        // marker file inside) must be untouched.
        for ( $i = 5; $i < 25; $i++ ) {
            $dir = File_Backup::operation_dir( "op-backup-{$i}" );
            $this->assertDirectoryExists( $dir );
            $this->assertFileExists( $dir . '/marker.txt' );
            File_Backup::delete_backup_dir( "op-backup-{$i}" );
        }
    }
}
