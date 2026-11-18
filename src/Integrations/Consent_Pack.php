<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cookie consent banners (issue #686): Complianz, CookieYes and Cookie
 * Notice as free ops on the theme dispatcher pair, so they add no top-level
 * tool. One adapter per plugin (Consent_Complianz, Consent_CookieYes,
 * Consent_Cookie_Notice) says where its banner, texts and cookie list live.
 *
 *  - get-consent-banner-status: per consent plugin, installed, active,
 *    version, whether a banner shows, who manages it (the plugin or its web
 *    app), the banners, the cookie count and the last scan.
 *  - get-consent-settings: one plugin's allowlisted consent settings
 *    (credentials only as set or not, the account email masked) and the
 *    current banner texts with the keys update-consent-banner-texts takes.
 *  - list-consent-cookies: the cookies the plugin's scan found (Complianz,
 *    CookieYes); Cookie Notice keeps no list and says so.
 *  - update-consent-banner-texts: change banner texts. These are legal
 *    texts, so the write is off until the site opts in
 *    (wpmcp_enable_consent_write), the reply shows every text before and
 *    after, and it is snapshotted (the Cookie Notice option, or a db_rows
 *    before-image of the Complianz or CookieYes banner row); undo with
 *    rollback-operation.
 *  - start-consent-cookie-scan: restart the Complianz scan. Off until the
 *    site opts in, not snapshotted (it clears scan progress, nothing else),
 *    so it is destructive and needs confirm:true. CookieYes scans in its web
 *    app and Cookie Notice has no scan; both refuse with not_supported.
 *
 * Every op needs manage_options, the capability each plugin's settings
 * screen needs. With none of the three active the ops stay documented
 * (dependency_met:false) and answer plugin_missing or plugin_inactive.
 * plugin picks the consent plugin and may be left out when one is active.
 */
