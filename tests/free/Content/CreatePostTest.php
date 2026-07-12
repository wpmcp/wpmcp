<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Create_Post;

class CreatePostTest extends \WP_UnitTestCase
{
    public function test_creates_post_and_returns_id_and_permalink(): void
    {
        $out = (new Create_Post())->handle([
            'post_type' => 'post',
            'title'     => 'Hello',
            'content'   => '<p>Hi</p>',
            'status'    => 'draft',
        ]);

        $this->assertArrayHasKey('post_id', $out);
        $this->assertArrayHasKey('permalink', $out);

        $post = get_post($out['post_id']);
        $this->assertNotNull($post);
        $this->assertSame('Hello', $post->post_title);
        $this->assertSame('<p>Hi</p>', $post->post_content);
        $this->assertSame('draft', $post->post_status);
    }

    public function test_rejects_internal_post_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle(['post_type' => 'revision', 'title' => 'x']);
    }

    public function test_rejects_unknown_post_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle(['post_type' => 'no_such_type', 'title' => 'x']);
    }

    public function test_rejects_protected_meta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle([
            'post_type' => 'post',
            'title'     => 'x',
            'meta'      => ['_elementor_data' => '[]'],
        ]);
    }

    public function test_applies_terms_and_meta(): void
    {
        $term = self::factory()->category->create(['name' => 'News']);

        $out = (new Create_Post())->handle([
            'post_type' => 'post',
            'title'     => 'x',
            'terms'     => ['category' => [$term]],
            'meta'      => ['my_field' => 'v'],
        ]);

        $assigned = wp_get_post_terms($out['post_id'], 'category', ['fields' => 'ids']);
        $this->assertContains($term, $assigned);
        $this->assertSame('v', get_post_meta($out['post_id'], 'my_field', true));
    }
}
