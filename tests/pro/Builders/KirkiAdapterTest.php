<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Kirki_Content, Update_Builder_Content};

/**
 * Issue #641: the Kirki page builder (Kirki 6, by Themeum) through the
 * builder dispatcher pair.
 *
 * The page is stored the way Kirki 6.4.2's own save stores it
 * (PageManager::save_blocks(), HelperFunctions::save_kirki_data_to_db()):
 * the `kirki` postmeta row holding ['blocks' => a map of elements by id],
 * the root element `body` (parentId 'root'), every element naming its
 * parentId and, for a container, its children ids in order, plus the
 * `kirki_editor_mode` = 'kirki' flag. The layout below is Kirki's own 404
 * page template (pre-built-pages/basic/404.zip, kirki-data.json), with the
 * editor's stylePanels dropped from every element but the body. The plugin's
 * presence is toggled through wpmcp_kirki_active.
 */
class KirkiAdapterTest extends \WP_UnitTestCase
{
    private const PANELS = [
        'typography' => true, 'composition' => true, 'size' => true, 'background' => true, 'stroke' => true, 'shadow' => true,
        'effects' => true, 'position' => true, 'transform' => true, 'interaction' => true, 'animation' => true,
    ];

    private int $admin;

    /** @return array<string,array<string,mixed>> */
    public static function blocks(): array
    {
        return [
            'body'       => ['visibility' => true, 'collapse' => false, 'name' => 'body', 'title' => 'Body', 'properties' => ['tag' => 'div'], 'styleIds' => [], 'className' => 'kirki-body', 'source' => 'kirki', 'children' => ['k-1bp2gj9p'], 'id' => 'body', 'parentId' => 'root', 'stylePanels' => self::PANELS],
            'k-1bp2gj9p' => ['visibility' => true, 'collapse' => false, 'name' => 'section', 'title' => '404 Section', 'properties' => ['tag' => 'section', 'interactions' => ['elementAsTrigger' => []]], 'styleIds' => ['k-uicwmaku'], 'className' => '', 'source' => 'kirki', 'children' => ['k-ahngav6v'], 'id' => 'k-1bp2gj9p', 'parentId' => 'body'],
            'k-ahngav6v' => ['visibility' => true, 'collapse' => false, 'name' => 'div', 'title' => 'Container', 'properties' => ['tag' => 'div'], 'styleIds' => ['k-54zi8qeb'], 'className' => '', 'source' => 'kirki', 'children' => ['k-g9enbdcm'], 'id' => 'k-ahngav6v', 'parentId' => 'k-1bp2gj9p'],
            'k-g9enbdcm' => ['visibility' => true, 'collapse' => false, 'name' => 'div', 'title' => 'Contents', 'properties' => ['tag' => 'div'], 'styleIds' => ['k-arf2ectm'], 'className' => '', 'source' => 'kirki', 'children' => ['k-ksfaqgl5', 'k-5iwc7x4i', 'k-o3s352vn', 'k-t2wgsjzu'], 'id' => 'k-g9enbdcm', 'parentId' => 'k-ahngav6v'],
            'k-ksfaqgl5' => ['visibility' => true, 'collapse' => false, 'name' => 'heading', 'title' => 'Heading', 'properties' => ['contents' => ['404'], 'tag' => 'h1'], 'styleIds' => ['k-40k0juz6'], 'className' => '', 'source' => 'kirki', 'id' => 'k-ksfaqgl5', 'parentId' => 'k-g9enbdcm'],
            'k-5iwc7x4i' => ['visibility' => true, 'collapse' => false, 'name' => 'heading', 'title' => 'Heading', 'properties' => ['contents' => ['Unfortunately page is down!'], 'tag' => 'h1'], 'styleIds' => ['k-kpv29mcb'], 'className' => '', 'source' => 'kirki', 'id' => 'k-5iwc7x4i', 'parentId' => 'k-g9enbdcm'],
            'k-o3s352vn' => ['visibility' => true, 'collapse' => false, 'name' => 'paragraph', 'title' => 'Paragraph', 'properties' => ['contents' => ['This page is missing or you assembled the link incorrectly'], 'tag' => 'p'], 'styleIds' => ['k-xixyxajb'], 'className' => '', 'source' => 'kirki', 'id' => 'k-o3s352vn', 'parentId' => 'k-g9enbdcm'],
            'k-t2wgsjzu' => ['visibility' => true, 'collapse' => false, 'name' => 'button', 'title' => 'Primary Button', 'properties' => ['tag' => 'button', 'contents' => ['Create Your Own'], 'type' => 'href', 'attributes' => ['href' => '', 'target' => '']], 'styleIds' => ['k-3k9lfwd1'], 'className' => '', 'source' => 'kirki', 'children' => ['k-q2j7o0ph', 'k-bzdyfwo8'], 'id' => 'k-t2wgsjzu', 'parentId' => 'k-g9enbdcm', 'template_mounted' => true],
            'k-q2j7o0ph' => ['styleIds' => [], 'visibility' => true, 'collapse' => false, 'name' => 'text', 'title' => 'Text', 'properties' => ['contents' => ['Back to Home'], 'tag' => 'span'], 'className' => '', 'source' => 'kirki', 'id' => 'k-q2j7o0ph', 'parentId' => 'k-t2wgsjzu'],
            'k-bzdyfwo8' => ['visibility' => true, 'collapse' => false, 'name' => 'svg', 'title' => 'Shape', 'properties' => ['tag' => 'svg', 'svgOuterHtml' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="14" viewBox="0 0 16 14" fill="none"><path d="M0.5 6.99908H14.5" stroke="white" stroke-width="1.5" /></svg>'], 'styleIds' => ['k-dfyzqerm'], 'className' => '', 'source' => 'kirki', 'id' => 'k-bzdyfwo8', 'parentId' => 'k-t2wgsjzu'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_kirki_active', '__return_true');
        if (! post_type_exists(Kirki_Content::SYMBOL_POST_TYPE)) {
            register_post_type(Kirki_Content::SYMBOL_POST_TYPE, ['public' => false]);
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_kirki_active');
        global $wpdb;
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}kirki_stages"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        parent::tearDown();
    }

    /** @param array<string,mixed>|null $blocks */
    private function page(?array $blocks = null, array $args = []): int
    {
        $id = self::factory()->post->create($args + ['post_type' => 'page', 'post_content' => '']);
        update_post_meta($id, 'kirki', wp_slash(['blocks' => $blocks ?? self::blocks()]));
        update_post_meta($id, 'kirki_editor_mode', 'kirki');

        return $id;
    }

    /** @return array<string,array<string,mixed>> */
    private function stored(int $id): array
    {
        $row = get_post_meta($id, 'kirki', true);

        return is_array($row) && is_array($row['blocks'] ?? null) ? $row['blocks'] : [];
    }

    private function read(int $id): array
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    private function write(int $id, array $args)
    {
        return (new Update_Builder_Content())->handle($args + ['post_id' => $id, 'builder' => 'kirki', 'expected_hash' => $this->read($id)['hash']]);
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    private function error(string $code, $out): void
    {
        $this->assertWPError($out);
        $this->assertSame($code, $out->get_error_code(), $out->get_error_message());
    }

    public function test_detects_a_kirki_page_by_its_editor_mode(): void
    {
        $this->assertSame('kirki', (new Detect_Builder())->handle(['post_id' => $this->page()])['builder']);

        $plain = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        update_post_meta($plain, 'kirki', wp_slash(['blocks' => self::blocks()]));
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $plain])['builder'], 'a page sent back to the WordPress editor is not Kirki');
    }

