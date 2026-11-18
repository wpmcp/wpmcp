<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HubSpot for WordPress forms (issue #684) on the plugin-data pair,
 * paid-tier and read-only, so it adds no top-level tools.
 *
 * The HubSpot plugin (leadin, 11.x) keeps no contacts or form definitions in
 * WordPress: they live in the HubSpot account, which the plugin reaches
 * through its OAuth connection. What the site itself holds is the connected
 * account (the leadin_portalId, leadin_account_name and leadin_hublet
 * options) and where its forms are placed: the [hubspot type="form"
 * portal="..." id="..."] shortcode and the leadin/hubspot-form-block block
 * (portalId and formId attributes).
 *
 * hubspot-list-forms reports the connection (never the plugin's tokens) and
 * each form placed on the site with the posts it is placed on, kept to the
 * posts the caller may read. Reading or writing HubSpot contacts would call
 * HubSpot's API with the site's stored credentials and is left out.
 *
 * Presence is filterable (wpmcp_hubspot_active, default: LEADIN_PLUGIN_VERSION
 * is defined).
 */
final class HubSpot_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finds the posts holding a HubSpot form by their content; WP_Query has no content filter.

    /** Posts scanned for placed forms. */
    public const MAX_POSTS = 500;

    private const PLUGIN_FILE = 'leadin/leadin.php';

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters('wpmcp_hubspot_active', defined('LEADIN_PLUGIN_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::loaded()) {
            return true;
        }
        $refusal = Plugin_Refusal::make('plugin_missing', 'HubSpot', self::PLUGIN_FILE);
        if ('inactive' === ($refusal['data']['state'] ?? '')) {
            $refusal['code'] = 'plugin_inactive';
        }
        return $refusal;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return [
            'hubspot-list-forms' => [
                'tier'         => 'pro',
                'mode'         => 'read',
                'requires'     => static fn () => self::presence(),
                'description'  => 'HubSpot connection (portal id, account name, region; never tokens) and every HubSpot form placed on the site by shortcode or block, with the posts it is on. Contacts and form fields live in HubSpot and are not read',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::list_forms(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function list_forms(): array
    {
        $portal = (string) get_option('leadin_portalId', '');
        $forms  = [];
        foreach (self::candidate_posts() as $post) {
            if (! current_user_can('read_post', $post->ID)) {
                continue;
            }
            foreach (self::placed_forms((string) $post->post_content) as [ $portal_id, $form_id ]) {
                $key = $portal_id . ':' . $form_id;
                if (! isset($forms[ $key ])) {
                    $forms[ $key ] = [ 'form_id' => $form_id, 'portal_id' => $portal_id, 'posts' => [] ];
                }
                $forms[ $key ]['posts'][ $post->ID ] = [
                    'id'     => (int) $post->ID,
                    'title'  => get_the_title($post),
                    'type'   => (string) $post->post_type,
                    'status' => (string) $post->post_status,
                ];
            }
        }
        ksort($forms);

        return [
            'connected'    => '' !== $portal,
            'portal_id'    => '' !== $portal ? $portal : null,
            'account_name' => '' !== $portal ? (string) get_option('leadin_account_name', '') : null,
            'region'       => '' !== $portal ? (string) get_option('leadin_hublet', '') : null,
            'forms'        => array_values(array_map(static function (array $form): array {
                $form['posts'] = array_values($form['posts']);
                return $form;
            }, $forms)),
        ];
    }

    /** @return \WP_Post[] posts whose content names a HubSpot form */
    private static function candidate_posts(): array
    {
        global $wpdb;
        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM %i WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_type <> 'revision' AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC LIMIT %d",
            $wpdb->posts,
            '%' . $wpdb->esc_like('[hubspot') . '%',
            '%' . $wpdb->esc_like('wp:leadin/hubspot-form-block') . '%',
            self::MAX_POSTS
        ));
        return array_values(array_filter(array_map('get_post', array_map('intval', $ids))));
    }

    /**
     * The forms one post's content places: [portal id, form id] pairs.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function placed_forms(string $content): array
    {
        $found = [];
        if (preg_match_all('/' . get_shortcode_regex([ 'hubspot' ]) . '/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $atts = shortcode_parse_atts((string) $m[3]);
                $atts = is_array($atts) ? $atts : [];
                if ('form' === ($atts['type'] ?? '') && '' !== (string) ($atts['id'] ?? '')) {
                    $found[] = [ (string) ($atts['portal'] ?? ''), (string) $atts['id'] ];
                }
            }
        }
        foreach (self::form_blocks(parse_blocks($content)) as $attrs) {
            if ('' !== (string) ($attrs['formId'] ?? '')) {
                $found[] = [ (string) ($attrs['portalId'] ?? ''), (string) $attrs['formId'] ];
            }
        }
        return $found;
    }

    /** @return array<int, array<string, mixed>> the attributes of every HubSpot form block, inner blocks included */
    private static function form_blocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if ('leadin/hubspot-form-block' === ($block['blockName'] ?? '')) {
                $out[] = (array) ($block['attrs'] ?? []);
            }
            $out = array_merge($out, self::form_blocks((array) ($block['innerBlocks'] ?? [])));
        }
        return $out;
    }
}
