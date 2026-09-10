<?php

namespace WPMCP\Tests\Pro\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\WidgetBuilder\Create_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Set_Widget_Status;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Compiler\Compile_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Compiler\Compiled_Widget_Manifest;
use WPMCP\Tools\WidgetBuilder\Compiler\Generated_Code_Lint;
use WPMCP\Tools\WidgetBuilder\Compiler\Widget_Compiler;

/**
 * Issue #72: the spec-compiled widget builder. This is the exec-adjacent half
 * of the widget builder (the plugin generates PHP and writes it to disk), so
 * the suite is written adversarially: a hostile-spec corpus that must survive
 * compilation as inert text, seeded-hostile sources that the pre-write lint
 * must reject, and proofs that nothing loads unless the manifest says so and
 * the bytes on disk still hash to what the manifest recorded.
 */
class CompiledWidgetBuilderTest extends \WP_UnitTestCase
{
    /** @var string */
    private $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Inside wp-content on purpose: sandbox_dir() confines the filter, so
        // a value outside the install is ignored (see the test for it below).
        $this->sandbox = rtrim(WP_CONTENT_DIR, '/') . '/wpmcp-widget-sandbox-' . wp_generate_password(8, false);
        add_filter('wpmcp_compiled_widgets_dir', function () {
            return $this->sandbox;
        });
        add_filter('wpmcp_enable_widget_compiler', '__return_true');
        delete_option(Compiled_Widget_Manifest::OPTION);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_widget_compiler', '__return_true');
        remove_all_filters('wpmcp_compiled_widgets_dir');
        if (is_dir($this->sandbox)) {
            foreach ((array) glob($this->sandbox . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($this->sandbox);
        }
        delete_option(Compiled_Widget_Manifest::OPTION);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function valid_spec(): array
    {
        return [
            'name'     => 'promo-box',
            'title'    => 'Promo Box',
            'icon'     => 'eicon-info-box',
            'keywords' => ['promo', 'cta'],
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
                ['name' => 'body', 'type' => 'wysiwyg', 'label' => 'Body', 'default' => ''],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => ''],
            ],
            'template' => '<div class="promo"><h3>{{heading}}</h3><div>{{body}}</div><a href="{{link}}">Go</a></div>',
        ];
    }

    private function create(?array $spec = null): int
    {
        $out = (new Create_Custom_Widget())->handle(['spec' => $spec ?? $this->valid_spec()]);
        $this->assertIsArray($out, 'create-custom-widget should return the new widget');
        return (int) $out['widget_id'];
    }

    // ---- Generated_Code_Lint: seeded-hostile sources ------------------------

    /**
     * @dataProvider hostile_sources
     */
    public function test_lint_rejects_hostile_source(string $label, string $source): void
    {
        $out = Generated_Code_Lint::check($source);
        $this->assertInstanceOf(\WP_Error::class, $out, "lint must reject: {$label}");
        $this->assertSame('wpmcp_generated_code_rejected', $out->get_error_code());
    }

