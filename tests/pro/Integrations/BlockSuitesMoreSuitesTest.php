<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suite packs (issue #287), second slice: Spectra, Otter Blocks and
 * the Blocksy companion blocks through the block-suites dispatcher pair.
 *
 * None of the suites is installed in the test core. Otter and Blocksy
 * register their blocks from block.json files, so their blocks are stood in
 * by registering the same attribute schemas. Spectra registers most of its
 * blocks only in the editor and keeps each block's attribute defaults in an
 * attributes.php file; those defaults are supplied through the
 * wpmcp_block_suite_default_attributes filter, the same seam that reads
 * them from Spectra on a real site. Presence is toggled through the
 * wpmcp_block_suite_active filter.
 */
class BlockSuitesMoreSuitesTest extends \WP_UnitTestCase
{
    /** @var array<string,bool> suite slug => active */
    private array $active = [ 'spectra' => true, 'otter-blocks' => true, 'blocksy' => true ];

    /** @var callable */
    private $presence;

    /** @var callable */
    private $defaults;

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

        // Spectra: the defaults its attributes.php files return.
        $this->defaults = static function (array $defaults, string $suite): array {
            if ('spectra' !== $suite) {
                return $defaults;
            }
            return $defaults + [
                'uagb/advanced-heading' => [
                    'title'      => 'Heading',
                    'attributes' => [
                        'headingTitle'      => '',
                        'headingAlign'      => 'center',
                        'separatorHeight'   => 2,
                        'headingDescToggle' => false,
                        'headingTag'        => 'h2',
                    ],
                ],
                'uagb/buttons'          => [
                    'title'      => 'Buttons',
                    'attributes' => [ 'classMigrate' => false, 'childMigrate' => false, 'align' => 'center' ],
                ],
                'uagb/container'        => [
                    'title'      => 'Container',
                    'attributes' => [ 'directionDesktop' => 'column', 'contentWidth' => 'alignfull' ],
                ],
            ];
        };
        add_filter('wpmcp_block_suite_default_attributes', $this->defaults, 10, 2);

        // Spectra registers a few dynamic blocks on the server.
        $this->register('uagb/icon', [ 'block_id' => [ 'type' => 'string' ], 'icon' => [ 'type' => 'string', 'default' => 'circle-check' ] ]);

        // Otter: block.json schemas.
        $otter = [
            'id'           => [ 'type' => 'string' ],
            'content'      => [ 'type' => 'string', 'source' => 'html', 'selector' => 'h1,h2,h3,h4,h5,h6,div,p,span' ],
            'tag'          => [ 'type' => 'string', 'default' => 'h2' ],
            'headingColor' => [ 'type' => 'string' ],
            'fontSize'     => [ 'type' => [ 'number', 'string' ] ],
        ];
        $this->register('themeisle-blocks/advanced-heading', $otter);
        $this->register('themeisle-blocks/advanced-columns', [ 'id' => [ 'type' => 'string' ], 'columns' => [ 'type' => 'number' ] ]);
        $this->register('themeisle-blocks/advanced-column', [ 'id' => [ 'type' => 'string' ], 'columnWidth' => [ 'type' => 'string' ] ]);

        // Blocksy companion: its query block keys its CSS on uniqueId.
        $this->register('blocksy/query', [ 'uniqueId' => [ 'type' => 'string', 'default' => '' ], 'limit' => [ 'type' => 'number', 'default' => 5 ] ]);
        $this->register('blocksy/breadcrumbs', [ 'textColor' => [ 'type' => 'string' ] ]);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_filter('wpmcp_block_suite_default_attributes', $this->defaults, 10);
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

    private function spectra_heading(string $attrs = '{"headingAlign":"left"}'): string
    {
        return '<!-- wp:uagb/advanced-heading ' . $attrs . ' --><div class="wp-block-uagb-advanced-heading uagb-block-__UNIQUE_ID__"><h2 class="uagb-heading-text">Hi</h2></div><!-- /wp:uagb/advanced-heading -->';
    }

    private function otter_heading(string $id = '__UNIQUE_ID__'): string
    {
        $attrs = '__UNIQUE_ID__' === $id ? '{"headingColor":"#ffffff"}' : sprintf('{"id":"%s","headingColor":"#ffffff"}', $id);
        return sprintf(
            '<!-- wp:themeisle-blocks/advanced-heading %s --><h2 id="%s" class="wp-block-themeisle-blocks-advanced-heading %s">Hi</h2><!-- /wp:themeisle-blocks/advanced-heading -->',
            $attrs,
            $id,
            $id
        );
    }

    // ------------------------------------------------------------ surface

    public function test_list_suites_reports_the_new_suites(): void
    {
        $by = array_column($this->read('list-suites')['result']['suites'], null, 'suite');

        $this->assertSame('block_id', $by['spectra']['unique_id_attribute']);
        $this->assertSame('uagb/', $by['spectra']['namespace']);
        $this->assertSame('per_post_cache', $by['spectra']['css_model']);
        $this->assertTrue($by['spectra']['active']);

        $this->assertSame('id', $by['otter-blocks']['unique_id_attribute']);
        $this->assertSame('themeisle-blocks/', $by['otter-blocks']['namespace']);
        $this->assertSame('per_post_cache', $by['otter-blocks']['css_model']);

        $this->assertSame('uniqueId', $by['blocksy']['unique_id_attribute']);
        $this->assertSame('blocksy/', $by['blocksy']['namespace']);
        $this->assertSame('render_time', $by['blocksy']['css_model']);
    }

    public function test_the_pair_registers_while_only_a_new_suite_is_active(): void
    {
        $this->active = [ 'spectra' => false, 'otter-blocks' => true, 'blocksy' => false ];
        $this->assertTrue(( new Block_Suites_Integration() )->should_register());
        $this->active = [];
        $this->assertFalse(( new Block_Suites_Integration() )->should_register());
    }

    // ------------------------------------------------------------ Spectra

    public function test_spectra_schemas_merge_its_attribute_defaults_with_the_registry(): void
    {
        $out = $this->read('get-block-schemas', [ 'suite' => 'spectra' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $by = array_column($out['result']['blocks'], null, 'name');

        $this->assertArrayHasKey('uagb/advanced-heading', $by);
        $this->assertArrayHasKey('uagb/container', $by);
        $this->assertArrayHasKey('uagb/icon', $by, 'server registered blocks are listed too');
        $this->assertSame('Heading', $by['uagb/advanced-heading']['title']);
        $this->assertContains('block_id', $by['uagb/advanced-heading']['attributes']);
        $this->assertSame('allowed', $out['result']['unknown_attributes'], 'Spectra declares most attributes only in the editor');

        $one = $this->read('get-block-schemas', [ 'suite' => 'spectra', 'name' => 'uagb/advanced-heading' ])['result']['blocks'][0];
        $this->assertSame('number', $one['attributes']['separatorHeight']['type']);
        $this->assertSame(2, $one['attributes']['separatorHeight']['default']);
        $this->assertSame('boolean', $one['attributes']['headingDescToggle']['type']);
        $this->assertSame('string', $one['attributes']['block_id']['type']);
        $this->assertArrayNotHasKey('type', $one['attributes']['headingTitle'], 'an empty default says nothing about the type');
    }

    public function test_spectra_insert_generates_its_block_id_and_fills_the_markup(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $out           = $this->write('insert-block', [
            'suite'         => 'spectra',
            'id'            => $id,
            'expected_hash' => $hash,
            'path'          => [ 1 ],
            'markup'        => $this->spectra_heading('{"headingAlign":"left","UAGHideMob":true}'),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node  = $this->blocks($id)[1];
        $block = (string) $node['attrs']['block_id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $block);
        $this->assertStringContainsString('uagb-block-' . $block . '"', $node['innerHTML']);
        $this->assertTrue($node['attrs']['UAGHideMob'], 'an editor-only attribute is kept');
        $this->assertSame($block, $out['result']['unique_ids'][0]['unique_id']);
        $this->assertStringNotContainsString('__UNIQUE_ID__', $this->content($id));
    }

    public function test_spectra_attributes_with_a_known_type_are_still_checked(): void
    {
        [ $id, $hash ] = $this->post('');
        $out           = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => $this->spectra_heading('{"separatorHeight":"tall"}'),
        ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null);

        $out = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:uagb/nope --><div></div><!-- /wp:uagb/nope -->',
        ]);
        $this->assertSame('unknown_suite_block', $out['error']['code'] ?? null);
        $this->assertSame('', $this->content($id));
    }

    public function test_spectra_write_and_rollback_drop_its_per_post_assets(): void
    {
        [ $id, $hash ] = $this->post('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->');
        $original      = $this->content($id);
        $this->seed_spectra_assets($id);

        $out = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => $this->spectra_heading(),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('per_post_cache', $out['result']['css']['model']);
        $this->assertTrue($out['result']['css']['refreshed']);
        $this->assert_spectra_assets_gone($id);

        // Spectra regenerated the assets on a page view, then the write is undone.
        $this->seed_spectra_assets($id);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
        $this->assert_spectra_assets_gone($id);
    }

    public function test_spectra_update_keeps_the_block_id(): void
    {
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash(str_replace([ '{"headingAlign":"left"}', '__UNIQUE_ID__' ], [ '{"block_id":"1a2b3c4d","headingAlign":"left"}', '1a2b3c4d' ], $this->spectra_heading())) ]);
        $before = $this->content($id);

        $out = $this->write('update-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => hash('sha256', $before), 'path' => [ 0 ],
            'attrs' => [ 'block_id' => 'ffffffff' ],
        ]);
        $this->assertSame('unique_id_immutable', $out['error']['code'] ?? null);

        $out = $this->write('update-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => hash('sha256', $before), 'path' => [ 0 ],
            'attrs' => [ 'headingAlign' => 'right' ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->blocks($id)[0];
        $this->assertSame('1a2b3c4d', $node['attrs']['block_id']);
        $this->assertSame('right', $node['attrs']['headingAlign']);
        $this->assertArrayNotHasKey('classMigrate', $node['attrs'], 'a block that keeps its id keeps its selector mode');
    }

    /**
     * Spectra's editor sets classMigrate (and childMigrate on parent blocks)
     * whenever it gives a block a new block_id. Without classMigrate Spectra
     * builds the block's CSS for its legacy #uagb-{block}-{id} selector,
     * which current markup does not carry, so the styles never apply.
     */
    public function test_spectra_new_block_ids_come_with_the_editors_selector_flags(): void
    {
        [ $id, $hash ] = $this->post('');
        $out           = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => $this->spectra_heading(),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $heading = $this->blocks($id)[0]['attrs'];
        $this->assertTrue($heading['classMigrate'] ?? null, 'the heading targets .uagb-block-{id}');
        $this->assertArrayNotHasKey('childMigrate', $heading, 'the heading has no child blocks to migrate');

        $out = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $out['result']['content_hash'], 'path' => [ 1 ],
            'markup' => '<!-- wp:uagb/buttons --><div class="wp-block-uagb-buttons uagb-block-__UNIQUE_ID__"></div><!-- /wp:uagb/buttons -->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $buttons = $this->blocks($id)[1]['attrs'];
        $this->assertTrue($buttons['classMigrate'] ?? null);
        $this->assertTrue($buttons['childMigrate'] ?? null);

        $out = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $out['result']['content_hash'], 'path' => [ 2 ],
            'markup' => $this->spectra_heading('{"classMigrate":false}'),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertFalse($this->blocks($id)[2]['attrs']['classMigrate'], 'an explicit legacy flag is kept');

        $out = $this->write('insert-block', [
            'suite' => 'spectra', 'id' => $id, 'expected_hash' => $out['result']['content_hash'], 'path' => [ 3 ],
            'markup' => '<!-- wp:uagb/container --><div class="wp-block-uagb-container uagb-block-__UNIQUE_ID__"></div><!-- /wp:uagb/container -->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertArrayNotHasKey('classMigrate', $this->blocks($id)[3]['attrs'], 'a block the editor does not flag is left alone');
    }

    private function seed_spectra_assets(int $id): void
    {
        update_post_meta($id, '_uag_page_assets', [ 'css' => '.uagb-block-x{color:red}', 'uag_version' => '1' ]);
        update_post_meta($id, '_uag_css_file_name', 'uag-css-' . $id . '.css');
        update_post_meta($id, '_uag_js_file_name', 'uag-js-' . $id . '.js');
    }

    private function assert_spectra_assets_gone(int $id): void
    {
        foreach ([ '_uag_page_assets', '_uag_css_file_name', '_uag_js_file_name' ] as $key) {
            $this->assertSame('', get_post_meta($id, $key, true), $key . ' is dropped so Spectra rebuilds it');
        }
    }

    // ------------------------------------------------------------ Otter

    public function test_otter_insert_generates_its_block_prefixed_id(): void
    {
        [ $id, $hash ] = $this->post('');
        $out           = $this->write('insert-block', [
            'suite' => 'otter-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => $this->otter_heading(),
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $node   = $this->blocks($id)[0];
        $unique = (string) $node['attrs']['id'];
        $this->assertMatchesRegularExpression('/^wp-block-themeisle-blocks-advanced-heading-[0-9a-f]{8}$/', $unique);
        $this->assertStringContainsString('id="' . $unique . '"', $node['innerHTML']);
        $this->assertStringContainsString('advanced-heading ' . $unique . '"', $node['innerHTML']);
    }

    public function test_otter_nested_blocks_each_get_an_id_for_their_own_block(): void
    {
        [ $id, $hash ] = $this->post('');
        $markup = '<!-- wp:themeisle-blocks/advanced-columns {"columns":1} --><div id="__UNIQUE_ID__" class="wp-block-themeisle-blocks-advanced-columns"><div class="innerblocks-wrap">'
            . '<!-- wp:themeisle-blocks/advanced-column --><div id="__UNIQUE_ID__" class="wp-block-themeisle-blocks-advanced-column">'
            . $this->otter_heading()
            . '</div><!-- /wp:themeisle-blocks/advanced-column --></div></div><!-- /wp:themeisle-blocks/advanced-columns -->';

        $out = $this->write('insert-block', [ 'suite' => 'otter-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $markup ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $cols = $this->blocks($id)[0];
        $col  = $cols['innerBlocks'][0];
        $head = $col['innerBlocks'][0];
        $this->assertStringStartsWith('wp-block-themeisle-blocks-advanced-columns-', $cols['attrs']['id']);
        $this->assertStringStartsWith('wp-block-themeisle-blocks-advanced-column-', $col['attrs']['id']);
        $this->assertStringStartsWith('wp-block-themeisle-blocks-advanced-heading-', $head['attrs']['id']);
        $this->assertStringContainsString('id="' . $cols['attrs']['id'] . '"', $cols['innerContent'][0]);
        $this->assertStringContainsString('id="' . $col['attrs']['id'] . '"', $col['innerContent'][0]);
        $this->assertCount(3, $out['result']['unique_ids']);
    }

    public function test_otter_duplicate_id_is_regenerated_and_unknown_attributes_refused(): void
    {
        $taken = 'wp-block-themeisle-blocks-advanced-heading-fb3c7a39';
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash($this->otter_heading($taken)) ]);
        $hash = hash('sha256', $this->content($id));

        $out = $this->write('insert-block', [ 'suite' => 'otter-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => $this->otter_heading($taken) ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $second = $this->blocks($id)[1];
        $this->assertNotSame($taken, $second['attrs']['id']);
        $this->assertStringNotContainsString($taken, $second['innerHTML'], 'the copied id is rewritten in the markup');
        $this->assertSame($taken, $this->blocks($id)[0]['attrs']['id']);

        $hash = hash('sha256', $this->content($id));
        $out  = $this->write('insert-block', [
            'suite' => 'otter-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:themeisle-blocks/advanced-heading {"bogus":1} --><h2>x</h2><!-- /wp:themeisle-blocks/advanced-heading -->',
        ]);
        $this->assertSame('invalid_block_attributes', $out['error']['code'] ?? null, 'Otter declares its schema in block.json, so it is strict');
    }

    public function test_otter_write_and_rollback_drop_its_stylesheet(): void
    {
        [ $id, $hash ] = $this->post('');
        $file          = $this->seed_otter_stylesheet($id);

        $out = $this->write('insert-block', [ 'suite' => 'otter-blocks', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'markup' => $this->otter_heading() ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['result']['css']['refreshed']);
        $this->assertSame('', get_post_meta($id, '_themeisle_gutenberg_block_stylesheet', true));
        $this->assertSame('', get_post_meta($id, '_themeisle_gutenberg_block_styles', true));
        $this->assertFileDoesNotExist($file, 'the stale stylesheet file is removed so Otter writes a new one');

        $file = $this->seed_otter_stylesheet($id);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame('', $this->content($id));
        $this->assertSame('', get_post_meta($id, '_themeisle_gutenberg_block_stylesheet', true));
        $this->assertFileDoesNotExist($file);
    }

    private function seed_otter_stylesheet(int $id): string
    {
        $name = 'post-v2-' . $id . '-' . wp_rand(1000, 9999);
        $dir  = wp_upload_dir(null, false)['basedir'] . '/themeisle-gutenberg';
        wp_mkdir_p($dir);
        $file = $dir . '/' . $name . '.css';
        file_put_contents($file, '#x{color:red}');
        update_post_meta($id, '_themeisle_gutenberg_block_stylesheet', $name);
        update_post_meta($id, '_themeisle_gutenberg_block_styles', '#x{color:red}');
        return $file;
    }

    // ------------------------------------------------------------ Blocksy

    public function test_blocksy_insert_generates_an_eight_character_unique_id(): void
    {
        [ $id, $hash ] = $this->post('');
        $out           = $this->write('insert-block', [
            'suite' => 'blocksy', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ],
            'markup' => '<!-- wp:blocksy/query {"limit":3} /-->',
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $node = $this->blocks($id)[0];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $node['attrs']['uniqueId']);
        $this->assertSame('render_time', $out['result']['css']['model']);
        $this->assertFalse($out['result']['css']['refreshed']);

        // A Blocksy block without an id attribute goes in as written.
        $hash = hash('sha256', $this->content($id));
        $out  = $this->write('insert-block', [ 'suite' => 'blocksy', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 1 ], 'markup' => '<!-- wp:blocksy/breadcrumbs /-->' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame([], $out['result']['unique_ids']);
    }

    public function test_an_inactive_new_suite_is_refused(): void
    {
        $this->active['otter-blocks'] = false;
        $out = $this->read('get-block-schemas', [ 'suite' => 'otter-blocks' ]);
        $this->assertSame('suite_unavailable', $out['error']['code']);
    }
}
