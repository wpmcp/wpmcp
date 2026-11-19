<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FiboSearch (AJAX search for WooCommerce) settings, top searches and
 * settings writes (issue #700), as free ops on the theme dispatcher pair
 * through Ops_Status_Packs, so they add no top-level tools:
 *  - get-fibosearch-settings: the dgwt_wcas_settings option FiboSearch's
 *    settings screen saves (form, layout, autocomplete, analytics), through
 *    Ops_Status_Packs::redact() like every status read.
 *  - list-fibosearch-top-searches: the most searched phrases in its
 *    wp_dgwt_wcas_stats analytics table, aggregated and masked
 *    (Search_Plugin_Support).
 *  - update-fibosearch-settings: merge text values into settings the site
 *    already has (an unknown key is refused, so a typo cannot add a dead
 *    setting), sanitized the way its settings screen sanitizes text,
 *    snapshot-wrapped on dgwt_wcas_settings, off until the site opts in
 *    (wpmcp_enable_search_write).
 *
 * The free edition searches products live and keeps no index of its own, so
 * there is no index status or rebuild op here. Every op runs at
 * manage_options, the capability FiboSearch's settings screen takes.
 * Presence is the DGWT_WCAS_VERSION constant, filterable through
 * wpmcp_fibosearch_active.
 */
final class FiboSearch_Status
{
    public const OPTION = 'dgwt_wcas_settings';

    private const MAX_LENGTH = 1000;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'fibosearch_inactive', 'FiboSearch');

        return [
            'get-fibosearch-settings'      => Ops_Status_Packs::read_op(
                'FiboSearch (WooCommerce AJAX search) settings: search form, layout, autocomplete, analytics, as its settings screen saves them',
                $requires,
                static fn (): array => [
                    'settings'          => self::stored(),
                    'analytics_enabled' => 'on' === (self::stored()['analytics_enabled'] ?? 'off'),
                    'version'           => defined('DGWT_WCAS_VERSION') ? (string) DGWT_WCAS_VERSION : null,
                ]
            ),
            'list-fibosearch-top-searches' => Ops_Status_Packs::read_op(
                'FiboSearch top searched phrases from its analytics, aggregated per phrase (count, most results, last time), personal data masked; zero_hits:true for searches that found nothing',
                $requires,
                static function (array $args): array {
                    global $wpdb;
                    return [ 'logging_enabled' => 'on' === (self::stored()['analytics_enabled'] ?? 'off') ]
                        + Search_Plugin_Support::top_searches($wpdb->prefix . 'dgwt_wcas_stats', 'phrase', 'hits', 'created_at', $args);
                },
                Search_Plugin_Support::top_searches_schema()
            ),
            'update-fibosearch-settings'   => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Change FiboSearch settings the site already has (settings: {name: text}, names as get-fibosearch-settings reports them). Snapshotted on dgwt_wcas_settings; rollback-operation restores it. Disabled by default (wpmcp_enable_search_write filter)',
                'enabled_by_default' => Search_Plugin_Support::writes_enabled(),
                'opt_in_filter'      => Search_Plugin_Support::ENABLE_FILTER,
                'input_schema'       => Search_Plugin_Support::settings_schema(),
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::refusal((array) $args['settings']),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'            => static fn (array $args): array => self::update((array) $args['settings']),
            ],
        ];
    }

    /** Whether FiboSearch is loaded, filterable through wpmcp_fibosearch_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_fibosearch_active', defined('DGWT_WCAS_VERSION'));
    }

    /** @return array<string,mixed> */
    private static function stored(): array
    {
        $settings = get_option(self::OPTION, []);
        return is_array($settings) ? $settings : [];
    }

    /** @param array<string,mixed> $settings */
    private static function refusal(array $settings): ?array
    {
        if ([] === $settings) {
            return Search_Plugin_Support::invalid('Pass at least one setting to change.', '');
        }
        $stored = self::stored();
        foreach ($settings as $name => $value) {
            $name = (string) $name;
            if (! array_key_exists($name, $stored)) {
                return Search_Plugin_Support::invalid(sprintf('"%s" is not a FiboSearch setting on this site. Read get-fibosearch-settings for the names.', $name), $name);
            }
            if (! is_scalar($value) || is_array($stored[ $name ]) || strlen((string) $value) > self::MAX_LENGTH) {
                return Search_Plugin_Support::invalid(sprintf('"%s" takes a text value of at most %d characters.', $name, self::MAX_LENGTH), $name);
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{changed:list<string>}
     */
    private static function update(array $settings): array
    {
        $stored = self::stored();
        foreach ($settings as $name => $value) {
            $stored[ (string) $name ] = is_bool($value) ? ($value ? 'on' : 'off') : sanitize_text_field((string) $value);
        }
        update_option(self::OPTION, $stored);

        return [ 'changed' => array_map('strval', array_keys($settings)) ];
    }
}
