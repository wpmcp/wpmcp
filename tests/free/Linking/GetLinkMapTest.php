<?php

namespace WPMCP\Tests\Free\Linking;

use WPMCP\Tools\Linking\Get_Link_Map;

class GetLinkMapTest extends \WP_UnitTestCase
{
    /** @var int[] */
    private array $post_ids = [];

    public function tearDown(): void
    {
        foreach ($this->post_ids as $id) {
            wp_delete_post($id, true);
        }
        $this->post_ids = [];
        parent::tearDown();
    }

    public function test_reports_incoming_and_outgoing_counts(): void
    {
        $b = self::factory()->post->create(['post_title' => 'B', 'post_status' => 'publish']);
        $a = self::factory()->post->create([
            'post_title'   => 'A',
            'post_status'  => 'publish',
            'post_content' => '<a href="' . get_permalink($b) . '">B</a>',
        ]);
        $c = self::factory()->post->create(['post_title' => 'C', 'post_status' => 'publish']);
        $this->post_ids = [$a, $b, $c];

        $out = (new Get_Link_Map())->handle(['post_type' => 'post']);

        $by_id = [];
        foreach ($out['posts'] as $row) {
            $by_id[$row['id']] = $row;
        }

        $this->assertSame(1, $by_id[$a]['outgoing']);
        $this->assertSame(0, $by_id[$a]['incoming']);
        $this->assertSame(0, $by_id[$b]['outgoing']);
        $this->assertSame(1, $by_id[$b]['incoming']);

        $orphan_ids = array_column($out['orphans'], 'id');
        $this->assertContains($c, $orphan_ids);
        $this->assertContains($a, $orphan_ids);
        $this->assertNotContains($b, $orphan_ids);

        $this->assertSame($b, $out['most_linked'][0]['id'], 'B is the most-linked post');
    }

    public function test_cap_limits_posts_listed(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post_ids[] = self::factory()->post->create(['post_status' => 'publish']);
        }

        $out = (new Get_Link_Map())->handle(['post_type' => 'post', 'cap' => 2]);

        $this->assertCount(2, $out['posts']);
        $this->assertSame(4, $out['total']);
    }
}
