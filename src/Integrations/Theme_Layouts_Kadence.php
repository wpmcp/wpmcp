<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kadence Pro Elements (issue #656), as Kadence Pro 1.2 stores them: the
 * kadence_element post type, the element kind in _kad_element_type
 * (default, fixed, template, script), its hook in _kad_element_hook (custom
 * plus _kad_element_hook_custom for an action of the site's own) and
 * _kad_element_hook_priority, and the display rules as JSON strings in
 * _kad_element_show_conditionals, _kad_element_hide_conditionals,
 * _kad_element_user_conditionals and _kad_element_device_conditionals, plus
 * the _kad_element_all_show flag, each in the shape the element editor
 * saves and Elements_Controller::check_element_conditionals() reads.
 *
 * HTML code elements (type script) print raw markup and scripts, so they
 * are read without their content and never written.
 */
final class Theme_Layouts_Kadence extends Theme_Layouts_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    private const GENERAL = [ 'site', 'front_page', 'home', 'search', '404', 'singular', 'archive', 'author', 'date', 'paged', 'product_search', 'woocommerce' ];

    private const SELECTS = [ 'all', 'ids', 'tax', 'author', 'individual' ];

    private const DEVICES = [ 'desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile' ];

    public function family(): string
    {
        return 'kadence';
    }

    public function product(): string
    {
        return 'Kadence Pro';
    }

    public function plugin_file(): string
    {
        return 'kadence-pro/kadence-pro.php';
    }

    protected function loaded(): bool
    {
        return defined('KTP_VERSION');
    }

    public function post_type(): string
    {
        return 'kadence_element';
    }

    public function module_hint(): string
    {
        return 'Switch on Elements under Appearance > Kadence > Pro Add-ons.';
    }

    public function types(): array
    {
        return [
            'default'  => [ 'label' => 'Content Section', 'writable' => true, 'hook' => true ],
            'fixed'    => [ 'label' => 'Fixed Section', 'writable' => true, 'hook' => true ],
            'template' => [ 'label' => 'Template', 'writable' => true, 'hook' => true ],
            'script'   => [ 'label' => 'HTML Element', 'writable' => false, 'hook' => true ],
        ];
    }

    public function type_of(int $id): string
    {
        $type = (string) get_post_meta($id, '_kad_element_type', true);
        return '' === $type ? 'default' : $type;
    }

    protected function hook_catalog(string $type): array
    {
        if (! class_exists('\Kadence_Pro\Elements_Controller')) {
            return [];
        }
        $method = [ 'fixed' => 'get_fixed_hook_options', 'template' => 'get_template_hook_options', 'script' => 'get_code_hook_options' ][ $type ] ?? 'get_hook_options';
        $controller = \Kadence_Pro\Elements_Controller::get_instance();
        if (! method_exists($controller, $method)) {
            return [];
        }
        $out = [];
        foreach ((array) $controller->{$method}() as $group) {
            $hooks = [];
            foreach ((array) ($group['options'] ?? []) as $option) {
                $hooks[] = [ 'name' => (string) ($option['value'] ?? ''), 'label' => (string) ($option['label'] ?? '') ];
            }
            $out[] = [ 'group' => (string) ($group['label'] ?? ''), 'hooks' => $hooks ];
        }
        return $out;
    }

    public function placement(int $id): array
    {
        $hook     = (string) get_post_meta($id, '_kad_element_hook', true);
        $custom   = 'custom' === $hook;
        $name     = $custom ? (string) get_post_meta($id, '_kad_element_hook_custom', true) : $hook;
        $priority = get_post_meta($id, '_kad_element_hook_priority', true);
        return [
            'hook'        => '' === $name ? null : $name,
            'custom_hook' => $custom,
            'priority'    => '' === $priority ? null : (int) $priority,
        ];
    }

    public function write_new(int $id, string $type): void
    {
        update_post_meta($id, '_kad_element_type', $type);
    }

    public function write_placement(int $id, string $type, string $hook, bool $custom, int $priority): void
    {
        update_post_meta($id, '_kad_element_hook', $custom ? 'custom' : $hook);
        if ($custom) {
            update_post_meta($id, '_kad_element_hook_custom', $hook);
        } else {
            delete_post_meta($id, '_kad_element_hook_custom');
        }
        update_post_meta($id, '_kad_element_hook_priority', $priority);
    }

    public function content_key(string $type, int $id = 0): string
    {
        if ('script' === $type) {
            throw new Operation_Refused('code_layout', 'This Kadence element is an HTML code element; its code is edited in wp-admin only.');
        }
        return 'post_content';
    }

    public function read_rules(int $id): array
    {
        $user   = self::json($id, '_kad_element_user_conditionals');
        $device = self::json($id, '_kad_element_device_conditionals');
        return [
            'show'     => self::json($id, '_kad_element_show_conditionals'),
            'all_show' => (bool) get_post_meta($id, '_kad_element_all_show', true),
            'hide'     => self::json($id, '_kad_element_hide_conditionals'),
            'user'     => array_values(array_filter(array_map(static fn ($u) => is_array($u) ? (string) ($u['role'] ?? '') : '', $user))),
            'device'   => array_values(array_filter(array_map(static fn ($d) => is_array($d) ? (string) ($d['value'] ?? '') : '', $device))),
        ];
    }

    public function normalize_rules(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            if ('show' === $key || 'hide' === $key) {
                $out[ $key ] = array_map(static fn ($rule) => self::rule($rule), self::list_of($value, $key));
            } elseif ('all_show' === $key) {
                if (! is_bool($value)) {
                    throw self::bad_rules('all_show is true (every show rule must match) or false (any one).');
                }
                $out['all_show'] = $value;
            } elseif ('user' === $key) {
                $out['user'] = [];
                foreach (self::list_of($value, 'user') as $role) {
                    $role = (string) (is_array($role) ? ($role['role'] ?? '') : $role);
                    if (! in_array($role, [ 'public', 'logged_in', 'logged_out' ], true) && ! self::role_exists($role)) {
                        throw self::bad_rules(sprintf('User rule "%s" is not public, logged_in, logged_out or a role on this site.', $role));
                    }
                    $out['user'][] = [ 'role' => $role ];
                }
            } elseif ('device' === $key) {
                $out['device'] = [];
                foreach (self::list_of($value, 'device') as $device) {
                    $device = (string) (is_array($device) ? ($device['value'] ?? '') : $device);
                    if (! isset(self::DEVICES[ $device ])) {
                        throw self::bad_rules(sprintf('Device "%s" is not desktop, tablet or mobile.', $device));
                    }
                    $out['device'][] = [ 'value' => $device, 'label' => self::DEVICES[ $device ] ];
                }
            } else {
                throw self::bad_rules(sprintf('Kadence display rules take show, all_show, hide, user and device, not "%s".', (string) $key));
            }
        }
        return $out;
    }

    public function write_rules(int $id, string $type, array $rules): void
    {
        $keys = [ 'show' => '_kad_element_show_conditionals', 'hide' => '_kad_element_hide_conditionals', 'user' => '_kad_element_user_conditionals', 'device' => '_kad_element_device_conditionals' ];
        foreach ($keys as $key => $meta) {
            if (array_key_exists($key, $rules)) {
                self::put_meta($id, $meta, [] === $rules[ $key ] ? '' : (string) wp_json_encode($rules[ $key ]));
            }
        }
        if (array_key_exists('all_show', $rules)) {
            self::put_meta($id, '_kad_element_all_show', $rules['all_show'] ? true : '');
        }
    }

    public function rule_vocabulary(): array
    {
        $out = [
            'format' => '{show: [{rule, select, ids, subRule, subSelection, mustMatch}], all_show: bool, hide: [same], user: [roles], device: [desktop|tablet|mobile]}. rule: general|site, general|front_page, general|home, general|search, general|404, general|singular, general|archive, general|author, general|date, general|paged, singular|<post_type> or tax_archive|<taxonomy>. select (singular and tax_archive rules): all, ids (ids: post ids), tax (subRule: taxonomy, subSelection: [{value: term_id}]), author (subRule: user id) or individual (subSelection: [{value: term_id}]). user: public, logged_in, logged_out or a role.',
        ];
        if (class_exists('\Kadence_Pro\Elements_Controller')) {
            $controller = \Kadence_Pro\Elements_Controller::get_instance();
            if (method_exists($controller, 'get_display_options')) {
                self::load_role_functions();
                $out['display_options'] = $controller->get_display_options();
                $out['user_options']    = $controller->get_user_options();
            }
        }
        return $out;
    }

    /** @return array<int,mixed> */
    private static function json(int $id, string $key): array
    {
        $decoded = json_decode((string) get_post_meta($id, $key, true), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One show or hide rule in the element editor's shape.
     *
     * @param mixed $rule
     * @return array<string,mixed>
     */
    private static function rule($rule): array
    {
        if (! is_array($rule) || ! is_string($rule['rule'] ?? null)) {
            throw self::bad_rules('Each show and hide entry is an object with a rule.');
        }
        $name = $rule['rule'];
        if (1 !== preg_match('/^(general|singular|tax_archive)\|([a-z0-9_-]+)$/', $name, $m)) {
            throw self::bad_rules(sprintf('"%s" is not a Kadence display rule (see list-kadence-hooks).', $name));
        }
        if ('general' === $m[1] && ! in_array($m[2], self::GENERAL, true)) {
            throw self::bad_rules(sprintf('"%s" is not a Kadence general rule.', $name));
        }
        if ('singular' === $m[1]) {
            self::check_post_type($m[2], $name);
        }
        if ('tax_archive' === $m[1]) {
            self::check_taxonomy($m[2], $name);
        }

        $select = (string) ($rule['select'] ?? 'all');
        if (! in_array($select, self::SELECTS, true)) {
            throw self::bad_rules(sprintf('select "%s" is not one of %s.', $select, implode(', ', self::SELECTS)));
        }
        $ids = [];
        foreach (self::list_of($rule['ids'] ?? [], 'ids') as $id) {
            if (! is_numeric($id) || (int) $id < 1) {
                throw self::bad_rules('ids are post ids.');
            }
            // Kadence compares these to get_the_ID() strictly, so they are integers.
            $ids[] = (int) $id;
        }
        $selection = [];
        foreach (self::list_of($rule['subSelection'] ?? [], 'subSelection') as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : $item;
            if (! is_numeric($value)) {
                throw self::bad_rules('subSelection entries are {value: term_id}.');
            }
            $selection[] = [ 'value' => (int) $value ] + (is_array($item) && isset($item['label']) ? [ 'label' => (string) $item['label'] ] : []);
        }
        $sub = (string) ($rule['subRule'] ?? '');
        if ('tax' === $select && '' !== $sub) {
            self::check_taxonomy($sub, $name);
        }

        return [
            'rule'         => $name,
            'select'       => $select,
            'ids'          => $ids,
            'subRule'      => sanitize_text_field($sub),
            'subSelection' => $selection,
            'mustMatch'    => ! empty($rule['mustMatch']),
        ];
    }
}
