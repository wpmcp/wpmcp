<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Block-theme render adapter (issue #70). Planned mechanism: filter the
 * theme's template-part resolution (get_block_templates /
 * pre_get_block_file_template) so a winning wpmcp_template supplies the
 * header/footer part content natively; the 404 part hooks template_include.
 */
class Block_Adapter implements Adapter
{
    public function supports(): bool
    {
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }

    public function register(string $part_type): void
    {
        // TODO(#70): filter block template-part resolution so the winning
        // template (Template_Resolver::resolve) replaces the theme part.
        // Failing tests first per the issue's TDD notes; no-op until then.
    }
}
