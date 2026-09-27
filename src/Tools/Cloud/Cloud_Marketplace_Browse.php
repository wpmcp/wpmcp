<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Browse the WP MCP Cloud marketplace (issue #135): shared widget and block
 * specs another account published. Read-only.
 *
 * Contract: GET /marketplace[?type=widget|block][&search=term] returns
 * { listings: [ { slug, type, title, description?, author?, version? } ] }.
 * Listings are projected onto those scalar fields; the spec itself is not
 * returned here, because nothing should act on a spec that has not been
 * through cloud-marketplace-install's validation.
 */
class Cloud_Marketplace_Browse
{
    public const TYPES = ['widget', 'block'];

    public function handle(array $args)
    {
        $query = [];

        $type = trim((string) ($args['type'] ?? ''));
        if ('' !== $type) {
            if (! in_array($type, self::TYPES, true)) {
                return new \WP_Error('invalid_type', 'type must be widget or block.');
            }
            $query['type'] = $type;
        }

        $search = trim(sanitize_text_field((string) ($args['search'] ?? '')));
        if ('' !== $search) {
            $query['search'] = $search;
        }

        $path = '/marketplace';
        if ([] !== $query) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $result = (new Cloud_Client())->get($path);
        if (is_wp_error($result)) {
            return $result;
        }

        $listings = [];
        foreach ((array) ($result['listings'] ?? []) as $listing) {
            $projected = self::project($listing);
            if (null !== $projected) {
                $listings[] = $projected;
            }
        }

        return [
            'listings' => $listings,
            'count'    => count($listings),
            'note'     => 'Install one with cloud-marketplace-install and its slug. Installs land as inactive drafts after spec validation; activate with set-widget-status or set-block-status once reviewed.',
        ];
    }

    /**
     * @param mixed $listing
     * @return array<string,string>|null
     */
    private static function project($listing): ?array
    {
        if (! is_array($listing)) {
            return null;
        }
        $slug = is_string($listing['slug'] ?? null) ? $listing['slug'] : '';
        $type = is_string($listing['type'] ?? null) ? $listing['type'] : '';
        if ('' === $slug || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        $out = ['slug' => sanitize_title($slug), 'type' => $type];
        foreach (['title', 'description', 'author', 'version'] as $field) {
            $out[ $field ] = is_scalar($listing[ $field ] ?? null) ? sanitize_text_field((string) $listing[ $field ]) : '';
        }

        return $out;
    }
}