    public function hostile_sources(): array
    {
        return [
            'eval'                  => ['eval', '<?php eval($x);'],
            'backtick'              => ['backtick', '<?php $out = `id`;'],
            'include'               => ['include', '<?php include "/etc/passwd";'],
            'require'               => ['require', '<?php require_once "/etc/passwd";'],
            'close tag'             => ['close tag', "<?php echo 1; ?>trailing"],
            'plain exec'            => ['plain exec', '<?php exec("id");'],
            // A namespaced generated class calls functions fully qualified, and
            // PHP 8 tokenizes "\exec" as ONE T_NAME_FULLY_QUALIFIED token: a
            // lint that only looks at T_STRING never sees it.
            'fully qualified exec'  => ['fully qualified exec', '<?php \exec("id");'],
            'fully qualified write' => ['fully qualified write', '<?php \file_put_contents("/tmp/x", "y");'],
            'qualified call'        => ['qualified call', '<?php Evil\Ns\system("id");'],
            // Variable function: the callee name never appears as an identifier.
            'variable function'     => ['variable function', '<?php $f = "sys" . "tem"; $f("id");'],
            'variable variable'     => ['variable variable', '<?php $v = "x"; $y = $$v;'],
            'dynamic method'        => ['dynamic method', '<?php $m = "run"; $o->$m();'],
            'dynamic static'        => ['dynamic static', '<?php $m = "run"; Foo::$m();'],
            'dynamic new'           => ['dynamic new', '<?php $c = "Foo"; $o = new $c();'],
            // STATIC / METHOD / new dispatch with a LITERAL callee. The
            // dangerous name is a plain identifier here, but it sits after
            // `::`, `->` or `new`, so a lint that treats those operators as
            // "not a call" never looks at it at all.
            'static call'           => ['static call', '<?php Evil::system("id");'],
            'nullsafe method call'  => ['nullsafe method call', '<?php $o?->system("id");'],
            'method call'           => ['method call', '<?php $o->system("id");'],
            'literal new'           => ['literal new', '<?php $o = new Evil("id");'],
            'new on $this method'   => ['new on $this method', '<?php class X { function f() { return new Evil(); } }'],
            'this method not in allowlist' => ['this method not in allowlist', '<?php class X { function f() { $this->system("id"); } }'],
            'foreign static const'  => ['foreign static const', '<?php echo Evil::NAME;'],
            // String-callable dispatch: "system" is only ever a string literal.
            'array_map callable'    => ['array_map callable', '<?php array_map("system", $x);'],
            'add_action callable'   => ['add_action callable', '<?php add_action("init", "system");'],
            'call_user_func'        => ['call_user_func', '<?php call_user_func("system", "id");'],
            'wpdb query'            => ['wpdb query', '<?php $wpdb = null; wp_delete_post(1, true);'],
            'delete_option'         => ['delete_option', '<?php delete_option("siteurl");'],
            'remote get'            => ['remote get', '<?php wp_safe_remote_get("http://evil.test");'],
            'base64 payload'        => ['base64 payload', '<?php echo base64_decode("aWQ=");'],
            'glob'                  => ['glob', '<?php $f = glob("/etc/*");'],
            'complex interpolation' => ['complex interpolation', '<?php $a = ["x" => "y"]; echo "{$a[\'x\']}";'],
            'syntax error'          => ['syntax error', '<?php function {'],
            'missing open tag'      => ['missing open tag', 'echo 1;'],
            'empty'                 => ['empty', '   '],
        ];
    }

    /**
     * token_get_all(..., TOKEN_PARSE) throws CompileError - NOT ParseError -
     * for __halt_compiler() inside a function. The tripwire has to report that
     * as a rejection, not fatal the request.
     */
    public function test_lint_reports_compile_error_instead_of_fataling(): void
    {
        $out = Generated_Code_Lint::check('<?php function f() { __halt_compiler(); }');
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_generated_code_rejected', $out->get_error_code());
    }

    public function test_lint_accepts_the_shape_the_emitter_produces(): void
    {
        $source = "<?php\nclass X\n{\n    protected function render()\n    {\n"
            . "        \$settings = \$this->get_settings_for_display();\n"
            . "        echo '<p>';\n"
            . "        echo esc_html((string) (\$settings['a'] ?? ''));\n"
            . "        echo '</p>';\n    }\n}\n";
        $this->assertTrue(Generated_Code_Lint::check($source));
    }

    /**
     * The old denylist was context-blind: a property named $this->copy or a
     * method named rename() aborted a perfectly safe write. Call position is
     * what matters.
     */
    public function test_lint_does_not_flag_safe_identifiers_out_of_call_position(): void
    {
        $source = "<?php\nclass X\n{\n    const SYSTEM = 1;\n"
            . "    public function rename()\n    {\n        return \$this->copy;\n    }\n}\n";
        $this->assertTrue(Generated_Code_Lint::check($source));
    }

    // ---- Widget_Compiler: emission is safe by construction ------------------

