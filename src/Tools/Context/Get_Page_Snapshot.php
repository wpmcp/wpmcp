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
 *  - Every requested name is accounted for in the `sections` block:
 *    `rendered` (produced something), `unknown` (nothing on this build knows
 *    it), `withheld` (not run because the content was withheld) and
 *    `dropped` (rendered, then shed to fit the byte budget). Without it an
 *    agent asking a free build for a pro overlay section gets a
 *    normal-looking digest and no signal that the section is unavailable.
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
 * exactly which sections were not measured. That verdict is keyed off the
 * BUILDER, never off whether extraction happened to return rows: a page
 * converted to a builder normally keeps its pre-conversion post_content, so
 * extraction succeeds and describes markup the page does not render, which
 * `stale_post_content` says out loud.
 *
 * The response is size-bounded three ways: per-inventory item counts are
 * capped, individual strings are truncated, and a final byte budget is
 * enforced over whatever the overlay filter returned. That last one sheds the
 * inventories first and then drops whole non-core sections, overlay sections
 * included, so the bound holds no matter what the filter appended; every drop
 * is named in `sections.dropped` and flips `truncated`.
 *
 * ACCESS. Gated at edit_posts like the other reads, then two per-post
 * checks. Anything not published must survive current_user_can('read_post'),
 * mirroring Search_Content. And a post whose TYPE is not viewable must
 * survive current_user_can('edit_post') even when published: the agent
 * memory guardrail entries are published posts of a public=false CPT whose
 * own abilities are all manage_options, and read_post on a published post
 * maps to the type's plain read cap, which every contributor holds, so the
 * status check alone would leak them.
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

    /**
     * Final byte budget for the whole encoded digest, overlays included.
     * Public so a test can assert the real bound rather than a number it
     * invented.
     */
    public const MAX_BYTES = 262144;

    /**
     * Keys the core owns end to end. They are re-asserted over whatever the
     * overlay filter returned, and they are the last thing the byte budget
     * touches: everything outside this set is shed first.
     */
    private const CORE_KEYS = [
        'post_id', 'builder', 'content_coverage', 'structure',
        'outline', 'media', 'links', 'seo_lite', 'truncated', 'sections',
    ];

    /** Heavy sections that only render when explicitly requested. */
    private const OPT_IN_SECTIONS = ['global_tokens', 'responsive_overrides'];

    /** Builders whose page body is not stored in post_content. */
    private const OFF_CONTENT_BUILDERS = ['elementor', 'bricks'];

    /**
     * Sections derived from the extracted content, as paths into the digest.
     * A dotted entry names one field inside a section that is otherwise
     * measured: structure's block and element counts come from storage, its
     * word_count does not.
     */
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
        // contributor cannot digest another author's draft or a private post.
        if ('publish' !== $post->post_status && ! current_user_can('read_post', $post_id)) {
            throw new \RuntimeException('You do not have permission to read that post.');
        }

        // Published is not enough on its own. A non-viewable post type is
        // internal machinery rather than site content: the agent memory
        // guardrail entries are PUBLISHED posts of a public=false CPT whose
        // own abilities are all gated at manage_options, and read_post on a
        // published post maps to the type's plain read cap, which every
        // contributor holds. For a non-viewable type require instead that the
        // caller could edit the entry, which routes through the type's own
        // capability map.
        $type_object = get_post_type_object($post->post_type);
        $viewable    = $type_object instanceof \WP_Post_Type ? is_post_type_viewable($type_object) : false;
        if (! $viewable && ! current_user_can('edit_post', $post_id)) {
            throw new \RuntimeException('You do not have permission to read that post.');
        }

        $all_requested = array_values(array_unique(array_filter(
            array_map('strval', (array) ($args['sections'] ?? [])),
            static fn (string $s): bool => '' !== $s
        )));
        $heavy = array_values(array_intersect($all_requested, self::OPT_IN_SECTIONS));

        $builder = Builder_Detector::detect($post_id);

        // A password-protected post's body is withheld from callers who have
        // not supplied the password AND cannot edit the post. The password
        // prompt is a visitor-facing cookie check rather than a capability,
        // so applying it to an editor of the post would add no boundary (the
        // same body comes back from wpmcp/get-post) while making the digest
        // disagree with the surface it summarizes. Where it does apply,
        // NOTHING reads the stored body: not the extractor, not the block
        // parser, not the builder tree, not the token regexes.
        $locked  = post_password_required($post) && ! current_user_can('edit_post', $post_id);
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
            'structure'        => $this->structure_summary($post, $builder, $extract, $locked),
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
                ? $this->global_tokens($post, $builder, $locked)
                : $this->responsive_overrides($post_id, $builder, $locked);
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
         * A callback that answers by invoking another ability's handler MUST
         * re-check that ability through Registrar::is_permitted() first: the
         * capability, governance and memory-guardrail gates belong to the
         * ability being run, not to this one, and this filter runs inside a
         * call that only cleared get-page-snapshot's own edit_posts gate. The
         * pro overlay test carries that pattern verbatim. The seam is not
         * reached at all for a post whose content this call withheld.
         *
         * @param array    $snapshot  The digest built so far.
         * @param int      $post_id   The post being digested.
         * @param string[] $requested All section names the caller asked for.
         */
        // Withheld body, no overlay. An overlay section is computed by a
        // separate handler that would read the post afresh, and a handler
        // invoked from inside this filter has not passed this ability's own
        // gates; running one here would let an overlay reinstate a body the
        // core deliberately did not read. So when the content is withheld the
        // seam does not run at all, and the requested overlay names come back
        // reported as withheld rather than silently missing.
        $withheld = [];
        if ($locked) {
            $withheld = array_values(array_diff($all_requested, $heavy));
        } else {
            $filtered = apply_filters('wpmcp_page_snapshot_sections', $snapshot, $post_id, $all_requested);
        }

        if (! $locked && is_array($filtered)) {
            // Re-assert everything the core computed: the core keys AND the
            // heavy sections the caller explicitly opted into, which the core
            // and not the overlay produced. An overlay may only ADD keys.
            $overlay = $filtered;
            foreach (array_merge(self::CORE_KEYS, $heavy) as $key) {
                if (array_key_exists($key, $snapshot)) {
                    $overlay[$key] = $snapshot[$key];
                }
            }
            // An overlay that truncated its OWN section says so through this
            // flag; ORing keeps that rather than resetting it to the core's
            // answer about the core's own lists.
            $truncated = $truncated || ! empty($filtered['truncated']);
            $snapshot  = $overlay;
        }

        $snapshot['truncated'] = $truncated;

        // Which section names actually produced something, and which the
        // caller asked for that nothing on this build knows. Without this an
        // agent asking for a pro overlay section on a free build gets a
        // normal-looking digest and no signal that the section is simply not
        // available here; the schema deliberately carries no enum, so this is
        // the only place that distinction can be made.
        $rendered = array_values(array_filter(
            $all_requested,
            static fn (string $name): bool => array_key_exists($name, $snapshot) && ! in_array($name, self::CORE_KEYS, true)
        ));
        $snapshot['sections'] = [
            'rendered' => $rendered,
            'unknown'  => array_values(array_diff($all_requested, $rendered, $withheld)),
            'withheld' => $withheld,
            'dropped'  => [],
        ];

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
                'source'             => 'none',
                'complete'           => false,
                'unmeasured'         => self::CONTENT_SECTIONS,
                'stale_post_content' => false,
                'note'               => 'The post is password protected and you cannot edit it, so its content was not read.',
            ];
        }

        // Keyed off the BUILDER, never off whether extraction happened to
        // return rows. A page converted to Elementor or Bricks normally keeps
        // its pre-conversion post_content, so extraction succeeds and returns
        // an outline, media and link inventory taken from markup the page
        // does not render. Reporting that as complete coverage is the exact
        // fabrication this block exists to prevent, so the stale rows are
        // reported as stale rather than as measurements.
        if (in_array($builder, self::OFF_CONTENT_BUILDERS, true)) {
            $stale = [] !== $extract['headings'] || [] !== $extract['images'] || [] !== $extract['links'] || 0 < (int) $extract['word_count'];

            return [
                'source'             => 'post_content',
                'complete'           => false,
                'unmeasured'         => self::CONTENT_SECTIONS,
                'stale_post_content' => $stale,
                'note'               => sprintf(
                    $stale
                        ? 'This page was authored in %s, which stores its body in postmeta rather than post_content. The content-derived sections below were measured from leftover post_content, which is not what the page renders.'
                        : 'This page was authored in %s, which stores its body in postmeta rather than post_content. The content-derived sections were not measured: their zeros mean "not extracted", not "none present".',
                    $builder
                ),
            ];
        }

        if ('divi' === $builder) {
            return [
                'source'             => 'post_content',
                'complete'           => false,
                // seo_lite (h1_count, images_missing_alt) is derived from the
                // same unparsed shortcode soup as the inventories, so it is
                // listed here too rather than reading as a measurement.
                'unmeasured'         => self::CONTENT_SECTIONS,
                'stale_post_content' => false,
                'note'               => 'Divi stores its layout as shortcodes in post_content. word_count includes shortcode markup and the element inventory is partial.',
            ];
        }

        return [
            'source'             => 'post_content',
            'complete'           => true,
            'unmeasured'         => [],
            'stale_post_content' => false,
            'note'               => '',
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
    private function structure_summary(\WP_Post $post, string $builder, array $extract, bool $locked = false): array
    {
        $summary = [
            'word_count'    => (int) $extract['word_count'],
            'heading_count' => count($extract['headings']),
            'post_type'     => (string) $post->post_type,
            'post_status'   => (string) $post->post_status,
        ];

        // Withheld body: no block parse, no builder tree read. The
        // content_coverage note says the content was not read, and this is
        // what makes that true rather than only true of the extractor.
        if ($locked) {
            return $summary;
        }

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
    private function global_tokens(\WP_Post $post, string $builder, bool $locked = false): array
    {
        if ($locked) {
            return [
                'theme_presets'   => [],
                'builder_globals' => [],
                'has_theme_json'  => function_exists('wp_theme_has_theme_json') ? (bool) wp_theme_has_theme_json() : null,
                'note'            => 'The post is password protected, so its stored content was not read.',
            ];
        }

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
        // Lazy slug, longest suffix first: `has-pale-pink-background-color`
        // is the `pale-pink` colour preset. A greedy slug with `color` ahead
        // of `background-color` in the alternation reports it as the slug
        // `pale-pink-background`, a token that never existed, and that is the
        // single most common preset class Gutenberg emits. Groups are
        // normalized onto the same vocabulary as the
        // --wp--preset--<group>--<slug> custom properties, so a page that
        // references one token both ways reports it once.
        $class_groups = [
            'background-color'    => 'color',
            'gradient-background' => 'gradient',
            'font-size'           => 'font-size',
            'color'               => 'color',
        ];
        if (preg_match_all('/\bhas-([a-z0-9-]+?)-(background-color|gradient-background|font-size|color)\b/i', $haystack, $m)) {
            foreach ($m[1] as $i => $slug) {
                $group             = $class_groups[strtolower($m[2][$i])] ?? strtolower($m[2][$i]);
                $presets[$group][] = strtolower($slug);
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
            'note'             => '',
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
    private function responsive_overrides(int $post_id, string $builder, bool $locked = false): array
    {
        if ($locked) {
            return [
                'supported'   => in_array($builder, self::OFF_CONTENT_BUILDERS, true),
                'breakpoints' => [],
                'note'        => 'The post is password protected, so its builder tree was not read.',
            ];
        }

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
            // Elementor suffixes the setting key (`padding_tablet`); Bricks
            // suffixes it after a colon (`_padding:tablet_portrait`). Both
            // are stored keys, so both are countable, and a Bricks page whose
            // tally came back empty would be exactly the misleading zero this
            // section exists to avoid.
            if (is_string($key) && preg_match('/_(mobile|mobile_extra|tablet|tablet_extra|laptop|widescreen)$/', $key, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            } elseif (is_string($key) && preg_match('/:(desktop|tablet_portrait|tablet_landscape|mobile_portrait|mobile_landscape)$/', $key, $m)) {
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
     * append past it.
     *
     * Order matters. The inventories are halved first, because losing half a
     * link list is the cheapest possible loss. When that is not enough the
     * non-core sections go entirely, largest first: those are the opt-in
     * heavy sections and whatever the overlay filter appended, and dropping
     * one of them is what makes the budget a real bound rather than a bound
     * on the sections the core happens to know how to shrink. Every drop is
     * named in sections.dropped and flips `truncated`, so a consumer is never
     * handed a silently amputated digest.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function enforce_byte_budget(array $snapshot): array
    {
        $size = static fn (array $s): int => strlen((string) wp_json_encode($s));
        if ($size($snapshot) <= self::MAX_BYTES) {
            return $snapshot;
        }

        $halve = static function (array &$s, array $path): bool {
            $list = 1 === count($path) ? ($s[$path[0]] ?? null) : ($s[$path[0]][$path[1]] ?? null);
            if (! is_array($list) || [] === $list) {
                return false;
            }
            $kept = array_slice($list, 0, (int) floor(count($list) / 2));
            if (1 === count($path)) {
                $s[$path[0]] = $kept;
            } else {
                $s[$path[0]][$path[1]] = $kept;
            }
            return true;
        };

        $guard = 0;
        while ($size($snapshot) > self::MAX_BYTES && $guard++ < 128) {
            $shed = false;
            foreach ([['links', 'items'], ['media', 'images'], ['outline']] as $path) {
                if ($halve($snapshot, $path)) {
                    $shed = true;
                    $snapshot['truncated'] = true;
                    break;
                }
            }
            if ($shed) {
                continue;
            }

            // Inventories exhausted: drop whole non-core sections, biggest
            // first. This is the step that bounds an overlay.
            $sizes = [];
            foreach ($snapshot as $key => $value) {
                if (in_array($key, self::CORE_KEYS, true)) {
                    continue;
                }
                $sizes[$key] = strlen((string) wp_json_encode($value));
            }
            if ([] !== $sizes) {
                arsort($sizes);
                $victim = (string) array_key_first($sizes);
                unset($snapshot[$victim]);
                $snapshot['sections']['dropped'][] = $victim;
                $snapshot['sections']['rendered']  = array_values(array_diff(
                    $snapshot['sections']['rendered'] ?? [],
                    [$victim]
                ));
                $snapshot['truncated'] = true;
                continue;
            }

            // Core only, still over budget: a single pathological string
            // somewhere in it. Empty the inventories outright and, failing
            // that, the block tally, rather than returning something over the
            // cap. structure/seo_lite/content_coverage are bounded by their
            // own per-string caps.
            if ([] !== ($snapshot['links']['items'] ?? []) || [] !== ($snapshot['media']['images'] ?? []) || [] !== ($snapshot['outline'] ?? [])) {
                $snapshot['links']['items']  = [];
                $snapshot['media']['images'] = [];
                $snapshot['outline']         = [];
                $snapshot['truncated']       = true;
                continue;
            }
            if (! empty($snapshot['structure']['block_counts'])) {
                $snapshot['structure']['block_counts'] = [];
                $snapshot['truncated']                 = true;
                continue;
            }
            break;
        }

        return $snapshot;
    }
}
