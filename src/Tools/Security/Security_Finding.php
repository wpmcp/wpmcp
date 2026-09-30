<?php

namespace WPMCP\Tools\Security;

if (! defined('ABSPATH')) {
    exit;
}

class Security_Finding
{
    /**
     * Build the canonical finding array shared by every security audit.
     *
     * @param string $category       malware|integrity|hardening|software|vulnerabilities
     * @param string $status         pass|warning|critical|info
     * @param mixed  $value
     * @param string $recommendation Non-empty when status is not "pass".
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
