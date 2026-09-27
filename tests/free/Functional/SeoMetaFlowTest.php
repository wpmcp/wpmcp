<?php

namespace WPMCP\Tests\Free\Functional;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\SEO\Update_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;

/**
 * End-to-end agent-realistic flow: set a post's SEO title/description via
 * update-seo-meta, change it again, confirm list-operations surfaces the
 * mutation, then roll back and confirm the original SEO meta is restored.
 */
class SeoMetaFlowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // update-seo-meta re-checks edit_post on the target post.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if ('' === SEO_Adapter::active_plugin()) {
            $this->markTestSkipped('No supported SEO plugin (Yoast/RankMath) active.');
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_set_update_list_and_rollback_seo_meta_round_trips(): void
    {
        $post_id    = self::factory()->post->create(['post_title' => 'A Post']);
        $session_id = 'seo-flow-session-' . uniqid();

        $first = (new Update_SEO_Meta())->handle([
            'post_id'     => $post_id,
            'session_id'  => $session_id,
            'title'       => 'Original SEO Title',
            'description' => 'Original SEO description.',
        ]);
        $this->assertSame('Original SEO Title', $first['title']);

        $second = (new Update_SEO_Meta())->handle([
            'post_id'    => $post_id,
            'session_id' => $session_id,
            'title'      => 'Changed SEO Title',
        ]);
        $this->assertSame('Changed SEO Title', $second['title']);
        // Untouched field survives the partial update.
        $this->assertSame('Original SEO description.', $second['description']);

        $current = SEO_Adapter::get_meta($post_id);
        $this->assertSame('Changed SEO Title', $current['title']);

        $ops = (new List_Operations())->handle(['session_id' => $session_id]);
        $this->assertSame(2, $ops['total_count']);
        foreach ($ops['operations'] as $op) {
            $this->assertSame('update-seo-meta', $op['tool_name']);
            $this->assertTrue($op['rollback_available']);
        }

        // Roll back only the second operation: title reverts, description
        // (never touched by that mutation) is untouched by definition.
        $result = (new Rollback_Operation())->handle(['operation_id' => $second['operation_id']]);
        $this->assertTrue($result['restored']);

        $restored = SEO_Adapter::get_meta($post_id);
        $this->assertSame('Original SEO Title', $restored['title']);
        $this->assertSame('Original SEO description.', $restored['description']);
    }
}
