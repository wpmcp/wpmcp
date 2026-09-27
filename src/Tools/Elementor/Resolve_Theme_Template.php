<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolver-style read for the theme builder: given a location (header, footer,
 * single, archive, ...) and an optional context (post_type / post_id), report
 * every candidate template with its display conditions, whether each condition
 * matches that context, and which template wins. Read-only.
 *
 * Resolution mirrors Elementor Pro's conditions manager closely enough to be
 * useful without Pro installed:
 *
 * - a template with no conditions is displayed nowhere (score -1);
 * - among matching `include` conditions the most specific one wins, where
 *   specificity is the number of parts after the verb (`include/general` = 1,
 *   `include/singular/post` = 2, `include/singular/post/12` = 3);
 * - an `exclude` knocks the candidate out only when it actually matches the
 *   requested context. A template carrying `include/general` plus
 *   `exclude/singular/page` still wins everywhere that is not a page.
 *
 * Context is optional and partial. A condition part the caller did not
 * constrain (no post_type given, say) is treated as unknown rather than as a
 * mismatch: includes stay eligible, and excludes stay non-fatal, so an
 * unqualified call reports specificity ordering exactly as the old behavior
 * did without inventing an exclusion.
 */
class Resolve_Theme_Template
{
    /** Nothing on a site should need more candidates than this for one location. */
    private const MAX_CANDIDATES = 200;

    /**
     * Theme-builder locations are coarser than Elementor's template types: a
     * site's "single" location is served by templates stored as `single`,
     * `single-post` or `single-page`, and the archive location also covers
     * search results and the 404 page. Querying the location string alone
     * misses those, so each location expands to the type set that serves it.
     */
    private const LOCATION_TYPES = [
        'single'   => ['single', 'single-post', 'single-page'],
        'archive'  => ['archive', 'search-results', 'error-404'],
    ];

    public function handle(array $args)
    {
        $location = sanitize_key((string) ($args['location'] ?? ''));
        if ('' === $location) {
            return new \WP_Error('missing_location', 'A location is required (header, footer, single, archive, ...).');
        }
        if (! Elementor_Template_Data::is_theme_type($location)) {
            return new \WP_Error(
                'invalid_location',
                "'{$location}' is not a theme-builder location (" . implode(', ', Elementor_Template_Data::THEME_TYPES) . ').'
            );
        }

        $context = $this->context($args);
        $types   = self::LOCATION_TYPES[$location] ?? [$location];

        // Bounded like List_Theme_Templates: a resolver read must not turn into
        // an unbounded query on a site with a large library. One extra row is
        // fetched so the truncation can be reported rather than hidden.
        $posts = get_posts([
            'post_type'      => Elementor_Template_Data::POST_TYPE,
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => self::MAX_CANDIDATES + 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- template type is only stored in post meta; the query is bounded above.
                [
                    'key'     => '_elementor_template_type',
                    'value'   => $types,
                    'compare' => 'IN',
                ],
            ],
        ]);

        $truncated = count($posts) > self::MAX_CANDIDATES;
        if ($truncated) {
            $posts = array_slice($posts, 0, self::MAX_CANDIDATES);
        }

        $candidates = [];
        foreach ($posts as $post) {
            $template_id = (int) $post->ID;
            $conditions  = Elementor_Template_Data::conditions($template_id);
            $scored      = $this->score($conditions, $context);

            $candidates[] = [
                'template_id'   => $template_id,
                'title'         => get_the_title($post),
                'template_type' => (string) get_post_meta($template_id, '_elementor_template_type', true),
                'status'        => (string) $post->post_status,
                'conditions'    => $conditions,
                'score'         => $scored['score'],
                'excluded_by'   => $scored['excluded_by'],
            ];
        }

        // Highest score wins; ties break on the lower id, matching the order
        // Elementor itself resolves same-specificity conditions in.
        usort($candidates, static function (array $a, array $b) {
            return $b['score'] <=> $a['score'] ?: $a['template_id'] <=> $b['template_id'];
        });

        $winner = null;
        foreach ($candidates as $candidate) {
            if ($candidate['score'] >= 0 && 'publish' === $candidate['status']) {
                $winner = $candidate['template_id'];
                break;
            }
        }

