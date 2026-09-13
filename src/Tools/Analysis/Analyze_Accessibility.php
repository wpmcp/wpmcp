<?php

namespace WPMCP\Tools\Analysis;

use WPMCP\Tools\Content\Content_Extractor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: scan a post's stored HTML for common WCAG issues (images missing
 * alt text, heading order jumps, empty or non-descriptive link text, and form
 * controls without labels) and return scored findings with the offending
 * element locations.
 *
 * Content structure comes from Content_Extractor and the scoring from
 * A11y_Analyzer. Reads have nothing to roll back, so this never touches
 * Safe_Mutation.
 */
class Analyze_Accessibility
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        if (! get_post($post_id)) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $extract = Content_Extractor::extract($post_id);
        $report  = A11y_Analyzer::analyze($extract);

        return [
            'post_id' => $post_id,
            'report'  => $report,
        ];
    }
}
