<?php

namespace WPMCP\Tests\Safety;

use WPMCP\Safety\{Safe_Mutation, Snapshot_Store, Mutation_Failed};

class SafeMutationTest extends \WP_UnitTestCase {
    protected function setUp(): void { parent::setUp(); Snapshot_Store::install(); }
    private function ctx( int $id ): array { return ['object_type'=>'post','object_id'=>$id,'session_id'=>'s1','tool_name'=>'update-blocks','args'=>[]]; }

    public function test_successful_run_snapshots_and_applies(): void {
        $id = self::factory()->post->create( [ 'post_content' => 'OLD' ] );
        $out = Safe_Mutation::run( $this->ctx($id), function () use ($id) {
            wp_update_post( [ 'ID' => $id, 'post_content' => 'NEW' ] );
            return 'ok';
        } );
        $this->assertSame( 'NEW', get_post( $id )->post_content );
        $this->assertNotNull( Snapshot_Store::get_by_operation( $out['operation_id'] ) );
    }

    public function test_verify_failure_rolls_back_and_throws(): void {
        $id = self::factory()->post->create( [ 'post_content' => 'OLD' ] );
        $this->expectException( Mutation_Failed::class );
        try {
            Safe_Mutation::run( $this->ctx($id),
                function () use ($id) { wp_update_post( [ 'ID' => $id, 'post_content' => 'BROKEN' ] ); },
                fn() => false
            );
        } finally {
            $this->assertSame( 'OLD', get_post( $id )->post_content );
        }
    }
}
