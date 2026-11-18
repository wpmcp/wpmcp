<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tracking and tag plugins (issue #899): GTM4WP, PixelYourSite, Meta Pixel
 * for WordPress and Microsoft Clarity, as free ops on the plugin-data pair,
 * so they add no top-level tool:
 *
 *  - tracking-get-settings: per active plugin (or the one `plugin` names)
 *    the container, pixel, measurement and project IDs, the events and
 *    integrations each one turns on, the data layer settings and the roles
 *    excluded from tracking. Access tokens, conversion API tokens and GTM
 *    environment auth strings are never returned, only whether each is set.
 *  - tracking-find-duplicates: every ID the plugins load plus the IDs hard
 *    coded in the active theme's files, and each ID that more than one of
 *    them loads (the double counting that hides in a site's tracking).
 *  - gtm4wp-set-settings: replace the GTM container IDs (an ID that stays
 *    keeps its environment, domain and path), the excluded roles, the data
 *    layer name, and switch named events, integrations and data layer
 *    fields on or off. Data layer fields that put personal data on the page
 *    (user email, name, id, registration date, visitor IP) and the
 *    WooCommerce and EDD customer and order data are read only here: turning
 *    them on stays a decision made on GTM4WP's own screen.
 *  - clarity-set-project-id: point Microsoft Clarity at another project.
 *
 * Both writes are snapshotted on the plugin's option, so rollback-operation
 * puts it back exactly, and are off until the site opts in through
 * wpmcp_enable_tracking_write. PixelYourSite and Meta Pixel are read here
 * only: PixelYourSite keeps its settings in its own table and the Meta
 * plugin's pixel comes from Meta's own connection flow.
 *
 * Every op runs at manage_options, the capability each plugin's settings
 * screen needs. Presence is filterable per plugin (wpmcp_gtm4wp_active,
 * wpmcp_pixelyoursite_active, wpmcp_meta_pixel_active, wpmcp_clarity_active).
 */
final class Tracking_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_tracking_write';

    private const GTM_OPTION     = 'gtm4wp-options';
    private const CLARITY_OPTION = 'clarity_project_id';
    private const META_OPTION    = 'facebook_business_extension_config';
    private const META_FBL4B     = 'facebook_fbl4b_config';

    /** Slug => display name, as Plugin_Refusal knows them. */
    private const PLUGINS = [
        'gtm4wp'        => 'GTM4WP',
        'pixelyoursite' => 'PixelYourSite',
        'meta-pixel'    => 'Meta Pixel for WordPress',
        'clarity'       => 'Microsoft Clarity',
    ];

    /** GTM4WP 2.x container ID pattern (ContainerRows::GTM_ID_PATTERN). */
    private const GTM_ID = '/^GTM-[A-Z0-9]+$/D';

    /** GTM4WP's data layer name rule (ContainerRows::JS_IDENTIFIER_PATTERN). */
    private const JS_IDENTIFIER = '/^[A-Za-z_$][A-Za-z0-9_$]*$/D';

    /** GTM4WP placements (GTM4WP_PLACEMENT_*). */
    private const PLACEMENTS = [ 0 => 'footer', 1 => 'body_open', 2 => 'body_open_auto', 3 => 'off' ];

    /** GTM4WP 2.1.1 boolean event settings. */
    private const GTM_EVENTS = [
        'event-form-move', 'event-form-move-filled-only', 'event-new-user-registration', 'event-user-logged-in',
        'event-vimeo', 'event-soundcloud', 'event-html5-media', 'event-dailymotion', 'event-mixcloud',
        'event-cloudflare-stream', 'event-wistia', 'event-jwplayer', 'event-videopress', 'event-spotify',
        'event-twitch', 'event-media-dynamic',
    ];

    /** GTM4WP 2.1.1 boolean integration switches. */
    private const GTM_INTEGRATIONS = [
        'integrate-wpcf7', 'integrate-wpcf7-ga4events',
        'integrate-woocommerce-track-enhanced-ecommerce', 'integrate-woocommerce-cart-content-in-datalayer',
        'integrate-woocommerce-remarketing-usesku', 'integrate-woocommerce-exclude-tax', 'integrate-woocommerce-exclude-shipping',
        'integrate-woocommerce-clear-ecommerce-datalayer', 'integrate-woocommerce-purchase-track-on-any-page',
        'integrate-edd-track-ecommerce', 'integrate-edd-cart-content-in-datalayer', 'integrate-edd-use-sku',
        'integrate-edd-exclude-tax', 'integrate-edd-clear-ecommerce-datalayer',
        'integrate-consent-mode', 'integrate-cookiebot', 'integrate-cookieyes',
        'integrate-woocommerce-customer-data', 'integrate-woocommerce-order-data',
        'integrate-edd-customer-data', 'integrate-edd-order-data',
    ];

    /** GTM4WP 2.1.1 boolean data layer fields. */
    private const GTM_DATALAYER = [
        'include-loggedin', 'include-userrole', 'include-userid', 'include-useremail', 'include-userregdate',
        'include-username', 'include-visitor-ip', 'include-posttype', 'include-categories', 'include-tags',
        'include-authorid', 'include-author', 'include-postdate', 'include-posttitle', 'include-postcount',
        'include-postid', 'include-postformat', 'include-postterms', 'include-searchdata', 'include-browserdata',
        'include-osdata', 'include-devicedata', 'include-siteid', 'include-sitename', 'include-content-word-count',
        'include-reading-time', 'include-modified-date', 'include-content-age', 'include-comment-count',
        'include-page-template', 'include-featured-image', 'include-page-hierarchy', 'include-post-sticky',
        'include-primary-category', 'include-page-language', 'include-parent-categories', 'include-master-language',
        'cache-safe-datalayer',
    ];

    /** GTM4WP 2.1.1 boolean container switches. */
    private const GTM_CONTAINER_FLAGS = [ 'gtm-load-gtm-early', 'gtm-no-console-log', 'gtm-production-only', 'blacklist-enable' ];

    /** Switches that put personal data on the page: readable, not writable here. */
    private const GTM_PERSONAL = [
        'include-userid', 'include-useremail', 'include-userregdate', 'include-username', 'include-visitor-ip',
        'integrate-woocommerce-customer-data', 'integrate-woocommerce-order-data',
        'integrate-edd-customer-data', 'integrate-edd-order-data',
    ];

    /** PixelYourSite 11 modules read here: network => [row name, ID key]. */
    private const PYS_NETWORKS = [
        'facebook' => [ 'pys_facebook', 'pixel_id' ],
        'ga'       => [ 'pys_ga', 'tracking_id' ],
        'gtm'      => [ 'pys_gtm', 'gtm_id' ],
    ];

    /** Flags this pack builds itself, exempt from the secret-key mask. */
    private const PLAIN = [ 'auth_set', 'access_token_set', 'server_api_token_set' ];

    /** Theme files scanned for hard coded snippets, and the most bytes read per file. */
    private const THEME_MAX_FILES = 400;
    private const THEME_MAX_BYTES = 1048576;

    public static function gtm4wp_active(): bool
    {
        return (bool) apply_filters('wpmcp_gtm4wp_active', defined('GTM4WP_VERSION'));
    }

    public static function pixelyoursite_active(): bool
    {
        return (bool) apply_filters('wpmcp_pixelyoursite_active', defined('PYS_FREE_VERSION') || defined('PYS_VERSION'));
    }

    public static function meta_pixel_active(): bool
    {
        return (bool) apply_filters('wpmcp_meta_pixel_active', class_exists('FacebookPixelPlugin\\FacebookForWordpress'));
    }

    public static function clarity_active(): bool
    {
        return (bool) apply_filters('wpmcp_clarity_active', function_exists('clarity_add_script_to_header'));
    }

    /** Whether any of the plugins is loaded. */
    public static function active(): bool
    {
        return [] !== self::active_slugs();
    }

    /** @return string[] */
    private static function active_slugs(): array
    {
        $checks = [
            'gtm4wp'        => self::gtm4wp_active(),
            'pixelyoursite' => self::pixelyoursite_active(),
            'meta-pixel'    => self::meta_pixel_active(),
            'clarity'       => self::clarity_active(),
        ];
        return array_keys(array_filter($checks));
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return self::shared_operations() + self::gtm4wp_operations() + self::clarity_operations();
    }

    /** @return array<string, array<string, mixed>> the reads every plugin answers. */
    public static function shared_operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_tracking_write', false);
        $any     = static fn () => self::active() ? true : Plugin_Refusal::any_of(
            'plugin_missing',
            'No tracking plugin is active (GTM4WP, PixelYourSite, Meta Pixel for WordPress or Microsoft Clarity).',
            [ 'plugins' => array_values(self::PLUGINS) ]
        );

        return [
            'tracking-get-settings'    => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'requires'     => $any,
                'description'  => 'Tracking IDs (GTM containers, pixels, GA, Clarity), enabled events, data layer settings and excluded roles per active tracking plugin; tokens masked. plugin narrows to one',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'plugin' => [ 'type' => 'string', 'enum' => array_keys(self::PLUGINS) ] ],
                ],
                'validate'     => static fn (array $args): ?array => self::plugin_refusal($args),
                'handler'      => static fn (array $args): array => self::settings($args, $enabled),
            ],
            'tracking-find-duplicates' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'requires'     => $any,
                'description'  => 'Every tracking ID the plugins load plus those hard coded in the theme, and each ID loaded more than once',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::duplicates(),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function gtm4wp_operations(): array
    {
        $enabled = (bool) apply_filters('wpmcp_enable_tracking_write', false);

        return [
            'gtm4wp-set-settings' => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'requires'           => static fn () => self::gtm4wp_active() ? true : self::refusal('GTM4WP'),
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'description'        => 'Set GTM4WP container_ids (GTM-XXXX), excluded_roles, datalayer_name and toggles (event, integration and data layer switches tracking-get-settings lists). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_tracking_write filter)',
                'input_schema'       => [
                    'type'          => 'object',
                    'properties'    => [
                        'container_ids'  => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => [ 'type' => 'string', 'maxLength' => 20 ] ],
                        'excluded_roles' => [ 'type' => 'array', 'maxItems' => 50, 'items' => [ 'type' => 'string', 'maxLength' => 100 ] ],
                        'datalayer_name' => [ 'type' => 'string', 'maxLength' => 64 ],
                        'toggles'        => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'boolean' ] ],
                    ],
                ],
                'validate'           => static fn (array $args): ?array => self::gtm_refusal($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::GTM_OPTION ],
                'handler'            => static fn (array $args): array => self::gtm_write($args),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function clarity_operations(): array
    {
        $enabled = (bool) apply_filters('wpmcp_enable_tracking_write', false);

        return [
            'clarity-set-project-id' => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'requires'           => static fn () => self::clarity_active() ? true : self::refusal('Microsoft Clarity'),
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'description'        => 'Point Microsoft Clarity at another project_id. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_tracking_write filter)',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [ 'project_id' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ] ],
                    'required'   => [ 'project_id' ],
                ],
                'validate'           => static fn (array $args): ?array => self::clarity_refusal($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::CLARITY_OPTION ],
                'handler'            => static fn (array $args): array => self::clarity_write($args),
            ],
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
        $refusal['code'] = 'inactive' === ($refusal['data']['state'] ?? '') ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function plugin_refusal(array $args): ?array
    {
        $slug = isset($args['plugin']) ? (string) $args['plugin'] : '';
        if ('' === $slug || in_array($slug, self::active_slugs(), true)) {
            return null;
        }
        return self::refusal(self::PLUGINS[ $slug ] ?? $slug);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function settings(array $args, bool $enabled): array
    {
        $slugs = isset($args['plugin']) ? [ (string) $args['plugin'] ] : self::active_slugs();
        $out   = [];
        foreach ($slugs as $slug) {
            switch ($slug) {
                case 'gtm4wp':
                    $out[ $slug ] = self::gtm_settings();
                    break;
                case 'pixelyoursite':
                    $out[ $slug ] = self::pys_settings();
                    break;
                case 'meta-pixel':
                    $out[ $slug ] = self::meta_settings();
                    break;
                case 'clarity':
                    $out[ $slug ] = [ 'project_id' => self::clarity_id(), 'writable' => true ];
                    break;
            }
        }

        return Ops_Status_Packs::redact([ 'plugins' => $out, 'writes_enabled' => $enabled ], self::PLAIN);
    }

    /** @return array<string, mixed> GTM4WP's stored option, or an empty array. */
    private static function gtm_stored(): array
    {
        $stored = get_option(self::GTM_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * The container rows GTM4WP loads: the 2.x rows, or before its migration
     * ran the 1.x comma separated gtm-code with the shared environment.
     *
     * @return array<int, array<string, string>>
     */
    private static function gtm_rows(array $stored): array
    {
        $columns = [ 'id', 'gtm_auth', 'gtm_preview', 'domain', 'path', 'no_id' ];
        $rows    = [];
        if (array_key_exists('gtm-containers', $stored)) {
            foreach ((array) $stored['gtm-containers'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($columns as $column) {
                    $clean[ $column ] = is_scalar($row[ $column ] ?? '') ? trim((string) ($row[ $column ] ?? '')) : '';
                }
                if ('' !== $clean['id']) {
                    $rows[] = $clean;
                }
            }
            return $rows;
        }
        foreach (array_filter(array_map('trim', explode(',', (string) ($stored['gtm-code'] ?? '')))) as $id) {
            $rows[] = [
                'id'          => $id,
                'gtm_auth'    => trim((string) ($stored['gtm-env-gtm-auth'] ?? '')),
                'gtm_preview' => trim((string) ($stored['gtm-env-gtm-preview'] ?? '')),
                'domain'      => trim((string) ($stored['gtm-domain-name'] ?? '')),
                'path'        => trim((string) ($stored['gtm-custom-path'] ?? '')),
                'no_id'       => '',
            ];
        }
        return $rows;
    }

    /** @return string[] */
    private static function roles_list($value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(array_map(static fn ($role): string => trim((string) $role), $list), 'strlen')));
    }

    /** @return array<string, bool> */
    private static function flags(array $stored, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[ $key ] = self::truthy($stored[ $key ] ?? false);
        }
        return $out;
    }

    /** @param mixed $value */
    private static function truthy($value): bool
    {
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), [ '', '0', 'false', 'no', 'off' ], true);
        }
        return (bool) $value;
    }

    /** @return array<string, mixed> */
    private static function gtm_settings(): array
    {
        $stored     = self::gtm_stored();
        $containers = [];
        foreach (self::gtm_rows($stored) as $row) {
            $containers[] = [
                'id'          => $row['id'],
                'environment' => [ 'preview' => $row['gtm_preview'], 'auth_set' => '' !== $row['gtm_auth'] ],
                'domain'      => $row['domain'],
                'path'        => $row['path'],
                'no_id'       => self::truthy($row['no_id']),
            ];
        }
        $placement = (int) ($stored['gtm-code-placement'] ?? 0);

        return [
            'containers'      => $containers,
            'hardcoded'       => defined('GTM4WP_HARDCODED_GTM_ID'),
            'placement'       => self::PLACEMENTS[ $placement ] ?? (string) $placement,
            'datalayer_name'  => '' === trim((string) ($stored['gtm-datalayer-variable-name'] ?? '')) ? 'dataLayer' : trim((string) $stored['gtm-datalayer-variable-name']),
            'excluded_roles'  => self::roles_list($stored['gtm-no-gtm-for-logged-in'] ?? ''),
            'container_flags' => self::flags($stored, self::GTM_CONTAINER_FLAGS),
            'events'          => self::flags($stored, self::GTM_EVENTS),
            'integrations'    => self::flags($stored, self::GTM_INTEGRATIONS),
            'datalayer'       => self::flags($stored, self::GTM_DATALAYER),
            'read_only'       => self::GTM_PERSONAL,
        ];
    }

    /**
     * One PixelYourSite settings row: its own table first, then the
     * wp_options row older versions used, as PYS\Settings reads them.
     *
     * @return array<string, mixed>
     */
    private static function pys_row(string $name): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pys_options';
        if (Ops_Status_Packs::table_exists($table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $name));
            if (null !== $raw) {
                $value = maybe_unserialize($raw);
                return is_array($value) ? $value : [];
            }
        }
        $legacy = get_option($name, []);
        return is_array($legacy) ? $legacy : [];
    }

    /** @return string[] */
    private static function id_list($value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(array_map(static fn ($id): string => trim((string) $id), $list), 'strlen')));
    }

    /** @return array<string, mixed> */
    private static function pys_settings(): array
    {
        $networks = [];
        foreach (self::PYS_NETWORKS as $network => [ $row, $key ]) {
            $values = self::pys_row($row);
            $events = [];
            foreach ($values as $name => $value) {
                if (is_string($name) && 'enabled' !== $name && str_ends_with($name, '_enabled') && is_scalar($value)) {
                    $events[ $name ] = self::truthy($value);
                }
            }
            $networks[ $network ] = [
                'enabled' => self::truthy($values['enabled'] ?? false),
                'ids'     => self::id_list($values[ $key ] ?? []),
                'events'  => $events,
            ];
            if ('facebook' === $network) {
                $token                                        = $values['server_access_api_token'] ?? '';
                $networks[ $network ]['server_api_token_set'] = is_scalar($token) ? '' !== trim((string) $token) : ! empty($token);
            }
        }
        $core = self::pys_row('pys_core');

        return [
            'networks'       => $networks,
            'excluded_roles' => self::roles_list($core['do_not_track_user_roles'] ?? []),
            'writable'       => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function meta_settings(): array
    {
        $config = get_option(self::META_OPTION, []);
        $config = is_array($config) ? $config : [];
        $fbl4b  = get_option(self::META_FBL4B, []);
        $fbl4b  = is_array($fbl4b) ? $fbl4b : [];
        $pixel  = trim((string) ($config['facebook_pixel_id'] ?? ''));
        $token  = '' !== trim((string) ($config['facebook_access_token'] ?? ''));

        return [
            'pixel_id'         => '' === $pixel || '0' === $pixel ? null : $pixel,
            'access_token_set' => $token,
            'connected'        => $token && self::truthy($config['facebook_is_fbe_installed'] ?? false),
            'fbl4b_pixel_id'   => '' === trim((string) ($fbl4b['fbl4b_pixel_id'] ?? '')) ? null : trim((string) $fbl4b['fbl4b_pixel_id']),
            'writable'         => false,
            'note'             => 'The Meta pixel is set through Meta\'s own connection flow on the plugin\'s settings screen.',
        ];
    }

    private static function clarity_id(): ?string
    {
        $id = get_option(self::CLARITY_OPTION, '');
        return is_string($id) && '' !== trim($id) ? trim($id) : null;
    }

    /**
     * Every ID the active plugins load, as [source, kind, id].
     *
     * @return array<int, array<string, string>>
     */
    private static function plugin_ids(): array
    {
        $ids    = [];
        $active = self::active_slugs();
        if (in_array('gtm4wp', $active, true)) {
            $placement = (int) (self::gtm_stored()['gtm-code-placement'] ?? 0);
            if (3 !== $placement) {
                foreach (self::gtm_rows(self::gtm_stored()) as $row) {
                    $ids[] = [ 'source' => 'gtm4wp', 'kind' => 'gtm', 'id' => strtoupper($row['id']) ];
                }
            }
        }
        if (in_array('pixelyoursite', $active, true)) {
            foreach (self::pys_settings()['networks'] as $network => $settings) {
                if (! $settings['enabled']) {
                    continue;
                }
                foreach ($settings['ids'] as $id) {
                    $kind  = 'facebook' === $network ? 'meta_pixel' : ('gtm' === $network ? 'gtm' : 'google_tag');
                    $ids[] = [ 'source' => 'pixelyoursite', 'kind' => $kind, 'id' => 'meta_pixel' === $kind ? $id : strtoupper($id) ];
                }
            }
        }
        if (in_array('meta-pixel', $active, true)) {
            $pixel = self::meta_settings()['pixel_id'];
            if (null !== $pixel) {
                $ids[] = [ 'source' => 'meta-pixel', 'kind' => 'meta_pixel', 'id' => (string) $pixel ];
            }
        }
        if (in_array('clarity', $active, true) && null !== self::clarity_id()) {
            $ids[] = [ 'source' => 'clarity', 'kind' => 'clarity', 'id' => (string) self::clarity_id() ];
        }
        return $ids;
    }

    /** @return string[] the active theme's directories, child first. */
    private static function theme_dirs(): array
    {
        $dirs = array_values(array_unique([ get_stylesheet_directory(), get_template_directory() ]));
        return array_values(array_filter((array) apply_filters('wpmcp_tracking_theme_dirs', $dirs), 'is_dir'));
    }

    /**
     * IDs hard coded in the theme's PHP, HTML and JS files: GTM containers,
     * gtag measurement IDs, fbq('init') pixels and Clarity tags.
     *
     * @return array{0: array<int, array<string, string>>, 1: bool}
     */
    private static function theme_ids(): array
    {
        $patterns = [
            'gtm'        => '/\bGTM-[A-Z0-9]{4,12}\b/',
            'google_tag' => '/\b(?:G|AW|UA)-[A-Z0-9-]{4,20}\b/',
            'meta_pixel' => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{6,20})[\'"]/',
            'clarity'    => '/(?:clarity\.ms\/tag\/|[\'"]clarity[\'"]\s*,\s*[\'"]script[\'"]\s*,\s*[\'"])([a-z0-9]{6,20})/i',
        ];
        $ids       = [];
        $scanned   = 0;
        $truncated = false;
        foreach (self::theme_dirs() as $dir) {
            $dir      = untrailingslashit($dir);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), [ 'node_modules', 'vendor', '.git' ], true))
                )
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), [ 'php', 'html', 'js' ], true) || str_ends_with($file->getFilename(), '.min.js')) {
                    continue;
                }
                if ($scanned >= self::THEME_MAX_FILES) {
                    $truncated = true;
                    break 2;
                }
                ++$scanned;
                $body = (string) file_get_contents($file->getPathname(), false, null, 0, self::THEME_MAX_BYTES);
                $rel  = ltrim(substr($file->getPathname(), strlen($dir)), '/');
                foreach ($patterns as $kind => $pattern) {
                    if (! preg_match_all($pattern, $body, $m)) {
                        continue;
                    }
                    foreach (array_unique($m[ isset($m[1]) ? 1 : 0 ]) as $id) {
                        $ids[] = [ 'source' => 'theme', 'kind' => $kind, 'id' => 'meta_pixel' === $kind || 'clarity' === $kind ? (string) $id : strtoupper((string) $id), 'file' => $rel ];
                    }
                }
            }
        }
        return [ $ids, $truncated ];
    }

    /** @return array<string, mixed> */
    private static function duplicates(): array
    {
        [ $theme, $truncated ] = self::theme_ids();
        $ids                   = array_merge(self::plugin_ids(), $theme);

        $by_id = [];
        foreach ($ids as $entry) {
            $by_id[ $entry['kind'] . '|' . $entry['id'] ][] = $entry['source'];
        }
        $duplicates = [];
        foreach ($by_id as $key => $sources) {
            if (count($sources) > 1) {
                [ $kind, $id ] = explode('|', $key, 2);
                $duplicates[]  = [ 'id' => $id, 'kind' => $kind, 'sources' => array_values(array_unique($sources)), 'count' => count($sources) ];
            }
        }

        $warnings = [];
        if ([] !== $duplicates) {
            $warnings[] = 'An ID loaded by more than one source fires its tags and events twice (double page views and purchases). Keep one source per ID.';
        }
        $gtm_sources = array_unique(array_column(array_filter($ids, static fn (array $e): bool => 'gtm' === $e['kind']), 'source'));
        if (count($gtm_sources) > 1) {
            $warnings[] = sprintf('GTM containers are loaded from %d sources (%s).', count($gtm_sources), implode(', ', $gtm_sources));
        }
        if ($truncated) {
            $warnings[] = sprintf('Only the first %d theme files were scanned.', self::THEME_MAX_FILES);
        }

        return [ 'ids' => $ids, 'duplicates' => $duplicates, 'warnings' => $warnings ];
    }

    // ---------------------------------------------------------------
    // GTM4WP write
    // ---------------------------------------------------------------

    /** @return string[] */
    private static function gtm_writable_toggles(): array
    {
        return array_values(array_diff(
            array_merge(self::GTM_EVENTS, self::GTM_INTEGRATIONS, self::GTM_DATALAYER, self::GTM_CONTAINER_FLAGS),
            self::GTM_PERSONAL
        ));
    }

    /** @return array<string, mixed> the option keys and values a call asks for. */
    private static function gtm_requested(array $args): array
    {
        $stored  = self::gtm_stored();
        $changes = [];
        if (isset($args['container_ids'])) {
            $existing = [];
            foreach (self::gtm_rows($stored) as $row) {
                $existing[ strtoupper($row['id']) ] = $row;
            }
            $rows = [];
            foreach (self::id_list(array_map('strtoupper', (array) $args['container_ids'])) as $id) {
                $rows[] = $existing[ $id ] ?? [ 'id' => $id, 'gtm_auth' => '', 'gtm_preview' => '', 'domain' => '', 'path' => '', 'no_id' => '' ];
            }
            $changes['gtm-containers'] = $rows;
        }
        if (isset($args['excluded_roles'])) {
            $changes['gtm-no-gtm-for-logged-in'] = implode(',', self::roles_list((array) $args['excluded_roles']));
        }
        if (isset($args['datalayer_name'])) {
            $changes['gtm-datalayer-variable-name'] = trim((string) $args['datalayer_name']);
        }
        foreach ((array) ($args['toggles'] ?? []) as $key => $value) {
            $changes[ (string) $key ] = (bool) $value;
        }
        return $changes;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function gtm_refusal(array $args): ?array
    {
        if (! isset($args['container_ids']) && ! isset($args['excluded_roles']) && ! isset($args['datalayer_name']) && empty($args['toggles'])) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass container_ids, excluded_roles, datalayer_name or toggles.' ];
        }
        if (isset($args['container_ids'])) {
            if (defined('GTM4WP_HARDCODED_GTM_ID')) {
                return [ 'code' => 'hardcoded_container', 'message' => 'The GTM container is hard coded in wp-config.php (GTM4WP_HARDCODED_GTM_ID); change it there.' ];
            }
            $bad = array_values(array_filter(
                array_map(static fn ($id): string => strtoupper(trim((string) $id)), (array) $args['container_ids']),
                static fn (string $id): bool => 1 !== preg_match(self::GTM_ID, $id)
            ));
            if ([] !== $bad) {
                return [ 'code' => 'invalid_gtm_id', 'message' => sprintf('Not a GTM container ID (GTM-XXXX): %s.', implode(', ', $bad)), 'data' => [ 'invalid' => $bad ] ];
            }
        }
        if (isset($args['excluded_roles'])) {
            $known   = array_keys(wp_roles()->roles);
            $unknown = array_values(array_diff(self::roles_list((array) $args['excluded_roles']), $known));
            if ([] !== $unknown) {
                return [ 'code' => 'unknown_role', 'message' => sprintf('Not a role on this site: %s.', implode(', ', $unknown)), 'data' => [ 'unknown' => $unknown, 'roles' => $known ] ];
            }
        }
        if (isset($args['datalayer_name']) && 1 !== preg_match(self::JS_IDENTIFIER, trim((string) $args['datalayer_name']))) {
            return [ 'code' => 'invalid_datalayer_name', 'message' => 'datalayer_name must be a JavaScript identifier (letters, digits, _ and $, not starting with a digit).' ];
        }
        $writable = self::gtm_writable_toggles();
        foreach (array_keys((array) ($args['toggles'] ?? [])) as $key) {
            if (! in_array((string) $key, $writable, true)) {
                $personal = in_array((string) $key, self::GTM_PERSONAL, true);
                return [
                    'code'    => 'setting_not_writable',
                    'message' => $personal
                        ? sprintf('%s puts personal data on every page; turn it on or off on GTM4WP\'s own settings screen.', $key)
                        : sprintf('%s is not a toggle gtm4wp-set-settings changes.', $key),
                    'data'    => [ 'writable' => $writable ],
                ];
            }
        }

        $stored  = self::gtm_stored();
        $changed = false;
        foreach (self::gtm_requested($args) as $key => $value) {
            if ('gtm-containers' === $key) {
                $changed = $changed || ! array_key_exists('gtm-containers', $stored) || $value !== self::gtm_rows($stored);
                continue;
            }
            $current = $stored[ $key ] ?? null;
            $changed = $changed || (is_bool($value) ? self::truthy($current) !== $value : (string) $current !== (string) $value);
        }
        if (! $changed) {
            return [ 'code' => 'no_change', 'message' => 'Every value is already set in GTM4WP. Nothing was written and no undo point was taken.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function gtm_write(array $args): array
    {
        $stored = self::gtm_stored();
        foreach (self::gtm_requested($args) as $key => $value) {
            $stored[ $key ] = $value;
        }
        update_option(self::GTM_OPTION, $stored);

        return Ops_Status_Packs::redact([
            'plugin'   => 'gtm4wp',
            'settings' => self::gtm_settings(),
            'check'    => 'Load a page on the front end (logged out) and check the GTM container loads once; undo with rollback-operation if not.',
        ], self::PLAIN);
    }

    // ---------------------------------------------------------------
    // Microsoft Clarity write
    // ---------------------------------------------------------------

    /** @return array{code: string, message: string}|null */
    private static function clarity_refusal(array $args): ?array
    {
        $id = trim((string) ($args['project_id'] ?? ''));
        if (1 !== preg_match('/\A[a-zA-Z0-9]+\z/', $id)) {
            return [ 'code' => 'invalid_project_id', 'message' => 'project_id must be the Clarity project ID (letters and digits only).' ];
        }
        if ($id === self::clarity_id()) {
            return [ 'code' => 'no_change', 'message' => sprintf('Clarity already uses project %s.', $id) ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function clarity_write(array $args): array
    {
        $before = self::clarity_id();
        update_option(self::CLARITY_OPTION, trim((string) $args['project_id']));

        return [
            'plugin'     => 'clarity',
            'before'     => $before,
            'project_id' => self::clarity_id(),
            'check'      => 'Recordings start under the new project; check the Clarity dashboard shows live visits.',
        ];
    }
}
