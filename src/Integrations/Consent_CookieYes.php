<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CookieYes (GDPR Cookie Consent 3.x) for Consent_Pack (issue #686). Its
 * banners are rows of {prefix}cky_banners whose contents column is JSON,
 * one tree per language; its cookies are rows of {prefix}cky_cookies whose
 * names and descriptions are JSON per language too; cky_settings holds the
 * account (connected to the CookieYes web app or not) and the languages.
 *
 * Only the 3.x data model is covered: a site still on the 2.x settings
 * (cky_is_legacy()) is read as legacy and refuses text writes. While the
 * site is connected to the CookieYes web app the app owns the banner, so
 * text writes refuse too. A text write updates the banner row's contents
 * for one language, snapshotted as a db_rows before-image, and fires
 * cky_after_update_banner as the plugin's own save does so it drops its
 * caches; an undo fires it again. Scans run in the CookieYes web app only.
 */
final class Consent_CookieYes extends Consent_Adapter
{
    /** text key => [path inside a language's contents tree, kind]. */
    private const TEXTS = [
        'title'                  => [ 'notice.elements.title', 'text' ],
        'description'            => [ 'notice.elements.description', 'html' ],
        'accept'                 => [ 'notice.elements.buttons.elements.accept', 'text' ],
        'reject'                 => [ 'notice.elements.buttons.elements.reject', 'text' ],
        'customize'              => [ 'notice.elements.buttons.elements.settings', 'text' ],
        'read_more'              => [ 'notice.elements.buttons.elements.readMore', 'text' ],
        'preferences_title'      => [ 'preferenceCenter.elements.title', 'text' ],
        'preferences_description' => [ 'preferenceCenter.elements.description', 'html' ],
    ];

    public function slug(): string
    {
        return 'cookieyes';
    }

    public function label(): string
    {
        return 'CookieYes';
    }

    public function plugin_file(): string
    {
        return 'cookie-law-info/cookie-law-info.php';
    }

    protected function loaded(): bool
    {
        return defined('CLI_VERSION');
    }

    public function version(): string
    {
        return defined('CLI_VERSION') ? (string) constant('CLI_VERSION') : '';
    }

    private function legacy(): bool
    {
        return function_exists('cky_is_legacy') ? (bool) cky_is_legacy() : null === self::table('cky_banners');
    }

    /** @return array<string, mixed> */
    private function settings_option(): array
    {
        $settings = get_option('cky_settings', []);
        return is_array($settings) ? $settings : [];
    }

    private function connected(): bool
    {
        return ! empty($this->settings_option()['account']['connected']);
    }

    private function default_language(): string
    {
        $language = (string) ($this->settings_option()['languages']['default'] ?? '');
        return '' === $language ? 'en' : sanitize_key($language);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        global $wpdb;
        $table = self::table('cky_banners');
        if (null === $table) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CookieYes's own banner table, read on request.
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY status DESC, banner_default DESC, banner_id ASC LIMIT 50', $table), ARRAY_A);
    }

    public function status(): array
    {
        $banners = [];
        foreach ($this->rows() as $row) {
            $settings  = json_decode((string) $row['settings'], true);
            $banners[] = [
                'id'      => (int) $row['banner_id'],
                'name'    => (string) $row['name'],
                'active'  => 1 === (int) $row['status'],
                'default' => 1 === (int) $row['banner_default'],
                'law'     => is_array($settings) ? (string) ($settings['settings']['applicableLaw'] ?? '') : '',
                'type'    => is_array($settings) ? (string) ($settings['settings']['type'] ?? '') : '',
            ];
        }
        $count = $this->cookies(1, 0);

        return [
            'banner_enabled' => [] !== array_filter($banners, static fn (array $b): bool => $b['active']),
            'managed_by'     => $this->connected() ? 'cookieyes_web_app' : 'plugin',
            'legacy'         => $this->legacy(),
            'banners'        => $banners,
            'cookie_count'   => null === $count ? 0 : $count['total'],
        ];
    }

    public function settings(): array
    {
        $settings = $this->settings_option();
        $account  = is_array($settings['account'] ?? null) ? $settings['account'] : [];
        return [
            'account'      => [
                'connected' => ! empty($account['connected']),
                'plan'      => (string) ($account['plan'] ?? ''),
                'domain'    => (string) ($account['domain'] ?? ''),
                'email'     => Masking::email((string) ($account['email'] ?? '')),
            ],
            'consent_logs' => ! empty($settings['consent_logs']['status']),
            'languages'    => is_array($settings['languages'] ?? null) ? $settings['languages'] : [],
            'masked'       => [
                [ 'name' => 'api.token', 'set' => '' !== (string) ($settings['api']['token'] ?? '') ],
                [ 'name' => 'account.website_key', 'set' => '' !== (string) ($account['website_key'] ?? '') ],
            ],
        ];
    }

    public function text_keys(): array
    {
        return array_map(static fn (array $t): string => $t[1], self::TEXTS);
    }

    public function banner(array $args): array
    {
        $id = (int) ($args['banner_id'] ?? 0);
        foreach ($this->rows() as $row) {
            if (0 === $id || (int) $row['banner_id'] === $id) {
                $language = isset($args['language']) ? sanitize_key((string) $args['language']) : $this->default_language();
                return [ 'id' => (int) $row['banner_id'], 'name' => (string) $row['name'], 'language' => $language ];
            }
        }
        return self::not_found($id > 0
            ? sprintf('CookieYes has no banner %d. get-consent-banner-status lists them.', $id)
            : 'CookieYes has no banner on this site yet.');
    }

    /** @return array<string, mixed> the stored contents of every language of banner $id. */
    private function contents(int $id): array
    {
        foreach ($this->rows() as $row) {
            if ((int) $row['banner_id'] === $id) {
                $contents = json_decode((string) $row['contents'], true);
                return is_array($contents) ? $contents : [];
            }
        }
        return [];
    }

    public function texts(array $banner): array
    {
        $tree = $this->contents((int) $banner['id'])[ $banner['language'] ] ?? [];
        $out  = [];
        foreach (self::TEXTS as $key => $text) {
            $value       = is_array($tree) ? self::path_get($tree, $text[0]) : null;
            $out[ $key ] = is_scalar($value) ? (string) $value : '';
        }
        return $out;
    }

    public function write_refusal(array $banner): ?array
    {
        if ($this->legacy()) {
            return [ 'code' => 'not_supported', 'message' => 'This site runs the CookieYes 2.x settings (legacy). Text writes cover the 3.x banner only.' ];
        }
        if ($this->connected()) {
            return [ 'code' => 'managed_in_cloud', 'message' => 'This site is connected to the CookieYes web app, which owns the banner. Change its texts in the web app.' ];
        }
        $tree = $this->contents((int) $banner['id'])[ $banner['language'] ] ?? null;
        if (! is_array($tree) || [] === $tree) {
            return [
                'code'    => 'not_stored',
                'message' => sprintf('Banner %d has no stored texts for language "%s" (CookieYes shows its built in translation). Save the banner once in the CookieYes editor, then try again.', (int) $banner['id'], $banner['language']),
            ];
        }
        return null;
    }

    /** @return array<string, mixed> the contents column after $changes. */
    private function columns(array $banner, array $changes): array
    {
        $contents = $this->contents((int) $banner['id']);
        $tree     = is_array($contents[ $banner['language'] ] ?? null) ? $contents[ $banner['language'] ] : [];
        foreach ($changes as $key => $value) {
            $tree = self::path_set($tree, self::TEXTS[ $key ][0], $value);
        }
        $contents[ $banner['language'] ] = $tree;
        return [ 'contents' => (string) wp_json_encode($contents) ];
    }

    public function write_texts(array $banner, array $changes): void
    {
        global $wpdb;
        $set                  = $this->columns($banner, $changes);
        $set['date_modified'] = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row CookieYes's own save updates; snapshotted as db_rows first.
        $wpdb->update((string) self::table('cky_banners'), $set, [ 'banner_id' => (int) $banner['id'] ]);
        do_action('cky_after_update_banner'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- CookieYes's own hook, fired as its own save does.
    }

    public function snapshot(array $banner, array $changes): ?array
    {
        $table = self::table('cky_banners');
        return null === $table ? null : self::row_snapshot($table, 'banner_id', (int) $banner['id'], $this->columns($banner, $changes));
    }

    public function after_rows_restored(string $table): void
    {
        if (strtolower($table) === strtolower((string) self::table('cky_banners'))) {
            do_action('cky_after_update_banner'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- CookieYes's own hook, so it drops its banner caches as after its own save.
        }
    }

    public function cookies(int $limit, int $offset): ?array
    {
        global $wpdb;
        $table      = self::table('cky_cookies');
        $categories = self::table('cky_cookie_categories');
        if (null === $table) {
            return [ 'total' => 0, 'cookies' => [] ];
        }
        $language = $this->default_language();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CookieYes's own cookie tables, read on request.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        $rows  = null === $categories
            ? (array) $wpdb->get_results($wpdb->prepare('SELECT c.*, NULL AS category_slug FROM %i c ORDER BY c.name LIMIT %d OFFSET %d', $table, $limit, $offset), ARRAY_A)
            : (array) $wpdb->get_results($wpdb->prepare('SELECT c.*, k.slug AS category_slug FROM %i c LEFT JOIN %i k ON k.category_id = c.category ORDER BY c.name LIMIT %d OFFSET %d', $table, $categories, $limit, $offset), ARRAY_A);
        // phpcs:enable
        $cookies = [];
        foreach ($rows as $row) {
            $cookies[] = [
                'id'          => (int) $row['cookie_id'],
                'name'        => (string) $row['name'],
                'category'    => null === $row['category_slug'] ? (int) $row['category'] : (string) $row['category_slug'],
                'domain'      => (string) $row['domain'],
                'duration'    => self::localized((string) $row['duration'], $language),
                'description' => self::localized((string) $row['description'], $language),
                'type'        => (string) $row['type'],
                'discovered'  => (bool) $row['discovered'],
            ];
        }
        return [ 'total' => $total, 'cookies' => $cookies ];
    }

    /** One language of a JSON {lang: text} column, or the text itself. */
    private static function localized(string $stored, string $language): string
    {
        $value = json_decode($stored, true);
        if (! is_array($value)) {
            return $stored;
        }
        $text = $value[ $language ] ?? reset($value);
        return is_scalar($text) ? (string) $text : '';
    }

    public function scan_refusal(): ?array
    {
        return [
            'code'    => 'not_supported',
            'message' => 'CookieYes runs its cookie scan in the CookieYes web app. Connect the site there and start the scan from the app.',
        ];
    }
}
