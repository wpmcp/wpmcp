<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The calls into a loaded Cornerstone (Themeco) the adapter makes (issue
 * #646), recorded from Cornerstone 7.8.12:
 *
 * - element definitions come from its Elements service
 *   (cornerstone('Elements')): get_element() answers the 'undefined'
 *   element for a type it does not know, and a definition's options carry
 *   valid_children ('*', a type or a list of types; the region keys it by
 *   region, the page body being 'content') and valid_parent (a type, or
 *   'region' for the top level);
 * - a page is saved the way the builder's own save does it
 *   (Controllers\Documents::update()): Document::locate($id) gives the
 *   page's Content document, update(['elements' => ...]) runs cs_sanitize()
 *   over the values (kses for a user without unfiltered_html) and save()
 *   writes `_cornerstone_data` without element ids, drops
 *   `_cornerstone_override` and the generated styles, renders the page
 *   into post_content between `<!-- cs-content -->` comments, and lets its
 *   save_post hook copy the rows onto the new revision;
 * - Content::isAllowed() is its own per-post-type permission check.
 *
 * Tests stand a double in through the `wpmcp_cornerstone_engine` filter: an
 * object with the same public methods.
 */
class Cornerstone_Engine
{
    /** The engine to use, or null when Cornerstone is not loaded. */
    public static function get(): ?object
    {
        $double = apply_filters('wpmcp_cornerstone_engine', null);
        if (is_object($double)) {
            return $double;
        }

        return self::loaded() ? new self() : null;
    }

    public static function loaded(): bool
    {
        return defined('CS_VERSION') && function_exists('cornerstone') && class_exists('Themeco\\Cornerstone\\Documents\\Document');
    }

    /**
     * One element definition: {type, title, group, valid_children,
     * valid_parent, in_library}, or null for a type Cornerstone does not know.
     *
     * @return array<string,mixed>|null
     */
    public function definition(string $type): ?array
    {
        $definition = cornerstone('Elements')->get_element($type);
        if (! is_object($definition) || ('undefined' === $definition->get_type() && 'undefined' !== $type)) {
            return null;
        }
        $data    = $definition->serialize();
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        return [
            'type'           => $type,
            'title'          => (string) ($data['title'] ?? ''),
            'group'          => $data['group'] ?? null,
            'valid_children' => $options['valid_children'] ?? null,
            'valid_parent'   => $options['valid_parent'] ?? null,
            'in_library'     => (bool) $definition->in_library(),
        ];
    }

    /**
     * The elements the builder's library offers, by title.
     *
     * @return array<int,array<string,mixed>>
     */
    public function catalog(): array
    {
        $out = [];
        foreach (cornerstone('Elements')->get_public_definitions() as $definition) {
            $data  = $definition->serialize();
            $out[] = ['type' => $definition->get_type(), 'title' => (string) ($data['title'] ?? ''), 'group' => $data['group'] ?? null];
        }

        return $out;
    }

    /**
     * One element's schema: its definition, every value key with its
     * default and designation (markup:seo marks text the page shows, style
     * a style value), and the keys its editor edits inline.
     *
     * @return array<string,mixed>|null
     */
    public function schema(string $type): ?array
    {
        $base = $this->definition($type);
        if (null === $base) {
            return null;
        }
        $definition = cornerstone('Elements')->get_element($type);
        $data       = $definition->serialize();
        $inline     = $data['options']['inline'] ?? [];

        return $base + [
            'inline'       => is_array($inline) ? array_keys($inline) : [],
            'defaults'     => $definition->get_defaults(),
            'designations' => $definition->get_designations(),
        ];
    }

    /** Whether Cornerstone lets the current user edit this page. */
    public function allowed(int $post_id): bool
    {
        $doc = self::document($post_id);

        return null !== $doc && $doc->isAllowed();
    }

    /**
     * Save a page's elements through Cornerstone's own document save.
     *
     * @param array<int,array<string,mixed>> $elements
     */
    public function save(int $post_id, array $elements): void
    {
        self::forget_rendered_document($post_id);

        $doc = self::document($post_id);
        if (null === $doc) {
            throw new \RuntimeException(esc_html("Cornerstone could not load post {$post_id} as a page."));
        }
        $doc->update(['elements' => $elements])->save();
    }

    /**
     * Cornerstone renders post_content from the document its Resolver keeps
     * per request (a DocumentCache of its own, not a shared service, with no
     * public way to drop an entry). Its builder saves one page per request,
     * so that never matters there; here a page read or written earlier in
     * the same request (a batch of edits) would be rendered as it was then.
     * Drop this page's entry so the save renders the elements it stores.
     */
    private static function forget_rendered_document(int $post_id): void
    {
        $resolver = cornerstone('Resolver');
        if (! is_object($resolver) || ! property_exists($resolver, 'documentCache')) {
            return;
        }
        $property = new \ReflectionProperty($resolver, 'documentCache');
        $cache = $property->getValue($resolver);
        if (is_object($cache) && method_exists($cache, 'unset')) {
            $cache->unset($post_id);
        }
    }

    /** The page's Content document, or null when it is not one. */
    private static function document(int $post_id): ?object
    {
        $doc = \Themeco\Cornerstone\Documents\Document::locate($post_id);

        return $doc instanceof \Themeco\Cornerstone\Documents\Content ? $doc : null;
    }
}
