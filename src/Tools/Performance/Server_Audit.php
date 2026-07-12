<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Server_Audit
{
    public function evaluate_php_version(string $version): array
    {
        if (version_compare($version, '8.2', '>=')) {
            return Finding::make(
                'php_version',
                'server',
                'PHP version',
                'pass',
                $version,
                sprintf('PHP %s is current and supported.', $version)
            );
        }
        if (version_compare($version, '8.0', '>=')) {
            return Finding::make(
                'php_version',
                'server',
                'PHP version',
                'warning',
                $version,
                sprintf('PHP %s is nearing end of life.', $version),
                'Upgrade to PHP 8.2 or newer for better performance and security support.'
            );
        }
        return Finding::make(
            'php_version',
            'server',
            'PHP version',
            'critical',
            $version,
            sprintf('PHP %s is end-of-life and unsupported.', $version),
            'Upgrade to PHP 8.2+ immediately; old PHP is slow and a security risk.'
        );
    }
}
