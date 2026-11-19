<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared location for full-site backup archives.
 *
 * A site archive contains a complete database dump and, for a full backup,
 * copies of wp-content. Serving one over HTTP would hand an anonymous
 * visitor every credential hash and secret key on the site, so where the
 * archives live is a security boundary rather than tidiness.
 *
 * By default the archives live in a dedicated directory under uploads,
 * blocked from direct web access the same way Export_Dir and
 * WPMCP\Safety\File_Backup protect theirs. The .htaccess only covers
 * Apache; on nginx the per-archive random suffix (see
 * Site_Archive_Builder::archive_path()) keeps a blind fetch from
 * enumerating archives, and Backup_Dir_Health requests a canary file to
 * tell the owner whether the folder is actually served (issue #846).
 *
 * Defining WPMCP_BACKUP_DIR in wp-config.php (issue #846) moves the
 * archives to `<WPMCP_BACKUP_DIR>/wpmcp-site-backups`, typically a folder
 * outside the web root. The folder name stays the same on purpose: the
 * file tools (Filesystem_Guard::in_site_backup_dir()), the archive builder's
 * exclusions and uninstall all key on it. On a network, every site other
 * than the main one keeps its own subfolder,
 * `<WPMCP_BACKUP_DIR>/sites/<blog id>/wpmcp-site-backups`, as uploads does
 * today. A constant that cannot be used (not a string, or a folder that
 * cannot be created or written) falls back to uploads, and the reason is
 * reported by get-backup-status.
 *
 * The location is read from a constant, never from the database, because a
 * restore replaces the database while its progress file and safety archive
 * sit in this directory.
 */
class Site_Backup_Dir
{
    public const DIR_NAME = 'wpmcp-site-backups';

    /** Defined in wp-config.php: the parent folder for the backup folder. */
    public const CONSTANT = 'WPMCP_BACKUP_DIR';

    /** @var array{0: bool, 1: mixed}|null */
    private static ?array $constant_for_tests = null;

    public static function path(): string
    {
        return self::resolve()['path'];
    }

    /**
     * Where archives go and why.
     *
     * @return array{path: string, source: string, fallback_reason: ?string}
     *         source is 'constant' or 'uploads'.
     */
    public static function resolve(): array
    {
        [ $defined, $value ] = self::constant();
        if (! $defined) {
            return [ 'path' => self::uploads_path(), 'source' => 'uploads', 'fallback_reason' => null ];
        }

        $reason = null;
        if (! is_string($value) || '' === trim($value)) {
            $reason = self::CONSTANT . ' is not a folder path.';
        } else {
            $base = rtrim(str_replace('\\', '/', trim($value)), '/');
            if (! str_starts_with($base, '/') && 1 !== preg_match('#^[A-Za-z]:/#', $base)) {
                $reason = self::CONSTANT . ' must be an absolute path.';
            } else {
                $dir = $base . self::site_suffix() . '/' . self::DIR_NAME;
                if (self::usable($dir)) {
                    return [ 'path' => $dir, 'source' => 'constant', 'fallback_reason' => null ];
                }
                $reason = self::CONSTANT . ' points at a folder that cannot be created or written; archives stay in uploads.';
            }
        }

        return [ 'path' => self::uploads_path(), 'source' => 'uploads', 'fallback_reason' => $reason ];
    }

    /** The default location under uploads, where archives lived before WPMCP_BACKUP_DIR. */
    public static function uploads_path(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . self::DIR_NAME;
    }

    /**
     * Every folder an archive may legitimately sit in: the current one, and
     * the uploads one while archives made before WPMCP_BACKUP_DIR was set
     * have not been moved yet. Archive_Locator accepts both, so restore and
     * push-site-archive keep finding an archive across the move.
     *
     * @return string[]
     */
    public static function roots(): array
    {
        return array_values(array_unique([ self::path(), self::uploads_path() ]));
    }

    /**
     * Whether $dir sits inside a folder the web server is expected to serve:
     * the WordPress folder, wp-content or uploads.
     */
    public static function inside_web_root(string $dir): bool
    {
        return null !== self::public_url($dir);
    }

    /**
     * The URL $dir would be served at, when it sits under uploads,
     * wp-content or the WordPress folder; null otherwise.
     */
    public static function public_url(string $dir): ?string
    {
        $dir     = self::canonical($dir);
        $uploads = wp_upload_dir(null, false);
        $pairs   = [];
        if (! empty($uploads['basedir']) && ! empty($uploads['baseurl'])) {
            $pairs[] = [ (string) $uploads['basedir'], (string) $uploads['baseurl'] ];
        }
        if (defined('WP_CONTENT_DIR')) {
            $pairs[] = [ WP_CONTENT_DIR, content_url() ];
        }
        $pairs[] = [ ABSPATH, site_url() ];

        foreach ($pairs as [ $base, $url ]) {
            $base = self::canonical($base);
            if ('' === $base) {
                continue;
            }
            if ($dir === $base || str_starts_with($dir, $base . '/')) {
                $relative = ltrim(substr($dir, strlen($base)), '/');
                return untrailingslashit($url) . ('' === $relative ? '' : '/' . $relative);
            }
        }

        return null;
    }

    /** Ensure the directory exists and is blocked from direct web access. */
    public static function protect(string $dir): void
    {
        wp_mkdir_p($dir);
        if (! is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        if (! is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (! is_file($dir . '/README.txt')) {
            @file_put_contents(
                $dir . '/README.txt',
                "WP MCP site backups.\n\n"
                . "Each archive holds a full database dump, including password hashes and\n"
                . "secret keys. The .htaccess here denies direct web access on Apache. If\n"
                . "this site runs nginx or another server that ignores .htaccess, block\n"
                . "this directory in the server config, or define WPMCP_BACKUP_DIR in\n"
                . "wp-config.php with a folder outside the web root to move the archives\n"
                . "there.\n"
            );
        }
    }

    /**
     * Test seam: behave as if WPMCP_BACKUP_DIR were (or were not) defined
     * with $value. A real constant cannot be undefined again within a run.
     */
    public static function set_constant_for_tests(bool $defined, $value = null): void
    {
        self::$constant_for_tests = [ $defined, $value ];
    }

    public static function reset_for_tests(): void
    {
        self::$constant_for_tests = null;
    }

    /** @return array{0: bool, 1: mixed} */
    private static function constant(): array
    {
        if (null !== self::$constant_for_tests) {
            return self::$constant_for_tests;
        }

        return defined(self::CONSTANT) ? [ true, constant(self::CONSTANT) ] : [ false, null ];
    }

    /** '' on a single site and the main site, '/sites/<id>' on any other network site. */
    private static function site_suffix(): string
    {
        if (! is_multisite() || is_main_site()) {
            return '';
        }

        return '/sites/' . (int) get_current_blog_id();
    }

    /**
     * Whether $dir exists and is writable, or could be created: its nearest
     * existing ancestor is a writable directory. Nothing is created here,
     * because path() is called from read-only code.
     */
    private static function usable(string $dir): bool
    {
        if (file_exists($dir)) {
            return is_dir($dir) && wp_is_writable($dir);
        }

        $parent = dirname($dir);
        while (! file_exists($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                return false;
            }
            $parent = $next;
        }

        return is_dir($parent) && wp_is_writable($parent);
    }

    /**
     * $path with symlinks resolved as far as it exists, forward slashes and
     * no trailing slash, so a folder not created yet still compares
     * against a symlinked parent (macOS /var is /private/var).
     */
    private static function canonical(string $path): string
    {
        $path   = rtrim(str_replace('\\', '/', $path), '/');
        $suffix = '';
        $probe  = $path;
        while ('' !== $probe && ! file_exists($probe)) {
            $suffix = '/' . basename($probe) . $suffix;
            $next   = dirname($probe);
            if ($next === $probe) {
                break;
            }
            $probe = $next;
        }

        $real = '' !== $probe ? realpath($probe) : false;
        $base = false !== $real ? rtrim(str_replace('\\', '/', $real), '/') : $probe;

        return $base . $suffix;
    }
}
