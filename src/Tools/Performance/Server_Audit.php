<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Server_Audit
{
    private const MIN_MEMORY_BYTES = 134217728; // 128 MB

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

    public function evaluate_memory_limit(string $limit): array
    {
        $bytes = $this->to_bytes($limit);
        if ($bytes < 0) { // -1 = unlimited.
            return Finding::make('memory_limit', 'server', 'PHP memory limit', 'pass', $limit, 'PHP memory is unlimited.');
        }
        if ($bytes >= self::MIN_MEMORY_BYTES) {
            return Finding::make(
                'memory_limit',
                'server',
                'PHP memory limit',
                'pass',
                $limit,
                sprintf('PHP memory limit is %s.', $limit)
            );
        }
        return Finding::make(
            'memory_limit',
            'server',
            'PHP memory limit',
            'warning',
            $limit,
            sprintf('PHP memory limit is only %s.', $limit),
            'Raise memory_limit (and WP_MEMORY_LIMIT) to at least 128M to avoid out-of-memory errors under load.'
        );
    }

    private function to_bytes(string $value): int
    {
        $value = trim($value);
        if ('-1' === $value) {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num  = (int) $value;
        switch ($unit) {
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'm':
                return $num * 1024 * 1024;
            case 'k':
                return $num * 1024;
            default:
                return (int) $value;
        }
    }
}