    public function test_every_declared_control_type_compiles(): void
    {
        $controls = [];
        $template = '';
        foreach (array_keys(Widget_Spec::CONTROL_TYPES) as $i => $type) {
            $name       = 'c' . $i;
            $controls[] = ['name' => $name, 'type' => $type, 'label' => ucfirst($type)];
            $template  .= '<span>{{' . $name . '}}</span>';
        }
        $spec = ['name' => 'every-type', 'title' => 'Every Type', 'controls' => $controls, 'template' => $template];

        $source = Widget_Compiler::compile($spec, 42);
        $this->assertIsString($source, 'every type Widget_Spec accepts must also compile');
        $this->assertTrue(Generated_Code_Lint::check($source));
    }

    public function test_emitted_source_escapes_every_placeholder_per_control_type(): void
    {
        $source = Widget_Compiler::compile($this->valid_spec(), 7);
        $this->assertIsString($source);

        // The fallback is the control's declared default, exactly as
        // Widget_Renderer does it, so the two render paths cannot diverge.
        $this->assertStringContainsString("echo esc_html((string) (\$settings['heading'] ?? 'Hi'));", $source);
        $this->assertStringContainsString("echo wp_kses_post((string) (\$settings['body'] ?? ''));", $source);
        $this->assertStringContainsString("echo esc_url((string) (\$settings['link'] ?? ''));", $source);
        // No echo of a setting anywhere without an escaper around it.
        $this->assertSame(
            0,
            preg_match('/echo\s+\$settings/', $source),
            'a setting must never be echoed unescaped'
        );
    }

    public function test_compiler_and_renderer_share_one_escaper_table(): void
    {
        foreach (Widget_Spec::CONTROL_TYPES as $type => $meta) {
            $this->assertArrayHasKey('escaper', $meta, "control type {$type} must declare an escaper");
            $this->assertSame($meta['escaper'], Widget_Spec::escaper_for($type));
        }
    }

    /**
     * @dataProvider hostile_specs
     */
    public function test_hostile_spec_compiles_to_inert_text(string $label, array $spec): void
    {
        $source = Widget_Compiler::compile($spec, 11);
        $this->assertIsString($source, "hostile spec should still compile: {$label}");
        $this->assertTrue(
            Generated_Code_Lint::check($source),
            "hostile spec must not produce code the lint rejects: {$label}"
        );
    }

    public function hostile_specs(): array
    {
        $make = static function (string $title, string $template, string $default = ''): array {
            return [
                'name'     => 'hostile',
                'title'    => $title,
                'controls' => [['name' => 'a', 'type' => 'text', 'label' => 'A', 'default' => $default]],
                'template' => $template,
            ];
        };

        return [
            'template breaks out of the string' => ['quote break', $make('T', "</p>'; system('id'); \$x = '")],
            'template closes php'               => ['close php', $make('T', '<p>?> <?php system("id"); ?></p>')],
            'template opens php'                => ['open php', $make('T', '<?php system("id"); ?>')],
            'title is code'                     => ['title code', $make("'); system('id'); //", '<p>{{a}}</p>')],
            'default is code'                   => ['default code', $make('T', '<p>{{a}}</p>', "'); system('id'); //")],
            'backslash soup'                    => ['backslash', $make('T', '<p>\\\\\' . system("id") . \'</p>')],
            'heredoc-ish'                       => ['heredoc', $make('T', "<<<EOT\nsystem('id')\nEOT")],
            'null byte'                         => ['null byte', $make('T', "<p>a\0b{{a}}</p>")],
            'unknown placeholder'               => ['unknown placeholder', $make('T', '<p>{{ghost}}</p>')],
        ];
    }

    public function test_hostile_template_text_is_a_literal_not_syntax(): void
    {
        $spec             = $this->valid_spec();
        $spec['template'] = "</p>'; system('id'); \$x = '<p>{{heading}}";

        $source = Widget_Compiler::compile($spec, 12);
        $this->assertIsString($source);
        $this->assertTrue(Generated_Code_Lint::check($source));
        // The dangerous text survives as data, so it would render, not run.
        $this->assertStringContainsString('system', $source);
        $this->assertSame(0, preg_match('/^\s*system\(/m', $source), 'system() must never be emitted in call position');
    }

    // ---- class naming: uniqueness -------------------------------------------

