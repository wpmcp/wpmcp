<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Finding
{
    /**
     * Build the canonical finding array shared by every performance audit.
     *
     * @param string $category server|database|config|page|assets
     * @param string $status   pass|warning|critical|info
     * @param mixed  $value
     */
    public static function make(
        string $id,
        string $category,
        string $label,
        string $status,
        $value,
        string $message,
        string $recommendation = ''
    ): array {
        return [
            'id'             => $id,
            'category'       => $category,
            'label'          => $label,
            'status'         => $status,
            'value'          => $value,
            'message'        => $message,
            'recommendation' => $recommendation,
        ];
    }
}
