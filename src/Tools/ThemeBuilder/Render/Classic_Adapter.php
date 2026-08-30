<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classic-theme render adapter (issue #70). Planned mechanism: buffer and
 * replace get_header()/get_footer() output via the get_header/get_footer
 * actions plus an early template_include for the 404 part, rendering the
 * winning template's block markup with do_blocks().
 */
class Classic_Adapter implements Adapter
{
    public function supports(): bool
    {
        return ! function_exists('wp_is_block_theme') || ! wp_is_block_theme();
    }

    public function register(string $part_type): void
    {
        // TODO(#70): hook get_header/get_footer/template_include and render
        // the winner with do_blocks(). Failing tests first; no-op until then.
    }
}
