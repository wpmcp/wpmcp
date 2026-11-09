<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-level pieces of WPBakery editing (issue #639) that sit beside the
 * element operations of WPBakery_Shortcodes: the content hash a write can be
 * pinned to, the page's revisions, a revision's layout to restore, inserting
 * a template's markup at a tree position, and copying one element's markup
 * out of a page.
 *
 * Insertion and copying never re-serialize: they splice the stored string
 * around unique placeholder elements placed through the parser's own add(),
 * so the template or element keeps every byte it was stored with, and the
 * parser's container checks apply unchanged.
 */
final class WPBakery_Page
{
    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    public static function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            if (wp_is_post_autosave($revision)) {
                continue;
            }
            $out[] = [
                'id'       => (int) $revision->ID,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => self::hash((string) $revision->post_content),
            ];
        }

        return $out;
    }

    /**
     * The layout of one of the page's revisions.
     *
     * @throws \InvalidArgumentException when $revision is not a revision of $post_id
     */
    public static function revision_content(int $post_id, string $revision): string
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }

        return (string) $post->post_content;
    }

    /**
     * Insert a template's layout under a container ('' is the top level) at
     * a child index. Returns the new content and the path of the template's
     * first element.
     *
     * @return array{0:string,1:string}
     */
    public static function insert_template(string $content, string $to, ?int $index, string $template): array
    {
        $markup = WPBakery_Design::template_content($template);
        if (null === $markup || '' === trim($markup)) {
            throw new \InvalidArgumentException(esc_html("No WPBakery template '{$template}'; read get-builder-content scope templates for the ids."));
        }

        $tag = self::placeholder($content . $markup);
        [$placed, $path] = WPBakery_Shortcodes::add($content, $to, $index, ['tag' => $tag]);

        return [self::replace_once($placed, '[' . $tag . ']', $markup), $path];
    }

    /**
     * The exact stored markup of the element at $path ('' is the whole page).
     */
    public static function slice(string $content, string $path): string
    {
        if ('' === $path) {
            return $content;
        }
        if (! preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            throw new \InvalidArgumentException(esc_html("Invalid path {$path}; use dotted child indexes such as 0.1.0."));
        }

        $steps  = explode('.', $path);
        $index  = (int) array_pop($steps);
        $parent = implode('.', $steps);

        // Resolves the path (and throws when nothing is there) before any
        // placeholder goes in.
        WPBakery_Shortcodes::update($content, $path, [], null);

        $before = self::placeholder($content);
        $after  = $before . '_end';
        [$marked] = WPBakery_Shortcodes::add($content, $parent, $index, ['tag' => $before]);
        [$marked] = WPBakery_Shortcodes::add($marked, $parent, $index + 2, ['tag' => $after]);

        $start = strpos($marked, '[' . $before . ']') + strlen('[' . $before . ']');
        $end   = strpos($marked, '[' . $after . ']');

        return substr($marked, $start, $end - $start);
    }

    /** A void tag that occurs nowhere in $haystack. */
    private static function placeholder(string $haystack): string
    {
        do {
            $tag = 'wpmcp_mark_' . bin2hex(random_bytes(6));
        } while (false !== strpos($haystack, $tag));

        return $tag;
    }

    private static function replace_once(string $subject, string $search, string $replace): string
    {
        $at = strpos($subject, $search);

        return false === $at ? $subject : substr_replace($subject, $replace, $at, strlen($search));
    }
}
