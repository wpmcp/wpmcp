<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Analyze_Performance
{
    public function handle(array $args): array
    {
        $analyzer = new Analyzer();
        $result   = $analyzer->analyze($args);

        if (is_wp_error($result)) {
            throw new \InvalidArgumentException(esc_html($result->get_error_message()));
        }

        return $result;
    }
}
