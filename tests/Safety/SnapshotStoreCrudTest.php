<?php

namespace WPMCP\Tests\Safety;

use WPMCP\Safety\{Snapshot_Store, Snapshot};

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
}
