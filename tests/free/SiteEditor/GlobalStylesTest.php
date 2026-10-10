<?php

namespace WPMCP\Tests\Free\SiteEditor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\SiteEditor\Site_Templates_Read;
use WPMCP\Tools\SiteEditor\Site_Templates_Write;

/**
 * Global styles on block themes (issue #379), through the site template
 * tools with entity "global_styles": site-templates-read reports the theme,
 * user and merged theme.json layers, the style variations and the font
 * families; site-templates-write patches the user layer by dot path, applies
 * a style variation or resets the user layer. Every write is one snapshot of
 * the user global styles post and rolls back byte for byte, or removes the
 * post when the write created it.
 *
 * Runs against Twenty Twenty-Four, selected through the stylesheet and
 * template filters as SiteTemplatesTest does.
 */
class GlobalStylesTest extends \WP_UnitTestCase
{
    private const THEME = 'twentytwentyfour';

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
        remove_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10);
        remove_filter('content_save_pre', [self::class, 'reencode_like_wp69'], 11);
        remove_filter('content_save_pre', [self::class, 'drop_the_base_color'], 11);
        $this->use_classic_theme();
        parent::tearDown();
    }

    private function use_block_theme(): void
    {
        add_filter('stylesheet', [$this, 'block_theme_slug']);
        add_filter('template', [$this, 'block_theme_slug']);
        $this->forget_theme_data();
    }

    private function use_classic_theme(): void
    {
        remove_filter('stylesheet', [$this, 'block_theme_slug']);
        remove_filter('template', [$this, 'block_theme_slug']);
        $this->forget_theme_data();
    }

    private function forget_theme_data(): void
    {
        \WP_Theme_JSON_Resolver::clean_cached_data();
        wp_clean_theme_json_cache();
    }

    public function block_theme_slug(): string
    {
        return self::THEME;
    }

    /** The front end's global stylesheet, freshly generated. */
    private function stylesheet(): string
    {
        $this->forget_theme_data();
        return wp_get_global_stylesheet();
    }

    /** @return int[] every user global styles post of the test theme. */
    private function user_posts(): array
    {
        return array_map('intval', get_posts([
            'post_type'      => 'wp_global_styles',
            'post_status'    => ['publish', 'draft', 'auto-draft'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [[ 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => self::THEME ]],
        ]));
    }

    /** A user global styles post as the site editor stores one. */
    private function create_user_post(string $content): int
    {
        $id = self::factory()->post->create([
            'post_type'    => 'wp_global_styles',
            'post_status'  => 'publish',
            'post_title'   => 'Custom Styles',
            'post_name'    => 'wp-global-styles-' . self::THEME,
            'post_content' => $content,
        ]);
        wp_set_post_terms($id, [self::THEME], 'wp_theme');
        $this->forget_theme_data();
        return $id;
    }

    private function raw_content(int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id));
    }

    public function test_reads_the_theme_user_and_merged_layers_variations_and_fonts(): void
    {
        $this->use_block_theme();
        $ops_before = count(Snapshot_Store::recent(100));

        $out = $this->read->handle(['entity' => 'global_styles']);

        $this->assertTrue($out['block_theme']);
        $this->assertSame(self::THEME, $out['theme']);
        $this->assertSame(\WP_Theme_JSON::LATEST_SCHEMA, $out['schema_version']);
        $this->assertSame(0, $out['user_post_id']);

        $theme_palette = $out['layers']['theme']['settings']['color']['palette']['theme'];
        $this->assertContains('base', array_column($theme_palette, 'slug'));
        $this->assertSame([], $out['layers']['user']);
        $this->assertArrayHasKey('styles', $out['layers']['merged']);
        $this->assertContains('base', array_column($out['layers']['merged']['settings']['color']['palette']['theme'], 'slug'));

        $this->assertContains('Ember', array_column($out['variations'], 'title'));
        $this->assertNotEmpty($out['font_families']);
        $this->assertArrayHasKey('slug', $out['font_families'][0]);
        $this->assertArrayHasKey('fontFamily', $out['font_families'][0]);

        // Reading never creates the user post, as core's own lookup can.
        $this->assertSame([], $this->user_posts());
        $this->assertCount($ops_before, Snapshot_Store::recent(100));
    }

    public function test_a_dot_path_id_narrows_every_layer(): void
    {
        $this->use_block_theme();

        $out = $this->read->handle(['entity' => 'global_styles', 'id' => 'settings.color.palette']);

        $this->assertSame('settings.color.palette', $out['path']);
        $this->assertArrayHasKey('theme', $out['layers']['theme']);
        $this->assertNull($out['layers']['user']);
        $this->assertArrayHasKey('theme', $out['layers']['merged']);
        $this->assertArrayNotHasKey('variations', $out);
    }

    public function test_changing_a_palette_color_changes_the_front_end_and_rollback_removes_the_new_post(): void
    {
        $this->use_block_theme();
        $before = $this->stylesheet();
        $this->assertStringNotContainsString('#123456', $before);

        $out = $this->write->handle([
            'entity'     => 'global_styles',
            'attrs'      => ['settings.color.palette.theme.base.color' => '#123456'],
            'session_id' => 's379',
        ]);

        $this->assertNotEmpty($out['operation_id']);
        $this->assertGreaterThan(0, $out['user_post_id']);
        $this->assertSame([$out['user_post_id']], $this->user_posts());
        $this->assertStringContainsString('--wp--preset--color--base: #123456', $this->stylesheet());

        // The rest of the theme palette is kept: the entry was patched in a
        // copy of the effective list, not written as a one-color palette.
        $palette = $this->read->handle(['entity' => 'global_styles', 'id' => 'settings.color.palette.theme'])['layers']['merged'];
        $this->assertContains('contrast', array_column($palette, 'slug'));
        $this->assertSame(hash('sha256', $this->raw_content($out['user_post_id'])), $out['content_hash']);

        $rows = Snapshot_Store::list_by_session('s379');
        $this->assertCount(1, $rows);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame([], $this->user_posts());
        $this->assertSame($before, $this->stylesheet());
    }

    public function test_a_font_size_preset_and_element_style_patch_rolls_back_byte_for_byte(): void
    {
        $this->use_block_theme();
        $original = '{"styles":{"color":{"text":"#222222"}},"isGlobalStylesUserThemeJSON":true,"version":3}';
        $post_id  = $this->create_user_post($original);
        $before   = $this->stylesheet();

        $out = $this->write->handle([
            'entity' => 'global_styles',
            'attrs'  => [
                'settings.typography.fontSizes.theme.medium.size' => '2.75rem',
                'styles.elements.link.color.text'                => '#ab1234',
            ],
        ]);

        $this->assertSame($post_id, $out['user_post_id']);
        $css = $this->stylesheet();
        $this->assertStringContainsString('2.75rem', $css);
        $this->assertStringContainsString('#ab1234', $css);
        $this->assertStringContainsString('#222222', $css, 'Existing user styles are kept.');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame([$post_id], $this->user_posts());
        $this->assertSame($original, $this->raw_content($post_id));
        $this->assertSame($before, $this->stylesheet());
    }

    public function test_null_removes_a_user_value(): void
    {
        $this->use_block_theme();
        $post_id = $this->create_user_post('{"styles":{"color":{"text":"#222222","background":"#eeeeee"}},"isGlobalStylesUserThemeJSON":true,"version":3}');

        $this->write->handle(['entity' => 'global_styles', 'attrs' => ['styles.color.text' => null]]);

        $data = json_decode($this->raw_content($post_id), true);
        $this->assertArrayNotHasKey('text', $data['styles']['color']);
        $this->assertSame('#eeeeee', $data['styles']['color']['background']);
    }

    public function test_applying_a_style_variation_is_one_undoable_write(): void
    {
        $this->use_block_theme();
        $before = $this->stylesheet();

        $out = $this->write->handle([
            'entity'     => 'global_styles',
            'action'     => 'variation',
            'title'      => 'Ember',
            'session_id' => 's379-variation',
        ]);

        $this->assertCount(1, Snapshot_Store::list_by_session('s379-variation'));
        $data = json_decode($this->raw_content($out['user_post_id']), true);
        $this->assertSame('Ember', $data['title']);
        $this->assertTrue($data['isGlobalStylesUserThemeJSON']);
        $this->assertNotSame($before, $this->stylesheet());
        $this->assertStringContainsStringIgnoringCase('#dbab88', $this->stylesheet());

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame([], $this->user_posts());
        $this->assertSame($before, $this->stylesheet());
    }

    public function test_revert_resets_the_user_layer_and_rollback_brings_it_back(): void
    {
        $this->use_block_theme();
        $original = '{"styles":{"color":{"text":"#222222"}},"isGlobalStylesUserThemeJSON":true,"version":3}';
        $post_id  = $this->create_user_post($original);

        $out = $this->write->handle(['entity' => 'global_styles', 'action' => 'revert']);

        $this->assertSame([], $this->user_posts());
        $this->assertStringNotContainsString('#222222', $this->stylesheet());

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame([$post_id], $this->user_posts());
        $this->assertSame($original, $this->raw_content($post_id));
        $this->assertStringContainsString('#222222', $this->stylesheet());
    }

    public static function invalid_writes(): array
    {
        return [
            'unknown style property' => [['attrs' => ['styles.color.sparkle' => '#fff']], 'styles.color.sparkle'],
            'unknown top level key'  => [['attrs' => ['templateParts.header' => 'x']], '"settings." or "styles."'],
            'unknown block name'     => [['attrs' => ['styles.blocks.core/not-a-block.color.text' => '#fff']], 'styles.blocks.core/not-a-block.color.text'],
            'unknown preset slug'    => [['attrs' => ['settings.color.palette.theme.nope.color' => '#fff']], 'nope'],
            'css injection'          => [['attrs' => ['styles.color.text' => 'red;}body{display:none']], 'styles.color.text'],
            'preset without slug'    => [['attrs' => ['settings.color.palette.custom' => [['color' => '#fff']]]], 'slug'],
            'object where a value'   => [['attrs' => ['styles.color' => 'red']], 'styles.color'],
            'empty patch'            => [['attrs' => []], 'attrs'],
            'unknown variation'      => [['action' => 'variation', 'title' => 'Nope'], 'Ember'],
            'path action'            => [['action' => 'update_block', 'attrs' => ['styles.color.text' => '#fff']], 'save, variation, revert'],
            'revert with no styles'  => [['action' => 'revert'], 'no user global styles'],
        ];
    }

    /**
     * @dataProvider invalid_writes
     */
    public function test_invalid_paths_and_values_are_refused_before_anything_is_saved(array $args, string $message): void
    {
        $this->use_block_theme();
        $ops_before = count(Snapshot_Store::recent(100));

        try {
            $this->write->handle(['entity' => 'global_styles'] + $args);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], $this->user_posts());
        $this->assertCount($ops_before, Snapshot_Store::recent(100));
    }

    /** An administrator who, like one on multisite, lacks unfiltered_html, so kses filters the global styles post. */
    private function act_as_admin_without_unfiltered_html(): void
    {
        add_filter('map_meta_cap', [self::class, 'deny_unfiltered_html'], 10, 2);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertFalse(current_user_can('unfiltered_html'));
        $this->assertSame(9, has_filter('content_save_pre', 'wp_filter_global_styles_post'));
    }

    /**
     * @param string[] $caps
     * @return string[]
     */
    public static function deny_unfiltered_html(array $caps, string $cap): array
    {
        return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps;
    }

    /**
     * WordPress 6.9's kses filter for this post re-encodes the document with
     * a bare wp_json_encode(), so every "/" comes back as "\/". Applied after
     * core's filter so the 7.1 suite sees the same bytes the 6.9 one does.
     *
     * @param mixed $content
     * @return mixed
     */
    public static function reencode_like_wp69($content)
    {
        $data = json_decode(wp_unslash((string) $content), true);
        return is_array($data) ? wp_slash(wp_json_encode($data)) : $content;
    }

    /**
     * A save filter that removes a value from the document, as kses does
     * with a property it judges unsafe.
     *
     * @param mixed $content
     * @return mixed
     */
    public static function drop_the_base_color($content)
    {
        $data = json_decode(wp_unslash((string) $content), true);
        if (! is_array($data) || ! isset($data['settings']['color']['palette']['theme'])) {
            return $content;
        }
        $data['settings']['color']['palette']['theme'] = array_values(array_filter(
            $data['settings']['color']['palette']['theme'],
            static fn ($entry) => 'base' !== ($entry['slug'] ?? '')
        ));
        return wp_slash(wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
    }

    /** @return array<string, array{0: bool}> */
    public static function kses_encodings(): array
    {
        return [
            'core encoding of this WordPress' => [false],
            'escaped slashes, as 6.9 stores'   => [true],
        ];
    }

    /**
     * Issue #487: "Base / Two" in the patched palette made the stored bytes
     * differ from the encoded document on 6.9 although nothing was removed,
     * so every palette save was rolled back. The returned hash must be the
     * stored one, so it works as the next write's expected_hash.
     *
     * @dataProvider kses_encodings
     */
    public function test_an_admin_without_unfiltered_html_can_change_a_palette_named_with_a_slash(bool $escaped_slashes): void
    {
        $this->use_block_theme();
        $this->act_as_admin_without_unfiltered_html();
        if ($escaped_slashes) {
            add_filter('content_save_pre', [self::class, 'reencode_like_wp69'], 11);
        }

        $first = $this->write->handle([
            'entity' => 'global_styles',
            'attrs'  => ['settings.color.palette.theme.base.color' => '#123456'],
        ]);

        $post_id = (int) $first['user_post_id'];
        $stored  = $this->raw_content($post_id);
        $this->assertSame(hash('sha256', $stored), $first['content_hash']);
        $palette = json_decode($stored, true)['settings']['color']['palette']['theme'];
        $this->assertContains('Base / Two', array_column($palette, 'name'));
        $this->assertStringContainsString('--wp--preset--color--base: #123456', $this->stylesheet());

        $read = $this->read->handle(['entity' => 'global_styles']);
        $this->assertSame($first['content_hash'], $read['content_hash']);

        $second = $this->write->handle([
            'entity'        => 'global_styles',
            'attrs'         => ['settings.color.palette.theme.base.color' => '#654321'],
            'expected_hash' => $read['content_hash'],
        ]);
        $this->assertSame($post_id, (int) $second['user_post_id']);
        $this->assertStringContainsString('--wp--preset--color--base: #654321', $this->stylesheet());
    }

    /**
     * Comparing the decoded document must still fail closed: when a save
     * filter removes something, the write is refused and rolled back.
     *
     * @dataProvider kses_encodings
     */
    public function test_a_save_whose_filters_remove_a_value_is_rolled_back(bool $escaped_slashes): void
    {
        $this->use_block_theme();
        $original = '{"settings":{"color":{"palette":{"theme":[{"slug":"base","name":"Base","color":"#111111"}]}}},"isGlobalStylesUserThemeJSON":true,"version":3}';
        $post_id  = $this->create_user_post($original);
        $this->act_as_admin_without_unfiltered_html();
        if ($escaped_slashes) {
            add_filter('content_save_pre', [self::class, 'reencode_like_wp69'], 11);
        }
        add_filter('content_save_pre', [self::class, 'drop_the_base_color'], 11);

        try {
            $this->write->handle([
                'entity' => 'global_styles',
                'attrs'  => ['settings.color.palette.theme.base.color' => '#222222'],
            ]);
            $this->fail('Expected the verification to refuse a save the filters changed.');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('Verification failed', $e->getMessage());
        }

        $this->assertSame([$post_id], $this->user_posts());
        $this->assertSame($original, $this->raw_content($post_id));
    }

    public function test_an_unsafe_css_value_is_refused_for_an_admin_without_unfiltered_html(): void
    {
        $this->use_block_theme();
        $this->act_as_admin_without_unfiltered_html();
        $ops_before = count(Snapshot_Store::recent(100));

        try {
            $this->write->handle(['entity' => 'global_styles', 'attrs' => ['styles.color.background' => 'url(javascript:alert(1))']]);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('styles.color.background', $e->getMessage());
        }

        $this->assertSame([], $this->user_posts());
        $this->assertCount($ops_before, Snapshot_Store::recent(100));
    }

    public function test_a_stale_expected_hash_is_refused(): void
    {
        $this->use_block_theme();
        $post_id = $this->create_user_post('{"isGlobalStylesUserThemeJSON":true,"version":3}');
        $read    = $this->read->handle(['entity' => 'global_styles']);
        $this->assertSame($post_id, $read['user_post_id']);
        $this->assertSame(hash('sha256', $this->raw_content($post_id)), $read['content_hash']);

        wp_update_post(['ID' => $post_id, 'post_content' => '{"styles":{"color":{"text":"#010101"}},"isGlobalStylesUserThemeJSON":true,"version":3}']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stale expected_hash');
        $this->write->handle([
            'entity'        => 'global_styles',
            'attrs'         => ['styles.color.text' => '#fff'],
            'expected_hash' => $read['content_hash'],
        ]);
    }

    public function test_custom_css_needs_edit_css(): void
    {
        $this->use_block_theme();
        $grant = static function (array $caps, string $cap): array {
            return 'edit_css' === $cap ? ['do_not_allow'] : $caps;
        };
        add_filter('map_meta_cap', $grant, 10, 2);

        try {
            $this->write->handle(['entity' => 'global_styles', 'attrs' => ['styles.css' => 'body{color:red}']]);
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('edit_css', $e->getMessage());
        } finally {
            remove_filter('map_meta_cap', $grant, 10);
        }
        $this->assertSame([], $this->user_posts());
    }

    public function test_classic_theme_is_told_global_styles_do_not_apply(): void
    {
        $this->assertFalse(wp_is_block_theme());

        $out = $this->read->handle(['entity' => 'global_styles']);

        $this->assertFalse($out['block_theme']);
        $this->assertStringContainsString('global styles do not apply', $out['message']);
        $this->assertArrayNotHasKey('layers', $out);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('global styles do not apply');
        $this->write->handle(['entity' => 'global_styles', 'attrs' => ['styles.color.text' => '#fff']]);
    }

    public function test_rollback_of_a_global_styles_write_requires_edit_theme_options(): void
    {
        $this->use_block_theme();
        $out = $this->write->handle(['entity' => 'global_styles', 'attrs' => ['styles.color.text' => '#303030']]);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotNull(get_post($out['user_post_id']));
    }
}
