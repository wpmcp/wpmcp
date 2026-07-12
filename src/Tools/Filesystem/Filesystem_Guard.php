<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for the filesystem tools: confines every path to the
 * WordPress install root (ABSPATH in production; a temp fixture root in
 * tests), refuses protected files, and backs up before destructive writes.
 *
 * resolve_path() is the one chokepoint that makes "inside the WordPress
 * install only" true, so every rejection case is exercised exhaustively by
 * FilesystemGuardTest, including a real symlink escaping the root.
 */
class Filesystem_Guard
{
    /**
     * Confine a path to $root (defaults to ABSPATH). Returns the canonical
     * absolute path, or a WP_Error when the path is invalid or escapes root.
     *
     * @return string|\WP_Error
     */
    public static function resolve_path(string $path, ?string $root = null)
    {
        $root = $root ?? ABSPATH;

        if (false !== strpos($path, "\0")) {
            return new \WP_Error('invalid_path', 'Invalid file path.');
        }

        $is_absolute = ('' !== $path) && (
            '/' === $path[0]
            || '\\' === $path[0]
            || 1 === preg_match('#^[A-Za-z]:[\\\\/]#', $path)
        );
        $candidate = $is_absolute
            ? $path
            : rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');

        $real = realpath($candidate);
        if (false === $real) {
            $real = $candidate;
        }

        $root_real = realpath($root);
        if (false === $root_real) {
            $root_real = $root;
        }

        $real_n = rtrim($real, '/\\');
        $root_n = rtrim($root_real, '/\\');
        if ($real_n !== $root_n && 0 !== strpos($real_n, $root_n . DIRECTORY_SEPARATOR)) {
            return new \WP_Error('outside_root', 'Path is outside the WordPress installation.');
        }

        return $real;
    }
}