    public function test_class_name_includes_the_spec_id_so_same_titles_do_not_collide(): void
    {
        $a = Widget_Compiler::class_name_for(4, 'hero-box');
        $b = Widget_Compiler::class_name_for(5, 'hero-box');
        $this->assertIsString($a);
        $this->assertIsString($b);
        $this->assertNotSame(strtolower($a), strtolower($b), 'PHP class names are case-insensitive; ids must disambiguate');
        $this->assertStringContainsString('_4_', $a);
    }

    public function test_class_name_rejects_a_name_with_no_compilable_characters(): void
    {
        $this->assertInstanceOf(\WP_Error::class, Widget_Compiler::class_name_for(3, ''));
        $this->assertInstanceOf(\WP_Error::class, Widget_Compiler::class_name_for(3, '日本語'));
        $this->assertInstanceOf(\WP_Error::class, Widget_Compiler::class_name_for(0, 'hero'));
    }

    // ---- manifest: shape validation and containment -------------------------

    public function test_manifest_round_trips_a_valid_entry(): void
    {
        $entry = $this->entry(9);
        $this->assertTrue(Compiled_Widget_Manifest::put($entry));

        $read = Compiled_Widget_Manifest::read();
        $this->assertArrayHasKey(9, $read);
        $this->assertSame('widget-9.php', $read[9]['file']);
        $this->assertTrue($read[9]['enabled']);
    }

    /**
     * @dataProvider malformed_entries
     */
    public function test_manifest_rejects_malformed_entries(string $label, array $entry): void
    {
        $this->assertNull(Compiled_Widget_Manifest::validate_entry($entry['spec_id'] ?? 1, $entry), $label);
    }

    public function malformed_entries(): array
    {
        $base = [
            'spec_id' => 9,
            'name'    => 'promo-box',
            'class'   => 'WPMCP_Compiled_Widget_9_Promo_Box',
            'file'    => 'widget-9.php',
            'hash'    => str_repeat('a', 64),
            'enabled' => true,
        ];
        return [
            'traversal file'   => ['traversal file', array_merge($base, ['file' => '../../wp-config.php'])],
            'absolute file'    => ['absolute file', array_merge($base, ['file' => '/etc/passwd'])],
            'subdir file'      => ['subdir file', array_merge($base, ['file' => 'a/b.php'])],
            'empty file'       => ['empty file', array_merge($base, ['file' => ''])],
            'bad hash'         => ['bad hash', array_merge($base, ['hash' => 'nope'])],
            'bad class'        => ['bad class', array_merge($base, ['class' => 'Evil\\Class; //'])],
            'zero spec id'     => ['zero spec id', array_merge($base, ['spec_id' => 0])],
        ];
    }

    public function test_manifest_refuses_to_store_a_malformed_entry(): void
    {
        $bad = $this->entry(9);
        $bad['file'] = '../../wp-config.php';
        $out = Compiled_Widget_Manifest::put($bad);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame([], Compiled_Widget_Manifest::read());
    }

