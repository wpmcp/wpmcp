<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Content\Get_Preview_Link;

/**
 * get-preview-link (issue #283): core's preview URL for an unpublished post
 * the caller can edit, so an agent can hand the user a link after a draft edit.
 */
class GetPreviewLinkTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    /** @return array<string, array{0: string}> */
    public static function unpublished_statuses(): array
    {
        return [
            'draft'     => ['draft'],
            'pending'   => ['pending'],
            'scheduled' => ['future'],
        ];
    }

    /** @dataProvider unpublished_statuses */
    public function test_returns_core_preview_url_for_unpublished_posts(string $status): void
    {
        $args = ['post_status' => $status, 'post_title' => 'Preview me'];
        if ('future' === $status) {
            $args['post_date'] = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        }
        $post_id = self::factory()->post->create($args);

        $out = (new Get_Preview_Link())->handle(['post_id' => $post_id]);

        $this->assertSame($post_id, $out['post_id']);
        $this->assertSame($status, $out['status']);
        $this->assertSame(get_preview_post_link($post_id), $out['preview_url']);
        $this->assertStringContainsString('preview=true', $out['preview_url']);
    }

    public function test_works_for_a_draft_page(): void
    {
        $page_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft']);

        $out = (new Get_Preview_Link())->handle(['post_id' => $page_id]);

        $this->assertSame(get_preview_post_link($page_id), $out['preview_url']);
    }

    public function test_refuses_a_published_post(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);

        $this->expectException(\InvalidArgumentException::class);
        (new Get_Preview_Link())->handle(['post_id' => $post_id]);
    }

    public function test_refuses_a_draft_the_caller_cannot_edit(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'draft', 'post_author' => $this->admin]);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->expectException(\RuntimeException::class);
        (new Get_Preview_Link())->handle(['post_id' => $post_id]);
    }

    public function test_refuses_a_missing_post(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Preview_Link())->handle(['post_id' => 999999]);
    }

    public function test_refuses_a_private_plugin_type(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'wpmcp_chat_convo', 'post_status' => 'draft']);

        $this->expectException(\InvalidArgumentException::class);
        (new Get_Preview_Link())->handle(['post_id' => $post_id]);
    }

    public function test_is_registered_as_a_free_read_content_ability(): void
    {
        $ability = null;
        foreach (RegisteredAbilities::all() as $candidate) {
            if ('wpmcp/get-preview-link' === $candidate->name) {
                $ability = $candidate;
            }
        }

        $this->assertNotNull($ability);
        $this->assertSame('free', $ability->tier);
        $this->assertSame('edit_posts', $ability->capability);
        $this->assertSame('content', $ability->domain);
        $this->assertSame('read', $ability->operation);
        $this->assertTrue($ability->read_only_hint);
    }
}
