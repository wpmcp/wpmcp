<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * find-unused-media (issue #383): attachments that nothing on the site
 * references, so a Media Library clean-up does not mean opening every post by
 * hand. Read-only; deletion stays with delete-media, which backs files up.
 *
 * The costly mistake is a false "unused", because the agent then deletes an
 * image a page still shows. So every check errs toward "used": a reference is
 * matched loosely (an id in any JSON or serialized "...id" key, a URL by its
 * upload path stem so every size variant counts) and anything that looks like
 * one keeps the attachment off the list. The locations checked are:
 *
 *  - featured_image:   _thumbnail_id on any post other than a revision.
 *  - site_logo_icon:   site_icon, the custom_logo theme mod, site_logo.
 *  - post_content_*:   post content of every post type but revisions, by URL
 *                      (plain and JSON-escaped) and by id (wp-image-N class,
 *                      block and JSON "id" attributes, gallery ids).
 *  - post_meta_*:      every post meta value but revisions' and attachment
 *                      bookkeeping, which is where page builders keep their
 *                      data (JSON with escaped slashes, or serialized arrays),
 *                      plus custom fields holding the bare id and product
 *                      gallery id lists.
 *  - term_meta:        term meta by URL, id and bare id (category thumbnails).
 *  - options:          non-transient options by URL and by id (widgets, theme
 *                      mods such as the header image).
 *
 * A revision's content does not count: once the page itself dropped the image
 * nothing renders it.
 *
 * Large libraries are scanned in pages keyed by an attachment id cursor, and
 * each page also stops at a time budget (at least one attachment is always
 * scanned, so repeated calls always finish), so one call cannot time out.
 */
class Find_Unused_Media
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE     = 200;

    /** Seconds one call may spend scanning before it hands back a cursor. */
    public const TIME_BUDGET = 20.0;

    public const CHECKS = [
        'featured_image',
        'site_logo_icon',
        'post_content_id',
        'post_content_url',
        'post_meta_id',
        'post_meta_url',
        'term_meta',
        'options',
    ];

    /** Post meta that records an attachment's own file data, not a use of it. */
    private const IGNORED_META_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_wp_attachment_backup_sizes',
        '_edit_lock',
        '_edit_last',
    ];

    private const NOT_CHECKED = 'Theme and plugin files, custom database tables, CSS files and other sites are not scanned. Review before deleting; delete-media backs files up for rollback.';

    public function handle(array $args): array
    {
        global $wpdb;

        $started  = microtime(true);
        $budget   = (float) apply_filters('wpmcp_find_unused_media_time_budget', self::TIME_BUDGET);
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $cursor   = max(0, (int) ($args['cursor'] ?? 0));
        $with_un  = ! empty($args['unattached']);

        [ $mime_sql, $mime_args ] = $this->mime_clause((string) ($args['type'] ?? ''));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- id-keyed paging over attachments; the mime clause is built from placeholders.
        $batch = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_mime_type, post_date, post_parent FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit', 'private') AND ID > %d{$mime_sql} ORDER BY ID ASC LIMIT %d", array_merge([ $cursor ], $mime_args, [ $per_page ])));

        $protected = $this->site_logo_icon_ids();
        $featured  = $this->featured_ids(array_map(static fn ($row) => (int) $row->ID, $batch));

        $items         = [];
        $unattached    = [];
        $total_bytes   = 0;
        $scanned       = 0;
        $last_id       = $cursor;
        $stopped_early = false;

        foreach ($batch as $index => $row) {
            if ($index > 0 && (microtime(true) - $started) >= $budget) {
                $stopped_early = true;
                break;
            }

            $id      = (int) $row->ID;
            $last_id = $id;
            ++$scanned;

            $stems  = $this->path_stems($id);
            $checks = $stems ? self::CHECKS : array_values(array_diff(self::CHECKS, [ 'post_content_url', 'post_meta_url' ]));
            $used   = isset($featured[ $id ]) || isset($protected[ $id ]) || $this->is_referenced($id, $stems);

            if ($with_un && 0 === (int) $row->post_parent) {
                $unattached[] = [
                    'media_id' => $id,
                    'title'    => (string) $row->post_title,
                    'url'      => (string) wp_get_attachment_url($id),
                    'used'     => $used,
                ];
            }

            if ($used) {
                continue;
            }

            $bytes        = $this->size_on_disk($id);
            $total_bytes += $bytes;
            $items[]      = [
                'media_id'    => $id,
                'title'       => (string) $row->post_title,
                'url'         => (string) wp_get_attachment_url($id),
                'mime_type'   => (string) $row->post_mime_type,
                'date'        => (string) $row->post_date,
                'post_parent' => (int) $row->post_parent,
                'size_bytes'  => $bytes,
                'checks'      => $checks,
            ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- attachment count for the paging envelope; the mime clause is built from placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit', 'private') AND ID > %d{$mime_sql}", array_merge([ 0 ], $mime_args)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- attachments left after the cursor; the mime clause is built from placeholders.
        $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit', 'private') AND ID > %d{$mime_sql}", array_merge([ $last_id ], $mime_args)));

        $out = [
            'items'         => $items,
            'unused_count'  => count($items),
            'total_bytes'   => $total_bytes,
            'scanned'       => $scanned,
            'total'         => $total,
            'remaining'     => $remaining,
            'next_cursor'   => $remaining > 0 ? $last_id : null,
            'done'          => 0 === $remaining,
            'stopped_early' => $stopped_early,
            'per_page'      => $per_page,
            'checks'        => self::CHECKS,
            'not_checked'   => self::NOT_CHECKED,
        ];
        if ($with_un) {
            $out['unattached'] = $unattached;
        }

        return $out;
    }

    /** @return array{0:string,1:array} "image" matches image/*, "image/png" exactly. */
    private function mime_clause(string $type): array
    {
        global $wpdb;

        $type = trim($type);
        if ('' === $type) {
            return [ '', [] ];
        }
        if (false !== strpos($type, '/')) {
            return [ ' AND post_mime_type = %s', [ $type ] ];
        }

        return [ ' AND post_mime_type LIKE %s', [ $wpdb->esc_like($type) . '/%' ] ];
    }

    /** @return array<int,true> */
    private function site_logo_icon_ids(): array
    {
        $ids = [];
        // The raw theme mod: when site_logo is set, core filters
        // get_theme_mod('custom_logo') to return it instead, which would hide
        // a classic logo set separately.
        $mods = get_option('theme_mods_' . get_option('stylesheet'));
        $logo = is_array($mods) ? ($mods['custom_logo'] ?? null) : null;
        foreach ([ get_option('site_icon'), $logo, get_theme_mod('custom_logo'), get_option('site_logo') ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[ (int) $value ] = true;
            }
        }

        return $ids;
    }

    /**
     * @param int[] $ids
     * @return array<int,true>
     */
    private function featured_ids(array $ids): array
    {
        global $wpdb;

        if (! $ids) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one indexed _thumbnail_id lookup for the whole page; the IN list is built from placeholders.
        $found = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND p.post_type <> 'revision' AND pm.meta_value IN ({$in})", array_map('strval', $ids)));

        $out = [];
        foreach ($found as $value) {
            $out[ (int) $value ] = true;
        }

        return $out;
    }

    /**
     * Upload-relative path stems ("2024/01/photo") that every URL of the
     * attachment starts with: the original, its -scaled copy and each size
     * variant ("photo-300x200.jpg") alike.
     *
     * @return string[]
     */
    private function path_stems(int $id): array
    {
        $file = (string) get_post_meta($id, '_wp_attached_file', true);
        if ('' === $file) {
            return [];
        }
        if ('/' === $file[0] || false !== strpos($file, '://') || preg_match('#^[A-Za-z]:[\\\\/]#', $file)) {
            $file = wp_basename($file);
        }

        $dir   = dirname($file);
        $dir   = '.' === $dir ? '' : trailingslashit($dir);
        $names = [ wp_basename($file) ];
        $meta  = wp_get_attachment_metadata($id);
        if (is_array($meta) && ! empty($meta['original_image'])) {
            $names[] = wp_basename((string) $meta['original_image']);
        }

        $stems = [];
        foreach ($names as $name) {
            $stem = preg_replace('/\.[^.\/]+$/', '', $name);
            $stem = preg_replace('/-scaled$/', '', (string) $stem);
            if ('' !== (string) $stem) {
                $stems[] = $dir . $stem;
            }
        }

        return array_values(array_unique($stems));
    }

    /** @param string[] $stems */
    private function is_referenced(int $id, array $stems): bool
    {
        global $wpdb;

        $url = $this->url_likes($stems);
        $n   = (string) $id;

        // JSON ("id":5, "mediaId":"5", "ids":[4,5]) and serialized
        // (s:13:"attachment_id";i:5; or a string value "5") forms of an id.
        $json = [];
        foreach ([ 'id', 'Id' ] as $key) {
            foreach ([ ',', '}', ']' ] as $end) {
                $json[] = $key . '":' . $n . $end;
            }
            $json[] = $key . '":"' . $n . '"';
        }
        $arrays = [ 'ids":[' . $n . ',', 'ids":[' . $n . ']' ];
        $serial = [ 'id";i:' . $n . ';', 'Id";i:' . $n . ';', 's:' . strlen($n) . ':"' . $n . '";' ];

        $content = array_merge(
            $this->likes(array_merge([ 'wp-image-' . $n . '"', 'wp-image-' . $n . ' ', 'ids="' . $n . '"', 'ids="' . $n . ',' ], $json, $arrays)),
            [ $this->like_raw('ids="', ',' . $n . ','), $this->like_raw('ids="', ',' . $n . '"'), $this->like_raw('ids":[', ',' . $n . ','), $this->like_raw('ids":[', ',' . $n . ']') ],
            $url
        );
        $meta = array_merge(
            $this->likes(array_merge($json, $arrays, $serial)),
            [ $this->like_raw('ids":[', ',' . $n . ','), $this->like_raw('ids":[', ',' . $n . ']') ],
            $url
        );

        $content_or = $this->ors('post_content', count($content));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- reference scan of post content; every pattern is a placeholder.
        if (null !== $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND post_type <> 'revision' AND {$content_or} LIMIT 1", array_merge([ $id ], $content)))) {
            return true;
        }

        $ignored = implode(',', array_fill(0, count(self::IGNORED_META_KEYS), '%s'));
        $gallery = [ $n . ',%', '%,' . $n, '%,' . $n . ',%' ];
        $gallery_or = $this->ors('pm.meta_value', count($gallery));
        $meta_or    = $this->ors('pm.meta_value', count($meta));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- reference scan of post meta (builder data, custom fields); every pattern is a placeholder.
        if (null !== $wpdb->get_var($wpdb->prepare("SELECT pm.meta_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.post_id <> %d AND p.post_type <> 'revision' AND pm.meta_key NOT IN ({$ignored}) AND (pm.meta_value = %s OR (pm.meta_key = '_product_image_gallery' AND {$gallery_or}) OR {$meta_or}) LIMIT 1", array_merge([ $id ], self::IGNORED_META_KEYS, [ $n ], $gallery, $meta)))) {
            return true;
        }

        $term_or = $this->ors('meta_value', count($meta));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- reference scan of term meta (category thumbnails); every pattern is a placeholder.
        if (null !== $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_value = %s OR {$term_or} LIMIT 1", array_merge([ $n ], $meta)))) {
            return true;
        }

        $option_or = $this->ors('option_value', count($meta));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- reference scan of options (widgets, theme mods); every pattern is a placeholder.
        return null !== $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND {$option_or} LIMIT 1", array_merge([ $wpdb->esc_like('_transient_') . '%', $wpdb->esc_like('_site_transient_') . '%' ], $meta)));
    }

    /**
     * "/2024/01/photo." and "/2024/01/photo-", plain and with the escaped
     * slashes JSON builder data stores.
     *
     * @param string[] $stems
     * @return string[]
     */
    private function url_likes(array $stems): array
    {
        $needles = [];
        foreach ($stems as $stem) {
            foreach ([ '.', '-' ] as $next) {
                $plain     = '/' . $stem . $next;
                $needles[] = $plain;
                $needles[] = str_replace('/', '\\/', $plain);
            }
        }

        return $this->likes($needles);
    }

    /**
     * @param string[] $needles
     * @return string[]
     */
    private function likes(array $needles): array
    {
        global $wpdb;

        return array_map(static fn ($needle) => '%' . $wpdb->esc_like($needle) . '%', $needles);
    }

    /** A LIKE for $tail somewhere after $head, e.g. an id inside ids="...". */
    private function like_raw(string $head, string $tail): string
    {
        global $wpdb;

        return '%' . $wpdb->esc_like($head) . '%' . $wpdb->esc_like($tail) . '%';
    }

    private function ors(string $column, int $count): string
    {
        return '(' . implode(' OR ', array_fill(0, $count, $column . ' LIKE %s')) . ')';
    }

    private function size_on_disk(int $id): int
    {
        $file = get_attached_file($id);
        if (! is_string($file) || '' === $file) {
            return 0;
        }

        $dir   = trailingslashit(dirname($file));
        $paths = [ $file ];
        $meta  = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            if (! empty($meta['original_image'])) {
                $paths[] = $dir . wp_basename((string) $meta['original_image']);
            }
            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (is_array($size) && ! empty($size['file'])) {
                    $paths[] = $dir . wp_basename((string) $size['file']);
                }
            }
        }

        $bytes = 0;
        foreach (array_unique($paths) as $path) {
            if (is_file($path)) {
                $bytes += (int) filesize($path);
            }
        }

        return $bytes;
    }
}