        return [
            'location'   => $location,
            'context'    => $context,
            'winner'     => $winner,
            'candidates' => $candidates,
            'total'      => count($candidates),
            'truncated'  => $truncated,
        ];
    }

    /**
     * The requested context, with anything the caller left out omitted (an
     * absent key means "unconstrained", not "must be empty").
     *
     * @return array<string,mixed>
     */
    private function context(array $args): array
    {
        $context = [];

        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id > 0) {
            $context['post_id'] = $post_id;
        }

        $post_type = sanitize_key((string) ($args['post_type'] ?? ''));
        if ('' === $post_type && isset($context['post_id'])) {
            $resolved = get_post_type($context['post_id']);
            if (is_string($resolved) && '' !== $resolved) {
                $post_type = $resolved;
            }
        }
        if ('' !== $post_type) {
            $context['post_type'] = $post_type;
        }

        return $context;
    }

    /**
     * Score a template's conditions against the requested context.
     *
     * @return array{score:int,excluded_by:?string} score below zero means the
     *         template never displays for this location and context.
     */
    private function score(array $conditions, array $context): array
    {
        if ([] === $conditions) {
            return ['score' => -1, 'excluded_by' => null];
        }

        $best = -1;
        foreach ($conditions as $condition) {
            $parts = $this->parts($condition);
            if ([] === $parts) {
                continue;
            }
            if ('include' !== array_shift($parts)) {
                continue;
            }
            if (! $this->contradicted($parts, $context)) {
                // Specificity is the number of parts after the verb, so
                // include/general scores 1, include/singular/post 2 and
                // include/singular/post/12 3. A bare `include` scores 0: still
                // eligible, but beaten by anything that names a target.
                $best = max($best, count($parts));
            }
        }

        if ($best < 0) {
            return ['score' => -1, 'excluded_by' => null];
        }

        foreach ($conditions as $condition) {
            $parts = $this->parts($condition);
            if ([] === $parts) {
                continue;
            }
            if ('exclude' !== array_shift($parts)) {
                continue;
            }
            // An exclude only bites when the context confirms it. With no
            // context supplied, exclude/singular/page is undecidable, so it is
            // reported on the candidate instead of silently disqualifying it.
            if ($this->confirmed($parts, $context)) {
                return ['score' => -1, 'excluded_by' => 'exclude/' . implode('/', $parts)];
            }
        }

        return ['score' => $best, 'excluded_by' => null];
    }

    /** A condition's parts, accepting slash strings and part arrays alike. */
    private function parts($condition): array
    {
        if (is_array($condition)) {
            $condition = implode('/', array_map(static fn ($p) => (string) $p, $condition));
        }
        if (! is_string($condition)) {
            return [];
        }
        return array_values(array_filter(explode('/', $condition), static fn ($p) => '' !== $p));
    }

    /**
     * Whether the supplied context rules this sub-condition out. Unknown
     * (unconstrained) context never contradicts.
     *
     * @param array<int,string> $sub condition parts after include/exclude.
     */
    private function contradicted(array $sub, array $context): bool
    {
        // 'general' and an empty tail match everything.
        if ([] === $sub || 'general' === $sub[0]) {
            return false;
        }

        if (isset($sub[1], $context['post_type']) && $sub[1] !== $context['post_type']) {
            return true;
        }
        if (isset($sub[2], $context['post_id']) && (int) $sub[2] !== (int) $context['post_id']) {
            return true;
        }

        return false;
    }

    /**
     * Whether the supplied context positively confirms this sub-condition, so
     * an exclude carrying it definitely applies here. Every constraining part
     * has to be pinned by the context.
     *
     * @param array<int,string> $sub condition parts after include/exclude.
     */
    private function confirmed(array $sub, array $context): bool
    {
        if ([] === $sub || 'general' === $sub[0]) {
            return true;
        }

        if (isset($sub[1])) {
            if (! isset($context['post_type']) || $sub[1] !== $context['post_type']) {
                return false;
            }
        }
        if (isset($sub[2])) {
            if (! isset($context['post_id']) || (int) $sub[2] !== (int) $context['post_id']) {
                return false;
            }
        }

        return true;
    }
}
