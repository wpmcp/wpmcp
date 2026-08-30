<?php

namespace WPMCP\Tests\Free\Sync;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Sync\Change_Set_Builder;

/**
 * The change set is the artifact a later phase pushes at a live site, so
 * every one of these tests is really asking the same question: does the
 * artifact tell the truth about what it carries and what it does not?
 *
 * Definition of done, phase 1 (issue #192):
 *  1. A change set derived from a build session lists exactly the objects
 *     that session touched.
 *  2. Dependencies resolve: a page's attachments and terms are included.
 *  3. The artifact is inspectable before it is applied anywhere.
 */
class ChangeSetBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    private function note(string $op, string $session, string $type, $object_id, string $tool = 'update-post'): int
    {
        return Snapshot_Store::save(
            $op,
            $session,
            ['object_type' => $type, 'object_id' => $object_id, 'data' => ['post' => null, 'meta' => []]],
            $tool,
            str_repeat('a', 64)
        );
    }

    public function test_session_change_set_lists_exactly_the_objects_the_session_touched(): void
    {
        $mine  = self::factory()->post->create(['post_title' => 'Touched']);
        $other = self::factory()->post->create(['post_title' => 'Untouched']);

        $this->note('op-1', 'sess-a', 'post', $mine);
        $this->note('op-2', 'sess-b', 'post', $other);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess-a']);

        $this->assertCount(1, $set['objects']);
        $this->assertSame($mine, $set['objects'][0]['object_id']);
        $this->assertSame('Touched', $set['objects'][0]['data']['post_title']);
    }

    public function test_repeated_writes_to_one_object_produce_one_entry(): void
    {
        $post = self::factory()->post->create();

        $this->note('op-1', 'sess', 'post', $post);
        $this->note('op-2', 'sess', 'post', $post);
        $this->note('op-3', 'sess', 'post', $post);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $this->assertCount(1, $set['objects']);
    }

    public function test_page_build_rows_export_as_posts(): void
    {
        // Build_Page snapshots its composite write as object_type page_build
        // with the numeric post id. Filtering on the raw ledger string would
        // drop exactly the pages the flagship build tool just created.
        $post = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Built']);

        $this->note('op-1', 'sess', 'page_build', $post, 'build-page');

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $this->assertCount(1, $set['objects'], 'A page_build row must reach the post exporter');
        $this->assertSame('post', $set['objects'][0]['object_type']);
        $this->assertSame($post, $set['objects'][0]['object_id']);
        $this->assertSame('Built', $set['objects'][0]['data']['post_title']);
    }

    public function test_live_side_types_are_excluded_by_design_and_gaps_are_not_dressed_up_as_policy(): void
    {
        $this->note('op-1', 'sess', 'wc_order', 7);
        $this->note('op-2', 'sess', 'term', 0);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $reasons = [];
        foreach ($set['excluded'] as $row) {
            $reasons[ $row['object_type'] ] = $row['reason'];
        }

        $this->assertStringContainsString('by design', $reasons['wc_order']);
        $this->assertStringContainsString('not implemented', $reasons['term']);
    }

    public function test_string_keyed_rows_are_reported_one_per_row_not_collapsed_into_one(): void
    {
        // object_id is 0 for every string-keyed type (Snapshot_Store keys
        // them inside the blob), so keying excluded rows on the column would
        // report three touched options as a single "object_id: 0" entry.
        $this->note('op-1', 'sess', 'option', 0, 'update-option');
        $this->note('op-2', 'sess', 'option', 0, 'update-option');
        $this->note('op-3', 'sess', 'option', 0, 'update-option');

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $this->assertSame([], $set['objects'], 'Options are not exportable yet, so they must not be counted as objects');
        $this->assertCount(3, $set['excluded']);
        $this->assertSame(
            ['op-1', 'op-2', 'op-3'],
            array_values(array_reverse(wp_list_pluck($set['excluded'], 'operation_id')))
        );
    }

    public function test_a_locally_deleted_object_is_reported_not_dropped(): void
    {
        $post = self::factory()->post->create();
        $this->note('op-1', 'sess', 'post', $post);
        wp_delete_post($post, true);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $this->assertCount(1, $set['objects']);
        $this->assertTrue($set['objects'][0]['deleted']);
    }

    public function test_attachments_resolve_from_featured_image_blocks_and_classic_markup(): void
    {
        $featured = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $in_block = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $classic  = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $content = '<!-- wp:video {"id":' . $in_block . '} --><figure class="wp-block-video"></figure><!-- /wp:video -->'
            . '<img class="wp-image-' . $classic . '" src="x.jpg" />';

        $post = self::factory()->post->create(['post_content' => $content]);
        set_post_thumbnail($post, $featured);

        $this->note('op-1', 'sess', 'post', $post);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);
        $ids = wp_list_pluck($set['dependencies']['attachments'], 'object_id');

        $this->assertContains($featured, $ids, 'The featured image is a dependency');
        $this->assertContains($in_block, $ids, 'wp:video carries its media id in block attributes, not a wp-image class');
        $this->assertContains($classic, $ids, 'Classic markup still resolves');
    }

    public function test_elementor_media_ids_resolve_from_postmeta(): void
    {
        // Elementor stores media as ['id' => N, 'url' => '...'] inside the
        // _elementor_data element tree, never in post_content, so an
        // Elementor page that resolved zero attachments would sync as a page
        // full of broken images.
        $image = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $post  = self::factory()->post->create(['post_content' => '']);

        update_post_meta($post, '_elementor_data', wp_slash(wp_json_encode([
            [
                'id'       => 'abc123',
                'elType'   => 'section',
                'elements' => [
                    [
                        'id'         => 'def456',
                        'elType'     => 'widget',
                        'widgetType' => 'image',
                        'settings'   => ['image' => ['id' => $image, 'url' => 'http://example.org/x.jpg']],
                    ],
                ],
            ],
        ])));

        $this->note('op-1', 'sess', 'post', $post);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);
        $ids = wp_list_pluck($set['dependencies']['attachments'], 'object_id');

        $this->assertContains($image, $ids);
    }

    public function test_a_stale_reference_is_reported_not_emitted_as_a_phantom_attachment(): void
    {
        $post = self::factory()->post->create([
            'post_content' => '<img class="wp-image-999999" src="x.jpg" />',
        ]);
        $this->note('op-1', 'sess', 'post', $post);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $this->assertSame([], $set['dependencies']['attachments']);
        $reasons = wp_list_pluck($set['excluded'], 'reason');
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('does not exist locally', $reasons[0]);
    }

    public function test_terms_travel_with_the_post(): void
    {
        $post = self::factory()->post->create();
        wp_set_object_terms($post, 'syncable', 'category');

        $this->note('op-1', 'sess', 'post', $post);

        $set = (new Change_Set_Builder())->build(['session_id' => 'sess']);

        $this->assertContains('syncable', $set['objects'][0]['terms']['category']);
    }

    public function test_a_marker_below_the_retention_floor_is_reported_as_truncated(): void
    {
        global $wpdb;

        $post  = self::factory()->post->create();
        $first = $this->note('op-1', 'sess', 'post', $post);
        $this->note('op-2', 'sess', 'post', $post);
        $this->note('op-3', 'sess', 'post', $post);

        // Safe_Mutation::run() prunes to the licence's history limit after
        // every write, and the free tier keeps 20 rows, so a build session
        // longer than that has already lost its earliest ledger rows by the
        // time anyone asks for a change set. A silently partial set is the
        // data-loss mode this issue exists to prevent, so it is reported.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Snapshot_Store::table_name() . ' WHERE id <= %d',
            $first
        ));

        $set = (new Change_Set_Builder())->build(['since_id' => $first - 1]);

        $this->assertTrue($set['truncated']['truncated']);
        $this->assertStringContainsString('pruned', (string) $set['truncated']['reason']);
        $this->assertSame(Snapshot_Store::min_id(), $set['truncated']['retention_floor']);
    }

    public function test_a_marker_inside_the_surviving_range_is_not_reported_as_truncated(): void
    {
        $post  = self::factory()->post->create();
        $first = $this->note('op-1', 'sess', 'post', $post);
        $this->note('op-2', 'sess', 'post', $post);

        $set = (new Change_Set_Builder())->build(['since_id' => $first]);

        $this->assertFalse($set['truncated']['truncated']);
    }

    public function test_an_operation_id_marker_resolves_to_its_ledger_row(): void
    {
        $a = self::factory()->post->create();
        $b = self::factory()->post->create();

        $this->note('op-1', 'sess', 'post', $a);
        $this->note('op-2', 'sess', 'post', $b);

        // Everything after op-1, which is op-2 alone.
        $set = (new Change_Set_Builder())->build(['operation_id' => 'op-1']);

        $this->assertCount(1, $set['objects']);
        $this->assertSame($b, $set['objects'][0]['object_id']);
    }

    public function test_an_unknown_operation_id_marker_raises(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Change_Set_Builder())->build(['operation_id' => 'never-existed']);
    }
}
