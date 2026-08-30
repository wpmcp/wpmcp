<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A render adapter integrates a resolved theme-builder template into the
 * active theme (issue #70). Two implementations are planned: Block_Adapter
 * (block themes, Gutenberg-template-part-native where possible) and
 * Classic_Adapter (classic themes, hook/output-buffer based).
 */
interface Adapter
{
    /** True when this adapter can serve the currently active theme. */
    public function supports(): bool;

    /** Hook the adapter into the front-end render pipeline for a part type. */
    public function register(string $part_type): void;
}
