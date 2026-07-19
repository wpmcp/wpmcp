<?php

namespace WPMCP\Tests\Free\Compose;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Compose\Build_Page;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * One-call declarative page composition (issue #57), Gutenberg dialect.
 *
 * The whole composition must behave as ONE atomic, recoverable operation:
 * a mid-build failure leaves nothing behind, and a single rollback of the
 * returned operation_id removes the page and its menu placement entirely.
 */
class BuildPageTest extends \WP_UnitTestCase
{
    private array $menus = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->menus as $id) {
            wp_delete_nav_menu($id);
        }
        $this->menus = [];
        unregister_block_pattern('wpmcp-tests/hero');
        parent::tearDown();
    }

    private function menu(string $name): int
    {
        $id = wp_create_nav_menu($name);
        $this->menus[] = $id;
        return $id;
    }

    private function nested_spec(array $overrides = []): array
    {
        return array_merge([
            'title'   => 'Landing Page',
            'content' => [
                ['type' => 'group', 'children' => [
                    ['type' => 'columns', 'children' => [
                        ['type' => 'column', 'children' => [
                            ['type' => 'heading', 'settings' => ['text' => 'Welcome', 'level' => 2]],
                            ['type' => 'paragraph', 'settings' => ['text' => 'Built in one call.']],
                        ]],
                        ['type' => 'column', 'children' => [
                            ['type' => 'list', 'settings' => ['items' => ['Fast', 'Safe']]],
                        ]],
                    ]],
                ]],
                ['type' => 'separator'],
            ],
        ], $overrides);
    }

    private function snapshot_rows(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    private function page_count(): int
    {
        return count(get_posts([
            'post_type'   => 'page',
            'post_status' => 'any',
            'numberposts' => -1,
        ]));
    }

    public function test_builds_a_page_from_a_deeply_nested_tree(): void
    {
        $out = (new Build_Page())->handle(['spec' => $this->nested_spec()]);

        $this->assertGreaterThan(0, $out['post_id']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame(8, $out['created_elements']);
        $this->assertStringContainsString('action=edit', $out['edit_url']);
        $this->assertNotEmpty($out['preview_url']);

        $post = get_post($out['post_id']);
        $this->assertSame('page', $post->post_type);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('Landing Page', $post->post_title);

        // 3+ levels of nesting survive the round trip through parse_blocks().
        $blocks = parse_blocks($post->post_content);
        $blocks = array_values(array_filter($blocks, fn ($b) => null !== $b['blockName']));
        $this->assertSame('core/group', $blocks[0]['blockName']);
        $columns = $blocks[0]['innerBlocks'][0];
        $this->assertSame('core/columns', $columns['blockName']);
        $this->assertCount(2, $columns['innerBlocks']);
        $this->assertSame('core/column', $columns['innerBlocks'][0]['blockName']);
        $this->assertSame('core/heading', $columns['innerBlocks'][0]['innerBlocks'][0]['blockName']);
        $this->assertStringContainsString('Welcome', $columns['innerBlocks'][0]['innerBlocks'][0]['innerHTML']);
        $this->assertSame('core/list', $columns['innerBlocks'][1]['innerBlocks'][0]['blockName']);
        $this->assertSame('core/separator', $blocks[1]['blockName']);
    }

    public function test_every_gutenberg_leaf_type_composes_valid_blocks(): void
    {
        $attachment_id = self::factory()->attachment->create_object('kitchen.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
        ]);

        $out = (new Build_Page())->handle(['spec' => [
            'title'   => 'Kitchen Sink',
            'status'  => 'publish',
            'content' => [
                ['type' => 'quote', 'settings' => ['text' => 'Ship it.', 'citation' => 'A. Gent']],
                ['type' => 'image', 'settings' => ['attachment_id' => $attachment_id, 'alt' => 'Kitchen']],
                ['type' => 'image', 'settings' => ['url' => 'https://example.com/x.png']],
                ['type' => 'list', 'settings' => ['items' => ['One'], 'ordered' => true]],
                ['type' => 'buttons', 'children' => [
                    ['type' => 'button', 'settings' => ['text' => 'Go', 'url' => 'https://example.com/']],
                ]],
                ['type' => 'spacer', 'settings' => ['height' => 40]],
                ['type' => 'code', 'settings' => ['text' => '<?php echo "hi";']],
                ['type' => 'html', 'settings' => ['html' => '<aside>raw</aside>']],
            ],
        ]]);

        $post = get_post($out['post_id']);
        $this->assertSame('publish', $post->post_status);

        $names = array_map(
            fn ($b) => $b['blockName'],
            array_values(array_filter(parse_blocks($post->post_content), fn ($b) => null !== $b['blockName']))
        );
        $this->assertSame(
            ['core/quote', 'core/image', 'core/image', 'core/list', 'core/buttons', 'core/spacer', 'core/code', 'core/html'],
            $names
        );

        $this->assertStringContainsString('wp-image-' . $attachment_id, $post->post_content);
        $this->assertStringContainsString('<ol', $post->post_content);
        // The PHP in the code block is content, never executed, and is escaped.
        $this->assertStringNotContainsString('<?php', $post->post_content);
    }

    public function test_whole_composition_is_one_recoverable_operation(): void
    {
        $menu_id = $this->menu('Primary Nav');
        $rows    = $this->snapshot_rows();

        $out = (new Build_Page())->handle(['spec' => $this->nested_spec([
            'menu' => ['menu_id' => $menu_id, 'title' => 'Landing'],
        ])]);

        $this->assertSame($rows + 1, $this->snapshot_rows(), 'The whole build must be exactly ONE operation in history.');
        $this->assertGreaterThan(0, $out['menu_item_id']);

        $items = wp_get_nav_menu_items($menu_id);
        $this->assertCount(1, $items);
        $this->assertSame((string) $out['post_id'], (string) $items[0]->object_id);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);

        $this->assertNull(get_post($out['post_id']), 'Rollback must remove the created page entirely.');
        $this->assertSame([], (array) wp_get_nav_menu_items($menu_id), 'Rollback must remove the menu placement.');
    }

    public function test_session_rollback_also_removes_the_created_page(): void
    {
        $out = (new Build_Page())->handle([
            'spec'       => $this->nested_spec(),
            'session_id' => 'compose-session',
        ]);

        (new Rollback_Session())->handle(['session_id' => 'compose-session']);

        $this->assertNull(get_post($out['post_id']));
    }

    public function test_malformed_spec_fails_before_any_side_effect(): void
    {
        $pages = $this->page_count();
        $rows  = $this->snapshot_rows();

        try {
            (new Build_Page())->handle(['spec' => [
                'title'   => 'Bad Page',
                'content' => [
                    ['type' => 'group', 'children' => [
                        ['type' => 'not-a-block'],
                    ]],
                ],
            ]]);
            $this->fail('Expected the malformed spec to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('content[0].children[0]', $e->getMessage());
        }

        $this->assertSame($pages, $this->page_count(), 'A rejected spec must not create a page.');
        $this->assertSame($rows, $this->snapshot_rows(), 'A rejected spec must not write history.');
    }

    public function test_missing_spec_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Build_Page())->handle([]);
    }

    public function test_referenced_media_must_exist_before_any_write(): void
    {
        $pages = $this->page_count();

        try {
            (new Build_Page())->handle(['spec' => $this->nested_spec([
                'media' => ['featured' => 987654321],
            ])]);
            $this->fail('Expected a missing attachment to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('987654321', $e->getMessage());
        }

        $this->assertSame($pages, $this->page_count());
    }

    public function test_referenced_menu_must_exist_before_any_write(): void
    {
        $pages = $this->page_count();

        try {
            (new Build_Page())->handle(['spec' => $this->nested_spec([
                'menu' => ['menu_id' => 987654321],
            ])]);
            $this->fail('Expected a missing menu to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('menu', strtolower($e->getMessage()));
        }

        $this->assertSame($pages, $this->page_count());
    }

    public function test_featured_image_is_assigned(): void
    {
        $attachment_id = self::factory()->attachment->create_object('feat.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
        ]);

        $out = (new Build_Page())->handle(['spec' => $this->nested_spec([
            'media' => ['featured' => $attachment_id],
        ])]);

        $this->assertSame($attachment_id, (int) get_post_thumbnail_id($out['post_id']));
    }

    public function test_pattern_nodes_inline_registered_pattern_content(): void
    {
        register_block_pattern('wpmcp-tests/hero', [
            'title'   => 'Hero',
            'content' => "<!-- wp:paragraph --><p>Pattern body</p><!-- /wp:paragraph -->",
        ]);

        $out = (new Build_Page())->handle(['spec' => [
            'title'   => 'Patterned',
            'content' => [
                ['type' => 'pattern', 'settings' => ['slug' => 'wpmcp-tests/hero']],
            ],
        ]]);

        $this->assertStringContainsString('Pattern body', get_post($out['post_id'])->post_content);
    }

    public function test_unregistered_pattern_is_rejected_before_any_write(): void
    {
        $pages = $this->page_count();

        try {
            (new Build_Page())->handle(['spec' => [
                'title'   => 'Patterned',
                'content' => [
                    ['type' => 'pattern', 'settings' => ['slug' => 'wpmcp-tests/nope']],
                ],
            ]]);
            $this->fail('Expected the unknown pattern slug to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('wpmcp-tests/nope', $e->getMessage());
        }

        $this->assertSame($pages, $this->page_count());
    }

    public function test_mid_build_failure_rolls_everything_back(): void
    {
        $menu_id = $this->menu('Doomed Nav');
        $pages   = $this->page_count();
        $rows    = $this->snapshot_rows();

        // Sabotage the build AFTER the page is created but BEFORE the menu
        // step: deleting the menu makes wp_update_nav_menu_item() fail.
        $sabotage = function ($post_id, $post) use ($menu_id) {
            if ('page' === $post->post_type) {
                wp_delete_nav_menu($menu_id);
            }
        };
        add_action('wp_insert_post', $sabotage, 10, 2);

        try {
            (new Build_Page())->handle(['spec' => $this->nested_spec([
                'menu' => ['menu_id' => $menu_id],
            ])]);
            $this->fail('Expected the sabotaged build to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('menu', strtolower($e->getMessage()));
        } finally {
            remove_action('wp_insert_post', $sabotage, 10);
        }

        $this->assertSame($pages, $this->page_count(), 'A failed build must not leave an orphan page behind.');
        $this->assertSame($rows, $this->snapshot_rows(), 'A failed build must not appear in history.');
        $this->assertSame(0, count(get_posts([
            'post_type'   => 'nav_menu_item',
            'post_status' => 'any',
            'numberposts' => -1,
        ])), 'A failed build must not leave orphan menu items behind.');
    }

    public function test_slug_is_applied(): void
    {
        $out = (new Build_Page())->handle(['spec' => $this->nested_spec([
            'slug'   => 'landing-2026',
            'status' => 'publish',
        ])]);

        $this->assertSame('landing-2026', get_post($out['post_id'])->post_name);
    }
}
