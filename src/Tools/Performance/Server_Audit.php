<?php

namespace WPMCP\Tools\Performance;

if (! defined('ABSPATH')) {
    exit;
}

class Server_Audit
{
    private const MIN_MEMORY_BYTES     = 134217728; // 128 MB
    private const PLUGIN_WARN_COUNT    = 40;
    private const REVISIONS_WARN_COUNT = 1000;
    private const AUTOLOAD_WARN_BYTES  = 1048576;  // 1 MB
    private const AUTOLOAD_CRIT_BYTES  = 3145728;  // 3 MB

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

    public function evaluate_opcache(bool $enabled): array
    {
        return $enabled
            ? Finding::make('opcache', 'server', 'PHP OPcache', 'pass', true, 'OPcache is enabled.')
            : Finding::make(
                'opcache',
                'server',
                'PHP OPcache',
                'warning',
                false,
                'OPcache is disabled.',
                'Enable the Zend OPcache extension, it caches compiled PHP and dramatically reduces request time.'
            );
    }

    public function evaluate_object_cache(bool $persistent): array
    {
        return $persistent
            ? Finding::make('object_cache', 'server', 'Persistent object cache', 'pass', true, 'A persistent object cache is active.')
            : Finding::make(
                'object_cache',
                'server',
                'Persistent object cache',
                'warning',
                false,
                'No persistent object cache detected.',
                'Add Redis or Memcached with a drop-in (for example redis-cache) to cache DB queries across requests.'
            );
    }

    public function evaluate_image_lib(bool $imagick, bool $gd): array
    {
        if ($imagick || $gd) {
            return Finding::make(
                'image_lib',
                'server',
                'Image library',
                'pass',
                $imagick ? 'imagick' : 'gd',
                'An image processing library is available.'
            );
        }
        return Finding::make(
            'image_lib',
            'server',
            'Image library',
            'warning',
            'none',
            'No image library (Imagick/GD) detected.',
            'Install Imagick or GD so WordPress can generate optimized image sizes.'
        );
    }

    public function evaluate_wp_debug(bool $on, string $environment): array
    {
        if (! $on) {
            return Finding::make('wp_debug', 'config', 'WP_DEBUG', 'pass', false, 'WP_DEBUG is off.');
        }
        if ('production' === $environment) {
            return Finding::make(
                'wp_debug',
                'config',
                'WP_DEBUG',
                'warning',
                true,
                'WP_DEBUG is ON in production.',
                'Turn off WP_DEBUG on production, debug logging and notices add overhead and leak information.'
            );
        }
        return Finding::make(
            'wp_debug',
            'config',
            'WP_DEBUG',
            'info',
            true,
            sprintf('WP_DEBUG is on (environment: %s).', $environment)
        );
    }

    public function evaluate_plugin_count(int $count): array
    {
        if ($count > self::PLUGIN_WARN_COUNT) {
            return Finding::make(
                'plugin_count',
                'config',
                'Active plugins',
                'warning',
                $count,
                sprintf('%d active plugins.', $count),
                'A large plugin count compounds per-request overhead. Audit for unused or overlapping plugins.'
            );
        }
        return Finding::make('plugin_count', 'config', 'Active plugins', 'info', $count, sprintf('%d active plugins.', $count));
    }

    public function evaluate_revisions(int $count): array
    {
        if ($count > self::REVISIONS_WARN_COUNT) {
            return Finding::make(
                'post_revisions',
                'database',
                'Post revisions',
                'warning',
                $count,
                sprintf('%d post revisions stored.', $count),
                'Cap revisions with define("WP_POST_REVISIONS", 5) and clean old ones to shrink the posts table.'
            );
        }
        return Finding::make('post_revisions', 'database', 'Post revisions', 'info', $count, sprintf('%d post revisions stored.', $count));
    }

    public function evaluate_cron_backlog(int $overdue): array
    {
        if ($overdue > 0) {
            return Finding::make(
                'cron_backlog',
                'config',
                'WP-Cron backlog',
                'warning',
                $overdue,
                sprintf('%d overdue cron events.', $overdue),
                'A backlog means cron is not firing (low traffic, or DISABLE_WP_CRON without a real cron job). Add a server cron hitting wp-cron.php.'
            );
        }
        return Finding::make('cron_backlog', 'config', 'WP-Cron backlog', 'pass', 0, 'No overdue cron events.');
    }

    public function evaluate_autoload_size(int $bytes, array $top_options): array
    {
        $human = $this->human_bytes($bytes);
        $value = ['bytes' => $bytes, 'top' => $top_options];
        if ($bytes >= self::AUTOLOAD_CRIT_BYTES) {
            return Finding::make(
                'autoload_size',
                'database',
                'Autoloaded options',
                'critical',
                $value,
                sprintf('Autoloaded options total %s, loaded on every request.', $human),
                'Find and disable autoload for the largest offenders (often stale plugin caches). See the listed options.'
            );
        }
        if ($bytes >= self::AUTOLOAD_WARN_BYTES) {
            return Finding::make(
                'autoload_size',
                'database',
                'Autoloaded options',
                'warning',
                $value,
                sprintf('Autoloaded options total %s.', $human),
                'Trim autoloaded options above ~1 MB; large autoload bloats every request.'
            );
        }
        return Finding::make('autoload_size', 'database', 'Autoloaded options', 'pass', $value, sprintf('Autoloaded options total %s.', $human));
    }

    public function evaluate_database_size(int $bytes, array $top_tables): array
    {
        return Finding::make(
            'database_size',
            'database',
            'Database size',
            'info',
            ['bytes' => $bytes, 'top_tables' => $top_tables],
            sprintf('Database is %s; %d largest tables listed.', $this->human_bytes($bytes), count($top_tables))
        );
    }

    private function human_bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
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
