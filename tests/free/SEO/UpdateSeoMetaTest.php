<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\Update_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class UpdateSeoMetaTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // update-seo-meta re-checks edit_post on the target post.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function post(): int
    {
        $id = $this->factory()->post->create();
        $this->created[] = $id;
        return $id;
    }

    public function test_updates_title_and_description(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $post_id = $this->post();

        $out = (new Update_SEO_Meta())->handle([
            'post_id'     => $post_id,
            'title'       => 'New SEO title',
            'description' => 'New SEO description',
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertSame('New SEO title', $meta['title']);
        $this->assertSame('New SEO description', $meta['description']);
    }

    public function test_update_is_snapshotted_and_rollback_restores_prior_values(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $post_id = $this->post();
        SEO_Adapter::update_meta($post_id, [
            'title'       => 'Original title',
            'description' => 'Original description',
        ]);

        $out = (new Update_SEO_Meta())->handle([
            'post_id'     => $post_id,
            'title'       => 'Mutated title',
            'description' => 'Mutated description',
        ]);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $mutated = SEO_Adapter::get_meta($post_id);
        $this->assertSame('Mutated title', $mutated['title']);

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $restored = SEO_Adapter::get_meta($post_id);
        $this->assertSame('Original title', $restored['title']);
        $this->assertSame('Original description', $restored['description']);
    }

    public function test_requires_post_id(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Update_SEO_Meta())->handle(['title' => 'x']);
    }

    public function test_requires_at_least_one_field(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $post_id = $this->post();
        $this->expectException(\InvalidArgumentException::class);
        (new Update_SEO_Meta())->handle(['post_id' => $post_id]);
    }

    /**
     * edit_posts on the ability says the caller edits posts somewhere, not
     * that they may rewrite the SEO fields of a post someone else published.
     */
    public function test_contributor_cannot_update_another_authors_post(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $post_id = $this->post();
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->expectException(\RuntimeException::class);
        (new Update_SEO_Meta())->handle(['post_id' => $post_id, 'title' => 'Hijacked']);
    }
}
