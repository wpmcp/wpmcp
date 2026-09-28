<?php

namespace WPMCP\Tests\Free\SiteEditor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\SiteEditor\Site_Templates_Read;
use WPMCP\Tools\SiteEditor\Site_Templates_Write;

/**
 * Block theme templates, template parts and navigation (issue #378):
 * site-templates-read lists and reads them, site-templates-write saves a
 * customization, edits blocks by path, reverts to the theme file and edits
 * wp_navigation menus. Every write is one snapshot and rolls back exactly.
 *
 * Runs against Twenty Twenty-Four, the block theme the test install ships,
 * selected through the stylesheet and template filters so nothing is written
 * to the theme options.
 */
class SiteTemplatesTest extends \WP_UnitTestCase
{
    private const THEME  = 'twentytwentyfour';
    private const MARKER = 'WPMCP-378-HEADER-MARKER';

    private Site_Templates_Read $read;
    private Site_Templates_Write $write;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->read  = new Site_Templates_Read();
        $this->write = new Site_Templates_Write();
    }

    protected function tearDown(): void
    {
        $this->use_classic_theme();
        parent::tearDown();
    }

    private function use_block_theme(): void
    {
        add_filter('stylesheet', [$this, 'block_theme_slug']);
        add_filter('template', [$this, 'block_theme_slug']);
    }

    private function use_classic_theme(): void
    {
        remove_filter('stylesheet', [$this, 'block_theme_slug']);
        remove_filter('template', [$this, 'block_theme_slug']);
    }

    public function block_theme_slug(): string
    {
        return self::THEME;
    }

    private function render_header(): string
    {
        return do_blocks('<!-- wp:template-part {"slug":"header","theme":"' . self::THEME . '"} /-->');
    }

    private function customization_ids(string $post_type, string $slug): array
    {
        return get_posts([
            'post_type'      => $post_type,
            'post_status'    => ['publish', 'draft', 'auto-draft'],
            'name'           => $slug,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [[ 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => self::THEME ]],
        ]);
    }

    private function header_markup(string $text): string
    {
        return '<!-- wp:paragraph -->' . "\n" . '<p>' . $text . '</p>' . "\n" . '<!-- /wp:paragraph -->';
    }

    private function find(array $items, string $slug): array
    {
        foreach ($items as $item) {
            if ($slug === $item['slug']) {
                return $item;
            }
        }
        $this->fail("No entry with slug {$slug}.");
    }

    public function test_lists_templates_and_parts_with_source_and_area_on_a_block_theme(): void
    {
        $this->use_block_theme();

        $out = $this->read->handle([]);

        $this->assertTrue($out['block_theme']);
        $this->assertSame(self::THEME, $out['theme']);

        $header = $this->find($out['template_parts'], 'header');
        $this->assertSame(self::THEME . '//header', $header['id']);
        $this->assertSame('header', $header['area']);
        $this->assertSame('theme', $header['source']);
        $this->assertTrue($header['has_theme_file']);
        $this->assertFalse($header['customized']);
        $this->assertSame('footer', $this->find($out['template_parts'], 'footer')['area']);

        $index = $this->find($out['templates'], 'index');
        $this->assertSame('theme', $index['source']);
        $this->assertFalse($index['customized']);
        $this->assertArrayNotHasKey('area', $index);
    }

    public function test_entity_filter_lists_only_that_kind(): void
    {
        $this->use_block_theme();

        $out = $this->read->handle(['entity' => 'template_part']);

        $this->assertArrayHasKey('template_parts', $out);
        $this->assertArrayNotHasKey('templates', $out);
    }

    public function test_reads_one_template_part_as_parsed_blocks_without_writing(): void
    {
        $this->use_block_theme();
        $posts_before = (int) wp_count_posts('wp_template_part')->publish;
        $ops_before   = count(Snapshot_Store::recent(100));

        $out = $this->read->handle(['entity' => 'template_part', 'id' => 'header']);

        $this->assertSame('header', $out['slug']);
        $this->assertSame('theme', $out['source']);
        $this->assertNotEmpty($out['blocks']);
        $this->assertSame('core/group', $out['blocks'][0]['blockName']);
        $this->assertSame(hash('sha256', $out['content']), $out['content_hash']);
        $this->assertSame($posts_before, (int) wp_count_posts('wp_template_part')->publish);
        $this->assertCount($ops_before, Snapshot_Store::recent(100));
    }

    public function test_classic_theme_is_reported_clearly(): void
    {
        $this->assertFalse(wp_is_block_theme());

        $out = $this->read->handle([]);

        $this->assertFalse($out['block_theme']);
        $this->assertSame([], $out['templates']);
        $this->assertSame([], $out['template_parts']);
        $this->assertStringContainsString('classic theme', $out['message']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('classic theme');
        $this->write->handle([
            'entity'  => 'template_part',
            'id'      => 'header',
            'content' => $this->header_markup(self::MARKER),
        ]);
    }

    public function test_saving_a_part_changes_the_rendered_header_and_rollback_restores_it(): void
    {
        $this->use_block_theme();
        $before = $this->render_header();
        $this->assertStringNotContainsString(self::MARKER, $before);

        $out = $this->write->handle([
            'entity'     => 'template_part',
            'id'         => 'header',
            'content'    => $this->header_markup(self::MARKER),
            'session_id' => 's378',
        ]);

        $this->assertNotEmpty($out['operation_id']);
        $this->assertGreaterThan(0, $out['wp_id']);
        $this->assertStringContainsString(self::MARKER, $this->render_header());

        $listed = $this->find($this->read->handle(['entity' => 'template_part'])['template_parts'], 'header');
        $this->assertSame('custom', $listed['source']);
        $this->assertTrue($listed['customized']);
        $this->assertTrue($listed['has_theme_file']);
        $this->assertSame('header', $listed['area']);

        $rows = Snapshot_Store::list_by_session('s378');
        $this->assertCount(1, $rows);
        $this->assertSame('site_template', $rows[0]['object_type']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame([], $this->customization_ids('wp_template_part', 'header'));
        $this->assertNull(get_post($out['wp_id']));
        $this->assertStringNotContainsString(self::MARKER, $this->render_header());
    }

    public function test_updating_an_existing_customization_rolls_back_to_the_prior_customization(): void
    {
        $this->use_block_theme();
        $first  = $this->write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => $this->header_markup('First footer')]);
        $second = $this->write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => $this->header_markup('Second footer')]);

        $this->assertSame($first['wp_id'], $second['wp_id']);
        $this->assertStringContainsString('Second footer', get_post($first['wp_id'])->post_content);

        $this->assertTrue(Rollback_Service::restore_operation($second['operation_id']));

        $this->assertStringContainsString('First footer', get_post($first['wp_id'])->post_content);
        $this->assertSame([$first['wp_id']], $this->customization_ids('wp_template_part', 'footer'));
    }

    public function test_save_accepts_a_parsed_block_tree(): void
    {
        $this->use_block_theme();
        $blocks = $this->read->handle(['entity' => 'template_part', 'id' => 'footer']);
        $tree   = parse_blocks($this->header_markup('From blocks'));

        $out = $this->write->handle(['entity' => 'template_part', 'id' => 'footer', 'blocks' => $tree]);

        $this->assertNotSame($blocks['content_hash'], $out['content_hash']);
        $this->assertStringContainsString('From blocks', get_post($out['wp_id'])->post_content);
    }

    public function test_revert_falls_back_to_the_theme_file_and_rollback_restores_the_customization(): void
    {
        $this->use_block_theme();
        $saved = $this->write->handle(['entity' => 'template_part', 'id' => 'header', 'content' => $this->header_markup(self::MARKER)]);
        $this->assertStringContainsString(self::MARKER, $this->render_header());

        $revert = $this->write->handle(['entity' => 'template_part', 'id' => 'header', 'action' => 'revert', 'session_id' => 'rv']);

        $this->assertNotEmpty($revert['operation_id']);
        $this->assertSame('theme', $revert['source']);
        $this->assertSame([], $this->customization_ids('wp_template_part', 'header'));
        $this->assertStringNotContainsString(self::MARKER, $this->render_header());
        $this->assertFalse($this->find($this->read->handle(['entity' => 'template_part'])['template_parts'], 'header')['customized']);

        $this->assertTrue(Rollback_Service::restore_operation($revert['operation_id']));

        $this->assertSame([$saved['wp_id']], $this->customization_ids('wp_template_part', 'header'));
        $this->assertStringContainsString(self::MARKER, $this->render_header());
        $restored = $this->read->handle(['entity' => 'template_part', 'id' => 'header']);
        $this->assertSame('custom', $restored['source']);
        $this->assertSame('header', $restored['area']);
    }

    public function test_revert_without_a_customization_is_refused_and_writes_nothing(): void
    {
        $this->use_block_theme();
        $before = count(Snapshot_Store::recent(100));

        try {
            $this->write->handle(['entity' => 'template', 'id' => 'index', 'action' => 'revert']);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no customization', $e->getMessage());
        }

        $this->assertCount($before, Snapshot_Store::recent(100));
    }

    public function test_path_edit_inside_a_theme_file_template_creates_a_customization(): void
    {
        $this->use_block_theme();
        $read = $this->read->handle(['entity' => 'template', 'id' => 'index']);

        $out = $this->write->handle([
            'entity'        => 'template',
            'id'            => 'index',
            'action'        => 'add_block',
            'path'          => [0],
            'content'       => $this->header_markup('Inserted by path'),
            'expected_hash' => $read['content_hash'],
        ]);

        $after = $this->read->handle(['entity' => 'template', 'id' => 'index']);
        $this->assertSame('custom', $after['source']);
        $this->assertSame('core/paragraph', $after['blocks'][0]['blockName']);
        $this->assertSame($out['content_hash'], $after['content_hash']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('theme', $this->read->handle(['entity' => 'template', 'id' => 'index'])['source']);
    }

    public function test_update_block_by_path_in_a_customized_part(): void
    {
        $this->use_block_theme();
        $saved = $this->write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => $this->header_markup('Old text')]);

        $out = $this->write->handle([
            'entity'        => 'template_part',
            'id'            => 'footer',
            'action'        => 'update_block',
            'path'          => [0],
            'inner_html'    => "\n<p>New text</p>\n",
            'expected_hash' => $saved['content_hash'],
        ]);

        $this->assertStringContainsString('New text', get_post($saved['wp_id'])->post_content);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertStringContainsString('Old text', get_post($saved['wp_id'])->post_content);
    }

    public function test_path_edit_with_a_stale_hash_is_refused(): void
    {
        $this->use_block_theme();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stale expected_hash');
        $this->write->handle([
            'entity'        => 'template_part',
            'id'            => 'footer',
            'action'        => 'remove_block',
            'path'          => [0],
            'expected_hash' => str_repeat('0', 64),
        ]);
    }

    public function test_a_template_of_another_theme_is_refused(): void
    {
        $this->use_block_theme();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('active theme');
        $this->write->handle(['entity' => 'template_part', 'id' => 'twentytwentythree//header', 'content' => $this->header_markup('x')]);
    }

    public function test_navigation_menus_are_listed_read_and_edited_undoably(): void
    {
        $markup = '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->';
        $nav    = self::factory()->post->create([
            'post_type'    => 'wp_navigation',
            'post_title'   => 'Primary',
            'post_status'  => 'publish',
            'post_content' => $markup,
        ]);

        $list = $this->read->handle(['entity' => 'navigation']);
        $ids  = array_map('intval', wp_list_pluck($list['navigation'], 'id'));
        $this->assertContains($nav, $ids);

        $one = $this->read->handle(['entity' => 'navigation', 'id' => $nav]);
        $this->assertSame('Primary', $one['title']);
        $this->assertSame('core/navigation-link', $one['blocks'][0]['blockName']);

        $add = $this->write->handle([
            'entity'        => 'navigation',
            'id'            => $nav,
            'action'        => 'add_block',
            'path'          => [1],
            'content'       => '<!-- wp:navigation-link {"label":"About","url":"/about"} /-->',
            'expected_hash' => $one['content_hash'],
        ]);
        $this->assertStringContainsString('"About"', get_post($nav)->post_content);

        $save = $this->write->handle([
            'entity'  => 'navigation',
            'id'      => $nav,
            'content' => '<!-- wp:navigation-link {"label":"Shop","url":"/shop"} /-->',
        ]);
        $this->assertStringContainsString('"Shop"', get_post($nav)->post_content);

        $this->assertTrue(Rollback_Service::restore_operation($save['operation_id']));
        $this->assertStringContainsString('"About"', get_post($nav)->post_content);
        $this->assertTrue(Rollback_Service::restore_operation($add['operation_id']));
        $this->assertSame($markup, get_post($nav)->post_content);
    }

    public function test_a_new_custom_template_is_created_and_reverting_it_deletes_it(): void
    {
        $this->use_block_theme();

        $out = $this->write->handle([
            'entity'  => 'template',
            'id'      => 'wpmcp-landing',
            'title'   => 'Landing',
            'content' => $this->header_markup('Landing body'),
        ]);
        $read = $this->read->handle(['entity' => 'template', 'id' => self::THEME . '//wpmcp-landing']);
        $this->assertSame('Landing', $read['title']);
        $this->assertFalse($read['has_theme_file']);
        $this->assertTrue($read['customized']);

        $revert = $this->write->handle(['entity' => 'template', 'id' => 'wpmcp-landing', 'action' => 'revert']);
        $this->assertSame('none', $revert['source']);
        $this->assertNotSame('', $revert['notes']);
        $this->assertSame([], $this->customization_ids('wp_template', 'wpmcp-landing'));

        $this->assertTrue(Rollback_Service::restore_operation($revert['operation_id']));
        $this->assertSame([$out['wp_id']], $this->customization_ids('wp_template', 'wpmcp-landing'));
    }

    /** @return array<string, array{0: array, 1: string}> */
    public function refusals(): array
    {
        return [
            'missing id'         => [['entity' => 'template_part'], '"id" is required'],
            'unknown action'     => [['entity' => 'template_part', 'id' => 'footer', 'action' => 'rename'], '"action" must be one of'],
            'unknown entity'     => [['entity' => 'style', 'id' => 'x', 'content' => 'x'], '"entity" must be one of'],
            'bad slug'           => [['entity' => 'template_part', 'id' => 'Bad Slug!', 'content' => 'x'], 'must be a template slug'],
            'bad area'           => [['entity' => 'template_part', 'id' => 'wpmcp-new-part', 'area' => 'nowhere', 'content' => 'x'], '"area" must be one of'],
            'save without body'  => [['entity' => 'template_part', 'id' => 'footer'], 'save needs "content"'],
            'stale save hash'    => [['entity' => 'template_part', 'id' => 'footer', 'content' => 'x', 'expected_hash' => 'abc'], 'Stale expected_hash'],
            'path without hash'  => [['entity' => 'template_part', 'id' => 'footer', 'action' => 'remove_block', 'path' => [0]], '"expected_hash" is required'],
            'path on missing'    => [['entity' => 'template_part', 'id' => 'wpmcp-missing', 'action' => 'remove_block', 'path' => [0], 'expected_hash' => 'x'], 'to edit by path'],
            'read missing'       => [['read' => true, 'entity' => 'template_part', 'id' => 'wpmcp-missing'], 'No template part'],
            'navigation revert'  => [['nav' => true, 'entity' => 'navigation', 'action' => 'revert'], 'no theme file'],
            'navigation missing' => [['entity' => 'navigation', 'id' => 999999, 'content' => 'x'], 'wp_navigation'],
        ];
    }

    /** @dataProvider refusals */
    public function test_invalid_calls_are_refused_without_writing(array $args, string $message): void
    {
        $this->use_block_theme();
        $before = count(Snapshot_Store::recent(100));
        if (! empty($args['nav'])) {
            $args['id'] = self::factory()->post->create(['post_type' => 'wp_navigation', 'post_status' => 'publish']);
        }
        $read = ! empty($args['read']);
        unset($args['nav'], $args['read']);

        try {
            $read ? $this->read->handle($args) : $this->write->handle($args);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertCount($before, Snapshot_Store::recent(100));
    }

    public function test_update_block_needs_a_change_and_refuses_inner_html_on_a_container(): void
    {
        $this->use_block_theme();
        $read = $this->read->handle(['entity' => 'template_part', 'id' => 'header']);
        $base = ['entity' => 'template_part', 'id' => 'header', 'action' => 'update_block', 'path' => [0], 'expected_hash' => $read['content_hash']];

        foreach ([
            [[], 'needs "attrs"'],
            [['attrs' => 'x'], '"attrs" must be an object'],
            [['inner_html' => '<p>x</p>'], 'has innerBlocks'],
        ] as [$extra, $message]) {
            try {
                $this->write->handle($base + $extra);
                $this->fail('Expected a refusal.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $out = $this->write->handle($base + ['attrs' => ['tagName' => 'header']]);
        $this->assertSame(['tagName' => 'header'], parse_blocks(get_post($out['wp_id'])->post_content)[0]['attrs']);
    }

    public function test_add_block_needs_exactly_one_block(): void
    {
        $this->use_block_theme();
        $read = $this->read->handle(['entity' => 'template_part', 'id' => 'footer']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one block');
        $this->write->handle([
            'entity'        => 'template_part',
            'id'            => 'footer',
            'action'        => 'add_block',
            'path'          => [0],
            'content'       => $this->header_markup('a') . $this->header_markup('b'),
            'expected_hash' => $read['content_hash'],
        ]);
    }

    public function test_navigation_is_listed_on_a_classic_theme_too(): void
    {
        $nav = self::factory()->post->create(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Classic nav']);

        $ids = array_map('intval', wp_list_pluck($this->read->handle(['entity' => 'navigation'])['navigation'], 'id'));

        $this->assertContains($nav, $ids);
    }

    public function test_navigation_write_refuses_a_post_that_is_not_a_navigation_menu(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wp_navigation');
        $this->write->handle(['entity' => 'navigation', 'id' => $page, 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
    }

    public function test_rollback_of_a_template_write_requires_edit_theme_options(): void
    {
        $this->use_block_theme();
        $out = $this->write->handle(['entity' => 'template_part', 'id' => 'footer', 'content' => $this->header_markup('Guarded')]);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotNull(get_post($out['wp_id']));
    }

    public function test_rollback_of_a_navigation_edit_requires_edit_theme_options(): void
    {
        $nav = self::factory()->post->create([
            'post_type'    => 'wp_navigation',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
        ]);
        $out = $this->write->handle(['entity' => 'navigation', 'id' => $nav, 'content' => '<!-- wp:navigation-link {"label":"Shop","url":"/shop"} /-->']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertStringContainsString('"Shop"', get_post($nav)->post_content);
    }

    public function test_site_template_snapshots_are_restorable(): void
    {
        $this->assertContains('site_template', Rollback_Service::restorable_object_types());
    }

    public function test_abilities_are_free_and_gated_at_edit_theme_options(): void
    {
        $abilities = wp_get_abilities();
        foreach (['wpmcp/site-templates-read', 'wpmcp/site-templates-write'] as $name) {
            $this->assertArrayHasKey($name, $abilities);

            wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny an editor");

            wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
    }
}
