<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * All in One SEO storage (issue #294): one row per post in `aioseo_posts`
 * and per term in `aioseo_terms` (the terms table ships only with the paid
 * AIOSEO), read and written in the neutral vocabulary the other adapters use.
 *
 * Column meanings, from the plugin's schema and Post model:
 *
 * - title, description, canonical_url; focus_keyword (5.0+) with the legacy
 *   `keyphrases` JSON ({"focus":{"keyphrase":...}}) kept in step.
 * - robots_noindex / robots_nofollow apply only while robots_default is 0
 *   (1 means "follow the global settings"), so setting a flag switches the
 *   override on, and a flag under robots_default = 1 reads false.
 * - og_title, og_description; the Open Graph image is og_image_custom_url
 *   when og_image_type is 'custom'. twitter_* likewise, and twitter_use_og
 *   = 1 renders the whole Twitter card from the Open Graph fields.
 *
 * Every write replaces the object's rows through
 * Rollback_Service::write_aioseo_rows(), the same path the rollback of an
 * 'aioseo_row' snapshot takes; callers snapshot with snapshot_target().
 */
class Aioseo_Store
{
    /** Robots columns other than the two this vocabulary owns. */
    private const OTHER_ROBOTS = [
        'robots_noarchive',
        'robots_nosnippet',
        'robots_noimageindex',
        'robots_noodp',
        'robots_notranslate',
    ];

    /** Neutral term fields AIOSEO stores on terms. */
    private const TERM_FIELDS = ['title', 'description', 'canonical', 'noindex', 'nofollow'];

    public static function present(): bool
    {
        return (bool) apply_filters('wpmcp_seo_aioseo_active', defined('AIOSEO_VERSION'));
    }

    public static function info(): array
    {
        return [
            'plugin'  => 'aioseo',
            'name'    => 'All in One SEO',
            'version' => defined('AIOSEO_VERSION') ? (string) AIOSEO_VERSION : '',
        ];
    }

    /** @return array{object_type: string, object_id: string} */
    public static function snapshot_target(string $kind, int $id): array
    {
        return ['object_type' => 'aioseo_row', 'object_id' => $kind . ':' . $id];
    }

    /** The neutral fields a term can hold here: none without the terms table. */
    public static function term_fields(): array
    {
        return [] === Snapshot::aioseo_columns('term') ? [] : self::TERM_FIELDS;
    }

    public static function get_post(int $post_id): array
    {
        return self::fields(self::row('post', $post_id));
    }

    public static function update_post(int $post_id, array $fields): void
    {
        self::update('post', $post_id, $fields);
    }

    public static function get_term(int $term_id): array
    {
        return self::fields(self::row('term', $term_id));
    }

    public static function update_term(int $term_id, array $fields): void
    {
        self::update('term', $term_id, array_intersect_key($fields, array_flip(self::TERM_FIELDS)));
    }

    /**
     * Resolved social fields and where each came from, in Social_Meta's
     * shape: 'override', 'inherited' (from Open Graph under twitter_use_og),
     * or 'absent'.
     */
    public static function get_social(int $post_id): array
    {
        $row    = self::row('post', $post_id);
        $fields = [
            'og_title'            => self::text($row, 'og_title'),
            'og_description'      => self::text($row, 'og_description'),
            'og_image'            => self::image($row, 'og'),
            'twitter_title'       => self::text($row, 'twitter_title'),
            'twitter_description' => self::text($row, 'twitter_description'),
            'twitter_image'       => self::image($row, 'twitter'),
        ];

        $sources = [];
        foreach ($fields as $field => $value) {
            $sources[$field] = '' === $value ? 'absent' : 'override';
        }

        if (! empty($row['twitter_use_og'])) {
            foreach (['title', 'description', 'image'] as $part) {
                $fields['twitter_' . $part]  = $fields['og_' . $part];
                $sources['twitter_' . $part] = '' === $fields['og_' . $part] ? 'absent' : 'inherited';
            }
        }

        return ['fields' => $fields, 'sources' => $sources];
    }

    /**
     * Set the custom Open Graph and/or Twitter image. A Twitter-only image
     * would not render while the card mirrors Open Graph, so the mirror is
     * switched off and the title and description it was inheriting are
     * written as explicit Twitter values first.
     *
     * @param string $target 'og', 'twitter' or 'both'.
     */
    public static function set_social_image(int $post_id, string $target, string $url, int $attachment_id): void
    {
        $row = self::row('post', $post_id) ?? [];

        $set = [];
        if ('twitter' === $target && ! empty($row['twitter_use_og'])) {
            $set['twitter_use_og']      = 0;
            $set['twitter_title']       = self::text($row, 'og_title');
            $set['twitter_description'] = self::text($row, 'og_description');
        }

        $meta   = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;
        $width  = is_array($meta) && ! empty($meta['width']) ? (int) $meta['width'] : null;
        $height = is_array($meta) && ! empty($meta['height']) ? (int) $meta['height'] : null;

        foreach (['og', 'twitter'] as $prefix) {
            if ($target !== $prefix && 'both' !== $target) {
                continue;
            }
            $set[$prefix . '_image_type']       = 'custom';
            $set[$prefix . '_image_custom_url'] = $url;
            $set[$prefix . '_image_url']        = $url;
        }
        if ('twitter' !== $target) {
            $set['og_image_width']  = $width;
            $set['og_image_height'] = $height;
        }

        self::write('post', $post_id, $set);
    }

    /** The object's first row, or null when it has none (or the table is missing). */
    private static function row(string $kind, int $id): ?array
    {
        $rows = Snapshot::aioseo_rows($kind, $id);

        return $rows[0] ?? null;
    }

    private static function fields(?array $row): array
    {
        $override = null !== $row && '0' === (string) ($row['robots_default'] ?? '1');

        return [
            'title'         => self::text($row, 'title'),
            'description'   => self::text($row, 'description'),
            'focus_keyword' => self::focus_keyword($row),
            'canonical'     => self::text($row, 'canonical_url'),
            'noindex'       => $override && ! empty($row['robots_noindex']),
            'nofollow'      => $override && ! empty($row['robots_nofollow']),
        ];
    }

    private static function update(string $kind, int $id, array $fields): void
    {
        $row = self::row($kind, $id) ?? [];
        $set = [];

        $map = ['title' => 'title', 'description' => 'description', 'canonical' => 'canonical_url'];
        foreach ($map as $field => $column) {
            if (array_key_exists($field, $fields)) {
                $set[$column] = (string) $fields[$field];
            }
        }

        if ('post' === $kind && array_key_exists('focus_keyword', $fields)) {
            $keyword              = (string) $fields['focus_keyword'];
            $set['focus_keyword'] = $keyword;
            $phrases              = json_decode((string) ($row['keyphrases'] ?? ''), true);
            $phrases              = is_array($phrases) ? $phrases : ['additional' => []];
            $phrases['focus']     = array_merge(
                is_array($phrases['focus'] ?? null) ? $phrases['focus'] : [],
                ['keyphrase' => $keyword]
            );
            $set['keyphrases'] = wp_json_encode($phrases);
        }

        $noindex  = array_key_exists('noindex', $fields) ? (bool) $fields['noindex'] : null;
        $nofollow = array_key_exists('nofollow', $fields) ? (bool) $fields['nofollow'] : null;
        if (null !== $noindex) {
            $set['robots_noindex'] = $noindex ? 1 : 0;
        }
        if (null !== $nofollow) {
            $set['robots_nofollow'] = $nofollow ? 1 : 0;
        }
        if ($noindex || $nofollow) {
            // A flag is only honoured with the per-object override on. The
            // override is left as it is when flags are cleared, since other
            // robots directives may still depend on it.
            $set['robots_default'] = 0;
            if ('1' === (string) ($row['robots_default'] ?? '1')) {
                // Turning the override on from the global defaults: flags
                // stored under the old defaults were never in effect.
                foreach (array_merge(self::OTHER_ROBOTS, ['robots_noindex', 'robots_nofollow']) as $column) {
                    $set[$column] = $set[$column] ?? 0;
                }
            }
        }

        if ([] !== $set) {
            self::write($kind, $id, $set);
        }
    }

    /**
     * Merge $set into the object's first row (or a new one) and write the
     * rows back. Columns this install's table lacks (an older AIOSEO) are
     * dropped rather than failing the insert.
     */
    private static function write(string $kind, int $id, array $set): void
    {
        $columns = Snapshot::aioseo_columns($kind);
        if ([] === $columns) {
            return;
        }

        $rows = Snapshot::aioseo_rows($kind, $id) ?? [];
        $now  = current_time('mysql', true);
        $row  = $rows[0] ?? ['created' => $now];

        $row            = array_merge($row, $set, ['updated' => $now]);
        $rows[0]        = array_intersect_key($row, array_flip($columns));

        Rollback_Service::write_aioseo_rows($kind, $id, $rows);
    }

    private static function text(?array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function image(?array $row, string $prefix): string
    {
        return 'custom' === self::text($row, $prefix . '_image_type')
            ? self::text($row, $prefix . '_image_custom_url')
            : '';
    }

    private static function focus_keyword(?array $row): string
    {
        $keyword = self::text($row, 'focus_keyword');
        if ('' !== $keyword) {
            return $keyword;
        }

        $phrases = json_decode(self::text($row, 'keyphrases'), true);
        $legacy  = is_array($phrases) ? ($phrases['focus']['keyphrase'] ?? '') : '';

        return is_scalar($legacy) ? (string) $legacy : '';
    }
}
