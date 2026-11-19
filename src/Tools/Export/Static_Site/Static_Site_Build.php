<?php

namespace WPMCP\Tools\Export\Static_Site;

use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The static HTML export's build, in resumable steps (issue #854).
 *
 * A build lives in a work folder inside the protected site-backup
 * directory: site/ holds the files as they will appear in the ZIP and
 * state.json holds everything needed to carry on (the page and asset
 * queues, what each URL maps to, the skips with their reasons). The state is
 * written after every page and every asset, so a step the host kills
 * (max_execution_time, memory) loses at most the item it was on, and the
 * next step starts from the last saved state. The ZIP is written once all
 * pages and assets are in, to a partial file renamed into place when
 * complete.
 *
 * Pages are fetched through a loopback HTTP request with no cookies, so
 * each one is rendered exactly as an anonymous visitor sees it: nothing
 * private, no admin bar, no masked data to leak. Assets on this site are
 * read from disk when they map to an allowed file type under ABSPATH or
 * wp-content (never inside a wpmcp-* data folder or a dot folder), and
 * fetched over HTTP otherwise, so the web server's own access rules apply.
 * Off-site assets are left pointing at their origin and reported.
 */
class Static_Site_Build
{
    public const STATE_VERSION = 1;

    /** Redirect hops followed from one start URL before it counts as a loop. */
    public const MAX_REDIRECTS = 5;

    /** Entries of the skip list kept in the job record (the ZIP report has all). */
    public const RESULT_LIST_LIMIT = 100;

    /** Default largest asset copied. Filter: wpmcp_static_export_max_asset_bytes. */
    public const MAX_ASSET_BYTES = 52428800;

