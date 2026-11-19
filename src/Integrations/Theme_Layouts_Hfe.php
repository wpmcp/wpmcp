<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ultimate Addons for Elementor templates (issue #651), as
 * header-footer-elementor 2.9.8 stores them (HFE_Admin::save_template_meta()):
 * the elementor-hf post type, the template kind in ehf_template_type
 * (type_header, type_before_footer, type_footer or custom for a Custom Block
 * placed with the [hfe_template id='<id>'] shortcode), the display rules as
 * ehf_target_include_locations and ehf_target_exclude_locations in the
 * target rules library's {rule, specific} shape (the plugin's own copy of
 * Astra_Target_Rules_Fields, HFE\Lib) plus ehf_target_user_roles, and the
 * Elementor Canvas switch as display-on-canvas-template ('1', or no key).
 *
 * The plugin is not a theme add-on, so its family is there whatever the
 * active theme. Its templates are built in Elementor: their content is
 * Elementor data, which these ops leave to the Elementor tools. The plugin
 * gives the post type manage_options for every capability, so the ops take
 * manage_options too. Presence is HFE_VER or UAEL_VER, filterable through
 * wpmcp_ultimate_addons_active like the suite's widget ops.
 */
final class Theme_Layouts_Hfe extends Theme_Layouts_Adapter
{
    use Theme_Layouts_Target_Rules;

    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    private const META = [
        'location'  => 'ehf_target_include_locations',
        'exclusion' => 'ehf_target_exclude_locations',
        'users'     => 'ehf_target_user_roles',
    ];

    private const CANVAS = 'display-on-canvas-template';

    public function family(): string
    {
        return 'ultimate-addons';
    }

    public function product(): string
    {
        return 'Ultimate Addons for Elementor (Header Footer Elementor)';
    }

    public function plugin_file(): string
    {
        return 'header-footer-elementor/header-footer-elementor.php';
    }

    protected function loaded(): bool
    {
        return defined('HFE_VER') || defined('UAEL_VER');
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_ultimate_addons_active', $this->loaded());
    }

    public function capability(): string
    {
        return 'manage_options';
    }

    public function deletable(): bool
    {
        return true;
    }

    public function post_type(): string
    {
        return 'elementor-hf';
    }

    public function module_hint(): string
    {
        return 'The plugin registers its templates post type (elementor-hf) on init; deactivate and reactivate it under Plugins.';
    }

    public function types(): array
    {
        return [
            'type_header'        => [ 'label' => 'Header', 'writable' => true, 'hook' => false ],
            'type_before_footer' => [ 'label' => 'Before Footer', 'writable' => true, 'hook' => false ],
            'type_footer'        => [ 'label' => 'Footer', 'writable' => true, 'hook' => false ],
            'custom'             => [ 'label' => 'Custom Block', 'writable' => true, 'hook' => false ],
        ];
    }

    public function type_of(int $id): string
    {
        return (string) get_post_meta($id, 'ehf_template_type', true);
    }

    protected function hook_catalog(string $type): array
    {
        return [];
    }

    public function placement(int $id): array
    {
        return [ 'hook' => null, 'custom_hook' => false, 'priority' => null ];
    }

    public function write_new(int $id, string $type): void
    {
        update_post_meta($id, 'ehf_template_type', $type);
    }

    public function write_placement(int $id, string $type, string $hook, bool $custom, int $priority): void
    {
        // No template type is placed on a hook; plan_placement() refuses one first.
    }

    public function content_key(string $type, int $id = 0): string
    {
        $where = $id > 0 ? sprintf(' (post id %d)', $id) : ' once the template exists';
        throw new Operation_Refused('elementor_content', 'Ultimate Addons templates are built with Elementor, so their content is Elementor data: read and edit it with the Elementor tools (get-elementor-data, add-widget, update-element) on the template' . $where . '.');
    }

    public function runs_code(int $id): ?string
    {
        return null;
    }

    public function location_keys(): array
    {
        return [ 'location', 'exclusion' ];
    }

    public function read_rules(int $id): array
    {
        $users = get_post_meta($id, self::META['users'], true);
        return [
            'location'  => self::stored_target(get_post_meta($id, self::META['location'], true)),
            'exclusion' => self::stored_target(get_post_meta($id, self::META['exclusion'], true)),
            'users'     => is_array($users) ? array_values($users) : [],
            'canvas'    => '' !== (string) get_post_meta($id, self::CANVAS, true),
        ];
    }

    public function normalize_rules(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            if ('location' === $key || 'exclusion' === $key) {
                $out[ $key ] = self::target($value, $key, 'list-ultimate-addons-hooks');
            } elseif ('users' === $key) {
                $out['users'] = self::target_users($value);
            } elseif ('canvas' === $key) {
                if (! is_bool($value)) {
                    throw self::bad_rules('"canvas" is true (also show on Elementor Canvas pages) or false.', $value);
                }
                $out['canvas'] = $value;
            } else {
                throw self::bad_rules(sprintf('Ultimate Addons display rules take location, exclusion, users and canvas, not "%s".', (string) $key));
            }
        }
        return $out;
    }

    public function write_rules(int $id, string $type, array $rules): void
    {
        foreach (self::META as $key => $meta) {
            if (array_key_exists($key, $rules)) {
                $value = $rules[ $key ];
                self::put_meta($id, $meta, 'users' === $key || [] !== $value['rule'] ? $value : []);
            }
        }
        if (array_key_exists('canvas', $rules)) {
            self::put_meta($id, self::CANVAS, $rules['canvas'] ? '1' : '');
        }
    }

    public function rule_vocabulary(): array
    {
        $out = [
            'format' => '{location: {rule: [...], specific: [...]}, exclusion: {same}, users: [...], canvas: bool (also show on Elementor Canvas pages)}. ' . self::target_format(),
        ];
        $fields = '\\HFE\\Lib\\Astra_Target_Rules_Fields';
        if (class_exists($fields)) {
            self::load_role_functions();
            self::load_screen_functions();
            $out['location_options'] = $fields::get_location_selections();
            $out['user_options']     = $fields::get_user_selections();
        }
        return $out;
    }

    public function extras(int $id): array
    {
        return 'custom' === $this->type_of($id) ? [ 'shortcode' => sprintf("[hfe_template id='%d']", $id) ] : [];
    }
}
