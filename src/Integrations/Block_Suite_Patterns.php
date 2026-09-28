<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The pattern side of the block suite packs (issue #287): browsing the
 * block pattern registry (core, theme and the patterns suites such as Otter
 * and Blocksy register there) by the suites each pattern uses, and bringing
 * a pattern's remote images into the Media Library before
 * Block_Suites_Integration::import_pattern() writes it.
 *
 * Every remote image goes through Remote_Image_Guard::sideload(), the same
 * path as every other remote media fetch: https only, the host allowlist
 * (wpmcp_remote_media_allowed_hosts) checked before any request, no
 * redirects, size caps and a byte-level image check. An image the guard
 * refuses is left at its URL and reported, never fetched another way.
 *
 * The WordPress.org Pattern Directory is a second source (issue #364):
 * list-patterns with source "directory" and "directory:<id>" names are
 * handed to Block_Suite_Pattern_Directory.
 */
final class Block_Suite_Patterns
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Most images one import fetches; the rest are kept at their URLs. */
    private const MAX_SIDELOADS = 20;

    private const IMAGE_URL = '#https?://[^\s"\'<>()\\\\]+?\.(?:jpe?g|png|gif|webp)(?:\?[^\s"\'<>()\\\\]*)?(?=[\s"\'<>()\\\\]|$)#i';

    public static function list_patterns(array $args): array
    {
        if (Block_Suite_Pattern_Directory::SOURCE === ($args['source'] ?? '')) {
            return Block_Suite_Pattern_Directory::list_patterns($args);
        }

        $suite    = (string) ($args['suite'] ?? '');
        $category = (string) ($args['category'] ?? '');
        $search   = strtolower(trim((string) ($args['search'] ?? '')));
        $limit    = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $offset   = max(0, (int) ($args['offset'] ?? 0));

        $matches = [];
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            $name       = (string) ($pattern['name'] ?? '');
            $title      = (string) ($pattern['title'] ?? '');
            $categories = array_values(array_map('strval', (array) ($pattern['categories'] ?? [])));
            if ('' !== $category && ! in_array($category, $categories, true)) {
                continue;
            }
            if ('' !== $search && ! str_contains(strtolower($name), $search) && ! str_contains(strtolower($title), $search)) {
                continue;
            }
            $content = (string) ($pattern['content'] ?? '');
            $suites  = array_keys(self::suites_in($content));
            sort($suites, SORT_STRING);
            if ('' !== $suite && ! in_array($suite, $suites, true)) {
                continue;
            }
            $matches[] = [
                'name'          => $name,
                'title'         => $title,
                'categories'    => $categories,
                'suites'        => $suites,
                'remote_images' => count(self::remote_images($content)),
            ];
        }

        return [
            'patterns' => array_slice($matches, $offset, $limit),
            'total'    => count($matches),
        ];
    }

    /** A registered or Pattern Directory pattern's content, or an unknown_pattern refusal. */
    public static function content(string $name): string
    {
        if (Block_Suite_Pattern_Directory::owns($name)) {
            return Block_Suite_Pattern_Directory::content($name);
        }
        $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered($name);
        if (! $pattern) {
            throw new Operation_Error('unknown_pattern', sprintf('Pattern "%s" is not registered. Browse them with list-patterns.', $name), [ 'name' => $name ]);
        }
        return (string) ($pattern['content'] ?? '');
    }

    /**
     * The suites whose blocks appear in block markup.
     *
     * @return array<string,bool>
     */
    public static function suites_in(string $content): array
    {
        $suites = [];
        if (preg_match_all('#<!--\s+wp:([a-z0-9-]+/)#', $content, $m)) {
            foreach (array_unique($m[1]) as $namespace) {
                $suite = Block_Suite::suite_of($namespace . 'x');
                if (null !== $suite) {
                    $suites[ $suite ] = true;
                }
            }
        }
        return $suites;
    }

    /**
     * The distinct image URLs in the markup that are not on this site.
     *
     * @return string[]
     */
    public static function remote_images(string $content): array
    {
        if (! preg_match_all(self::IMAGE_URL, str_replace('\\/', '/', $content), $m)) {
            return [];
        }
        $local = array_filter(array_map(
            static fn ($url) => strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST)),
            [ home_url(), site_url(), wp_upload_dir(null, false)['baseurl'] ?? '' ]
        ));
        $out = [];
        foreach (array_unique($m[0]) as $url) {
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ('' !== $host && ! in_array($host, $local, true)) {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * Sideload the markup's remote images through the remote image guard and
     * point the markup at the local copies. Each image is reported as
     * sideloaded (with media_id) or kept (with the reason).
     *
     * A source with its own image hosts (the Pattern Directory) passes them
     * with the filter that extends them; null keeps the remote media list.
     *
     * @param string[]|null $hosts
     * @return array{content:string,images:array<int,array<string,mixed>>,map:array<string,array{url:string,id:int}>}
     */
    public static function import_images(string $content, int $post_id, bool $sideload, ?array $hosts = null, string $filter = 'wpmcp_remote_media_allowed_hosts'): array
    {
        $images = [];
        $map    = [];
        foreach (self::remote_images($content) as $i => $url) {
            if (! $sideload) {
                $images[] = [ 'url' => $url, 'status' => 'kept', 'reason' => 'sideload_images is false.' ];
                continue;
            }
            if ($i >= self::MAX_SIDELOADS) {
                $images[] = [ 'url' => $url, 'status' => 'kept', 'reason' => sprintf('Only %d images are sideloaded per import.', self::MAX_SIDELOADS) ];
                continue;
            }
            try {
                $media = Remote_Image_Guard::sideload($url, $post_id, 'pattern-image', $hosts, $filter);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $images[] = [ 'url' => $url, 'status' => 'kept', 'reason' => $e->getMessage() ];
                continue;
            }
            $local       = (string) wp_get_attachment_url($media);
            $map[ $url ] = [ 'url' => $local, 'id' => $media ];
            $content     = str_replace(
                [ $url, str_replace('/', '\\/', $url) ],
                [ $local, str_replace('/', '\\/', $local) ],
                $content
            );
            $images[]    = [ 'url' => $url, 'status' => 'sideloaded', 'media_id' => $media, 'local_url' => $local ];
        }
        return [ 'content' => $content, 'images' => $images, 'map' => $map ];
    }

    /**
     * Point an attribute object that pairs a sideloaded image's url with an
     * attachment id ({"id":261,"url":"..."}, as suites store backgrounds) at
     * the new attachment, through the block and its inner blocks.
     *
     * @param array<string,array{url:string,id:int}> $map original url => local copy
     */
    public static function relink_attachments(array $node, array $map): array
    {
        if ([] === $map) {
            return $node;
        }
        $by_local = [];
        foreach ($map as $copy) {
            $by_local[ $copy['url'] ] = $copy['id'];
        }
        if (is_array($node['attrs'] ?? null)) {
            $node['attrs'] = self::relink($node['attrs'], $by_local);
        }
        foreach ((array) ($node['innerBlocks'] ?? []) as $i => $child) {
            $node['innerBlocks'][ $i ] = self::relink_attachments($child, $map);
        }
        return $node;
    }

    /** @param array<string,int> $by_local local url => attachment id */
    private static function relink(array $attrs, array $by_local): array
    {
        if (isset($attrs['url'], $attrs['id']) && is_string($attrs['url']) && isset($by_local[ $attrs['url'] ])) {
            $attrs['id'] = $by_local[ $attrs['url'] ];
        }
        foreach ($attrs as $key => $value) {
            if (is_array($value)) {
                $attrs[ $key ] = self::relink($value, $by_local);
            }
        }
        return $attrs;
    }

    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
