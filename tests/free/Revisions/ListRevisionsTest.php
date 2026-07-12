<?php

namespace WPMCP\Tests\Free\Revisions;

use WPMCP\Tools\Revisions\List_Revisions;

class ListRevisionsTest extends \WP_UnitTestCase
{
    public function test_lists_revisions_for_a_post(): void
    {
        $id = self::factory()->post->create(['post_content' => 'V0']);

        wp_update_post(['ID' => $id, 'post_content' => 'V1']);
        wp_update_post(['ID' => $id, 'post_content' => 'V2']);

        $out = (new List_Revisions())->handle(['post_id' => $id]);

        $this->assertArrayHasKey('revisions', $out);
        $this->assertCount(2, $out['revisions']);

        $first = $out['revisions'][0];
        $this->assertArrayHasKey('revision_id', $first);
        $this->assertArrayHasKey('author_id', $first);
        $this->assertArrayHasKey('date', $first);
        $this->assertArrayHasKey('excerpt', $first);
    }

    public function test_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new List_Revisions())->handle(['post_id' => 999999]);
    }
}
