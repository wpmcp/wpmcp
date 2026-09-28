<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validates an uploaded plugin or theme ZIP BEFORE anything is extracted
 * (issue #282). Core's upgrader is only ever handed an archive this class
 * has already read end to end, from its central directory, without writing
 * a byte to disk.
 *
 * Refused outright:
 *  - an entry name that is absolute (leading slash or a drive letter), holds
 *    a ".." segment (either slash direction) or a NUL byte;
 *  - a symlink entry (a unix mode of S_IFLNK in the external attributes),
 *    which could point an extracted path anywhere on the server;
 *  - anything but exactly one top-level directory, so the package lands in
 *    one directory under the plugins or themes root and nowhere else;
 *  - an archive that is not what the caller said it is: a plugin needs a
 *    PHP file directly in that directory with a Plugin Name header, a theme
 *    needs style.css there with a Theme Name header;
 *  - more entries or more uncompressed bytes than the caps below.
 *
 * macOS __MACOSX/ metadata is ignored when counting roots, because core's
 * own unzip skips it, but its entry names are still checked.
 */
class Package_Archive
{
    /** Kept in step with the refusal message below. */
    public const MAX_ENTRIES = 20000;

    public const MAX_UNCOMPRESSED_BYTES = 536870912;

    /** Same window core's get_file_data() reads headers from. */
    private const HEADER_BYTES = 8192;

    private const IGNORED_ROOT = '__MACOSX';

    private const ROOT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** Unix file-type bits and the symlink type, as stored in the high 16 bits. */
    private const S_IFMT  = 0170000;
    private const S_IFLNK = 0120000;

    /**
     * @param string $type 'plugin' or 'theme'.
     * @return array{root: string, main_file: string} main_file is the plugin
     *         file relative to root, '' for a theme.
     * @throws Package_Rejected
     */
    public static function inspect(string $path, string $type): array
    {
        if (! class_exists('ZipArchive')) {
            throw new Package_Rejected('The ZipArchive PHP extension is required to validate a package archive.', 'zip-unsupported');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new Package_Rejected('The attachment is not a readable ZIP archive.', 'not-a-zip');
        }

        try {
            $root = self::single_root($zip);
            if ('theme' === $type) {
                self::require_header($zip, $root . '/style.css', 'Theme Name');
                return ['root' => $root, 'main_file' => ''];
            }
            return ['root' => $root, 'main_file' => self::plugin_main_file($zip, $root)];
        } finally {
            $zip->close();
        }
    }

    /** Check every entry, then return the one top-level directory. */
    private static function single_root(\ZipArchive $zip): string
    {
        $count = $zip->numFiles;
        if ($count < 1) {
            throw new Package_Rejected('The archive is empty.', 'empty');
        }
        if ($count > self::MAX_ENTRIES) {
            throw new Package_Rejected('The archive has more than 20000 entries.', 'too-many-entries');
        }

        $roots = [];
        $bytes = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';

            $segments = self::checked_segments($name);
            if (self::is_symlink($zip, $i)) {
                throw new Package_Rejected(sprintf('The archive contains a symlink (%s).', esc_html($name)), 'symlink');
            }

            $bytes += (int) ($stat['size'] ?? 0);
            if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new Package_Rejected('The archive expands to more than 512 MB.', 'too-large');
            }

            if (self::IGNORED_ROOT === $segments[0]) {
                continue;
            }
            // A single segment is a file sitting at the top level, which is
            // a second root as far as the install is concerned.
            $key           = 1 === count($segments) ? '/' . $segments[0] : $segments[0];
            $roots[ $key ] = true;
        }

        if (1 !== count($roots)) {
            throw new Package_Rejected('The archive must contain exactly one top-level directory and nothing beside it.', 'multiple-roots');
        }

        $root = (string) array_key_first($roots);
        if (! preg_match(self::ROOT_PATTERN, $root)) {
            throw new Package_Rejected('The archive must contain exactly one top-level directory and nothing beside it.', 'multiple-roots');
        }
        return $root;
    }

    /**
     * An entry name split on either slash, refused when absolute or when it
     * climbs out of the extraction directory.
     *
     * @return string[]
     */
    private static function checked_segments(string $name): array
    {
        if ('' === $name || false !== strpos($name, "\0")) {
            throw new Package_Rejected('The archive contains an entry with an invalid name.', 'bad-entry-name');
        }

        $normalized = str_replace('\\', '/', $name);
        if ('/' === $normalized[0] || preg_match('/^[A-Za-z]:/', $normalized)) {
            throw new Package_Rejected(sprintf('The archive contains an absolute path (%s).', esc_html($name)), 'absolute-path');
        }

        $segments = explode('/', rtrim($normalized, '/'));
        if (in_array('..', $segments, true)) {
            throw new Package_Rejected(sprintf('The archive contains a path traversal entry (%s).', esc_html($name)), 'path-traversal');
        }
        return $segments;
    }

    private static function is_symlink(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr  = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }
        return \ZipArchive::OPSYS_UNIX === $opsys && self::S_IFLNK === (($attr >> 16) & self::S_IFMT);
    }

    /** The first PHP file directly under $root carrying a Plugin Name header. */
    private static function plugin_main_file(\ZipArchive $zip, string $root): string
    {
        $prefix = $root . '/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name     = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $relative = substr($name, strlen($prefix));
            if (
                ! str_starts_with($name, $prefix)
                || '' === $relative
                || false !== strpos($relative, '/')
                || '.php' !== strtolower(substr($relative, -4))
            ) {
                continue;
            }
            if (self::has_header((string) $zip->getFromIndex($i, self::HEADER_BYTES), 'Plugin Name')) {
                return $relative;
            }
        }
        throw new Package_Rejected('The archive is not a plugin: no PHP file in its top-level directory has a plugin header (Plugin Name).', 'not-a-plugin');
    }

    private static function require_header(\ZipArchive $zip, string $entry, string $field): void
    {
        $index = $zip->locateName($entry);
        if (false === $index || ! self::has_header((string) $zip->getFromIndex($index, self::HEADER_BYTES), $field)) {
            throw new Package_Rejected('The archive is not a theme: its top-level directory needs a style.css with a Theme Name header.', 'not-a-theme');
        }
    }

    /** The header match core's get_file_data() uses, with a non-empty value. */
    private static function has_header(string $contents, string $field): bool
    {
        $contents = str_replace("\r", "\n", $contents);
        return 1 === preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($field, '/') . ':(.*)$/mi', $contents, $match)
            && '' !== trim($match[1]);
    }
}
