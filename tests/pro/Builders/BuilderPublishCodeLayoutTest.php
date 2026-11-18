<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Builders\Update_Builder_Content;

/**
 * Follow-up to issues #890 and #893: the builder publish op is a generic
 * status path too, so it refuses to publish a theme code layout (here a
 * Kadence Pro HTML element) with code_layout, before any snapshot, and still
 * publishes an ordinary draft.
 */
class BuilderPublishCodeLayoutTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        register_post_type('kadence_element', ['public' => false, 'show_ui' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        unregister_post_type('kadence_element');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function element(string $type): int
    {
        $id = self::factory()->post->create(['post_type' => 'kadence_element', 'post_status' => 'draft', 'post_content' => '[vc_row][vc_column]<script>x()</script>[/vc_column][/vc_row]']);
        update_post_meta($id, '_kad_element_type', $type);
        return $id;
    }

    private function publish(int $id)
    {
        return (new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'wpbakery',
            'operation'     => 'publish',
            'expected_hash' => hash('sha256', (string) get_post($id)->post_content),
        ]);
    }

    public function test_builder_publish_refuses_a_kadence_html_element(): void
    {
        $id = $this->element('script');

        $out = $this->publish($id);

        $this->assertWPError($out);
        $this->assertSame('code_layout', $out->get_error_code(), $out->get_error_message());
        $this->assertStringContainsString('update-kadence-layout', $out->get_error_message());
        clean_post_cache($id);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_builder_publish_still_publishes_an_element_without_code(): void
    {
        $id = $this->element('default');

        $out = $this->publish($id);

        $this->assertNotWPError($out);
        clean_post_cache($id);
        $this->assertSame('publish', get_post_status($id));
    }
}
