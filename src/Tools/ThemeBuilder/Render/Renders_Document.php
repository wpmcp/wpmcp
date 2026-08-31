<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The document-swap mechanism both adapters share: on `template_include`,
 * when the request is a 404 and an active 404 site part wins it, hand
 * WordPress this subsystem's own document template instead of the theme's
 * 404.php.
 *
 * Scoped to the 404 part on purpose. A document swap replaces the whole page,
 * which is correct for an error page and wrong for anything with content of
 * its own, so header and footer are left to the theme until the composition
 * slice lands. `supports()` is what differs between the two adapters; this
 * does not.
 */
trait Renders_Document
{
    public function register(string $part_type): void
    {
        if ('404' !== $part_type) {
            return;
        }
        add_filter('template_include', [$this, 'swap_document'], 20);
    }

    /**
     * @param string $template the template WordPress resolved
     *
     * @return string
     */
    public function swap_document($template)
    {
        if (! is_404() || ! Template_Renderer::has_winner('404')) {
            return $template;
        }
        Template_Renderer::set_current_part('404');
        return Template_Renderer::document_template();
    }
}
