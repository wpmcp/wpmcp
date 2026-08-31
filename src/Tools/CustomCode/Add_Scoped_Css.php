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
 * silently discards the agent's earlier CSS.
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
        $css = isset($args['css']) ? (string) $args['css'] : '';
        if ('' === trim($css)) {
            throw new \InvalidArgumentException('A css value is required.');
        }

        $post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post_id is required (this tool stores page-scoped CSS; use add-custom-css for site-wide CSS).');
        }
        if (! get_post($post_id)) {
            throw new \InvalidArgumentException("Post {$post_id} does not exist.");
        }

        if (! current_user_can('edit_css')) {
            throw new \RuntimeException(
                'Storing custom CSS requires the edit_css capability (unfiltered_html), the same bar WordPress core applies to Additional CSS.'
            );
        }

        $selector   = isset($args['selector']) ? (string) $args['selector'] : '';
        $element_id = '';

        if (array_key_exists('element_id', $args)) {
            $element_id = trim((string) $args['element_id']);
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $element_id)) {
                throw new \InvalidArgumentException(
                    'An element_id must be an Elementor element id: 1 to 64 characters of letters, digits, hyphen or underscore. It is interpolated into a selector, so the alphabet is allowlisted rather than escaped.'
                );
            }
            // Prefixed, not replaced: an element_id plus a selector reads as
            // "this selector, inside that element", which is how an agent
            // asks for "the h2 in this section" without knowing the h2's own
            // Elementor id.
            $selector = '.elementor-element-' . $element_id
                . ('' !== trim($selector) ? ' ' . $selector : '');
        }

        if ('' !== $selector) {
            $selector = Css_Sanitizer::sanitize_selector($selector);
            // Both brace characters, not just the opener. The wrap is
            // "selector { declarations }", so a declarations string carrying
            // its own '}' closes the block early and whatever follows applies
            // outside the scope this ability advertises. A stray '}' did fail
            // the sanitizer's brace COUNT a moment later, but as "unbalanced
            // braces" - an error about the CSS instead of about the contract
            // the caller actually broke.
            if (false !== strpbrk($css, '{}')) {
                throw new \InvalidArgumentException('When a selector is given, css must be bare declarations without braces.');
            }
            $css = $selector . ' { ' . $css . ' }';
        }

        $css     = Css_Sanitizer::sanitize($css);
        $replace = ! empty($args['replace']);
        $stored  = '';

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Custom_Code_Store::post_option($post_id),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'add-scoped-css',
                'args'        => $args,
            ],
            function () use ($css, $post_id, $replace, &$stored): void {
                $stored = Custom_Code_Store::set_css($css, $post_id, $replace);
            }
        );

        return [
            'scope'        => '' !== $element_id ? 'element' : 'post',
            'post_id'      => $post_id,
            'element_id'   => $element_id,
            'css'          => $stored,
            'replaced'     => $replace,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
