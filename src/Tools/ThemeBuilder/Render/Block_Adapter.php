<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Block-theme render adapter (issue #70). The 404 part is served by swapping
 * the document template, which works in a block theme because a PHP template
 * returned from `template_include` takes precedence over the block template
 * canvas. Header and footer in a block theme are template parts, so they get
 * the native get_block_templates()/pre_get_block_file_template() integration
 * in the next slice rather than a document swap that would discard the
 * theme's own page content.
 */
class Block_Adapter implements Adapter
{
    use Renders_Document;

    public function supports(): bool
    {
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }
}
