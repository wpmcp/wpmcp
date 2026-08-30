<?php

namespace WPMCP\Tools\Context;

use WPMCP\Tools\Builders\Builder_Detector;
use WPMCP\Tools\Content\Content_Extractor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: one-call normalized page digest (issue #81).
 *
 * Replaces the chain of read calls an agent otherwise burns reconstructing a
 * page: one response carries the structure summary, content outline, media
 * and link inventory, and builder detection for a post.
 *
 * Lives in Tools\Context, not Tools\Analysis, on purpose. This is a FREE
 * ability and the wp.org build removes src/Tools/Analysis and
 * register_analysis_abilities wholesale, so a free tool placed there would
 * be silently absent from the only build free users install. Same reasoning
 * for its Content_Extractor dependency, which now lives in Tools\Content.
 * tests/free/Platform/WporgFreeSurfaceTest.php is the gate that keeps this
 * from regressing.
 *
 * Sections model:
 *  - CORE sections always render: structure, outline, media, links,
 *    builder, seo_lite.
 *  - HEAVY sections are excluded by default and opt-in via the `sections`
 *    param: `global_tokens` (theme/global style tokens the stored content
 *    references) and `responsive_overrides` (per-breakpoint override counts
 *    for builder pages).
 *  - PRO OVERLAY sections attach through the `wpmcp_page_snapshot_sections`
 *    filter: the pro audit tools (analyze-seo / analyze-accessibility) hook
 *    it to append their overlay sections without any free-code change, and
 *    the free build renders cleanly without them because the filter simply
 *    has no callbacks. Section names the core does not know are passed
 *    through to the filter untouched, so an overlay's own opt-in name
 *    (e.g. "seo_audit") reaches its callback.
 *
 * CONTENT COVERAGE. Extraction reads the stored post_content. For Elementor
 * and Bricks the page body lives in postmeta, and for Divi post_content is
 * shortcode soup, so the content-derived sections cannot be measured from it.
 * Rather than reporting a fabricated-looking zero, the digest carries a
 * `content_coverage` block naming the source, whether it is complete, and
 * exactly which sections were not measured.
 *
 * The response is size-bounded twice: per-inventory item counts are capped,
 * individual strings are truncated, and a final byte budget is enforced over
 * whatever the overlay filter returned, with a `truncated` flag reporting it.
 *
 * Reads have nothing to roll back, so this never touches Safe_Mutation.
 */
class Get_Page_Snapshot
{
    /**
     * Hard cap on items kept per inventory list (headings, links, images).
     * Pathological pages beyond this report truncated=true instead of
     * growing the response unboundedly.
     */
    private const MAX_ITEMS = 200;

    /** Per-string caps, so 200 items cannot each carry a megabyte. */
    private const MAX_TEXT   = 300;
    private const MAX_URL    = 500;
    private const MAX_BLOCKS = 50;

    /** Final byte budget for the whole encoded digest, overlays included. */
    private const MAX_BYTES = 262144;

    /** Heavy sections that only render when explicitly requested. */
    private const OPT_IN_SECTIONS = ['global_tokens', 'responsive_overrides'];

    /** Builders whose page body is not stored in post_content. */
    private const OFF_CONTENT_BUILDERS = ['elementor', 'bricks'];

    /** Sections derived from the extracted content. */
    private const CONTENT_SECTIONS = ['outline', 'media', 'links', 'seo_lite', 'structure.word_count'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $post = get_post($post_id);
        if (! $post) {
            throw new \InvalidArgumentException('Post not found.');
        }

        // The ability's edit_posts gate says "this user edits content", not
        // "this user may read THIS post". Mirrors Search_Content: anything
        // not published has to survive a per-post read_post check, so a
        // contributor cannot digest another author's draft, a private post,
        // or a private CPT such as the memory guardrail posts.
        if ('publish' !== $post->post_status && ! current_user_can('read_post', $post_id)) {
            throw new \RuntimeException('You do not have permission to read that post.');
        }

        $all_requested = array_values(array_unique(array_filter(
            array_map('strval', (array) ($args['sections'] ?? [])),
            static fn (string $s): bool => '' !== $s
        )));
        $heavy = array_values(array_intersect($all_requested, self::OPT_IN_SECTIONS));

        $builder = Builder_Detector::detect($post_id);

        // A password-protected post's body is withheld from readers who have
        // not supplied the password, so it is not extracted here either.
        $locked  = post_password_required($post);
        $extract = $locked ? self::empty_extract($post_id) : Content_Extractor::extract($post_id);

        $coverage  = $this->content_coverage($builder, $locked, $extract);
        $truncated = false;
        $headings  = $this->cap($extract['headings'], $truncated);
        $links     = $this->cap($extract['links'], $truncated);
        $images    = $this->cap($extract['images'], $truncated);

        $snapshot = [
            'post_id'          => $post_id,
            'builder'          => $builder,
            'content_coverage' => $coverage,
            'structure'        => $this->structure_summary($post, $builder, $extract),
            'outline'          => array_map(
                fn (array $h): array => [
                    'level' => (int) $h['level'],
                    'text'  => $this->clip((string) $h['text'], self::MAX_TEXT),
                ],
                $headings
            ),
            'media'            => [
                'image_count' => count($extract['images']),
                'images'      => array_map(
                    fn (array $i): array => [
                        'src'      => $this->clip((string) ($i['src'] ?? ''), self::MAX_URL),
                        'alt'      => $this->clip((string) ($i['alt'] ?? ''), self::MAX_TEXT),
                        'location' => (string) ($i['location'] ?? ''),
                    ],
                    $images
                ),
            ],
            'links'            => [
                'link_count' => count($extract['links']),
                'internal'   => count(array_filter($extract['links'], static fn (array $l): bool => ! empty($l['internal']))),
                'external'   => count(array_filter($extract['links'], static fn (array $l): bool => empty($l['internal']))),
                'items'      => array_map(
                    fn (array $l): array => [
                        'url'      => $this->clip((string) ($l['url'] ?? ''), self::MAX_URL),
                        'text'     => $this->clip((string) ($l['text'] ?? ''), self::MAX_TEXT),
                        'internal' => ! empty($l['internal']),
                    ],
                    $links
                ),
            ],
            'seo_lite'         => $this->seo_lite($post, $extract),
            'truncated'        => $truncated,
        ];

        foreach ($heavy as $section) {
            $snapshot[$section] = 'global_tokens' === $section
                ? $this->global_tokens($post, $builder)
                : $this->responsive_overrides($post_id, $builder);
        }

        /**
         * Pro overlay seam (issue #81): pro audit tools attach overlay
         * sections (e.g. seo_audit, accessibility_audit) here without any
         * free-code change. Callbacks receive the digest built so far and
         * must return it, as an array, with their sections appended. A
         * callback that returns anything else is ignored rather than allowed
         * to replace the digest, and the core keys are re-asserted over the
         * result so an overlay cannot rewrite post_id or the cap flags.
         *
         * $requested carries EVERY section name the caller asked for,
         * including names the core does not know, which is how an overlay
         * gets its own opt-in signal.
         *
         * @param array    $snapshot  The digest built so far.
         * @param int      $post_id   The post being digested.
         * @param string[] $requested All section names the caller asked for.
         */
        $filtered = apply_filters('wpmcp_page_snapshot_sections', $snapshot, $post_id, $all_requested);

        if (is_array($filtered)) {
            $core     = ['post_id', 'builder', 'content_coverage', 'structure', 'outline', 'media', 'links', 'seo_lite'];
            $overlay  = $filtered;
            foreach ($core as $key) {
                $overlay[$key] = $snapshot[$key];
            }
            $snapshot = $overlay;
        }

        $snapshot['truncated'] = $truncated;

        return $this->enforce_byte_budget($snapshot);
    }

    /**
     * What the content-derived sections actually measured, so a consumer can
     * tell "no images on this page" apart from "images not extractable from
     * this storage".
     *
     * @param array<string,mixed> $extract
     * @return array<string,mixed>
     */
    private function content_coverage(string $builder, bool $locked, array $extract): array
    {
        if ($locked) {
            return [
                'source'      => 'none',
                'complete'    => false,
                'unmeasured'  => self::CONTENT_SECTIONS,
                'note'        => 'The post is password protected, so its content was not read.',
            ];
        }

        if (in_array($builder, self::OFF_CONTENT_BUILDERS, true) && [] === $extract['headings'] && [] === $extract['images'] && [] === $extract['links']) {
            return [
                'source'     => 'post_content',
                'complete'   => false,
                'unmeasured' => self::CONTENT_SECTIONS,
                'note'       => sprintf(
                    'This page was authored in %s, which stores its body in postmeta rather than post_content. The content-derived sections were not measured: their zeros mean "not extracted", not "none present".',
                    $builder
                ),
            ];
        }

        if ('divi' === $builder) {
            return [
                'source'     => 'post_content',
                'complete'   => false,
                'unmeasured' => ['outline', 'media', 'links'],
                'note'       => 'Divi stores its layout as shortcodes in post_content. word_count includes shortcode markup and the element inventory is partial.',
            ];
        }

        return [
            'source'     => 'post_content',
            'complete'   => true,
            'unmeasured' => [],
            'note'       => '',
        ];
    }

    /** The shape Content_Extractor returns for an unreadable body. */
    private static function empty_extract(int $post_id): array
    {
        return [
            'post_id'     => $post_id,
            'headings'    => [],
            'links'       => [],
            'images'      => [],
            'form_fields' => [],
            'text'        => '',
            'word_count'  => 0,
        ];
    }

    /**
     * Structure summary: element/block counts appropriate to how the page
     * was authored.
     */
    private function structure_summary(\WP_Post $post, string $builder, array $extract): array
    {
        $summary = [
            'word_count'    => (int) $extract['word_count'],
            'heading_count' => count($extract['headings']),
            'post_type'     => (string) $post->post_type,
            'post_status'   => (string) $post->post_status,
        ];

        if ('gutenberg' === $builder && function_exists('parse_blocks')) {
            $counts = [];
            $this->count_blocks(parse_blocks((string) $post->post_content), $counts);
            arsort($counts);
            $summary['block_count']  = array_sum($counts);
            $summary['block_counts'] = array_slice($counts, 0, self::MAX_BLOCKS, true);
        }

        if (in_array($builder, self::OFF_CONTENT_BUILDERS, true)) {
            $summary['element_count'] = $this->builder_element_count($post->ID, $builder);
        }

        return $summary;
    }

    /**
     * Element count read off the builder's own stored tree, which is the one
     * structural number available for a page whose body is not in
     * post_content. Null when the tree is missing or unparseable.
     */
    private function builder_element_count(int $post_id, string $builder): ?int
    {
        $raw = 'elementor' === $builder
            ? get_post_meta($post_id, '_elementor_data', true)
            : get_post_meta($post_id, '_bricks_page_content_2', true);

        $tree = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($tree)) {
            return null;
        }

        return $this->count_tree_nodes($tree);
    }

    /** @param array<int|string,mixed> $nodes */
    private function count_tree_nodes(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (isset($node['id']) || isset($node['elType']) || isset($node['name'])) {
                $count++;
            }
            foreach (['elements', 'children'] as $key) {
                if (! empty($node[$key]) && is_array($node[$key])) {
                    $count += $this->count_tree_nodes($node[$key]);
                }
            }
        }
        return $count;
    }

    /** Recursively tally block names, skipping parser artifacts (null names). */
    private function count_blocks(array $blocks, array &$counts): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (null !== $name && '' !== $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
            if (! empty($block['innerBlocks'])) {
                $this->count_blocks($block['innerBlocks'], $counts);
            }
        }
    }

    /**
     * Heavy section: which global style tokens the stored content references.
     * Reads the preset custom properties and the preset class names WordPress
     * itself emits, plus Elementor's global token references, so the answer
     * comes from storage rather than from rendering the page.
     *
     * @return array<string,mixed>
     */
    private function global_tokens(\WP_Post $post, string $builder): array
    {
        $haystack = (string) $post->post_content;
        if (in_array($builder, self::OFF_CONTENT_BUILDERS, true)) {
            $meta = 'elementor' === $builder
                ? get_post_meta($post->ID, '_elementor_data', true)
                : get_post_meta($post->ID, '_bricks_page_content_2', true);
            $haystack .= is_string($meta) ? $meta : wp_json_encode($meta);
        }

        $presets = [];
        if (preg_match_all('/--wp--preset--([a-z0-9-]+)--([a-z0-9-]+)/i', $haystack, $m)) {
            foreach ($m[1] as $i => $group) {
                $presets[$group][] = $m[2][$i];
            }
        }
        if (preg_match_all('/\bhas-([a-z0-9-]+)-(background-color|color|font-size|gradient-background)\b/i', $haystack, $m)) {
            foreach ($m[1] as $i => $slug) {
                $group = 'background-color' === $m[2][$i] || 'color' === $m[2][$i] ? 'color' : $m[2][$i];
                $presets[$group][] = $slug;
            }
        }
        foreach ($presets as $group => $slugs) {
            $presets[$group] = array_values(array_unique($slugs));
        }
        ksort($presets);

        $globals = [];
        if (preg_match_all('/globals\/(colors|typography)\?id=([a-z0-9_-]+)/i', $haystack, $m)) {
            foreach ($m[1] as $i => $group) {
                $globals[strtolower($group)][] = $m[2][$i];
            }
            foreach ($globals as $group => $ids) {
                $globals[$group] = array_values(array_unique($ids));
            }
        }

        return [
            'theme_presets'    => $presets,
            'builder_globals'  => $globals,
            'has_theme_json'   => function_exists('wp_theme_has_theme_json') ? (bool) wp_theme_has_theme_json() : null,
        ];
    }

    /**
     * Heavy section: per-breakpoint override counts. Elementor and Bricks
     * both store responsive values as suffixed setting keys, so the count of
     * those keys is a real measure of how much the page diverges per device.
     * Gutenberg has no equivalent stored per-breakpoint layer, which the
     * section says rather than implying zero overrides.
     *
     * @return array<string,mixed>
     */
    private function responsive_overrides(int $post_id, string $builder): array
    {
        if (! in_array($builder, self::OFF_CONTENT_BUILDERS, true)) {
            return [
                'supported'   => false,
                'breakpoints' => [],
                'note'        => sprintf('Per-breakpoint overrides are a builder concept; %s pages store none.', $builder),
            ];
        }

        $raw = 'elementor' === $builder
            ? get_post_meta($post_id, '_elementor_data', true)
            : get_post_meta($post_id, '_bricks_page_content_2', true);
        $tree = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($tree)) {
            return ['supported' => true, 'breakpoints' => [], 'note' => 'No builder tree stored for this post.'];
        }

        $counts = [];
        $this->tally_breakpoints($tree, $counts);
        arsort($counts);

        return [
            'supported'   => true,
            'breakpoints' => $counts,
            'note'        => '',
        ];
    }

    /**
     * @param array<int|string,mixed> $node
     * @param array<string,int>       $counts
     */
    private function tally_breakpoints(array $node, array &$counts): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && preg_match('/_(mobile|mobile_extra|tablet|tablet_extra|laptop|widescreen)$/', $key, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
            if (is_array($value)) {
                $this->tally_breakpoints($value, $counts);
            }
        }
    }

    /** SEO-lite: the cheap signals; the pro analyze-seo overlay goes deeper. */
    private function seo_lite(\WP_Post $post, array $extract): array
    {
        $h1s = array_values(array_filter(
            $extract['headings'],
            static fn (array $h): bool => 1 === (int) $h['level']
        ));

        $missing_alt = count(array_filter(
            $extract['images'],
            static fn (array $img): bool => '' === trim((string) ($img['alt'] ?? ''))
        ));

        return [
            'title'              => get_the_title($post),
            'title_length'       => mb_strlen(get_the_title($post)),
            'has_excerpt'        => '' !== trim((string) $post->post_excerpt),
            'h1_count'           => count($h1s),
            'images_missing_alt' => $missing_alt,
            'word_count'         => (int) $extract['word_count'],
        ];
    }

    /** Cap a list at MAX_ITEMS, flagging truncation. */
    private function cap(array $items, bool &$truncated): array
    {
        if (count($items) > self::MAX_ITEMS) {
            $truncated = true;
            return array_slice($items, 0, self::MAX_ITEMS);
        }
        return $items;
    }

    /** Clip one string to a character budget, marking that it was clipped. */
    private function clip(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max) . '...';
    }

    /**
     * Final bound, applied AFTER the overlay filter so an overlay cannot
     * append past it. Sheds the inventories worst-first until the encoded
     * digest fits, and reports that it did.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function enforce_byte_budget(array $snapshot): array
    {
        $size = static fn (array $s): int => strlen((string) wp_json_encode($s));

        $shedders = [
            static function (array &$s): bool {
                if (empty($s['links']['items'])) {
                    return false;
                }
                $s['links']['items'] = array_slice($s['links']['items'], 0, (int) floor(count($s['links']['items']) / 2));
                return true;
            },
            static function (array &$s): bool {
                if (empty($s['media']['images'])) {
                    return false;
                }
                $s['media']['images'] = array_slice($s['media']['images'], 0, (int) floor(count($s['media']['images']) / 2));
                return true;
            },
            static function (array &$s): bool {
                if (empty($s['outline'])) {
                    return false;
                }
                $s['outline'] = array_slice($s['outline'], 0, (int) floor(count($s['outline']) / 2));
                return true;
            },
        ];

        $guard = 0;
        while ($size($snapshot) > self::MAX_BYTES && $guard++ < 64) {
            $shed = false;
            foreach ($shedders as $shedder) {
                if ($shedder($snapshot)) {
                    $shed = true;
                    $snapshot['truncated'] = true;
                    break;
                }
            }
            if (! $shed) {
                break;
            }
        }

        return $snapshot;
    }
}
