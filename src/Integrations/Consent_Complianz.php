<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Complianz (7.x) for Consent_Pack (issue #686). Its consent settings are
 * the cmplz_options option; each banner is a row of {prefix}cmplz_cookiebanners
 * whose text columns are plain text, or a serialized {text, show} pair for
 * the header and the deny button; the cookies its scan found are rows of
 * {prefix}cmplz_cookies.
 *
 * A text write updates the banner row the way the plugin's own save does
 * (its sanitizers, banner_version + 1, its banner cache dropped), snapshotted
 * as a db_rows before-image of that row. A scan restart does what the
 * plugin's own "restart scan" does: the page lists and the scanned post
 * marks are cleared and cmplz_scan_reset fires; Complianz then scans in the
 * background while an administrator has wp-admin open.
 */
final class Consent_Complianz extends Consent_Adapter
{
    /** text key => kind; a pair stores {text, show}. */
    private const TEXTS = [
        'header'           => 'pair',
        'message_optin'    => 'html',
        'message_optout'   => 'html',
        'accept'           => 'text',
        'dismiss'          => 'pair',
        'save_preferences' => 'text',
        'view_preferences' => 'text',
        'revoke'           => 'text',
    ];

    /** Consent settings read from cmplz_options. Company and contact details stay out. */
    private const SETTINGS = [
        'regions', 'other_region_behaviour', 'eu_consent_regions', 'uk_consent_regions', 'us_states', 'use_country',
        'enable_cookie_banner', 'enable_cookie_blocker', 'cookie_banner_required', 'consent_per_service',
        'cookie_expiry', 'disable_automatic_cookiescan', 'respect_dnt', 'records_of_consent', 'consent-mode',
        'uses_thirdparty_services', 'thirdparty_services_on_site', 'uses_social_media', 'socialmedia_on_site',
        'compile_statistics', 'uses_ad_cookies',
    ];

    public function slug(): string
    {
        return 'complianz';
    }

    public function label(): string
    {
        return 'Complianz';
    }

    public function plugin_file(): string
    {
        return 'complianz-gdpr/complianz-gpdr.php';
    }

    protected function loaded(): bool
    {
        return defined('CMPLZ_VERSION');
    }

    public function version(): string
    {
        return defined('CMPLZ_VERSION') ? (string) constant('CMPLZ_VERSION') : '';
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $options = get_option('cmplz_options', []);
        return is_array($options) ? $options : [];
    }

    public function status(): array
    {
        global $wpdb;
        $banners = [];
        $table   = self::table('cmplz_cookiebanners');
        if (null !== $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complianz's own banner table, read on request.
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT ID, title, `default`, disable_cookiebanner, position, banner_version FROM %i ORDER BY ID LIMIT 50', $table), ARRAY_A);
            foreach ($rows as $row) {
                $banners[] = [
                    'id'             => (int) $row['ID'],
                    'title'          => (string) $row['title'],
                    'default'        => (bool) $row['default'],
                    'disabled'       => (bool) $row['disable_cookiebanner'],
                    'position'       => (string) $row['position'],
                    'banner_version' => (int) $row['banner_version'],
                ];
            }
        }
        $options = $this->options();
        $enabled = 'no' !== ($options['enable_cookie_banner'] ?? 'yes');
        $count   = $this->cookies(1, 0);
        $last    = (int) get_option('cmplz_last_cookie_scan', 0);

        return [
            'banner_enabled' => $enabled && [] !== array_filter($banners, static fn (array $b): bool => ! $b['disabled']),
            'managed_by'     => 'plugin',
            'banners'        => $banners,
            'cookie_count'   => null === $count ? 0 : $count['total'],
            // Complianz stores this in site local time, as a Unix timestamp.
            'last_scan'      => $last > 0 ? gmdate('Y-m-d H:i:s', $last) : null,
        ];
    }

    public function settings(): array
    {
        $options = $this->options();
        $out     = [];
        foreach (self::SETTINGS as $key) {
            if (array_key_exists($key, $options)) {
                $out[ $key ] = $options[ $key ];
            }
        }
        return $out;
    }

    public function text_keys(): array
    {
        return array_map(static fn (string $kind): string => 'html' === $kind ? 'html' : 'text', self::TEXTS);
    }

    public function sanitize(string $key, string $value): string
    {
        $value = trim($value);
        if ('html' === (self::TEXTS[ $key ] ?? '')) {
            return function_exists('cmplz_allowed_html') ? wp_kses($value, cmplz_allowed_html()) : wp_kses_post($value);
        }
        return sanitize_text_field($value);
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        global $wpdb;
        $table = self::table('cmplz_cookiebanners');
        if (null === $table) {
            return null;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complianz's own banner table, read before a write.
        $row = $id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', $table, $id), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY `default` DESC, ID ASC LIMIT 1', $table), ARRAY_A);
        // phpcs:enable
        return is_array($row) ? $row : null;
    }

    public function banner(array $args): array
    {
        $row = $this->row((int) ($args['banner_id'] ?? 0));
        if (null === $row) {
            return self::not_found(isset($args['banner_id'])
                ? sprintf('Complianz has no banner %d. get-consent-banner-status lists them.', (int) $args['banner_id'])
                : 'Complianz has no cookie banner yet. Finish its wizard first.');
        }
        return [ 'id' => (int) $row['ID'], 'title' => (string) $row['title'], 'row' => $row ];
    }

    public function texts(array $banner): array
    {
        $row = $this->row((int) $banner['id']) ?? [];
        $out = [];
        foreach (self::TEXTS as $key => $kind) {
            $value = $row[ $key ] ?? '';
            if ('pair' === $kind) {
                $value = self::pair((string) $value)['text'];
            }
            $out[ $key ] = (string) $value;
        }
        return $out;
    }

    /** @return array{text: string, show: int} a stored {text, show} column. */
    private static function pair(string $stored): array
    {
        $value = is_serialized($stored) ? unserialize($stored, [ 'allowed_classes' => false ]) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Complianz stores these columns serialized; no objects are allowed.
        if (! is_array($value)) {
            return [ 'text' => '', 'show' => 1 ];
        }
        return [ 'text' => (string) ($value['text'] ?? ''), 'show' => (int) ($value['show'] ?? 1) ];
    }

    /** @return array<string, mixed> column => stored value for $changes on $row. */
    private function columns(array $row, array $changes): array
    {
        $set = [];
        foreach ($changes as $key => $value) {
            if ('pair' === self::TEXTS[ $key ]) {
                $pair  = self::pair((string) ($row[ $key ] ?? ''));
                $value = serialize([ 'text' => $value, 'show' => $pair['show'] ]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the format Complianz's own save writes.
            }
            $set[ $key ] = $value;
        }
        $set['banner_version'] = (int) ($row['banner_version'] ?? 0) + 1;
        return $set;
    }

    public function write_texts(array $banner, array $changes): void
    {
        global $wpdb;
        $row = $this->row((int) $banner['id']);
        if (null === $row) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row Complianz's own save updates; snapshotted as db_rows first.
        $wpdb->update((string) self::table('cmplz_cookiebanners'), $this->columns($row, $changes), [ 'ID' => (int) $banner['id'] ]);
        $this->drop_cache((int) $banner['id']);
    }

    public function snapshot(array $banner, array $changes): ?array
    {
        $table = self::table('cmplz_cookiebanners');
        $row   = $this->row((int) $banner['id']);
        return null === $table || null === $row ? null : self::row_snapshot($table, 'ID', (int) $banner['id'], $this->columns($row, $changes));
    }

    public function after_rows_restored(string $table): void
    {
        if (strtolower($table) === strtolower((string) self::table('cmplz_cookiebanners'))) {
            $this->drop_cache(0);
        }
    }

    /** What Complianz's save drops after a banner write; 0 drops every banner's entry. */
    private function drop_cache(int $id): void
    {
        global $wpdb;
        $ids = $id > 0 ? [ $id ] : array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT ID FROM %i', (string) self::table('cmplz_cookiebanners')))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- banner ids for the cache keys.
        foreach ($ids as $banner_id) {
            wp_cache_delete('cmplz_cookiebanner_' . $banner_id, 'cmplz');
        }
        foreach ([ 'cmplz_min_banner_id', 'cmplz_default_banner_id' ] as $transient) {
            if (function_exists('cmplz_delete_transient')) {
                cmplz_delete_transient($transient);
            } else {
                delete_transient($transient);
            }
        }
    }

    public function cookies(int $limit, int $offset): ?array
    {
        global $wpdb;
        $table = self::table('cmplz_cookies');
        if (null === $table) {
            return [ 'total' => 0, 'cookies' => [] ];
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complianz's own cookie table, read on request.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE deleted = 0 AND isTranslationFrom = 0", $table));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, name, serviceID, type, retention, purpose, cookieFunction, isOwnDomainCookie, domain, ignored, showOnPolicy, firstAddDate, lastAddDate FROM %i WHERE deleted = 0 AND isTranslationFrom = 0 ORDER BY name LIMIT %d OFFSET %d", $table, $limit, $offset), ARRAY_A);
        // phpcs:enable
        $cookies = [];
        foreach ($rows as $row) {
            $cookies[] = [
                'id'          => (int) $row['ID'],
                'name'        => (string) $row['name'],
                'service_id'  => (int) $row['serviceID'],
                'type'        => (string) $row['type'],
                'retention'   => (string) $row['retention'],
                'purpose'     => (string) $row['purpose'],
                'function'    => (string) $row['cookieFunction'],
                'first_party' => (bool) $row['isOwnDomainCookie'],
                'domain'      => (string) $row['domain'],
                'ignored'     => (bool) $row['ignored'],
                'on_policy'   => (bool) $row['showOnPolicy'],
                'first_found' => (int) $row['firstAddDate'] > 0 ? gmdate('Y-m-d H:i:s', (int) $row['firstAddDate']) : null,
                'last_found'  => (int) $row['lastAddDate'] > 0 ? gmdate('Y-m-d H:i:s', (int) $row['lastAddDate']) : null,
            ];
        }
        return [ 'total' => $total, 'cookies' => $cookies ];
    }

    public function scan_refusal(): ?array
    {
        if (defined('CMPLZ_DO_NOT_SCAN') && constant('CMPLZ_DO_NOT_SCAN')) {
            return [ 'code' => 'scan_disabled', 'message' => 'Complianz scanning is turned off on this site by the CMPLZ_DO_NOT_SCAN constant.' ];
        }
        return null;
    }

    public function start_scan(): array
    {
        delete_transient('cmplz_pages_list');
        delete_transient('cmplz_processed_pages_list');
        delete_post_meta_by_key('_cmplz_scanned_post');
        if (class_exists('COMPLIANZ', false) && isset(\COMPLIANZ::$wsc_scanner) && is_object(\COMPLIANZ::$wsc_scanner) && method_exists(\COMPLIANZ::$wsc_scanner, 'wsc_scan_reset')) {
            \COMPLIANZ::$wsc_scanner->wsc_scan_reset();
        }
        do_action('cmplz_scan_reset'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Complianz's own hook, fired as its own scan restart does.

        return [
            'restarted' => true,
            'note'      => 'Complianz scans in the background while an administrator has wp-admin open. Its own scan needs the Complianz website scan connected. Check progress with get-consent-banner-status (last_scan) and list-consent-cookies.',
        ];
    }
}
