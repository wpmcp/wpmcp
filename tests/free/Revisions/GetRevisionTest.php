<?php

namespace WPMCP\Tests\Free\Revisions;

use WPMCP\Tools\Revisions\Get_Revision;

class GetRevisionTest extends \WP_UnitTestCase
{
    public function test_returns_revision_fields(): void
    {
        $id = self::factory()->post->create(['post_title' => 'T0', 'post_content' => 'V0']);
        wp_update_post(['ID' => $id, 'post_title' => 'T1', 'post_content' => 'V1']);

        $revisions  = wp_get_post_revisions($id);
        $revision   = array_shift($revisions);

        $out = (new Get_Revision())->handle(['revision_id' => $revision->ID]);

        $this->assertSame((int) $revision->ID, $out['revision_id']);
        $this->assertSame($id, $out['post_id']);
        $this->assertSame('T1', $out['title']);
        $this->assertSame('V1', $out['content']);
        $this->assertArrayHasKey('author_id', $out);
        $this->assertArrayHasKey('date', $out);
    }

    public function test_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Revision())->handle(['revision_id' => 999999]);
    }
}