final class Consent_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_consent_write';

    private const MAX_COOKIES = 200;

    private const CHECK_NOTE = 'These are legal texts. Load the site in a private window to check the banner, and undo with rollback-operation if anything is wrong.';

    /** @return Consent_Adapter[] slug => adapter */
    public static function adapters(): array
    {
        $out = [];
        foreach ([ new Consent_Complianz(), new Consent_CookieYes(), new Consent_Cookie_Notice() ] as $adapter) {
            $out[ $adapter->slug() ] = $adapter;
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled  = (bool) apply_filters('wpmcp_enable_consent_write', false);
        $plugin   = [ 'type' => 'string', 'enum' => array_keys(self::adapters()) ];
        $base     = [
            'capability' => 'manage_options',
            'requires'   => static fn () => self::presence(),
        ];
        $write    = $base + [
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];
        $text_ids = [];
        foreach (self::adapters() as $adapter) {
            $text_ids = array_merge($text_ids, array_keys($adapter->text_keys()));
        }
        $text_ids = array_values(array_unique($text_ids));

        return [
            'get-consent-banner-status'   => $base + [
                'mode'         => 'read',
                'description'  => 'Cookie consent plugins (Complianz, CookieYes, Cookie Notice): per plugin, installed, active, version, whether a banner shows, who manages it (the plugin or its web app), its banners, cookie count and last scan',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::status(),
            ],
            'get-consent-settings'        => $base + [
                'mode'         => 'read',
                'description'  => 'One consent plugin\'s consent settings (credentials masked) and its banner texts with the keys update-consent-banner-texts takes. banner_id and language pick a Complianz or CookieYes banner',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin'    => $plugin,
                        'banner_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'language'  => [ 'type' => 'string', 'maxLength' => 10 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(self::pick($args), $args),
                'handler'      => static fn (array $args): array => self::read_settings($args),
            ],
            'list-consent-cookies'        => $base + [
                'mode'         => 'read',
                'description'  => 'Cookies the consent plugin\'s scan found (Complianz, CookieYes): name, category or service, domain, retention, purpose',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin' => $plugin,
                        'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_COOKIES ],
                        'offset' => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(self::pick($args), $args)
                    ?? (null === self::pick($args)->cookies(1, 0) ? self::no_cookie_list(self::pick($args)) : null),
                'handler'      => static fn (array $args): array => self::list_cookies($args),
            ],
            'update-consent-banner-texts' => $write + [
                'mode'         => 'write',
                'description'  => 'Change consent banner texts: texts maps keys (get-consent-settings lists them per plugin) to new text. Shows each text before and after. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_consent_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin'    => $plugin,
                        'banner_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'language'  => [ 'type' => 'string', 'maxLength' => 10 ],
                        'texts'     => [
                            'type'                 => 'object',
                            'properties'           => array_fill_keys($text_ids, [ 'type' => 'string', 'minLength' => 1, 'maxLength' => Consent_Adapter::MAX_TEXT ]),
                            'additionalProperties' => false,
                            'minProperties'        => 1,
                        ],
                    ],
                    'required'   => [ 'texts' ],
                ],
                'validate'     => static fn (array $args): ?array => self::write_refusal($args),
                'snapshot'     => static fn (array $args): ?array => self::pick($args)->snapshot(self::pick($args)->banner($args), self::changes(self::pick($args), $args)),
                'handler'      => static fn (array $args): array => self::write($args),
            ],
            'start-consent-cookie-scan'   => $write + [
                'mode'         => 'destructive',
                'description'  => 'Restart the Complianz cookie scan (it clears the scan progress and scans again in the background while an administrator has wp-admin open). Not snapshotted. Needs confirm:true. CookieYes scans in its web app. Disabled by default (wpmcp_enable_consent_write filter)',
                'undoable'     => false,
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'plugin' => $plugin ] ],
                'validate'     => static fn (array $args): ?array => self::refusal(self::pick($args), $args) ?? self::pick($args)->scan_refusal(),
                'handler'      => static fn (array $args): array => [ 'plugin' => self::pick($args)->slug() ] + self::pick($args)->start_scan(),
            ],
        ];
    }

    /**
     * true when at least one consent plugin is active, otherwise
     * plugin_inactive (one is installed) or plugin_missing.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        $installed = false;
        foreach (self::adapters() as $adapter) {
            if ($adapter->active()) {
                return true;
            }
            $installed = $installed || $adapter->installed();
        }
        $url = admin_url('plugins.php');
        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf('No cookie consent plugin is active on this site. Install and activate Complianz, CookieYes or Cookie Notice (%s), then try again.', $url),
            'data'    => [ 'plugins' => [ 'complianz-gdpr', 'cookie-law-info', 'cookie-notice' ], 'plugins_url' => $url ],
        ];
    }

    /** Let each consent plugin refresh its caches after an undo restored rows of its table (wpmcp_rollback_db_rows_restored). */
    public static function after_rows_restored(string $table): void
    {
        foreach (self::adapters() as $adapter) {
            if ($adapter->active()) {
                $adapter->after_rows_restored($table);
            }
        }
    }

    /** The adapter a call names, or the first active one. */
    private static function pick(array $args): Consent_Adapter
    {
        $adapters = self::adapters();
        if (isset($args['plugin'], $adapters[ (string) $args['plugin'] ])) {
            return $adapters[ (string) $args['plugin'] ];
        }
        foreach ($adapters as $adapter) {
            if ($adapter->active()) {
                return $adapter;
            }
        }
        return reset($adapters);
    }

    /** Why $adapter cannot answer: not active, or plugin left out while several are active. */
    private static function refusal(Consent_Adapter $adapter, array $args): ?array
    {
        if (! isset($args['plugin'])) {
            $active = array_keys(array_filter(self::adapters(), static fn (Consent_Adapter $a): bool => $a->active()));
            if (count($active) > 1) {
                return [
                    'code'    => 'invalid_args',
                    'message' => sprintf('Several consent plugins are active (%s). Name one with plugin.', implode(', ', $active)),
                    'data'    => [ 'active' => $active ],
                ];
            }
        }
        if ($adapter->active()) {
            return null;
        }
        $url       = admin_url('plugins.php');
        $installed = $adapter->installed();
        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf('%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.', $adapter->label(), $installed ? 'installed but not active' : 'not installed', $url),
            'data'    => [ 'plugin' => dirname($adapter->plugin_file()), 'plugins_url' => $url ],
        ];
    }

    /** @return array{code: string, message: string} */
    private static function no_cookie_list(Consent_Adapter $adapter): array
    {
        return [
            'code'    => 'not_supported',
            'message' => sprintf('%s keeps no cookie list on the site; its web app does.', $adapter->label()),
        ];
    }

    /** @return array<string, mixed> */
    private static function status(): array
    {
        $plugins = [];
        foreach (self::adapters() as $slug => $adapter) {
            $row = [
                'name'      => $adapter->label(),
                'installed' => $adapter->installed() || $adapter->active(),
                'active'    => $adapter->active(),
            ];
            if ($adapter->active()) {
                $row += [ 'version' => $adapter->version() ] + $adapter->status();
            }
            $plugins[ $slug ] = $row;
        }
        return [
            'plugins'        => $plugins,
            'writes_enabled' => (bool) apply_filters('wpmcp_enable_consent_write', false),
        ];
    }

    /** @return array<string, mixed> */
    private static function read_settings(array $args): array
    {
        $adapter = self::pick($args);
        $banner  = $adapter->banner($args);
        $out     = [
            'plugin'         => $adapter->slug(),
            'name'           => $adapter->label(),
            'version'        => $adapter->version(),
            'settings'       => $adapter->settings(),
            'writable_texts' => array_keys($adapter->text_keys()),
            'writes_enabled' => (bool) apply_filters('wpmcp_enable_consent_write', false),
        ];
        if (isset($banner['code'])) {
            return $out + [ 'banner' => null, 'texts' => null, 'banner_note' => $banner['message'] ];
        }
        return $out + [ 'banner' => self::banner_ref($banner), 'texts' => $adapter->texts($banner) ];
    }

    /** @return array<string, mixed> */
    private static function list_cookies(array $args): array
    {
        $adapter = self::pick($args);
        $limit   = (int) ($args['limit'] ?? 50);
        $offset  = (int) ($args['offset'] ?? 0);
        $list    = (array) $adapter->cookies($limit, $offset);
        return [
            'plugin'  => $adapter->slug(),
            'total'   => (int) ($list['total'] ?? 0),
            'limit'   => $limit,
            'offset'  => $offset,
            'cookies' => (array) ($list['cookies'] ?? []),
        ];
    }

    /** @return array<string, mixed> the banner as the reply names it. */
    private static function banner_ref(array $banner): array
    {
        return array_intersect_key($banner, array_flip([ 'id', 'title', 'name', 'language' ]));
    }

    /**
     * The sanitized texts a write asks for whose value really changes.
     *
     * @return array<string, string>
     */
    private static function changes(Consent_Adapter $adapter, array $args): array
    {
        $banner = $adapter->banner($args);
        if (isset($banner['code'])) {
            return [];
        }
        $current = $adapter->texts($banner);
        $changes = [];
        foreach ((array) ($args['texts'] ?? []) as $key => $value) {
            $value = $adapter->sanitize((string) $key, (string) $value);
            if (($current[ (string) $key ] ?? null) !== $value) {
                $changes[ (string) $key ] = $value;
            }
        }
        return $changes;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function write_refusal(array $args): ?array
    {
        $adapter = self::pick($args);
        $refusal = self::refusal($adapter, $args);
        if (null !== $refusal) {
            return $refusal;
        }
        $keys = $adapter->text_keys();
        foreach (array_keys((array) $args['texts']) as $key) {
            if (! isset($keys[ (string) $key ])) {
                return [
                    'code'    => 'invalid_args',
                    'message' => sprintf('"%s" is not one of the banner texts of %s.', $key, $adapter->label()),
                    'data'    => [ 'writable_texts' => array_keys($keys) ],
                ];
            }
            if ('' === $adapter->sanitize((string) $key, (string) $args['texts'][ $key ])) {
                return [ 'code' => 'invalid_args', 'message' => sprintf('%s would be empty after sanitizing.', $key) ];
            }
        }
        $banner = $adapter->banner($args);
        if (isset($banner['code'])) {
            return $banner;
        }
        $refusal = $adapter->write_refusal($banner);
        if (null !== $refusal) {
            return $refusal;
        }
        if ([] === self::changes($adapter, $args)) {
            return [
                'code'    => 'no_change',
                'message' => sprintf('Every text is already set in %s. Nothing was written and no undo point was taken.', $adapter->label()),
            ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function write(array $args): array
    {
        $adapter = self::pick($args);
        $banner  = $adapter->banner($args);
        $before  = $adapter->texts($banner);
        $changes = self::changes($adapter, $args);
        $adapter->write_texts($banner, $changes);
        $after = $adapter->texts($banner);

        $changed = [];
        foreach (array_keys($changes) as $key) {
            $changed[ $key ] = [ 'before' => $before[ $key ] ?? '', 'after' => $after[ $key ] ?? '' ];
        }
        return [
            'plugin'  => $adapter->slug(),
            'banner'  => self::banner_ref($banner),
            'changed' => $changed,
            'check'   => self::CHECK_NOTE,
        ];
    }
}
