<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolver-style read for the theme builder: given a location (header,
 * footer, single, archive, ...) and an optional context, report every
 * candidate template with its conditions and which one wins. Read-only.
 *
 * Resolution order mirrors Elementor Pro's conditions manager: a more
 * specific condition (more slash parts, exact-id match) beats a general one,
 * and an exclude condition knocks a candidate out. When Elementor Pro's own
 * conditions manager is available it should be treated as the source of
 * truth; the meta-based scoring here is the fallback.
 */
class Resolve_Theme_Template
{
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

        // TODO(#61): when \ElementorPro\Modules\ThemeBuilder\Module is loaded,
        // delegate to its conditions manager instead of scoring meta directly.

        $candidates = [];
        $query      = new \WP_Query([
            'post_type'      => Elementor_Template_Data::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'   => '_elementor_template_type',
                    'value' => $location,
                ],
            ],
        ]);

        foreach ($query->posts as $template_id) {
            $template_id = (int) $template_id;
            $conditions  = Elementor_Template_Data::conditions($template_id);
            $score       = $this->score($conditions, $args);

            $candidates[] = [
                'template_id' => $template_id,
                'title'       => get_the_title($template_id),
                'conditions'  => $conditions,
                'score'       => $score,
            ];
        }

        usort($candidates, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $winner = null;
        foreach ($candidates as $candidate) {
            if ($candidate['score'] >= 0) {
                $winner = $candidate['template_id'];
                break;
            }
        }

        return [
            'location'   => $location,
            'winner'     => $winner,
            'candidates' => $candidates,
        ];
    }

    /**
     * Score a template's conditions against the requested context. Higher
     * wins; below zero means excluded or never matching.
     *
     * TODO(#61): honor the context args (post_type, post_id, term) when
     * matching sub-conditions; currently only include/exclude and condition
     * specificity (part count) are weighed.
     */
    private function score(array $conditions, array $context): int
    {
        unset($context); // Context matching is the next slice; see TODO above.

        if ([] === $conditions) {
            return -1;
        }

        $best = -1;
        foreach ($conditions as $condition) {
            if (! is_string($condition)) {
                continue;
            }
            $parts = array_values(array_filter(explode('/', $condition)));
            if ([] === $parts) {
                continue;
            }
            $specificity = count($parts);
            if ('exclude' === $parts[0]) {
                return -1;
            }
            if ('include' === $parts[0]) {
                $best = max($best, $specificity);
            }
        }

        return $best;
    }
}
