<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classic-theme render adapter (issue #70). The 404 part is served by
 * swapping the document template on `template_include`. Header and footer
 * cannot be swapped from a hook in a classic theme (get_header() calls
 * locate_template() with nothing filterable in between), so they wait for the
 * full document composition slice rather than shipping an output-buffer
 * guess.
 */
class Classic_Adapter implements Adapter
{
    use Renders_Document;

    public function supports(): bool
    {
        return ! function_exists('wp_is_block_theme') || ! wp_is_block_theme();
    }
}
