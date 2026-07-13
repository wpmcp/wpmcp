<?php

namespace WPMCP\Tests\Free\Input;

use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Content\Delete_Post;

/**
 * Input-boundary tests for the Content domain: missing required args, wrong
 * types, invalid enum values, and invalid/out-of-range ids must all fail
 * cleanly (InvalidArgumentException/RuntimeException per this domain's
 * convention), never a fatal or a silent wrong result.
 */
class ContentInputTest extends \WP_UnitTestCase
{
    public function test_create_post_rejects_non_writable_post_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle(['post_type' => 'revision', 'title' => 'x']);
    }

    public function test_create_post_rejects_unknown_post_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle(['post_type' => 'this_type_does_not_exist', 'title' => 'x']);
    }

    public function test_create_post_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle(['title' => 'x', 'status' => 'not-a-real-status']);
    }

    public function test_create_post_rejects_protected_meta_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Post())->handle(['title' => 'x', 'meta' => ['_secret' => 'nope']]);
    }

    public function test_create_post_defaults_missing_post_type_to_post(): void
    {
        // Missing (not just empty) post_type must not fatal; it defaults to 'post'.
        $result = (new Create_Post())->handle(['title' => 'No type given']);
        $this->assertSame('post', get_post($result['post_id'])->post_type);
    }

    public function test_update_post_rejects_missing_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['title' => 'x']);
    }

    public function test_update_post_rejects_zero_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => 0, 'title' => 'x']);
    }

    public function test_update_post_rejects_negative_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => -5, 'title' => 'x']);
    }

    public function test_update_post_rejects_nonexistent_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => 999999999, 'title' => 'x']);
    }

    public function test_update_post_rejects_non_numeric_post_id_string(): void
    {
        // A non-numeric string coerces to (int) 0, which must be treated as missing.
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => 'abc', 'title' => 'x']);
    }

    public function test_update_post_rejects_invalid_status(): void
    {
        $id = self::factory()->post->create();
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => $id, 'status' => 'bogus-status']);
    }

    public function test_update_post_rejects_protected_meta_key(): void
    {
        $id = self::factory()->post->create();
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => $id, 'meta' => ['_secret' => 'nope']]);
    }

    public function test_delete_post_rejects_missing_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Post())->handle([]);
    }

    public function test_delete_post_rejects_nonexistent_post_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Post())->handle(['post_id' => 999999999]);
    }

    public function test_delete_post_force_requires_confirm(): void
    {
        add_filter('wpmcp_enable_delete_post', '__return_true');
        $id = self::factory()->post->create();

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Post())->handle(['post_id' => $id, 'force' => true]);
    }
}
