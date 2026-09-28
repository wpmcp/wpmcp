<?php

/**
 * The document Classic_Adapter hands to `template_include` when a 404 site
 * part wins (issue #70). The theme's header and footer frame it, or the
 * winning header and footer site parts when there are any, because
 * get_header() / get_footer() route back through Classic_Adapter's hooks.
 *
 * @package WPMCP
 */

if (! defined('ABSPATH')) {
    exit;
}

get_header();
// Already filtered with wp_kses_post() on the way into the store and passed
// through do_blocks() here, exactly like a rendered post body.
echo \WPMCP\Tools\ThemeBuilder\Render\Template_Renderer::render_current(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized on store in Template_Store::sanitize_content(); escaping block markup here would print it.
get_footer();
