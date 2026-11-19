<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registered image sizes an attachment lacks (issue #716), the way core's
 * own upload flow sees them: wp_get_missing_image_subsizes() skips sizes the
 * original is too small for, so every name returned here can be generated.
 */
class Image_Subsizes
{
    /** @return string[] */
    public static function missing(int $attachment_id): array
    {
        if (! wp_attachment_is_image($attachment_id)) {
            return [];
        }
        if (! function_exists('wp_get_missing_image_subsizes')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        return array_values(array_map('strval', array_keys((array) wp_get_missing_image_subsizes($attachment_id))));
    }
}
