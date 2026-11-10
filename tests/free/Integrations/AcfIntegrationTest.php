<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\ACF_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * End-to-end proof of the dispatcher framework through the shipped reference
 * integration: ACF field read/write behind a single acf-read / acf-write
 * dispatcher pair. Write stays default-off (same posture as the flat
 * update-fields tool: the site opts in via the wpmcp_enable_acf_write
 * filter) and routes through Safe_Mutation on the post target.
 */
class AcfIntegrationTest extends \WP_UnitTestCase
{
    private ACF_Integration $integration;
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }
        Snapshot_Store::install();
        $this->integration = new ACF_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function registerGroup(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_test_dispatch',
            'title'    => 'WPMCP Dispatcher Test Group',
            'fields'   => [
                [
                    'key'   => 'field_wpmcp_test_dispatch_text',
                    'label' => 'Dispatch Text',
                    'name'  => 'wpmcp_dispatch_text',
                    'type'  => 'text',
                ],
            ],
            'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
        ]);
    }

    private function post(): int
    {
        $id              = $this->factory()->post->create();
        $this->created[] = $id;
        return $id;
    }

    /**
     * ACF caches field values in an in-request store (acf_get_store('values'))
     * that does not observe the direct postmeta writes a rollback-operation
     * performs. Reset it before reading a value that may have changed
     * underneath ACF, mirroring UpdateFieldsTest.
     */
    private function freshField(string $selector, int $post_id)
    {
        $store = acf_get_store('values');
        if ($store) {
            $store->reset();
        }
        return get_field($selector, $post_id);
    }

    private function withWritesEnabled(callable $fn)
    {
        add_filter('wpmcp_enable_acf_write', '__return_true');
        try {
            return $fn();
        } finally {
            remove_filter('wpmcp_enable_acf_write', '__return_true');
        }
    }

    public function test_reports_available_when_acf_is_active(): void
    {
        $this->assertTrue($this->integration->is_available());
        $this->assertSame('acf', $this->integration->integration());
    }

    public function test_get_fields_via_read_dispatcher(): void
    {
        $this->registerGroup();
        $post_id = $this->post();
        update_field('wpmcp_dispatch_text', 'dispatched hello', $post_id);

        $out = $this->integration->handle_read([
            'operation' => 'get-fields',
            'args'      => [ 'post_id' => $post_id ],
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('dispatched hello', $out['result']['fields']['wpmcp_dispatch_text']);
    }

    public function test_list_field_groups_via_read_dispatcher(): void
    {
        $this->registerGroup();

        $out = $this->integration->handle_read([ 'operation' => 'list-field-groups' ]);

        $this->assertArrayNotHasKey('error', $out);
        $keys = array_column($out['result']['field_groups'], 'key');
        $this->assertContains('group_wpmcp_test_dispatch', $keys);
    }

    public function test_update_fields_is_disabled_by_default(): void
    {
        $post_id = $this->post();

        $out = $this->integration->handle_write([
            'operation' => 'update-fields',
            'args'      => [ 'post_id' => $post_id, 'fields' => [ 'wpmcp_dispatch_text' => 'x' ] ],
        ]);

        $this->assertSame('opt_in_required', $out['error']['code']);
    }

    public function test_update_fields_writes_through_safe_mutation_and_rolls_back(): void
    {
        $this->registerGroup();
        $post_id = $this->post();
        update_field('wpmcp_dispatch_text', 'before', $post_id);

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'update-fields',
            'args'      => [ 'post_id' => $post_id, 'fields' => [ 'wpmcp_dispatch_text' => 'after' ] ],
        ]));

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('after', get_field('wpmcp_dispatch_text', $post_id));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertSame('before', $this->freshField('wpmcp_dispatch_text', $post_id));
    }

    public function test_update_fields_rejects_malformed_args_before_any_write(): void
    {
        $post_id = $this->post();

        $out = $this->withWritesEnabled(fn () => $this->integration->handle_write([
            'operation' => 'update-fields',
            'args'      => [ 'post_id' => $post_id ],
        ]));

        $this->assertSame('invalid_args', $out['error']['code']);
    }
}
