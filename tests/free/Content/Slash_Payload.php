<?php

namespace WPMCP\Tests\Free\Content;

/**
 * Values that lose bytes when a write path forgets that WordPress unslashes
 * what it is given (issue #425): a lone less-than, JSON's \/ and \\ escapes,
 * a literal backslash, unicode-escaped block attributes and an Elementor
 * JSON tree. Every write path's round-trip test stores one of these and
 * reads it back byte for byte.
 */
trait Slash_Payload
{
    /** A post body: block markup plus an Elementor JSON tree. */
    protected static function body(): string
    {
        return <<<'TXT'
<!-- wp:paragraph {"placeholder":"\u003cb\u003e \/ \\ \"q\""} -->
<p>1 < 2, C:\temp\new, a\/b, a\\b</p>
<!-- /wp:paragraph -->
[{"id":"e1","elType":"widget","settings":{"link":"https:\/\/example.test\/","html":"\u003cp\u003e","path":"C:\\dir"},"widgetType":"html"}]
TXT;
    }

    /**
     * One line of plain text for fields that are sanitized as text (titles,
     * names, alt text): no markup, quotes or newlines to be normalized, only
     * the backslashes under test.
     */
    protected static function line(): string
    {
        return <<<'TXT'
C:\temp\new a\/b a\\b \u003c end\
TXT;
    }

    /** A nested meta value, the shape array-valued meta takes. */
    protected static function nested(): array
    {
        return ['body' => self::body(), 'list' => [self::line(), 'plain']];
    }
}
