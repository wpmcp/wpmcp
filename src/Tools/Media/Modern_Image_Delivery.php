<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Serve the WebP/AVIF copies optimize-media writes (issue #432), when the
 * wpmcp_serve_modern_images option is on (off by default).
 *
 * An image is wrapped in <picture> with one <source> per modern format and
 * the original <img> left inside, untouched, as the fallback. A format gets
 * a <source> only when EVERY candidate in the image's srcset (or its src)
 * has a copy on disk that optimize-media recorded: a partial source would
 * make the browser pick the wrong resolution. Anything else, including an
 * image with no copies, is returned exactly as it came.
 *
 * Built to sit behind a page cache and beside other plugins:
 *  - the markup is the same for every request (no Accept sniffing, no
 *    Vary header), so a cached page is right for every browser, and a
 *    browser without the format falls back to the <img> by itself;
 *  - an active image-optimization plugin serves its own copies, so while
 *    one is active nothing is rewritten, the same deferral optimize-media
 *    makes (and on the same cached plugin scan);
 *  - an <img> already inside a <picture>, or one a lazy loader has
 *    rewritten (data-src, data-srcset, data-lazy-*), is left alone;
 *  - URLs outside the uploads directory (a CDN rewrite) are left alone,
 *    since the copy's existence cannot be checked there.
 *
 * Covers post content (the_content, after core adds srcset) and images a
 * theme renders through wp_get_attachment_image() (featured images,
 * galleries). Not in wp-admin or feeds.
 */
class Modern_Image_Delivery
{
    /** Most compact first: the browser takes the first <source> it supports. */
    private const FORMATS = [
        'avif' => 'image/avif',
        'webp' => 'image/webp',
    ];

    public static function register(): void
    {
        // After do_shortcode (11) and wp_filter_content_tags (12), so
        // shortcode output is seen and srcset is already on the image.
        add_filter('the_content', [ self::class, 'filter_content' ], 20);
        add_filter('wp_get_attachment_image', [ self::class, 'filter_attachment_image' ], 10, 2);
    }

    private static function active(): bool
    {
        if (is_admin() || is_feed()) {
            return false;
        }
        if (! Optimize_Uploads::enabled(Optimize_Uploads::DELIVERY_OPTION)) {
            return false;
        }
        return [] === Optimize_Media::active_optimizers();
    }

    /**
     * @internal the_content callback
     *
     * @param mixed $content
     * @return mixed
     */
    public static function filter_content($content)
    {
        if (! is_string($content) || false === stripos($content, '<img') || ! self::active()) {
            return $content;
        }

        return (string) preg_replace_callback(
            '#<picture\b.*?</picture\s*>|<img\b[^>]*>#is',
            static function (array $m): string {
                if (0 === stripos($m[0], '<picture')) {
                    return $m[0];
                }
                return preg_match('/\bwp-image-(\d+)\b/', $m[0], $id) ? self::wrap($m[0], (int) $id[1]) : $m[0];
            },
            $content
        );
    }

    /**
     * @internal wp_get_attachment_image callback
     *
     * @param mixed $html
     * @param mixed $attachment_id
     * @return mixed
     */
    public static function filter_attachment_image($html, $attachment_id)
    {
        if (! is_string($html) || false !== stripos($html, '<picture') || ! self::active()) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            static fn (array $m): string => self::wrap($m[0], (int) $attachment_id),
            $html
        );
    }

    /** $img wrapped in <picture> with a <source> per format that fully exists, or $img as it was. */
    private static function wrap(string $img, int $id): string
    {
        if ($id <= 0 || preg_match('/\sdata-(?:src|srcset|lazy[\w-]*)\s*=/i', $img)) {
            return $img;
        }
        $record = get_post_meta($id, Optimize_Media::META_KEY, true);
        if (! is_array($record) || empty($record['formats'])) {
            return $img;
        }

        $tag = new \WP_HTML_Tag_Processor($img);
        if (! $tag->next_tag('img')) {
            return $img;
        }
        $candidates = self::candidates($tag->get_attribute('srcset'), $tag->get_attribute('src'));
        if ([] === $candidates) {
            return $img;
        }
        $sizes = $tag->get_attribute('sizes');
        // "auto" is an <img loading=lazy> keyword that a <source> cannot use.
        $sizes = is_string($sizes) ? trim((string) preg_replace('/^\s*auto\s*(,|$)/i', '', $sizes)) : '';

        $sources = '';
        foreach (self::FORMATS as $format => $mime) {
            $srcset = self::srcset($candidates, (array) ($record['formats'][ $format ] ?? []), $format);
            if (null === $srcset) {
                continue;
            }
            $sources .= sprintf(
                '<source type="%s" srcset="%s"%s>',
                esc_attr($mime),
                esc_attr($srcset),
                '' === $sizes ? '' : ' sizes="' . esc_attr($sizes) . '"'
            );
        }

        return '' === $sources ? $img : '<picture>' . $sources . $img . '</picture>';
    }

    /**
     * @param mixed $srcset
     * @param mixed $src
     * @return array<int,array{0:string,1:string}> [url, descriptor] pairs
     */
    private static function candidates($srcset, $src): array
    {
        $out = [];
        if (is_string($srcset) && '' !== trim($srcset)) {
            foreach (explode(',', $srcset) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate), 2);
                if (! empty($parts[0])) {
                    $out[] = [ (string) $parts[0], (string) ($parts[1] ?? '') ];
                }
            }
            return $out;
        }
        return is_string($src) && '' !== $src ? [ [ $src, '' ] ] : [];
    }

    /**
     * The srcset for one format, or null unless every candidate has a
     * recorded copy on disk.
     *
     * @param array<int,array{0:string,1:string}> $candidates
     * @param string[]                            $copies basenames optimize-media recorded
     */
    private static function srcset(array $candidates, array $copies, string $format): ?string
    {
        if ([] === $copies) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $base    = set_url_scheme((string) $uploads['baseurl'], 'http');
        $entries = [];
        foreach ($candidates as [$url, $descriptor]) {
            $url      = html_entity_decode($url, ENT_QUOTES);
            $url      = (string) strtok($url, '?#');
            $relative = str_starts_with(set_url_scheme($url, 'http'), $base . '/') ? substr(set_url_scheme($url, 'http'), strlen($base)) : null;
            if (null === $relative || str_contains($relative, '..')) {
                return null;
            }
            $copy = $relative . '.' . $format;
            if (! in_array(basename($copy), $copies, true) || ! is_file($uploads['basedir'] . $copy)) {
                return null;
            }
            $entries[] = trim($url . '.' . $format . ' ' . $descriptor);
        }
        return implode(', ', $entries);
    }
}
