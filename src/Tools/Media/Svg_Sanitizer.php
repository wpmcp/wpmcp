<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bundled fail-closed SVG sanitizer (issue #64). SVGs are XML documents that
 * browsers treat as active content, so the stance is REJECT anything with
 * script or external-fetch capability, STRIP what is merely unwanted, and
 * fail closed on anything unparseable:
 *
 *  - Rejected outright (whole document refused): <script>, <foreignObject>
 *    (and other embedding elements), any on* event-handler attribute,
 *    javascript:/data:text/* hrefs, external URL references (href, url(...)
 *    pointing anywhere but an internal #fragment or an embedded raster
 *    data: URI), DOCTYPE/ENTITY declarations (entity-expansion bombs), a
 *    non-<svg> root, and any markup libxml cannot parse.
 *  - Stripped from accepted documents: comments, processing instructions,
 *    and elements outside the allowlist (metadata, style, editor cruft).
 *
 * All name checks are done on the lowercased localName, so <ScRiPt> and
 * ONLOAD= receive no case-trick exemption. Parsing uses DOMDocument with
 * LIBXML_NONET; entities never load because any DOCTYPE is rejected before
 * the parser runs.
 */
class Svg_Sanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /** Elements whose mere presence rejects the document. */
    private const REJECT_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'handler',
        'annotation-xml', 'audio', 'video', 'set', 'animate', 'animatemotion', 'animatetransform',
    ];

    /** Elements kept (everything else non-rejecting is stripped). */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath', 'image',
        'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'marker',
    ];

    /**
     * @throws \InvalidArgumentException on any rejecting construct;
     *         fail closed, never "best effort" on dangerous input.
     */
    public static function sanitize(string $markup): string
    {
        $markup = trim($markup);
        if ('' === $markup) {
            throw new \InvalidArgumentException('The SVG document is empty.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $markup)) {
            throw new \InvalidArgumentException('SVGs with DOCTYPE or ENTITY declarations are rejected (entity-expansion risk).');
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = new \DOMDocument();
        $loaded   = $doc->loadXML($markup, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $doc->documentElement) {
            throw new \InvalidArgumentException('The SVG could not be parsed as well-formed XML; rejecting (fail closed).');
        }

        // Belt-and-braces for the pre-parse regex: that check runs on raw
        // bytes, so a DOCTYPE hidden by an exotic input encoding (e.g.
        // UTF-16) could slip past it while libxml still honors it. If the
        // parsed document carries ANY doctype, reject regardless.
        if (null !== $doc->doctype) {
            throw new \InvalidArgumentException('SVGs with DOCTYPE or ENTITY declarations are rejected (entity-expansion risk).');
        }

        $root = $doc->documentElement;
        if ('svg' !== strtolower((string) $root->localName) || self::SVG_NS !== (string) $root->namespaceURI) {
            throw new \InvalidArgumentException('The document root is not an SVG element.');
        }

        $xpath = new \DOMXPath($doc);

        // Comments and processing instructions are stripped, never kept.
        foreach (iterator_to_array($xpath->query('//comment() | //processing-instruction()')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//*')) as $element) {
            /** @var \DOMElement $element */
            $name = strtolower((string) $element->localName);

            if (in_array($name, self::REJECT_ELEMENTS, true)) {
                throw new \InvalidArgumentException(sprintf('SVG element <%s> is not allowed.', esc_html($element->localName)));
            }

            // Attributes are vetted on EVERY element — including ones about
            // to be stripped — so a dangerous payload (onload, javascript:
            // href) rejects the document even when it rides on an element
            // outside the allowlist. Strip-then-accept would silently launder
            // such input into an "accepted" file.
            foreach (iterator_to_array($element->attributes) as $attr) {
                /** @var \DOMAttr $attr */
                self::vet_attribute($attr);
            }

            if (! in_array($name, self::ALLOWED_ELEMENTS, true)) {
                $element->parentNode?->removeChild($element);
            }
        }

        return (string) $doc->saveXML($doc->documentElement);
    }

    /** @throws \InvalidArgumentException on any dangerous attribute. */
    private static function vet_attribute(\DOMAttr $attr): void
    {
        $name  = strtolower((string) $attr->localName);
        $value = (string) $attr->value;

        if (str_starts_with($name, 'on')) {
            throw new \InvalidArgumentException(sprintf('SVG event-handler attribute "%s" is not allowed.', esc_html($attr->name)));
        }

        if ('href' === $name) {
            self::vet_reference($value);
        }

        // url(...) can trigger external fetches from style, fill, filter,
        // mask, clip-path, etc. Only internal fragment references pass.
        if (preg_match('/url\s*\(\s*["\']?\s*(?!#)[^"\')\s]/i', $value)) {
            throw new \InvalidArgumentException('SVG url(...) references may only target internal #fragments.');
        }

        if ('style' === $name && preg_match('/expression|@import|javascript:/i', $value)) {
            throw new \InvalidArgumentException('SVG style attribute contains a disallowed construct.');
        }
    }

    /** href / xlink:href values: internal fragments and embedded raster data URIs only. */
    private static function vet_reference(string $value): void
    {
        // Normalize away whitespace/control characters used to smuggle schemes.
        $normalized = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', $value));

        if ('' === $normalized || str_starts_with($normalized, '#')) {
            return;
        }
        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $normalized)) {
            return;
        }

        throw new \InvalidArgumentException('SVG href references may only target internal #fragments or embedded raster data: URIs.');
    }
}
