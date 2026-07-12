<?php

namespace WPMCP\Tests\Safety;

use WPMCP\Safety\Snapshot;

class SnapshotCaptureTest extends \WP_UnitTestCase {
    public function test_capture_records_content_and_builder_meta(): void {
        $id = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->' ] );
        update_post_meta( $id, '_elementor_data', '[{"id":"abc"}]' );
        $snap = Snapshot::capture( 'post', $id );
        $this->assertSame( '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->', $snap['data']['post']['post_content'] );
        $this->assertSame( '[{"id":"abc"}]', $snap['data']['meta']['_elementor_data'][0] );
    }

    public function test_serialize_roundtrip(): void {
        $before = [ 'object_type' => 'post', 'object_id' => 5, 'data' => [ 'post' => [ 'post_content' => 'x' ], 'meta' => [] ] ];
        $this->assertEquals( $before, Snapshot::unserialize( Snapshot::serialize( $before ) ) );
    }
}
