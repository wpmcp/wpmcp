<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\List_Media;

/**
 * list-media (issue #64): enumerate the Media Library with type/date/search
 * filters and paging. Fills the plain gap where get-media can read ONE
 * attachment but nothing can list them.
 */
class ListMediaTest extends \WP_UnitTestCase
{
    private int $jpeg_id;
    private int $png_id;
    private int $pdf_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jpeg_id = (int) $this->factory->attachment->create([
            'post_title'     => 'Sunset over the harbor',
            'post_mime_type' => 'image/jpeg',
            'post_date'      => '2024-01-15 10:00:00',
        ]);
        $this->png_id = (int) $this->factory->attachment->create([
            'post_title'     => 'Company logo',
            'post_mime_type' => 'image/png',
            'post_date'      => '2024-06-01 09:30:00',
        ]);
        $this->pdf_id = (int) $this->factory->attachment->create([
            'post_title'     => 'Price list document',
            'post_mime_type' => 'application/pdf',
            'post_date'      => '2024-06-20 14:00:00',
        ]);
        update_post_meta($this->jpeg_id, '_wp_attachment_image_alt', 'A harbor at sunset');
    }

    public function test_lists_all_attachments_with_paging_envelope(): void
    {
        $out = (new List_Media())->handle([]);

        $this->assertSame(3, $out['total']);
        $this->assertSame(1, $out['page']);
        $this->assertSame(1, $out['pages']);
        $this->assertCount(3, $out['items']);

        $ids = array_column($out['items'], 'media_id');
        $this->assertContains($this->jpeg_id, $ids);
        $this->assertContains($this->png_id, $ids);
        $this->assertContains($this->pdf_id, $ids);
    }

    public function test_item_shape_includes_core_fields(): void
    {
        $out  = (new List_Media())->handle(['search' => 'Sunset']);
        $item = $out['items'][0];

        $this->assertSame($this->jpeg_id, $item['media_id']);
        $this->assertSame('Sunset over the harbor', $item['title']);
        $this->assertSame('image/jpeg', $item['mime_type']);
        $this->assertSame('A harbor at sunset', $item['alt']);
        $this->assertArrayHasKey('url', $item);
        $this->assertArrayHasKey('date', $item);
    }

    public function test_filters_by_broad_type(): void
    {
        $out = (new List_Media())->handle(['type' => 'image']);

        $this->assertSame(2, $out['total']);
        $ids = array_column($out['items'], 'media_id');
        $this->assertNotContains($this->pdf_id, $ids);
    }

    public function test_filters_by_exact_mime_type(): void
    {
        $out = (new List_Media())->handle(['type' => 'image/png']);

        $this->assertSame(1, $out['total']);
        $this->assertSame($this->png_id, $out['items'][0]['media_id']);
    }

    public function test_filters_by_search(): void
    {
        $out = (new List_Media())->handle(['search' => 'logo']);

        $this->assertSame(1, $out['total']);
        $this->assertSame($this->png_id, $out['items'][0]['media_id']);
    }

    public function test_filters_by_date_range(): void
    {
        $out = (new List_Media())->handle(['after' => '2024-05-01', 'before' => '2024-06-10']);

        $this->assertSame(1, $out['total']);
        $this->assertSame($this->png_id, $out['items'][0]['media_id']);
    }

    public function test_pages_through_results_newest_first(): void
    {
        $page1 = (new List_Media())->handle(['per_page' => 1, 'page' => 1]);
        $page2 = (new List_Media())->handle(['per_page' => 1, 'page' => 2]);

        $this->assertSame(3, $page1['total']);
        $this->assertSame(3, $page1['pages']);
        $this->assertCount(1, $page1['items']);
        // Newest first: the PDF (2024-06-20) leads, then the PNG (2024-06-01).
        $this->assertSame($this->pdf_id, $page1['items'][0]['media_id']);
        $this->assertSame($this->png_id, $page2['items'][0]['media_id']);
    }

    public function test_per_page_is_capped_at_100(): void
    {
        $out = (new List_Media())->handle(['per_page' => 5000]);

        $this->assertSame(100, $out['per_page']);
    }
}
