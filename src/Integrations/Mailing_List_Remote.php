<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MC4WP (Mailchimp for WordPress) and Brevo setup, lists and forms (issue
 * #683), read only, part of Mailing_List_Pack.
 *
 * Both keep their contacts and lists in the remote account, not on the site,
 * so these ops never call the remote API: they read what the plugin itself
 * keeps on the site.
 *  - MC4WP (4.x): its settings in the mc4wp option (the API key is only
 *    reported as set or not), the lists it last fetched in the
 *    mc4wp_mailchimp_lists transient (refreshed by MC4WP's own settings
 *    screen) and its forms as mc4wp-form posts with their _mc4wp_settings
 *    meta (lists, double opt-in, update existing), placed with
 *    [mc4wp_form id="<post id>"].
 *  - Brevo (3.x): its settings in sib_main_option and sib_api_key_v3 (never
 *    returned), the lists it last fetched in the sib_list_<md5 of the access
 *    key> transient, and its forms as rows of {prefix}sib_model_forms
 *    (lists, double opt-in, captcha on or off; captcha keys and the form
 *    HTML are not returned), placed with [sibwp_form id=<id>].
 *
 * Contacts are not read or written here: they live in the remote account,
 * and a remote write cannot be snapshotted on the site.
 *
 * Every op takes manage_options, the capability both plugins' settings
 * screens take.
 */
final class Mailing_List_Remote
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Brevo's own forms table live; it has no cached read API.

    public const CAPABILITY = 'manage_options';

    public const MC4WP_FILE = 'mailchimp-for-wp/mailchimp-for-wp.php';

    public const BREVO_FILE = 'mailin/sendinblue.php';

    public static function mc4wp_active(): bool
    {
        return (bool) apply_filters('wpmcp_mc4wp_active', defined('MC4WP_VERSION'));
    }

    public static function brevo_active(): bool
    {
        return (bool) apply_filters('wpmcp_brevo_active', class_exists('SIB_Manager', false));
    }

    /** @return array<string, array<string, mixed>> */
    public static function mc4wp_operations(): array
    {
        $base = [
            'tier'       => 'pro',
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => Mailing_List_Pack::presence(self::mc4wp_active(), 'MC4WP', self::MC4WP_FILE),
        ];
        $none = [ 'type' => 'object', 'properties' => (object) [] ];

        return [
            'mc4wp-summary'    => $base + [
                'description'  => 'MC4WP setup: version, whether a Mailchimp API key is set (never the key), how many lists it has cached and how many forms. Contacts live in Mailchimp',
                'input_schema' => $none,
                'handler'      => static fn (): array => self::mc4wp_summary(),
            ],
            'mc4wp-list-lists' => $base + [
                'description'  => 'Mailchimp lists (audiences) as MC4WP last cached them: id, title, member count. cached:false when MC4WP has not fetched them; refresh them on its settings screen',
                'input_schema' => $none,
                'handler'      => static fn (): array => self::mc4wp_lists(),
            ],
            'mc4wp-list-forms' => $base + [
                'description'  => 'List MC4WP forms: id, title, status, the list ids it subscribes to, double opt-in, update existing, and the shortcode that places it',
                'input_schema' => $none,
                'handler'      => static fn (): array => [ 'forms' => self::mc4wp_forms() ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function brevo_operations(): array
    {
        $base = [
            'tier'       => 'pro',
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => Mailing_List_Pack::presence(self::brevo_active(), 'Brevo', self::BREVO_FILE),
        ];
        $none = [ 'type' => 'object', 'properties' => (object) [] ];

        return [
            'brevo-summary'    => $base + [
                'description'  => 'Brevo setup: version, whether an API key is set (never the key), how many lists it has cached and how many forms. Contacts live in Brevo',
                'input_schema' => $none,
                'handler'      => static fn (): array => self::brevo_summary(),
            ],
            'brevo-list-lists' => $base + [
                'description'  => 'Brevo contact lists as the Brevo plugin last cached them: id and title. cached:false when it has not fetched them',
                'input_schema' => $none,
                'handler'      => static fn (): array => self::brevo_lists(),
            ],
            'brevo-list-forms' => $base + [
                'description'  => 'List Brevo subscription forms: id, title, list ids, double opt-in, captcha on, terms checkbox, date, and the shortcode that places it (captcha keys and form HTML are not returned)',
                'input_schema' => $none,
                'handler'      => static fn (): array => [ 'forms' => self::brevo_forms() ],
            ],
        ];
    }

    // ------------------------------------------------------------------ MC4WP

    /** @return array<string, mixed> */
    private static function mc4wp_summary(): array
    {
        $options = get_option('mc4wp', []);
        $key     = defined('MC4WP_API_KEY') && '' !== (string) constant('MC4WP_API_KEY') ? 'set' : (is_array($options) ? (string) ($options['api_key'] ?? '') : '');
        $lists   = self::mc4wp_lists();
        return [
            'plugin'      => 'MC4WP',
            'version'     => defined('MC4WP_VERSION') ? (string) constant('MC4WP_VERSION') : null,
            'api_key_set' => '' !== $key,
            'lists'       => $lists['cached'] ? count($lists['lists']) : null,
            'forms'       => count(self::mc4wp_forms()),
        ];
    }

    /** @return array{cached: bool, lists: array<int, array<string, mixed>>} */
    private static function mc4wp_lists(): array
    {
        $cached = get_transient('mc4wp_mailchimp_lists');
        if (! is_array($cached)) {
            return [ 'cached' => false, 'lists' => [] ];
        }
        $out = [];
        foreach ($cached as $list) {
            $list  = (object) $list;
            $out[] = [
                'id'       => (string) ($list->id ?? ''),
                'title'    => (string) ($list->name ?? ''),
                'web_id'   => isset($list->web_id) ? (int) $list->web_id : null,
                'contacts' => isset($list->stats->member_count) ? (int) $list->stats->member_count : null,
            ];
        }
        return [ 'cached' => true, 'lists' => $out ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function mc4wp_forms(): array
    {
        $posts = get_posts([
            'post_type'        => 'mc4wp-form',
            'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'numberposts'      => Mailing_List_Pack::MAX_PER_PAGE,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);
        $out   = [];
        foreach ($posts as $post) {
            $settings = get_post_meta($post->ID, '_mc4wp_settings', true);
            $settings = is_array($settings) ? $settings : [];
            $lists    = $settings['lists'] ?? [];
            $lists    = is_array($lists) ? $lists : array_map('trim', explode(',', (string) $lists));
            $out[]    = [
                'id'              => (int) $post->ID,
                'title'           => (string) $post->post_title,
                'status'          => (string) $post->post_status,
                'lists'           => array_values(array_filter(array_map('strval', $lists), static fn (string $id): bool => '' !== $id)),
                'double_optin'    => ! empty($settings['double_optin']),
                'update_existing' => ! empty($settings['update_existing']),
                'shortcode'       => sprintf('[mc4wp_form id="%d"]', (int) $post->ID),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ Brevo

    private static function brevo_access_key(): string
    {
        $options = get_option('sib_main_option', []);
        return is_array($options) ? (string) ($options['access_key'] ?? '') : '';
    }

    /** @return array<string, mixed> */
    private static function brevo_summary(): array
    {
        $lists = self::brevo_lists();
        return [
            'plugin'      => 'Brevo',
            'version'     => class_exists('SendinblueApiClient', false) && defined('SendinblueApiClient::PLUGIN_VERSION') ? (string) constant('SendinblueApiClient::PLUGIN_VERSION') : null,
            'api_key_set' => '' !== (string) get_option('sib_api_key_v3', '') || '' !== self::brevo_access_key(),
            'lists'       => $lists['cached'] ? count($lists['lists']) : null,
            'forms'       => count(self::brevo_forms()),
        ];
    }

    /** @return array{cached: bool, lists: array<int, array<string, mixed>>} */
    private static function brevo_lists(): array
    {
        $cached = get_transient('sib_list_' . md5(self::brevo_access_key()));
        if (! is_array($cached) || [] === $cached) {
            return [ 'cached' => false, 'lists' => [] ];
        }
        $out = [];
        foreach ($cached as $list) {
            $list  = (array) $list;
            $out[] = [ 'id' => (int) ($list['id'] ?? 0), 'title' => (string) ($list['name'] ?? '') ];
        }
        return [ 'cached' => true, 'lists' => $out ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function brevo_forms(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sib_model_forms';
        if (! Mailing_List_Pack::table_exists($table)) {
            return [];
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC LIMIT %d', $table, Mailing_List_Pack::MAX_PER_PAGE), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $raw   = (string) ($row['listID'] ?? '');
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Brevo stores the list ids serialized; no object is ever allowed back.
            $lists = is_serialized($raw) ? unserialize($raw, [ 'allowed_classes' => false ]) : [];
            $out[] = [
                'id'           => (int) $row['id'],
                'title'        => (string) ($row['title'] ?? ''),
                'lists'        => array_values(array_filter(array_map('intval', is_array($lists) ? $lists : []), static fn (int $id): bool => $id > 0)),
                'double_optin' => ! empty($row['isDopt']),
                'captcha'      => ! empty($row['gCaptcha']) || ! empty($row['selectCaptchaType']),
                'terms'        => ! empty($row['termAccept']),
                'date'         => (string) ($row['date'] ?? ''),
                'shortcode'    => sprintf('[sibwp_form id=%d]', (int) $row['id']),
            ];
        }
        return $out;
    }
}
