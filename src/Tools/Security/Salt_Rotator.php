<?php

namespace WPMCP\Tools\Security;

use WPMCP\Safety\Core_Files_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Regenerates the eight authentication keys and salts in wp-config.php
 * (incident-response rotate-salts, issue #382).
 *
 * It refuses rather than guesses. Every key must be defined exactly once in
 * wp-config.php as a plain single-quoted literal, and that literal must be the
 * value WordPress is running with; anything else means the salts come from
 * somewhere this edit would not reach (an included file, an environment
 * variable, a second define), and rewriting wp-config.php would log nobody out
 * while claiming it had.
 *
 * The write is atomic: the original is copied to a backup next to it (a .php
 * file under an unguessable name, so a web request executes it rather than
 * serving the database password), the new file is written to a temp file in
 * the same directory, checked, and renamed over wp-config.php. At no point is
 * wp-config.php missing or half written. No key value, old or new, is ever
 * returned or logged.
 */
class Salt_Rotator
{
    public const KEYS = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ];

    public const BACKUP_PREFIX = 'wp-config.wpmcp-backup-';

    private string $config;

    /** @var callable(): string */
    private $keygen;

    public function __construct(?string $config_path = null, ?callable $keygen = null)
    {
        $this->config = $config_path ?? self::locate_config();
        $this->keygen = $keygen ?? static fn (): string => wp_generate_password(64, true, true);
    }

    /** Where WordPress loaded wp-config.php from: ABSPATH, or one level up as wp-load.php allows. */
    public static function locate_config(): string
    {
        if (file_exists(ABSPATH . 'wp-config.php')) {
            return ABSPATH . 'wp-config.php';
        }
        $parent = dirname(ABSPATH);
        if (file_exists($parent . '/wp-config.php') && ! file_exists($parent . '/wp-settings.php')) {
            return $parent . '/wp-config.php';
        }
        return '';
    }

    /**
     * Rotate every key and salt. Throws \RuntimeException with the reason when
     * it refuses; the file is untouched in that case.
     *
     * @return array{ rotated: string[], config: string, backup: string }
     */
    public function rotate(): array
    {
        $config = $this->config;
        if ('' === $config || ! is_file($config) || is_link($config)) {
            throw new \RuntimeException('wp-config.php was not found as a regular file, so the salts cannot be rotated.');
        }

        $fs  = Core_Files_Snapshot::filesystem();
        $dir = dirname($config);
        if (! $fs->is_writable($config)) {
            throw new \RuntimeException('wp-config.php is not writable by the web server, so the salts cannot be rotated. Rotate them by hand.');
        }
        if (! $fs->is_writable($dir)) {
            throw new \RuntimeException('The directory holding wp-config.php is not writable, so it cannot be replaced atomically. Rotate the salts by hand.');
        }

        $source = $fs->get_contents($config);
        if (! is_string($source) || '' === $source) {
            throw new \RuntimeException('wp-config.php could not be read.');
        }

        $spans = self::locate_defines($source);
        $new   = self::replace($source, $spans, $this->fresh_values());

        try {
            token_get_all($new, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException('The rewritten wp-config.php would not parse, so nothing was changed.');
        }

        $mode   = (int) octdec((string) $fs->getchmod($config));
        $suffix = gmdate('Ymd-His') . '-' . wp_generate_password(16, false);
        $backup = $dir . '/' . self::BACKUP_PREFIX . $suffix . '.php';
        if (! $fs->copy($config, $backup, false, $mode) || $fs->get_contents($backup) !== $source) {
            $fs->delete($backup);
            throw new \RuntimeException('wp-config.php could not be backed up, so it was not changed.');
        }

        $tmp = $dir . '/wp-config.wpmcp-' . wp_generate_password(16, false) . '.tmp';
        if (! $fs->put_contents($tmp, $new, $mode) || $fs->get_contents($tmp) !== $new) {
            $fs->delete($tmp);
            throw new \RuntimeException('The new wp-config.php could not be written, so nothing was changed.');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- a same-directory rename is the atomic swap this method exists for; WP_Filesystem::move() deletes wp-config.php before moving, leaving a window in which WordPress serves its installer.
        if (! rename($tmp, $config)) {
            $fs->delete($tmp);
            throw new \RuntimeException('wp-config.php could not be replaced, so nothing was changed.');
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($config, true);
        }

        return [
            'rotated' => self::KEYS,
            'config'  => basename($config),
            'backup'  => basename($backup),
        ];
    }

    /**
     * Offsets of each key's define() statement, after every refusal check.
     *
     * @return array<string, array{0: int, 1: int}> KEY => [offset, length]
     */
    private static function locate_defines(string $source): array
    {
        $spans = [];
        foreach (self::KEYS as $key) {
            $any = preg_match_all('/\bdefine\s*\(\s*([\'"])' . $key . '\1/', $source);
            if (1 !== $any) {
                throw new \RuntimeException(sprintf(
                    '%s is %s in wp-config.php, so it is defined somewhere this edit cannot reach. Nothing was changed.',
                    esc_html($key),
                    0 === $any ? 'not defined' : 'defined more than once'
                ));
            }
            $literal = '/\bdefine\s*\(\s*([\'"])' . $key . '\1\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)\s*;/';
            if (1 !== preg_match($literal, $source, $m, PREG_OFFSET_CAPTURE)) {
                throw new \RuntimeException(sprintf(
                    '%s in wp-config.php is not a plain quoted value, so it cannot be rewritten safely. Nothing was changed.',
                    esc_html($key)
                ));
            }
            $value = strtr($m[2][0], ['\\\\' => '\\', "\\'" => "'"]);
            if (defined($key) && constant($key) !== $value) {
                throw new \RuntimeException(sprintf(
                    'The running value of %s does not come from wp-config.php, so rewriting it there would change nothing. Nothing was changed.',
                    esc_html($key)
                ));
            }
            $spans[ $key ] = [ (int) $m[0][1], strlen($m[0][0]) ];
        }
        return $spans;
    }

    /** @return array<string, string> KEY => fresh value, all distinct. */
    private function fresh_values(): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            do {
                $value = (string) ($this->keygen)();
            } while (strlen($value) < 32 || in_array($value, $values, true));
            $values[ $key ] = $value;
        }
        return $values;
    }

    /**
     * @param array<string, array{0: int, 1: int}> $spans
     * @param array<string, string>               $values
     */
    private static function replace(string $source, array $spans, array $values): string
    {
        uasort($spans, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($spans as $key => [$offset, $length]) {
            $line   = sprintf("define( '%s', '%s' );", $key, addcslashes($values[ $key ], "'\\"));
            $source = substr_replace($source, $line, $offset, $length);
        }
        return $source;
    }
}