    /** A tampered option cannot make the loader require an arbitrary path. */
    public function test_read_drops_entries_written_around_the_api(): void
    {
        update_option(Compiled_Widget_Manifest::OPTION, [
            9 => ['spec_id' => 9, 'class' => 'X', 'file' => '../../../wp-config.php', 'hash' => str_repeat('a', 64), 'enabled' => true],
        ], false);
        $this->assertSame([], Compiled_Widget_Manifest::read());
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled());
    }

    private function entry(int $id): array
    {
        return [
            'spec_id'     => $id,
            'name'        => 'promo-box',
            'class'       => 'WPMCP_Compiled_Widget_' . $id . '_Promo_Box',
            'file'        => 'widget-' . $id . '.php',
            'hash'        => str_repeat('a', 64),
            'enabled'     => true,
            'compiled_at' => gmdate('c'),
        ];
    }

    // ---- the tool: gates, write path, history -------------------------------

    public function test_compiler_is_off_by_default(): void
    {
        remove_filter('wpmcp_enable_widget_compiler', '__return_true');
        $id = $this->create();

        $this->expectException(\RuntimeException::class);
        (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
    }

    public function test_compile_returns_wp_error_for_an_unknown_widget(): void
    {
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => 999999]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('widget_not_found', $out->get_error_code());
    }

    public function test_compile_refuses_a_draft_widget(): void
    {
        $id = $this->create();
        (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'draft']);

        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('widget_not_published', $out->get_error_code());
        $this->assertSame([], Compiled_Widget_Manifest::read(), 'a refused compile writes no manifest entry');
    }

    public function test_compile_honors_disallow_file_edit_semantics(): void
    {
        $gate = \WPMCP\Tools\Filesystem\Filesystem_Guard::check_writes(true, true);
        $this->assertInstanceOf(\WP_Error::class, $gate, 'DISALLOW_FILE_EDIT must block writes');

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertInstanceOf(\WP_Error::class, $out, 'a user without edit_files cannot compile PHP');
    }

    public function test_compile_writes_a_hash_verified_class_into_the_sandbox(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertTrue($out['compiled']);
        $this->assertNotEmpty($out['operation_id'], 'a compile is an operation in history');

        $path = Compiled_Widget_Manifest::path_for($out['file']);
        $this->assertFileExists($path);
        $this->assertSame($out['hash'], hash('sha256', (string) file_get_contents($path)));

        // The sandbox is hardened the way every other generated-file directory
        // in the plugin is, and it is NOT under uploads.
        $this->assertFileExists($this->sandbox . '/.htaccess');
        $this->assertFileExists($this->sandbox . '/index.php');
        $this->assertFileExists($this->sandbox . '/README.txt');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($this->sandbox . '/.htaccess'));

        $entry = Compiled_Widget_Manifest::get($id);
        $this->assertIsArray($entry);
        $this->assertSame($out['class'], $entry['class']);
        $this->assertTrue($entry['enabled']);
    }

    public function test_written_source_passes_the_lint_and_is_valid_php(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);

        $source = (string) file_get_contents(Compiled_Widget_Manifest::path_for($out['file']));
        $this->assertTrue(Generated_Code_Lint::check($source));
        $this->assertIsArray(token_get_all($source, TOKEN_PARSE));
    }

    public function test_loader_loads_only_enabled_hash_matching_files(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);

        $loaded = Compiled_Widget_Manifest::load_enabled();
        $this->assertArrayHasKey($id, $loaded);
        $this->assertSame($out['class'], $loaded[$id]);

        // Disabling removes it from the builder without deleting spec or file.
        $this->assertTrue(Compiled_Widget_Manifest::set_enabled($id, false));
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled());
        $this->assertFileExists(Compiled_Widget_Manifest::path_for($out['file']));
        $this->assertSame('wpmcp_widget', get_post_type($id));
    }

    public function test_tampered_file_is_not_loaded(): void
    {
        $spec         = $this->valid_spec();
        $spec['name'] = 'tamper-target';
        $id           = $this->create($spec);
        $out          = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);

        file_put_contents(
            Compiled_Widget_Manifest::path_for($out['file']),
            "<?php\n// swapped out from under the manifest\n"
        );

        $this->assertSame([], Compiled_Widget_Manifest::load_enabled(), 'a hash mismatch must not load');
    }

    public function test_set_widget_status_and_delete_flip_the_compiled_entry(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);

        (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'draft']);
        $this->assertFalse(Compiled_Widget_Manifest::get($id)['enabled']);

        (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'publish']);
        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled']);

        // Trashing the spec stops the class loading through POST STATUS, and
        // deliberately leaves the manifest flag alone: that is what makes the
        // generic restore-post ability a complete undo rather than a restore
        // that silently drops the widget onto the dynamic render path.
        (new Delete_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled(), 'a trashed spec must not load its class');
        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled'], 'the entry is kept enabled so restore-post is a complete undo');

        wp_untrash_post($id);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        $this->assertArrayHasKey($id, Compiled_Widget_Manifest::load_enabled(), 'restoring the spec brings the compiled class back');
    }

    // ---- the execution gate -------------------------------------------------

    /**
     * The gate that matters. Gating only the WRITE would mean a widget
     * compiled while the feature was on keeps being require()'d on every
     * editor and front-end render after the site turns the opt-in back off,
     * which makes "PRO-only and default-off" false for the execution site.
     */
    public function test_compiled_php_stops_executing_when_the_opt_in_is_turned_off(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertArrayHasKey($id, Compiled_Widget_Manifest::load_enabled());

        remove_filter('wpmcp_enable_widget_compiler', '__return_true');

        $this->assertFalse(Compiled_Widget_Manifest::execution_allowed());
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled(), 'the opt-in gates the require, not just the write');
        // The artifacts are untouched; only execution stopped.
        $this->assertFileExists(Compiled_Widget_Manifest::path_for($out['file']));
        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled']);
    }

    public function test_compiled_php_stops_executing_when_pro_lapses(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);

        Gate::set_pro_for_tests(false);
        $this->assertFalse(Compiled_Widget_Manifest::execution_allowed());
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled(), 'a lapsed licence must stop the require');
    }

    public function test_pro_gate_blocks_the_compile_tool(): void
    {
        $id = $this->create();
        Gate::set_pro_for_tests(false);

        $this->expectException(\RuntimeException::class);
        (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
    }

    public function test_disallow_file_edit_blocks_the_compile_tool(): void
    {
        $id = $this->create();
        add_filter('wpmcp_disallow_file_edit_for_tests', '__return_true');
        $blocked = \WPMCP\Tools\Filesystem\Filesystem_Guard::check_writes(true, true);
        remove_filter('wpmcp_disallow_file_edit_for_tests', '__return_true');
        $this->assertInstanceOf(\WP_Error::class, $blocked);

        // Route it through the handler with the capability removed, which is
        // the same gate Filesystem_Guard::writes_allowed() enforces.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame([], Compiled_Widget_Manifest::read(), 'a blocked compile writes no manifest entry');
        $this->assertFalse(is_file(Compiled_Widget_Manifest::path_for(Widget_Compiler::file_name_for($id))));
    }

    // ---- sandbox confinement ------------------------------------------------

    public function test_sandbox_dir_ignores_a_filter_pointing_outside_wp_content(): void
    {
        remove_all_filters('wpmcp_compiled_widgets_dir');
        add_filter('wpmcp_compiled_widgets_dir', static function () {
            return rtrim(sys_get_temp_dir(), '/') . '/wpmcp-escape-attempt';
        });

        $this->assertSame(
            trailingslashit(WP_CONTENT_DIR) . Compiled_Widget_Manifest::DIR_NAME,
            Compiled_Widget_Manifest::sandbox_dir(),
            'a filter must not be able to relocate generated PHP outside the install'
        );
    }

    // ---- the allowlist may not drift wider than the emitter -----------------

    public function test_allowed_calls_is_not_wider_than_what_the_emitter_produces(): void
    {
        $spec = $this->all_control_types_spec();
        $id   = $this->create($spec);

        $source = Widget_Compiler::compile(Widget_Spec::normalize($spec), $id);
        $this->assertIsString($source, is_wp_error($source) ? $source->get_error_message() : '');

        foreach (Generated_Code_Lint::ALLOWED_CALLS as $fn) {
            $this->assertStringContainsString(
                $fn . '(',
                $source,
                "{$fn}() is in the allowlist but the emitter never produces it; the allowlist has drifted wider than the emitter"
            );
        }
        foreach (Generated_Code_Lint::ALLOWED_METHODS as $method) {
            $this->assertStringContainsString('$this->' . $method . '(', $source);
        }
    }

    // ---- the two forms of one spec must not diverge -------------------------

    /**
     * The compiled render used $settings[$key] ?? '' while Widget_Renderer
     * falls back to the control's declared default, so a widget rendered
     * before anything was saved came out empty compiled and defaulted
     * dynamically. Same spec, same output, both ways.
     */
    public function test_compiled_render_matches_the_dynamic_renderer_when_settings_are_missing(): void
    {
        $spec = [
            'name'     => 'defaults-box',
            'title'    => 'Defaults Box',
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hello & welcome'],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => 'https://example.test/a b'],
            ],
            'template' => '<h2>{{heading}}</h2><a href="{{link}}">go</a>',
        ];
        $id     = $this->create($spec);
        $source = Widget_Compiler::compile(Widget_Spec::normalize($spec), $id);
        $this->assertIsString($source);

        foreach ($spec['controls'] as $control) {
            $this->assertStringContainsString(
                var_export($control['default'], true),
                $source,
                'the control default must be the fallback in the emitted render, as it is in Widget_Renderer'
            );
        }

        $dynamic = \WPMCP\Tools\WidgetBuilder\Widget_Renderer::render(Widget_Spec::normalize($spec), []);
        $this->assertStringContainsString(esc_html('Hello & welcome'), $dynamic);
        $this->assertStringContainsString(esc_html('Hello & welcome'), $this->render_compiled($source, $id));
        $this->assertSame($dynamic, $this->render_compiled($source, $id));
    }

    /**
     * Evaluate the emitted render body in isolation (no Elementor), which is
     * the only way to compare the two render paths byte for byte.
     */
    private function render_compiled(string $source, int $id): string
    {
        $start = strpos($source, 'protected function render()');
        $this->assertNotFalse($start);
        $body = substr($source, (int) strpos($source, '{', $start) + 1);
        $body = substr($body, 0, (int) strrpos($body, '}'));
        $body = substr($body, 0, (int) strrpos($body, '}'));
        $body = str_replace('$this->get_settings_for_display()', '[]', $body);

        ob_start();
        eval($body);
        return (string) ob_get_clean();
    }

    // ---- undo restores the widget, not just the manifest row ----------------

    public function test_capture_and_restore_put_back_bytes_and_hash_together(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);

        $path     = Compiled_Widget_Manifest::path_for($out['file']);
        $original = (string) file_get_contents($path);
        $snapshot = Compiled_Widget_Manifest::capture($id, $out['file']);

        // Simulate a recompile: new bytes on disk, new hash in the manifest.
        file_put_contents($path, "<?php\n// a later compile\n");
        $entry         = Compiled_Widget_Manifest::get($id);
        $entry['hash'] = hash('sha256', (string) file_get_contents($path));
        $this->assertTrue(Compiled_Widget_Manifest::put($entry));

        Compiled_Widget_Manifest::restore($snapshot);

        $this->assertSame($original, (string) file_get_contents($path), 'undo must restore the FILE, not only the option');
        $this->assertSame($out['hash'], Compiled_Widget_Manifest::get($id)['hash']);
        $this->assertArrayHasKey($id, Compiled_Widget_Manifest::load_enabled(), 'a restored widget must actually load again');
    }

    public function test_restore_touches_only_its_own_widget(): void
    {
        $a = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $a]));
        $snapshot = Compiled_Widget_Manifest::capture($a, Widget_Compiler::file_name_for($a));

        $other         = $this->valid_spec();
        $other['name'] = 'second-box';
        $b             = $this->create($other);
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $b]));

        Compiled_Widget_Manifest::restore($snapshot);

        $this->assertNotNull(Compiled_Widget_Manifest::get($b), 'undoing compile A must not revert compile B');
        $this->assertArrayHasKey($b, Compiled_Widget_Manifest::load_enabled());
    }

    public function test_a_first_compile_undoes_to_no_file_and_no_entry(): void
    {
        $id       = $this->create();
        $file     = Widget_Compiler::file_name_for($id);
        $snapshot = Compiled_Widget_Manifest::capture($id, $file);
        $this->assertNull($snapshot['entry']);
        $this->assertNull($snapshot['bytes']);

        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));
        Compiled_Widget_Manifest::restore($snapshot);

        $this->assertNull(Compiled_Widget_Manifest::get($id));
        $this->assertFalse(is_file(Compiled_Widget_Manifest::path_for($file)));
    }

    // ---- the spec store is the source of truth ------------------------------

    public function test_updating_a_spec_disables_its_stale_compiled_class(): void
    {
        $id  = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));

        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        $out              = (new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $spec]);

        $this->assertTrue($out['compiled_disabled'], 'an accepted update must not silently keep rendering the old template');
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled());
    }

    public function test_read_tools_surface_compiled_and_stale_state(): void
    {
        $id = $this->create();
        $this->assertFalse((new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget())->handle(['widget_id' => $id])['compiled']['compiled']);

        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));
        $fresh = (new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget())->handle(['widget_id' => $id])['compiled'];
        $this->assertTrue($fresh['compiled']);
        $this->assertTrue($fresh['loading']);
        $this->assertFalse($fresh['stale'], 'a freshly compiled widget is not stale');

        // Change the spec directly (bypassing the tool that disables the
        // class) so the compiled class really is stale AND still winning.
        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::update($id, $spec);

        $stale = (new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget())->handle(['widget_id' => $id])['compiled'];
        $this->assertTrue($stale['stale'], 'the stale compiled class is what actually renders; a read tool must say so');

        $listed = (new \WPMCP\Tools\WidgetBuilder\List_Custom_Widgets())->handle([]);
        $this->assertTrue($listed['widgets'][0]['compiled']['compiled']);
    }

    // ---- retention ----------------------------------------------------------

    public function test_permanently_deleting_a_spec_purges_the_generated_file(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);
        $path = Compiled_Widget_Manifest::path_for($out['file']);
        $this->assertFileExists($path);

        \WPMCP\Tools\WidgetBuilder\Widget_Registry::purge_on_delete($id);

        $this->assertNull(Compiled_Widget_Manifest::get($id), 'a permanently deleted spec leaves no manifest entry');
        $this->assertFalse(is_file($path), 'a permanently deleted spec leaves no generated PHP behind');
    }

    // ---- manifest hardening -------------------------------------------------

    public function test_manifest_rejects_a_class_name_not_tied_to_its_own_spec_id(): void
    {
        $entry          = $this->entry(41);
        $entry['class'] = 'WPMCP_Compiled_Widget_99_Promo_Box';
        $this->assertNull(Compiled_Widget_Manifest::validate_entry(41, $entry), 'a class bound to another spec id is not this entry');

        $entry['class'] = 'WP_User';
        $this->assertNull(Compiled_Widget_Manifest::validate_entry(41, $entry), 'a bare identifier could bind a spec to any declared class');
    }

    public function test_control_types_report_compilable_from_the_table(): void
    {
        $out = (new \WPMCP\Tools\WidgetBuilder\List_Control_Types())->handle([]);
        foreach ($out['control_types'] as $row) {
            $this->assertSame(
                in_array($row['escaper'], Generated_Code_Lint::ALLOWED_CALLS, true),
                $row['compilable'],
                "compilable for {$row['type']} must be derived, not hardcoded"
            );
        }
    }

    // ---- spec create/update/delete are operations in history ---------------

    public function test_spec_update_status_and_delete_are_operations_in_history(): void
    {
        $id = $this->create();

        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        $updated          = (new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $spec]);
        $this->assertNotEmpty($updated['operation_id'], 'update-custom-widget must be undoable');

        $status = (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'draft']);
        $this->assertNotEmpty($status['operation_id'], 'set-widget-status must be undoable');

        $deleted = (new Delete_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertNotEmpty($deleted['operation_id'], 'delete-custom-widget must be undoable');
        $this->assertNotSame($updated['operation_id'], $deleted['operation_id']);
    }

    /** Undoing an update must put the previous spec back on the post. */
    public function test_undoing_a_spec_update_restores_the_previous_spec(): void
    {
        $id       = $this->create();
        $original = \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::get($id);

        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        $out              = (new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $spec]);

        $row = \WPMCP\Safety\Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertIsArray($row, 'the update must have left a snapshot to restore from');

        \WPMCP\Safety\Rollback_Service::apply_snapshot($row['snapshot']);
        $this->assertSame(
            $original['template'],
            \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::get($id)['template']
        );
    }

    private function all_control_types_spec(): array
    {
        $controls = [];
        $template = '';
        foreach (array_keys(Widget_Spec::CONTROL_TYPES) as $type) {
            $controls[] = ['name' => $type . '_field', 'type' => $type, 'label' => ucfirst($type), 'default' => 'd'];
            $template  .= '<span>{{' . $type . '_field}}</span>';
        }
        return ['name' => 'every-type', 'title' => 'Every Type', 'controls' => $controls, 'template' => $template];
    }
}
