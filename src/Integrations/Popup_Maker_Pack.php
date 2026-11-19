<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Popup Maker (issue #685) on the plugin-data pair, free tier, so it adds no
 * top-level tools. Ops are prefixed popupmaker- because the pair is shared.
 *
 * Popup Maker 1.25 keeps a popup as a `popup` post: post_title is the
 * internal name, post_content the popup's content, the popup_title post
 * meta the title visitors see, the popup_settings post meta array its
 * settings (triggers, cookies, conditions, theme_id, size, location,
 * animation and close options) and the enabled post meta its on/off switch
 * (1 or 0; a missing key counts as on, Popup::is_enabled()). The post type's
 * capabilities all map to the plugin's edit_popups permission
 * (edit_others_posts unless the site changed it). A popup shows when it is
 * published and enabled.
 *
 * Reads: popupmaker-list-popups, -get-popup (content, settings, triggers,
 * cookies, conditions) and -get-popup-triggers. Popups carry no visitor
 * data, so nothing needs masking.
 * Writes are off until a site opts in with wpmcp_enable_popup_maker_write,
 * since a popup shows on every page its conditions match:
 *  - create-popup inserts a popup as a draft (enable-popup shows it),
 *    recorded for rollback (Post_Creation_Snapshot: the undo trashes it);
 *  - update-popup changes the name, title, content and the given display
 *    settings, merged into popup_settings, under a post snapshot;
 *  - set-popup-triggers replaces the triggers (and the cookies when given)
 *    under a post snapshot;
 *  - enable-popup publishes the popup and sets enabled to 1, disable-popup
 *    sets enabled to 0 (Popup Maker's own list toggle), each under a post
 *    snapshot.
 * Content goes through WordPress's own save filters, so it is filtered for
 * users without unfiltered_html as an editor save would be.
 *
 * Presence is filterable (wpmcp_popup_maker_active, default: POPMAKE_VERSION
 * is defined).
 */
final class Popup_Maker_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const POPUP = 'popup';

    public const THEME = 'popup_theme';

    public const SETTINGS = 'popup_settings';

    public const OPT_IN_FILTER = 'wpmcp_enable_popup_maker_write';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_TRIGGERS = 20;

    public const PLUGIN_FILES = [ 'popup-maker/popup-maker.php' ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

    /** The display settings an agent may set, with their JSON types. */
    private const FIELDS = [
        'theme_id'               => 'integer',
        'size'                   => 'string',
        'location'               => 'string',
        'animation_type'         => 'string',
        'close_on_overlay_click' => 'boolean',
        'close_on_esc_press'     => 'boolean',
        'disable_on_mobile'      => 'boolean',
        'disable_on_tablet'      => 'boolean',
    ];

    /** What a new popup starts with, from Popup Maker's own default popup. */
    private const SEED = [
        'triggers'               => [],
        'cookies'                => [],
        'conditions'             => [],
        'size'                   => 'medium',
        'location'               => 'center',
        'animation_type'         => 'fade',
        'animation_speed'        => '350',
        'close_on_overlay_click' => false,
        'close_on_esc_press'     => false,
        'disable_on_mobile'      => false,
        'disable_on_tablet'      => false,
    ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_popup_maker_active', defined('POPMAKE_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Popup Maker is %s on this site. Install and activate Popup Maker (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'popup-maker', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $one     = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $setting = [];
        foreach (self::FIELDS as $key => $type) {
            $setting[ $key ] = 'integer' === $type ? $id : ('string' === $type ? [ 'type' => 'string', 'maxLength' => 50 ] : [ 'type' => 'boolean' ]);
        }
        $fields   = [
            'title'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'display_title' => [ 'type' => 'string', 'maxLength' => 500 ],
            'content'       => [ 'type' => 'string', 'maxLength' => 200000 ],
        ] + $setting;
        $triggers = [ 'type' => 'array', 'maxItems' => self::MAX_TRIGGERS, 'items' => [ 'type' => 'object' ] ];
        $base     = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $write    = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_popup_maker_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $popup    = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $theme    = [ 'theme_id' => [ 'type' => 'post', 'access' => 'read' ] ];
        $target   = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $by_id    = static fn (array $args): ?array => self::popup_refusal((int) $args['id']) ?? self::theme_refusal($args);

        return [
            'popupmaker-list-popups'        => $base + [
                'mode'         => 'read',
                'description'  => 'List Popup Maker popups: id, title (internal name), display_title, status, switched_on, enabled (published and switched on), theme_id, trigger_types. Filter by status, switched_on, search. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'switched_on' => [ 'type' => 'boolean' ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(),
                'handler'      => static fn (array $args): array => self::list_popups($args),
            ],
            'popupmaker-get-popup'          => $base + [
                'mode'         => 'read',
                'objects'      => $popup('read'),
                'description'  => 'Read one Popup Maker popup: content, display settings, triggers, cookies, conditions',
                'input_schema' => $one,
                'validate'     => $by_id,
                'handler'      => static fn (array $args): array => [ 'popup' => self::view(get_post((int) $args['id']), true) ],
            ],
            'popupmaker-get-popup-triggers' => $base + [
                'mode'         => 'read',
                'objects'      => $popup('read'),
                'description'  => 'Read a Popup Maker popup\'s triggers (type and settings) and cookies',
                'input_schema' => $one,
                'validate'     => $by_id,
                'handler'      => static fn (array $args): array => self::triggers_of((int) $args['id']),
            ],
            'popupmaker-create-popup'       => $write + [
                'self_snapshotting' => true,
                'objects'           => $theme,
                'description'       => 'Create a Popup Maker popup as a draft (enable-popup shows it): title (internal name) required; display_title, content, triggers [{type, settings}], display settings. Undo: rollback-session trashes it',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => $fields + [ 'triggers' => $triggers ],
                    'required'             => [ 'title' ],
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'popupmaker-update-popup'       => $write + [
                'objects'      => $popup('write') + $theme,
                'description'  => 'Change a Popup Maker popup; only the args given change, merged into its settings. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id ] + $fields,
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => $by_id,
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'popupmaker-set-popup-triggers' => $write + [
                'objects'      => $popup('write'),
                'description'  => 'Replace a Popup Maker popup\'s triggers ([{type: click_open|auto_open|form_submission|..., settings}], [] for none) and, when given, its cookies ([{event, settings}]). Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'triggers' => $triggers, 'cookies' => $triggers ],
                    'required'             => [ 'id', 'triggers' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::popup_refusal((int) $args['id']) ?? self::rules_refusal($args),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_triggers($args),
            ],
            'popupmaker-enable-popup'       => $write + [
                'objects'      => $popup('write'),
                'description'  => 'Show a Popup Maker popup: publish it and switch it on. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => $by_id,
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::switch_popup((int) $args['id'], true),
            ],
            'popupmaker-disable-popup'      => $write + [
                'objects'      => $popup('write'),
                'description'  => 'Switch a Popup Maker popup off (enabled 0); it stays published. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => $by_id,
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::switch_popup((int) $args['id'], false),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** The capability behind Popup Maker's edit_popups permission. */
    private static function permission(): string
    {
        $cap = 'edit_others_posts';
        if (function_exists('\\PopupMaker\\plugin')) {
            $plugin = \PopupMaker\plugin();
            if (is_object($plugin) && method_exists($plugin, 'get_permission')) {
                $cap = (string) $plugin->get_permission('edit_popups');
            }
        }
        return '' === $cap ? 'edit_others_posts' : $cap;
    }

    private static function cap_refusal(): ?array
    {
        $cap = self::permission();
        return current_user_can($cap) ? null : self::refusal('operation_denied', sprintf('This needs the %s capability (Popup Maker\'s edit_popups permission).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function is_popup($post): bool
    {
        return $post instanceof \WP_Post && self::POPUP === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function popup_refusal(int $id): ?array
    {
        if (! self::is_popup(get_post($id))) {
            return self::refusal('popup_not_found', sprintf('%d is not a Popup Maker popup.', $id), [ 'id' => $id ]);
        }
        return self::cap_refusal();
    }

    private static function create_refusal(array $args): ?array
    {
        if (! post_type_exists(self::POPUP)) {
            return self::refusal('post_type_missing', 'Popup Maker has not registered its popup post type.');
        }
        return self::cap_refusal() ?? self::theme_refusal($args) ?? self::rules_refusal($args);
    }

    private static function theme_refusal(array $args): ?array
    {
        if (! isset($args['theme_id'])) {
            return null;
        }
        $theme = get_post((int) $args['theme_id']);
        if ($theme instanceof \WP_Post && self::THEME === $theme->post_type && 'trash' !== $theme->post_status) {
            return null;
        }
        return self::refusal('theme_not_found', sprintf('%d is not a Popup Maker theme.', (int) $args['theme_id']), [ 'theme_id' => (int) $args['theme_id'] ]);
    }

    /** Triggers and cookies are lists of {type|event, settings} with flat settings. */
    private static function rules_refusal(array $args): ?array
    {
        foreach ([ 'triggers' => 'type', 'cookies' => 'event' ] as $list => $key) {
            $code = 'triggers' === $list ? 'trigger_invalid' : 'cookie_invalid';
            foreach ((array) ($args[ $list ] ?? []) as $i => $rule) {
                if (! is_array($rule) || 1 !== preg_match('/^[a-z0-9_]{1,64}$/', (string) ($rule[ $key ] ?? ''))) {
                    return self::refusal($code, sprintf('%s[%d] needs a %s of lowercase letters, digits and underscores.', $list, (int) $i, $key), [ 'index' => (int) $i ]);
                }
                $settings = $rule['settings'] ?? [];
                if (! is_array($settings)) {
                    return self::refusal($code, sprintf('%s[%d].settings must be an object.', $list, (int) $i), [ 'index' => (int) $i ]);
                }
                foreach ($settings as $value) {
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            if (! is_scalar($item)) {
                                return self::refusal($code, sprintf('%s[%d].settings holds a value nested too deep; settings take text, numbers, booleans or lists of them.', $list, (int) $i), [ 'index' => (int) $i ]);
                            }
                        }
                    } elseif (null !== $value && ! is_scalar($value)) {
                        return self::refusal($code, sprintf('%s[%d].settings holds a value of an unknown kind.', $list, (int) $i), [ 'index' => (int) $i ]);
                    }
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed> */
    private static function settings(int $id): array
    {
        $settings = get_post_meta($id, self::SETTINGS, true);
        return is_array($settings) ? $settings : [];
    }

    /** Popup::is_enabled(): a missing key is on, anything but 0 or 1 is on. */
    private static function switched_on(int $id): bool
    {
        $raw = get_post_meta($id, 'enabled', true);
        return '' === $raw || 0 !== (int) $raw;
    }

    /** @return array<int, array{type: string, settings: array}> */
    private static function rules(array $settings, string $list, string $key): array
    {
        $out = [];
        foreach ((array) ($settings[ $list ] ?? []) as $rule) {
            if (is_array($rule) && isset($rule[ $key ])) {
                $out[] = [ $key => (string) $rule[ $key ], 'settings' => is_array($rule['settings'] ?? null) ? $rule['settings'] : [] ];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view(\WP_Post $post, bool $full): array
    {
        $id       = (int) $post->ID;
        $settings = self::settings($id);
        $on       = self::switched_on($id);
        $triggers = self::rules($settings, 'triggers', 'type');
        $out      = [
            'id'            => $id,
            'title'         => (string) $post->post_title,
            'display_title' => (string) get_post_meta($id, 'popup_title', true),
            'status'        => (string) $post->post_status,
            'switched_on'   => $on,
            'enabled'       => $on && 'publish' === $post->post_status,
            'theme_id'      => (int) ($settings['theme_id'] ?? 0),
            'trigger_types' => array_values(array_unique(array_column($triggers, 'type'))),
            'modified'      => (string) $post->post_modified,
        ];
        if ($full) {
            $display = [];
            foreach (self::FIELDS as $key => $type) {
                if (array_key_exists($key, $settings)) {
                    $display[ $key ] = 'integer' === $type ? (int) $settings[ $key ] : ('boolean' === $type ? (bool) $settings[ $key ] : (string) $settings[ $key ]);
                }
            }
            $out['content']    = (string) $post->post_content;
            $out['settings']   = $display;
            $out['triggers']   = $triggers;
            $out['cookies']    = self::rules($settings, 'cookies', 'event');
            $out['conditions'] = is_array($settings['conditions'] ?? null) ? $settings['conditions'] : [];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function triggers_of(int $id): array
    {
        $settings = self::settings($id);
        return [ 'id' => $id, 'triggers' => self::rules($settings, 'triggers', 'type'), 'cookies' => self::rules($settings, 'cookies', 'event') ];
    }

    /** @return array<string, mixed> */
    private static function list_popups(array $args): array
    {
        $query = [
            'post_type'      => self::POPUP,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        if (array_key_exists('switched_on', $args)) {
            // A popup is off only when the enabled meta is 0; a missing key is on.
            $off                 = [ 'key' => 'enabled', 'value' => '0' ];
            $query['meta_query'] = $args['switched_on'] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the on/off switch is post meta in Popup Maker.
                ? [ 'relation' => 'OR', [ 'key' => 'enabled', 'compare' => 'NOT EXISTS' ], [ 'key' => 'enabled', 'value' => '0', 'compare' => '!=' ] ]
                : [ $off ];
        }
        $q = Readable_Posts::query($query);
        return [ 'popups' => array_map(static fn (\WP_Post $p): array => self::view($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    // ------------------------------------------------------------ writes

    /** @return array<int, array<string, mixed>> */
    private static function clean_rules(array $rules, string $key): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $settings = [];
            foreach ((array) ($rule['settings'] ?? []) as $name => $value) {
                $name = sanitize_key((string) $name);
                if ('' === $name) {
                    continue;
                }
                if (is_array($value)) {
                    $settings[ $name ] = array_values(array_map(static fn ($v) => is_string($v) ? sanitize_text_field($v) : $v, $value));
                } else {
                    $settings[ $name ] = is_string($value) ? sanitize_text_field($value) : $value;
                }
            }
            $out[] = [ $key => (string) $rule[ $key ], 'settings' => $settings ];
        }
        return $out;
    }

    /** Merge the given display settings into popup_settings; keys the call does not name stay. */
    private static function merged_settings(array $settings, array $args): array
    {
        foreach (self::FIELDS as $key => $type) {
            if (! array_key_exists($key, $args)) {
                continue;
            }
            $settings[ $key ] = 'integer' === $type ? (int) $args[ $key ] : ('boolean' === $type ? (bool) $args[ $key ] : sanitize_text_field((string) $args[ $key ]));
        }
        return $settings;
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $id = wp_insert_post([
            'post_type'      => self::POPUP,
            'post_title'     => wp_slash((string) $args['title']),
            'post_content'   => wp_slash((string) ($args['content'] ?? '')),
            'post_status'    => 'draft',
            'post_author'    => get_current_user_id(),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'Popup Maker could not create the popup.');
        }
        $id       = (int) $id;
        $op       = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        $settings = self::merged_settings(self::SEED, $args);
        if (isset($args['triggers'])) {
            $settings['triggers'] = self::clean_rules((array) $args['triggers'], 'type');
        }
        update_post_meta($id, self::SETTINGS, $settings);
        update_post_meta($id, 'popup_title', sanitize_text_field((string) ($args['display_title'] ?? '')));
        update_post_meta($id, 'enabled', 1);
        clean_post_cache($id);

        return [ 'result' => [ 'popup' => self::view(get_post($id), true) ], 'operation_ids' => [ $op ] ];
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        if (array_key_exists('title', $args)) {
            $fields['post_title'] = wp_slash((string) $args['title']);
        }
        if (array_key_exists('content', $args)) {
            $fields['post_content'] = wp_slash((string) $args['content']);
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        if (array_key_exists('display_title', $args)) {
            update_post_meta($id, 'popup_title', sanitize_text_field((string) $args['display_title']));
        }
        if ([] !== array_intersect_key($args, self::FIELDS)) {
            update_post_meta($id, self::SETTINGS, self::merged_settings(self::settings($id), $args));
        }
        clean_post_cache($id);

        return [ 'popup' => self::view(get_post($id), true) ];
    }

    /** @return array<string, mixed> */
    private static function set_triggers(array $args): array
    {
        $id                   = (int) $args['id'];
        $settings             = self::settings($id);
        $settings['triggers'] = self::clean_rules((array) $args['triggers'], 'type');
        if (isset($args['cookies'])) {
            $settings['cookies'] = self::clean_rules((array) $args['cookies'], 'event');
        }
        update_post_meta($id, self::SETTINGS, $settings);
        clean_post_cache($id);

        return self::triggers_of($id);
    }

    /** @return array<string, mixed> */
    private static function switch_popup(int $id, bool $on): array
    {
        if ($on && 'publish' !== get_post_status($id)) {
            $out = Save_Filters::set_post_status($id, 'publish', true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        update_post_meta($id, 'enabled', $on ? 1 : 0);
        clean_post_cache($id);

        return [ 'popup' => self::view(get_post($id), false) ];
    }
}
