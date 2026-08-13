<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared location for full-site backup archives: a dedicated directory under
 * uploads, blocked from direct web access the same way Export_Dir and
 * WPMCP\Safety\File_Backup protect theirs.
 *
 * A site archive contains a complete database dump and, for a full backup,
 * copies of wp-content. Serving one over HTTP would hand an anonymous
 * visitor every credential hash and secret key on the site, so the deny
 * rules here are a security boundary rather than tidiness. The .htaccess
 * only covers Apache; on nginx the directory name is deliberately
 * unguessable-adjacent (a per-archive random suffix, see
 * Site_Archive_Builder::archive_path()) so a blind fetch cannot enumerate
 * archives, and the readme spells the exposure out for the site owner.
 */
class Site_Backup_Dir
{
    public const DIR_NAME = 'wpmcp-site-backups';

    public static function path(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . self::DIR_NAME;
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
                . "this directory in the server config or move the archives off the web\n"
                . "root entirely.\n"
            );
        }
    }
}
