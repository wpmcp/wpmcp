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
 * Sanitization happens BEFORE the write (Css_Sanitizer::sanitize throws on
 * anything script-capable, so no stored XSS via CSS) and again at render
 * time as defense in depth. The write routes through Safe_Mutation with
 * object_type 'option' and object_id Custom_Code_Store::OPTION, so every
 * change is snapshot-first and reversible via rollback-operation.
 *
 * TODO(#63): element-scoped writes into builder settings (Elementor page
 * settings custom_css) are a follow-up slice through the builder snapshot
 * path; this slice covers page scope in the plugin's own store.
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

        $selector = isset($args['selector']) ? (string) $args['selector'] : '';
        if ('' !== $selector) {
            $selector = Css_Sanitizer::sanitize_selector($selector);
            if (false !== strpos($css, '{')) {
                throw new \InvalidArgumentException('When a selector is given, css must be bare declarations without braces.');
            }
            $css = $selector . ' { ' . $css . ' }';
        }

        $css = Css_Sanitizer::sanitize($css);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Custom_Code_Store::OPTION,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'add-scoped-css',
                'args'        => $args,
            ],
            function () use ($css, $post_id): void {
                Custom_Code_Store::set_css($css, $post_id);
            }
        );

        return [
            'scope'        => 'post',
            'post_id'      => $post_id,
            'css'          => $css,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
