<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Avada (Fusion Builder) dialect of the shared shortcode layout parser.
 * Avada stores a page as nested shortcodes in post_content:
 * fusion_builder_container > fusion_builder_row > fusion_builder_column,
 * with fusion_builder_row_inner > fusion_builder_column_inner nested inside a
 * column, and elements (fusion_text, fusion_title, fusion_button ...) inside
 * the columns. Addressing, parsing and byte-preserving edits are the parent's;
 * only the writing conventions differ:
 *  - the editor writes an element with no content self-closed
 *    (`[fusion_separator ... /]`), except fusion_text and fusion_code, and
 *    always closes the layout tags;
 *  - it writes attribute values raw, so the characters that would end the
 *    value or the tag are written as the HTML entities `&quot;`, `&#91;` and
 *    `&#93;`, which render as the original characters in the element's
 *    output.
 */
class Avada_Shortcodes extends WPBakery_Shortcodes
{
    protected const CONTAINER_TAG = '/^fusion_(?:builder_(?:container|row|row_inner|column|column_inner)|text|code)$/';

    protected const ATTR_ENCODING = ['"' => '&quot;', '[' => '&#91;', ']' => '&#93;'];

    protected const SELF_CLOSE_EMPTY = true;
}
