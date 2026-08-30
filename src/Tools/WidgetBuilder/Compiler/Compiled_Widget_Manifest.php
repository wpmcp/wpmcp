<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Manifest for compiled widgets (issue #72). Compiled PHP lives in an
 * isolated uploads sandbox (uploads/wpmcp-widgets/) and is loaded ONLY via
 * this manifest: a file on disk that no manifest entry points at is inert.
 * Disabling a widget flips its manifest entry without deleting the spec or
 * the generated file, so re-enable is instant and delete is restorable from
 * the spec (the CPT stays the source of truth).
 */
class Compiled_Widget_Manifest
{
    private const MANIFEST_FILE = 'manifest.json';

    public static function sandbox_dir(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'wpmcp-widgets';
    }

    /** @return array<string,array{file:string,enabled:bool,spec_id:int,hash:string}> */
    public static function read(): array
    {
        $path = trailingslashit(self::sandbox_dir()) . self::MANIFEST_FILE;
        if (! is_readable($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,array{file:string,enabled:bool,spec_id:int,hash:string}> $entries
     * @return true|\WP_Error
     */
    public static function write(array $entries)
    {
        $dir = self::sandbox_dir();
        if (! wp_mkdir_p($dir)) {
            return new \WP_Error('wpmcp_widget_sandbox_unwritable', 'Could not create the widget sandbox directory.');
        }
        // Deny direct web access to the sandbox; only the manifest loader reads it.
        if (! file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        $json = wp_json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === file_put_contents(trailingslashit($dir) . self::MANIFEST_FILE, $json)) {
            return new \WP_Error('wpmcp_widget_manifest_unwritable', 'Could not write the widget manifest.');
        }
        return true;
    }

    public static function set_enabled(string $name, bool $enabled): bool
    {
        $entries = self::read();
        if (! isset($entries[$name])) {
            return false;
        }
        $entries[$name]['enabled'] = $enabled;
        return true === self::write($entries);
    }

    /**
     * Load every enabled, hash-verified compiled widget file.
     *
     * TODO(#72): hook this from the Elementor widgets_registered path once the
     * compiler emits real classes; verify the stored hash against the file on
     * disk before require-ing (a tampered file must be skipped and reported),
     * and register each loaded class with the Elementor widgets manager.
     */
    public static function load_enabled(): void
    {
        // Intentionally inert until the compiler (Widget_Compiler) can emit
        // lint-passing classes; loading nothing is the safe default.
    }
}
