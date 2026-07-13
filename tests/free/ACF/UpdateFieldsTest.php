<?php

namespace WPMCP\Tests\Free\ACF;

use WPMCP\Tools\ACF\Update_Fields;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class UpdateFieldsTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('wpmcp_enable_acf_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_acf_write', '__return_true');
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function registerGroup(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_test_update',
            'title'    => 'WPMCP Test Update Group',
            'fields'   => [
                [
                    'key'   => 'field_wpmcp_test_update_text',
                    'label' => 'Test Text',
                    'name'  => 'wpmcp_test_text',
                    'type'  => 'text',
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'post',
                    ],
                ],
            ],
        ]);
    }

    private function post(): int
    {
        $id = $this->factory()->post->create();
        $this->created[] = $id;
        return $id;
    }

    /**
     * ACF caches field values in an in-request store (acf_get_store('values'))
     * that is not invalidated by a direct postmeta rewrite, such as the one
     * rollback-operation performs. Reset it before reading a value that may
     * have been restored outside of ACF's own update_field() so the read
     * reflects the database rather than a stale in-process cache. Mirrors the
     * wc_delete_product_transients() cache-bypass already used in
     * UpdateProductTest for the same class of staleness.
     */
    private function freshField(string $selector, int $post_id)
    {
        $store = acf_get_store('values');
        if ($store) {
            $store->reset();
        }
        return get_field($selector, $post_id);
    }

    public function test_updates_field_value(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $this->registerGroup();
        $post_id = $this->post();
        update_field('wpmcp_test_text', 'before', $post_id);

        $out = (new Update_Fields())->handle([
            'post_id' => $post_id,
            'fields'  => ['wpmcp_test_text' => 'after'],
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('after', get_field('wpmcp_test_text', $post_id));
    }

    public function test_update_is_snapshotted_and_rollback_restores_prior_value(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $this->registerGroup();
        $post_id = $this->post();
        update_field('wpmcp_test_text', 'original value', $post_id);

        $out = (new Update_Fields())->handle([
            'post_id' => $post_id,
            'fields'  => ['wpmcp_test_text' => 'mutated value'],
        ]);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('mutated value', get_field('wpmcp_test_text', $post_id));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $this->assertSame('original value', $this->freshField('wpmcp_test_text', $post_id));
    }

    public function test_refused_when_acf_write_disabled(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        remove_filter('wpmcp_enable_acf_write', '__return_true');

        $this->registerGroup();
        $post_id = $this->post();
        update_field('wpmcp_test_text', 'untouched', $post_id);

        try {
            (new Update_Fields())->handle([
                'post_id' => $post_id,
                'fields'  => ['wpmcp_test_text' => 'should not apply'],
            ]);
            $this->fail('Expected a refusal while the tool is disabled.');
        } catch (\RuntimeException $e) {
            $this->assertSame('untouched', get_field('wpmcp_test_text', $post_id));
        }
    }

    public function test_requires_post_id(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Fields())->handle(['fields' => ['wpmcp_test_text' => 'x']]);
    }

    public function test_requires_fields(): void
    {
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }

        $post_id = $this->post();
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Fields())->handle(['post_id' => $post_id]);
    }
}
