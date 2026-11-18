<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Astra Pro Custom Layouts (issue #656), as Astra Pro 4.13 stores them:
 * the astra-advanced-hook post type, the layout kind in
 * ast-advanced-hook-layout, the hooks it is placed on as
 * ast-advanced-hook-actions rows ({action, custom}, mirrored into the legacy
 * ast-advanced-hook-action and ast-custom-hook keys the way
 * Astra_Ext_Advanced_Hooks_Meta::sync_legacy_hook_meta() does), and the
 * display rules as ast-advanced-hook-location and -exclusion
 * ({rule: [...], specific: [...]}, Astra_Target_Rules_Fields'
 * get_format_rule_value() shape) plus ast-advanced-hook-users.
 *
 * A layout in Astra's code editor (editor_type code_editor) keeps its code
 * in ast-advanced-hook-php-code, which these ops never read or write.
 */
final class Theme_Layouts_Astra extends Theme_Layouts_Adapter
{
    use Theme_Layouts_Target_Rules;

    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public function family(): string
    {
        return 'astra';
    }

    public function product(): string
    {
        return 'Astra Pro';
    }

    public function plugin_file(): string
    {
        return 'astra-addon/astra-addon.php';
    }

    protected function loaded(): bool
    {
        return defined('ASTRA_EXT_VER');
    }

    public function post_type(): string
    {
        return 'astra-advanced-hook';
    }

    public function module_hint(): string
    {
        return 'Switch on Site Builder under Astra > Dashboard > Astra Pro Modules.';
    }

    public function types(): array
    {
        return [
            'header'   => [ 'label' => 'Header', 'writable' => true, 'hook' => false ],
            'footer'   => [ 'label' => 'Footer', 'writable' => true, 'hook' => false ],
            '404-page' => [ 'label' => '404 Page', 'writable' => true, 'hook' => false ],
            'hooks'    => [ 'label' => 'Hooks', 'writable' => true, 'hook' => true ],
            'content'  => [ 'label' => 'Inside Post/Page Content', 'writable' => false, 'hook' => false ],
            'template' => [ 'label' => 'Template', 'writable' => false, 'hook' => false ],
        ];
    }

    public function type_of(int $id): string
    {
        return (string) get_post_meta($id, 'ast-advanced-hook-layout', true);
    }

    protected function custom_marker(): string
    {
        return 'custom_hook';
    }

    protected function hook_catalog(string $type): array
    {
        if ('hooks' !== $type || ! class_exists('\Astra_Ext_Advanced_Hooks_Meta')) {
            return [];
        }
        if ([] === \Astra_Ext_Advanced_Hooks_Meta::$hooks && method_exists('\Astra_Ext_Advanced_Hooks_Meta', 'get_instance')) {
            \Astra_Ext_Advanced_Hooks_Meta::get_instance();
        }
        $out = [];
        foreach ((array) \Astra_Ext_Advanced_Hooks_Meta::$hooks as $group) {
            $hooks = [];
            foreach ((array) ($group['hooks'] ?? []) as $name => $hook) {
                $hooks[] = [ 'name' => (string) $name, 'label' => (string) ($hook['title'] ?? $name) ];
            }
            $out[] = [ 'group' => (string) ($group['title'] ?? ''), 'hooks' => $hooks ];
        }
        return $out;
    }

    public function placement(int $id): array
    {
        if ('hooks' !== $this->type_of($id)) {
            return [ 'hook' => null, 'custom_hook' => false, 'priority' => null ];
        }
        // Astra's own reader (get_hook_actions): the rows, else the legacy pair.
        $rows = get_post_meta($id, 'ast-advanced-hook-actions', true);
        $row  = is_array($rows) && isset($rows[0]['action'])
            ? $rows[0]
            : [ 'action' => get_post_meta($id, 'ast-advanced-hook-action', true), 'custom' => get_post_meta($id, 'ast-custom-hook', true) ];
        $custom   = 'custom_hook' === ($row['action'] ?? '');
        $hook     = $custom ? (string) ($row['custom'] ?? '') : (string) ($row['action'] ?? '');
        $priority = get_post_meta($id, 'ast-advanced-hook-priority', true);
        return [
            'hook'        => '' === $hook ? null : $hook,
            'custom_hook' => $custom,
            'priority'    => '' === $priority ? null : (int) $priority,
        ] + (is_array($rows) && count($rows) > 1 ? [ 'more_hooks' => array_slice($rows, 1) ] : []);
    }

    public function write_new(int $id, string $type): void
    {
        update_post_meta($id, 'ast-advanced-hook-layout', $type);
        update_post_meta($id, 'editor_type', 'wordpress_editor');
    }

    public function write_placement(int $id, string $type, string $hook, bool $custom, int $priority): void
    {
        $row = [ 'action' => $custom ? 'custom_hook' : $hook, 'custom' => $custom ? $hook : '' ];
        update_post_meta($id, 'ast-advanced-hook-actions', [ $row ]);
        update_post_meta($id, 'ast-advanced-hook-action', $row['action']);
        if ($custom) {
            update_post_meta($id, 'ast-custom-hook', $hook);
        } else {
            delete_post_meta($id, 'ast-custom-hook');
        }
        update_post_meta($id, 'ast-advanced-hook-priority', (string) $priority);
    }

    public function content_key(string $type, int $id = 0): string
    {
        if ($id > 0 && 'code_editor' === get_post_meta($id, 'editor_type', true)) {
            throw new Operation_Refused('code_layout', 'This Astra layout is a code snippet (code editor); its code is edited in wp-admin only.');
        }
        return 'post_content';
    }

    /** Astra's code editor runs its code as PHP. */
    public function runs_code(int $id): ?string
    {
        return 'code_editor' === get_post_meta($id, 'editor_type', true) ? 'php' : null;
    }

    public function location_keys(): array
    {
        return [ 'location', 'exclusion' ];
    }

    public function read_rules(int $id): array
    {
        $users = get_post_meta($id, 'ast-advanced-hook-users', true);
        return [
            'location'  => self::stored_target(get_post_meta($id, 'ast-advanced-hook-location', true)),
            'exclusion' => self::stored_target(get_post_meta($id, 'ast-advanced-hook-exclusion', true)),
            'users'     => is_array($users) ? array_values($users) : [],
        ];
    }

    public function normalize_rules(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            if ('location' === $key || 'exclusion' === $key) {
                $out[ $key ] = self::target($value, $key, 'list-astra-hooks');
            } elseif ('users' === $key) {
                $out['users'] = self::target_users($value);
            } else {
                throw self::bad_rules(sprintf('Astra display rules take location, exclusion and users, not "%s".', (string) $key));
            }
        }
        return $out;
    }

    public function write_rules(int $id, string $type, array $rules): void
    {
        if ('404-page' === $type) {
            // Astra forces a 404 layout onto the 404 page (target_rules_404_layout()).
            $rules['location'] = [ 'rule' => [ 'special-404' ], 'specific' => [] ];
        }
        foreach ([ 'location' => 'ast-advanced-hook-location', 'exclusion' => 'ast-advanced-hook-exclusion', 'users' => 'ast-advanced-hook-users' ] as $key => $meta) {
            if (array_key_exists($key, $rules)) {
                $value = $rules[ $key ];
                self::put_meta($id, $meta, 'users' === $key || [] !== $value['rule'] ? $value : []);
            }
        }
    }

    public function rule_vocabulary(): array
    {
        $out = [
            'format' => '{location: {rule: [...], specific: [...]}, exclusion: {same}, users: [...]}. ' . self::target_format(),
        ];
        if (class_exists('\Astra_Target_Rules_Fields')) {
            self::load_role_functions();
            self::load_screen_functions();
            $out['location_options'] = \Astra_Target_Rules_Fields::get_location_selections();
            $out['user_options']     = \Astra_Target_Rules_Fields::get_user_selections();
        }
        return $out;
    }

    public function extras(int $id): array
    {
        return [ 'enabled' => 'no' !== get_post_meta($id, 'ast-advanced-hook-enabled', true) ];
    }
}
