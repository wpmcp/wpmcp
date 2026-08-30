<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;

/**
 * The theme integration (issue #69). Covers the guard chain the adversarial
 * review demanded: allowlist and structural-key refusals happen BEFORE any
 * snapshot is written, values are sanitized per key, both write ops are
 * default-off and capability-gated, and the child-theme scaffolder is
 * confined, idempotent, grandchild-refusing and self-cleaning on failure.
 */
class ThemeIntegrationTest extends \WP_UnitTestCase
{
    private const SLUG = 'wpmcp-test-child';

    private Theme_Integration $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->theme = new Theme_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->remove_scaffold();
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $this->remove_scaffold();
        parent::tearDown();
    }

    private function scaffold_dir(): string
    {
        return trailingslashit(get_theme_root()) . self::SLUG;
    }

    private function remove_scaffold(): void
    {
        $dir = $this->scaffold_dir();
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    // ---------------------------------------------------------------- context

    public function test_get_context_reports_theme_shape(): void
    {
        $out = $this->theme->handle_read([ 'operation' => 'get-context' ])['result'];

        $this->assertSame(get_stylesheet(), $out['stylesheet']);
        $this->assertSame(get_template(), $out['template']);
        $this->assertIsBool($out['is_child_theme']);
        $this->assertIsBool($out['is_block_theme']);
        $this->assertIsArray($out['nav_menus']);
        $this->assertIsArray($out['supports']);
        $this->assertArrayHasKey('framework', $out);
    }

    public function test_framework_detection_is_case_insensitive(): void
    {
        // Divi and Avada ship directories with a capital letter; a
        // case-sensitive compare against the lowercase map never matched.
        $divi = static fn () => 'Divi';
        add_filter('template', $divi);
        $out = $this->theme->handle_read([ 'operation' => 'get-context' ])['result'];
        remove_filter('template', $divi);

        $this->assertSame('divi', $out['framework']);
    }

    // -------------------------------------------------------------- allowlist

    public function test_non_allowlisted_key_is_refused_at_top_level_without_snapshot(): void
    {
        $before = $this->snapshot_count();

        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'some_random_mod' => 'x' ] ],
        ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame('key_not_allowlisted', $out['error']['code']);
        $this->assertArrayNotHasKey('result', $out);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame($before, $this->snapshot_count(), 'A refused call must write no snapshot row');
    }

    public function test_structural_key_is_refused_without_snapshot(): void
    {
        $before = $this->snapshot_count();

        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'nav_menu_locations' => [ 'primary' => 1 ] ] ],
        ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame($before, $this->snapshot_count());
    }

    public function test_filter_cannot_widen_allowlist_onto_structural_keys(): void
    {
        $widen = static fn (array $keys): array => array_merge($keys, [ 'custom_css_post_id' ]);
        add_filter('wpmcp_theme_mod_allowlist', $widen);

        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'custom_css_post_id' => 7 ] ],
        ]);

        remove_filter('wpmcp_theme_mod_allowlist', $widen);
        $this->assertSame('structural_key_refused', $out['error']['code']);
    }

    public function test_dropped_keys_are_not_writable(): void
    {
        // site_icon lives in an option and header_text is encoded in
        // header_textcolor; neither is a theme mod, so neither is writable.
        foreach ([ 'site_icon', 'header_text' ] as $key) {
            $out = $this->theme->handle_write([
                'operation' => 'set-theme-mods',
                'args'      => [ 'mods' => [ $key => 1 ] ],
            ]);
            $this->assertSame('key_not_allowlisted', $out['error']['code'], $key);
        }
    }

    // ------------------------------------------------------------ mod writing

    public function test_allowlisted_write_sanitizes_and_snapshots(): void
    {
        $out = $this->theme->handle_write([
            'operation'  => 'set-theme-mods',
            'args'       => [ 'mods' => [ 'background_color' => '#AABBCC' ] ],
            'session_id' => 's-69',
        ]);

        $this->assertSame('aabbcc', $out['result']['mods']['background_color']);
        $this->assertSame('aabbcc', get_theme_mod('background_color'));
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
    }

    public function test_xss_payload_in_background_color_is_refused(): void
    {
        $before = get_theme_mod('background_color');

        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'background_color' => 'red;}</style><script>alert(1)</script>' ] ],
        ]);

        $this->assertSame('invalid_mod_value', $out['error']['code']);
        $this->assertSame($before, get_theme_mod('background_color'));
    }

    public function test_non_scalar_mod_value_is_rejected_by_schema(): void
    {
        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'header_textcolor' => [ 'nested' => 'array' ] ] ],
        ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertArrayNotHasKey('result', $out);
    }

    public function test_enum_backed_key_refuses_a_bogus_value(): void
    {
        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'background_repeat' => 'sideways' ] ],
        ]);

        $this->assertSame('invalid_mod_value', $out['error']['code']);
    }

    public function test_write_ops_are_default_off(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');

        $out = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'background_color' => '#ffffff' ] ],
        ]);

        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->assertSame('operation_disabled', $out['error']['code']);
    }

    public function test_mod_ops_require_edit_theme_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));

        $write = $this->theme->handle_write([
            'operation' => 'set-theme-mods',
            'args'      => [ 'mods' => [ 'background_color' => '#ffffff' ] ],
        ]);
        $read = $this->theme->handle_read([ 'operation' => 'list-theme-mods' ]);

        $this->assertSame('operation_denied', $write['error']['code']);
        $this->assertSame('operation_denied', $read['error']['code']);
    }

    // ------------------------------------------------------------- scaffolder

    public function test_scaffolder_requires_confirm(): void
    {
        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertDirectoryDoesNotExist($this->scaffold_dir());
    }

    public function test_scaffolder_creates_an_activatable_child_and_is_idempotent(): void
    {
        $first = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG, 'name' => 'WPMCP Test Child' ],
        ]);

        $this->assertTrue($first['result']['created']);
        $this->assertFileExists($this->scaffold_dir() . '/style.css');
        $this->assertFileExists($this->scaffold_dir() . '/functions.php');

        $style = (string) file_get_contents($this->scaffold_dir() . '/style.css');
        $this->assertStringContainsString('Template: ' . get_template(), $style);

        $second = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $this->assertFalse($second['result']['created']);
        $this->assertTrue($second['result']['existing']);
    }

    public function test_scaffolder_neutralizes_comment_terminator_in_name(): void
    {
        $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG, 'name' => 'Evil */ body{display:none} /*' ],
        ]);

        $style = (string) file_get_contents($this->scaffold_dir() . '/style.css');
        // Exactly one comment block: the injected terminator (and the opener
        // that would have followed it) must be gone, so nothing the caller
        // supplied can become live CSS.
        $this->assertSame(1, substr_count($style, '/*'));
        $this->assertSame(1, substr_count($style, '*/'));
        $this->assertStringStartsWith('/*', $style);
        $this->assertStringEndsWith("*/\n", $style);
    }

    public function test_scaffolder_confines_slug_to_the_theme_root(): void
    {
        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => '../../../../etc/wpmcp-escape' ],
        ]);

        $path = $out['result']['path'] ?? '';
        if ('' !== $path) {
            $root = trailingslashit((string) realpath(get_theme_root()));
            $this->assertStringStartsWith($root, $path);
            $this->assertSame($root . basename($path), $path, 'The scaffold must be a single segment under the theme root');
            $this->assertStringNotContainsString('..', $path);
            // Clean up whatever confined directory the sanitizer produced.
            foreach ((array) glob($path . '/*') as $file) {
                if (is_string($file)) {
                    unlink($file);
                }
            }
            rmdir($path);
        } else {
            $this->assertArrayHasKey('error', $out);
        }
        $this->assertDirectoryDoesNotExist('/etc/wpmcp-escape');
    }

    public function test_scaffolder_refuses_a_grandchild_of_the_active_child(): void
    {
        // WP 6.9's is_child_theme() compares the resolved path globals, not
        // the filtered accessors, so this is the way to stage an active child.
        global $wp_stylesheet_path;
        $real = $wp_stylesheet_path;
        $wp_stylesheet_path = trailingslashit(get_theme_root()) . 'some-active-child';

        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $wp_stylesheet_path = $real;
        $this->assertSame('grandchild_refused', $out['error']['code']);
        $this->assertArrayNotHasKey('result', $out);
        $this->assertArrayNotHasKey('operation_id', $out);
    }

    public function test_scaffolder_refuses_an_explicit_parent_that_is_itself_a_child(): void
    {
        // Scaffold a real child, then ask for a child OF that child.
        $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => 'wpmcp-test-grandchild', 'parent' => self::SLUG ],
        ]);

        $this->assertSame('grandchild_refused', $out['error']['code']);
        $this->assertDirectoryDoesNotExist(trailingslashit(get_theme_root()) . 'wpmcp-test-grandchild');
    }

    public function test_scaffolder_refuses_an_unknown_explicit_parent(): void
    {
        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG, 'parent' => 'no-such-theme-here' ],
        ]);

        $this->assertSame('unknown_parent', $out['error']['code']);
    }

    public function test_scaffolder_refuses_a_foreign_directory(): void
    {
        mkdir($this->scaffold_dir());
        file_put_contents($this->scaffold_dir() . '/style.css', "/* Someone else's theme */\n");

        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $this->assertSame('directory_exists', $out['error']['code']);
    }

    public function test_scaffolder_rejects_a_half_written_scaffold(): void
    {
        // Marker present but functions.php missing: the previous
        // implementation reported this half-theme as an existing scaffold.
        mkdir($this->scaffold_dir());
        file_put_contents(
            $this->scaffold_dir() . '/style.css',
            "/*\nTheme Name: Half\nTemplate: " . get_template() . "\nDescription: Generated by WPMCP create-child-theme.\n*/\n"
        );

        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $this->assertTrue($out['result']['created'], 'A half-written scaffold must be completed, not reported as done');
        $this->assertFileExists($this->scaffold_dir() . '/functions.php');
    }

    public function test_scaffolder_requires_edit_themes(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertDirectoryDoesNotExist($this->scaffold_dir());
    }

    public function test_scaffolder_honors_disallow_file_edit(): void
    {
        $block = static fn (): bool => true;
        add_filter('wpmcp_theme_file_edit_disallowed', $block);

        $out = $this->theme->handle_write([
            'operation' => 'create-child-theme',
            'confirm'   => true,
            'args'      => [ 'slug' => self::SLUG ],
        ]);

        remove_filter('wpmcp_theme_file_edit_disallowed', $block);
        $this->assertSame('file_edit_disabled', $out['error']['code']);
        $this->assertDirectoryDoesNotExist($this->scaffold_dir());
    }

    // --------------------------------------------------------- framework pack

    public function test_framework_pack_registers_only_when_that_family_is_active(): void
    {
        $catalog = $this->theme->catalog();
        $names   = array_column($catalog['operations'], 'name');
        $this->assertNotContains('get-astra-settings', $names);

        $astra = static fn () => 'astra';
        add_filter('template', $astra);
        $with = array_column((new Theme_Integration())->catalog()['operations'], 'name');
        remove_filter('template', $astra);

        $this->assertContains('get-astra-settings', $with);
        $this->assertContains('set-astra-settings', $with);
    }
}