    /** File types an asset may be read from disk as. */
    private const DISK_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'm4a', 'pdf',
    ];

    public const REPORT_FILE = 'wpmcp-static-report.json';

    /**
     * A new build: the work folder, the start URLs queued, the state saved.
     *
     * @param array<string, string> $start_urls URL => source, from Url_Discovery.
     * @param array<int, array{url: string, reason: string}> $skipped Start URLs refused.
     */
    public function start(int $job_id, array $start_urls, array $skipped, bool $follow): array
    {
        $root = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($root);

        $token    = substr(md5(wp_generate_uuid4()), 0, 8);
        $work_dir = trailingslashit($root) . sprintf('wpmcp-static-%d-%s.work', $job_id, $token);
        if (! wp_mkdir_p($work_dir . '/site')) {
            throw new \RuntimeException('Could not create the static export work folder in the site-backup directory; check its permissions.');
        }

        $zip = trailingslashit($root) . sprintf('wpmcp-static-%s-%d-%s.zip', gmdate('Y-m-d-His'), $job_id, $token);

        $state = [
            'version'       => self::STATE_VERSION,
            'job_id'        => $job_id,
            'work_dir'      => $work_dir,
            'zip_file'      => $zip,
            'home'          => home_url('/'),
            'follow'        => $follow,
            'max_pages'     => Url_Discovery::max_pages(),
            'phase'         => 'pages',
            'queue'         => [],
            'pages'         => [],
            'asset_queue'   => [],
            'assets'        => [],
            'skipped'       => [],
            'dynamic'       => [],
            'pages_done'    => 0,
            'assets_done'   => 0,
            'bytes'         => 0,
            'started_at'    => time(),
        ];

        foreach ($skipped as $skip) {
            $state['skipped'][] = $skip;
        }
        foreach (array_keys($start_urls) as $url) {
            $this->queue_page($state, $url, 0, true);
        }

        self::save($state);

        return $state;
    }

    /** The saved state of the build in $work_dir. */
    public static function load(string $work_dir): array
    {
        $file = trailingslashit($work_dir) . 'state.json';
        $raw  = is_file($file) ? file_get_contents($file) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || self::STATE_VERSION !== ($data['version'] ?? null)) {
            throw new \RuntimeException('The static export build state is missing or unreadable; start the export again.');
        }

        return $data;
    }

    public static function save(array $state): void
    {
        $file = trailingslashit((string) $state['work_dir']) . 'state.json';
        $tmp  = $file . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- an atomic replace inside the plugin's own protected folder, written from a WP-Cron request where WP_Filesystem may need credentials it cannot ask for.
        if (false === file_put_contents($tmp, (string) wp_json_encode($state)) || ! rename($tmp, $file)) {
            throw new \RuntimeException('Could not save the static export build state; check disk space and permissions.');
        }
    }

    /** What the job record shows while the build runs. */
    public static function progress(array $state): array
    {
        return [
            'phase'         => (string) $state['phase'],
            'pages_done'    => (int) $state['pages_done'],
            'pages_queued'  => count($state['queue']),
            'assets_done'   => (int) $state['assets_done'],
            'assets_queued' => count($state['asset_queue']),
            'bytes'         => (int) $state['bytes'],
            'skipped'       => count($state['skipped']),
            // Cancel and the cron sweep remove this file (Site_Archive_Builder::discard_partial).
            'partial_file'  => (string) $state['zip_file'] . '.part',
        ];
    }

    /**
     * Work until $deadline (microtime) or the build is done, saving after
     * every item. The ZIP is written in the step that finds the queues
     * empty. $heartbeat receives the progress; it throws to stop.
     *
     * @param callable(array): void $heartbeat
     */
    public function step(array $state, float $deadline, callable $heartbeat): array
    {
        // At least one item per step, so a step budget smaller than one
        // fetch still makes progress.
        while ('pages' === $state['phase']) {
            if ([] !== $state['asset_queue']) {
                $file = (string) array_key_first($state['asset_queue']);
                $url  = (string) $state['asset_queue'][ $file ];
                unset($state['asset_queue'][ $file ]);
                $this->export_asset($state, $url, $file);
                $state['assets_done']++;
            } elseif ([] !== $state['queue']) {
                $item = array_shift($state['queue']);
                $this->export_page($state, (string) $item[0], (int) $item[1]);
                $state['pages_done']++;
            } else {
                $state['phase'] = 'zip';
            }

            self::save($state);
            $heartbeat(self::progress($state));

            if ('pages' === $state['phase'] && microtime(true) >= $deadline) {
                return $state;
            }
        }

        if ('zip' === $state['phase']) {
            $state['result'] = $this->write_zip($state, $heartbeat);
            $state['phase']  = 'done';
            self::save($state);
        }

        return $state;
    }

    /** Remove the work folder and any partial ZIP. */
    public static function discard(string $work_dir, string $zip_file = ''): void
    {
        $root = realpath(Site_Backup_Dir::path());
        $real = realpath($work_dir);
        if (false !== $root && false !== $real && str_starts_with($real, trailingslashit($root) . 'wpmcp-static-') && is_dir($real)) {
            self::remove_tree($real);
        }
        if ('' !== $zip_file && is_file($zip_file . '.part')) {
            wp_delete_file($zip_file . '.part');
        }
    }

    private static function remove_tree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes this build's own work folder, see discard().
            } else {
                wp_delete_file($item->getPathname());
            }
        }
        rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes this build's own work folder, see discard().
    }

    /**
     * Queue a page URL unless it is already known, refused, or over the
     * limit. Returns its ZIP path when the page is (or will be) exported.
     */
    private function queue_page(array &$state, string $url, int $hops, bool $start): ?string
    {
        $reason = Url_Discovery::refusal($url, (string) $state['home']);
        if (null !== $reason) {
            if ($start) {
                self::skip($state, $url, $reason);
            }
            return null;
        }

        $file = (string) Link_Rewriter::page_file($url);
        if (isset($state['pages'][ $file ])) {
            return $file;
        }
        if (count($state['pages']) >= (int) $state['max_pages']) {
            if ($start) {
                self::skip($state, $url, sprintf('over the %d page limit (filter wpmcp_static_export_max_pages)', (int) $state['max_pages']));
            }
            return null;
        }

        $state['pages'][ $file ] = $url;
        $state['queue'][]        = [$url, $hops];

        return $file;
    }

    private static function skip(array &$state, string $url, string $reason): void
    {
        foreach ($state['skipped'] as $known) {
            if ($known['url'] === $url) {
                return;
            }
        }
        $state['skipped'][] = ['url' => $url, 'reason' => $reason];
    }

    /** One page: fetch, follow a redirect, rewrite, write. */
    private function export_page(array &$state, string $url, int $hops): void
    {
        $file = (string) Link_Rewriter::page_file($url);

        $response = $this->fetch($url, 0);
        if (is_wp_error($response)) {
            self::skip($state, $url, 'fetch failed: ' . $response->get_error_message());
            unset($state['pages'][ $file ]);
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            $this->export_redirect($state, $url, $file, $hops, (string) wp_remote_retrieve_header($response, 'location'));
            return;
        }
        if (200 !== $code) {
            self::skip($state, $url, sprintf('HTTP %d', $code));
            unset($state['pages'][ $file ]);
            return;
        }

        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ('' !== $type && ! str_contains($type, 'text/html')) {
            self::skip($state, $url, sprintf('not an HTML page (%s)', $type));
            unset($state['pages'][ $file ]);
            return;
        }

        $html = (string) wp_remote_retrieve_body($response);

        $features = self::dynamic_features($html);
        if ([] !== $features) {
            $state['dynamic'][] = ['url' => $url, 'file' => $file, 'has' => $features];
        }

        $resolver = function (string $target, string $kind) use (&$state, $hops): ?string {
            return $this->resolve_target($state, $target, $kind, $hops);
        };
        $html = Link_Rewriter::rewrite_html($html, $url, $file, $resolver);

        $this->write($state, $file, $html);
    }

    /**
     * A 3xx page: a small refresh page at its path that sends the visitor
     * on to the exported target, or a skip when the redirect loops, chains
     * too far or leaves the site.
     */
    private function export_redirect(array &$state, string $url, string $file, int $hops, string $location): void
    {
        $target = '' === $location ? null : Link_Rewriter::resolve($location, $url);
        $reason = null;
        if (null === $target) {
            $reason = 'redirect with no usable Location';
        } elseif (! Link_Rewriter::is_internal($target, (string) $state['home'])) {
            $reason = sprintf('redirects off-site to %s', $target);
        } elseif (Link_Rewriter::page_file((string) strtok($target, '#')) === $file || $hops >= self::MAX_REDIRECTS) {
            $reason = 'redirect loop (or a chain of more than ' . self::MAX_REDIRECTS . ' redirects)';
        }

        $target_file = null;
        if (null === $reason) {
            $target_file = $this->queue_page($state, (string) strtok((string) $target, '#'), $hops + 1, false);
            if (null === $target_file) {
                $reason = sprintf('redirects to %s, which is not exported', $target);
            }
        }

        if (null !== $reason) {
            self::skip($state, $url, $reason);
            unset($state['pages'][ $file ]);
            return;
        }

        $href = esc_attr(Link_Rewriter::relative($file, (string) $target_file));
        $this->write($state, $file, sprintf(
            "<!doctype html>\n<html><head><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"0; url=%1\$s\"><link rel=\"canonical\" href=\"%1\$s\"><title>Redirect</title></head><body><a href=\"%1\$s\">%1\$s</a></body></html>\n",
            $href
        ));
    }

    /**
     * The link resolver for one page: the ZIP path a reference is exported
     * to, queueing it as needed, or null to leave the reference as it is.
     */
    private function resolve_target(array &$state, string $url, string $kind, int $hops): ?string
    {
        $home = (string) $state['home'];
        if (! Link_Rewriter::is_internal($url, $home)) {
            if ('asset' === $kind) {
                self::skip($state, $url, 'off-site asset, left pointing at its origin');
            }
            return null;
        }
        if (Link_Rewriter::is_dynamic($url)) {
            return null;
        }

        if ('page' === $kind) {
            $file = Link_Rewriter::page_file($url);
            if (null === $file) {
                return null;
            }
            if (isset($state['pages'][ $file ])) {
                return $file;
            }
            return $state['follow'] ? $this->queue_page($state, $url, $hops, false) : null;
        }

        if ('asset' !== $kind) {
            return null;
        }

        $file = Link_Rewriter::asset_file($url);
        if (null === $file) {
            return null;
        }
        if (! isset($state['assets'][ $file ])) {
            $state['assets'][ $file ]      = $url;
            $state['asset_queue'][ $file ] = $url;
        }

        return $file;
    }

    /** One asset: from disk where allowed, else over HTTP; CSS rewritten. */
    private function export_asset(array &$state, string $url, string $file): void
    {
        $max  = max(1, (int) apply_filters('wpmcp_static_export_max_asset_bytes', self::MAX_ASSET_BYTES));
        $body = null;

        $local = self::local_file($url);
        if (null !== $local) {
            if (filesize($local) > $max) {
                self::skip($state, $url, sprintf('asset larger than %d bytes (filter wpmcp_static_export_max_asset_bytes)', $max));
                return;
            }
            $body = (string) file_get_contents($local);
        } else {
            $response = $this->fetch($url, 3, $max);
            if (is_wp_error($response)) {
                self::skip($state, $url, 'asset fetch failed: ' . $response->get_error_message());
                return;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if (200 !== $code) {
                self::skip($state, $url, sprintf('asset HTTP %d', $code));
                return;
            }
            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) >= $max) {
                self::skip($state, $url, sprintf('asset larger than %d bytes (filter wpmcp_static_export_max_asset_bytes)', $max));
                return;
            }
        }

        if ('css' === strtolower((string) pathinfo($file, PATHINFO_EXTENSION))) {
            $resolver = function (string $target, string $kind) use (&$state): ?string {
                return $this->resolve_target($state, $target, 'asset', 0);
            };
            $body = Link_Rewriter::rewrite_css($body, $url, $file, $resolver);
        }

        $this->write($state, $file, $body);
    }

    /**
     * The file on disk an internal asset URL maps to, or null when it is
     * not one this export may read directly.
     */
    private static function local_file(string $url): ?string
    {
        $ext = strtolower((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (! in_array($ext, self::DISK_EXTENSIONS, true)) {
            return null;
        }

        $bare = (string) strtok($url, '?#');
        $maps = [
            untrailingslashit(content_url()) => WP_CONTENT_DIR,
            untrailingslashit(site_url())    => untrailingslashit(ABSPATH),
        ];

        $candidate = null;
        foreach ($maps as $prefix => $dir) {
            $plain = preg_replace('#^https?:#i', '', $prefix);
            $bare_plain = preg_replace('#^https?:#i', '', $bare);
            if (str_starts_with((string) $bare_plain, (string) $plain . '/')) {
                $candidate = $dir . rawurldecode(substr((string) $bare_plain, strlen((string) $plain)));
                break;
            }
        }
        if (null === $candidate) {
            return null;
        }

        $real = realpath($candidate);
        if (false === $real || ! is_file($real)) {
            return null;
        }

        foreach ([WP_CONTENT_DIR, ABSPATH] as $root) {
            $root_real = realpath($root);
            if (false === $root_real || ! str_starts_with($real, trailingslashit($root_real))) {
                continue;
            }
            // Never a dot folder or one of this plugin's data folders
            // (backups, exports), whatever the web server would allow.
            $inside = '/' . substr($real, strlen(trailingslashit($root_real)));
            return preg_match('#/(\.[^/]*|wpmcp-[^/]*)/#', $inside) ? null : $real;
        }

        return null;
    }

    /**
     * A loopback GET with no cookies: the page as an anonymous visitor
     * gets it.
     *
     * @return array|\WP_Error
     */
    private function fetch(string $url, int $redirects, int $limit = 0)
    {
        $args = [
            'timeout'     => 20,
            'redirection' => $redirects,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, the one WP-Cron's spawn honours.
            'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false),
            'user-agent'  => 'WP MCP static export',
            'headers'     => ['Accept' => 'text/html,*/*'],
        ];
        if ($limit > 0) {
            $args['limit_response_size'] = $limit;
        }

        return wp_remote_get($url, $args);
    }

    private function write(array &$state, string $file, string $body): void
    {
        $path = trailingslashit((string) $state['work_dir']) . 'site/' . $file;
        if (! wp_mkdir_p(dirname($path)) || false === file_put_contents($path, $body)) {
            throw new \RuntimeException(sprintf('Could not write %s into the static export work folder; check disk space.', esc_html($file)));
        }
        $state['bytes'] += strlen($body);
    }

    /**
     * What on a page will not work in a static copy.
     *
     * @return string[]
     */
    public static function dynamic_features(string $html): array
    {
        $has = [];
        if (preg_match('#role=["\']search["\']|<input[^>]+name=["\']s["\']#i', $html)) {
            $has[] = 'search';
        }
        if (preg_match('#id=["\']respond["\']|class=["\'][^"\']*comment-form#i', $html)) {
            $has[] = 'comments';
        }
        $forms = preg_match_all('#<form\b#i', $html);
        $other = (int) $forms - (in_array('search', $has, true) ? 1 : 0) - (in_array('comments', $has, true) ? 1 : 0);
        if ($other > 0) {
            $has[] = 'form';
        }

        return $has;
    }

    /** The finished ZIP, and the result the job records. */
    private function write_zip(array $state, callable $heartbeat): array
    {
        if (! class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension (ZipArchive) is required to write the static export.');
        }

        $site    = trailingslashit((string) $state['work_dir']) . 'site';
        $zip     = (string) $state['zip_file'];
        $partial = $zip . '.part';

        $report = [
            'home'          => (string) $state['home'],
            'generated_at'  => gmdate('c'),
            'pages'         => array_values(array_map('strval', array_keys($state['pages']))),
            'skipped'       => $state['skipped'],
            'dynamic_pages' => $state['dynamic'],
        ];
        file_put_contents($site . '/' . self::REPORT_FILE, (string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // The home page sits at the host root when WordPress does. When the
        // site lives in a sub-folder, a root index.html sends a visitor on.
        $home_file = (string) Link_Rewriter::page_file((string) $state['home']);
        if ('index.html' !== $home_file && ! is_file($site . '/index.html') && is_file($site . '/' . $home_file)) {
            $href = esc_attr(Link_Rewriter::relative('index.html', $home_file));
            file_put_contents($site . '/index.html', sprintf('<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0; url=%1$s"><a href="%1$s">%1$s</a>', $href));
        }

        $archive = new \ZipArchive();
        if (true !== $archive->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not create the static export ZIP in the site-backup directory.');
        }

        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $item) {
            if (! $item->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($site))), '/');
            $archive->addFile($item->getPathname(), $relative);
            $count++;
        }
        $heartbeat(array_merge(self::progress($state), ['phase' => 'zip']));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moves the finished ZIP into place inside the protected site-backup folder, as Site_Archive_Builder does.
        if (! $archive->close() || ! rename($partial, $zip)) {
            if (is_file($partial)) {
                wp_delete_file($partial);
            }
            throw new \RuntimeException('Could not finish the static export ZIP; check disk space.');
        }

        return [
            'kind'                  => 'static',
            'file'                  => $zip,
            'size'                  => (int) filesize($zip),
            'files'                 => $count,
            'pages'                 => count($state['pages']),
            'assets'                => (int) $state['assets_done'],
            'home'                  => $home_file,
            'skipped_total'         => count($state['skipped']),
            'skipped'               => array_slice($state['skipped'], 0, self::RESULT_LIST_LIMIT),
            'dynamic_pages'         => array_slice($state['dynamic'], 0, self::RESULT_LIST_LIMIT),
            'report'                => self::REPORT_FILE,
        ];
    }
}
