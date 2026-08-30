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

        $this->sandbox = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-widget-sandbox-' . wp_generate_password(8, false);
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

        $this->assertStringContainsString("echo esc_html((string) (\$settings['heading'] ?? ''));", $source);
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

        (new Delete_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertFalse(Compiled_Widget_Manifest::get($id)['enabled'], 'deleting the spec disables the class');
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled());
    }
}
