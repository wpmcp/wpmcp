<?php

namespace WPMCP\Tools\Portable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The portable bundle format (issue #297): one versioned JSON document that
 * carries items out of this site's stores and into another site's, with no
 * cloud account in between.
 *
 *   { format: "wpmcp-bundle", version: 1, generated_at, items: [...], checksum }
 *
 * Block and widget items use the cloud asset shape Cloud_Push_Assets sends
 * ({type, name, title, spec}); a snippet item is {type: "snippet", name, code}.
 * Items carry no store id and no active flag: ids are site-local, and an
 * import always lands every item inactive.
 *
 * The checksum is "sha256:" over the canonical JSON of the items (associative
 * keys sorted at every depth), so a bundle re-serialized by a client with a
 * different key order still verifies, while any changed value does not. It
 * catches truncation and hand edits; it is not a signature, and every item is
 * re-validated by its own store on import regardless.
 */
class Bundle
{
    public const FORMAT    = 'wpmcp-bundle';
    public const VERSION   = 1;
    public const MAX_ITEMS = 500;

    /** @param array<int,mixed> $items */
    public static function build(array $items): array
    {
        $items = array_values($items);

        return [
            'format'       => self::FORMAT,
            'version'      => self::VERSION,
            'generated_at' => gmdate('c'),
            'items'        => $items,
            'checksum'     => self::checksum($items),
        ];
    }

    /** @param array<int,mixed> $items */
    public static function checksum(array $items): string
    {
        return 'sha256:' . hash('sha256', (string) wp_json_encode(self::canonical(array_values($items))));
    }

    /**
     * Decode and verify a bundle. Accepts the decoded object or its JSON
     * text, since a client may hand either back.
     *
     * @param mixed $raw
     * @return array|\WP_Error the verified bundle.
     */
    public static function verify($raw)
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || self::FORMAT !== ($raw['format'] ?? null)) {
            return new \WP_Error('bundle_invalid', sprintf('Not a %s document: pass the bundle object export-bundle returned.', self::FORMAT));
        }
        $version = $raw['version'] ?? null;
        if (! is_int($version) || $version < 1) {
            return new \WP_Error('bundle_invalid', 'The bundle has no valid version.');
        }
        if ($version > self::VERSION) {
            return new \WP_Error(
                'bundle_version_unsupported',
                sprintf('Bundle version %d is newer than this plugin reads (%d). Update the plugin on this site first.', $version, self::VERSION)
            );
        }
        $items = $raw['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            return new \WP_Error('bundle_invalid', 'The bundle items must be a list.');
        }
        if (count($items) > self::MAX_ITEMS) {
            return new \WP_Error('bundle_invalid', sprintf('The bundle holds %d items, over the %d item limit.', count($items), self::MAX_ITEMS));
        }
        $checksum = $raw['checksum'] ?? null;
        if (! is_string($checksum) || ! hash_equals(self::checksum($items), $checksum)) {
            return new \WP_Error('bundle_checksum_mismatch', 'The bundle checksum does not match its items: it was truncated or edited after export. Nothing was imported.');
        }

        return $raw;
    }

    /**
     * Sort associative keys at every depth; lists keep their order.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonical($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $child) {
            $out[ $key ] = self::canonical($child);
        }
        if (! array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }
}
