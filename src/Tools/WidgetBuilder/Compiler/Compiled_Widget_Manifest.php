<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Manifest for compiled widgets (issue #72): the integrity record that decides
 * which generated PHP files the site is allowed to load.
 *
 * Two deliberate choices:
 *
 *  - The manifest lives in a WordPress OPTION, not in a file beside the code it
 *    vouches for. A hash record stored in the same directory as the files it
 *    hashes proves nothing: anyone who can write the sandbox can rewrite both.
 *    In the option, tampering with a generated file makes it stop loading.
 *  - The sandbox is wp-content/wpmcp-widgets, NOT uploads. Executable PHP does
 *    not belong under a media directory: it would be flagged (correctly) as
 *    critical by this plugin's own scanner, and uploads is the directory most
 *    likely to be world-readable and PHP-enabled. The directory still gets the
 *    same deny hardening the repo uses everywhere else (Export_Dir,
 *    Site_Backup_Dir): .htaccess, an index.php, and a README that spells out
 *    the nginx caveat, because .htaccess only covers Apache.
 *
 * Loading is manifest-only, hash-verified, and gated: a require happens only
 * when the site may execute generated PHP at all (PRO + the
 * wpmcp_enable_widget_compiler opt-in, see execution_allowed()), the spec post
 * is still published, the entry is enabled, and the bytes on disk still hash
 * to the record. Turning the opt-in back off makes every compiled file inert
 * immediately; it does not merely stop new compiles.
 *
 * Disabling flips the entry without deleting the spec or the file, so
 * re-enable is instant and delete stays restorable from the spec (the
 * wpmcp_widget CPT remains the source of truth: post status is what
 * load_enabled() reads, so trashing a spec through any path - this plugin's
 * tools, wp-admin, or the generic post abilities - stops its class loading).
 * Permanently deleting a spec purges the entry and unlinks the file.
 */
class Compiled_Widget_Manifest
{
    public const OPTION   = 'wpmcp_compiled_widgets';
    public const DIR_NAME = 'wpmcp-widgets';

    /**
     * The sandbox directory, confined to wp-content.
     *
     * The filter exists so a site (and the test suite) can move the sandbox,
     * but a filter is not allowed to relocate generated PHP outside the
     * install: a value that does not resolve inside WP_CONTENT_DIR is ignored
     * and the default is used. Without that confinement the one tool in the
     * plugin that writes executable PHP would be the one write path with no
     * containment check at all.
     */
    public static function sandbox_dir(): string
    {
        $default = trailingslashit(WP_CONTENT_DIR) . self::DIR_NAME;

        /** Filter the directory compiled widget classes are written to. */
        $dir = (string) apply_filters('wpmcp_compiled_widgets_dir', $default);
        if ('' === $dir) {
            return $default;
        }
        return self::confined($dir) ? $dir : $default;
    }

    /**
     * True when $dir sits inside WP_CONTENT_DIR. Compared on the resolved
     * paths where they exist so a symlinked sandbox cannot escape, and on the
     * normalized strings where they do not (the directory is created later).
     */
    private static function confined(string $dir): bool
    {
        $content = self::normalize_path(WP_CONTENT_DIR);
        $target  = self::normalize_path($dir);

        return '' !== $content
            && ($target === $content || str_starts_with($target . '/', $content . '/'));
    }

    /**
     * Absolute, symlink-resolved, separator-normalized form of a path that
     * need not exist yet.
     *
     * realpath() alone is not enough for a containment check here: the sandbox
     * is compared BEFORE it is created, and on macOS (and any host with a
     * symlinked prefix) the resolved and unresolved forms of the same
     * directory differ, so a raw string comparison reports a directory inside
     * wp-content as outside it. Resolve the deepest ancestor that does exist
     * and re-append the rest.
     */
    private static function normalize_path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        $tail = [];
        $head = $path;
        while ('' !== $head && '/' !== $head) {
            $real = realpath($head);
            if (false !== $real) {
                $real = rtrim(str_replace('\\', '/', $real), '/');
                return [] === $tail ? $real : $real . '/' . implode('/', array_reverse($tail));
            }
            $tail[] = basename($head);
            $parent = dirname($head);
            if ($parent === $head) {
                break;
            }
            $head = $parent;
        }
        return $path;
    }

    /** Create the sandbox and block direct web access to it. */
    public static function protect(): bool
    {
        $dir = self::sandbox_dir();
        if (! wp_mkdir_p($dir)) {
            return false;
        }
        // The hardening is not best-effort: a caller that gets true back from
        // this method writes executable PHP into the directory on the strength
        // of it, so an unwritable-but-existing sandbox has to fail here rather
        // than silently host unguarded generated PHP.
        if (! is_file($dir . '/.htaccess') && false === @file_put_contents($dir . '/.htaccess', "Require all denied\n")) {
            return false;
        }
        if (! is_file($dir . '/index.php') && false === @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n")) {
            return false;
        }
        if (! is_file($dir . '/README.txt')) {
            @file_put_contents(
                $dir . '/README.txt',
                "WP MCP compiled widgets.\n\n"
                . "Every .php file here was generated by the plugin from a stored widget\n"
                . "spec, and is loaded only when its hash matches the wpmcp_compiled_widgets\n"
                . "option. Editing a file here does not change what runs; it stops the file\n"
                . "from loading at all.\n\n"
                . "The .htaccess here denies direct web access on Apache. If this site runs\n"
                . "nginx or another server that ignores .htaccess, deny this directory in the\n"
                . "server config as well.\n"
            );
        }
        return true;
    }

    /**
     * Every well-formed manifest entry, keyed by spec id.
     *
     * Entries are validated on the way out, not trusted: a stored 'file' must
     * be a plain basename that resolves inside the sandbox, so a tampered
     * option can never make the loader require() an arbitrary path.
     *
     * @return array<int,array{spec_id:int,name:string,class:string,file:string,hash:string,enabled:bool,compiled_at:string}>
     */
    public static function read(): array
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $key => $entry) {
            $valid = self::validate_entry($key, $entry);
            if (null !== $valid) {
                $out[ $valid['spec_id'] ] = $valid;
            }
        }
        return $out;
    }

    /**
     * Validate one raw entry. Returns the normalized entry, or null when it is
     * malformed, mis-keyed, or points anywhere but at a plain file inside the
     * sandbox.
     *
     * @param mixed $key
     * @param mixed $entry
     * @return array{spec_id:int,name:string,class:string,file:string,hash:string,enabled:bool,compiled_at:string}|null
     */
    public static function validate_entry($key, $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }
        $spec_id = isset($entry['spec_id']) ? (int) $entry['spec_id'] : 0;
        if ($spec_id <= 0 || (int) $key !== $spec_id) {
            return null;
        }

        $file = isset($entry['file']) && is_string($entry['file']) ? $entry['file'] : '';
        if ('' === $file || basename($file) !== $file || ! self::is_sandboxed($file)) {
            return null;
        }

        // The class name must be the shape Widget_Compiler::class_name_for()
        // emits, INCLUDING this entry's own spec id. A bare identifier check
        // would let a tampered option bind a spec id to any class the site has
        // already declared (or let two entries share one class name), and the
        // loader would then register that pre-existing class as the widget.
        $class = isset($entry['class']) && is_string($entry['class']) ? $entry['class'] : '';
        if (1 !== preg_match('/^WPMCP_Compiled_Widget_(\d+)_[A-Za-z0-9_]+$/', $class, $m)
            || (int) $m[1] !== $spec_id
        ) {
            return null;
        }

        $hash = isset($entry['hash']) && is_string($entry['hash']) ? strtolower($entry['hash']) : '';
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return null;
        }

        return [
            'spec_id'     => $spec_id,
            'name'        => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '',
            'class'       => $class,
            'file'        => $file,
            'hash'        => $hash,
            'enabled'     => ! empty($entry['enabled']),
            'compiled_at' => isset($entry['compiled_at']) && is_string($entry['compiled_at']) ? $entry['compiled_at'] : '',
        ];
    }

    /** Absolute path of a sandbox file, or '' when it would escape the sandbox. */
    public static function path_for(string $file): string
    {
        if ('' === $file || basename($file) !== $file) {
            return '';
        }
        return trailingslashit(self::sandbox_dir()) . $file;
    }

    /**
     * Containment check that survives symlinks: the resolved path must sit
     * directly inside the resolved sandbox directory.
     */
    private static function is_sandboxed(string $file): bool
    {
        $dir = realpath(self::sandbox_dir());
        if (false === $dir) {
            // Sandbox not created yet: the basename check above is all we can
            // assert, and it already rules out traversal.
            return true;
        }
        $path = self::path_for($file);
        $real = realpath($path);
        if (false === $real) {
            return true; // Not written yet; nothing to contain.
        }
        return dirname($real) === rtrim($dir, '/\\');
    }

    /**
     * Replace the whole manifest. Entries are validated first, so an invalid
     * entry is rejected loudly instead of being stored and skipped later.
     *
     * @param array<int|string,array<string,mixed>> $entries
     * @return true|\WP_Error
     */
    public static function write(array $entries)
    {
        $clean = [];
        foreach ($entries as $key => $entry) {
            $valid = self::validate_entry($key, $entry);
            if (null === $valid) {
                return new \WP_Error('wpmcp_widget_manifest_invalid', 'Refusing to store a malformed compiled-widget manifest entry.');
            }
            $clean[ (string) $valid['spec_id'] ] = $valid;
        }

        update_option(self::OPTION, $clean, false);
        return true;
    }

    /**
     * Add or replace one entry, leaving the rest of the manifest alone.
     *
     * @param array<string,mixed> $entry
     * @return true|\WP_Error
     */
    public static function put(array $entry)
    {
        $entries = self::read();
        $spec_id = isset($entry['spec_id']) ? (int) $entry['spec_id'] : 0;
        $entries[ $spec_id ] = $entry;
        return self::write($entries);
    }

    public static function get(int $spec_id): ?array
    {
        $entries = self::read();
        return $entries[ $spec_id ] ?? null;
    }

    /**
     * Enable or disable one compiled widget without touching its spec or its
     * file. Disabled means "not loaded, not registered in the builder".
     *
     * This is a read-modify-write on the option, so two toggles racing each
     * other can lose one flip. It is not guarded with a lock because the worst
     * case is benign in both directions: the loser's widget stays in its
     * previous enable state, and a stale entry still cannot load anything the
     * hash check does not vouch for. A compile, which is the write that
     * actually matters, goes through Safe_Mutation and is snapshotted.
     *
     * @return true|null|\WP_Error true when flipped, null when the widget has
     *                             no manifest entry, WP_Error when the write
     *                             itself failed.
     */
    public static function set_enabled(int $spec_id, bool $enabled)
    {
        $entries = self::read();
        if (! isset($entries[ $spec_id ])) {
            // Not compiled. Distinct from "the flip failed", which is why this
            // is null and not false: a caller reporting the outcome to an
            // agent must not turn a failed write into "not compiled".
            return null;
        }
        $entries[ $spec_id ]['enabled'] = $enabled;
        $written = self::write($entries);
        return true === $written ? true : $written;
    }

    public static function remove(int $spec_id): bool
    {
        $entries = self::read();
        if (! isset($entries[ $spec_id ])) {
            return false;
        }
        unset($entries[ $spec_id ]);
        return true === self::write($entries);
    }

    /**
     * Whether this site is allowed to EXECUTE generated widget PHP at all.
     *
     * The same two gates the compiler's write path uses, applied to the read
     * path, because a gate that only guards the write is not containment: a
     * widget compiled while the feature was on would otherwise keep being
     * require()'d on every editor and front-end render after the PRO licence
     * lapsed or the opt-in filter was turned back off. Turning the opt-in off
     * now makes the generated files inert, which is what "PRO-only and
     * default-off" has to mean for an execution site.
     *
     * The filesystem gates (edit_files / DISALLOW_FILE_EDIT) deliberately do
     * NOT apply here: they are write permissions, and loading a file that is
     * already on disk is not a write.
     */
    public static function execution_allowed(): bool
    {
        if (! \WPMCP\Pro\Gate::is_pro()) {
            return false;
        }
        return Compile_Custom_Widget::is_enabled();
    }

    /**
     * Load every enabled, hash-verified compiled widget file and return the
     * class names that are now defined.
     *
     * A file whose bytes no longer hash to the manifest's record is NOT
     * loaded: the only way to change what runs is to compile again through the
     * tool, which is the whole point of keeping the hashes in an option rather
     * than beside the code.
     *
     * Four things must all hold before a require happens: the site may execute
     * generated PHP at all (execution_allowed()), the spec post is still
     * published (post status stays the single source of truth for both widget
     * forms, so trashing a spec through ANY path stops its class loading), the
     * manifest entry is enabled, and the bytes on disk still hash to the
     * record. After the require, the class must actually have come from this
     * file: a class of the same name declared elsewhere is not vouched for by
     * the hash and is not registered.
     *
     * @return array<int,string> spec_id => loaded class name
     */
    public static function load_enabled(): array
    {
        if (! self::execution_allowed()) {
            return [];
        }

        $loaded = [];
        foreach (self::read() as $spec_id => $entry) {
            if (! $entry['enabled']) {
                continue;
            }
            if ('publish' !== get_post_status($spec_id)) {
                continue;
            }
            $path = self::path_for($entry['file']);
            if ('' === $path || ! is_file($path) || is_link($path)) {
                continue;
            }
            $bytes = @file_get_contents($path);
            if (! is_string($bytes) || ! hash_equals($entry['hash'], hash('sha256', $bytes))) {
                continue;
            }
            if (! class_exists($entry['class'], false)) {
                require_once $path;
            }
            if (! class_exists($entry['class'], false)) {
                continue;
            }
            if (! self::class_came_from($entry['class'], $path)) {
                continue;
            }
            $loaded[ $spec_id ] = $entry['class'];
        }
        return $loaded;
    }

    /**
     * True when the declared class really is the one this file declares. The
     * hash vouches for the FILE; without this check a class that was already
     * declared before the require (so the require never ran) would inherit
     * that vouching for free.
     */
    private static function class_came_from(string $class, string $path): bool
    {
        try {
            $declared = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable $e) {
            return false;
        }
        if (! is_string($declared)) {
            return false;
        }
        $a = realpath($declared);
        $b = realpath($path);
        return false !== $a && false !== $b && $a === $b;
    }

    /**
     * Forget a compiled widget completely: drop the manifest entry AND delete
     * the generated file. Used when a spec is permanently deleted, so a site
     * does not accumulate orphaned generated PHP that nothing will ever load
     * again (see Widget_Registry::purge_on_delete()).
     */
    public static function purge(int $spec_id): bool
    {
        $entry = self::get($spec_id);
        if (null === $entry) {
            return false;
        }
        $path = self::path_for($entry['file']);
        if ('' !== $path && is_file($path) && ! is_link($path)) {
            @unlink($path);
        }
        return self::remove($spec_id);
    }

    /**
     * Restore ONE compiled widget (manifest entry plus generated bytes) to a
     * previously captured state.
     *
     * Rolling back a compile by restoring the whole option is not enough on
     * two counts: it reverts every OTHER widget's entry that was written since
     * the snapshot, and it puts the old hash back against the new bytes, which
     * leaves the widget inert forever with nothing surfaced. Both are fixed by
     * snapshotting and restoring the single entry together with its file.
     *
     * @param array{spec_id:int,entry:?array<string,mixed>,file:string,bytes:?string} $state
     */
    public static function restore(array $state): void
    {
        $spec_id = isset($state['spec_id']) ? (int) $state['spec_id'] : 0;
        if ($spec_id <= 0) {
            return;
        }

        $file = isset($state['file']) && is_string($state['file']) ? $state['file'] : '';
        $path = '' === $file ? '' : self::path_for($file);
        if ('' !== $path && ! is_link($path)) {
            if (isset($state['bytes']) && is_string($state['bytes'])) {
                @file_put_contents($path, $state['bytes']);
            } elseif (is_file($path)) {
                // The compile created this file; the prior state had none.
                @unlink($path);
            }
        }

        $entries = self::read();
        if (isset($state['entry']) && is_array($state['entry'])) {
            $entries[ $spec_id ] = $state['entry'];
        } else {
            unset($entries[ $spec_id ]);
        }
        self::write($entries);
    }

    /**
     * Capture the current state of one compiled widget, for restore().
     *
     * @return array{spec_id:int,entry:?array<string,mixed>,file:string,bytes:?string}
     */
    public static function capture(int $spec_id, string $file): array
    {
        $path  = '' === $file ? '' : self::path_for($file);
        $bytes = ('' !== $path && is_file($path) && ! is_link($path)) ? @file_get_contents($path) : false;

        return [
            'spec_id' => $spec_id,
            'entry'   => self::get($spec_id),
            'file'    => $file,
            'bytes'   => is_string($bytes) ? $bytes : null,
        ];
    }

    /**
     * The compiled state of one spec, in the shape the widget read tools
     * report it.
     *
     * 'stale' is the one an agent actually needs: a compiled class takes
     * precedence over the spec at registration time, so after an
     * update-custom-widget the site keeps rendering the OLD template until a
     * recompile. Without this surfaced, that divergence is invisible from
     * every read tool.
     *
     * @return array<string,mixed>
     */
    public static function status_for(int $spec_id, ?array $spec = null): array
    {
        $entry = self::get($spec_id);
        if (null === $entry) {
            return ['compiled' => false];
        }

        $stale = null;
        if (is_array($spec)) {
            $source = Widget_Compiler::compile($spec, $spec_id);
            $stale  = is_wp_error($source)
                ? true
                : ! hash_equals($entry['hash'], hash('sha256', $source));
        }

        $path = self::path_for($entry['file']);

        return [
            'compiled'        => true,
            'class'           => $entry['class'],
            'file'            => $entry['file'],
            'compiled_hash'   => $entry['hash'],
            'enabled'         => $entry['enabled'],
            'compiled_at'     => $entry['compiled_at'],
            'file_present'    => '' !== $path && is_file($path),
            'loading'         => isset(self::load_enabled()[ $spec_id ]),
            'stale'           => $stale,
        ];
    }

    /** Spec ids that currently have an enabled, hash-matching compiled class. */
    public static function enabled_spec_ids(): array
    {
        return array_keys(self::load_enabled());
    }
}
