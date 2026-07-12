<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Delete_Media;
use WPMCP\Safety\{Snapshot_Store, Rollback_Service};

class DeleteMediaTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_disabled_by_default(): void
    {
        $id = self::factory()->attachment->create_object(['post_title' => 'Sunset']);

        $this->expectException(\RuntimeException::class);
        (new Delete_Media())->handle(['media_id' => $id, 'confirm' => true]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_media', '__return_true');
        $id = self::factory()->attachment->create_object(['post_title' => 'Sunset']);

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Media())->handle(['media_id' => $id]);
    }

    public function test_rejects_non_attachment_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_media', '__return_true');
        $id = self::factory()->post->create();

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Media())->handle(['media_id' => $id, 'confirm' => true]);
    }

    public function test_not_found_throws_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_media', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Media())->handle(['media_id' => 999999, 'confirm' => true]);
    }

    public function test_force_delete_is_safe_wrapped_and_rollback_resurrects_attachment(): void
    {
        add_filter('wpmcp_enable_delete_media', '__return_true');
        $id = self::factory()->attachment->create_object([
            'post_title'   => 'Sunset',
            'post_excerpt' => 'A caption',
        ]);
        update_post_meta($id, '_wp_attachment_image_alt', 'Sunset over the sea');

        $out = (new Delete_Media())->handle(['media_id' => $id, 'confirm' => true, 'force' => true, 'session_id' => 's1']);

        $this->assertSame('deleted', $out['deleted']);
        $this->assertNull(get_post($id));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $restored = get_post($id);
        $this->assertNotNull($restored);
        $this->assertSame('attachment', $restored->post_type);
        $this->assertSame('Sunset', $restored->post_title);
        $this->assertSame('A caption', $restored->post_excerpt);
        $this->assertSame('Sunset over the sea', get_post_meta($id, '_wp_attachment_image_alt', true));
    }
}
