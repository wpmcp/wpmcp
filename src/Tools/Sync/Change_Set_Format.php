<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Tools\Backup\Archive_Locator;
use WPMCP\Tools\Backup\Url_Rewriter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The change-set artifact format (issue #192): the rules both halves of a
 * local-live sync must agree on byte for byte.
 *
 * Determinism. Every list in an artifact is sorted and every map is key
 * sorted before it is hashed, and `checksum` is a SHA-256 over that
 * canonical form with the one volatile field (origin.created_at) left out.
 * Building the same change set twice from the same site state therefore
 * yields the same checksum, and an artifact that was truncated, hand-edited
 * or damaged in transit is refused by the apply side instead of being
 * half-applied.
 *
 * Content hashes. Conflict detection cannot compare raw values across two
 * sites: the local copy says http://site.local where the live copy says
 * https://example.com, and post_modified differs on sites that have never
 * diverged in content. So every syncable object is reduced to a projection
 * (the fields a sync writes, the meta keys it carries, its term slugs) with
 * the site's own URLs replaced by fixed tokens, and hashed. The same
 * projection computed on the target is what "modified on the target since
 * the base" means.
 *
 * Nothing in an artifact is trusted on the way back in. It may arrive inline
 * over MCP, so serialized meta is decoded with allowed_classes disabled and
 * the shape is validated before anything reads it.
 */
class Change_Set_Format
{
    /** Artifact format version, bumped on incompatible changes. */
    public const FORMAT_VERSION = 2;

    /** Token the origin's and target's home URL are normalized to before hashing. */
    private const HOME_TOKEN = 'https://wpmcp-sync-home.invalid';

    /** Token for the site (WordPress core) URL, when it differs from home. */
    private const SITE_TOKEN = 'https://wpmcp-sync-site.invalid';

    /**
     * Post columns a sync carries. IDs, GUIDs, authors and modification
     * stamps are site-local and are never pushed.
     */
    public const POST_FIELDS = [
        'post_type',
        'post_title',
        'post_content',
        'post_excerpt',
        'post_status',
        'post_name',
        'post_date',
        'post_date_gmt',
        'post_parent',
        'menu_order',
        'comment_status',
        'ping_status',
        'post_password',
        'post_mime_type',
    ];

    /**
     * Post columns that take part in the content hash. post_parent is an
     * id (site-local) and dates are identity, not content.
     */
    private const HASH_FIELDS = [
        'post_title',
        'post_content',
        'post_excerpt',
        'post_status',
        'post_name',
        'menu_order',
        'comment_status',
        'ping_status',
        'post_password',
    ];

    /**
     * Meta that is per-site bookkeeping or a regenerated cache. It is never
     * exported, never hashed and never written on the target: an edit lock
     * or a compiled Elementor stylesheet from the local site is noise at
     * best and a stale cache on live at worst. Attachment file meta is
     * handled by the attachment path, which regenerates it on the target.
     */
    public const VOLATILE_META = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_encloseme',
        '_pingme',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_desired_post_slug',
        '_elementor_css',
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_wp_attachment_backup_sizes',
    ];

    /**
     * Post types that are live-side data or pure bookkeeping. They are
     * excluded from export and refused on apply, whatever the artifact says:
     * pushing a local order table over a live store is the data-loss mode
     * the issue is written around. Filterable through
     * `wpmcp_sync_non_syncable_post_types` for stores with their own types.
     */
    private const NON_SYNCABLE_POST_TYPES = [
        'revision',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'shop_order',
        'shop_order_refund',
        'shop_order_placehold',
        'shop_subscription',
        'shop_coupon',
        'scheduled-action',
    ];

    /** Only these option families are syncable; everything else is site configuration. */
    public const SYNCABLE_OPTION_PREFIXES = ['theme_mods_'];

    /** @return string[] */
    public static function non_syncable_post_types(): array
    {
        $types = apply_filters('wpmcp_sync_non_syncable_post_types', self::NON_SYNCABLE_POST_TYPES);
        return array_values(array_map('strval', (array) $types));
    }

    public static function is_syncable_post_type(string $post_type): bool
    {
        return '' !== $post_type && ! in_array($post_type, self::non_syncable_post_types(), true);
    }

    public static function is_syncable_option(string $name): bool
    {
        foreach (self::SYNCABLE_OPTION_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function is_volatile_meta(string $key): bool
    {
        return in_array($key, self::VOLATILE_META, true);
    }

    /**
     * Recursively key-sort every associative array. Lists keep their order:
     * the caller sorts the lists whose order carries no meaning.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function canonical($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $is_list = array_is_list($value);
        $out     = [];
        foreach ($value as $key => $item) {
            $out[ $key ] = self::canonical($item);
        }
        if (! $is_list) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }

    /** SHA-256 of a value's canonical JSON. */
    public static function hash($value): string
    {
        return hash('sha256', (string) wp_json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The artifact's integrity checksum: everything except the checksum
     * itself and the build timestamp, so the same site state always hashes
     * the same.
     */
    public static function checksum(array $set): string
    {
        unset($set['checksum']);
        if (isset($set['origin']) && is_array($set['origin'])) {
            unset($set['origin']['created_at']);
        }
        return self::hash($set);
    }

    /**
     * The site URLs a value is normalized against: [url => token], longest
     * first, so a WordPress-in-a-subdirectory site URL is replaced before
     * the home URL it contains.
     *
     * @return array<string, string>
     */
    public static function url_tokens(string $home_url, string $site_url): array
    {
        $home = untrailingslashit($home_url);
        $site = untrailingslashit($site_url);

        $map = [];
        if ('' !== $site && $site !== $home) {
            $map[ $site ] = self::SITE_TOKEN;
        }
        if ('' !== $home) {
            $map[ $home ] = self::HOME_TOKEN;
        }
        uksort($map, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return $map;
    }

    /**
     * Replace a site's own URLs with the neutral tokens (serialization
     * aware), so values from two sites can be compared or hashed.
     *
     * @param mixed                 $value
     * @param array<string, string> $tokens url_tokens() output
     * @return mixed
     */
    public static function tokenize($value, array $tokens)
    {
        $rewriter = new Url_Rewriter();
        foreach ($tokens as $url => $token) {
            $value = $rewriter->rewrite_url($value, $url, $token);
        }
        return $value;
    }

    /**
     * Rewrite a value from the origin's URLs to the target's, via the
     * tokens. Going through the tokens (rather than origin -> target
     * directly, once per URL) means a target URL that contains an origin
     * URL (http://localhost to http://localhost:8080) can never be
     * rewritten twice.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function rewrite_urls($value, array $origin, string $target_home, string $target_site)
    {
        $from = self::url_tokens((string) ($origin['home_url'] ?? ''), (string) ($origin['site_url'] ?? ''));
        $to   = self::url_tokens($target_home, $target_site);
        if (array_keys($from) === array_keys($to)) {
            return $value;
        }

        $value    = self::tokenize($value, $from);
        $rewriter = new Url_Rewriter();
        foreach (array_flip($to) as $token => $url) {
            $value = $rewriter->rewrite_url($value, $token, $url);
        }
        return $value;
    }

    /**
     * The content projection a post's hash is computed over: the synced
     * columns, the given meta keys (absent keys recorded as null, so
     * "deleted" differs from "empty"), and the given taxonomies' term slugs.
     *
     * @param array                   $post       get_post(ARRAY_A)-shaped row
     * @param array<string, array>    $meta       raw get_post_meta() output
     * @param array<string, string[]> $terms      taxonomy => slugs
     * @param string[]                $meta_keys  keys the projection covers
     * @param string[]                $taxonomies taxonomies the projection covers
     */
    public static function post_hash(array $post, array $meta, array $terms, array $meta_keys, array $taxonomies, array $tokens): string
    {
        $fields = [];
        foreach (self::HASH_FIELDS as $field) {
            $fields[ $field ] = isset($post[ $field ]) ? (string) $post[ $field ] : '';
        }

        $meta_proj = [];
        foreach ($meta_keys as $key) {
            $meta_proj[ $key ] = isset($meta[ $key ]) ? array_values(array_map('strval', (array) $meta[ $key ])) : null;
        }

        $term_proj = [];
        foreach ($taxonomies as $taxonomy) {
            $slugs = array_values(array_map('strval', (array) ($terms[ $taxonomy ] ?? [])));
            sort($slugs, SORT_STRING);
            $term_proj[ $taxonomy ] = $slugs;
        }

        return self::hash(self::tokenize(['fields' => $fields, 'meta' => $meta_proj, 'terms' => $term_proj], $tokens));
    }

    /** The term fields a sync carries and hashes; the parent is a slug, never an id. */
    public static function term_projection(string $name, string $description, int $parent_id, string $taxonomy): array
    {
        $parent = null;
        if ($parent_id > 0) {
            $p      = get_term($parent_id, $taxonomy);
            $parent = $p instanceof \WP_Term ? $p->slug : null;
        }
        return ['name' => $name, 'description' => $description, 'parent' => $parent];
    }

    /**
     * Decode a raw (possibly serialized) meta or option value from an
     * artifact without ever instantiating an object. A value that holds one
     * is refused (null): an artifact can arrive inline over MCP, and
     * maybe_unserialize() on it would be a PHP object injection point.
     *
     * @param mixed $raw
     * @return array{0: bool, 1: mixed} [ok, value]
     */
    public static function safe_unserialize($raw): array
    {
        if (! is_string($raw) || ! is_serialized($raw)) {
            return [true, $raw];
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- decodes WP's own serialized meta format with allowed_classes disabled; an object is refused below.
        $value = @unserialize($raw, ['allowed_classes' => false]);
        if (false === $value && 'b:0;' !== $raw) {
            return [false, null];
        }
        if (self::contains_object($value)) {
            return [false, null];
        }
        return [true, $value];
    }

    /** @param mixed $value */
    private static function contains_object($value, int $depth = 0): bool
    {
        if (is_object($value)) {
            return true;
        }
        if (! is_array($value)) {
            return false;
        }
        if ($depth > 64) {
            return true;
        }
        foreach ($value as $item) {
            if (self::contains_object($item, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuse an artifact that is not one, is a different format version, or
     * does not match its own checksum.
     *
     * @throws \RuntimeException
     */
    public static function validate(array $set): void
    {
        $version = isset($set['format_version']) ? (int) $set['format_version'] : 0;
        if (self::FORMAT_VERSION !== $version) {
            throw new \RuntimeException(sprintf(
                'That artifact is change-set format version %d; this plugin reads version %d.',
                (int) $version,
                (int) self::FORMAT_VERSION
            ));
        }

        foreach (['origin', 'objects', 'dependencies'] as $key) {
            if (! isset($set[ $key ]) || ! is_array($set[ $key ])) {
                throw new \RuntimeException(sprintf('The change set is malformed: "%s" is missing.', esc_html($key)));
            }
        }

        foreach ($set['objects'] as $object) {
            if (! is_array($object) || empty($object['key']) || empty($object['object_type'])) {
                throw new \RuntimeException('The change set is malformed: an object entry has no key or type.');
            }
        }

        $checksum = (string) ($set['checksum'] ?? '');
        if ('' === $checksum || ! hash_equals(self::checksum($set), $checksum)) {
            throw new \RuntimeException(
                'The change set does not match its checksum: it was truncated or edited after it was built. Rebuild it on the origin site.'
            );
        }
    }

    /**
     * Resolve and read a change-set artifact from the site-backup directory.
     *
     * Containment is delegated to Archive_Locator::resolve(), the single
     * security boundary for path-taking backup tools. The resolved file must
     * look like something build-change-set wrote before a byte of it is
     * read: the same directory holds multi-GB site archives.
     *
     * @return array{0: string, 1: array} [real path, decoded artifact]
     * @throws \RuntimeException
     */
    public static function load_path(string $path): array
    {
        if ('' === trim($path)) {
            throw new \RuntimeException('Pass the path of a change-set artifact, as returned by build-change-set.');
        }

        try {
            $real = Archive_Locator::resolve(['path' => $path]);
        } catch (\RuntimeException $e) {
            if ('No such backup archive.' === $e->getMessage()) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not message text.
                throw new \RuntimeException('No such change-set artifact.', 0, $e);
            }
            throw $e;
        }

        $base = wp_basename($real);
        if (! str_starts_with($base, Build_Change_Set::ARTIFACT_PREFIX) || ! str_ends_with($base, '.json')) {
            throw new \RuntimeException(sprintf(
                'The file is not a change-set artifact: expected a %s*.json file written by build-change-set.',
                esc_html(Build_Change_Set::ARTIFACT_PREFIX)
            ));
        }

        $json = file_get_contents($real);
        $set  = false !== $json ? json_decode($json, true) : null;
        if (! is_array($set)) {
            throw new \RuntimeException('The file is not a readable change-set artifact.');
        }

        return [$real, $set];
    }
}
