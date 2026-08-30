<?php

namespace WPMCP\Tools\Analysis;

use WPMCP\Tools\Builders\Builder_Detector;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: one-call normalized page digest (issue #81).
 *
 * Replaces the chain of read calls an agent otherwise burns reconstructing a
 * page: one response carries the structure summary, content outline, media
 * and link inventory, and builder detection for any post, whether it was
 * authored in Gutenberg, a builder, or the classic editor.
 *
 * Sections model:
 *  - CORE sections always render: structure, outline, media, links,
 *    builder, seo_lite.
 *  - HEAVY sections are excluded by default and opt-in via the `sections`
 *    param (e.g. sections: ["global_tokens", "responsive_overrides"]).
 *  - PRO OVERLAY sections attach through the `wpmcp_page_snapshot_sections`
 *    filter: the pro audit tools (analyze-seo / analyze-accessibility) hook
 *    it to append their overlay sections without any free-code change, and
 *    the free build renders cleanly without them because the filter simply
 *    has no callbacks.
 *
 * The response is size-bounded: per-inventory item counts are capped and a
 * `truncated` flag reports when a pathological page hit the cap, so the
 * digest never balloons past what a client can hold.
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

    /** Heavy sections that only render when explicitly requested. */
    private const OPT_IN_SECTIONS = ['global_tokens', 'responsive_overrides'];

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

        $requested = array_values(array_intersect(
            array_map('strval', (array) ($args['sections'] ?? [])),
            self::OPT_IN_SECTIONS
        ));

        $builder = Builder_Detector::detect($post_id);
        $extract = Content_Extractor::extract($post_id);

        $truncated = false;
        $headings  = $this->cap($extract['headings'], $truncated);
        $links     = $this->cap($extract['links'], $truncated);
        $images    = $this->cap($extract['images'], $truncated);

        $snapshot = [
            'post_id'   => $post_id,
            'builder'   => $builder,
            'structure' => $this->structure_summary($post, $builder, $extract),
            'outline'   => array_map(
                static fn (array $h): array => ['level' => $h['level'], 'text' => $h['text']],
                $headings
            ),
            'media'     => [
                'image_count' => count($extract['images']),
                'images'      => $images,
            ],
            'links'     => [
                'link_count' => count($extract['links']),
                'internal'   => count(array_filter($extract['links'], static fn (array $l): bool => ! empty($l['internal']))),
                'external'   => count(array_filter($extract['links'], static fn (array $l): bool => empty($l['internal']))),
                'items'      => $links,
            ],
            'seo_lite'  => $this->seo_lite($post, $extract),
            'truncated' => $truncated,
        ];

        // TODO(#81): implement the heavy opt-in sections. global_tokens should
        // report which theme.json / builder global styles the page references;
        // responsive_overrides should summarize per-breakpoint overrides for
        // builder pages. Until then each requested section renders as a
        // 'not_implemented' stub so the sections contract is exercisable.
        foreach ($requested as $section) {
            $snapshot[$section] = ['status' => 'not_implemented'];
        }

        /**
         * Pro overlay seam (issue #81): pro audit tools attach overlay
         * sections (e.g. seo_audit, accessibility_audit) here without any
         * free-code change. Callbacks receive the digest built so far and
         * must return it with their sections appended.
         *
         * @param array    $snapshot  The digest built so far.
         * @param int      $post_id   The post being digested.
         * @param string[] $requested Opt-in sections the caller asked for.
         */
        $snapshot = (array) apply_filters('wpmcp_page_snapshot_sections', $snapshot, $post_id, $requested);

        return $snapshot;
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
            $summary['block_counts'] = $counts;
        }

        // TODO(#81): for elementor/bricks/divi, summarize element counts from
        // the builder's stored structure (Builders\Get_Builder_Content /
        // Elementor element tree) instead of only the rendered-HTML view.

        return $summary;
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
}
