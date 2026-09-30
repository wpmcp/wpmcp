<?php

namespace WPMCP\Tools\Filesystem;

use WPMCP\Auth\Atomic_Option;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Safe PHP writes for edit-file and write-file (issue #453).
 *
 * One bad save to an active theme's functions.php takes the whole site down,
 * the MCP endpoint with it, so the agent that broke it cannot call restore.
 * For a .php target this class therefore:
 *
 *  1. refuses wpmcp's own files, since breaking them removes the endpoint;
 *  2. parses the new content with token_get_all(TOKEN_PARSE) and refuses a
 *     file that does not parse, with the parser's message and line, before
 *     anything is written (no process call: the check runs in-process);
 *  3. takes a site-wide lock, so only one PHP edit runs at a time;
 *  4. checks the site before and after the save with core's scrape-key
 *     loopback, the technique the theme and plugin file editor uses
 *     (wp_start_scraping_edited_file_errors() in wp-settings.php reports the
 *     last fatal of a request carrying a matching key and nonce), on the
 *     front end and in admin;
 *  5. restores the backup (or removes a new file) in the same call when the
 *     site fatals, or stops answering the check, after the save.
 *
 * When the check cannot run before the save (loopbacks blocked, or the site
 * already fatals), the write is refused unless the caller passes
 * unchecked:true, and the response then says the file was not checked. A
 * file inside an inactive plugin or theme is not loaded, so it gets the
 * syntax check only.
 */
class Php_Edit_Guard
{
    public const LOCK_OPTION = 'wpmcp_php_edit_lock';
    public const LOCK_TTL    = 300;
    public const TIMEOUT     = 30;

    private static ?string $lock_token = null;

    /** Whether $abs gets the PHP checks. */
    public static function applies(string $abs): bool
    {
        return 'php' === strtolower((string) pathinfo($abs, PATHINFO_EXTENSION));
    }

    /**
     * Parse $code without running it.
     *
     * @return array{message: string, line: int}|null null when it parses.
     */
    public static function lint(string $code): ?array
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\CompileError $e) {
            return ['message' => $e->getMessage(), 'line' => $e->getLine()];
        }
        return null;
    }

    /** Whether $abs is inside wpmcp's own plugin directory. */
    public static function is_own_file(string $abs): bool
    {
        $own_raw = rtrim(WPMCP_DIR, '/\\') . DIRECTORY_SEPARATOR;
        $own     = realpath(WPMCP_DIR);
        $own     = false === $own ? $own_raw : rtrim($own, '/\\') . DIRECTORY_SEPARATOR;

        $path = realpath($abs);
        if (false === $path) {
            $dir  = realpath(dirname($abs));
            $path = false === $dir ? $abs : rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . basename($abs);
        }

        return 0 === strpos($path, $own) || 0 === strpos($abs, $own_raw);
    }

    /**
     * Whether $abs can be loaded by a request: false only for a file inside
     * an inactive plugin or theme directory on a single site. Everything
     * else (active code, mu-plugins, drop-ins, core, anything unknown) is
     * treated as loaded and checked.
     */
    public static function is_loaded(string $abs): bool
    {
        if (is_multisite()) {
            return true;
        }

        $plugin_rel = self::relative_to($abs, WP_PLUGIN_DIR);
        if (null !== $plugin_rel) {
            $active = array_map('strval', (array) get_option('active_plugins', []));
            if (false === strpos($plugin_rel, '/')) {
                return in_array($plugin_rel, $active, true);
            }
            $slug = strtok($plugin_rel, '/') . '/';
            foreach ($active as $plugin) {
                if (0 === strpos($plugin, $slug)) {
                    return true;
                }
            }
            return false;
        }

        $theme_rel = self::relative_to($abs, get_theme_root());
        if (null !== $theme_rel && false !== strpos($theme_rel, '/')) {
            $slug = (string) strtok($theme_rel, '/');
            return in_array($slug, [get_stylesheet(), get_template()], true);
        }

        return true;
    }

    /** Take the one-PHP-edit-at-a-time lock. Returns false while another edit holds it. */
    public static function acquire_lock(): bool
    {
        $token = wp_generate_password(20, false);
        $won   = Atomic_Option::mutate(
            self::LOCK_OPTION,
            static function (array $current) use ($token): array {
                if (! empty($current['token']) && (int) ($current['until'] ?? 0) > time()) {
                    return [null, false];
                }
                return [['token' => $token, 'until' => time() + self::LOCK_TTL], true];
            },
            false
        );
        if (true === $won) {
            self::$lock_token = $token;
        }
        return true === $won;
    }

    /** Release the lock this process holds, if any. */
    public static function release_lock(): void
    {
        $token = self::$lock_token;
        if (null === $token) {
            return;
        }
        Atomic_Option::mutate(
            self::LOCK_OPTION,
            static fn (array $current): array => ($current['token'] ?? null) === $token ? [[], true] : [null, false],
            false
        );
        self::$lock_token = null;
    }

    /**
     * Write PHP $content to $abs with every check above. Throws a
     * RuntimeException, with the file left as it was, on any refusal or
     * rollback.
     *
     * @return array{bytes: int, backup: string, php_check: string}
     */
    public static function write(string $abs, string $content, bool $unchecked): array
    {
        if (self::is_own_file($abs)) {
            throw new \RuntimeException('wpmcp does not edit its own PHP files through this tool; that could take the MCP endpoint down.');
        }

        $parse = self::lint($content);
        if (null !== $parse) {
            throw new \RuntimeException(esc_html(sprintf(
                'PHP syntax error on line %d: %s. Nothing was written.',
                $parse['line'],
                $parse['message']
            )));
        }

        if (! self::acquire_lock()) {
            throw new \RuntimeException('Another PHP file edit is in progress on this site; retry when it finishes.');
        }

        try {
            return self::write_locked($abs, $content, $unchecked);
        } finally {
            self::release_lock();
        }
    }

    /** @return array{bytes: int, backup: string, php_check: string} */
    private static function write_locked(string $abs, string $content, bool $unchecked): array
    {
        $check = self::is_loaded($abs) ? 'loopback' : 'syntax only (inactive plugin or theme, not loaded)';

        if ('loopback' === $check) {
            $before = self::probe();
            if ('ok' !== $before['state']) {
                if (! $unchecked) {
                    throw new \RuntimeException(esc_html(sprintf(
                        'The site check for PHP edits cannot run (%s), so nothing was written. Pass unchecked:true to write without it; the backup still allows restore.',
                        $before['detail']
                    )));
                }
                $check = 'unchecked: ' . $before['detail'];
            }
        }

        $backup = Filesystem_Guard::backup($abs);
        if (is_wp_error($backup)) {
            throw new \RuntimeException(esc_html($backup->get_error_message()));
        }
        if (! wp_mkdir_p(dirname($abs))) {
            throw new \RuntimeException('Could not create the parent directory.');
        }
        $bytes = file_put_contents($abs, $content);
        if (false === $bytes) {
            throw new \RuntimeException('Could not write the file (check permissions).');
        }
        self::invalidate($abs);

        if ('loopback' !== $check) {
            return ['bytes' => (int) $bytes, 'backup' => $backup, 'php_check' => $check];
        }

        $after = self::probe();
        if ('ok' === $after['state']) {
            return ['bytes' => (int) $bytes, 'backup' => $backup, 'php_check' => 'passed'];
        }

        if ('' === $backup) {
            wp_delete_file($abs);
            $undone = ! file_exists($abs);
            $what   = 'the new file was removed';
        } else {
            $undone = Filesystem_Guard::restore($backup, $abs);
            $what   = 'the previous version was restored';
        }
        self::invalidate($abs);
        Filesystem_Guard::log('revert', Filesystem_Guard::to_relative($abs));

        if (! $undone) {
            throw new \RuntimeException(esc_html(sprintf(
                'The edit broke the site (%s) and the automatic revert failed. Restore it by hand now%s.',
                $after['detail'],
                '' === $backup ? ' by deleting ' . Filesystem_Guard::to_relative($abs) : ' from ' . Filesystem_Guard::to_relative($backup)
            )));
        }
        throw new \RuntimeException(esc_html(sprintf(
            'The edit broke the site (%s), so %s and nothing changed.',
            $after['detail'],
            $what
        )));
    }

    /**
     * Request the front end, then admin, with a scrape key and nonce, and
     * read core's verdict from the response.
     *
     * @return array{state: string, detail: string} state is ok, fatal or unreachable.
     */
    public static function probe(): array
    {
        $key   = strtolower(wp_generate_password(32, false));
        $nonce = (string) wp_rand();
        set_transient('scrape_key_' . $key, $nonce, 5 * MINUTE_IN_SECONDS);
        $start = "###### wp_scraping_result_start:$key ######";
        $end   = "###### wp_scraping_result_end:$key ######";
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying core's loopback SSL filter, as core's file editor and Site Health do for this same request, not defining one.
        $sslverify = (bool) apply_filters('https_local_ssl_verify', false);

        try {
            foreach (['front end', 'admin'] as $where) {
                $params   = ['wp_scrape_key' => $key, 'wp_scrape_nonce' => $nonce];
                $url      = 'admin' === $where ? admin_url() : home_url('/');
                $response = wp_remote_get(add_query_arg($params, $url), [
                    'timeout'   => self::TIMEOUT,
                    'headers'   => ['Cache-Control' => 'no-cache'],
                    'sslverify' => $sslverify,
                ]);

                if (is_wp_error($response)) {
                    return ['state' => 'unreachable', 'detail' => $where . ': ' . $response->get_error_message()];
                }

                $status = (int) wp_remote_retrieve_response_code($response);
                $body   = (string) wp_remote_retrieve_body($response);
                $at     = strpos($body, $start);
                if (false === $at) {
                    return ['state' => 'unreachable', 'detail' => sprintf('%s: HTTP %d without the check result', $where, $status)];
                }
                $json   = substr($body, $at + strlen($start));
                $stop   = strpos($json, $end);
                $result = json_decode(trim(false === $stop ? $json : substr($json, 0, $stop)), true);

                if (true === $result) {
                    continue;
                }
                if (is_array($result) && isset($result['message']) && ! isset($result['code'])) {
                    return ['state' => 'fatal', 'detail' => sprintf(
                        '%s: HTTP %d, PHP fatal error: %s in %s on line %d',
                        $where,
                        $status,
                        (string) $result['message'],
                        (string) ($result['file'] ?? '?'),
                        (int) ($result['line'] ?? 0)
                    )];
                }
                $why = is_array($result) && isset($result['message']) ? (string) $result['message'] : 'unreadable check result';
                return ['state' => 'unreachable', 'detail' => sprintf('%s: HTTP %d, %s', $where, $status, $why)];
            }
        } finally {
            delete_transient('scrape_key_' . $key);
        }

        return ['state' => 'ok', 'detail' => ''];
    }

    private static function invalidate(string $abs): void
    {
        if (! function_exists('wp_opcache_invalidate')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        wp_opcache_invalidate($abs, true);
    }

    private static function relative_to(string $abs, string $dir): ?string
    {
        foreach (array_unique([rtrim($dir, '/\\'), rtrim((string) realpath($dir), '/\\')]) as $root) {
            if ('' !== $root && 0 === strpos($abs, $root . DIRECTORY_SEPARATOR)) {
                return str_replace('\\', '/', substr($abs, strlen($root) + 1));
            }
        }
        return null;
    }
}
