<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handler for compile-custom-widget (issue #72): compile a stored widget spec
 * into a real PHP widget class inside the manifest-loaded sandbox.
 *
 * This is the only tool in the plugin that writes executable PHP, so it is
 * gated like one, not like a content tool:
 *
 *  1. PRO via Pro\Gate.
 *  2. OFF by default: a site must opt in with the wpmcp_enable_widget_compiler
 *     filter, the same shape as wpmcp_enable_fs_writes for the filesystem
 *     tools.
 *  3. edit_files capability + DISALLOW_FILE_EDIT, via Filesystem_Guard, so a
 *     site that has turned off file editing cannot be made to compile PHP.
 *
 * The write itself is lint-then-write-then-record: Generated_Code_Lint must
 * pass before a byte reaches disk, the file is written to a temp name and
 * renamed into place so a half-written file is never loadable, and the
 * manifest entry (hash + class + enabled) is recorded through Safe_Mutation,
 * with the previous entry and the previous file bytes attached to the
 * snapshot, so the compile is an operation in history and undoing it restores
 * the previous widget (not merely the previous manifest row).
 *
 * Gates 1 and 2 also guard the EXECUTION site, not just this write: see
 * Compiled_Widget_Manifest::execution_allowed(). Turning the opt-in filter off
 * makes already-compiled files inert.
 */
class Compile_Custom_Widget
{
    /** Off by default; writing PHP is opt-in per site. */
    public static function is_enabled(): bool
    {
        /** Filter: allow compile-custom-widget to write generated PHP. */
        return (bool) apply_filters('wpmcp_enable_widget_compiler', false);
    }

    /**
     * @return array|\WP_Error
     */
    public function handle(array $args)
    {
        if (! Gate::can_use('compile-custom-widget')) {
            throw new \RuntimeException('Spec-compiled widgets are a PRO feature.');
        }
        if (! self::is_enabled()) {
            throw new \RuntimeException('The widget compiler is disabled. Enable it with the wpmcp_enable_widget_compiler filter.');
        }

        $gate = Filesystem_Guard::writes_allowed();
        if (is_wp_error($gate)) {
            return $gate;
        }

        $widget_id = absint($args['widget_id'] ?? 0);
        $spec      = Widget_Spec_Store::get($widget_id);
        if (null === $spec) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$widget_id}.");
        }
        if ('publish' !== get_post_status($widget_id)) {
            // A draft is a widget the user explicitly disabled, and a trashed
            // one is a widget they deleted; neither should gain a live
            // compiled class. Re-publish it first (set-widget-status).
            return new \WP_Error(
                'widget_not_published',
                "Custom widget {$widget_id} is not published; publish it before compiling."
            );
        }

        $source = Widget_Compiler::compile($spec, $widget_id);
        if (is_wp_error($source)) {
            return $source;
        }

        $lint = Generated_Code_Lint::check($source);
        if (is_wp_error($lint)) {
            // A lint failure is a generator bug, not user input: write nothing.
            return $lint;
        }

        $class = Widget_Compiler::class_name_for($widget_id, (string) ($spec['name'] ?? ''));
        if (is_wp_error($class)) {
            return $class;
        }
        $file = Widget_Compiler::file_name_for($widget_id);

        if (! Compiled_Widget_Manifest::protect()) {
            return new \WP_Error('wpmcp_widget_sandbox_unwritable', 'Could not create the compiled-widget sandbox directory.');
        }

        $path = Compiled_Widget_Manifest::path_for($file);
        if ('' === $path) {
            return new \WP_Error('wpmcp_widget_sandbox_unwritable', 'Refusing to write outside the compiled-widget sandbox.');
        }
        if (is_link($path)) {
            return new \WP_Error('wpmcp_widget_sandbox_unwritable', 'Refusing to write a compiled widget through a symlink.');
        }

        // Capture the entry AND the bytes it vouches for BEFORE the rename
        // overwrites them. A recompile replaces widget-<id>.php in place, so
        // without this the previous good file is simply gone: an undo would
        // restore the old hash against the new bytes (widget silently inert),
        // and the failure path below would delete a file the surviving
        // manifest entry still points at.
        $previous = Compiled_Widget_Manifest::capture($widget_id, $file);

        $written = self::write_atomic($path, $source);
        if (is_wp_error($written)) {
            return $written;
        }

        $entry = [
            'spec_id'     => $widget_id,
            'name'        => (string) ($spec['name'] ?? ''),
            'class'       => $class,
            'file'        => $file,
            'hash'        => hash('sha256', $source),
            'enabled'     => true,
            'compiled_at' => gmdate('c'),
        ];

        $recorded = null;
        $out      = Safe_Mutation::run(
            [
                'object_type'         => 'option',
                'object_id'           => Compiled_Widget_Manifest::OPTION,
                'session_id'          => 'default',
                'tool_name'           => 'compile-custom-widget',
                'args'                => ['widget_id' => $widget_id],
                // Undo this compile only, bytes and hash together. Restoring
                // the whole option would also revert every other widget
                // compiled since the snapshot.
                'extra_snapshot_data' => ['compiled_widget' => $previous],
            ],
            static function () use ($entry, &$recorded): void {
                $recorded = Compiled_Widget_Manifest::put($entry);
            }
        );

        if (is_wp_error($recorded)) {
            // Put back exactly what was there. Unlinking unconditionally
            // would destroy a working compiled widget whose manifest entry
            // survived this failed recompile.
            Compiled_Widget_Manifest::restore($previous);
            return $recorded;
        }

        Filesystem_Guard::log('compile-widget', 'wp-content/' . Compiled_Widget_Manifest::DIR_NAME . '/' . $file);

        return [
            'compiled'     => true,
            'widget_id'    => $widget_id,
            'class'        => $class,
            'file'         => $file,
            'hash'         => $entry['hash'],
            'enabled'      => true,
            'bytes'        => strlen($source),
            'operation_id' => $out['operation_id'],
        ];
    }

    /**
     * Write via a temp file plus rename, so the loader never sees a
     * half-written class: a compile interrupted BEFORE the rename leaves the
     * previous file, which still matches the previous manifest hash, intact.
     *
     * After the rename the previous bytes are gone, so crash-safety past that
     * point is not the rename's doing: it comes from the capture the caller
     * takes first, which both the failure path and the undo restore from.
     *
     * @return true|\WP_Error
     */
    private static function write_atomic(string $path, string $source)
    {
        $tmp = $path . '.' . wp_generate_password(8, false) . '.tmp';
        $ok  = file_put_contents($tmp, $source);
        if (false === $ok || $ok !== strlen($source)) {
            @unlink($tmp);
            return new \WP_Error('wpmcp_widget_sandbox_unwritable', 'Could not write the compiled widget file.');
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            return new \WP_Error('wpmcp_widget_sandbox_unwritable', 'Could not move the compiled widget into the sandbox.');
        }
        return true;
    }
}
