<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classic-theme render adapter (issue #70).
 *
 * Header and footer: get_header() and get_footer() fire an action and then
 * load the theme's header.php / footer.php through locate_template() with
 * require_once, and nothing in between is filterable. So when a site part
 * wins, this adapter prints the part itself from that action (opening the
 * document for a header, closing it for a footer) and then requires the
 * theme's own file into a discarded buffer with the wp_head / wp_footer
 * callbacks removed. The require_once inside get_header() is then a no-op, so
 * the theme's file runs exactly once and prints nothing. This is the same
 * mechanism the established theme builders use for classic themes; the
 * trade-off is that wrapper markup the theme's header.php opens (and its
 * footer.php closes) is dropped with it, which a site part replaces anyway.
 *
 * 404: when a 404 site part wins, the whole document is swapped on
 * `template_include` for this subsystem's document.php. Its get_header() /
 * get_footer() calls come back through the hooks above, so a header or footer
 * site part frames the 404 part too.
 */
class Classic_Adapter implements Adapter
{
    public function supports(): bool
    {
        return ! function_exists('wp_is_block_theme') || ! wp_is_block_theme();
    }

    public function register(string $part_type): void
    {
        if ('header' === $part_type) {
            add_action('get_header', [$this, 'replace_header']);
            return;
        }
        if ('footer' === $part_type) {
            add_action('get_footer', [$this, 'replace_footer']);
            return;
        }
        if ('404' === $part_type) {
            add_filter('template_include', [$this, 'swap_document'], 20);
        }
    }

    /**
     * @param string $template the template WordPress resolved
     *
     * @return string
     */
    public function swap_document($template)
    {
        if (! is_404() || null === Template_Renderer::winner('404')) {
            return $template;
        }
        Template_Renderer::set_current_part('404');
        return Template_Renderer::document_template();
    }

    /**
     * @param string|null $name the header name get_header() was called with
     */
    public function replace_header($name): void
    {
        $template = Template_Renderer::winner('header');
        if (null === $template) {
            return;
        }

        $this->print_document_head();
        // Already filtered with wp_kses_post() on the way into the store.
        echo Template_Renderer::render_template($template); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized on store in Template_Store::sanitize_content(); escaping block markup here would print it.

        $this->discard_theme_file('header', $name, 'wp_head');
    }

    /**
     * @param string|null $name the footer name get_footer() was called with
     */
    public function replace_footer($name): void
    {
        $template = Template_Renderer::winner('footer');
        if (null === $template) {
            return;
        }

        echo Template_Renderer::render_template($template); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized on store in Template_Store::sanitize_content(); escaping block markup here would print it.
        wp_footer();
        echo "\n</body>\n</html>\n";

        $this->discard_theme_file('footer', $name, 'wp_footer');
    }

    /** The document opening a theme's header.php would otherwise print. */
    private function print_document_head(): void
    {
        echo "<!DOCTYPE html>\n<html ";
        language_attributes();
        echo ">\n<head>\n<meta charset=\"" . esc_attr(get_bloginfo('charset')) . "\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        if (! current_theme_supports('title-tag')) {
            // Older themes hard-code <title> in the header.php being replaced.
            echo '<title>' . esc_html(wp_get_document_title()) . "</title>\n";
        }
        wp_head();
        echo "</head>\n<body ";
        body_class();
        echo ">\n";
        wp_body_open();
    }

    /**
     * Run the theme's own header.php / footer.php once, into a buffer that is
     * thrown away, so get_header() / get_footer()'s require_once of the same
     * file prints nothing. The hook this document already fired is emptied
     * first so its callbacks do not run a second time into the buffer.
     *
     * @param string|null $name
     */
    private function discard_theme_file(string $slug, $name, string $document_hook): void
    {
        $templates = [];
        $name      = (string) $name;
        if ('' !== $name) {
            $templates[] = "{$slug}-{$name}.php";
        }
        $templates[] = "{$slug}.php";

        remove_all_actions($document_hook);
        ob_start();
        locate_template($templates, true, true);
        ob_end_clean();
    }
}
