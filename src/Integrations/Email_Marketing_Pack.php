<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Privacy\Masking;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Email marketing connectors (issue #900): MailerLite, Klaviyo, Kit
 * (ConvertKit), Omnisend and ActiveCampaign, paid-tier ops on the
 * plugin-data pair, so they add no top-level tool.
 *
 * Reads, one per plugin, report the connection (connected true or false and
 * at most the last four characters of a key, never a token or secret), the
 * account, the forms and the lists, groups or tags they feed, and where the
 * forms are placed. No subscriber data is read.
 *
 *  - mailerlite-get-settings: platform, account, double opt-in and popups
 *    flags and the signup forms from the plugin's network-wide
 *    mailerlite_forms table (custom forms with their groups, embedded forms
 *    with their webform id) and the posts holding their shortcode or block.
 *  - klaviyo-get-settings: the checkout email and SMS signup (checkbox, list
 *    id, label) from 'klaviyo_settings', and the WooCommerce link.
 *  - kit-get-settings: account, cached forms, tag count, the default form
 *    per post type, the site-wide (modal, slide in, sticky bar) forms, and
 *    the posts that set their own form or tag or hold a form shortcode or
 *    block.
 *  - omnisend-get-settings: connection, connected domain and the last error
 *    in the plugin's omnisend_logs table (emails masked). Omnisend keeps
 *    lists and sync state in its own app.
 *  - activecampaign-get-settings: account, the forms the site loaded and
 *    which of them are enabled, and site tracking.
 *
 * Writes change local options only, each snapshotted on its option so
 * rollback-operation puts it back exactly, and stay off until the site opts
 * in through wpmcp_enable_email_marketing_write:
 *
 *  - klaviyo-set-checkout-signup: the email and SMS list ids, checkboxes and
 *    the email label in 'klaviyo_settings'.
 *  - kit-set-default-forms: the default form per post type and the site-wide
 *    forms in '_wp_convertkit_settings', form ids checked against the
 *    plugin's form cache.
 *
 * Double opt-in is reported but not written: every one of these platforms
 * keeps it in its own account (MailerLite's local flag is overwritten from
 * the account on the next settings screen load), so a local change would
 * not hold and no local snapshot could undo a remote one. MailerLite's form
 * groups sit in a network-wide table and ActiveCampaign and Omnisend have no
 * list mapping in WordPress, so those three are read only.
 *
 * Every op runs at manage_options. Without the plugin the ops answer
 * plugin_missing or plugin_inactive naming it. Presence is filterable
 * (wpmcp_mailerlite_active, wpmcp_klaviyo_active, wpmcp_kit_active,
 * wpmcp_omnisend_active, wpmcp_activecampaign_active).
 */
final class Email_Marketing_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_email_marketing_write';

    private const KLAVIYO_OPTION = 'klaviyo_settings';
    private const KIT_OPTION     = '_wp_convertkit_settings';

    /** Posts a placement scan reads at most, per plugin. */
    private const PLACEMENT_LIMIT = 50;

    /** Kit form formats its site-wide (non-inline) setting accepts. */
    private const KIT_SITE_WIDE_FORMATS = [ 'modal', 'slide in', 'sticky bar' ];

    private const DOUBLE_OPT_IN_NOTE = '%s keeps double opt-in on the list or form in its own account; change it there.';

    public static function mailerlite_active(): bool
    {
        return (bool) apply_filters('wpmcp_mailerlite_active', defined('MAILERLITE_VERSION'));
    }

    public static function klaviyo_active(): bool
    {
        return (bool) apply_filters('wpmcp_klaviyo_active', defined('KLAVIYO_PLUGIN_VERSION') || class_exists('WooCommerceKlaviyo'));
    }

    public static function kit_active(): bool
    {
        return (bool) apply_filters('wpmcp_kit_active', defined('CONVERTKIT_PLUGIN_VERSION'));
    }

    public static function omnisend_active(): bool
    {
        return (bool) apply_filters('wpmcp_omnisend_active', defined('OMNISEND_WOO_PLUGIN_FILE'));
    }

    public static function activecampaign_active(): bool
    {
        return (bool) apply_filters('wpmcp_activecampaign_active', function_exists('activecampaign_plugin_options'));
    }

    /** Whether any of the plugins is usable and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && (
            self::mailerlite_active() || self::klaviyo_active() || self::kit_active()
            || self::omnisend_active() || self::activecampaign_active()
        );
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $ops = [];
        foreach (self::groups() as [ , $group ]) {
            $ops += $group;
        }
        return $ops;
    }

    /**
     * Each plugin's label and its ops, for the dispatcher's host list.
     *
     * @return array<int, array{0: string, 1: array<string, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_email_marketing_write', false);
        $empty   = [ 'type' => 'object', 'properties' => [] ];
        $base    = static fn (string $plugin, callable $active): array => [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => $active() ? true : self::refusal($plugin),
        ];
        $write   = [
            'mode'               => 'write',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];
        $list_id = [ 'type' => 'string', 'maxLength' => 64 ];

        $mailerlite = $base('MailerLite', [ self::class, 'mailerlite_active' ]);
        $klaviyo    = $base('Klaviyo', [ self::class, 'klaviyo_active' ]);
        $kit        = $base('Kit', [ self::class, 'kit_active' ]);
        $omnisend   = $base('Omnisend', [ self::class, 'omnisend_active' ]);
        $ac         = $base('ActiveCampaign', [ self::class, 'activecampaign_active' ]);

        return [
            [ 'MailerLite', [
                'mailerlite-get-settings' => $mailerlite + [
                    'mode'         => 'read',
                    'description'  => 'MailerLite connection, double opt-in, signup forms with their groups and the posts that place them. API key never returned',
                    'input_schema' => $empty,
                    'handler'      => static fn (): array => self::mailerlite_settings(),
                ],
            ] ],
            [ 'Klaviyo', [
                'klaviyo-get-settings'        => $klaviyo + [
                    'mode'         => 'read',
                    'description'  => 'Klaviyo connection and checkout email and SMS signup (list ids, checkboxes, label)',
                    'input_schema' => $empty,
                    'handler'      => static fn (): array => self::klaviyo_settings($enabled),
                ],
                'klaviyo-set-checkout-signup' => $klaviyo + $write + [
                    'description'  => 'Set Klaviyo checkout signup: newsletter_enabled, newsletter_list_id, newsletter_text, sms_enabled, sms_list_id. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_email_marketing_write filter)',
                    'input_schema' => [
                        'type'          => 'object',
                        'properties'    => [
                            'newsletter_enabled' => [ 'type' => 'boolean' ],
                            'newsletter_list_id' => $list_id,
                            'newsletter_text'    => [ 'type' => 'string', 'maxLength' => 500 ],
                            'sms_enabled'        => [ 'type' => 'boolean' ],
                            'sms_list_id'        => $list_id,
                        ],
                        'minProperties' => 1,
                    ],
                    'validate'     => static fn (array $args): ?array => self::klaviyo_refusal($args),
                    'snapshot'     => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::KLAVIYO_OPTION ],
                    'handler'      => static fn (array $args): array => self::klaviyo_set($args),
                ],
            ] ],
            [ 'Kit', [
                'kit-get-settings'      => $kit + [
                    'mode'         => 'read',
                    'description'  => 'Kit (ConvertKit) account, forms, default form per post type, site-wide forms and per-post form and tag settings. Tokens never returned',
                    'input_schema' => $empty,
                    'handler'      => static fn (): array => self::kit_settings($enabled),
                ],
                'kit-set-default-forms' => $kit + $write + [
                    'description'  => 'Set Kit default_forms ({post_type: form id, 0 for none}) and/or site_wide_forms (modal, slide in, sticky bar form ids). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_email_marketing_write filter)',
                    'input_schema' => [
                        'type'          => 'object',
                        'properties'    => [
                            'default_forms'   => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'integer', 'minimum' => 0 ] ],
                            'site_wide_forms' => [ 'type' => 'array', 'maxItems' => 20, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                        ],
                        'minProperties' => 1,
                    ],
                    'validate'     => static fn (array $args): ?array => self::kit_refusal($args),
                    'snapshot'     => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::KIT_OPTION ],
                    'handler'      => static fn (array $args): array => self::kit_set($args),
                ],
            ] ],
            [ 'Omnisend', [
                'omnisend-get-settings' => $omnisend + [
                    'mode'         => 'read',
                    'description'  => 'Omnisend connection, connected domain and last sync error (emails masked). API key never returned',
                    'input_schema' => $empty,
                    'handler'      => static fn (): array => self::omnisend_settings(),
                ],
            ] ],
            [ 'ActiveCampaign', [
                'activecampaign-get-settings' => $ac + [
                    'mode'         => 'read',
                    'description'  => 'ActiveCampaign connection, account, forms (enabled or not, shortcode) and site tracking. API key never returned',
                    'input_schema' => $empty,
                    'handler'      => static fn (): array => self::activecampaign_settings(),
                ],
            ] ],
        ];
    }

    /**
     * plugin_missing or plugin_inactive naming the plugin and the plugins screen.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    private static function refusal(string $plugin): array
    {
        $refusal         = Plugin_Refusal::make('plugin_missing', $plugin);
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /** A credential shown as its last four characters, null when not set. */
    private static function tail($key): ?string
    {
        $key = is_scalar($key) ? trim((string) $key) : '';
        if ('' === $key) {
            return null;
        }
        return '****' . (strlen($key) > 4 ? substr($key, -4) : '');
    }

    /** @return array<string, mixed> */
    private static function option_array(string $name): array
    {
        $value = get_option($name, []);
        return is_array($value) ? $value : [];
    }

    /** A serialized value from a plugin table, objects read as arrays. */
    private static function unserialize_row($data): array
    {
        if (! is_string($data) || ! is_serialized($data)) {
            return is_array($data) ? $data : [];
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- plain data rows; objects limited to stdClass.
        $value = @unserialize($data, [ 'allowed_classes' => [ 'stdClass' ] ]);
        return is_array($value) ? json_decode((string) wp_json_encode($value), true) ?? [] : [];
    }

    /**
     * Posts the current user may read whose content contains one of the
     * needles, newest first, at most PLACEMENT_LIMIT.
     *
     * @param string[] $needles
     * @return \WP_Post[]
     */
    private static function posts_containing(array $needles): array
    {
        global $wpdb;

        $likes = [];
        $args  = [];
        foreach ($needles as $needle) {
            $likes[] = 'post_content LIKE %s';
            $args[]  = '%' . $wpdb->esc_like($needle) . '%';
        }
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND (" . implode(' OR ', $likes) . ') ORDER BY ID DESC LIMIT 200';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql holds only fixed SQL and one placeholder per needle.
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$args));
        return self::readable(array_map('intval', (array) $ids));
    }

    /**
     * @param int[] $ids
     * @return \WP_Post[]
     */
    private static function readable(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        return array_values(array_filter(Readable_Posts::get_posts([
            'post__in'       => $ids,
            'post_type'      => 'any',
            'posts_per_page' => self::PLACEMENT_LIMIT,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ]), static fn ($post): bool => $post instanceof \WP_Post));
    }

    /** @return array<string, mixed> */
    private static function placement(\WP_Post $post, string $via): array
    {
        return [
            'post_id'   => (int) $post->ID,
            'title'     => (string) $post->post_title,
            'post_type' => (string) $post->post_type,
            'status'    => (string) $post->post_status,
            'via'       => $via,
        ];
    }

    /**
     * Whether WooCommerce is active and the REST API key the platform's app
     * created (described "<app> - API (...)" by WooCommerce) exists and was used.
     *
     * @return array<string, mixed>
     */
    private static function woocommerce_key(string $app): array
    {
        global $wpdb;

        if (! class_exists('WooCommerce')) {
            return [ 'active' => false ];
        }
        $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT permissions, last_access FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s ORDER BY key_id DESC LIMIT 1",
            $wpdb->esc_like($app) . '%'
        ), ARRAY_A);
        $wpdb->suppress_errors(false);

        return [
            'active'        => true,
            'api_key_found' => is_array($row),
            'permissions'   => is_array($row) ? (string) $row['permissions'] : null,
            'last_access'   => is_array($row) && ! empty($row['last_access']) ? (string) $row['last_access'] : null,
        ];
    }

    // ---------------------------------------------------------------
    // MailerLite
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function mailerlite_settings(): array
    {
        $key      = get_option('mailerlite_api_key', '');
        $platform = (int) get_option('mailerlite_platform', 0);

        return [
            'connected'      => '' !== trim((string) $key),
            'api_key'        => self::tail($key),
            'platform'       => 2 === $platform ? 'MailerLite' : (1 === $platform ? 'MailerLite Classic' : null),
            'account'        => [
                'id'        => '' !== (string) get_option('account_id', '') ? (string) get_option('account_id') : null,
                'subdomain' => '' !== (string) get_option('account_subdomain', '') ? (string) get_option('account_subdomain') : null,
            ],
            'double_opt_in'  => [
                'enabled' => ! get_option('mailerlite_double_optin_disabled'),
                'note'    => sprintf(self::DOUBLE_OPT_IN_NOTE, 'MailerLite') . ' The plugin copies it from the account when its settings screen loads.',
            ],
            'popups_enabled' => ! get_option('mailerlite_popups_disabled'),
            'forms'          => self::mailerlite_forms(),
            'writable'       => false,
            'note'           => 'Form groups are edited in the MailerLite plugin screen or the MailerLite account; the forms table is shared by every site of a network.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function mailerlite_forms(): array
    {
        global $wpdb;

        $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results("SELECT id, time, name, type, data FROM {$wpdb->base_prefix}mailerlite_forms ORDER BY id ASC LIMIT 200", ARRAY_A);
        $wpdb->suppress_errors(false);
        if (! is_array($rows) || [] === $rows) {
            return [];
        }

        $placed = [];
        foreach (self::posts_containing([ '[mailerlite_form', 'wp:mailerlite/form-block' ]) as $post) {
            $content = (string) $post->post_content;
            if (preg_match_all('/\[mailerlite_form\b[^\]]*\bform_id=["\']?(\d+)/', $content, $m)) {
                foreach (array_unique($m[1]) as $id) {
                    $placed[ (int) $id ][] = self::placement($post, 'shortcode');
                }
            }
            if (preg_match_all('/<!--\s*wp:mailerlite\/form-block\s+(\{.*?\})\s*\/?-->/s', $content, $m)) {
                foreach ($m[1] as $json) {
                    $attrs = json_decode($json, true);
                    if (is_array($attrs) && isset($attrs['form_id'])) {
                        $placed[ (int) $attrs['form_id'] ][] = self::placement($post, 'block');
                    }
                }
            }
        }

        $forms = [];
        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $data = self::unserialize_row($row['data']);
            $form = [
                'id'         => $id,
                'name'       => (string) $row['name'],
                'type'       => 2 === (int) $row['type'] ? 'embedded' : 'custom',
                'created'    => (string) $row['time'],
                'shortcode'  => sprintf('[mailerlite_form form_id=%d]', $id),
            ];
            if ('custom' === $form['type']) {
                $names = [];
                foreach ((array) ($data['selected_groups'] ?? []) as $group) {
                    if (is_array($group) && isset($group['id'])) {
                        $names[ (string) $group['id'] ] = (string) ($group['name'] ?? '');
                    }
                }
                $form['groups'] = array_values(array_map(
                    static fn ($gid): array => [ 'id' => (string) $gid, 'name' => $names[ (string) $gid ] ?? null ],
                    array_filter((array) ($data['lists'] ?? []), 'is_scalar')
                ));
            } else {
                $form['webform_id'] = isset($data['id']) ? (string) $data['id'] : null;
                $form['groups_note'] = 'An embedded form subscribes to the groups set on the form in the MailerLite account.';
            }
            $form['placements'] = $placed[ $id ] ?? [];
            $forms[]            = $form;
        }
        return $forms;
    }

    // ---------------------------------------------------------------
    // Klaviyo
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function klaviyo_shape(array $settings): array
    {
        $list = static fn ($id): ?string => '' !== trim((string) $id) ? trim((string) $id) : null;

        return [
            'email_signup' => [
                'enabled' => ! empty($settings['klaviyo_subscribe_checkbox']),
                'list_id' => $list($settings['klaviyo_newsletter_list_id'] ?? ''),
                'text'    => (string) ($settings['klaviyo_newsletter_text'] ?? ''),
            ],
            'sms_signup'   => [
                'enabled' => ! empty($settings['klaviyo_sms_subscribe_checkbox']),
                'list_id' => $list($settings['klaviyo_sms_list_id'] ?? ''),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function klaviyo_settings(bool $enabled): array
    {
        $settings = self::option_array(self::KLAVIYO_OPTION);

        return [
            'connected'      => '' !== trim((string) ($settings['klaviyo_public_api_key'] ?? '')),
            'public_api_key' => self::tail($settings['klaviyo_public_api_key'] ?? ''),
        ] + self::klaviyo_shape($settings) + [
            'double_opt_in'  => [ 'note' => sprintf(self::DOUBLE_OPT_IN_NOTE, 'Klaviyo') ],
            'woocommerce'    => self::woocommerce_key('Klaviyo') + [ 'note' => 'Klaviyo pulls orders and products through the WooCommerce REST API; sync status and errors show in the Klaviyo WooCommerce integration page.' ],
            'writes_enabled' => $enabled,
        ];
    }

    /** @return array<string, string> option key => new value, for the fields the call names. */
    private static function klaviyo_requested(array $args): array
    {
        $map = [];
        foreach ([ 'newsletter_enabled' => 'klaviyo_subscribe_checkbox', 'sms_enabled' => 'klaviyo_sms_subscribe_checkbox' ] as $arg => $key) {
            if (array_key_exists($arg, $args)) {
                $map[ $key ] = $args[ $arg ] ? 'true' : '';
            }
        }
        foreach ([ 'newsletter_list_id' => 'klaviyo_newsletter_list_id', 'sms_list_id' => 'klaviyo_sms_list_id' ] as $arg => $key) {
            if (array_key_exists($arg, $args)) {
                $map[ $key ] = trim((string) $args[ $arg ]);
            }
        }
        if (array_key_exists('newsletter_text', $args)) {
            $map['klaviyo_newsletter_text'] = trim(sanitize_text_field((string) $args['newsletter_text']));
        }
        return $map;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function klaviyo_refusal(array $args): ?array
    {
        $map = self::klaviyo_requested($args);
        if ([] === $map) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass at least one of newsletter_enabled, newsletter_list_id, newsletter_text, sms_enabled, sms_list_id.' ];
        }
        foreach ([ 'klaviyo_newsletter_list_id', 'klaviyo_sms_list_id' ] as $key) {
            if (isset($map[ $key ]) && '' !== $map[ $key ] && 1 !== preg_match('/^[A-Za-z0-9]{1,64}$/', $map[ $key ])) {
                return [ 'code' => 'invalid_list_id', 'message' => sprintf('"%s" is not a Klaviyo list id (letters and digits, as in the list URL).', $map[ $key ]) ];
            }
        }
        $current = self::option_array(self::KLAVIYO_OPTION);
        $next    = array_replace($current, $map);
        foreach ([ 'klaviyo_subscribe_checkbox' => 'klaviyo_newsletter_list_id', 'klaviyo_sms_subscribe_checkbox' => 'klaviyo_sms_list_id' ] as $box => $list) {
            if (! empty($next[ $box ]) && '' === trim((string) ($next[ $list ] ?? ''))) {
                return [ 'code' => 'list_id_required', 'message' => sprintf('Set %s before turning that checkbox on; Klaviyo needs a list to subscribe to.', 'klaviyo_sms_list_id' === $list ? 'sms_list_id' : 'newsletter_list_id') ];
            }
        }
        $email = trim((string) ($next['klaviyo_newsletter_list_id'] ?? ''));
        if ('' !== $email && trim((string) ($next['klaviyo_sms_list_id'] ?? '')) === $email) {
            return [ 'code' => 'same_list_ids', 'message' => 'The email and SMS lists must differ, as the Klaviyo settings screen requires.' ];
        }
        $changed = false;
        foreach ($map as $key => $value) {
            $changed = $changed || (string) ($current[ $key ] ?? '') !== $value;
        }
        if (! $changed) {
            return [ 'code' => 'no_change', 'message' => 'Those Klaviyo signup settings are already set; nothing to change.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function klaviyo_set(array $args): array
    {
        $settings = array_replace(self::option_array(self::KLAVIYO_OPTION), self::klaviyo_requested($args));
        update_option(self::KLAVIYO_OPTION, $settings);

        return self::klaviyo_shape(self::option_array(self::KLAVIYO_OPTION)) + [
            'check' => 'Open the checkout and confirm the signup checkbox and label; undo with rollback-operation if not.',
        ];
    }

    // ---------------------------------------------------------------
    // Kit
    // ---------------------------------------------------------------

    /** @return string[] Post types Kit offers a default form for. */
    private static function kit_post_types(): array
    {
        if (function_exists('convertkit_get_supported_post_types')) {
            return array_values(array_map('strval', (array) convertkit_get_supported_post_types()));
        }
        $types = array_merge([ 'page', 'post' ], array_values(get_post_types([ 'public' => true, '_builtin' => false ])));
        return array_values(array_unique(array_diff($types, [ 'attachment', 'elementor_library', 'e-landing-page', 'jet-engine', 'jet-menu' ])));
    }

    /** @return array<int, array<string, mixed>> form id => form, from Kit's cache. */
    private static function kit_forms(): array
    {
        $forms = [];
        foreach (self::option_array('convertkit_forms') as $id => $form) {
            if (is_array($form)) {
                $forms[ (int) ($form['id'] ?? $id) ] = $form;
            }
        }
        ksort($forms);
        return $forms;
    }

    /** @return array<string, mixed> */
    private static function kit_shape(array $settings): array
    {
        $defaults = [];
        foreach (self::kit_post_types() as $type) {
            $defaults[ $type ] = (int) ($settings[ $type . '_form' ] ?? 0);
        }
        return [
            'default_forms'   => $defaults,
            'site_wide_forms' => array_values(array_map('intval', array_filter((array) ($settings['non_inline_form'] ?? []), 'is_numeric'))),
        ];
    }

    /** @return array<string, mixed> */
    private static function kit_settings(bool $enabled): array
    {
        $settings = self::option_array(self::KIT_OPTION);
        $oauth    = '' !== (string) ($settings['access_token'] ?? '');
        $legacy   = '' !== (string) ($settings['api_key'] ?? '');
        $account  = self::option_array('convertkit_account');
        $details  = is_array($account['account'] ?? null) ? $account['account'] : [];

        $forms = [];
        foreach (self::kit_forms() as $id => $form) {
            $forms[] = [
                'id'     => $id,
                'name'   => (string) ($form['name'] ?? ''),
                'format' => (string) ($form['format'] ?? 'inline'),
            ];
        }

        return [
            'connected'  => $oauth || $legacy,
            'auth'       => $oauth ? 'oauth' : ($legacy ? 'api_key' : null),
            'api_key'    => $legacy ? self::tail($settings['api_key']) : null,
            'account'    => [] === $details ? null : [
                'id'    => isset($details['id']) ? (int) $details['id'] : null,
                'name'  => (string) ($details['name'] ?? ''),
                'plan'  => isset($details['plan_type']) ? (string) $details['plan_type'] : null,
                'email' => isset($details['primary_email_address']) ? (string) $details['primary_email_address'] : null,
            ],
            'forms'      => $forms,
            'tag_count'  => count(self::option_array('convertkit_tags')),
        ] + self::kit_shape($settings) + [
            'placements'     => self::kit_placements(),
            'double_opt_in'  => [ 'note' => sprintf(self::DOUBLE_OPT_IN_NOTE, 'Kit') ],
            'writes_enabled' => $enabled,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function kit_placements(): array
    {
        global $wpdb;

        $rows = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT 500",
            '_wp_convertkit_post_meta'
        ), ARRAY_A);
        $settings = [];
        foreach ((array) $meta as $row) {
            $value = self::unserialize_row($row['meta_value']);
            $form  = (int) ($value['form'] ?? -1);
            $tag   = (int) ($value['tag'] ?? 0);
            if ($form >= 0 || $tag > 0) {
                $settings[ (int) $row['post_id'] ] = [ $form, $tag ];
            }
        }
        foreach (self::readable(array_keys($settings)) as $post) {
            [ $form, $tag ] = $settings[ $post->ID ];
            $rows[]         = self::placement($post, 'post_setting') + [
                'form_id' => $form >= 0 ? $form : null,
                'tag_id'  => $tag > 0 ? $tag : null,
            ];
        }

        foreach (self::posts_containing([ '[convertkit', '[kit', 'wp:convertkit/form' ]) as $post) {
            $content = (string) $post->post_content;
            $found   = [];
            if (preg_match_all('/\[(?:convertkit|kit)(?:_form)?\b[^\]]*\bform=["\']?(\d+)/', $content, $m)) {
                foreach ($m[1] as $id) {
                    $found[ (int) $id ] = 'shortcode';
                }
            }
            if (preg_match_all('/<!--\s*wp:convertkit\/form\s+(\{.*?\})\s*\/?-->/s', $content, $m)) {
                foreach ($m[1] as $json) {
                    $attrs = json_decode($json, true);
                    if (is_array($attrs) && (int) ($attrs['form'] ?? 0) > 0) {
                        $found[ (int) $attrs['form'] ] = 'block';
                    }
                }
            }
            foreach ($found as $id => $via) {
                $rows[] = self::placement($post, $via) + [ 'form_id' => $id, 'tag_id' => null ];
            }
        }
        return array_slice($rows, 0, self::PLACEMENT_LIMIT * 2);
    }

    /** @return array<string, mixed> option key => new value, for the settings the call names. */
    private static function kit_requested(array $args): array
    {
        $map = [];
        foreach ((array) ($args['default_forms'] ?? []) as $type => $form) {
            $map[ sanitize_key((string) $type) . '_form' ] = (int) $form;
        }
        if (array_key_exists('site_wide_forms', $args)) {
            $map['non_inline_form'] = array_values(array_unique(array_map('intval', (array) $args['site_wide_forms'])));
        }
        return $map;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function kit_refusal(array $args): ?array
    {
        $map = self::kit_requested($args);
        if ([] === $map) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass default_forms, site_wide_forms or both.' ];
        }
        $types = self::kit_post_types();
        $forms = self::kit_forms();
        foreach ((array) ($args['default_forms'] ?? []) as $type => $form) {
            if (! in_array(sanitize_key((string) $type), $types, true)) {
                return [ 'code' => 'unknown_post_type', 'message' => sprintf('Kit has no default form setting for post type "%s".', $type), 'data' => [ 'post_types' => $types ] ];
            }
            if ((int) $form > 0 && [] !== $forms && ! isset($forms[ (int) $form ])) {
                return [ 'code' => 'unknown_form', 'message' => sprintf('Form %d is not in Kit\'s form list; refresh the forms in the Kit plugin settings if it is new.', (int) $form), 'data' => [ 'forms' => array_keys($forms) ] ];
            }
        }
        foreach ((array) ($map['non_inline_form'] ?? []) as $form) {
            if ([] !== $forms && ! in_array((string) ($forms[ $form ]['format'] ?? ''), self::KIT_SITE_WIDE_FORMATS, true)) {
                return [ 'code' => 'not_a_site_wide_form', 'message' => sprintf('Form %d is not a modal, slide in or sticky bar form, so it cannot show site-wide.', $form) ];
            }
        }
        $current = self::option_array(self::KIT_OPTION);
        $changed = false;
        foreach ($map as $key => $value) {
            $old     = 'non_inline_form' === $key ? array_values(array_map('intval', (array) ($current[ $key ] ?? []))) : (int) ($current[ $key ] ?? 0);
            $changed = $changed || $old !== $value;
        }
        if (! $changed) {
            return [ 'code' => 'no_change', 'message' => 'Those Kit form settings are already set; nothing to change.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function kit_set(array $args): array
    {
        $settings = array_replace(self::option_array(self::KIT_OPTION), self::kit_requested($args));
        update_option(self::KIT_OPTION, $settings);

        return self::kit_shape(self::option_array(self::KIT_OPTION)) + [
            'check' => 'Load a post of each changed type and check the form shows; undo with rollback-operation if not.',
        ];
    }

    // ---------------------------------------------------------------
    // Omnisend
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function omnisend_settings(): array
    {
        global $wpdb;

        $key     = get_option('omnisend_api_key', '');
        $account = (string) get_option('omnisend_account_id', '');

        $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $error = $wpdb->get_row("SELECT date, endpoint, message FROM {$wpdb->prefix}omnisend_logs WHERE type = 'error' ORDER BY id DESC LIMIT 1", ARRAY_A);
        $wpdb->suppress_errors(false);

        $last = null;
        if (is_array($error)) {
            $message = (string) preg_replace_callback(
                '/[^\s@<>"\']+@[^\s@<>"\']+\.[A-Za-z]{2,}/',
                static fn (array $m): string => Masking::email($m[0]),
                mb_substr((string) $error['message'], 0, 500)
            );
            $last = [ 'date' => (string) $error['date'], 'endpoint' => (string) $error['endpoint'], 'message' => $message ];
        }

        return [
            'connected'        => '' !== $account && '' !== trim((string) $key),
            'api_key'          => self::tail($key),
            'account_id'       => '' !== $account ? $account : null,
            'connected_domain' => '' !== (string) get_option('omnisend_connected_domain', '') ? (string) get_option('omnisend_connected_domain') : null,
            'plugin_version'   => '' !== (string) get_option('omnisend_plugin_version', '') ? (string) get_option('omnisend_plugin_version') : null,
            'woocommerce'      => self::woocommerce_key('Omnisend App'),
            'last_error'       => $last,
            'lists_note'       => 'Omnisend keeps lists, signup forms, double opt-in and sync state in the Omnisend app; WordPress holds only the connection.',
        ];
    }

    // ---------------------------------------------------------------
    // ActiveCampaign
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function activecampaign_settings(): array
    {
        $settings = self::option_array('settings_activecampaign');
        $enabled  = array_map('intval', (array) ($settings['form_id'] ?? []));
        $forms    = [];
        foreach ((array) ($settings['forms'] ?? []) as $key => $form) {
            if (! is_array($form)) {
                continue;
            }
            $id      = (int) ($form['id'] ?? $key);
            $forms[] = [
                'id'        => $id,
                'name'      => (string) ($form['name'] ?? ''),
                'enabled'   => in_array($id, $enabled, true),
                'shortcode' => sprintf('[activecampaign form=%d]', $id),
            ];
        }

        return [
            'connected'     => '' !== (string) ($settings['api_url'] ?? '') && '' !== (string) ($settings['api_key'] ?? ''),
            'api_key'       => self::tail($settings['api_key'] ?? ''),
            'account'       => '' !== (string) ($settings['account'] ?? '') ? (string) $settings['account'] : null,
            'forms'         => $forms,
            'site_tracking' => ! empty($settings['site_tracking']),
            'lists_note'    => 'Each ActiveCampaign form subscribes to the lists and sends the opt-in set on the form in ActiveCampaign; WordPress holds no list mapping.',
        ];
    }
}
