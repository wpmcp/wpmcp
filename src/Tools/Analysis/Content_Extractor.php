<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only helper that turns a post's stored HTML content into a structural
 * summary: headings (level + text), links (with internal/external
 * classification), images (src + alt), form fields (label coverage), the
 * readable plain text, and a word count.
 *
 * Content is parsed with DOMDocument under libxml error suppression, matching
 * the parse approach already used by Performance\Page_Audit. This reads the
 * stored post_content HTML (Gutenberg or classic markup); it deliberately does
 * NOT walk the Elementor _elementor_data element tree. Elementor-native
 * extraction is a possible future extension and is out of scope here.
 */
class Content_Extractor
{
    /**
     * @return array{
     *   post_id:int, headings:array<int,array{level:int,text:string}>,
     *   links:array<int,array{url:string,text:string,internal:bool}>,
     *   images:array<int,array{src:string,alt:string,location:string}>,
     *   form_fields:array<int,array{label:string,type:string,location:string}>,
     *   text:string, word_count:int
     * }
     */
    public static function extract(int $post_id): array
    {
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $html = (string) $post->post_content;
        $dom  = self::parse_dom($html);

        if (null === $dom) {
            return [
                'post_id'     => (int) $post->ID,
                'headings'    => [],
                'links'       => [],
                'images'      => [],
                'form_fields' => [],
                'text'        => '',
                'word_count'  => 0,
            ];
        }

        $home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $text      = self::plain_text($dom);

        return [
            'post_id'     => (int) $post->ID,
            'headings'    => self::headings($dom),
            'links'       => self::links($dom, $home_host),
            'images'      => self::images($dom),
            'form_fields' => self::form_fields($dom),
            'text'        => $text,
            'word_count'  => self::word_count($text),
        ];
    }

    private static function parse_dom(string $html): ?\DOMDocument
    {
        if ('' === trim($html)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $dom      = new \DOMDocument();
        // Force UTF-8 handling; loadHTML assumes ISO-8859-1 otherwise.
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $dom;
    }

    /**
     * @return array<int,array{level:int,text:string}>
     */
    private static function headings(\DOMDocument $dom): array
    {
        $out = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $level = (int) substr($tag, 1);
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $text = trim((string) $node->textContent);
                if ('' === $text) {
                    continue;
                }
                $out[] = ['level' => $level, 'text' => $text];
            }
        }
        return $out;
    }

    /**
     * @return array<int,array{url:string,text:string,internal:bool}>
     */
    private static function links(\DOMDocument $dom, string $home_host): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('a') as $node) {
            $href = trim((string) $node->getAttribute('href'));
            if ('' === $href) {
                continue;
            }
            $out[] = [
                'url'      => $href,
                'text'     => trim((string) $node->textContent),
                'internal' => self::is_internal($href, $home_host),
            ];
        }
        return $out;
    }

    /**
     * A link is internal when it is root-relative, a fragment/relative path, or
     * points at the site's own host. Only absolute URLs with a different host
     * are external.
     */
    private static function is_internal(string $href, string $home_host): bool
    {
        $host = (string) wp_parse_url($href, PHP_URL_HOST);
        if ('' === $host) {
            // Root-relative, fragment, or scheme-less relative path.
            return ! preg_match('#^(mailto:|tel:)#i', $href);
        }
        return strtolower($host) === strtolower($home_host);
    }

    /**
     * @return array<int,array{src:string,alt:string,location:string}>
     */
    private static function images(\DOMDocument $dom): array
    {
        $out = [];
        $index = 0;
        foreach ($dom->getElementsByTagName('img') as $node) {
            $index++;
            $out[] = [
                'src'      => trim((string) $node->getAttribute('src')),
                'alt'      => $node->hasAttribute('alt') ? trim((string) $node->getAttribute('alt')) : '',
                'location' => 'img[' . $index . ']',
            ];
        }
        return $out;
    }

    /**
     * Extract form controls with their resolved label text. A control is
     * labelled when a <label for> targets its id, or when it is wrapped by a
     * <label>. Otherwise the label is an empty string, which the accessibility
     * audit flags.
     *
     * @return array<int,array{label:string,type:string,location:string}>
     */
    private static function form_fields(\DOMDocument $dom): array
    {
        $labels_for = [];
        foreach ($dom->getElementsByTagName('label') as $label) {
            $for = trim((string) $label->getAttribute('for'));
            if ('' !== $for) {
                $labels_for[$for] = trim((string) $label->textContent);
            }
        }

        $out   = [];
        $index = 0;
        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $type = strtolower((string) $node->getAttribute('type'));
                if ('input' === $tag && in_array($type, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
                    continue;
                }
                $index++;
                $id    = trim((string) $node->getAttribute('id'));
                $label = '';
                if ('' !== $id && isset($labels_for[$id])) {
                    $label = $labels_for[$id];
                } elseif (self::wrapped_by_label($node)) {
                    $label = self::ancestor_label_text($node);
                } elseif ('' !== trim((string) $node->getAttribute('aria-label'))) {
                    $label = trim((string) $node->getAttribute('aria-label'));
                }
                $out[] = [
                    'label'    => $label,
                    'type'     => '' !== $type ? $type : $tag,
                    'location' => $tag . '[' . $index . ']',
                ];
            }
        }
        return $out;
    }

    private static function wrapped_by_label(\DOMNode $node): bool
    {
        for ($p = $node->parentNode; null !== $p; $p = $p->parentNode) {
            if ('label' === strtolower($p->nodeName)) {
                return true;
            }
        }
        return false;
    }

    private static function ancestor_label_text(\DOMNode $node): string
    {
        for ($p = $node->parentNode; null !== $p; $p = $p->parentNode) {
            if ('label' === strtolower($p->nodeName)) {
                return trim((string) $p->textContent);
            }
        }
        return '';
    }

    private static function plain_text(\DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        $text = null !== $body ? (string) $body->textContent : (string) $dom->textContent;
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private static function word_count(string $text): int
    {
        if ('' === trim($text)) {
            return 0;
        }
        return count(preg_split('/\s+/', trim($text)) ?: []);
    }
}
