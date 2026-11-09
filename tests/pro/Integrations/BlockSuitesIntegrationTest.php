<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/generateblocks-legacy-stubs.php';

/**
 * Block suite packs (issue #287), first slice: Kadence Blocks and
 * GenerateBlocks through the block-suites dispatcher pair.
 *
 * Neither suite is installed in the test core, so each test registers
 * stand-in block types with the attribute schemas the suites' block.json
 * files declare, and toggles the suite's presence through the
 * wpmcp_block_suite_active filter.
 */
class BlockSuitesIntegrationTest extends \WP_UnitTestCase
{
    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'kadence-blocks' => true, 'generateblocks' => true ];

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
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);

        $this->register('kadence/advancedheading', [
            'uniqueID' => [ 'type' => 'string', 'default' => '' ],
            'content'  => [ 'type' => 'string', 'source' => 'html', 'selector' => 'h1,h2,h3,h4,h5,h6,p' ],
            'level'    => [ 'type' => 'number', 'default' => 2 ],
            'color'    => [ 'type' => 'string', 'default' => '' ],
            'fontSize' => [ 'type' => 'array', 'default' => [ '', '', '' ] ],
            'htmlTag'  => [ 'type' => 'string', 'default' => 'heading' ],
        ]);
        $this->register('kadence/rowlayout', [
            'uniqueID'  => [ 'type' => 'string', 'default' => '' ],
            'columns'   => [ 'type' => 'number', 'default' => 2 ],
            'kbVersion' => [ 'type' => 'number', 'default' => 1 ],
        ]);
        $gb = [
            'uniqueId'      => [ 'type' => 'string', 'default' => '' ],
            'tagName'       => [ 'type' => 'string', 'default' => '', 'enum' => [ 'p', 'span', 'div', 'h1', 'h2', 'h3' ] ],
            'content'       => [ 'type' => 'rich-text', 'source' => 'rich-text', 'selector' => '.gb-text' ],
            'styles'        => [ 'type' => 'object', 'default' => [] ],
            'css'           => [ 'type' => 'string', 'default' => '' ],
            'globalClasses' => [ 'type' => 'array', 'default' => [] ],
            'htmlAttributes' => [ 'type' => 'object', 'default' => [] ],
        ];
        $this->register('generateblocks/text', $gb);
        $this->register('generateblocks/element', $gb);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        foreach ($this->registered as $name) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        delete_option('generateblocks_dynamic_css_posts');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function register(string $name, array $attributes): void
    {
        register_block_type($name, [ 'title' => $name, 'attributes' => $attributes ]);
        $this->registered[] = $name;
    }

    private function integration(): Block_Suites_Integration
    {
        return new Block_Suites_Integration();
    }

    private function read(string $op, array $args = []): array
    {
        return $this->integration()->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return $this->integration()->handle_write([ 'operation' => $op, 'args' => $args ]);
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

    private function heading_markup(string $id_attr = ''): string
    {
        $attrs = '' === $id_attr ? '{"level":2}' : sprintf('{"uniqueID":"%s","level":2}', $id_attr);
        $token = '' === $id_attr ? '__UNIQUE_ID__' : $id_attr;
        return sprintf(
            '<!-- wp:kadence/advancedheading %s --><h2 class="kt-adv-heading%s wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading%s">Hello</h2><!-- /wp:kadence/advancedheading -->',
            $attrs,
            $token,
            $token
        );
    }

    private function first_block(int $id): array
    {
        $blocks = array_values(array_filter(parse_blocks($this->content($id)), static fn ($b) => null !== $b['blockName']));
        return $blocks[0];
    }

    // ------------------------------------------------------------ surface

    public function test_pair_is_pro_and_registers_only_while_a_suite_is_active(): void
    {
        $integration = $this->integration();
        $this->assertSame('block-suites', $integration->integration());
        $this->assertSame('pro', $integration->tier());
        $this->assertTrue($integration->should_register());

        $this->active = [ 'kadence-blocks' => false, 'generateblocks' => false ];
        $this->assertFalse($integration->is_available());
        $this->assertFalse($integration->should_register(), 'no suite active, no tools');

        $this->active = [ 'kadence-blocks' => false, 'generateblocks' => true ];
        $this->assertTrue($integration->should_register());
    }

    public function test_every_operation_is_pro_tier(): void
    {
        Gate::set_pro_for_tests(false);
        $catalog = $this->read('list-operations')['result'];
        $this->assertSame([], $catalog['operations'], 'without a license no op is in the catalog');

        Gate::set_pro_for_tests(true);
        $names = array_column($this->read('list-operations')['result']['operations'], 'name');
        foreach ([ 'list-suites', 'get-block-schemas', 'insert-block', 'update-block' ] as $op) {
            $this->assertContains($op, $names);
        }
    }

    public function test_list_suites_reports_presence_unique_id_attribute_and_css_model(): void
    {
        $this->active['generateblocks'] = false;
        $suites = $this->read('list-suites')['result']['suites'];
        $by     = array_column($suites, null, 'suite');

        $this->assertTrue($by['kadence-blocks']['active']);
        $this->assertSame('uniqueID', $by['kadence-blocks']['unique_id_attribute']);
        $this->assertSame('kadence/', $by['kadence-blocks']['namespace']);
        $this->assertFalse($by['generateblocks']['active']);
        $this->assertSame('uniqueId', $by['generateblocks']['unique_id_attribute']);
        $this->assertSame('per_post_cache', $by['generateblocks']['css_model']);
        $this->assertSame('render_time', $by['kadence-blocks']['css_model']);
    }

    // ------------------------------------------------------------ schemas

    public function test_block_schemas_list_only_the_suites_registered_blocks(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'kadence-blocks' ]);
        $this->assertArrayNotHasKey('error', $out);
        $names = array_column($out['result']['blocks'], 'name');
        $this->assertContains('kadence/advancedheading', $names);
        $this->assertContains('kadence/rowlayout', $names);
        $this->assertNotContains('generateblocks/text', $names);
        $this->assertNotContains('core/paragraph', $names);
        $this->assertSame('uniqueID', $out['result']['unique_id_attribute']);
    }

    public function test_block_schema_for_one_block_carries_its_attribute_definitions(): void
    {
        $out    = $this->read('get-block-schemas', [ 'suite' => 'generateblocks', 'name' => 'generateblocks/text' ]);
        $blocks = $out['result']['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame('object', $blocks[0]['attributes']['styles']['type']);
        $this->assertSame([ 'p', 'span', 'div', 'h1', 'h2', 'h3' ], $blocks[0]['attributes']['tagName']['enum']);
    }

    public function test_block_schemas_refuse_while_the_suite_is_inactive(): void
    {
        $this->active['kadence-blocks'] = false;
        $out = $this->read('get-block-schemas', [ 'suite' => 'kadence-blocks' ]);
        $this->assertSame('suite_unavailable', $out['error']['code']);
    }

    public function test_block_schema_for_a_block_of_another_suite_is_refused(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'kadence-blocks', 'name' => 'generateblocks/text' ]);
        $this->assertSame('unknown_suite_block', $out['error']['code']);
    }

    // ------------------------------------------------------------ Kadence insert

    public function test_kadence_insert_generates_a_post_scoped_unique_id_and_fills_the_markup(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');

        $out = $this->write('insert-block', [
            'suite'         => 'kadence-blocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 1 ],
            'markup'        => $this->heading_markup(),
        ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(1, $out['operation_ids']);

        $blocks = parse_blocks($this->content($id));
        $node   = $blocks[1];
        $this->assertSame('kadence/advancedheading', $node['blockName']);
        $unique = (string) $node['attrs']['uniqueID'];
        $this->assertMatchesRegularExpression('/^' . $id . '_[0-9a-f]{6}-[0-9a-f]{2}$/', $unique);
        $this->assertStringContainsString('kt-adv-heading' . $unique . ' ', $node['innerHTML']);
        $this->assertStringContainsString('data-kb-block="kb-adv-heading' . $unique . '"', $node['innerHTML']);
        $this->assertStringNotContainsString('__UNIQUE_ID__', $this->content($id));
        $this->assertSame($unique, $out['result']['unique_ids'][0]['unique_id']);
        $this->assertSame(hash('sha256', $this->content($id)), $out['result']['content_hash']);
    }

    public function test_kadence_duplicate_or_foreign_unique_ids_are_regenerated(): void
    {
        [ $id, ] = $this->post('');
        $existing = $id . '_aaaaaa-bb';
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash($this->heading_markup($existing)) ]);
        $hash = hash('sha256', $this->content($id));

        $out = $this->write('insert-block', [
            'suite'         => 'kadence-blocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 1 ],
            'markup'        => $this->heading_markup($existing),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $blocks = parse_blocks($this->content($id));
        $new    = (string) $blocks[1]['attrs']['uniqueID'];
        $this->assertNotSame($existing, $new, 'a duplicate id is replaced');
        $this->assertStringContainsString('kt-adv-heading' . $new, $blocks[1]['innerHTML']);
        $this->assertSame($existing, $blocks[0]['attrs']['uniqueID'], 'the existing block keeps its id');

        // An id copied from another post (its numeric prefix) is re-scoped.
        $hash = hash('sha256', $this->content($id));
        $out  = $this->write('insert-block', [
            'suite'         => 'kadence-blocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 2 ],
            'markup'        => $this->heading_markup('999999_cccccc-dd'),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $third = (string) parse_blocks($this->content($id))[2]['attrs']['uniqueID'];
        $this->assertStringStartsWith($id . '_', $third);
    }

    public function test_kadence_nested_blocks_each_get_their_own_id(): void
    {
        [ $id, $hash ] = $this->post('');
        $markup = '<!-- wp:kadence/rowlayout {"columns":1} --><div class="kb-row-layout-id__UNIQUE_ID__">'
            . $this->heading_markup()
            . '</div><!-- /wp:kadence/rowlayout -->';

        $out = $this->write('insert-block', [
            'suite'         => 'kadence-blocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 0 ],
            'markup'        => $markup,
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $row     = $this->first_block($id);
        $row_id  = (string) $row['attrs']['uniqueID'];
        $head_id = (string) $row['innerBlocks'][0]['attrs']['uniqueID'];
        $this->assertNotSame($row_id, $head_id);
        $this->assertStringContainsString('kb-row-layout-id' . $row_id, $row['innerContent'][0]);
        $this->assertStringContainsString('kt-adv-heading' . $head_id, $row['innerBlocks'][0]['innerHTML']);
        $this->assertCount(2, $out['result']['unique_ids']);
    }

    // ------------------------------------------------------------ refusals

    public function test_invalid_attributes_are_refused_before_any_snapshot(): void
    {
        [ $id, $hash ] = $this->post('');
        $before        = Snapshot_Store::row_count();
        $cases         = [
            'unknown attribute' => '<!-- wp:kadence/advancedheading {"bogus":1} --><h2>x</h2><!-- /wp:kadence/advancedheading -->',
            'wrong type'        => '<!-- wp:kadence/advancedheading {"level":"two"} --><h2>x</h2><!-- /wp:kadence/advancedheading -->',
        ];
        foreach ($cases as $label => $markup) {
            $out = $this->write('insert-block', [ 'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $markup ]);
            $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null, $label);
        }

        $out = $this->write('insert-block', [
            'suite'         => 'generateblocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 0 ],
            'markup'        => '<!-- wp:generateblocks/text {"tagName":"marquee"} --><p class="gb-text">x</p><!-- /wp:generateblocks/text -->',
        ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null, 'enum');

        $this->assertSame('', $this->content($id));
        $this->assertSame($before, Snapshot_Store::row_count(), 'a refused call writes no snapshot');
    }

    public function test_a_root_block_outside_the_suite_or_unregistered_is_refused(): void
    {
        [ $id, $hash ] = $this->post('');
        $out = $this->write('insert-block', [
            'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        ]);
        $this->assertSame('unknown_suite_block', $out['error']['code']);

        $out = $this->write('insert-block', [
            'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:kadence/nope --><div></div><!-- /wp:kadence/nope -->',
        ]);
        $this->assertSame('unknown_suite_block', $out['error']['code']);
    }

    public function test_stale_hash_and_inactive_suite_are_refused(): void
    {
        [ $id, ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $out     = $this->write('insert-block', [
            'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => str_repeat('0', 64), 'path' => [ 0 ],
            'markup' => $this->heading_markup(),
        ]);
        $this->assertSame('invalid_block_edit', $out['error']['code']);
        $this->assertStringContainsString('expected_hash', $out['error']['message']);

        $this->active['kadence-blocks'] = false;
        $hash = hash('sha256', $this->content($id));
        $out  = $this->write('insert-block', [
            'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => $this->heading_markup(),
        ]);
        $this->assertSame('suite_unavailable', $out['error']['code']);
    }

    // ------------------------------------------------------------ GenerateBlocks

    public function test_generateblocks_insert_compiles_css_from_styles_under_its_unique_id(): void
    {
        [ $id, $hash ] = $this->post('');
        $styles = [
            'fontSize'                => '20px',
            'color'                   => '#ffffff',
            '&:hover'                 => [ 'color' => '#000000' ],
            '@media (max-width:767px)' => [ 'fontSize' => '16px' ],
        ];
        $markup = '<!-- wp:generateblocks/text ' . wp_json_encode([ 'tagName' => 'p', 'styles' => $styles ])
            . ' --><p class="gb-text gb-text-__UNIQUE_ID__">Hi</p><!-- /wp:generateblocks/text -->';

        $out = $this->write('insert-block', [ 'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $markup ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node = $this->first_block($id);
        $uid  = (string) $node['attrs']['uniqueId'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $uid);
        $this->assertStringContainsString('gb-text-' . $uid . '"', $node['innerHTML']);
        $this->assertSame(
            ".gb-text-{$uid}{color:#ffffff;font-size:20px}.gb-text-{$uid}:hover{color:#000000}@media (max-width:767px){.gb-text-{$uid}{font-size:16px}}",
            $node['attrs']['css']
        );
    }

    public function test_generateblocks_style_values_that_break_out_of_a_rule_are_refused(): void
    {
        [ $id, $hash ] = $this->post('');
        $markup = '<!-- wp:generateblocks/text ' . wp_json_encode([ 'styles' => [ 'color' => 'red}body{display:none' ] ])
            . ' --><p class="gb-text">x</p><!-- /wp:generateblocks/text -->';
        $out = $this->write('insert-block', [ 'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $markup ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null);
        $this->assertSame('', $this->content($id));
    }

    public function test_generateblocks_write_and_rollback_invalidate_its_per_post_css_cache(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $original      = $this->content($id);
        update_option('generateblocks_dynamic_css_posts', [ $id => true, 12345 => true ]);

        $out = $this->write('insert-block', [
            'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ],
            'markup' => '<!-- wp:generateblocks/text {"styles":{"color":"#111111"}} --><p class="gb-text gb-text-__UNIQUE_ID__">x</p><!-- /wp:generateblocks/text -->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['result']['css']['refreshed']);
        $this->assertSame([ 12345 => true ], get_option('generateblocks_dynamic_css_posts'), 'the written post CSS is rebuilt on next view');

        // GenerateBlocks rebuilt the file on a page view, then the write is undone.
        update_option('generateblocks_dynamic_css_posts', [ $id => true, 12345 => true ]);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
        $this->assertSame([ 12345 => true ], get_option('generateblocks_dynamic_css_posts'), 'rollback rebuilds the CSS too');
    }

    public function test_rollback_leaves_the_cache_alone_while_generateblocks_is_inactive(): void
    {
        [ $id, $hash ] = $this->post('');
        $out = $this->write('insert-block', [
            'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:generateblocks/text --><p class="gb-text">x</p><!-- /wp:generateblocks/text -->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $this->active['generateblocks'] = false;
        update_option('generateblocks_dynamic_css_posts', [ $id => true ]);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame('', $this->content($id));
        $this->assertSame([ $id => true ], get_option('generateblocks_dynamic_css_posts'));
    }

    public function test_kadence_write_reports_render_time_css(): void
    {
        [ $id, $hash ] = $this->post('');
        $out = $this->write('insert-block', [ 'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $this->heading_markup() ]);
        $this->assertSame('render_time', $out['result']['css']['model']);
        $this->assertFalse($out['result']['css']['refreshed']);
    }

    // ------------------------------------------------------------ update

    public function test_update_merges_attributes_keeps_the_id_and_recompiles_css(): void
    {
        [ $id, $hash ] = $this->post('');
        $this->write('insert-block', [
            'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:generateblocks/text {"tagName":"p","styles":{"color":"#111111"}} --><p class="gb-text gb-text-__UNIQUE_ID__">x</p><!-- /wp:generateblocks/text -->',
        ]);
        $before = $this->content($id);
        $uid    = (string) $this->first_block($id)['attrs']['uniqueId'];

        $out = $this->write('update-block', [
            'suite'         => 'generateblocks',
            'id'            => $id,
            'expected_hash' => hash('sha256', $before),
            'path'          => [ 0 ],
            'attrs'         => [ 'styles' => [ 'color' => '#222222', 'paddingTop' => '1rem' ] ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node = $this->first_block($id);
        $this->assertSame($uid, $node['attrs']['uniqueId']);
        $this->assertSame('p', $node['attrs']['tagName'], 'untouched attributes are kept');
        $this->assertSame(".gb-text-{$uid}{color:#222222;padding-top:1rem}", $node['attrs']['css']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($before, $this->content($id));
    }

    public function test_update_refuses_to_change_the_unique_id_or_target_another_suite(): void
    {
        [ $id, ] = $this->post('');
        $unique  = $id . '_abcdef-12';
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash($this->heading_markup($unique) . '<!-- wp:paragraph --><p>p</p><!-- /wp:paragraph -->') ]);
        $hash = hash('sha256', $this->content($id));

        $out = $this->write('update-block', [ 'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'attrs' => [ 'uniqueID' => 'x' ] ]);
        $this->assertSame('unique_id_immutable', $out['error']['code']);

        $out = $this->write('update-block', [ 'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'attrs' => [ 'level' => 3 ] ]);
        $this->assertSame('unknown_suite_block', $out['error']['code']);

        $out = $this->write('update-block', [ 'suite' => 'kadence-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'attrs' => [ 'level' => 3, 'color' => null ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->first_block($id);
        $this->assertSame(3, $node['attrs']['level']);
        $this->assertSame($unique, $node['attrs']['uniqueID']);
    }

    /** A GenerateBlocks 1.x block as GenerateBlocks 2.x registers it: no attributes of its own. */
    private function register_legacy(string $name): void
    {
        register_block_type($name, [ 'title' => $name, 'render_callback' => static fn () => '' ]);
        $this->registered[] = $name;
    }

    private const LEGACY_HEADLINE = '<!-- wp:generateblocks/headline {"uniqueId":"a1b2c3d4","element":"h3","textColor":"#c0392b"} -->'
        . '<h3 class="gb-headline gb-headline-a1b2c3d4 gb-headline-text">GB legacy headline</h3>'
        . '<!-- /wp:generateblocks/headline -->';

    /** Issue #794: a saved 1.x Headline takes an attribute edit, and keeps its id. */
    public function test_generateblocks_legacy_headline_takes_an_attribute_edit_and_keeps_its_id(): void
    {
        $this->register_legacy('generateblocks/headline');
        [ $id, $hash ] = $this->post(self::LEGACY_HEADLINE);

        $out = $this->write('update-block', [ 'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'attrs' => [ 'textColor' => '#1e8449' ] ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $attrs = parse_blocks($this->content($id))[0]['attrs'];
        $this->assertSame([ 'uniqueId' => 'a1b2c3d4', 'element' => 'h3', 'textColor' => '#1e8449' ], $attrs);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame(self::LEGACY_HEADLINE, $this->content($id), 'the rollback restores the exact bytes');
    }

    public function test_generateblocks_legacy_headline_takes_an_inner_html_edit(): void
    {
        $this->register_legacy('generateblocks/headline');
        [ $id, $hash ] = $this->post(self::LEGACY_HEADLINE);

        $out = $this->write('update-block', [
            'suite'         => 'generateblocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 0 ],
            'inner_html'    => '<h3 class="gb-headline gb-headline-a1b2c3d4 gb-headline-text">New text</h3>',
        ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertStringContainsString('>New text</h3>', $this->content($id));
        $this->assertStringContainsString('"uniqueId":"a1b2c3d4"', $this->content($id));
    }

    public function test_generateblocks_legacy_container_insert_gets_a_new_id_and_checks_known_types(): void
    {
        $this->register_legacy('generateblocks/container');
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->');

        $out = $this->write('insert-block', [
            'suite'         => 'generateblocks',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 1 ],
            'markup'        => '<!-- wp:generateblocks/container {"uniqueId":"__UNIQUE_ID__","backgroundColor":"#eeeeee","blockVersion":4} -->'
                . '<div class="gb-container gb-container-__UNIQUE_ID__"></div><!-- /wp:generateblocks/container -->',
        ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $unique = (string) $out['result']['unique_ids'][0]['unique_id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $unique);
        $this->assertStringContainsString('gb-container-' . $unique, $this->content($id));

        // An attribute GenerateBlocks' defaults type is still checked.
        [ $id2, $hash2 ] = $this->post('');
        $bad = $this->write('insert-block', [
            'suite'         => 'generateblocks',
            'id'            => $id2,
            'expected_hash' => $hash2,
            'path'          => [ 0 ],
            'markup'        => '<!-- wp:generateblocks/container {"isGrid":"yes"} --><div class="gb-container"></div><!-- /wp:generateblocks/container -->',
        ]);
        $this->assertSame('invalid_block_attributes', $bad['error']['code']);
    }

    public function test_generateblocks_legacy_schema_states_its_lenient_rule(): void
    {
        $this->register_legacy('generateblocks/headline');

        $one = $this->read('get-block-schemas', [ 'suite' => 'generateblocks', 'name' => 'generateblocks/headline' ])['result']['blocks'][0];
        $this->assertTrue($one['legacy']);
        $this->assertSame('allowed', $one['unknown_attributes']);
        $this->assertSame([ 'type' => 'string', 'default' => 'h2' ], $one['attributes']['element']);
        $this->assertSame([ 'type' => 'string' ], $one['attributes']['uniqueId']);

        $all = $this->read('get-block-schemas', [ 'suite' => 'generateblocks' ])['result'];
        $this->assertSame('refused', $all['unknown_attributes'], 'the 2.x blocks stay strict');
        $by_name = array_column($all['blocks'], null, 'name');
        $this->assertArrayNotHasKey('legacy', $by_name['generateblocks/text']);
        $this->assertContains('textColor', $by_name['generateblocks/headline']['attributes']);
    }

    public function test_a_2x_block_still_refuses_an_unknown_attribute(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:generateblocks/text {"uniqueId":"b1c2d3e4","tagName":"p"} --><p class="gb-text">x</p><!-- /wp:generateblocks/text -->');

        $out = $this->write('update-block', [ 'suite' => 'generateblocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'attrs' => [ 'textColor' => '#000000' ] ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code']);
    }
}
