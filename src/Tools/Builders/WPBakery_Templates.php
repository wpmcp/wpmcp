<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content's templates scope for WPBakery (issue #639): add,
 * update or remove one saved template in the `wpb_js_templates` option, the
 * way WPBakery's templates panel does (Vc_Templates_Panel_Editor::save() and
 * delete() in 9.1): entries `<id> => {name, template}`, a new id is
 * sanitize_title(name) . '_' . wp_rand(), an empty name is stored as
 * "(no title)", an empty layout is refused, and a first template creates the
 * option without autoload.
 *
 * - add: element {name, content}, or element {name} with post_id (and path,
 *   '' or absent for the whole page) to save that page element's stored
 *   markup as a template;
 * - update: path is the template id, element {name?, content?};
 * - remove: path is the template id.
 *
 * Every call needs expected_hash, the hash the templates read returns, and
 * snapshots the whole option first, so rollback-operation puts it back
 * (deleting it again when the write created it).
 */
final class WPBakery_Templates
{
    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function write(array $args)
    {
        $option    = WPBakery_Design::TEMPLATES_OPTION;
        $operation = (string) ($args['operation'] ?? '');
        if (! in_array($operation, ['add', 'update', 'remove'], true)) {
            return new \WP_Error('invalid_template_request', "operation must be add, update or remove for scope templates; got '{$operation}'.");
        }

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error('missing_expected_hash', '"expected_hash" is required: read get-builder-content scope templates (builder wpbakery) first and pass back its hash.');
        }
        if (! hash_equals(Builder_Design_Data::option_hash($option), $expected)) {
            return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the saved templates changed since they were read. Nothing was written; re-read and retry.');
        }

        $stored = get_option($option, false);
        if (false !== $stored && ! is_array($stored)) {
            return new \WP_Error('invalid_template_storage', "The stored '{$option}' is not the array WPBakery writes, so it was left untouched.");
        }
        $templates = is_array($stored) ? $stored : [];

        try {
            [$templates, $id] = self::apply($templates, $operation, $args);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_template_request', $e->getMessage());
        }

        try {
            $out = Safe_Mutation::run(
                [
                    'object_type' => 'option',
                    'object_id'   => $option,
                    'session_id'  => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'   => 'update-builder-content',
                    'args'        => $args,
                ],
                static function () use ($option, $templates, $stored) {
                    if (false === $stored) {
                        add_option($option, $templates, '', false);
                    } else {
                        update_option($option, $templates);
                    }
                    return get_option($option);
                },
                static fn ($saved) => $saved === $templates
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'The saved templates did not store as intended; the previous value was restored.');
        }

        return [
            'operation_id' => $out['operation_id'],
            'builder'      => 'wpbakery',
            'path'         => $id,
            'hash'         => Builder_Design_Data::option_hash($option),
        ];
    }

    /**
     * @param array<int|string,mixed> $templates
     * @param array<string,mixed>     $args
     * @return array{0:array<int|string,mixed>,1:string}
     */
    private static function apply(array $templates, string $operation, array $args): array
    {
        $element = $args['element'] ?? [];
        if (! is_array($element)) {
            throw new \InvalidArgumentException(esc_html('element must be an object: {name, content}.'));
        }

        if ('add' === $operation) {
            $name    = self::name($element['name'] ?? '');
            $content = array_key_exists('content', $element) ? $element['content'] : self::from_post($args);
            self::assert_layout($content);

            $id = sanitize_title($name) . '_' . wp_rand();
            while (isset($templates[ $id ])) {
                $id = sanitize_title($name) . '_' . wp_rand();
            }
            $templates[ $id ] = ['name' => $name, 'template' => $content];

            return [$templates, $id];
        }

        $id = (string) ($args['path'] ?? '');
        if ('' === $id || ! isset($templates[ $id ]) || ! is_array($templates[ $id ])) {
            throw new \InvalidArgumentException(esc_html("No saved WPBakery template '{$id}'; read scope templates for the ids."));
        }

        if ('remove' === $operation) {
            unset($templates[ $id ]);

            return [$templates, $id];
        }

        if (! array_key_exists('name', $element) && ! array_key_exists('content', $element)) {
            throw new \InvalidArgumentException(esc_html('update needs element.name and/or element.content.'));
        }
        if (array_key_exists('name', $element)) {
            $templates[ $id ]['name'] = self::name($element['name']);
        }
        if (array_key_exists('content', $element)) {
            self::assert_layout($element['content']);
            $templates[ $id ]['template'] = $element['content'];
        }

        return [$templates, $id];
    }

    /** @param mixed $name */
    private static function name($name): string
    {
        if (! is_string($name)) {
            throw new \InvalidArgumentException(esc_html('element.name must be a string.'));
        }
        $name = trim($name);

        return '' === $name ? '(no title)' : $name;
    }

    /** @param mixed $content */
    private static function assert_layout($content): void
    {
        if (! is_string($content) || '' === trim($content)) {
            throw new \InvalidArgumentException(esc_html('A template needs a non-empty shortcode layout (element.content), or a post_id to copy one from.'));
        }
    }

    /** The stored markup of a page, or of one element of it when path is set. */
    private static function from_post(array $args): string
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException(esc_html('add needs element.content, or a post_id (and path) to copy the layout from.'));
        }
        if (! get_post($post_id) || ! current_user_can('read_post', $post_id)) {
            throw new \InvalidArgumentException(esc_html("No readable post with id '{$post_id}'."));
        }

        return WPBakery_Page::slice(WPBakery_Content::get_content($post_id), (string) ($args['path'] ?? ''));
    }
}
