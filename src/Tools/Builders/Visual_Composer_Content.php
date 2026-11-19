<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a Visual Composer Website Builder page (issue #642).
 * Recorded from Visual Composer 45.16 (the wordpress.org plugin):
 *
 * - The editor's document is the `vcv-pageContent` postmeta row:
 *   rawurlencode() of JSON holding `elements`, a flat map of element id to
 *   {id, tag, parent (an id, or false at the top), order, ...attributes}.
 *   Visual Composer's own server-side writer (its translation integration)
 *   reads it with rawurldecode()+json_decode() and stores it back with
 *   rawurlencode(wp_json_encode()); this class does the same.
 * - The live page is post_content: HTML the browser editor rendered from
 *   that document (each element's main node carries id="el-<id>"), with
 *   its CSS compiled in the browser too. The server cannot render an
 *   element, so a write here only changes what it can keep in step in both
 *   stores: an attribute whose value is the whole inner HTML of the
 *   element's el-<id> node (a Text Block's content, say), checked against
 *   the stored markup before anything is written, and the editor-only
 *   layer name. Every other change is refused with editor_render_required
 *   rather than leaving the live page out of step with the editor.
 *
 * Both stores are ordinary post row and postmeta, which the post snapshot
 * captures and restores.
 */
final class Visual_Composer_Content
{
    public const META_KEY = 'vcv-pageContent';

    public const EDITOR_META_KEY = 'vcv-be-editor';

    public const TEMPLATE_POST_TYPE = 'vcv_templates';

    /** Attributes the editor keeps for itself and never renders. */
    public const EDITOR_ONLY_ATTRS = ['customHeaderTitle'];

    /** Keys of an element that are structure, not attributes. */
    public const STRUCTURE_KEYS = ['id', 'tag', 'parent', 'order'];

    /** Whether Visual Composer is loaded. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_visual_composer_active', defined('VCV_VERSION'));
    }

    /** The loaded Visual Composer's version, or null. */
    public static function plugin_version(): ?string
    {
        if (! self::plugin_active() || ! defined('VCV_VERSION')) {
            return null;
        }
        $version = (string) constant('VCV_VERSION');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs Visual Composer loaded. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "Visual Composer is not active on this site, so its pages cannot be changed. Install and activate Visual Composer ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'Visual Composer', 'url' => $url]
        );
    }

    /** Where a person edits the page in Visual Composer's own editor. */
    public static function editor_url(int $post_id): string
    {
        return admin_url('post.php?vcv-action=frontend&vcv-source-id=' . $post_id);
    }

    /** The refusal for a change only Visual Composer's browser editor can render. */
    public static function render_required(int $post_id, string $what): \WP_Error
    {
        $url = self::editor_url($post_id);

        return new \WP_Error(
            'editor_render_required',
            "Visual Composer renders a page's markup and CSS in its browser editor, so {$what} cannot be written from here without leaving the live page out of step with the editor. Nothing was written. Make this change in Visual Composer ({$url}); text content and layer names can be edited here.",
            ['status' => 422, 'editor_url' => $url]
        );
    }

    /**
     * Whether a post is a Visual Composer page: its own isVcvPost() test
     * (a document with elements) on a page not switched to another editor.
     */
    public static function is_page(int $post_id): bool
    {
        $editor = (string) get_post_meta($post_id, self::EDITOR_META_KEY, true);
        if (in_array($editor, ['gutenberg', 'classic'], true)) {
            return false;
        }
        $doc = self::document($post_id);

        return null !== $doc && isset($doc->elements) && is_object($doc->elements) && [] !== get_object_vars($doc->elements);
    }

    /**
     * The stored document, decoded with objects so empty objects survive a
     * write, or null when there is none or it cannot be decoded.
     */
    public static function document(int $post_id): ?object
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);
        if (! is_string($raw) || '' === $raw) {
            return null;
        }
        $doc = json_decode(rawurldecode($raw));

        return is_object($doc) ? $doc : null;
    }

    /** sha256 of the stored document row and the rendered page, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        $post = get_post($post_id);

        return hash('sha256', (string) get_post_meta($post_id, self::META_KEY, true) . "\0" . ($post ? (string) $post->post_content : ''));
    }

    /**
     * The elements as plain arrays, keyed by id.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function elements(?object $doc): array
    {
        if (null === $doc || ! isset($doc->elements) || ! is_object($doc->elements)) {
            return [];
        }
        $out = [];
        foreach (get_object_vars($doc->elements) as $id => $element) {
            if (is_object($element)) {
                $out[ (string) $id ] = (array) json_decode((string) wp_json_encode($element), true);
            }
        }

        return $out;
    }

    /**
     * The element tree: top-level elements in order, each with its children.
     *
     * @param array<string,array<string,mixed>> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function outline(array $elements, $parent = false, int $depth = 0): array
    {
        if ($depth > 50) {
            return [];
        }
        $level = [];
        foreach ($elements as $id => $element) {
            $p = $element['parent'] ?? false;
            if ((false === $parent && (false === $p || '' === $p || null === $p)) || (false !== $parent && (string) $p === (string) $parent)) {
                $level[] = $id;
            }
        }
        usort($level, static fn ($a, $b) => ((int) ($elements[ $a ]['order'] ?? 0)) <=> ((int) ($elements[ $b ]['order'] ?? 0)));

        $out = [];
        foreach ($level as $id) {
            $element = $elements[ $id ];
            $node    = ['id' => (string) $id, 'tag' => (string) ($element['tag'] ?? '')];
            $label   = self::label($element);
            if ('' !== $label) {
                $node['label'] = $label;
            }
            $children = self::outline($elements, (string) $id, $depth + 1);
            if ([] !== $children) {
                $node['children'] = $children;
            }
            $out[] = $node;
        }

        return $out;
    }

    /** @param array<string,mixed> $element */
    private static function label(array $element): string
    {
        foreach (['customHeaderTitle', 'output', 'buttonText', 'text', 'title'] as $key) {
            if (isset($element[ $key ]) && is_string($element[ $key ])) {
                $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($element[ $key ]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ('' !== $text) {
                    return function_exists('mb_substr') ? mb_substr($text, 0, 80) : substr($text, 0, 80);
                }
            }
        }

        return '';
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function read(int $post_id)
    {
        if (! current_user_can('read_post', $post_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to read this post.', ['status' => 403]);
        }

        $raw      = get_post_meta($post_id, self::META_KEY, true);
        $doc      = self::document($post_id);
        $elements = self::elements($doc);
        $out      = [
            'post_id'        => $post_id,
            'builder'        => 'visual-composer',
            'plugin_active'  => self::plugin_active(),
            'plugin_version' => self::plugin_version(),
            'status'         => (string) get_post_status($post_id),
            'editor_url'     => self::editor_url($post_id),
            'hash'           => self::hash($post_id),
            'tree'           => self::outline($elements),
            'elements'       => $elements,
            'writable'       => 'text content (an attribute that is the whole inner HTML of the element, such as a Text Block output) and customHeaderTitle; publish. Other changes need Visual Composer\'s editor.',
        ];
        if (is_string($raw) && '' !== $raw && null === $doc) {
            $out['tree_error'] = 'The stored Visual Composer document could not be decoded.';
        }

        return $out;
    }

    /**
     * Update one element's attributes in both stores, or refuse.
     *
     * @param array<string,mixed> $attrs attribute => new value
     * @return array{0:object,1:string} the new document and post_content
     * @throws \InvalidArgumentException
     * @throws \DomainException naming what only the editor can render
     */
    public static function update(int $post_id, string $id, array $attrs): array
    {
        $doc = self::document($post_id);
        if (null === $doc || ! isset($doc->elements) || ! is_object($doc->elements)) {
            throw new \InvalidArgumentException(esc_html('This page holds no Visual Composer document that can be read.'));
        }
        if ('' === $id || ! isset($doc->elements->{$id}) || ! is_object($doc->elements->{$id})) {
            throw new \InvalidArgumentException(esc_html("No element with id '{$id}' on this page; read it for the ids."));
        }
        if ([] === $attrs) {
            throw new \InvalidArgumentException(esc_html('update needs attrs (attribute => new value) or text.'));
        }

        $element = $doc->elements->{$id};
        $post    = get_post($post_id);
        $html    = $post ? (string) $post->post_content : '';

        foreach ($attrs as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::STRUCTURE_KEYS, true)) {
                throw new \InvalidArgumentException(esc_html("'{$key}' is element structure, not an attribute; moving or retagging an element needs Visual Composer's editor."));
            }
            if (in_array($key, self::EDITOR_ONLY_ATTRS, true)) {
                if (! is_string($value)) {
                    throw new \InvalidArgumentException(esc_html("{$key} must be a string."));
                }
                $element->{$key} = $value;
                continue;
            }
            $old = $element->{$key} ?? null;
            if (! is_string($value) || ! is_string($old)) {
                throw new \DomainException(esc_html("attribute '{$key}'"));
            }
            $patched = Visual_Composer_Html::replace_inner($html, $id, $old, $value);
            if (null === $patched) {
                throw new \DomainException(esc_html("attribute '{$key}' (its value is not the element's rendered content)"));
            }
            $html            = $patched;
            $element->{$key} = $value;
        }

        return [$doc, $html];
    }

    /** Store a document and its rendered page, the way Visual Composer's own server-side writer stores the document. */
    public static function save(int $post_id, object $doc, string $html): void
    {
        $post = get_post($post_id);
        if ($post && (string) $post->post_content !== $html) {
            Save_Filters::update_post(['ID' => $post_id, 'post_content' => wp_slash($html)]);
        }
        update_post_meta($post_id, self::META_KEY, rawurlencode((string) wp_json_encode($doc)));
    }
}
