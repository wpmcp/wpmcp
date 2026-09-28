<?php

namespace WPMCP\Tools\ThemeBuilder\Dynamic;

use WPMCP\Tools\ThemeBuilder\Render\Adapters;
use WPMCP\Tools\ThemeBuilder\Render\Template_Renderer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end wiring for dynamic templates (issue #290). On a singular,
 * archive or search request, when a template of that context wins (the site
 * parts resolver: conditions, specificity, priority), the active render
 * adapter composes the document around it: a classic theme gets
 * document.php between its header and footer, a block theme gets its own
 * header and footer template parts around the template body. Bindings are
 * resolved as the body renders, by Binding_Resolver::filter_rendered().
 *
 * Runs after the adapters' own 404 swap (priority 20) and never on a 404,
 * so the 404 site part keeps that page.
 */
class Dynamic_Templates
{
    public const PRIORITY = 21;

    public static function boot(): void
    {
        if (is_admin()) {
            return;
        }
        add_filter('template_include', [self::class, 'template_include'], self::PRIORITY);
    }

    /** The template context of the live request, or null when none applies. */
    public static function request_context(): ?string
    {
        if (is_404()) {
            return null;
        }
        if (is_search()) {
            return 'search';
        }
        if (is_singular()) {
            return 'single';
        }
        if (is_archive()) {
            return 'archive';
        }
        return null;
    }

    /**
     * @param string $template the template WordPress resolved
     *
     * @return string
     */
    public static function template_include($template)
    {
        $context = self::request_context();
        if (null === $context || null === Template_Renderer::winner($context)) {
            return $template;
        }
        $adapter = Adapters::active();
        if (null === $adapter) {
            return $template;
        }
        return $adapter->compose_document($context, (string) $template);
    }
}
