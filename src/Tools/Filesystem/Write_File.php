<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create or overwrite a file inside the WordPress install. Disabled by
 * default: sites must opt in via the wpmcp_enable_fs_writes filter. Requires
 * the edit_files capability and honors DISALLOW_FILE_EDIT. Refuses
 * wp-config.php/.htaccess. Records the write as an undoable operation
 * (File_Write_Operation): an existing file is backed up first, and
 * rollback-operation with the returned operation_id writes it back, or
 * removes the file when this call created it (issue #602).
 * A .php target is syntax-checked and site-checked first (Php_Edit_Guard).
 *
 * encoding: base64 writes binary files such as fonts and images (issue
 * #851): strict base64 only, at most BASE64_MAX_BYTES decoded, and never an
 * executable or server-config type (see refuses_binary_name()), so it is
 * never a way around the PHP checks a text write meets. The backup is a
 * byte copy, so rollback-operation restores binary content exactly.
 */
class Write_File
{
    /** Decoded size cap for an encoding: base64 write. */
    public const BASE64_MAX_BYTES = 5242880;

    /**
     * Name segments a base64 write refuses: PHP and other server-run code,
     * and per-directory server config. Any segment after the first counts,
     * so shell.php.png is refused too.
     */
    private const BINARY_REFUSED_SEGMENT = '/^(php\d*|phtml?|pht|phps|phar|inc|cgi|fcgi|pl|py|rb|sh|bash|asp|aspx|jsp|shtml?|ini|htaccess|htpasswd)$/';

    /** Whether a base64 write must refuse this file name. */
    public static function refuses_binary_name(string $abs): bool
    {
        $base = strtolower(basename($abs));
        if ('web.config' === $base) {
            return true;
        }
        $segments = explode('.', $base);
        array_shift($segments);
        foreach ($segments as $segment) {
            if (1 === preg_match(self::BINARY_REFUSED_SEGMENT, $segment)) {
                return true;
            }
        }
        return false;
    }

    /** Decode a strict base64 payload (whitespace ignored) within the size cap. */
    private static function decode_base64(string $encoded): string
    {
        $clean = (string) preg_replace('/\s+/', '', $encoded);
        if (strlen($clean) > 4 * (int) ceil(self::BASE64_MAX_BYTES / 3)) {
            throw new \RuntimeException(esc_html(sprintf('The decoded file is over the %d-byte base64 write limit.', self::BASE64_MAX_BYTES)));
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a binary file the caller asked to write; not obfuscation.
        $bytes = base64_decode($clean, true);
        if (false === $bytes) {
            throw new \RuntimeException('The content is not valid base64, so nothing was written.');
        }
        if (strlen($bytes) > self::BASE64_MAX_BYTES) {
            throw new \RuntimeException(esc_html(sprintf('The decoded file is over the %d-byte base64 write limit.', self::BASE64_MAX_BYTES)));
        }
        return $bytes;
    }

    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_fs_writes', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \WPMCP\MCP\Opt_In_Required('The write-file tool is disabled.', 'wpmcp_enable_fs_writes');
        }

        $gate = Filesystem_Guard::writes_allowed();
        if (is_wp_error($gate)) {
            throw new \RuntimeException(esc_html($gate->get_error_message()));
        }

        $encoding = (string) ($args['encoding'] ?? 'utf8');
        if (! in_array($encoding, ['utf8', 'base64'], true)) {
            throw new \RuntimeException('Unknown encoding: use utf8 or base64.');
        }

        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? ''));
        if (is_wp_error($abs)) {
            throw new \RuntimeException(esc_html($abs->get_error_message()));
        }

        if (Filesystem_Guard::is_protected($abs)) {
            throw new \RuntimeException('This file is protected from writes.');
        }

        // Defense in depth: resolve_path() already rejects a dangling
        // symlink leaf, but never write through a symlink here either,
        // in case it is ever reached with an already-resolved path.
        if (is_link($abs)) {
            throw new \RuntimeException('Refusing to write through a symlink.');
        }

        $content = (string) ($args['content'] ?? '');
        $binary  = 'base64' === $encoding;
        if ($binary) {
            if (self::refuses_binary_name($abs)) {
                throw new \RuntimeException('A base64 write cannot create an executable or server-config file (PHP, CGI, scripts, .ini, .htaccess).');
            }
            $content = self::decode_base64($content);
        }
        $existed = is_file($abs);

        $done = File_Write_Operation::run(
            'write-file',
            $abs,
            $args,
            static function (string $backup) use ($abs, $content, $args): array {
                if (Php_Edit_Guard::applies($abs)) {
                    $written = Php_Edit_Guard::write($abs, $content, ! empty($args['unchecked']), $backup);
                    return ['bytes' => $written['bytes'], 'php_check' => $written['php_check']];
                }
                if (! wp_mkdir_p(dirname($abs))) {
                    throw new \RuntimeException('Could not create the parent directory.');
                }
                $bytes = file_put_contents($abs, $content);
                if (false === $bytes) {
                    throw new \RuntimeException('Could not write the file (check permissions).');
                }
                return ['bytes' => $bytes, 'php_check' => null];
            }
        );
        $php_check = $done['result']['php_check'];

        $rel = Filesystem_Guard::to_relative($abs);
        Filesystem_Guard::log($existed ? 'overwrite' : 'create', $rel);

        return [
            'path'         => $rel,
            'bytes'        => (int) $done['result']['bytes'],
            'action'       => $existed ? 'overwritten' : 'created',
            'operation_id' => $done['operation_id'],
            'backup'       => '' !== $done['backup'] ? Filesystem_Guard::to_relative($done['backup']) : null,
            // A create is undone by removing the file, an overwrite by
            // writing the backup back: both through rollback-operation.
            'recoverable'  => true,
        ] + (null === $php_check ? [] : ['php_check' => $php_check])
            + ($binary ? ['sha256' => hash('sha256', $content)] : []);
    }
}
