<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * OptinMonster (issue #685) on the plugin-data pair, free tier, so it adds no
 * top-level tools. Ops are prefixed optinmonster- because the pair is shared.
 *
 * OptinMonster campaigns are built and published in the OptinMonster app;
 * the WordPress plugin (2.17) syncs each one down as an `omapi` post
 * (OMAPI_Save: post_name the campaign slug, post_excerpt its app id,
 * post_content the embed) with the post meta _omapi_type, _omapi_ids and
 * _omapi_enabled (true while the campaign is active, deleted otherwise),
 * plus the WordPress display rules the plugin's own settings screen writes
 * (_omapi_automatic, _omapi_users, _omapi_never, _omapi_only,
 * _omapi_categories, _omapi_show, _omapi_test, _omapi_auto_location).
 * OMAPI_Rules hides a campaign whose _omapi_enabled is empty. Every
 * capability of the post type maps to the plugin's access capability
 * (manage_options, filterable through optin_monster_api_menu_cap).
 *
 * Reads: optinmonster-list-campaigns and -get-campaign (with the display
 * rules). Campaign posts hold no visitor data, so nothing needs masking.
 * Writes are off until a site opts in with wpmcp_enable_optinmonster_write:
 * enable-campaign sets _omapi_enabled and disable-campaign deletes it, the
 * same switch the plugin's settings screen and its sync use, each under a
 * post snapshot. A campaign cannot be created or designed here: that
 * happens in the OptinMonster app, and the next sync from the app sets
 * _omapi_enabled to the app's own status again.
 *
 * Presence is filterable (wpmcp_optinmonster_active, default: OMAPI_FILE is
 * defined or the OMAPI class is loaded; the plugin defines no OMAPI_VERSION).
 */
final class OptinMonster_Pack
{
    public const CAMPAIGN = 'omapi';

    public const OPT_IN_FILTER = 'wpmcp_enable_optinmonster_write';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'optinmonster/optin-monster-wp-api.php' ];

    /** The WordPress display rules, without the _omapi_ prefix. */
    private const RULES = [ 'users', 'never', 'only', 'categories', 'taxonomies', 'show', 'auto_location' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_optinmonster_active', defined('OMAPI_FILE') || class_exists('OMAPI', false));
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
                'OptinMonster is %s on this site. Install and activate OptinMonster (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'optinmonster', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $one      = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $base     = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $write    = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_optinmonster_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $campaign = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $target   = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $by_id    = static fn (array $args): ?array => self::campaign_refusal((int) $args['id']);

        return [
            'optinmonster-list-campaigns'   => $base + [
                'mode'         => 'read',
                'description'  => 'List OptinMonster campaigns synced to this site: id, slug, title, type, enabled, automatic, test_mode. Filter by enabled, type, search. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'enabled'  => [ 'type' => 'boolean' ],
                        'type'     => [ 'type' => 'string', 'maxLength' => 50 ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(),
                'handler'      => static fn (array $args): array => self::list_campaigns($args),
            ],
            'optinmonster-get-campaign'     => $base + [
                'mode'         => 'read',
                'objects'      => $campaign('read'),
                'description'  => 'Read one OptinMonster campaign with its WordPress display rules (users, never, only, categories, show, auto_location)',
                'input_schema' => $one,
                'validate'     => $by_id,
                'handler'      => static fn (array $args): array => [ 'campaign' => self::view(get_post((int) $args['id']), true) ],
            ],
            'optinmonster-enable-campaign'  => $write + [
                'objects'      => $campaign('write'),
                'description'  => 'Let an OptinMonster campaign show on this site. The next sync from the OptinMonster app may reset it to the app status. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => $by_id,
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::switch_campaign((int) $args['id'], true),
            ],
            'optinmonster-disable-campaign' => $write + [
                'objects'      => $campaign('write'),
                'description'  => 'Stop an OptinMonster campaign showing on this site. The next sync from the OptinMonster app may reset it to the app status. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => $by_id,
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::switch_campaign((int) $args['id'], false),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** OMAPI::access_capability(). */
    private static function capability(): string
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying OptinMonster's own filter, as OMAPI::access_capability() does; wpmcp adds no callback to it.
        $cap = apply_filters('optin_monster_api_menu_cap', 'manage_options', null);
        return is_string($cap) && '' !== $cap ? $cap : 'manage_options';
    }

    private static function cap_refusal(): ?array
    {
        $cap = self::capability();
        return current_user_can($cap) ? null : self::refusal('operation_denied', sprintf('This needs the %s capability (OptinMonster\'s access capability).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function campaign_refusal(int $id): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::CAMPAIGN !== $post->post_type || 'trash' === $post->post_status) {
            return self::refusal('campaign_not_found', sprintf('%d is not an OptinMonster campaign synced to this site.', $id), [ 'id' => $id ]);
        }
        return self::cap_refusal();
    }

    // ------------------------------------------------------------ reads

    /** @return mixed */
    private static function meta(int $id, string $key)
    {
        return get_post_meta($id, '_omapi_' . $key, true);
    }

    /** @return array<string, mixed> */
    private static function view(\WP_Post $post, bool $full): array
    {
        $id  = (int) $post->ID;
        $out = [
            'id'        => $id,
            'slug'      => (string) $post->post_name,
            'title'     => (string) $post->post_title,
            'type'      => (string) self::meta($id, 'type'),
            'enabled'   => ! empty(self::meta($id, 'enabled')),
            'automatic' => ! empty(self::meta($id, 'automatic')),
            'test_mode' => ! empty(self::meta($id, 'test')),
            'modified'  => (string) $post->post_modified,
        ];
        if ($full) {
            $rules = [];
            foreach (self::RULES as $key) {
                $value         = self::meta($id, $key);
                $rules[ $key ] = is_array($value) ? array_values($value) : ('' === $value || null === $value ? null : (string) $value);
            }
            $out['app_id']    = (string) $post->post_excerpt;
            $out['rules']     = $rules;
            $out['shortcode'] = ! empty(self::meta($id, 'shortcode'));
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_campaigns(array $args): array
    {
        $query = [
            'post_type'      => self::CAMPAIGN,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $meta = [];
        if (array_key_exists('enabled', $args)) {
            $meta[] = $args['enabled']
                ? [ 'key' => '_omapi_enabled', 'value' => [ '', '0' ], 'compare' => 'NOT IN' ]
                : [ 'relation' => 'OR', [ 'key' => '_omapi_enabled', 'compare' => 'NOT EXISTS' ], [ 'key' => '_omapi_enabled', 'value' => [ '', '0' ], 'compare' => 'IN' ] ];
        }
        if (isset($args['type']) && '' !== (string) $args['type']) {
            $meta[] = [ 'key' => '_omapi_type', 'value' => (string) $args['type'] ];
        }
        if ([] !== $meta) {
            $query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- OptinMonster keeps a campaign's type and switch in post meta.
        }
        $q = Readable_Posts::query($query);
        return [ 'campaigns' => array_map(static fn (\WP_Post $p): array => self::view($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    // ------------------------------------------------------------ writes

    /** @return array<string, mixed> */
    private static function switch_campaign(int $id, bool $on): array
    {
        if ($on) {
            update_post_meta($id, '_omapi_enabled', true);
        } else {
            delete_post_meta($id, '_omapi_enabled');
        }
        clean_post_cache($id);

        return [ 'campaign' => self::view(get_post($id), false) ];
    }
}
