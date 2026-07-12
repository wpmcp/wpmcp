<?php

namespace WPMCP\Tests\Free\Safety;

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

    public function test_capture_records_existing_option_value(): void {
        update_option( 'blogname', 'My Site' );
        $snap = Snapshot::capture( 'option', 'blogname' );
        $this->assertSame( 'option', $snap['object_type'] );
        $this->assertSame( 'blogname', $snap['object_id'] );
        $this->assertSame( 'blogname', $snap['data']['name'] );
        $this->assertSame( 'My Site', $snap['data']['value'] );
        $this->assertTrue( $snap['data']['existed'] );
    }

    public function test_capture_records_nonexistent_option(): void {
        delete_option( 'wpmcp_test_missing_option' );
        $snap = Snapshot::capture( 'option', 'wpmcp_test_missing_option' );
        $this->assertFalse( $snap['data']['existed'] );
    }

    public function test_capture_records_comment_row_and_meta(): void {
        $post_id    = self::factory()->post->create();
        $comment_id = self::factory()->comment->create( [
            'comment_post_ID'      => $post_id,
            'comment_content'      => 'Nice post',
            'comment_approved'     => '1',
            'comment_author'       => 'Ada',
            'comment_author_email' => 'ada@example.com',
            'comment_author_url'   => 'https://example.com',
        ] );
        update_comment_meta( $comment_id, 'rating', '5' );

        $snap = Snapshot::capture( 'comment', $comment_id );

        $this->assertSame( 'comment', $snap['object_type'] );
        $this->assertSame( $comment_id, $snap['object_id'] );
        $this->assertSame( 'Nice post', $snap['data']['comment']['comment_content'] );
        $this->assertSame( '1', $snap['data']['comment']['comment_approved'] );
        $this->assertSame( 'Ada', $snap['data']['comment']['comment_author'] );
        $this->assertSame( 'ada@example.com', $snap['data']['comment']['comment_author_email'] );
        $this->assertSame( 'https://example.com', $snap['data']['comment']['comment_author_url'] );
        $this->assertSame( '5', $snap['data']['meta']['rating'][0] );
    }
}
