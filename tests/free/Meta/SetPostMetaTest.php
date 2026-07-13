<?php

namespace WPMCP\Tests\Free\Meta;

use WPMCP\Tools\Meta\Set_Post_Meta;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class SetPostMetaTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function post(): int
    {
        $id = $this->factory()->post->create();
        $this->created[] = $id;
        return $id;
    }

    public function test_sets_meta_and_round_trips(): void
    {
        $post_id = $this->post();

        $out = (new Set_Post_Meta())->handle([
            'post_id' => $post_id,
            'key'     => 'color',
            'value'   => 'blue',
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('blue', get_post_meta($post_id, 'color', true));
    }

    public function test_refuses_a_protected_key(): void
    {
        $post_id = $this->post();

        $this->expectException(\InvalidArgumentException::class);
        (new Set_Post_Meta())->handle([
            'post_id' => $post_id,
            'key'     => '_secret',
            'value'   => 'nope',
        ]);
    }

    public function test_requires_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Post_Meta())->handle(['key' => 'color', 'value' => 'blue']);
    }

    public function test_requires_a_key(): void
    {
        $post_id = $this->post();
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Post_Meta())->handle(['post_id' => $post_id, 'value' => 'blue']);
    }
}
