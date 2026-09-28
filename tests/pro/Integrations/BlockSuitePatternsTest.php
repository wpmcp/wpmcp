<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Block suite packs (issue #287): browsing the block pattern registry by
 * suite and importing a pattern into a post, with suite unique ids made
 * unique in the post and remote images fetched through the remote image
 * guard (Remote_Image_Guard::sideload).
 *
 * The suites are not installed, so their blocks are stood in by
 * registering their block.json attribute schemas, and presence is toggled
 * through the wpmcp_block_suite_active filter. The remote image download is
 * served by a pre_http_request stub, as the stock image tests do.
 */
class BlockSuitePatternsTest extends \WP_UnitTestCase
{
    private const REMOTE = 'https://images.pexels.com/photos/1/hero.jpg';
    private const OFFLIST = 'https://api.example-cdn.test/wp-content/uploads/bg.jpg';

    /** @var callable */
    private $presence;

    /** @var string[] */
    private array $registered = [];

    /** @var string[] */
    private array $patterns = [];

    /** @var string[] URLs the site tried to fetch */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->presence = static fn ($default, string $suite): bool => in_array($suite, [ 'kadence-blocks', 'generateblocks', 'otter-blocks' ], true);
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);
        add_filter('pre_http_request', [ $this, 'serve_image' ], 10, 3);

        $this->register('kadence/advancedheading', [ 'uniqueID' => [ 'type' => 'string' ], 'level' => [ 'type' => 'number' ] ]);
        $this->register('generateblocks/text', [
            'uniqueId' => [ 'type' => 'string' ],
            'tagName'  => [ 'type' => 'string' ],
            'styles'   => [ 'type' => 'object' ],
            'css'      => [ 'type' => 'string' ],
        ]);
        $this->register('themeisle-blocks/advanced-columns', [
            'id'              => [ 'type' => 'string' ],
            'backgroundImage' => [ 'type' => 'object' ],
        ]);

        $this->pattern('wpmcp-test/kadence-hero', 'Kadence hero', [ 'featured' ], $this->kadence_heading('1_aaaaaa-bb') . '<!-- wp:generateblocks/text {"uniqueId":"abcd1234","css":".gb-text-abcd1234{color:red}"} --><p class="gb-text gb-text-abcd1234">x</p><!-- /wp:generateblocks/text -->');
        $this->pattern('wpmcp-test/otter-cover', 'Otter cover', [ 'otter-blocks' ], $this->otter_columns());
        $this->pattern('wpmcp-test/plain', 'Plain text', [ 'text' ], '<!-- wp:paragraph --><p>plain</p><!-- /wp:paragraph -->');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_filter('pre_http_request', [ $this, 'serve_image' ], 10);
        foreach ($this->registered as $name) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        foreach ($this->patterns as $name) {
            if (\WP_Block_Patterns_Registry::get_instance()->is_registered($name)) {
                unregister_block_pattern($name);
            }
        }
        delete_option('generateblocks_dynamic_css_posts');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function serve_image($preempt, $parsed_args, $url)
    {
        $this->fetched[] = (string) $url;
        $source          = DIR_TESTDATA . '/images/canola.jpg';
        $body            = (string) file_get_contents($source);
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => [ 'content-type' => 'image/jpeg', 'content-length' => (string) filesize($source) ],
            'body'     => $body,
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    private function register(string $name, array $attributes): void
    {
        register_block_type($name, [ 'title' => $name, 'attributes' => $attributes ]);
        $this->registered[] = $name;
    }

    private function pattern(string $name, string $title, array $categories, string $content): void
    {
        register_block_pattern($name, [ 'title' => $title, 'categories' => $categories, 'content' => $content ]);
        $this->patterns[] = $name;
    }

    private function kadence_heading(string $unique): string
    {
        return sprintf(
            '<!-- wp:kadence/advancedheading {"uniqueID":"%1$s","level":2} --><h2 class="kt-adv-heading%1$s">Hello</h2><!-- /wp:kadence/advancedheading -->',
            $unique
        );
    }

    private function otter_columns(): string
    {
        $bg = wp_json_encode([ 'id' => 261, 'url' => self::REMOTE ], JSON_UNESCAPED_SLASHES);
        return '<!-- wp:themeisle-blocks/advanced-columns {"id":"wp-block-themeisle-blocks-advanced-columns-678cc482","backgroundImage":' . $bg . '} -->'
            . '<div id="wp-block-themeisle-blocks-advanced-columns-678cc482" class="wp-block-themeisle-blocks-advanced-columns">'
            . '<!-- wp:image --><figure class="wp-block-image"><img src="' . self::REMOTE . '" alt=""/></figure><!-- /wp:image -->'
            . '<!-- wp:image --><figure class="wp-block-image"><img src="' . self::OFFLIST . '" alt=""/></figure><!-- /wp:image -->'
            . '</div><!-- /wp:themeisle-blocks/advanced-columns -->';
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

    // ------------------------------------------------------------ browse

    public function test_pattern_ops_are_in_the_pro_catalog(): void
    {
        $names = array_column($this->read('list-operations')['result']['operations'], 'name');
        $this->assertContains('list-patterns', $names);
        $this->assertContains('import-pattern', $names);
    }

    public function test_list_patterns_filters_by_suite_and_reports_suites_and_images(): void
    {
        $out = $this->read('list-patterns', [ 'suite' => 'otter-blocks' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $names = array_column($out['result']['patterns'], 'name');
        $this->assertContains('wpmcp-test/otter-cover', $names);
        $this->assertNotContains('wpmcp-test/kadence-hero', $names);
        $this->assertNotContains('wpmcp-test/plain', $names);

        $by = array_column($out['result']['patterns'], null, 'name');
        $this->assertSame([ 'otter-blocks' ], $by['wpmcp-test/otter-cover']['suites']);
        $this->assertSame(2, $by['wpmcp-test/otter-cover']['remote_images']);
        $this->assertArrayNotHasKey('content', $by['wpmcp-test/otter-cover']);

        $kadence = array_column($this->read('list-patterns', [ 'suite' => 'kadence-blocks' ])['result']['patterns'], null, 'name');
        $this->assertSame([ 'generateblocks', 'kadence-blocks' ], $kadence['wpmcp-test/kadence-hero']['suites']);
    }

    public function test_list_patterns_without_a_suite_browses_every_pattern_with_search_and_paging(): void
    {
        $all = $this->read('list-patterns', [ 'search' => 'wpmcp-test/' ])['result'];
        $this->assertSame(3, $all['total']);
        $this->assertSame([], $this->read('list-patterns', [ 'search' => 'wpmcp-test/plain' ])['result']['patterns'][0]['suites']);

        $page = $this->read('list-patterns', [ 'search' => 'wpmcp-test/', 'limit' => 2, 'offset' => 2 ])['result'];
        $this->assertCount(1, $page['patterns']);
        $this->assertSame(3, $page['total']);

        $cat = $this->read('list-patterns', [ 'category' => 'featured' ])['result']['patterns'];
        $this->assertContains('wpmcp-test/kadence-hero', array_column($cat, 'name'));
        $this->assertNotContains('wpmcp-test/plain', array_column($cat, 'name'));
    }

    // ------------------------------------------------------------ import

    public function test_import_inserts_the_pattern_with_unique_ids_in_one_snapshot(): void
    {
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->') ]);
        $original = $this->content($id);
        $before   = Snapshot_Store::row_count();
        update_option('generateblocks_dynamic_css_posts', [ $id => true ]);

        $out = $this->write('import-pattern', [ 'name' => 'wpmcp-test/kadence-hero', 'id' => $id, 'expected_hash' => hash('sha256', $original), 'path' => [ 1 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame($before + 1, Snapshot_Store::row_count(), 'one snapshot for the whole import');
        $this->assertSame(2, $out['result']['inserted']);

        $blocks = $this->blocks($id);
        $this->assertSame('core/paragraph', $blocks[0]['blockName']);
        $kadence = (string) $blocks[1]['attrs']['uniqueID'];
        $this->assertStringStartsWith($id . '_', $kadence, 'a Kadence id from another post is re-scoped');
        $this->assertStringContainsString('kt-adv-heading' . $kadence, $blocks[1]['innerHTML']);
        $this->assertSame('abcd1234', $blocks[2]['attrs']['uniqueId'], 'a free GenerateBlocks id is kept');
        $this->assertSame([], get_option('generateblocks_dynamic_css_posts'), 'the suite CSS of every suite in the pattern is rebuilt');
        $this->assertContains('generateblocks', array_column($out['result']['css'], 'suite'));

        // A second import of the same pattern cannot reuse the ids.
        $out2 = $this->write('import-pattern', [ 'name' => 'wpmcp-test/kadence-hero', 'id' => $id, 'expected_hash' => hash('sha256', $this->content($id)), 'path' => [ 3 ] ]);
        $this->assertArrayNotHasKey('error', $out2, wp_json_encode($out2));
        $blocks = $this->blocks($id);
        $this->assertNotSame('abcd1234', $blocks[4]['attrs']['uniqueId']);
        $this->assertStringContainsString('.gb-text-' . $blocks[4]['attrs']['uniqueId'] . '{', $blocks[4]['attrs']['css'], 'the css follows the new id');

        $this->assertTrue(Rollback_Service::restore_operation($out2['operation_ids'][0]));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
    }

    public function test_import_sideloads_allowlisted_remote_images_and_keeps_the_rest(): void
    {
        [ $id, $hash ] = $this->post('');
        $out           = $this->write('import-pattern', [ 'name' => 'wpmcp-test/otter-cover', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        $images = array_column($out['result']['images'], null, 'url');
        $this->assertSame('sideloaded', $images[ self::REMOTE ]['status']);
        $media = (int) $images[ self::REMOTE ]['media_id'];
        $this->assertSame('attachment', get_post_type($media));
        $this->assertSame('kept', $images[ self::OFFLIST ]['status']);
        $this->assertStringContainsString('wpmcp_remote_media_allowed_hosts', $images[ self::OFFLIST ]['reason']);
        $this->assertSame([ self::REMOTE ], $this->fetched, 'only the allowlisted host is fetched, and only once');

        $content = $this->content($id);
        $local   = (string) wp_get_attachment_url($media);
        $this->assertStringNotContainsString(self::REMOTE, $content);
        $this->assertStringContainsString('src="' . $local . '"', $content);
        $this->assertStringContainsString(self::OFFLIST, $content);

        $cols = $this->blocks($id)[0];
        $this->assertSame($local, $cols['attrs']['backgroundImage']['url']);
        $this->assertSame($media, $cols['attrs']['backgroundImage']['id'], 'the attachment id follows the sideloaded url');
    }

    public function test_import_can_skip_sideloading(): void
    {
        [ $id, $hash ] = $this->post('');
        $out           = $this->write('import-pattern', [ 'name' => 'wpmcp-test/otter-cover', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ], 'sideload_images' => false ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame([], $this->fetched);
        $this->assertStringContainsString(self::REMOTE, $this->content($id));
        $this->assertSame([ 'kept' ], array_values(array_unique(array_column($out['result']['images'], 'status'))));
    }

    public function test_import_refuses_an_unknown_pattern_or_stale_hash_before_any_fetch_or_snapshot(): void
    {
        [ $id, $hash ] = $this->post('');
        $before        = Snapshot_Store::row_count();

        $out = $this->write('import-pattern', [ 'name' => 'wpmcp-test/nope', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('unknown_pattern', $out['error']['code'] ?? null);

        $out = $this->write('import-pattern', [ 'name' => 'wpmcp-test/otter-cover', 'id' => $id, 'expected_hash' => str_repeat('0', 64), 'path' => [ 0 ] ]);
        $this->assertSame('invalid_block_edit', $out['error']['code'] ?? null);

        $this->assertSame([], $this->fetched);
        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame('', $this->content($id));
    }
}
