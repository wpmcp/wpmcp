<?php

namespace WPMCP\Tools\Security;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Signs of an existing compromise (issue #845): the traces attackers leave
 * behind rather than known malware strings or weak settings.
 *
 * - Back-dated files: a PHP file whose ctime is newer than its siblings' while
 *   its mtime claims it is old (a "touch" to hide a planted file).
 * - Look-alike plugin and theme folders, and look-alike PHP files in the
 *   WordPress root.
 * - Must-use plugins without a plugin header, and unknown PHP files in the
 *   wp-content root (drop-in slots), reported with their SHA-256.
 * - Cron hooks with no callback, or a callback living in uploads, the
 *   wp-content root or a temp folder.
 * - Database dumps, config backups and archives in public folders, checked
 *   with a loopback HEAD request.
 * - Pages that differ for a search crawler, or redirect visitors who arrive
 *   from a search engine (loopback GETs to this site only).
 * - Administrators created recently or with a generic or random login.
 *
 * Read-only. Files are only stat()ed, hashed or have their header read; they
 * are never executed or included. WP MCP's own backups (#604) are skipped.
 * Logins are masked (src/Privacy/Masking.php). Bounded by max_files and
 * max_seconds; a truncated run says so.
 */
class Compromise_Audit
{
    public const CATEGORY          = 'compromise';
    public const DAY               = 86400;
    public const MIN_SIBLINGS      = 3;
    public const RECENT_ADMIN_DAYS = 7;
    public const MAX_LISTED        = 20;
    public const MAX_HEAD_REQUESTS = 10;
    public const MAX_PAGES         = 3;
    public const FETCH_TIMEOUT     = 8;
    public const MAX_BODY_BYTES    = 1048576;
    public const MAX_ADMINS        = 500;
    public const SIZE_GAP_RATIO    = 0.3;
    public const SIZE_GAP_BYTES    = 2048;
    public const SCANNER_UA        = 'WPMCP-Security-Scanner/1.0';
    public const BROWSER_UA        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    public const CRAWLER_UA        = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    public const SEARCH_REFERER    = 'https://www.google.com/';

    private const PHP_EXT = '/\.(php|phtml|php3|php4|php5|php7|phps|pht)$/i';

    /** PHP files a WordPress release ships in its root folder. */
    private const CORE_ROOT_FILES = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
        'wp-config-sample.php', 'wp-config.php', 'wp-cron.php', 'wp-links-opml.php',
        'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
        'wp-trackback.php', 'xmlrpc.php',
    ];

    /** Drop-in file names WordPress itself loads from wp-content. */
    private const DROPINS = [
        'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php',
        'object-cache.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php',
        'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php', 'index.php',
    ];

    /** Core cron hooks whose callbacks are attached lazily outside a normal request. */
    private const CORE_CRON_HOOKS = [
        'wp_version_check', 'wp_update_plugins', 'wp_update_themes', 'wp_maybe_auto_update',
        'wp_scheduled_delete', 'wp_scheduled_auto_draft_delete', 'delete_expired_transients',
        'wp_privacy_delete_old_export_files', 'recovery_mode_clean_expired_keys',
        'wp_site_health_scheduled_check', 'wp_https_detection', 'wp_update_user_counts',
        'wp_delete_temp_updater_backups', 'importer_scheduled_cleanup', 'upgrader_scheduled_cleanup',
        'wp_split_shared_term_batch', 'do_pings', 'publish_future_post', 'wp_batch_split_terms',
    ];

    /** Login names often given to administrators an attacker adds. "admin" is the hardening audit's. */
    private const GENERIC_LOGINS = [
        'administrator', 'adminbackup', 'backupadmin', 'backup', 'root', 'support', 'wpsupport',
        'wp-support', 'wp_support', 'sysadmin', 'system', 'wordpress', 'wpadmin', 'wp-admin',
        'wp_admin', 'webadmin', 'siteadmin', 'wpupdate', 'wp-update', 'wpcore', 'wp-core',
        'test', 'testuser', 'temp', 'tmpadmin', 'adm1n', 'admln',
    ];

    private int $deadline  = 0;
    private int $max_files = 0;
    private int $checked   = 0;
    private int $requests  = 0;
    private ?string $truncated = null;

    /**
     * @return array{ findings: array, stats: array{ files_checked:int, truncated:bool, truncated_reason:?string, requests:int } }
     */
    public function run(int $max_files, int $max_seconds): array
    {
        $this->deadline  = time() + max(1, $max_seconds);
        $this->max_files = max(1, $max_files);
        $this->checked   = 0;
        $this->requests  = 0;
        $this->truncated = null;

        $findings = array_merge(
            $this->check_admins(),
            $this->check_cron(),
            $this->check_mu_plugins(),
            $this->check_dropins(),
            $this->check_lookalikes(),
            $this->check_dumps(),
            $this->check_backdated(),
            $this->check_cloaking()
        );

        return [
            'findings' => $findings,
            'stats'    => [
                'files_checked'    => $this->checked,
                'truncated'        => null !== $this->truncated,
                'truncated_reason' => $this->truncated,
                'requests'         => $this->requests,
            ],
        ];
    }

    // Pure helpers.

    /**
     * Pure: was this file planted later than its siblings and then given an
     * old modification time? A zip install keeps old mtimes but gives every
     * sibling the same ctime, and an in-place edit moves mtime with ctime.
     */
    public function is_backdated(int $mtime, int $ctime, int $sibling_median_ctime): bool
    {
        return ($ctime - $mtime) > self::DAY && ($ctime - $sibling_median_ctime) > self::DAY;
    }

    /** @param int[] $values */
    public function median(array $values): int
    {
        sort($values);
        $count = count($values);
        if (0 === $count) {
            return 0;
        }
        $mid = intdiv($count, 2);
        return 0 === $count % 2 ? intdiv($values[ $mid - 1 ] + $values[ $mid ], 2) : $values[ $mid ];
    }

    /**
     * Pure: pairs of names that differ by one or two characters once case,
     * hyphens and underscores are ignored (woocomerce next to woocommerce,
     * wprocket next to wp-rocket). Add-ons such as elementor-pro differ by
     * more and are not paired. Each pair is sorted.
     *
     * @param string[] $names
     * @return array<int, array{0:string, 1:string}>
     */
    public function lookalike_pairs(array $names): array
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        $pairs = [];
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->is_lookalike($names[ $i ], $names[ $j ])) {
                    $pair = [$names[ $i ], $names[ $j ]];
                    sort($pair);
                    $pairs[] = $pair;
                }
            }
        }
        return $pairs;
    }

    public function is_lookalike(string $a, string $b): bool
    {
        if ($a === $b) {
            return false;
        }
        $na = (string) preg_replace('/[-_.\s]/', '', strtolower($a));
        $nb = (string) preg_replace('/[-_.\s]/', '', strtolower($b));
        if ($na === $nb) {
            return true;
        }
        if (min(strlen($na), strlen($nb)) < 5 || strlen($na) > 255 || strlen($nb) > 255) {
            return false;
        }
        return levenshtein($na, $nb) <= 2;
    }

    /** Pure: why an administrator's login looks unusual, or null. */
    public function unusual_login_reason(string $login): ?string
    {
        $login = strtolower(trim($login));
        if (in_array($login, self::GENERIC_LOGINS, true)) {
            return 'generic_name';
        }
        if (1 === preg_match('/^[a-f0-9]{8,}$/', $login) && 1 === preg_match('/\d/', $login) && 1 === preg_match('/[a-f]/', $login)) {
            return 'random_looking';
        }
        return null;
    }

    /**
     * Pure: is this a file a visitor should never be able to download?
     * Database dumps and wp-config copies anywhere; archives and .bak/.old
     * copies only in the WordPress or wp-content root ($top_level), since
     * uploads legitimately hold zip downloads.
     */
    public function is_exposed_name(string $basename, bool $top_level): bool
    {
        $name = strtolower($basename);
        if (1 === preg_match('/\.(sql|sql\.gz|sql\.zip|sql\.bz2|sql\.xz|mysql|dump)$/', $name)) {
            return true;
        }
        if (1 === preg_match('/^\.?wp-config/', $name) && 1 !== preg_match(self::PHP_EXT, $name)) {
            return true;
        }
        return $top_level && 1 === preg_match('/\.(zip|tar|tar\.gz|tgz|gz|bz2|7z|rar|bak|old|backup|wpress)$/', $name);
    }

    /**
     * Pure: external link hosts of a page (www. dropped, lowercase).
     *
     * @return string[]
     */
    public function link_hosts(string $html): array
    {
        $hosts = [];
        if (preg_match_all('/<a\s[^>]*href\s*=\s*["\']?(?:https?:)?\/\/([^\/"\'\s>:?#]+)/i', $html, $m)) {
            foreach ($m[1] as $host) {
                $hosts[] = $this->bare_host((string) $host);
            }
        }
        $hosts = array_values(array_unique($hosts));
        sort($hosts);
        return $hosts;
    }

    /**
     * Pure: how the crawler and search-referred responses differ from the
     * plain browser response. Empty when they match.
     *
     * @param array{code:int, location:string, body:string}|null $browser
     * @param array{code:int, location:string, body:string}|null $crawler
     * @param array{code:int, location:string, body:string}|null $referred
     * @return array<string, mixed>
     */
    public function compare_fetches(?array $browser, ?array $crawler, ?array $referred, string $own_host): array
    {
        $diff = [];
        if (null === $browser) {
            return $diff;
        }
        $own = $this->bare_host($own_host);
        $browser_redirect = $this->redirect_host($browser);

        if (null !== $crawler) {
            $extra = array_values(array_diff(
                $this->link_hosts($crawler['body']),
                $this->link_hosts($browser['body']),
                [$own]
            ));
            if ([] !== $extra) {
                $diff['crawler_only_links'] = array_slice($extra, 0, self::MAX_LISTED);
            }
            $host = $this->redirect_host($crawler);
            if (null !== $host && $host !== $own && $host !== $browser_redirect) {
                $diff['crawler_redirect'] = $host;
            }
            $a   = strlen($browser['body']);
            $b   = strlen($crawler['body']);
            $gap = abs($a - $b);
            if ($gap >= self::SIZE_GAP_BYTES && $gap >= self::SIZE_GAP_RATIO * max($a, $b)) {
                $diff['size_gap'] = ['browser_bytes' => $a, 'crawler_bytes' => $b];
            }
        }
        if (null !== $referred) {
            $host = $this->redirect_host($referred);
            if (null !== $host && $host !== $own && $host !== $browser_redirect) {
                $diff['referer_redirect'] = $host;
            }
        }
        return $diff;
    }

    // Checks.

    private function check_admins(): array
    {
        if (! function_exists('get_users')) {
            return [];
        }
        $users = get_users([
            'role'    => 'administrator',
            'number'  => self::MAX_ADMINS,
            'orderby' => 'registered',
            'order'   => 'ASC',
            'fields'  => ['ID', 'user_login', 'user_registered'],
        ]);
        if (is_multisite() && function_exists('get_super_admins')) {
            foreach (get_super_admins() as $login) {
                $user = get_user_by('login', $login);
                if ($user) {
                    $users[] = (object) ['ID' => $user->ID, 'user_login' => $user->user_login, 'user_registered' => $user->user_registered];
                }
            }
        }

        $seen  = [];
        $times = [];
        foreach ($users as $user) {
            $id = (int) $user->ID;
            if (isset($seen[ $id ])) {
                continue;
            }
            $seen[ $id ]  = $user;
            $times[ $id ] = (int) strtotime((string) $user->user_registered . ' UTC');
        }
        if ([] === $seen) {
            return [];
        }
        $first   = min($times);
        $now     = time();
        $flagged = [];
        foreach ($seen as $id => $user) {
            $reasons = [];
            $reason  = $this->unusual_login_reason((string) $user->user_login);
            if (null !== $reason) {
                $reasons[] = $reason;
            }
            $registered = $times[ $id ];
            if ($registered >= $now - self::RECENT_ADMIN_DAYS * self::DAY && $registered - $first > self::DAY) {
                $reasons[] = 'created_recently';
            }
            if ([] === $reasons) {
                continue;
            }
            $flagged[] = [
                'user_id'    => (int) $id,
                'login'      => Masking::value((string) $user->user_login),
                'registered' => (string) $user->user_registered,
                'reasons'    => $reasons,
            ];
        }

        if ([] === $flagged) {
            return [$this->pass('compromise_suspicious_admins', 'Administrator accounts', 'No administrator was created recently or has a generic or random login.')];
        }
        return [Security_Finding::make(
            'compromise_suspicious_admins',
            self::CATEGORY,
            'Administrator accounts to review',
            'warning',
            [
                'count'     => count($flagged),
                'users'     => array_slice($flagged, 0, self::MAX_LISTED),
                'masked'    => true,
                'next_step' => 'wpmcp/get-user',
            ],
            'These administrators were created in the last ' . self::RECENT_ADMIN_DAYS . ' days (after the first administrator) or have a login attackers often use. Logins are masked.',
            'Check each with get-user. If nobody on your team created it, remove its administrator role with update-user and end its sessions with incident-response end-sessions.'
        )];
    }

    private function check_cron(): array
    {
        if (! function_exists('_get_cron_array')) {
            return [];
        }
        $hooks = [];
        foreach ((array) _get_cron_array() as $events) {
            foreach (array_keys((array) $events) as $hook) {
                $hooks[ (string) $hook ] = true;
            }
        }

        $orphans  = [];
        $misplace = [];
        foreach (array_keys($hooks) as $hook) {
            if (! has_action($hook)) {
                if (! in_array($hook, self::CORE_CRON_HOOKS, true)) {
                    $orphans[] = $hook;
                }
                continue;
            }
            foreach ($this->hook_callbacks($hook) as $callback) {
                $file  = $this->callback_file($callback);
                $where = null === $file ? null : $this->unexpected_location($file);
                if (null !== $where) {
                    $misplace[] = ['hook' => $hook, 'file' => $this->relative($file), 'where' => $where];
                }
            }
        }

        $findings = [];
        if ([] === $orphans) {
            $findings[] = $this->pass('compromise_orphan_cron', 'Cron hooks without a callback', 'Every scheduled cron hook has a registered callback.');
        } else {
            $findings[] = Security_Finding::make(
                'compromise_orphan_cron',
                self::CATEGORY,
                'Cron hooks without a callback',
                'warning',
                ['count' => count($orphans), 'hooks' => array_slice($orphans, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/unschedule-event'],
                'These scheduled cron hooks have no callback in this request. Often a removed plugin left them behind, but malware also schedules hooks that only its own code answers.',
                'Look at each with list-cron-events. If no installed plugin owns it, remove it with unschedule-event.'
            );
        }
        if ([] === $misplace) {
            $findings[] = $this->pass('compromise_cron_callback_location', 'Cron callback locations', 'No cron callback lives in uploads, the wp-content root or a temp folder.');
        } else {
            $findings[] = Security_Finding::make(
                'compromise_cron_callback_location',
                self::CATEGORY,
                'Cron callback in an unexpected file',
                'critical',
                ['count' => count($misplace), 'callbacks' => array_slice($misplace, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/read-file'],
                'A scheduled cron hook runs code from a file in uploads, the wp-content root or a temp folder, where no plugin or theme code belongs.',
                'Read the file with read-file. If it is not yours, remove it with delete-file (undoable), remove the hook with unschedule-event, and rotate credentials.'
            );
        }
        return $findings;
    }

    private function check_mu_plugins(): array
    {
        $dir = defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : '';
        if ('' === $dir || ! is_dir($dir)) {
            return [$this->pass('compromise_unexpected_mu_plugin', 'Must-use plugins', 'There is no must-use plugins folder.')];
        }
        $findings = [];
        $known    = [];
        foreach ($this->list_dir($dir) as $path) {
            $name = basename($path);
            if (! is_file($path) || 1 !== preg_match(self::PHP_EXT, $name)) {
                continue;
            }
            $entry   = $this->file_evidence($path);
            $header  = $this->has_plugin_header($path);
            $hidden  = 0 === strpos($name, '.');
            $wpmcp   = 0 === strpos($name, 'wpmcp');
            if ($header && ! $hidden) {
                $known[] = $entry;
                continue;
            }
            if ($wpmcp && $header) {
                continue;
            }
            $findings[] = Security_Finding::make(
                'compromise_unexpected_mu_plugin',
                self::CATEGORY,
                'Must-use plugin without a plugin header',
                'warning',
                $entry + ['hidden' => $hidden, 'next_step' => 'wpmcp/read-file'],
                'A must-use plugin runs on every request and cannot be switched off in the dashboard. This one has no Plugin Name header' . ($hidden ? ' and a hidden name' : '') . ', which is common for planted loaders.',
                'Read it with read-file. If your host or a plugin you trust did not put it there, remove it with delete-file (undoable).'
            );
        }
        if ([] === $findings) {
            $findings[] = $this->pass('compromise_unexpected_mu_plugin', 'Must-use plugins', 'Every must-use plugin has a plugin header.');
        }
        if ([] !== $known) {
            $findings[] = Security_Finding::make(
                'compromise_mu_plugins',
                self::CATEGORY,
                'Must-use plugins',
                'info',
                ['count' => count($known), 'files' => array_slice($known, 0, self::MAX_LISTED)],
                'The must-use plugins on this site, with their SHA-256, for review.',
                'Make sure you recognise each one.'
            );
        }
        return $findings;
    }

    private function check_dropins(): array
    {
        $content  = (string) WP_CONTENT_DIR;
        $known    = [];
        $unknown  = [];
        foreach ($this->list_dir($content) as $path) {
            $name = basename($path);
            if (! is_file($path) || 1 !== preg_match(self::PHP_EXT, $name)) {
                continue;
            }
            $entry = $this->file_evidence($path);
            if (in_array($name, self::DROPINS, true)) {
                if ('index.php' !== $name) {
                    $known[] = $entry;
                }
                continue;
            }
            $unknown[] = $entry;
        }
        $findings = [];
        if ([] === $unknown) {
            $findings[] = $this->pass('compromise_unknown_content_php', 'PHP in the wp-content root', 'The wp-content root holds only known drop-ins.');
        } else {
            $findings[] = Security_Finding::make(
                'compromise_unknown_content_php',
                self::CATEGORY,
                'Unknown PHP file in the wp-content root',
                'warning',
                ['count' => count($unknown), 'files' => array_slice($unknown, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/read-file'],
                'PHP files in the wp-content root that are not WordPress drop-ins. Plugins and themes keep their code in their own folders.',
                'Read each with read-file and remove any you do not recognise with delete-file (undoable).'
            );
        }
        if ([] !== $known) {
            $findings[] = Security_Finding::make(
                'compromise_dropins',
                self::CATEGORY,
                'Drop-ins',
                'info',
                ['count' => count($known), 'files' => $known],
                'Drop-ins replace parts of WordPress itself. They are listed with their SHA-256 for review.',
                'Make sure each belongs to a caching, database or other plugin you use.'
            );
        }
        return $findings;
    }

    private function check_lookalikes(): array
    {
        $pairs = [];
        $roots = ['plugins' => (string) WP_PLUGIN_DIR];
        if (function_exists('get_theme_root')) {
            $roots['themes'] = (string) get_theme_root();
        }
        foreach ($roots as $kind => $root) {
            $names = [];
            foreach ($this->list_dir($root) as $path) {
                if (is_dir($path)) {
                    $names[] = basename($path);
                }
            }
            foreach ($this->lookalike_pairs($names) as $pair) {
                $pairs[] = ['kind' => $kind, 'names' => $pair];
            }
        }

        $root_files = [];
        foreach ($this->list_dir((string) ABSPATH) as $path) {
            $name = basename($path);
            if (! is_file($path) || 1 !== preg_match(self::PHP_EXT, $name) || in_array($name, self::CORE_ROOT_FILES, true)) {
                continue;
            }
            foreach (self::CORE_ROOT_FILES as $core) {
                if ($this->is_lookalike($name, $core)) {
                    $root_files[] = $this->file_evidence($path) + ['looks_like' => $core];
                    break;
                }
            }
        }

        $findings = [];
        if ([] === $pairs) {
            $findings[] = $this->pass('compromise_lookalike_folders', 'Look-alike plugin and theme folders', 'No plugin or theme folder name is a near copy of another.');
        } else {
            $findings[] = Security_Finding::make(
                'compromise_lookalike_folders',
                self::CATEGORY,
                'Look-alike plugin or theme folders',
                'warning',
                ['count' => count($pairs), 'pairs' => array_slice($pairs, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/search-files'],
                'Folder names that differ by one or two characters. Attackers hide code in a folder named almost like a real plugin.',
                'Check which folder is the real one (list-plugins shows what WordPress knows) and inspect the other with search-files and read-file.'
            );
        }
        if ([] === $root_files) {
            $findings[] = $this->pass('compromise_lookalike_root_files', 'Look-alike core files', 'No PHP file in the WordPress root is named like a core file.');
        } else {
            $findings[] = Security_Finding::make(
                'compromise_lookalike_root_files',
                self::CATEGORY,
                'PHP file named like a core file',
                'critical',
                ['count' => count($root_files), 'files' => array_slice($root_files, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/read-file'],
                'A PHP file in the WordPress root is named almost like a core file (for example wp-l0gin.php). WordPress ships no such file.',
                'Read it with read-file and remove it with delete-file (undoable) unless you know what it is.'
            );
        }
        return $findings;
    }

    private function check_dumps(): array
    {
        $candidates = [];
        foreach ([(string) ABSPATH, (string) WP_CONTENT_DIR] as $root) {
            foreach ($this->list_dir($root) as $path) {
                if (is_file($path) && $this->is_exposed_name(basename($path), true)) {
                    $candidates[ $this->real($path) ] = true;
                }
            }
        }
        $uploads = function_exists('wp_upload_dir') ? (string) (wp_upload_dir(null, false)['basedir'] ?? '') : '';
        if ('' !== $uploads && is_dir($uploads)) {
            $abspath = $this->real((string) ABSPATH);
            $backups = (new Malware_Audit())->own_backup_dirs();
            foreach ($this->walk($uploads) as $path) {
                $rel = ltrim(substr($path, strlen($abspath)), '/');
                if ($this->in_dirs($rel, $backups)) {
                    continue; // WP MCP's own backups (#604).
                }
                if ($this->is_exposed_name(basename($path), false)) {
                    $candidates[ $path ] = true;
                }
            }
        }

        $open      = [];
        $blocked   = [];
        $unchecked = [];
        foreach (array_keys($candidates) as $path) {
            $url   = $this->public_url($path);
            $entry = ['location' => $this->relative($path), 'url' => (string) $url, 'bytes' => (int) @filesize($path)];
            if (null === $url || $this->requests >= self::MAX_HEAD_REQUESTS || time() >= $this->deadline) {
                $unchecked[] = $entry;
                continue;
            }
            $this->requests++;
            $response = wp_safe_remote_head($url, [
                'timeout'     => 5,
                'redirection' => 0,
                'user-agent'  => self::SCANNER_UA,
            ]);
            if (is_wp_error($response)) {
                $unchecked[] = $entry;
                continue;
            }
            $code          = (int) wp_remote_retrieve_response_code($response);
            $entry['http'] = $code;
            if (200 === $code || 206 === $code) {
                $open[] = $entry;
            } else {
                $blocked[] = $entry;
            }
        }

        $findings = [];
        if ([] !== $open) {
            $findings[] = Security_Finding::make(
                'compromise_public_dump',
                self::CATEGORY,
                'Downloadable backup or dump',
                'critical',
                ['count' => count($open), 'files' => array_slice($open, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/delete-file'],
                'A database dump, wp-config copy or site archive in a public folder answers with HTTP 200, so anyone who guesses the name can download it.',
                'Move it out of the web root or remove it with delete-file (undoable), then rotate the database password and salts it exposes.'
            );
        }
        if ([] !== $unchecked) {
            $findings[] = Security_Finding::make(
                'compromise_dump_unchecked',
                self::CATEGORY,
                'Backup or dump in a public folder (not checked)',
                'warning',
                ['count' => count($unchecked), 'files' => array_slice($unchecked, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/delete-file'],
                'A database dump, wp-config copy or archive sits in a public folder, and the loopback check could not tell whether it downloads.',
                'Move it out of the web root or remove it with delete-file (undoable).'
            );
        }
        if ([] !== $blocked) {
            $findings[] = Security_Finding::make(
                'compromise_dump_present',
                self::CATEGORY,
                'Backup or dump in a public folder (blocked)',
                'info',
                ['count' => count($blocked), 'files' => array_slice($blocked, 0, self::MAX_LISTED)],
                'These files sit in a public folder but the server refused to serve them.',
                'Keep them only if a backup tool you use put them there.'
            );
        }
        if ([] === $findings) {
            $findings[] = $this->pass('compromise_public_dump', 'Backups and dumps in public folders', 'No database dump, wp-config copy or site archive was found in a public folder.');
        }
        return $findings;
    }

    private function check_backdated(): array
    {
        $roots = [(string) WP_PLUGIN_DIR];
        if (defined('WPMU_PLUGIN_DIR')) {
            $roots[] = (string) WPMU_PLUGIN_DIR;
        }
        if (function_exists('get_theme_root')) {
            $roots[] = (string) get_theme_root();
        }

        /** @var array<string, array<string, array{0:int, 1:int}>> $by_dir */
        $by_dir = [];
        foreach ($this->list_dir((string) ABSPATH) as $path) {
            if (is_file($path) && 1 === preg_match(self::PHP_EXT, $path)) {
                $by_dir[ dirname($path) ][ $path ] = $this->file_times($path);
            }
        }
        foreach (array_unique($roots) as $root) {
            foreach ($this->walk($root) as $path) {
                if (1 === preg_match(self::PHP_EXT, $path)) {
                    $by_dir[ dirname($path) ][ $path ] = $this->file_times($path);
                }
            }
        }

        $flagged = [];
        foreach ($by_dir as $files) {
            if (count($files) < self::MIN_SIBLINGS + 1) {
                continue;
            }
            foreach ($files as $path => $times) {
                $siblings = [];
                foreach ($files as $other => $other_times) {
                    if ($other !== $path) {
                        $siblings[] = $other_times[1];
                    }
                }
                if ($this->is_backdated($times[0], $times[1], $this->median($siblings))) {
                    $flagged[] = [
                        'location' => $this->relative($path),
                        'mtime'    => gmdate('Y-m-d H:i:s', $times[0]),
                        'ctime'    => gmdate('Y-m-d H:i:s', $times[1]),
                    ];
                }
            }
        }

        if ([] === $flagged) {
            return [$this->pass('compromise_backdated_files', 'Back-dated files', 'No PHP file looks back-dated against its neighbours.')];
        }
        return [Security_Finding::make(
            'compromise_backdated_files',
            self::CATEGORY,
            'Back-dated PHP files',
            'warning',
            ['count' => count($flagged), 'files' => array_slice($flagged, 0, self::MAX_LISTED), 'next_step' => 'wpmcp/read-file'],
            'These PHP files were written later than the other files in their folder, yet their modification time was set back to look old, a common way to hide a planted file. A deploy that keeps file times can cause it too.',
            'Read each with read-file and compare it with a fresh copy of its plugin or theme. Replace or remove it if it differs.'
        )];
    }

    private function check_cloaking(): array
    {
        if (! function_exists('home_url')) {
            return [];
        }
        $urls = [home_url('/')];
        foreach (get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => self::MAX_PAGES - 1, 'has_password' => false, 'suppress_filters' => false]) as $post) {
            $link = get_permalink($post);
            if (is_string($link) && '' !== $link) {
                $urls[] = $link;
            }
        }
        $own_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        $pages   = [];
        $failed  = 0;
        foreach (array_slice(array_unique($urls), 0, self::MAX_PAGES) as $url) {
            if (time() >= $this->deadline) {
                $this->truncated = $this->truncated ?? 'time_budget';
                break;
            }
            $browser = $this->fetch($url, self::BROWSER_UA, '');
            if (null === $browser) {
                $failed++;
                continue;
            }
            $diff = $this->compare_fetches(
                $browser,
                $this->fetch($url, self::CRAWLER_UA, ''),
                $this->fetch($url, self::BROWSER_UA, self::SEARCH_REFERER),
                $own_host
            );
            if ([] !== $diff) {
                $pages[] = ['url' => $url] + $diff;
            }
        }

        if ([] === $pages) {
            if ($failed > 0 && $failed === count(array_slice(array_unique($urls), 0, self::MAX_PAGES))) {
                return [Security_Finding::make(
                    'compromise_cloaking',
                    self::CATEGORY,
                    'Crawler-only content',
                    'info',
                    false,
                    'This site could not fetch its own pages, so crawler-only content was not compared.',
                    'Run the check where the site can reach itself over HTTP.'
                )];
            }
            return [$this->pass('compromise_cloaking', 'Crawler-only content', 'Pages look the same to a search crawler, and visitors from a search engine are not redirected.')];
        }

        $critical = false;
        foreach ($pages as $page) {
            if (isset($page['crawler_only_links']) || isset($page['crawler_redirect']) || isset($page['referer_redirect'])) {
                $critical = true;
            }
        }
        return [Security_Finding::make(
            'compromise_cloaking',
            self::CATEGORY,
            'Content shown only to search crawlers',
            $critical ? 'critical' : 'warning',
            ['pages' => $pages, 'next_step' => 'wpmcp/search-files'],
            $critical
                ? 'A search crawler sees links or redirects a normal visitor does not, or visitors arriving from a search engine are sent to another site. This is SEO spam injected by malware.'
                : 'A search crawler gets a page of a very different size than a normal visitor. A cache or a plugin can cause this, but so can injected spam.',
            'Search the theme, plugins and database for the spam domain with search-files and search-content, remove the injected code, then ask the search engine to recrawl.'
        )];
    }

    // Plumbing.

    /**
     * [mtime, ctime] of a file. Overridable in tests, since ctime cannot be set.
     *
     * @return array{0:int, 1:int}
     */
    protected function file_times(string $path): array
    {
        $stat = @stat($path);
        if (false === $stat) {
            return [0, 0];
        }
        return [(int) $stat['mtime'], (int) $stat['ctime']];
    }

    /** @return array{code:int, location:string, body:string}|null */
    private function fetch(string $url, string $ua, string $referer): ?array
    {
        if (time() >= $this->deadline) {
            return null;
        }
        $this->requests++;
        $args = [
            'timeout'             => self::FETCH_TIMEOUT,
            'redirection'         => 0,
            'user-agent'          => $ua,
            'limit_response_size' => self::MAX_BODY_BYTES,
        ];
        if ('' !== $referer) {
            $args['headers'] = ['Referer' => $referer];
        }
        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response)) {
            return null;
        }
        return [
            'code'     => (int) wp_remote_retrieve_response_code($response),
            'location' => (string) wp_remote_retrieve_header($response, 'location'),
            'body'     => (string) wp_remote_retrieve_body($response),
        ];
    }

    /** @param array{code:int, location:string, body:string} $response */
    private function redirect_host(array $response): ?string
    {
        if ($response['code'] < 300 || $response['code'] >= 400 || '' === $response['location']) {
            return null;
        }
        $host = wp_parse_url($response['location'], PHP_URL_HOST);
        return is_string($host) && '' !== $host ? $this->bare_host($host) : null;
    }

    private function bare_host(string $host): string
    {
        $host = strtolower($host);
        return 0 === strpos($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @return array<int, array{function:mixed}> */
    private function hook_callbacks(string $hook): array
    {
        global $wp_filter;
        if (! isset($wp_filter[ $hook ]) || ! is_object($wp_filter[ $hook ])) {
            return [];
        }
        $out = [];
        foreach ((array) $wp_filter[ $hook ]->callbacks as $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $out[] = $callback['function'] ?? null;
            }
        }
        return $out;
    }

    /** @param mixed $callback */
    private function callback_file($callback): ?string
    {
        try {
            if (is_string($callback) && false !== strpos($callback, '::')) {
                $callback = explode('::', $callback, 2);
            }
            if (is_array($callback) && 2 === count($callback)) {
                $ref = new \ReflectionMethod($callback[0], (string) $callback[1]);
            } elseif ($callback instanceof \Closure || is_string($callback)) {
                $ref = new \ReflectionFunction($callback);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $ref = new \ReflectionMethod($callback, '__invoke');
            } else {
                return null;
            }
            $file = $ref->getFileName();
            return false === $file ? null : $file;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Why a callback file is somewhere code should not be, or null. */
    private function unexpected_location(string $file): ?string
    {
        $file    = $this->real($file);
        $content = $this->real((string) WP_CONTENT_DIR);
        $uploads = function_exists('wp_upload_dir') ? $this->real((string) (wp_upload_dir(null, false)['basedir'] ?? '')) : '';
        if ('' === $file) {
            return null;
        }
        if ('' !== $uploads && 0 === strpos($file, $uploads . '/')) {
            return 'uploads';
        }
        if ('' !== $content && dirname($file) === $content && ! in_array(basename($file), self::DROPINS, true)) {
            return 'wp_content_root';
        }
        foreach ([(string) ABSPATH, (string) WP_CONTENT_DIR, (string) WP_PLUGIN_DIR, defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : '', defined('WPMCP_DIR') ? (string) WPMCP_DIR : ''] as $root) {
            $root = $this->real($root);
            if ('' !== $root && 0 === strpos($file, $root . '/')) {
                return null;
            }
        }
        $tmp = $this->real(sys_get_temp_dir());
        if ('' !== $tmp && 0 === strpos($file, $tmp . '/')) {
            return 'temp_folder';
        }
        return null;
    }

    private function has_plugin_header(string $path): bool
    {
        if (! function_exists('get_file_data')) {
            return false;
        }
        $data = get_file_data($path, ['name' => 'Plugin Name']);
        return '' !== trim((string) ($data['name'] ?? ''));
    }

    /** @return array{location:string, sha256:string, bytes:int, mtime:string} */
    private function file_evidence(string $path): array
    {
        $this->checked++;
        $hash = '';
        if (is_readable($path) && (int) @filesize($path) <= Malware_Audit::MAX_FILE_BYTES) {
            $hash = (string) hash_file('sha256', $path);
        }
        return [
            'location' => $this->relative($path),
            'sha256'   => $hash,
            'bytes'    => (int) @filesize($path),
            'mtime'    => gmdate('Y-m-d H:i:s', (int) @filemtime($path)),
        ];
    }

    private function pass(string $id, string $label, string $message): array
    {
        return Security_Finding::make($id, self::CATEGORY, $label, 'pass', true, $message);
    }

    /**
     * Entries directly in a folder (absolute paths), symlinks skipped.
     *
     * @return string[]
     */
    private function list_dir(string $dir): array
    {
        $dir = rtrim($dir, '/\\');
        if ('' === $dir || ! is_dir($dir)) {
            return [];
        }
        $names = @scandir($dir);
        if (false === $names) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name || is_link($dir . '/' . $name)) {
                continue;
            }
            $out[] = $dir . '/' . $name;
        }
        return $out;
    }

    /**
     * Bounded recursive walk of regular files (real paths inside ABSPATH or
     * wp-content), sharing the run's file and time budget. Symlinks are not
     * followed.
     *
     * @return \Generator<int, string>
     */
    private function walk(string $root): \Generator
    {
        if (null !== $this->truncated || '' === $root || ! is_dir($root)) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                if ($this->checked >= $this->max_files) {
                    $this->truncated = 'max_files';
                    return;
                }
                if (time() >= $this->deadline) {
                    $this->truncated = 'time_budget';
                    return;
                }
                if ($file->isLink() || ! $file->isFile()) {
                    continue;
                }
                $this->checked++;
                $real = $this->real($file->getPathname());
                if ('' !== $real) {
                    yield $real;
                }
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /** @param string[] $dirs ABSPATH-relative */
    private function in_dirs(string $rel, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if ('' !== $dir && 0 === strpos($rel, $dir . '/')) {
                return true;
            }
        }
        return false;
    }

    private function public_url(string $path): ?string
    {
        $path    = $this->real($path);
        $content = $this->real((string) WP_CONTENT_DIR);
        $abspath = $this->real((string) ABSPATH);
        if ('' !== $content && 0 === strpos($path, $content . '/')) {
            return content_url($this->encode_path(substr($path, strlen($content) + 1)));
        }
        if ('' !== $abspath && 0 === strpos($path, $abspath . '/')) {
            return site_url('/' . $this->encode_path(substr($path, strlen($abspath) + 1)));
        }
        return null;
    }

    private function encode_path(string $rel): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    private function relative(string $path): string
    {
        $path    = str_replace('\\', '/', $path);
        $real    = $this->real($path);
        $abspath = $this->real((string) ABSPATH);
        $content = $this->real((string) WP_CONTENT_DIR);
        $path    = '' !== $real ? $real : $path;
        if ('' !== $abspath && 0 === strpos($path, $abspath . '/')) {
            return substr($path, strlen($abspath) + 1);
        }
        if ('' !== $content && 0 === strpos($path, $content . '/')) {
            return 'wp-content/' . substr($path, strlen($content) + 1);
        }
        return basename($path);
    }

    private function real(string $path): string
    {
        if ('' === $path) {
            return '';
        }
        $real = realpath($path);
        return false === $real ? '' : rtrim(str_replace('\\', '/', $real), '/');
    }
}
