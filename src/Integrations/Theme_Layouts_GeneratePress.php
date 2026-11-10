<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * GP Premium Elements (issue #656), as GP Premium 2.5 stores them: the
 * gp_elements post type, the element kind in _generate_element_type (hook,
 * header, layout, block; a block element's kind in _generate_block_type),
 * the hook in _generate_hook (custom plus _generate_custom_hook) with
 * _generate_hook_priority (a string, '0' kept as GP keeps it), and the
 * display rules as _generate_element_display_conditions and
 * _generate_element_exclude_conditions (lists of {rule, object}) plus
 * _generate_element_user_conditions, exactly as
 * GeneratePress_Elements_Metabox::save() writes them.
 *
 * Types are named as these ops show them: hook, header, layout, and
 * block/<block type> (block/site-header, block/hook, ...). A classic hook
 * element keeps its content in _generate_element_content; a block element
 * in post_content. A hook element that executes PHP is read without its
 * content and never written, and _generate_hook_execute_php is never set.
 */
final class Theme_Layouts_GeneratePress extends Theme_Layouts_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    private const GENERAL = [ 'site', 'front_page', 'blog', 'singular', 'archive', 'author', 'date', 'search', 'no_results', '404', 'is_paged' ];

    public function family(): string
    {
        return 'generatepress';
    }

    public function product(): string
    {
        return 'GP Premium';
    }

    public function plugin_file(): string
    {
        return 'gp-premium/gp-premium.php';
    }

    protected function loaded(): bool
    {
        return defined('GP_PREMIUM_VERSION');
    }

    public function post_type(): string
    {
        return 'gp_elements';
    }

    public function module_hint(): string
    {
        return 'Activate the Elements module under Appearance > GeneratePress.';
    }

    public function types(): array
    {
        $types = [
            'hook'   => [ 'label' => 'Hook', 'writable' => true, 'hook' => true ],
            'header' => [ 'label' => 'Header (page hero)', 'writable' => false, 'hook' => false ],
            'layout' => [ 'label' => 'Layout', 'writable' => false, 'hook' => false ],
        ];
        $blocks = [
            'hook'                        => [ 'Block: Hook', true ],
            'site-header'                 => [ 'Block: Site Header', true ],
            'site-footer'                 => [ 'Block: Site Footer', true ],
            'right-sidebar'               => [ 'Block: Right Sidebar', true ],
            'left-sidebar'                => [ 'Block: Left Sidebar', true ],
            'page-hero'                   => [ 'Block: Page Hero', true ],
            'content-template'            => [ 'Block: Content Template', false ],
            'loop-template'               => [ 'Block: Loop Template', false ],
            'post-meta-template'          => [ 'Block: Post Meta Template', false ],
            'post-navigation-template'    => [ 'Block: Post Navigation', false ],
            'archive-navigation-template' => [ 'Block: Archive Navigation', false ],
            'search-modal'                => [ 'Block: Search Modal', false ],
        ];
        foreach ($blocks as $block => [ $label, $writable ]) {
            $types[ 'block/' . $block ] = [ 'label' => $label, 'writable' => $writable, 'hook' => 'hook' === $block ];
        }
        return $types;
    }

    public function type_of(int $id): string
    {
        $type = (string) get_post_meta($id, '_generate_element_type', true);
        return 'block' === $type ? 'block/' . (string) get_post_meta($id, '_generate_block_type', true) : $type;
    }

    protected function hook_catalog(string $type): array
    {
        if (! class_exists('\GeneratePress_Elements_Helper')) {
            return [];
        }
        $out = [];
        foreach ((array) \GeneratePress_Elements_Helper::get_available_hooks() as $group) {
            $hooks = [];
            foreach ((array) ($group['hooks'] ?? []) as $name) {
                $hooks[] = [ 'name' => (string) $name, 'label' => (string) $name ];
            }
            $out[] = [ 'group' => (string) ($group['group'] ?? ''), 'hooks' => $hooks ];
        }
        return $out;
    }

    public function placement(int $id): array
    {
        $type = $this->type_of($id);
        if ('hook' !== $type && 'block/hook' !== $type) {
            return [ 'hook' => null, 'custom_hook' => false, 'priority' => null ];
        }
        $hook     = (string) get_post_meta($id, '_generate_hook', true);
        $custom   = 'custom' === $hook;
        $name     = $custom ? (string) get_post_meta($id, '_generate_custom_hook', true) : $hook;
        $priority = (string) get_post_meta($id, '_generate_hook_priority', true);
        return [
            'hook'        => '' === $name ? null : $name,
            'custom_hook' => $custom,
            'priority'    => '' === $priority ? null : (int) $priority,
        ];
    }

    public function write_new(int $id, string $type): void
    {
        if (0 === strpos($type, 'block/')) {
            update_post_meta($id, '_generate_element_type', 'block');
            update_post_meta($id, '_generate_block_type', substr($type, 6));
            return;
        }
        update_post_meta($id, '_generate_element_type', $type);
    }

    public function write_placement(int $id, string $type, string $hook, bool $custom, int $priority): void
    {
        update_post_meta($id, '_generate_hook', $custom ? 'custom' : $hook);
        if ($custom) {
            update_post_meta($id, '_generate_custom_hook', $hook);
        } else {
            delete_post_meta($id, '_generate_custom_hook');
        }
        update_post_meta($id, '_generate_hook_priority', (string) $priority);
    }

    public function content_key(string $type, int $id = 0): string
    {
        if (0 === strpos($type, 'block/')) {
            return 'post_content';
        }
        if ('layout' === $type) {
            throw new Operation_Refused('content_not_supported', 'A GP Premium Layout element holds settings, not content.');
        }
        if ($id > 0 && '' !== (string) get_post_meta($id, '_generate_hook_execute_php', true)) {
            throw new Operation_Refused('code_layout', 'This GP Premium element executes PHP; its code is edited in wp-admin only.');
        }
        return '_generate_element_content';
    }

    public function read_rules(int $id): array
    {
        $users = get_post_meta($id, '_generate_element_user_conditions', true);
        return [
            'display' => self::stored_conditions(get_post_meta($id, '_generate_element_display_conditions', true)),
            'exclude' => self::stored_conditions(get_post_meta($id, '_generate_element_exclude_conditions', true)),
            'users'   => is_array($users) ? array_values($users) : [],
        ];
    }

    public function normalize_rules(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            if ('display' === $key || 'exclude' === $key) {
                $out[ $key ] = array_map(static fn ($c) => self::condition($c), self::list_of($value, $key));
            } elseif ('users' === $key) {
                $out['users'] = [];
                foreach (self::list_of($value, 'users') as $user) {
                    $user = (string) $user;
                    if (! in_array($user, [ 'general:all', 'general:logged_in', 'general:logged_out' ], true) && ! self::role_exists($user)) {
                        throw self::bad_rules(sprintf('User rule "%s" is not general:all, general:logged_in, general:logged_out or a role on this site.', $user));
                    }
                    $out['users'][] = $user;
                }
            } else {
                throw self::bad_rules(sprintf('GP Premium display rules take display, exclude and users, not "%s".', (string) $key));
            }
        }
        return $out;
    }

    public function write_rules(int $id, string $type, array $rules): void
    {
        foreach ([ 'display' => '_generate_element_display_conditions', 'exclude' => '_generate_element_exclude_conditions', 'users' => '_generate_element_user_conditions' ] as $key => $meta) {
            if (array_key_exists($key, $rules)) {
                self::put_meta($id, $meta, $rules[ $key ]);
            }
        }
        if (0 === strpos($type, 'block/') && [] !== array_intersect_key($rules, [ 'display' => 1, 'exclude' => 1 ])) {
            // GP Premium's own save drops GenerateBlocks' per-post CSS cache when
            // a block element's conditions change, so the element's styles are
            // printed where it now shows. A cache: it rebuilds on the next view.
            if ([] !== (array) get_option('generateblocks_dynamic_css_posts', [])) {
                update_option('generateblocks_dynamic_css_posts', []);
            }
        }
    }

    public function rule_vocabulary(): array
    {
        $out = [
            'format' => '{display: [{rule, object}], exclude: [same], users: [...]}. rule: general:site, general:front_page, general:blog, general:singular, general:archive, general:author, general:date, general:search, general:no_results, general:404, general:is_paged, post:<post_type>, archive:<post_type>, taxonomy:<taxonomy> or <post_type>:taxonomy:<taxonomy>; object is a post or term id, or empty for all. users: general:all, general:logged_in, general:logged_out or a role.',
        ];
        if (class_exists('\GeneratePress_Conditions')) {
            self::load_role_functions();
            $out['location_options'] = \GeneratePress_Conditions::get_conditions();
            $out['user_options']     = \GeneratePress_Conditions::get_user_conditions();
        }
        return $out;
    }

    /**
     * @param mixed $stored
     * @return array<int,array{rule:string,object:string}>
     */
    private static function stored_conditions($stored): array
    {
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $condition) {
            if (is_array($condition)) {
                $out[] = [ 'rule' => (string) ($condition['rule'] ?? ''), 'object' => (string) ($condition['object'] ?? '') ];
            }
        }
        return $out;
    }

    /**
     * @param mixed $condition
     * @return array{rule:string,object:string}
     */
    private static function condition($condition): array
    {
        if (! is_array($condition) || ! is_string($condition['rule'] ?? null)) {
            throw self::bad_rules('Each display and exclude entry is an object with a rule.');
        }
        $rule = $condition['rule'];
        if (1 === preg_match('/^general:([a-z0-9_]+)$/', $rule, $m)) {
            if (! in_array($m[1], self::GENERAL, true)) {
                throw self::bad_rules(sprintf('"%s" is not a GP Premium general rule.', $rule));
            }
        } elseif (1 === preg_match('/^(post|archive):([a-z0-9_-]+)$/', $rule, $m)) {
            self::check_post_type($m[2], $rule);
        } elseif (1 === preg_match('/^taxonomy:([a-z0-9_-]+)$/', $rule, $m)) {
            self::check_taxonomy($m[1], $rule);
        } elseif (1 === preg_match('/^([a-z0-9_-]+):taxonomy:([a-z0-9_-]+)$/', $rule, $m)) {
            self::check_post_type($m[1], $rule);
            self::check_taxonomy($m[2], $rule);
        } else {
            throw self::bad_rules(sprintf('"%s" is not a GP Premium display rule (see list-generatepress-hooks).', $rule));
        }
        $object = $condition['object'] ?? '';
        if ('' !== $object && (! is_numeric($object) || (int) $object < 1)) {
            throw self::bad_rules('object is a post or term id, or empty.');
        }
        // GP stores the object through sanitize_key(), so as a string.
        return [ 'rule' => $rule, 'object' => '' === $object ? '' : (string) (int) $object ];
    }
}
