<?php

/**
 * The document a theme-builder render adapter hands to `template_include`.
 *
 * It is a whole-page template rather than a fragment because that is the only
 * short-circuit WordPress offers: get_header() and get_footer() call
 * locate_template() with no filter in between, so a classic theme's own
 * header.php cannot be swapped from a hook. The 404 part is therefore the
 * scoped v1 of the render slice (issue #70); replacing header and footer on
 * an arbitrary page needs the full document composition tracked as the next
 * slice.
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
