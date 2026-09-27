<?php

namespace WPMCP\Tests\Pro\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\WidgetBuilder\Create_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Set_Widget_Status;
use WPMCP\Tools\WidgetBuilder\Validate_Widget_Spec;
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
            // An allowlisted NAME is not an allowlisted FUNCTION: aliasing a
            // sink to an allowed name, or calling an allowed last segment in
            // another namespace, reaches a different function entirely.
            'use function alias'    => ['use function alias', '<?php use function system as esc_html; esc_html("id");'],
            'namespace shadow'      => ['namespace shadow', '<?php namespace Evil; esc_html("id");'],
            'qualified allowed name' => ['qualified allowed name', '<?php \\Evil\\esc_html("id");'],
            'relative allowed name' => ['relative allowed name', '<?php Evil\\esc_html("id");'],
            // Calling the RESULT of an expression: no identifier is in call
            // position at all, so a name allowlist never sees the callee.
            'string literal call'   => ['string literal call', '<?php "system"("id");'],
            'single quoted call'    => ['single quoted call', "<?php 'system'('id');"],
            'concatenated call'     => ['concatenated call', '<?php ("sys" . "tem")("id");'],
            'array element call'    => ['array element call', '<?php $a = ["system"]; $a[0]("id");'],
            'callable array call'   => ['callable array call', '<?php ["Evil", "run"]("id");'],
            'chained call result'   => ['chained call result', '<?php esc_html("system")("id");'],
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
            . "        \$value = \$settings['a'] ?? '';\n"
            . "        \$value = is_array(\$value) ? (\$value['url'] ?? '') : \$value;\n"
            . "        echo esc_html(is_scalar(\$value) ? (string) \$value : '');\n"
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

    // ---- validate-spec: malformed / hostile corpus, no side effects --------

    /**
     * Every spec here must be refused by validate-widget-spec with a clean
     * WP_Error-shaped answer (never a PHP warning, never "Array"), and the
     * refusal must have no side effects: no post, no option, no sandbox
     * directory, no snapshot. create-custom-widget must refuse it too, so a
     * hostile spec can never reach the store the compiler reads from.
     *
     * @dataProvider malformed_specs
     * @param mixed $spec
     */
    public function test_validate_spec_rejects_malformed_and_hostile_specs_without_side_effects(string $label, $spec): void
    {
        global $wpdb;
        $count = static function (string $table) use ($wpdb): int {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB
        };
        $posts   = $count($wpdb->posts);
        $options = $count($wpdb->options);

        $out = (new Validate_Widget_Spec())->handle(['spec' => $spec]);
        $this->assertFalse($out['valid'], "{$label}: validate-widget-spec accepted it");
        $this->assertNotSame('', (string) ($out['code'] ?? ''), "{$label}: refusal needs an error code");
        $this->assertStringNotContainsString('Array', (string) ($out['error'] ?? ''), "{$label}: an array leaked into the message");

        $created = (new Create_Custom_Widget())->handle(['spec' => $spec]);
        $this->assertInstanceOf(\WP_Error::class, $created, "{$label}: create-custom-widget stored it");

        $this->assertSame($posts, $count($wpdb->posts), "{$label}: a post was written");
        $this->assertSame($options, $count($wpdb->options), "{$label}: an option was written");
        $this->assertFalse(get_option(Compiled_Widget_Manifest::OPTION, false), "{$label}: the manifest was touched");
        $this->assertDirectoryDoesNotExist($this->sandbox, "{$label}: the sandbox was created");
    }

    public function malformed_specs(): array
    {
        $base = function (array $override = [], ?array $control = null): array {
            $spec = [
                'name'     => 'ok',
                'title'    => 'OK',
                'controls' => [$control ?? ['name' => 'a', 'type' => 'text', 'label' => 'A', 'default' => '']],
                'template' => '<p>{{a}}</p>',
            ];
            return array_merge($spec, $override);
        };
        $ctl = static function (array $override): array {
            return array_merge(['name' => 'a', 'type' => 'text', 'label' => 'A', 'default' => ''], $override);
        };
        $many = [];
        for ($i = 0; $i <= Widget_Spec::MAX_CONTROLS; $i++) {
            $many[] = ['name' => 'c' . $i, 'type' => 'text', 'label' => 'C'];
        }

        return [
            'spec is a string'                 => ['spec string', '<?php system("id");'],
            'empty spec'                       => ['empty', []],
            'title is an array'                => ['title array', $base(['title' => ['OK']])],
            'title is only whitespace'         => ['title blank', $base(['title' => "  \n "])],
            'title is too long'                => ['title long', $base(['title' => str_repeat('t', Widget_Spec::MAX_TEXT + 1)])],
            'name is an array'                 => ['name array', $base(['name' => ['ok']])],
            'icon is an array'                 => ['icon array', $base(['icon' => ['eicon-code']])],
            'icon breaks out of the attribute' => ['icon attr', $base(['icon' => 'eicon-code" onload="alert(1)'])],
            'keywords is a string'             => ['keywords string', $base(['keywords' => 'promo'])],
            'keyword is nested'                => ['keyword nested', $base(['keywords' => [['promo']]])],
            'too many keywords'                => ['keywords many', $base(['keywords' => array_fill(0, Widget_Spec::MAX_KEYWORDS + 1, 'k')])],
            'controls is a string'             => ['controls string', $base(['controls' => 'text'])],
            'controls is empty'                => ['controls empty', $base(['controls' => []])],
            'too many controls'                => ['controls many', $base(['controls' => $many])],
            'control is a string'              => ['control string', $base(['controls' => ['text']])],
            'control name is code'             => ['name code', $base([], $ctl(['name' => "a'];system('id');//"]))],
            'control name is an array'         => ['name arr', $base([], $ctl(['name' => ['a']]))],
            'control name is too long'         => ['name long', $base([], $ctl(['name' => str_repeat('a', Widget_Spec::MAX_NAME + 1)]))],
            'control names differ only by case' => ['name case', $base(['controls' => [$ctl(['name' => 'A']), $ctl(['name' => 'a'])]])],
            'control type is an array'         => ['type arr', $base([], $ctl(['type' => ['text']]))],
            'control type is raw html'         => ['type html', $base([], $ctl(['type' => 'html']))],
            'control type is eval'             => ['type eval', $base([], $ctl(['type' => 'eval']))],
            'control label is an array'        => ['label arr', $base([], $ctl(['label' => ['A']]))],
            'control label is too long'        => ['label long', $base([], $ctl(['label' => str_repeat('l', Widget_Spec::MAX_TEXT + 1)]))],
            'control default is an array'      => ['default arr', $base([], $ctl(['default' => ['url' => 'x']]))],
            'control default is too long'      => ['default long', $base([], $ctl(['default' => str_repeat('d', Widget_Spec::MAX_DEFAULT + 1)]))],
            'template is an array'             => ['template arr', $base(['template' => ['<p>{{a}}</p>']])],
            'template is only whitespace'      => ['template blank', $base(['template' => " \t\n"])],
            'template is too large'            => ['template big', $base(['template' => str_repeat('x', Widget_Spec::MAX_TEMPLATE + 1)])],
        ];
    }

    public function test_limits_leave_room_for_a_realistic_spec(): void
    {
        $spec = $this->valid_spec();
        $spec['keywords'] = array_fill(0, Widget_Spec::MAX_KEYWORDS, 'k');
        $spec['template'] = str_repeat('x', Widget_Spec::MAX_TEMPLATE - 20) . '{{heading}}';
        $this->assertTrue(Widget_Spec::validate($spec));
        $this->assertTrue((new Validate_Widget_Spec())->handle(['spec' => $spec])['valid']);
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
        $this->assertStringContainsString("\$value = \$settings['heading'] ?? 'Hi';", $source);
        $this->assertStringContainsString("\$value = \$settings['body'] ?? '';", $source);
        $this->assertStringContainsString("\$value = \$settings['link'] ?? '';", $source);
        $this->assertStringContainsString("echo esc_html(is_scalar(\$value) ? (string) \$value : '');", $source);
        $this->assertStringContainsString("echo wp_kses_post(is_scalar(\$value) ? (string) \$value : '');", $source);
        $this->assertStringContainsString("echo esc_url(is_scalar(\$value) ? (string) \$value : '');", $source);

        // Every echo is either a single string literal or a declared escaper
        // around the scalarized value; there is no third shape.
        $escapers = implode('|', array_unique(array_column(Widget_Spec::CONTROL_TYPES, 'escaper')));
        preg_match_all('/^\s*echo\s+(.*);$/m', $source, $echoes);
        $this->assertNotEmpty($echoes[1]);
        foreach ($echoes[1] as $expr) {
            $literal = 1 === preg_match("/^'(?:[^'\\\\]|\\\\.)*'$/s", $expr);
            $escaped = 1 === preg_match('/^(?:' . $escapers . ")\\(is_scalar\\(\\\$value\\) \\? \\(string\\) \\\$value : ''\\)$/", $expr);
            $this->assertTrue($literal || $escaped, "unexpected echo shape: {$expr}");
        }
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
        $this->assertInstanceOf(\WP_Error::class, Widget_Compiler::class_name_for(3, '___'), 'underscores alone leave no name segment');
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
     * Elementor returns URL, MEDIA and ICONS control values as arrays, and
     * Widget_Renderer reduces them to the documented member (link URL, image
     * URL, icon class or svg file URL) while blanking arrays under every
     * other type. The compiled class must do exactly the same, or a compiled
     * widget prints the literal "Array" (and raises a notice) wherever the
     * dynamic widget prints the URL.
     *
     * @dataProvider elementor_array_settings
     */
    public function test_compiled_render_unwraps_elementor_array_values_like_the_renderer(array $settings): void
    {
        $spec = [
            'name'     => 'array-box',
            'title'    => 'Array Box',
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => ''],
                ['name' => 'photo', 'type' => 'image', 'label' => 'Photo', 'default' => ''],
                ['name' => 'glyph', 'type' => 'icon', 'label' => 'Glyph', 'default' => ''],
            ],
            'template' => '<h3>{{heading}}</h3><a href="{{link}}"><img src="{{photo}}"><i class="{{glyph}}"></i></a>',
        ];
        $id     = $this->create($spec);
        $source = Widget_Compiler::compile(Widget_Spec::normalize($spec), $id);
        $this->assertIsString($source);
        $this->assertTrue(Generated_Code_Lint::check($source));

        $dynamic  = \WPMCP\Tools\WidgetBuilder\Widget_Renderer::render(Widget_Spec::normalize($spec), $settings);
        $compiled = $this->render_compiled($source, $id, $settings);

        $this->assertStringNotContainsString('Array', $compiled);
        $this->assertSame($dynamic, $compiled);
    }

    public function elementor_array_settings(): array
    {
        return [
            'url, media and font icon arrays' => [[
                'link'  => ['url' => 'https://example.com/go', 'is_external' => true, 'nofollow' => ''],
                'photo' => ['url' => 'https://example.com/cat.png', 'id' => 12],
                'glyph' => ['value' => 'fas fa-star', 'library' => 'fa-solid'],
            ]],
            'svg icon is one level deeper' => [[
                'glyph' => ['value' => ['url' => 'https://example.com/star.svg', 'id' => 7], 'library' => 'svg'],
            ]],
            'array under a text control is blanked, not promoted' => [[
                'heading' => ['url' => 'https://example.com/leak'],
            ]],
            'nested junk renders empty' => [[
                'heading' => ['unexpected' => ['deep']],
                'link'    => ['url' => ['nested']],
                'glyph'   => ['value' => ['url' => ['deeper']]],
            ]],
            'hostile scalar values are still escaped' => [[
                'heading' => '<script>alert(1)</script>',
                'link'    => 'javascript:alert(1)',
                'glyph'   => '" onmouseover="alert(1)',
            ]],
            'scalars of other types' => [[
                'heading' => 42,
                'link'    => null,
                'glyph'   => true,
            ]],
        ];
    }

    /**
     * Evaluate the emitted render body in isolation (no Elementor), which is
     * the only way to compare the two render paths byte for byte.
     */
    private function render_compiled(string $source, int $id, array $settings = []): string
    {
        $start = strpos($source, 'protected function render()');
        $this->assertNotFalse($start);
        $body = substr($source, (int) strpos($source, '{', $start) + 1);
        $body = substr($body, 0, (int) strrpos($body, '}'));
        $body = substr($body, 0, (int) strrpos($body, '}'));
        $body = str_replace('$this->get_settings_for_display()', var_export($settings, true), $body);

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

    /**
     * restore_session() dedups whole-object snapshots per object. Every
     * compile snapshot is an 'option' snapshot of one shared option, so an
     * option-name identity collapsed them all and only the oldest compile in
     * a session was undone while the rest were counted as restored.
     */
    public function test_restore_session_undoes_every_compile_in_the_session(): void
    {
        $session = 'compile-session-' . wp_generate_password(6, false);
        $a       = $this->create();
        $spec_b  = $this->valid_spec();
        $spec_b['name'] = 'second-box';
        $b = $this->create($spec_b);

        $out_a = (new Compile_Custom_Widget())->handle(['widget_id' => $a, 'session_id' => $session]);
        $out_b = (new Compile_Custom_Widget())->handle(['widget_id' => $b, 'session_id' => $session]);
        $this->assertIsArray($out_a);
        $this->assertIsArray($out_b);
        // A recompile of A in the same session: undoing the session must still
        // land on "never compiled", not on the first compile's bytes.
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $a, 'session_id' => $session]));

        \WPMCP\Safety\Rollback_Service::restore_session($session);

        $this->assertNull(Compiled_Widget_Manifest::get($a), 'compile A survived a session undo');
        $this->assertNull(Compiled_Widget_Manifest::get($b), 'compile B survived a session undo');
        $this->assertFileDoesNotExist(Compiled_Widget_Manifest::path_for($out_a['file']));
        $this->assertFileDoesNotExist(Compiled_Widget_Manifest::path_for($out_b['file']));
    }

    /**
     * A disable followed by a recompile in one session: unwinding must land on
     * the pre-session state (first compile, enabled), which needs every
     * manifest change undone newest first.
     */
    public function test_restore_session_unwinds_status_and_recompile_in_order(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);
        $path  = Compiled_Widget_Manifest::path_for($out['file']);
        $first = (string) file_get_contents($path);

        $session = 'status-session-' . wp_generate_password(6, false);
        $this->assertIsArray((new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'draft', 'session_id' => $session]));
        $this->assertIsArray((new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'publish', 'session_id' => $session]));
        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::update($id, $spec);
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id, 'session_id' => $session]));

        \WPMCP\Safety\Rollback_Service::restore_session($session);

        $this->assertSame($first, (string) file_get_contents($path));
        $this->assertSame($out['hash'], Compiled_Widget_Manifest::get($id)['hash']);
        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled']);
        $this->assertSame('publish', get_post_status($id));
    }

    /**
     * set-widget-status and update-custom-widget flip the manifest flag; the
     * operation's snapshot must carry the entry so undoing it restores the
     * compiled path, not just the post.
     */
    public function test_undoing_a_status_change_restores_the_compiled_flag(): void
    {
        $id = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));

        $off = (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'draft']);
        $this->assertFalse(Compiled_Widget_Manifest::get($id)['enabled']);

        \WPMCP\Safety\Rollback_Service::restore_operation($off['operation_id']);

        $this->assertSame('publish', get_post_status($id));
        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled'], 'undo republished the spec but left the compiled class off');
        $this->assertArrayHasKey($id, Compiled_Widget_Manifest::loadable());
    }

    public function test_undoing_a_spec_update_re_enables_the_compiled_class(): void
    {
        $id = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));

        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        $out              = (new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $spec]);
        $this->assertTrue($out['compiled_disabled']);

        \WPMCP\Safety\Rollback_Service::restore_operation($out['operation_id']);

        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled']);
        $this->assertArrayHasKey($id, Compiled_Widget_Manifest::loadable(), 'the previous spec and its compiled class are back together');
    }

    /** Reporting compiled_enabled=false must also be what the manifest holds. */
    public function test_enabling_while_the_opt_in_is_off_persists_disabled(): void
    {
        $id = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));

        remove_filter('wpmcp_enable_widget_compiler', '__return_true');
        $out = (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'publish']);
        $this->assertFalse($out['compiled_enabled']);
        $this->assertFalse(Compiled_Widget_Manifest::get($id)['enabled'], 'reported off but stored on');

        add_filter('wpmcp_enable_widget_compiler', '__return_true');
        $this->assertArrayNotHasKey($id, Compiled_Widget_Manifest::loadable(), 're-enabling the filter must not silently start executing it');
    }

    /**
     * The new bytes must not be on disk before the operation's snapshot is:
     * if anything in the operation throws, the previous good file comes back.
     */
    public function test_a_throw_inside_the_compile_operation_keeps_the_previous_file(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);
        $path     = Compiled_Widget_Manifest::path_for($out['file']);
        $previous = (string) file_get_contents($path);

        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::update($id, $spec);

        $boom = static function () {
            throw new \RuntimeException('manifest write exploded');
        };
        add_filter('pre_update_option_' . Compiled_Widget_Manifest::OPTION, $boom);
        try {
            (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
            $this->fail('the throw should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('manifest write exploded', $e->getMessage());
        } finally {
            remove_filter('pre_update_option_' . Compiled_Widget_Manifest::OPTION, $boom);
        }

        $this->assertSame($previous, (string) file_get_contents($path), 'the previous good bytes were lost');
        $this->assertArrayHasKey($id, Compiled_Widget_Manifest::loadable());
    }

    /** The manifest records the class the emitter actually wrote. */
    public function test_manifest_class_is_the_class_the_emitter_wrote(): void
    {
        $spec = $this->valid_spec();
        unset($spec['name']);
        $id  = $this->create($spec);
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);
        $source = (string) file_get_contents(Compiled_Widget_Manifest::path_for($out['file']));
        $this->assertStringContainsString('class ' . $out['class'] . ' extends', $source);
        $this->assertSame($out['class'], Compiled_Widget_Manifest::get($id)['class']);
    }

    /**
     * A failed manifest write must not be reported as a successful disable,
     * and the spec update must not land while the stale class keeps winning.
     */
    public function test_update_reports_a_failed_compiled_disable(): void
    {
        $id = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));
        $before = \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::get($id);

        // update_option() is refused: the filter hands back the old value.
        $refuse = static function ($value, $old) {
            return $old;
        };
        add_filter('pre_update_option_' . Compiled_Widget_Manifest::OPTION, $refuse, 10, 2);
        $this->assertInstanceOf(\WP_Error::class, Compiled_Widget_Manifest::set_enabled($id, false), 'a refused write must surface');

        $spec             = $this->valid_spec();
        $spec['template'] = '<section>{{heading}} v2</section>';
        $out              = (new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $spec]);
        remove_filter('pre_update_option_' . Compiled_Widget_Manifest::OPTION, $refuse, 10);

        $this->assertInstanceOf(\WP_Error::class, $out, 'reported compiled_disabled while the stale class kept rendering');
        $this->assertTrue(Compiled_Widget_Manifest::get($id)['enabled']);
        $this->assertSame($before['template'], \WPMCP\Tools\WidgetBuilder\Widget_Spec_Store::get($id)['template'], 'the spec changed although the stale class still wins');
    }

    /** Duplicate control names would register the same Elementor control id twice. */
    public function test_is_renderable_refuses_duplicate_control_names(): void
    {
        $spec = [
            'title'    => 'Dup',
            'controls' => [
                ['name' => 'Heading', 'type' => 'text', 'label' => 'A'],
                ['name' => 'heading', 'type' => 'text', 'label' => 'B'],
            ],
            'template' => '<p>{{heading}}</p>',
        ];
        $this->assertFalse(Widget_Spec::is_renderable($spec));
    }

    /**
     * A widget stored under the older rules can still be updated, and when
     * the update itself breaks a rule the error names the field to fix.
     */
    public function test_update_of_a_legacy_spec_names_the_failing_field(): void
    {
        $id     = $this->create();
        $legacy = [
            'name'     => 'promo-box',
            'title'    => 'Promo Box',
            'controls' => [['name' => 'My Heading', 'type' => 'text', 'label' => 'H']],
            'template' => '<h2>{{myheading}}</h2>',
        ];
        update_post_meta($id, '_wpmcp_widget_spec', $legacy);

        $err = (new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $legacy]);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $data = (array) $err->get_error_data();
        $this->assertSame('controls[0].name', $data['field'] ?? null);
        $this->assertStringContainsString('controls[0].name', $err->get_error_message());

        $fixed                        = $legacy;
        $fixed['controls'][0]['name'] = 'my_heading';
        $fixed['template']            = '<h2>{{my_heading}}</h2>';
        $this->assertIsArray((new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget())->handle(['widget_id' => $id, 'spec' => $fixed]));
    }

    /**
     * list-custom-widgets computes loadability once and hands it to every
     * row; status_for() must use what it is given rather than re-hashing
     * every compiled file per row.
     */
    public function test_status_for_uses_a_precomputed_loadable_set(): void
    {
        $id = $this->create();
        $this->assertIsArray((new Compile_Custom_Widget())->handle(['widget_id' => $id]));

        $this->assertTrue(Compiled_Widget_Manifest::status_for($id)['loading']);
        $this->assertFalse(Compiled_Widget_Manifest::status_for($id, null, [])['loading'], 'the precomputed set was ignored');

        $listed = (new \WPMCP\Tools\WidgetBuilder\List_Custom_Widgets())->handle([]);
        $this->assertTrue($listed['widgets'][0]['compiled']['loading']);
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

    /**
     * get-custom-widget and list-custom-widgets are read tools. Reporting
     * whether a compiled class would load must not require() it: a read must
     * never execute generated PHP, and list would otherwise re-hash and
     * re-require every file once per row.
     */
    public function test_read_tools_report_loading_without_executing_generated_php(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);
        $this->assertFalse(class_exists($out['class'], false), 'compiling must not load the class');

        $got = (new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget())->handle(['widget_id' => $id])['compiled'];
        $this->assertTrue($got['loading'], 'the class would load: gates open, enabled, published, hash matches');
        (new \WPMCP\Tools\WidgetBuilder\List_Custom_Widgets())->handle([]);
        $this->assertFalse(class_exists($out['class'], false), 'a read tool required generated PHP');

        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        $this->assertFalse(
            (new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget())->handle(['widget_id' => $id])['compiled']['loading'],
            'a draft spec does not load, and the read tool must say so'
        );
    }

    /**
     * Stricter write-time validation must not unregister widgets that were
     * stored before it existed. A legacy spec whose control name only became
     * valid through sanitize_key() keeps rendering through the dynamic widget.
     */
    public function test_legacy_stored_specs_stay_renderable(): void
    {
        $legacy = [
            'name'     => 'legacy',
            'title'    => 'Legacy',
            'controls' => [['name' => 'My Heading', 'type' => 'text', 'label' => 'H', 'default' => ['odd']]],
            'template' => '<h2>{{myheading}}</h2>',
        ];
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($legacy), 'new writes are held to the strict rules');
        $this->assertTrue(Widget_Spec::is_renderable($legacy), 'an already-stored spec keeps registering');
        $this->assertFalse(Widget_Spec::is_renderable(['title' => ['x'], 'controls' => 'no', 'template' => []]));
        $this->assertFalse(Widget_Spec::is_renderable(['title' => 'T', 'controls' => [['name' => 'a', 'type' => 'eval', 'label' => 'A']], 'template' => 'x']));
    }

    /**
     * An undo writes PHP back into the sandbox, so the bytes it writes are
     * held to the same pre-write lint as a compile. A snapshot payload that
     * does not pass (tampered history, or anything not produced by the
     * emitter) is not written; the entry still comes back, and because the
     * bytes on disk no longer hash to it, the widget is inert, not executing.
     */
    public function test_restore_refuses_bytes_that_fail_the_pre_write_lint(): void
    {
        $id  = $this->create();
        $out = (new Compile_Custom_Widget())->handle(['widget_id' => $id]);
        $this->assertIsArray($out);
        $path  = Compiled_Widget_Manifest::path_for($out['file']);
        $good  = (string) file_get_contents($path);
        $entry = Compiled_Widget_Manifest::get($id);

        $evil  = "<?php\nsystem('id');\n";
        $entry['hash'] = hash('sha256', $evil);
        Compiled_Widget_Manifest::restore(['spec_id' => $id, 'entry' => $entry, 'file' => $out['file'], 'bytes' => $evil]);

        $this->assertSame($good, (string) file_get_contents($path), 'restore wrote bytes that fail the lint');
        $this->assertSame([], Compiled_Widget_Manifest::load_enabled(), 'the vouched hash matches nothing on disk, so nothing loads');
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
