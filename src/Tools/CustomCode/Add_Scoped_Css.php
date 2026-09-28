<?php

namespace WPMCP\Tools\CustomCode;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The add-scoped-css tool handler (issue #63). Stores a sanitized CSS block
 * scoped to ONE post/page; Custom_Code_Renderer prints it in wp_head only
 * when that post is being viewed. Site-wide CSS is deliberately NOT this
 * tool's job: the existing wpmcp/add-custom-css ability (Elementor group)
 * already writes site-wide CSS through core's Additional CSS storage, and
 * duplicating that here would give agents two competing site-wide paths.
 *
 * When both 'selector' and 'css' are given the css is treated as bare
 * declarations and wrapped as "selector { declarations }"; when only 'css'
 * is given it is treated as a full stylesheet fragment.
 *
 * Like its sibling add-custom-css this APPENDS to the page's existing block
 * by default and replaces it only on replace=true, so a second call never
 * silently discards the agent's earlier CSS. css="" with replace=true clears
 * the block, snapshot-first like any other write.
 *
 * Two capability gates, not one. The ability's own gate is manage_options,
 * but WordPress treats authoring a stylesheet as an unfiltered_html-class
 * action (core maps 'edit_css' onto it, which multisite strips from everyone
 * but super admins), and CSS is not inert: it can hide, move or overlay page
 * chrome. Holding this tool to the same bar core holds Additional CSS to is
 * the conservative choice, so handle() requires 'edit_css' as well.
 *
 * Sanitization happens BEFORE the write (Css_Sanitizer::sanitize throws on
 * anything script-capable) and again at render time, which is a second
 * chance at a value that reached the option by some other route (a direct DB
 * edit, another plugin) rather than an independent barrier: it is the same
 * decision run again. The write
 * routes through Safe_Mutation with object_type 'option' and the post's OWN
 * option as object_id, so rolling back one page's write leaves every other
 * page's CSS alone.
 *
 * Element scope (issue #63's first acceptance criterion) is an 'element_id':
 * the declarations are prefixed with the .elementor-element-<id> class the
 * builder already renders on that element, so one element on one page can be
 * targeted. The block is still a plain stylesheet in this plugin's OWN
 * store. Writing into the builder's own page/element settings postmeta was
 * the other option and is deliberately not taken here: that postmeta is a
 * single serialized document covering the whole page, so a snapshot of it is
 * a whole-page before-image, and rolling back one element's CSS would revert
 * every other edit any tool made to that page in between. The trade is that
 * the CSS does not show up in the Elementor UI's own custom-css box, which
 * a follow-up slice can add on top of the builder snapshot path.
 *
 * The id goes into a selector, so it is ALLOWLISTED to the alphabet
 * Elementor issues rather than escaped after the fact.
 */
class Add_Scoped_Css
{
    public function handle(array $args): array
    {
        $css     = isset($args['css']) ? (string) $args['css'] : '';
        $replace = ! empty($args['replace']);
        $clear   = '' === trim($css);

        // An empty css is a request to CLEAR the page's block, and only with
        // replace=true: an empty append is almost certainly a mistake, and
        // silently deleting the block on one would be worse than an error.
        if ($clear && ! $replace) {
            throw new \InvalidArgumentException('A css value is required. To clear the page\'s stored CSS, pass css="" with replace=true.');
        }

        $post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post_id is required (this tool stores page-scoped CSS; use add-custom-css for site-wide CSS).');
        }
        $this->assert_renderable_post($post_id);

        if (! current_user_can('edit_css')) {
            throw new \RuntimeException(
                'Storing custom CSS requires the edit_css capability (unfiltered_html), the same bar WordPress core applies to Additional CSS.'
            );
        }

        $element_id = '';
        $next       = '';

        if (! $clear) {
            $selector = isset($args['selector']) ? (string) $args['selector'] : '';

            // Optional like every other argument: absent, null and '' all
            // mean "page scope".
            if (isset($args['element_id']) && '' !== trim((string) $args['element_id'])) {
                $element_id = trim((string) $args['element_id']);
                if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $element_id)) {
                    throw new \InvalidArgumentException(
                        'An element_id must be an Elementor element id: 1 to 64 characters of letters, digits, hyphen or underscore. It is interpolated into a selector, so the alphabet is allowlisted rather than escaped.'
                    );
                }
                // Every selector in a list is prefixed, so "h2, body" cannot
                // carry the rule out to body.
                $scope    = '.elementor-element-' . $element_id;
                $selector = '' !== trim($selector)
                    ? Css_Sanitizer::scope_selector_list($scope, $selector)
                    : $scope;
            }

            if ('' !== $selector) {
                $selector = Css_Sanitizer::sanitize_selector($selector);
                // Both brace characters, not just the opener. The wrap is
                // "selector { declarations }", so a declarations string
                // carrying its own '}' closes the block early and whatever
                // follows applies outside the scope this ability advertises.
                if (false !== strpbrk($css, '{}')) {
                    throw new \InvalidArgumentException('When a selector is given, css must be bare declarations without braces.');
                }
                $css = $selector . ' { ' . $css . ' }';
            }

            // The caller's own CSS first, so an error here is about it.
            $css  = Css_Sanitizer::sanitize($css);
            $next = Custom_Code_Store::compose_css($css, $this->existing_block($post_id, $replace), $replace);

            // The renderer decides on the JOINED block, so decide on exactly
            // the block that will be stored. A piece that passes alone can
            // still poison the join (a trailing backslash plus the join
            // newline is a line continuation).
            try {
                $next = Css_Sanitizer::sanitize($next);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    'This CSS passes on its own, but the page\'s stored block with it appended does not: ' . esc_html($e->getMessage()) . ' Adjust the CSS, or pass replace=true to overwrite the stored block.'
                );
            }
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Custom_Code_Store::post_option($post_id),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'add-scoped-css',
                'args'        => $args,
            ],
            // Writes exactly the block validated above; nothing is re-read or
            // recomposed after the decision.
            static function () use ($post_id, $next): void {
                Custom_Code_Store::write_css($post_id, $next);
            }
        );

        return [
            'scope'        => '' !== $element_id ? 'element' : 'post',
            'post_id'      => $post_id,
            'element_id'   => $element_id,
            'css'          => $next,
            'replaced'     => $replace,
            'cleared'      => $clear,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /**
     * The stored block an append builds on. Refuses, with a message about the
     * STORED block rather than the caller's CSS, when that block was not
     * written by this plugin or no longer passes the current rules; either
     * way the only way forward is replace=true.
     */
    private function existing_block(int $post_id, bool $replace): string
    {
        if ($replace || ! Custom_Code_Store::has_css($post_id)) {
            return '';
        }

        $verified = Custom_Code_Store::verified_css($post_id);
        if (null === $verified) {
            throw new \InvalidArgumentException(
                'The CSS already stored for this page was not written by this tool (its signature does not verify), so it is not rendered and cannot be appended to. Pass replace=true to overwrite it.'
            );
        }

        // Re-checked whatever rule version signed it: an older block may fail
        // tightened rules, and internal code (Custom_Code_Store::write_css)
        // signs without sanitizing.
        try {
            Css_Sanitizer::sanitize($verified['css']);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                'The CSS already stored for this page does not pass the current sanitizer (' . esc_html($e->getMessage()) . '), so nothing can be appended to it. Pass replace=true to overwrite it.'
            );
        }

        return $verified['css'];
    }

    /**
     * Refuse ids get_post() resolves but that never render as a page, so no
     * CSS is stored that could never print: revisions and autosaves (they
     * share post_type 'revision'), attachments, and post types that are not
     * publicly viewable. The posts page and the WooCommerce shop page ARE
     * accepted: Custom_Code_Renderer::scoped_post_id() maps is_home() and
     * the product archive back to them.
     */
    private function assert_renderable_post(int $post_id): void
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', (int) $post_id));
        }

        if ('revision' === $post->post_type || 'attachment' === $post->post_type || ! is_post_type_viewable($post->post_type)) {
            throw new \InvalidArgumentException(sprintf(
                'Post %d is a %s, which is never rendered as its own page, so scoped CSS stored for it would never print. Pass the id of the page or post that is actually viewed.',
                (int) $post_id,
                esc_html($post->post_type)
            ));
        }
    }
}
