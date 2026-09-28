<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one place wpmcp invalidates a page's Avada dynamic CSS cache.
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace.
 *
 * Avada compiles each page's dynamic CSS once and keeps it in three places:
 * a `fusion_dynamic_css_<post id>` transient, and the page's entry in two
 * options keyed by post id, `fusion_dynamic_css_posts` (whether the page's
 * CSS file is current) and `fusion_dynamic_css_ids` (the hash naming that
 * file under uploads/fusion-styles). Its own save_post handler deletes the
 * transient and sets both entries to false, and the next front-end view
 * recompiles. Any other change to the page (a raw write, a snapshot restore)
 * must do the same or the page keeps serving the old file.
 *
 * Done whether or not Avada is loaded, since the rows outlive it: a stale
 * entry would be served as soon as it is active again. Options that do not
 * exist are left absent, so a site without Avada gains nothing.
 */
final class Avada_Cache
{
    public const STATUS_META_KEY = 'fusion_builder_status';

    private const OPTIONS = ['fusion_dynamic_css_ids', 'fusion_dynamic_css_posts'];

    public static function invalidate(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        delete_transient('fusion_dynamic_css_' . $post_id);

        foreach (self::OPTIONS as $name) {
            $option = get_option($name);
            if (is_array($option) && false !== ($option[ $post_id ] ?? false)) {
                $option[ $post_id ] = false;
                update_option($name, $option);
            }
        }
    }
}