    public function test_read_returns_the_elements_an_outline_from_the_body_and_the_hash(): void
    {
        $read = $this->read($this->page());
        $this->assertSame('kirki', $read['builder']);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame('body', $read['root']);
        $this->assertSame(self::blocks(), $read['blocks']);
        $this->assertNull($read['unpublished_version']);
        $this->assertSame(64, strlen($read['hash']));
        $this->assertSame(10, $read['element_count']);

        $body = $read['tree'];
        $this->assertSame('body', $body['id']);
        $section = $body['children'][0];
        $this->assertSame(['id' => 'k-1bp2gj9p', 'name' => 'section', 'title' => '404 Section'], array_diff_key($section, ['children' => 1]));
        $contents = $section['children'][0]['children'][0];
        $this->assertSame(['id' => 'k-ksfaqgl5', 'name' => 'heading', 'title' => 'Heading', 'text' => '404'], $contents['children'][0]);
        $this->assertSame('k-q2j7o0ph', $contents['children'][3]['children'][0]['id']);
    }

    public function test_read_refuses_a_draft_the_caller_cannot_read(): void
    {
        $other = self::factory()->user->create(['role' => 'administrator']);
        $id    = $this->page(null, ['post_status' => 'draft', 'post_author' => $other]);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->error('forbidden', (new Get_Builder_Content())->handle(['post_id' => $id]));
    }

