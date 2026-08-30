<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

use WPMCP\Tools\WidgetBuilder\Widget_Spec;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The sole PHP emitter for spec-compiled widgets (issue #72). The agent never
 * writes PHP: it submits a data spec (already validated by Widget_Spec), and
 * this class compiles that spec into a real Elementor widget class.
 *
 * Two properties make the emission safe by construction rather than by review:
 *
 *  - Every literal that comes from the spec (title, icon, keywords, control
 *    names/labels/defaults, and each template chunk) is emitted through
 *    var_export(), so spec text is always a PHP string literal and can never
 *    become syntax. A template containing "'; system('id'); //" compiles to a
 *    string that prints those characters.
 *  - Every interpolated value is wrapped in the escaper its control type
 *    declares in Widget_Spec::CONTROL_TYPES, the same table Widget_Renderer
 *    reads, so a spec escapes identically whether it is rendered at runtime or
 *    compiled. There is no raw output path: a placeholder with no matching
 *    control emits nothing at all.
 *
 * The emitted source still has to pass Generated_Code_Lint before a byte
 * reaches disk (see Compile_Custom_Widget).
 */
class Widget_Compiler
{
    /** Placeholder syntax, identical to Widget_Renderer's. */
    private const PLACEHOLDER = '/\{\{\s*([a-z0-9_\-]+)\s*\}\}/i';

    /**
     * Compile a validated spec to PHP source (in memory; the caller is
     * responsible for the lint + sandbox write + manifest entry).
     *
     * @param array $spec    a spec that already passed Widget_Spec::validate().
     * @param int   $spec_id the wpmcp_widget post id the spec is stored under.
     * @return string|\WP_Error the full PHP source of the widget class.
     */
    public static function compile(array $spec, int $spec_id)
    {
        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if ($spec_id <= 0) {
            return new \WP_Error('wpmcp_compiler_invalid_target', 'Compiling needs the spec post id it is stored under.');
        }

        $spec  = Widget_Spec::normalize($spec);
        $class = self::class_name_for($spec_id, (string) $spec['name']);
        if (is_wp_error($class)) {
            return $class;
        }

        $controls = [];
        foreach ((array) ($spec['controls'] ?? []) as $control) {
            if (! is_array($control)) {
                continue;
            }
            $name = sanitize_key((string) ($control['name'] ?? ''));
            $type = (string) ($control['type'] ?? '');
            if ('' === $name || ! isset(Widget_Spec::CONTROL_TYPES[$type])) {
                // Unreachable after validate(); belt and braces so an
                // unknown type can never fall through to a raw echo.
                return new \WP_Error(
                    'wpmcp_compiler_unsupported_control',
                    sprintf('Control type "%s" cannot be compiled.', $type)
                );
            }
            $controls[$name] = [
                'type'    => $type,
                'label'   => (string) ($control['label'] ?? $name),
                'default' => is_scalar($control['default'] ?? null) ? (string) $control['default'] : '',
                'escaper' => Widget_Spec::escaper_for($type),
            ];
        }

        return self::emit($class, $spec_id, $spec, $controls);
    }

    /**
     * The class a spec compiles to. The post id is part of the name because
     * nothing makes a spec's machine name unique (Widget_Spec::normalize()
     * just sanitize_title()s the title, and PHP class names are
     * case-insensitive on top of that), so two widgets both titled "Hero Box"
     * would otherwise compile to the same class, overwrite each other's file,
     * and fatal on redeclare if both loaded.
     *
     * @return string|\WP_Error
     */
    public static function class_name_for(int $spec_id, string $name)
    {
        if ($spec_id <= 0) {
            return new \WP_Error('wpmcp_compiler_invalid_target', 'A compiled widget needs a positive spec id.');
        }
        $slug = sanitize_key(str_replace('-', '_', $name));
        if ('' === $slug) {
            // An empty or non-ascii machine name would collapse to the bare
            // prefix and collide with every other such spec.
            return new \WP_Error(
                'wpmcp_compiler_invalid_target',
                'The widget name has no compilable characters; rename it to something ascii before compiling.'
            );
        }
        $parts = array_map('ucfirst', array_filter(explode('_', $slug), 'strlen'));
        return 'WPMCP_Compiled_Widget_' . $spec_id . '_' . implode('_', $parts);
    }

    /** The sandbox filename a spec compiles to (a plain basename, never a path). */
    public static function file_name_for(int $spec_id): string
    {
        return 'widget-' . $spec_id . '.php';
    }

    /**
     * @param array<string,array{type:string,label:string,default:string,escaper:string}> $controls
     */
    private static function emit(string $class, int $spec_id, array $spec, array $controls): string
    {
        $title    = (string) ($spec['title'] ?? '');
        $name     = (string) ($spec['name'] ?? '');
        $icon     = (string) ($spec['icon'] ?? 'eicon-code');
        $keywords = array_values(array_map('strval', (array) ($spec['keywords'] ?? [])));

        $out  = "<?php\n\n";
        $out .= "/**\n";
        $out .= ' * Generated by WP MCP from custom widget spec #' . $spec_id . ".\n";
        $out .= " * Do not edit by hand: this file is rewritten by compile-custom-widget,\n";
        $out .= " * and is loaded only when its hash matches the compiled-widget manifest.\n";
        $out .= " */\n\n";
        $out .= "if (! defined('ABSPATH')) {\n    exit;\n}\n\n";
        $out .= "if (! class_exists('Elementor\\\\Widget_Base')) {\n    return;\n}\n\n";
        $out .= 'if (class_exists(' . self::lit($class) . ", false)) {\n    return;\n}\n\n";

        $out .= 'class ' . $class . " extends \\Elementor\\Widget_Base\n{\n";
        $out .= self::method('get_name', 'public', '        return ' . self::lit($name) . ";\n");
        $out .= self::method('get_title', 'public', '        return ' . self::lit($title) . ";\n");
        $out .= self::method('get_icon', 'public', '        return ' . self::lit($icon) . ";\n");
        $out .= self::method('get_categories', 'public', "        return ['general'];\n");
        $out .= self::method('get_keywords', 'public', '        return ' . self::lit_list($keywords) . ";\n");
        $out .= self::method('register_controls', 'protected', self::controls_body($title, $controls));
        $out .= self::method('render', 'protected', self::render_body((string) ($spec['template'] ?? ''), $controls));
        $out .= "}\n";

        return $out;
    }

    private static function method(string $name, string $visibility, string $body): string
    {
        return "    {$visibility} function {$name}()\n    {\n" . $body . "    }\n\n";
    }

    /** @param array<string,array{type:string,label:string,default:string,escaper:string}> $controls */
    private static function controls_body(string $title, array $controls): string
    {
        $body = '        $this->start_controls_section(\'wpmcp_content\', [\'label\' => ' . self::lit($title) . "]);\n";
        foreach ($controls as $name => $control) {
            $body .= '        $this->add_control(' . self::lit($name) . ", [\n";
            $body .= '            \'label\' => ' . self::lit($control['label']) . ",\n";
            $body .= '            \'type\' => ' . self::lit(Widget_Spec::CONTROL_TYPES[$control['type']]['elementor']) . ",\n";
            $body .= '            \'default\' => ' . self::lit($control['default']) . ",\n";
            $body .= "        ]);\n";
        }
        $body .= "        \$this->end_controls_section();\n";
        return $body;
    }

    /**
     * The render body: literal template chunks echoed as string literals,
     * placeholders echoed through their control's declared escaper. A
     * placeholder with no matching control echoes nothing (same as the
     * runtime renderer), so an unknown name can never reach output raw.
     *
     * @param array<string,array{type:string,label:string,default:string,escaper:string}> $controls
     */
    private static function render_body(string $template, array $controls): string
    {
        $body  = "        \$settings = \$this->get_settings_for_display();\n";
        $body .= "        \$settings = is_array(\$settings) ? \$settings : [];\n";

        $parts = preg_split(self::PLACEHOLDER, $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            $parts = [$template];
        }

        foreach ($parts as $i => $part) {
            if (0 === $i % 2) {
                if ('' !== $part) {
                    $body .= '        echo ' . self::lit($part) . ";\n";
                }
                continue;
            }
            $key = sanitize_key($part);
            if (! isset($controls[$key])) {
                continue;
            }
            $escaper = $controls[$key]['escaper'];
            $body   .= '        echo ' . $escaper . '((string) (' . self::settings_read($key) . "));\n";
        }

        return $body;
    }

    private static function settings_read(string $key): string
    {
        return '$settings[' . self::lit($key) . '] ?? \'\'';
    }

    /**
     * Emit a value as a PHP string literal. var_export() is the whole safety
     * story for spec-supplied text: whatever the string contains (quotes,
     * backslashes, "<?php", "?>", newlines, NUL) it comes back as a literal,
     * never as syntax.
     */
    private static function lit(string $value): string
    {
        return var_export($value, true);
    }

    /** @param array<int,string> $values */
    private static function lit_list(array $values): string
    {
        if ([] === $values) {
            return '[]';
        }
        return '[' . implode(', ', array_map([self::class, 'lit'], $values)) . ']';
    }
}
