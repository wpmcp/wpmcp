<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One theme add-on's custom layouts (issue #656): Astra Pro's Custom
 * Layouts, Kadence Pro's Elements or GP Premium's Elements. Each is a post
 * type whose placement (hook, priority) and display rules live in post meta
 * the add-on reads on every front-end request. Theme_Layouts_Pack builds the
 * ops; an adapter only knows its add-on's storage, read and written exactly
 * as the add-on's own save routine writes it.
 */
abstract class Theme_Layouts_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Theme family slug, as Theme_Integration detects it (astra, kadence, generatepress). */
    abstract public function family(): string;

    /** The add-on's product name, for refusals. */
    abstract public function product(): string;

    /** The add-on's main plugin file, relative to the plugins directory. */
    abstract public function plugin_file(): string;

    /** The add-on's own version constant is defined. */
    abstract protected function loaded(): bool;

    abstract public function post_type(): string;

    /** Where a site switches the layouts module of the add-on on. */
    abstract public function module_hint(): string;

    /**
     * Layout types: type => ['label', 'writable' => bool, 'hook' => whether
     * the type is placed on an action hook].
     *
     * @return array<string,array{label:string,writable:bool,hook:bool}>
     */
    abstract public function types(): array;

    abstract public function type_of(int $id): string;

    /**
     * The add-on's hook catalog for one type, in groups. The add-on's own
     * custom-hook entry is left in; the pack strips it.
     *
     * @return array<int,array{group:string,hooks:array<int,array{name:string,label:string}>}>
     */
    abstract protected function hook_catalog(string $type): array;

    /** The catalog entry an add-on lists for "custom hook", if any. */
    protected function custom_marker(): string
    {
        return 'custom';
    }

    /** @return array{hook:?string,custom_hook:bool,priority:?int} */
    abstract public function placement(int $id): array;

    /** Meta a new layout of $type gets before anything else is written. */
    abstract public function write_new(int $id, string $type): void;

    abstract public function write_placement(int $id, string $type, string $hook, bool $custom, int $priority): void;

    /**
     * Where a layout's content lives: 'post_content' or a meta key. Throws
     * when the content is code (PHP or raw script), which these ops never
     * read or write, or when the type holds no content.
     *
     * @throws Operation_Refused
     */
    abstract public function content_key(string $type, int $id = 0): string;

    /** @return array<string,mixed> the stored rules, in the shape the ops take them. */
    abstract public function read_rules(int $id): array;

    /**
     * Validate rules and return them in stored shape. Only the keys passed
     * are returned, so a write leaves the others as they are.
     *
     * @param array<string,mixed> $rules
     * @return array<string,mixed>
     * @throws Operation_Refused
     */
    abstract public function normalize_rules(array $rules): array;

    /** @param array<string,mixed> $rules normalized */
    abstract public function write_rules(int $id, string $type, array $rules): void;

    /**
     * How rules are written, plus the add-on's own option lists when it is
     * loaded.
     *
     * @return array<string,mixed>
     */
    abstract public function rule_vocabulary(): array;

    /** @return array<string,mixed> family-specific fields of a layout view. */
    public function extras(int $id): array
    {
        return [];
    }

    /** Whether the add-on is loaded, filterable through wpmcp_theme_pro_active. */
    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_theme_pro_active', $this->loaded(), $this->family());
    }

    /** @return array<int,array{group:string,hooks:array<int,array{name:string,label:string}>}> */
    public function hook_groups(string $type): array
    {
        $out = [];
        foreach ($this->hook_catalog($type) as $group) {
            $hooks = array_values(array_filter($group['hooks'], fn (array $hook): bool => $hook['name'] !== $this->custom_marker()));
            if ([] !== $hooks) {
                $out[] = [ 'group' => $group['group'], 'hooks' => $hooks ];
            }
        }
        return $out;
    }

    /** @return string[] */
    public function hook_names(string $type): array
    {
        $names = [];
        foreach ($this->hook_groups($type) as $group) {
            foreach ($group['hooks'] as $hook) {
                $names[] = $hook['name'];
            }
        }
        return $names;
    }

    /** @param mixed $value */
    protected static function bad_rules(string $message, $value = null): Operation_Refused
    {
        return new Operation_Refused('invalid_display_rules', $message, null === $value ? [] : [ 'value' => $value ]);
    }

    /**
     * A list argument, or a refusal naming the key.
     *
     * @param mixed $value
     * @return array<int,mixed>
     */
    protected static function list_of($value, string $key): array
    {
        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            throw self::bad_rules(sprintf('Display rules key "%s" must be a list.', $key));
        }
        return $value;
    }

    protected static function role_exists(string $role): bool
    {
        return wp_roles()->is_role($role);
    }

    /** A post type key a rule names exists on this site. */
    protected static function check_post_type(string $type, string $rule): void
    {
        if (! post_type_exists($type)) {
            throw self::bad_rules(sprintf('Display rule "%s" names post type "%s", which this site does not have.', $rule, $type));
        }
    }

    protected static function check_taxonomy(string $taxonomy, string $rule): void
    {
        if (! taxonomy_exists($taxonomy)) {
            throw self::bad_rules(sprintf('Display rule "%s" names taxonomy "%s", which this site does not have.', $rule, $taxonomy));
        }
    }

    /** Store a meta value, or delete the key for an empty one, as the add-ons do. */
    protected static function put_meta(int $id, string $key, $value): void
    {
        if ('' === $value || [] === $value || null === $value) {
            delete_post_meta($id, $key);
            return;
        }
        update_post_meta($id, $key, wp_slash($value));
    }

    /** The admin user functions the add-ons' role lists call. */
    protected static function load_role_functions(): void
    {
        if (! function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
    }
}
