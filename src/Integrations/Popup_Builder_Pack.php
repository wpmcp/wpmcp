<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Popup Builder (issue #898) on the plugin-data pair, free tier, so it adds
 * no top-level tools. Ops are prefixed popupbuilder- because the pair is
 * shared.
 *
 * Popup Builder 4.x keeps a popup as a `popupbuilder` post (title, content
 * in post_content) with its settings in the sg_popup_options post meta array
 * (sgpb-type, sgpb-is-active, size, effects and the rest), its display rules
 * in sg_popup_target (['sgpb-target' => [ 0 => rules ], 'sgpb-conditions'
 * => ...]; the plugin reads group 0 only) and its triggers in
 * sg_popup_events. A draft keeps the same keys with a _preview suffix
 * (SGPopup::getPopupOptionsById()). Its on/off switch is sgpb-is-active, not
 * the post status (Ajax::changePopupStatus()). Opens are counted per popup
 * in the SgpbCounter option. The free plugin has no start or end dates.
 *
 * Reads: popupbuilder-list-popups and -get-popup (content, rules, triggers,
 * settings, the shortcode). A popup holds no visitor data, so nothing needs
 * masking; settings never carry a licence (that lives in options).
 * Writes are off until a site opts in with wpmcp_enable_popup_builder_write,
 * each under a post snapshot (fields and meta):
 *  - update-popup changes the title, content and display rules (the rule
 *    list replaces group 0; conditions and settings stay);
 *  - enable-popup and disable-popup flip sgpb-is-active, the switch the
 *    plugin's own list uses, so publishing and pausing stay explicit.
 * Content goes through WordPress's own save filters, so a user without
 * unfiltered_html cannot store script.
 *
 * Presence is filterable (wpmcp_popup_builder_active, default:
 * SGPB_POPUP_VERSION is defined).
 */
final class Popup_Builder_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const POPUP = 'popupbuilder';

    public const OPTIONS = 'sg_popup_options';

    public const TARGET = 'sg_popup_target';

    public const EVENTS = 'sg_popup_events';

    public const OPT_IN_FILTER = 'wpmcp_enable_popup_builder_write';

    public const CAP = 'edit_sgpb_popups';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'popup-builder/popup-builder.php' ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

    /** Rules that take no value. */
    private const PLAIN_RULES = [ 'everywhere', 'post_all', 'page_all', 'post_tags' ];

    /** Rules that take post_ids, and the post type each id must be. */
    private const POST_RULES = [ 'post_selected' => 'post', 'page_selected' => 'page' ];

    /** Rules that take term_ids, and the taxonomy each id must be in. */
    private const TERM_RULES = [ 'post_category' => 'category', 'post_tags_ids' => 'post_tag' ];

    /**
     * page_type values, the plugin's own list (ConfigDataHelper::getPageTypes()).
     * Popup Builder calls each value as a function, so nothing else is taken.
     */
    private const PAGE_TYPES = [ 'is_home_page', 'is_home', 'is_search', 'is_404', 'is_shop', 'is_archive' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_popup_builder_active', defined('SGPB_POPUP_VERSION'));
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
                'Popup Builder is %s on this site. Install and activate Popup Builder (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'popup-builder', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $one    = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $ids    = [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100 ];
        $rule   = [
            'type'                 => 'object',
            'properties'           => [
                'param'    => [ 'type' => 'string', 'enum' => array_merge(self::PLAIN_RULES, array_keys(self::POST_RULES), array_keys(self::TERM_RULES), [ 'post_type', 'page_type' ]) ],
                'operator' => [ 'type' => 'string', 'enum' => [ '==', '!=' ] ],
                'post_ids' => $ids,
                'term_ids' => $ids,
                'values'   => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 100 ], 'minItems' => 1, 'maxItems' => 50 ],
            ],
            'required'             => [ 'param' ],
            'additionalProperties' => false,
        ];
        $base   = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_popup_builder_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $popup  = [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ];
        $target = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];

        return [
            'popupbuilder-list-popups'   => $base + [
                'mode'         => 'read',
                'description'  => 'List Popup Builder popups: id, title, status, type, active, trigger and rule names, views. Filter by status, type, active, search. page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'type'     => [ 'type' => 'string', 'maxLength' => 50 ],
                        'active'   => [ 'type' => 'boolean' ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(),
                'handler'      => static fn (array $args): array => self::list_popups($args),
            ],
            'popupbuilder-get-popup'     => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Read one Popup Builder popup: content, display rules, triggers, settings, views, shortcode',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::popup_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'popup' => self::view(get_post((int) $args['id']), true) ],
            ],
            'popupbuilder-update-popup'  => $write + [
                'objects'      => $popup + [
                    'rules.*.post_ids' => [ 'type' => 'post', 'access' => 'read' ],
                    'rules.*.term_ids' => [ 'type' => 'term', 'access' => 'read' ],
                ],
                'description'  => 'Change a Popup Builder popup: title, content, rules (replace the display rules: param, operator == or !=, post_ids for *_selected, term_ids for post_category and post_tags_ids, values for post_type and page_type). Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'      => $id,
                        'title'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
                        'content' => [ 'type' => 'string', 'maxLength' => 200000 ],
                        'rules'   => [ 'type' => 'array', 'items' => $rule, 'minItems' => 1, 'maxItems' => 20 ],
                    ],
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::popup_refusal((int) $args['id']) ?? self::rules_refusal((array) ($args['rules'] ?? [])),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'popupbuilder-enable-popup'  => $write + [
                'objects'      => $popup,
                'description'  => 'Turn a Popup Builder popup on (its own switch, not the post status). Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::popup_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_active((int) $args['id'], true),
            ],
            'popupbuilder-disable-popup' => $write + [
                'objects'      => $popup,
                'description'  => 'Turn a Popup Builder popup off; it keeps its rules and post status. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::popup_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_active((int) $args['id'], false),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function cap_refusal(): ?array
    {
        if (current_user_can('manage_options') || current_user_can(self::CAP)) {
            return null;
        }
        return self::refusal('operation_denied', sprintf('This needs the %s capability (Popup Builder grants it to the roles allowed in its settings).', self::CAP), [ 'reason' => 'capability', 'capability' => self::CAP ]);
    }

    private static function is_popup($post): bool
    {
        return $post instanceof \WP_Post && self::POPUP === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function popup_refusal(int $id): ?array
    {
        if (! self::is_popup(get_post($id))) {
            return self::refusal('popup_not_found', sprintf('%d is not a Popup Builder popup.', $id), [ 'id' => $id ]);
        }
        return self::cap_refusal();
    }

    private static function rule_refusal(int $index, string $message): array
    {
        return self::refusal('rule_invalid', sprintf('Rule %d: %s', $index, $message), [ 'rule' => $index ]);
    }

    private static function rules_refusal(array $rules): ?array
    {
        foreach (array_values($rules) as $i => $rule) {
            $param = (string) ($rule['param'] ?? '');
            if (isset(self::POST_RULES[ $param ])) {
                if (empty($rule['post_ids'])) {
                    return self::rule_refusal($i, sprintf('%s needs post_ids.', $param));
                }
                foreach ((array) $rule['post_ids'] as $pid) {
                    $post = get_post((int) $pid);
                    if (! $post instanceof \WP_Post || self::POST_RULES[ $param ] !== $post->post_type || 'publish' !== $post->post_status) {
                        return self::rule_refusal($i, sprintf('%d is not a published %s.', (int) $pid, self::POST_RULES[ $param ]));
                    }
                }
            } elseif (isset(self::TERM_RULES[ $param ])) {
                if (empty($rule['term_ids'])) {
                    return self::rule_refusal($i, sprintf('%s needs term_ids.', $param));
                }
                foreach ((array) $rule['term_ids'] as $tid) {
                    if (! get_term((int) $tid, self::TERM_RULES[ $param ]) instanceof \WP_Term) {
                        return self::rule_refusal($i, sprintf('%d is not a term of the %s taxonomy.', (int) $tid, self::TERM_RULES[ $param ]));
                    }
                }
            } elseif ('post_type' === $param || 'page_type' === $param) {
                if (empty($rule['values'])) {
                    return self::rule_refusal($i, sprintf('%s needs values.', $param));
                }
                foreach ((array) $rule['values'] as $value) {
                    $known = 'post_type' === $param ? post_type_exists((string) $value) : in_array((string) $value, self::PAGE_TYPES, true);
                    if (! $known) {
                        return self::rule_refusal($i, sprintf('Popup Builder does not know %s as a value of %s.', (string) $value, $param));
                    }
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /**
     * The meta key a popup's data lives under: the live key, else (on a
     * draft that has only it) the _preview one, as the plugin reads it.
     */
    private static function key(int $id, string $base): string
    {
        if (metadata_exists('post', $id, $base)) {
            return $base;
        }
        return metadata_exists('post', $id, $base . '_preview') ? $base . '_preview' : $base;
    }

    /** @return array<mixed> */
    private static function meta(int $id, string $base): array
    {
        $value = get_post_meta($id, self::key($id, $base), true);
        return is_array($value) ? $value : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function rules(int $id): array
    {
        $target = self::meta($id, self::TARGET);
        $group  = $target['sgpb-target'][0] ?? [];
        return is_array($group) ? array_values(array_filter($group, 'is_array')) : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function triggers(int $id): array
    {
        $events = self::meta($id, self::EVENTS);
        $group  = $events[0] ?? [];
        return is_array($group) ? array_values(array_filter($group, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    private static function view_rule(array $rule): array
    {
        $out = [ 'param' => (string) ($rule['param'] ?? ''), 'operator' => (string) ($rule['operator'] ?? '==') ];
        $raw = $rule['value'] ?? null;
        if (is_array($raw) && [] !== $raw) {
            if (array_is_list($raw)) {
                $out['value'] = array_map('strval', $raw);
            } else {
                $out['value'] = [];
                foreach ($raw as $key => $name) {
                    $out['value'][] = [ 'id' => (string) $key, 'name' => (string) $name ];
                }
            }
        } elseif (is_scalar($raw) && '' !== (string) $raw) {
            $out['value'] = (string) $raw;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view_trigger(array $event): array
    {
        return [
            'param'   => (string) ($event['param'] ?? ''),
            'value'   => is_scalar($event['value'] ?? null) ? (string) $event['value'] : '',
            'options' => is_array($event['hiddenOption'] ?? null) ? $event['hiddenOption'] : [],
        ];
    }

    private static function views(int $id): int
    {
        $counter = get_option('SgpbCounter');
        return is_array($counter) ? (int) ($counter[ $id ] ?? 0) : 0;
    }

    /** @return array<string, mixed> */
    private static function view(\WP_Post $post, bool $full): array
    {
        $id      = (int) $post->ID;
        $options = self::meta($id, self::OPTIONS);
        $out     = [
            'id'       => $id,
            'title'    => (string) $post->post_title,
            'status'   => (string) $post->post_status,
            'type'     => (string) ($options['sgpb-type'] ?? 'html'),
            'active'   => ! empty($options['sgpb-is-active']),
            'views'    => self::views($id),
            'modified' => (string) $post->post_modified,
        ];
        if (! $full) {
            $out['triggers'] = array_map(static fn (array $e): string => (string) ($e['param'] ?? ''), self::triggers($id));
            $out['rules']    = array_map(static fn (array $r): string => (string) ($r['param'] ?? ''), self::rules($id));
            return $out;
        }
        $target            = self::meta($id, self::TARGET);
        $out['content']    = (string) $post->post_content;
        $out['shortcode']  = sprintf('[sg_popup id="%d"]', $id);
        $out['rules']      = array_map([ self::class, 'view_rule' ], self::rules($id));
        $out['conditions'] = is_array($target['sgpb-conditions'] ?? null) ? $target['sgpb-conditions'] : [];
        $out['triggers']   = array_map([ self::class, 'view_trigger' ], self::triggers($id));
        $out['settings']   = $options;
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_popups(array $args): array
    {
        $per_page = (int) ($args['per_page'] ?? 20);
        $page     = (int) ($args['page'] ?? 1);
        $query    = [
            'post_type'      => self::POPUP,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            // A site has tens of popups, not thousands; type and the switch
            // are filtered below, so the page is cut after them.
            'posts_per_page' => 1000,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $rows = array_map(static fn (\WP_Post $p): array => self::view($p, false), Readable_Posts::get_posts($query));
        // Type and the switch sit in a serialized array under one of two
        // keys, so they are matched here rather than in SQL.
        if (isset($args['type']) && '' !== (string) $args['type']) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === (string) $args['type']));
        }
        if (isset($args['active'])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['active'] === (bool) $args['active']));
        }
        return [ 'popups' => array_slice($rows, ($page - 1) * $per_page, $per_page), 'total' => count($rows) ];
    }

    // ------------------------------------------------------------ writes

    /** The stored value of one rule, as SGPopup::targetSave() keeps it. */
    private static function store_rule(array $rule): array
    {
        $param = (string) $rule['param'];
        $out   = [ 'param' => $param, 'operator' => (string) ($rule['operator'] ?? '==') ];
        if (isset(self::POST_RULES[ $param ])) {
            $out['value'] = [];
            foreach ((array) $rule['post_ids'] as $pid) {
                $out['value'][ (int) $pid ] = (string) get_post((int) $pid)->post_title;
            }
        } elseif ('post_category' === $param) {
            $out['value'] = [];
            foreach ((array) $rule['term_ids'] as $tid) {
                $out['value'][ (int) $tid ] = (string) get_term((int) $tid, 'category')->name;
            }
        } elseif ('post_tags_ids' === $param) {
            // Tags are matched by slug (ConfigDataHelper::getTagsBySlug()).
            $out['value'] = [];
            foreach ((array) $rule['term_ids'] as $tid) {
                $term                         = get_term((int) $tid, 'post_tag');
                $out['value'][ $term->slug ] = (string) $term->name;
            }
        } elseif ('post_type' === $param || 'page_type' === $param) {
            $out['value'] = array_values(array_unique(array_map('strval', (array) $rule['values'])));
        }
        return $out;
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
        if (isset($args['rules'])) {
            $key                     = self::key($id, self::TARGET);
            $target                  = self::meta($id, self::TARGET);
            $target['sgpb-target']   = [ 0 => array_map([ self::class, 'store_rule' ], array_values((array) $args['rules'])) ];
            $target['sgpb-conditions'] = $target['sgpb-conditions'] ?? [];
            update_post_meta($id, $key, $target);
        }
        clean_post_cache($id);

        return [ 'popup' => self::view(get_post($id), true) ];
    }

    /** @return array<string, mixed> */
    private static function set_active(int $id, bool $on): array
    {
        $options                   = self::meta($id, self::OPTIONS);
        $options['sgpb-is-active'] = $on ? 'on' : '';
        update_post_meta($id, self::key($id, self::OPTIONS), $options);
        clean_post_cache($id);

        return [ 'popup' => self::view(get_post($id), false) ];
    }
}
