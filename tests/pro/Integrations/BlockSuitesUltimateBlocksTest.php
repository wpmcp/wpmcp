<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suite;
use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suites, Ultimate Blocks (issue #649) through the block-suites
 * dispatcher pair.
 *
 * Ultimate Blocks registers its blocks on the server from block.json files
 * and renders them in PHP, building each block's CSS from its attributes at
 * render time, keyed by blockID (the editor's whole client id). Its editor
 * script adds blockID and ubCustomCSS to every ub/ block it loads, which the
 * server-side schemas do not all declare, so attributes the server does not
 * know are allowed while declared ones keep their types.
 *
 * Ultimate Blocks is not installed in the test core: its blocks are stood in
 * by registering their block.json schemas and presence is toggled through
 * the wpmcp_block_suite_active filter.
 */
class BlockSuitesUltimateBlocksTest extends \WP_UnitTestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'ultimate-blocks' => true ];

    /** @var callable */
    private $presence;

    /** @var string[] */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);

        $this->register('ub/styled-box', [
            'blockID' => [ 'type' => 'string', 'default' => '' ],
            'text'    => [ 'type' => 'array', 'default' => [ '' ] ],
            'mode'    => [ 'type' => 'string', 'default' => '' ],
        ]);
        $this->register('ub/counter', [
            'startNumber' => [ 'type' => 'string', 'default' => '0' ],
            'endNumber'   => [ 'type' => 'string', 'default' => '100' ],
        ]);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        foreach ($this->registered as $name) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function register(string $name, array $attributes): void
    {
        register_block_type($name, [ 'title' => $name, 'attributes' => $attributes ]);
        $this->registered[] = $name;
    }

    private function read(string $op, array $args = []): array
    {
        return ( new Block_Suites_Integration() )->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return ( new Block_Suites_Integration() )->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array{0:int,1:string} */
    private function post(string $content = ''): array
    {
        $id = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => $content ]);
        return [ $id, hash('sha256', (string) get_post($id)->post_content) ];
    }

    private function content(int $id): string
    {
        clean_post_cache($id);
        return (string) get_post($id)->post_content;
    }

    private function blocks(int $id): array
    {
        return array_values(array_filter(parse_blocks($this->content($id)), static fn ($b) => null !== $b['blockName']));
    }

    public function test_list_suites_reports_ultimate_blocks(): void
    {
        $by = array_column($this->read('list-suites')['result']['suites'], null, 'suite');

        $this->assertSame('Ultimate Blocks', $by['ultimate-blocks']['label']);
        $this->assertSame('blockID', $by['ultimate-blocks']['unique_id_attribute']);
        $this->assertSame('ub/', $by['ultimate-blocks']['namespace']);
        $this->assertSame('render_time', $by['ultimate-blocks']['css_model']);
        $this->assertTrue($by['ultimate-blocks']['active']);
        $this->assertSame('ULTIMATE_BLOCKS_VERSION', Block_Suite::spec('ultimate-blocks')['constant']);
    }

    public function test_the_pair_registers_while_only_ultimate_blocks_is_active(): void
    {
        $this->assertTrue(( new Block_Suites_Integration() )->should_register());
        $this->active = [];
        $this->assertFalse(( new Block_Suites_Integration() )->should_register());
    }

    public function test_ub_blocks_are_not_taken_for_spectra_blocks(): void
    {
        $this->assertSame('ultimate-blocks', Block_Suite::suite_of('ub/styled-box'));
        $this->assertSame('spectra', Block_Suite::suite_of('uagb/advanced-heading'));
    }

    public function test_schemas_keep_declared_types_and_allow_editor_attributes(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'ultimate-blocks' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $by = array_column($out['result']['blocks'], null, 'name');
        $this->assertContains('blockID', $by['ub/counter']['attributes'], 'the editor gives every ub/ block a blockID');
        $this->assertSame('allowed', $out['result']['unknown_attributes']);

        [ $id, $hash ] = $this->post('');
        $out           = $this->write('insert-block', [
            'suite' => 'ultimate-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:ub/styled-box {"text":"not an array"} /-->',
        ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null, 'a declared type is still checked');
        $this->assertSame('', $this->content($id));
    }

    public function test_insert_generates_a_client_id_shaped_block_id_and_rollback_restores(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $original      = $this->content($id);
        $out           = $this->write('insert-block', [
            'suite' => 'ultimate-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:ub/styled-box {"mode":"notification","text":["Heads up"],"ubCustomCSS":"selector { color: red; }"} /-->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node = $this->blocks($id)[0];
        $this->assertMatchesRegularExpression(self::UUID, (string) $node['attrs']['blockID']);
        $this->assertSame('selector { color: red; }', $node['attrs']['ubCustomCSS'], 'an editor-only attribute is kept');
        $this->assertSame('render_time', $out['result']['css']['model']);
        $this->assertFalse($out['result']['css']['refreshed']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
    }

    public function test_update_keeps_the_block_id(): void
    {
        $uid     = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash('<!-- wp:ub/styled-box {"blockID":"' . $uid . '","mode":"notification"} /-->') ]);
        $hash = hash('sha256', $this->content($id));

        $out = $this->write('update-block', [ 'suite' => 'ultimate-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'attrs' => [ 'mode' => 'bordered' ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->blocks($id)[0];
        $this->assertSame($uid, $node['attrs']['blockID']);
        $this->assertSame('bordered', $node['attrs']['mode']);
    }
}
