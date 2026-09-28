<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A render adapter integrates a resolved theme-builder template into the
 * active theme (issue #70). Two implementations: Block_Adapter (block themes)
 * and Classic_Adapter (classic themes). Adapters::boot() picks the one whose
 * supports() answers yes and registers it for every part type.
 */
interface Adapter
{
    /** True when this adapter can serve the currently active theme. */
    public function supports(): bool;

    /** Hook the adapter into the front-end render pipeline for a part type. */
    public function register(string $part_type): void;

    /**
     * Hand WordPress a document whose body is the winning template of a
     * whole-page part type, framed by the theme's (or the winning site
     * parts') header and footer. Called from a `template_include` filter
     * once the caller knows a template wins; returns the template file to
     * load.
     */
    public function compose_document(string $part_type, string $template): string;
}
