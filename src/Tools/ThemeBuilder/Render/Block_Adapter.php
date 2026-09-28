<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Block-theme render adapter (issue #70), integrated through the block
 * template system rather than around it.
 *
 * Header and footer: a block theme renders them as core/template-part blocks
 * in the header and footer areas. `pre_render_block` short-circuits exactly
 * those blocks when a site part wins, keeping the part's wrapper element and
 * the wp-block-template-part class so theme styles still apply. Every other
 * block, and every part when nothing wins, renders untouched.
 *
 * 404: by `template_include` time locate_block_template() has already put the
 * theme's 404 block template into $_wp_current_template_content and pointed
 * WordPress at template-canvas.php. When a 404 site part wins, that content
 * is replaced with the theme's own header and footer template parts around
 * the site part, so the page keeps the block canvas (wp_head, global styles,
 * the header and footer site parts above) instead of a classic document.
 */
class Block_Adapter implements Adapter
{
    /** Template-part areas this subsystem owns => the element that wraps them. */
    private const AREAS = ['header' => 'header', 'footer' => 'footer'];

    /** Wrapper elements a template part may declare that are safe to print. */
    private const ALLOWED_TAGS = ['header', 'footer', 'div', 'section', 'aside', 'main'];

    /** The placeholder block compose_document() renders a whole-page body through. */
    private const BODY_BLOCK = 'wpmcp/site-part';

    /** @var array<string,bool> whole-page part types this request composed. */
    private static array $composed = [];

    /** Part types currently being rendered, so a part that embeds itself cannot recurse. */
    private static array $rendering = [];

    /** @var array<string,string> "theme//slug" => area, per request. */
    private array $area_cache = [];

    public function supports(): bool
    {
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }

    public function register(string $part_type): void
    {
        if ('404' === $part_type) {
            add_filter('template_include', [$this, 'compose_404'], 20);
            return;
        }
        if (! has_filter('pre_render_block', [$this, 'replace_template_part'])) {
            add_filter('pre_render_block', [$this, 'replace_template_part'], 10, 2);
        }
    }

    /**
     * @param string|null         $pre   an earlier short-circuit, respected as-is
     * @param array<string,mixed> $block the parsed block
     *
     * @return string|null
     */
    public function replace_template_part($pre, $block)
    {
        if (null !== $pre || ! is_array($block) || 'core/template-part' !== ($block['blockName'] ?? '')) {
            return $pre;
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $area  = $this->area_of($attrs);
        if (null === $area || isset(self::$rendering[$area])) {
            return $pre;
        }

        $template = Template_Renderer::winner($area);
        if (null === $template) {
            return $pre;
        }

        $tag = strtolower((string) ($attrs['tagName'] ?? ''));
        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $tag = self::AREAS[$area];
        }

        self::$rendering[$area] = true;
        try {
            $inner = Template_Renderer::render_template($template);
        } finally {
            unset(self::$rendering[$area]);
        }

        $class = 'wp-block-template-part wpmcp-site-part wpmcp-site-part-' . $area;
        // $inner was filtered with wp_kses_post() on the way into the store.
        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $tag, esc_attr($class), $inner);
    }

    /**
     * @param string $template the template WordPress resolved
     *
     * @return string
     */
    public function compose_404($template)
    {
        if (! is_404()) {
            return $template;
        }
        $part = Template_Renderer::winner('404');
        if (null === $part) {
            return $template;
        }

        global $_wp_current_template_content;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This is the block template system's own hand-off from locate_block_template() to template-canvas.php; replacing it is the documented way to change what the canvas renders.
        $_wp_current_template_content = '<!-- wp:template-part {"slug":"header","tagName":"header","area":"header"} /-->' . "\n"
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">' . "\n"
            . (string) $part['content'] . "\n"
            . '</main><!-- /wp:group -->' . "\n"
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer","area":"footer"} /-->';

        return ABSPATH . WPINC . '/template-canvas.php';
    }

    /**
     * Replace the block template for a whole-page part type with the theme's
     * own header and footer template parts around a body block that renders
     * the winning template. The body is a placeholder block answered from
     * `pre_render_block` (render_body()), so the template is rendered once,
     * through Template_Renderer::render_template(), and its output is never
     * parsed as block markup a second time.
     */
    public function compose_document(string $part_type, string $template): string
    {
        self::$composed[$part_type] = true;
        if (! has_filter('pre_render_block', [$this, 'render_body'])) {
            add_filter('pre_render_block', [$this, 'render_body'], 10, 2);
        }

        global $_wp_current_template_content;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The block template system's hand-off from locate_block_template() to template-canvas.php, as in compose_404().
        $_wp_current_template_content = '<!-- wp:template-part {"slug":"header","tagName":"header","area":"header"} /-->' . "\n"
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">' . "\n"
            . '<!-- wp:' . self::BODY_BLOCK . ' ' . wp_json_encode(['partType' => $part_type]) . ' /-->' . "\n"
            . '</main><!-- /wp:group -->' . "\n"
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer","area":"footer"} /-->';

        return ABSPATH . WPINC . '/template-canvas.php';
    }

    /**
     * The body placeholder compose_document() put into the canvas. Answered
     * only for a part type this request composed, so the same comment typed
     * into a post cannot pull a template into the page.
     *
     * @param string|null         $pre   an earlier short-circuit, respected as-is
     * @param array<string,mixed> $block the parsed block
     *
     * @return string|null
     */
    public function render_body($pre, $block)
    {
        if (null !== $pre || ! is_array($block) || self::BODY_BLOCK !== ($block['blockName'] ?? '')) {
            return $pre;
        }
        $part_type = is_array($block['attrs'] ?? null) ? (string) ($block['attrs']['partType'] ?? '') : '';
        if (! isset(self::$composed[$part_type]) || isset(self::$rendering[$part_type])) {
            return '';
        }
        $template = Template_Renderer::winner($part_type);
        if (null === $template) {
            return '';
        }

        self::$rendering[$part_type] = true;
        try {
            $inner = Template_Renderer::render_template($template);
        } finally {
            unset(self::$rendering[$part_type]);
        }

        $class = 'wpmcp-site-part wpmcp-site-part-' . $part_type;
        // $inner was filtered with wp_kses_post() on the way into the store.
        return sprintf('<div class="%1$s">%2$s</div>', esc_attr($class), $inner);
    }

    /**
     * The area a template-part block renders into: its own `area` attribute,
     * else the area its theme file declares, else its slug when that is
     * literally header or footer. Null for areas this subsystem does not own.
     *
     * @param array<string,mixed> $attrs
     */
    private function area_of(array $attrs): ?string
    {
        $area = isset($attrs['area']) && is_string($attrs['area']) ? $attrs['area'] : '';
        $slug = isset($attrs['slug']) && is_string($attrs['slug']) ? $attrs['slug'] : '';

        if ('' === $area && '' !== $slug && function_exists('get_block_template')) {
            $theme = isset($attrs['theme']) && is_string($attrs['theme']) ? $attrs['theme'] : get_stylesheet();
            $key   = $theme . '//' . $slug;
            if (! isset($this->area_cache[$key])) {
                $found                  = get_block_template($key, 'wp_template_part');
                $this->area_cache[$key] = $found && is_string($found->area ?? null) ? $found->area : '';
            }
            $area = $this->area_cache[$key];
        }
        if (('' === $area || 'uncategorized' === $area) && isset(self::AREAS[$slug])) {
            $area = $slug;
        }

        return isset(self::AREAS[$area]) ? $area : null;
    }
}
