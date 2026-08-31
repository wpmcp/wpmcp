<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\Get_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;

class GetSeoMetaTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function post(array $args = []): int
    {
        $id = $this->factory()->post->create($args);
        $this->created[] = $id;
        return $id;
    }

    public function test_returns_seo_meta_for_a_post(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $post_id = $this->post();
        SEO_Adapter::update_meta($post_id, [
            'title'         => 'SEO title',
            'description'   => 'SEO description',
            'focus_keyword' => 'keyword',
            'canonical'     => 'https://example.com/page',
            'noindex'       => true,
            'nofollow'      => false,
        ]);

        $out = (new Get_SEO_Meta())->handle(['post_id' => $post_id]);

        $this->assertSame($post_id, $out['post_id']);
        $this->assertSame('SEO title', $out['title']);
        $this->assertSame('SEO description', $out['description']);
        $this->assertSame('keyword', $out['focus_keyword']);
        $this->assertSame('https://example.com/page', $out['canonical']);
        $this->assertTrue($out['noindex']);
        $this->assertFalse($out['nofollow']);
    }

    public function test_returns_defaults_when_no_seo_meta_set(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $post_id = $this->post();

        $out = (new Get_SEO_Meta())->handle(['post_id' => $post_id]);

        $this->assertSame('', $out['title']);
        $this->assertFalse($out['noindex']);
    }

    public function test_requires_post_id(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Get_SEO_Meta())->handle([]);
    }

    /**
     * The description this returns is post content, and edit_posts is a
     * surface-level capability: it says the caller edits posts somewhere on
     * the site, not that they may read this one. The pro siblings in this
     * group already refused unreadable posts while this one did not, so the
     * same draft was readable through get-seo-meta and refused through
     * get-social-meta. Post_Access is now the one gate for the group.
     */
    public function test_another_authors_draft_is_refused(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $owner = $this->factory()->user->create(['role' => 'author']);
        $id    = $this->post(['post_status' => 'draft', 'post_author' => $owner]);

        wp_set_current_user($this->factory()->user->create(['role' => 'author']));

        $this->expectException(\RuntimeException::class);
        (new Get_SEO_Meta())->handle(['post_id' => $id]);
    }

    /**
     * A password-protected post is published, so the status check alone lets
     * it through, and this read takes the curated SEO description rather than
     * a display helper, so it bypasses the blanking post_password_required()
     * normally provides.
     */
    public function test_a_password_protected_post_is_refused_without_edit_post(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $owner = $this->factory()->user->create(['role' => 'author']);
        $id    = $this->post(['post_password' => 'hunter2', 'post_author' => $owner]);

        wp_set_current_user($this->factory()->user->create(['role' => 'author']));

        $this->expectException(\RuntimeException::class);
        (new Get_SEO_Meta())->handle(['post_id' => $id]);
    }

    /** An editor, who can read it in wp-admin anyway, still gets it. */
    public function test_an_editor_may_read_a_protected_post(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $id = $this->post(['post_password' => 'hunter2']);

        wp_set_current_user($this->factory()->user->create(['role' => 'editor']));

        $out = (new Get_SEO_Meta())->handle(['post_id' => $id]);

        $this->assertSame($id, $out['post_id']);
    }
}
