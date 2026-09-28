<?php

namespace WPMCP\Tests\Pro\BlockBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\BlockBuilder\Block_Spec;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\BlockBuilder\Block_Renderer;
use WPMCP\Tools\BlockBuilder\Block_Registry;
use WPMCP\Tools\BlockBuilder\Create_Custom_Block;
use WPMCP\Tools\BlockBuilder\Update_Custom_Block;
use WPMCP\Tools\BlockBuilder\Get_Custom_Block;
use WPMCP\Tools\BlockBuilder\List_Custom_Blocks;
use WPMCP\Tools\BlockBuilder\Delete_Custom_Block;
use WPMCP\Tools\BlockBuilder\Set_Block_Status;
use WPMCP\Tools\BlockBuilder\Validate_Block_Spec;
use WPMCP\Tools\BlockBuilder\List_Block_Control_Types;

/**
 * Cluster 7b (EMCP parity): the custom Gutenberg block builder, data-driven
 * (no code generation, no eval). A spec is stored as a wpmcp_block post and
 * registered via register_block_type with a render_callback that interpolates
 * attribute values into the template. Covers validation, the pure renderer's
 * per-attribute escaping, store CRUD, and real runtime registration.
 */
class CustomBlockBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function valid_spec(): array
    {
        return [
            'name'       => 'callout',
            'title'      => 'Callout',
            'category'   => 'widgets',
            'attributes' => [
                ['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Note'],
                ['name' => 'body', 'type' => 'richtext', 'label' => 'Body', 'default' => ''],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => ''],
            ],
            'template'   => '<div class="callout"><h4>{{heading}}</h4><div>{{body}}</div><a href="{{link}}">More</a></div>',
        ];
    }

    // ---- validation / control types -----------------------------------------

    public function test_valid_spec_passes(): void
    {
        $this->assertTrue(Block_Spec::validate($this->valid_spec()));
        $this->assertTrue((new Validate_Block_Spec())->handle(['spec' => $this->valid_spec()])['valid']);
    }

    public function test_spec_rejects_unknown_attribute_type(): void
    {
        $spec = $this->valid_spec();
        $spec['attributes'][0]['type'] = 'bogus';
        $err = Block_Spec::validate($spec);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('invalid_attribute', $err->get_error_code());
    }

    public function test_spec_rejects_duplicate_attribute_names(): void
    {
        $spec = $this->valid_spec();
        $spec['attributes'][1]['name'] = 'heading';
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));
    }

    public function test_list_block_control_types(): void
    {
        $types = array_column((new List_Block_Control_Types())->handle([])['attribute_types'], 'type');
        $this->assertContains('string', $types);
        $this->assertContains('richtext', $types);
        $this->assertContains('boolean', $types);
    }

    // ---- renderer -----------------------------------------------------------

    public function test_renderer_escapes_per_attribute(): void
    {
        $html = Block_Renderer::render($this->valid_spec(), [
            'heading' => 'Hi <script>x</script>',
            'body'    => '<em>ok</em><script>bad()</script>',
            'link'    => 'https://e.com/a"onmouseover="x',
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<em>ok</em>', $html);
        $this->assertStringNotContainsString('"onmouseover="', $html);
    }

    public function test_renderer_defaults_and_unknown_placeholder(): void
    {
        $spec             = $this->valid_spec();
        $spec['template'] = '{{heading}}|{{ghost}}';
        $this->assertSame('Note|', Block_Renderer::render($spec, []));
    }

    // ---- store CRUD + runtime registration ----------------------------------

    public function test_create_stores_and_normalizes_name(): void
    {
        $out = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()]);
        $this->assertSame('wpmcp_block', get_post_type($out['block_id']));
        $this->assertSame('wpmcp/callout', $out['name']);
    }

    public function test_get_list_update_delete_and_status(): void
    {
        $bid = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];

        $this->assertSame('wpmcp/callout', (new Get_Custom_Block())->handle(['block_id' => $bid])['spec']['name']);
        $this->assertContains($bid, array_column((new List_Custom_Blocks())->handle([])['blocks'], 'block_id'));

        $spec          = $this->valid_spec();
        $spec['title'] = 'Renamed';
        (new Update_Custom_Block())->handle(['block_id' => $bid, 'spec' => $spec]);
        $this->assertSame('Renamed', get_post_meta($bid, '_wpmcp_block_spec', true)['title']);

        (new Set_Block_Status())->handle(['block_id' => $bid, 'status' => 'draft']);
        $this->assertSame('draft', get_post_status($bid));

        $this->assertSame('trashed', (new Delete_Custom_Block())->handle(['block_id' => $bid])['deleted']);
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    // ---- safety model: every write to an existing block is undoable (#86) --

    public function test_update_is_snapshotted_and_rollback_restores_previous_spec(): void
    {
        $bid      = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $original = get_post_meta($bid, '_wpmcp_block_spec', true);

        $spec             = $this->valid_spec();
        $spec['title']    = 'Rewritten';
        $spec['template'] = '<p>{{heading}}</p>';
        $out = (new Update_Custom_Block())->handle(['block_id' => $bid, 'spec' => $spec, 'session_id' => 's86']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('Rewritten', get_post_meta($bid, '_wpmcp_block_spec', true)['title']);
        $this->assertSame('Rewritten', get_the_title($bid));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame($original, get_post_meta($bid, '_wpmcp_block_spec', true));
        $this->assertSame('Callout', get_post_field('post_title', $bid));
    }

    public function test_invalid_update_writes_nothing_and_records_no_operation(): void
    {
        $bid    = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $before = get_post_meta($bid, '_wpmcp_block_spec', true);
        $rows   = $this->snapshot_count();

        $spec                          = $this->valid_spec();
        $spec['attributes'][0]['type'] = 'bogus';
        $out = (new Update_Custom_Block())->handle(['block_id' => $bid, 'spec' => $spec]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame($before, get_post_meta($bid, '_wpmcp_block_spec', true));
        $this->assertSame($rows, $this->snapshot_count());
    }

    public function test_set_status_is_snapshotted_and_rollback_restores_previous_status(): void
    {
        $bid = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];

        $out = (new Set_Block_Status())->handle(['block_id' => $bid, 'status' => 'draft', 'session_id' => 's86']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('draft', get_post_status($bid));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('publish', get_post_status($bid));
    }

    public function test_delete_is_snapshotted_and_rollback_restores_the_block(): void
    {
        $bid  = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $spec = get_post_meta($bid, '_wpmcp_block_spec', true);

        $out = (new Delete_Custom_Block())->handle(['block_id' => $bid, 'session_id' => 's86']);

        $this->assertSame('trashed', $out['deleted']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('trash', get_post_status($bid));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('publish', get_post_status($bid));
        $this->assertSame($spec, get_post_meta($bid, '_wpmcp_block_spec', true));
        $this->assertSame('', (string) get_post_meta($bid, '_wp_trash_meta_status', true));
    }

    public function test_set_status_rejects_anything_but_publish_or_draft(): void
    {
        $bid  = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $rows = $this->snapshot_count();

        $out = (new Set_Block_Status())->handle(['block_id' => $bid, 'status' => 'private']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_status', $out->get_error_code());
        $this->assertSame('publish', get_post_status($bid));
        $this->assertSame($rows, $this->snapshot_count());
    }

    public function test_set_status_to_the_current_status_takes_no_snapshot(): void
    {
        $bid  = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $rows = $this->snapshot_count();

        $out = (new Set_Block_Status())->handle(['block_id' => $bid, 'status' => 'publish']);

        $this->assertTrue($out['unchanged']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame($rows, $this->snapshot_count());
    }

    public function test_deleting_an_already_trashed_block_is_refused_without_a_snapshot(): void
    {
        $bid = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        (new Delete_Custom_Block())->handle(['block_id' => $bid]);
        $rows = $this->snapshot_count();

        $out = (new Delete_Custom_Block())->handle(['block_id' => $bid]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('block_already_trashed', $out->get_error_code());
        $this->assertSame($rows, $this->snapshot_count());
    }

    public function test_an_update_whose_write_does_not_land_is_reported_and_rolled_back(): void
    {
        $bid      = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $original = get_post_meta($bid, '_wpmcp_block_spec', true);

        // Veto the spec meta write while letting the title write through, so
        // a half-applied update has to be unwound.
        $veto = static fn ($check, $object_id, $meta_key) => '_wpmcp_block_spec' === $meta_key ? false : $check;
        add_filter('update_post_metadata', $veto, 10, 3);

        $spec          = $this->valid_spec();
        $spec['title'] = 'Never stored';
        $out = (new Update_Custom_Block())->handle(['block_id' => $bid, 'spec' => $spec]);

        remove_filter('update_post_metadata', $veto, 10);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('mutation_failed', $out->get_error_code());
        $this->assertSame($original, get_post_meta($bid, '_wpmcp_block_spec', true));
        $this->assertSame('Callout', get_post_field('post_title', $bid));
    }

    public function test_a_status_change_that_does_not_land_is_reported_and_rolled_back(): void
    {
        $bid = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];

        $pin = static function (array $data): array {
            if (Block_Spec_Store::POST_TYPE === $data['post_type']) {
                $data['post_status'] = 'publish';
            }
            return $data;
        };
        add_filter('wp_insert_post_data', $pin);

        $out = (new Set_Block_Status())->handle(['block_id' => $bid, 'status' => 'draft']);

        remove_filter('wp_insert_post_data', $pin);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('mutation_failed', $out->get_error_code());
        $this->assertSame('publish', get_post_status($bid));
    }

    public function test_block_write_schemas_declare_session_id_and_the_status_enum(): void
    {
        $abilities = [];
        foreach (\WPMCP\Tests\Free\Platform\RegisteredAbilities::all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        // create-custom-block records a creation row (issue #192), so it
        // takes a session_id like every other block write.
        foreach (['create-custom-block', 'update-custom-block', 'set-block-status', 'delete-custom-block'] as $name) {
            $this->assertArrayHasKey('wpmcp/' . $name, $abilities);
            $this->assertArrayHasKey('session_id', $abilities[ 'wpmcp/' . $name ]->input_schema['properties'], $name);
        }
        $this->assertSame(
            ['publish', 'draft'],
            $abilities['wpmcp/set-block-status']->input_schema['properties']['status']['enum']
        );
    }

    public function test_update_keeps_backslashes_in_the_template(): void
    {
        $bid              = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];
        $spec             = $this->valid_spec();
        $spec['template'] = '<span class="a\\:b">{{heading}}</span>';

        $out = (new Update_Custom_Block())->handle(['block_id' => $bid, 'spec' => $spec]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($spec['template'], get_post_meta($bid, '_wpmcp_block_spec', true)['template']);
    }

    public function test_writes_to_a_missing_block_record_no_operation(): void
    {
        $before = $this->snapshot_count();

        $this->assertInstanceOf(\WP_Error::class, (new Update_Custom_Block())->handle(['block_id' => 999999, 'spec' => $this->valid_spec()]));
        $this->assertInstanceOf(\WP_Error::class, (new Set_Block_Status())->handle(['block_id' => 999999, 'status' => 'draft']));
        $this->assertInstanceOf(\WP_Error::class, (new Delete_Custom_Block())->handle(['block_id' => 999999]));

        $this->assertSame($before, $this->snapshot_count());
    }

    public function test_registry_registers_active_block_as_real_block_type(): void
    {
        (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()]);

        Block_Registry::register();

        $registry = \WP_Block_Type_Registry::get_instance();
        $this->assertTrue($registry->is_registered('wpmcp/callout'));

        // The registered block renders through the spec template.
        $type = $registry->get_registered('wpmcp/callout');
        $html = call_user_func($type->render_callback, ['heading' => 'Live']);
        $this->assertStringContainsString('Live', $html);

        $registry->unregister('wpmcp/callout');
    }
}