    public function test_update_merges_properties_sets_text_and_rolls_back_exactly(): void
    {
        $id      = $this->page();
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, [
            'operation'  => 'update',
            'path'       => 'k-ksfaqgl5',
            'text'       => 'Page not found',
            'attrs'      => ['tag' => 'h2', 'attributes' => ['data-x' => '1']],
            'element'    => ['title' => 'Big number'],
            'session_id' => $session,
        ]));
        $this->assertSame('k-ksfaqgl5', $out['path']);
        $this->assertSame('kirki', $out['builder']);
        $this->assertSame($this->read($id)['hash'], $out['hash']);
        $this->assertNotEmpty($out['operation_id']);

        $heading = $this->stored($id)['k-ksfaqgl5'];
        $this->assertSame(['contents' => ['Page not found'], 'tag' => 'h2', 'attributes' => ['data-x' => '1']], $heading['properties']);
        $this->assertSame('Big number', $heading['title']);

        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'k-t2wgsjzu', 'attrs' => ['type' => null]]));
        $this->assertArrayNotHasKey('type', $this->stored($id)['k-t2wgsjzu']['properties']);

        Rollback_Service::restore_session($session);
        $this->assertSame(self::blocks()['k-ksfaqgl5'], $this->stored($id)['k-ksfaqgl5']);
    }

    public function test_one_write_rolls_back_to_the_exact_postmeta(): void
    {
        $id      = $this->page();
        $before  = get_post_meta($id);
        $session = wp_generate_uuid4();
        $this->ok($this->write($id, ['operation' => 'remove', 'path' => 'k-o3s352vn', 'session_id' => $session]));
        $this->assertArrayNotHasKey('k-o3s352vn', $this->stored($id));

        Rollback_Service::restore_session($session);
        $this->assertSame($before, get_post_meta($id));
        $this->assertSame(self::blocks(), $this->stored($id));
    }

    public function test_update_refuses_text_on_rich_contents_and_unknown_element_fields(): void
    {
        $blocks = self::blocks();
        $blocks['k-o3s352vn']['properties']['contents'] = ['Hello ', ['id' => 'k-inline01', 'name' => 'link']];
        $id = $this->page($blocks);

        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'update', 'path' => 'k-o3s352vn', 'text' => 'x']));
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'update', 'path' => 'k-o3s352vn', 'element' => ['parentId' => 'body']]));
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'update', 'path' => 'k-missing0', 'text' => 'x']));
    }

    public function test_add_builds_an_element_tree_with_new_ids_under_the_parent(): void
    {
        $id  = $this->page();
        $out = $this->ok($this->write($id, [
            'operation' => 'add',
            'to'        => 'k-g9enbdcm',
            'index'     => 1,
            'element'   => [
                'name'     => 'div',
                'title'    => 'Card',
                'children' => [
                    ['name' => 'heading', 'text' => 'Need help?'],
                    ['name' => 'paragraph', 'text' => 'Write to us.', 'properties' => ['tag' => 'p']],
                ],
            ],
        ]));
        $new    = $out['path'];
        $blocks = $this->stored($id);
        $this->assertMatchesRegularExpression('/^k-[a-z0-9]{8}$/', $new);
        $this->assertSame($new, $blocks['k-g9enbdcm']['children'][1]);
        $this->assertSame(5, count($blocks['k-g9enbdcm']['children']));

        $card = $blocks[ $new ];
        $this->assertSame('div', $card['name']);
        $this->assertSame('Card', $card['title']);
        $this->assertSame('k-g9enbdcm', $card['parentId']);
        $this->assertSame(['tag' => 'div'], $card['properties']);
        $this->assertCount(2, $card['children']);

        $heading = $blocks[ $card['children'][0] ];
        $this->assertSame($new, $heading['parentId']);
        $this->assertEquals(['tag' => 'h2', 'contents' => ['Need help?']], $heading['properties']);
        $this->assertSame('Heading', $heading['title']);
        $this->assertArrayNotHasKey('children', $heading);
        $this->assertEquals(['contents' => ['Write to us.'], 'tag' => 'p'], $blocks[ $card['children'][1] ]['properties']);
        $this->assertSame(13, count($blocks));

        // '' adds to the body, at the end without an index.
        $top = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['name' => 'section']]))['path'];
        $this->assertSame(['k-1bp2gj9p', $top], $this->stored($id)['body']['children']);
    }

    public function test_add_refuses_a_parent_that_holds_no_children_and_a_bad_element(): void
    {
        $id = $this->page();
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'add', 'to' => 'k-ksfaqgl5', 'element' => ['name' => 'text']]));
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'add', 'to' => 'body', 'element' => ['title' => 'no name']]));
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'add', 'to' => 'body', 'element' => ['name' => 'Bad Name!']]));
    }

    public function test_add_places_a_component_instance(): void
    {
        $symbol = self::factory()->post->create(['post_type' => Kirki_Content::SYMBOL_POST_TYPE, 'post_title' => 'Site footer', 'post_status' => 'publish']);
        update_post_meta($symbol, 'kirki', wp_slash(['data' => ['k-root0001' => ['id' => 'k-root0001', 'name' => 'section']], 'root' => 'k-root0001', 'styleBlocks' => [], 'category' => 'footer', 'setAs' => 'footer']));
        $id = $this->page();

        $new     = $this->ok($this->write($id, ['operation' => 'add', 'to' => 'body', 'element' => ['symbol' => $symbol]]))['path'];
        $element = $this->stored($id)[ $new ];
        $this->assertSame('symbol', $element['name']);
        $this->assertSame('Site footer', $element['title']);
        $this->assertSame(['symbolId' => $symbol], $element['properties']);
        $this->assertSame([], $element['children']);

        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'add', 'to' => 'body', 'element' => ['symbol' => $page]]));
    }

    public function test_move_reorders_and_reparents_and_refuses_a_cycle(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'move', 'path' => 'k-t2wgsjzu', 'to' => 'k-g9enbdcm', 'index' => 0]));
        $this->assertSame(['k-t2wgsjzu', 'k-ksfaqgl5', 'k-5iwc7x4i', 'k-o3s352vn'], $this->stored($id)['k-g9enbdcm']['children']);

        $this->ok($this->write($id, ['operation' => 'move', 'path' => 'k-o3s352vn', 'to' => 'k-ahngav6v']));
        $blocks = $this->stored($id);
        $this->assertSame(['k-g9enbdcm', 'k-o3s352vn'], $blocks['k-ahngav6v']['children']);
        $this->assertSame('k-ahngav6v', $blocks['k-o3s352vn']['parentId']);
        $this->assertNotContains('k-o3s352vn', $blocks['k-g9enbdcm']['children']);

        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'move', 'path' => 'k-ahngav6v', 'to' => 'k-g9enbdcm']));
        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'move', 'path' => 'body', 'to' => 'k-g9enbdcm']));
    }

    public function test_remove_drops_the_whole_subtree_and_refuses_the_body(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'remove', 'path' => 'k-t2wgsjzu']));
        $blocks = $this->stored($id);
        foreach (['k-t2wgsjzu', 'k-q2j7o0ph', 'k-bzdyfwo8'] as $gone) {
            $this->assertArrayNotHasKey($gone, $blocks);
        }
        $this->assertSame(['k-ksfaqgl5', 'k-5iwc7x4i', 'k-o3s352vn'], $blocks['k-g9enbdcm']['children']);

        $this->error('invalid_kirki_request', $this->write($id, ['operation' => 'remove', 'path' => 'body']));
    }

    public function test_whole_layout_write_is_validated(): void
    {
        $id     = $this->page();
        $blocks = self::blocks();
        $blocks['k-ksfaqgl5']['properties']['contents'] = ['Whole write'];
        $this->ok($this->write($id, ['content' => wp_json_encode($blocks)]));
        $this->assertSame($blocks, $this->stored($id));

        $no_body = $blocks;
        unset($no_body['body']);
        $this->error('invalid_kirki_request', $this->write($id, ['content' => wp_json_encode($no_body)]));

        $dangling = $blocks;
        $dangling['k-g9enbdcm']['children'][] = 'k-nothere1';
        $this->error('invalid_kirki_request', $this->write($id, ['content' => wp_json_encode($dangling)]));

        $wrong_parent = $blocks;
        $wrong_parent['k-ksfaqgl5']['parentId'] = 'body';
        $this->error('invalid_kirki_request', $this->write($id, ['content' => wp_json_encode($wrong_parent)]));

        $this->error('invalid_kirki_request', $this->write($id, ['content' => 'not json']));
    }

    public function test_writes_need_the_plugin_the_hash_and_a_kirki_page(): void
    {
        $id = $this->page();
        $this->error('missing_expected_hash', (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'kirki', 'operation' => 'remove', 'path' => 'k-o3s352vn']));
        $this->error('stale_expected_hash', $this->write($id, ['operation' => 'remove', 'path' => 'k-o3s352vn', 'expected_hash' => str_repeat('0', 64)]));

        $elementor = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($elementor, '_elementor_edit_mode', 'builder');
        $this->error('unsupported_builder', (new Update_Builder_Content())->handle(['post_id' => $elementor, 'builder' => 'kirki', 'operation' => 'remove', 'path' => 'x']));

        remove_all_filters('wpmcp_kirki_active');
        add_filter('wpmcp_kirki_active', '__return_false');
        $out = $this->write($id, ['operation' => 'remove', 'path' => 'k-o3s352vn']);
        $this->error('plugin_missing', $out);
        $this->assertStringContainsString('Install and activate Kirki', $out->get_error_message());
        $this->assertSame(self::blocks(), $this->stored($id));
    }

    public function test_a_page_with_an_unpublished_kirki_version_is_not_written(): void
    {
        global $wpdb;
        $wpdb->query("CREATE TEMPORARY TABLE {$wpdb->prefix}kirki_stages (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id bigint unsigned NOT NULL, type varchar(50) NOT NULL DEFAULT 'page', version bigint unsigned NOT NULL, name varchar(255) NOT NULL, edited_by bigint unsigned NOT NULL DEFAULT 0, is_published tinyint(1) NOT NULL DEFAULT 0, no_legacy_global_style tinyint(1) NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id  = $this->page();
        $now = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}kirki_stages", ['post_id' => $id, 'version' => 1, 'name' => 'v1', 'is_published' => 1, 'created_at' => $now, 'updated_at' => $now]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $this->assertNull($this->read($id)['unpublished_version']);
        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'k-ksfaqgl5', 'text' => 'Published only']));

        $wpdb->insert("{$wpdb->prefix}kirki_stages", ['post_id' => $id, 'version' => 2, 'name' => 'v2', 'is_published' => 0, 'created_at' => $now, 'updated_at' => $now]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->assertSame(2, $this->read($id)['unpublished_version']);
        $before = $this->stored($id);
        $out    = $this->write($id, ['operation' => 'update', 'path' => 'k-ksfaqgl5', 'text' => 'Lost on publish']);
        $this->error('kirki_unpublished_version', $out);
        $this->assertSame($before, $this->stored($id));
    }

    public function test_a_blank_page_takes_a_first_layout_and_publish_sets_it_live(): void
    {
        $id      = self::factory()->post->create(['post_type' => 'page', 'post_content' => '', 'post_status' => 'draft']);
        $session = wp_generate_uuid4();
        $out     = $this->ok((new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'kirki', 'content' => wp_json_encode(self::blocks()), 'session_id' => $session]));
        $this->assertSame('kirki', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $this->assertSame(self::blocks(), $this->stored($id));
        $this->assertSame($out['hash'], $this->read($id)['hash']);

        $this->ok($this->write($id, ['operation' => 'publish', 'session_id' => $session]));
        $this->assertSame('publish', get_post_status($id));

        Rollback_Service::restore_session($session);
        $this->assertSame('draft', get_post_status($id));
        $this->assertFalse(metadata_exists('post', $id, 'kirki'));
        $this->assertFalse(metadata_exists('post', $id, 'kirki_editor_mode'));
    }

    public function test_a_blank_page_starts_from_a_body_when_the_first_write_is_an_add(): void
    {
        $id  = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $new = $this->ok((new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'kirki', 'operation' => 'add', 'to' => '', 'element' => ['name' => 'section']]))['path'];
        $blocks = $this->stored($id);
        $this->assertSame([$new], $blocks['body']['children']);
        $this->assertSame('root', $blocks['body']['parentId']);
        $this->assertSame('body', $blocks[ $new ]['parentId']);
    }

    public function test_scope_templates_lists_components_and_catalog_lists_element_types(): void
    {
        $symbol = self::factory()->post->create(['post_type' => Kirki_Content::SYMBOL_POST_TYPE, 'post_title' => 'Header', 'post_status' => 'publish']);
        update_post_meta($symbol, 'kirki', wp_slash(['data' => ['a' => [], 'b' => []], 'root' => 'a', 'category' => 'header', 'setAs' => 'header']));

        $out = (new Get_Builder_Content())->handle(['builder' => 'kirki', 'scope' => 'templates']);
        $this->assertIsArray($out);
        $this->assertSame([['id' => $symbol, 'title' => 'Header', 'status' => 'publish', 'category' => 'header', 'set_as' => 'header', 'elements' => 2]], $out['templates']);

        $catalog = (new Get_Builder_Content())->handle(['builder' => 'kirki', 'scope' => 'catalog']);
        $this->assertIsArray($catalog);
        $names = array_column($catalog['elements'], 'name');
        $this->assertContains('heading', $names);
        $this->assertContains('symbol', $names);

        $this->error('unsupported_scope', (new Get_Builder_Content())->handle(['builder' => 'kirki', 'scope' => 'design_system']));
    }
}
