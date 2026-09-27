<?php

namespace WPMCP\Tools\Export;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Generate a WordPress eXtended RSS (WXR) export of site content using the
 * native WordPress exporter (wp-admin/includes/export.php's export_wp()).
 * export_wp() was written for the one-shot "Tools > Export" browser download,
 * not for returning a string: it echoes the XML directly and unconditionally
 * sends its own Content-Type and Content-Disposition headers. Both of those
 * have to be undone here, and they need different treatment depending on how
 * the tool is reached:
 *
 * - Under a live REST dispatch of wpmcp/export-content, ob_start() does not
 *   make headers_sent() true, so export_wp()'s header() calls actually
 *   succeed and would stamp "text/xml" plus an attachment disposition onto
 *   the MCP JSON response. That is the damaging case, and no amount of
 *   warning suppression addresses it, so the response headers are snapshotted
 *   before the export and restored afterwards.
 * - Under WP-CLI/tests, or any request that has already flushed output,
 *   header() instead raises an E_WARNING that would land in the captured
 *   buffer. A deliberately narrow error handler swallows exactly that one
 *   message and hands everything else to whichever handler was installed
 *   before it (a monitoring plugin's, PHPUnit's) or, when there was none, to
 *   PHP's built-in handling, so unrelated warnings raised by third-party
 *   callbacks on export_wp()'s hooks (export_args, export_wp,
 *   the_content_export, wxr_export_skip_postmeta) are neither swallowed nor
 *   diverted around the site's own error handling.
 *
 * This does not mutate the site: it only reads posts/terms/comments and
 * writes a new file under uploads.
 *
 * export_wp() declares several helper functions (wxr_cdata(), etc.) inside
 * its own body with no function_exists() guard, so WordPress core itself
 * only supports calling it once per PHP process; a second call fatals with
 * "cannot redeclare function". A real WordPress request is one process per
 * request, so this is normally invisible, but a long-lived process (WP-CLI,
 * a persistent worker) could hit it. Track that with a static flag and fail
 * with a clear, actionable message instead of letting the raw fatal take
 * down the whole process.
 *
 * The export step itself is a constructor-injected callable defaulting to
 * export_wp(). As in Run_Backup_Job, this is not merely a test convenience:
 * the once-per-process budget above is unresettable, so the buffer, error
 * handler and header bookkeeping around the call can only be regression
 * tested through a stand-in. The process guard therefore applies only to the
 * default (core) exporter; an injected one never touches export_wp().
 */
class Export_Content
{
    /**
     * Upper bound on the handler/buffer unwind loops below. Both walk stacks
     * that hostile or buggy third-party code controls the depth of, and a
     * stack frame that refuses to pop would otherwise spin forever and hang
     * the request. Far above any plausible real nesting depth.
     */
    private const UNWIND_LIMIT = 32;

    private static bool $has_run = false;

    /** @var callable(array): void */
    private $exporter;

    private bool $uses_core_exporter;

    public function __construct(?callable $exporter = null)
    {
        $this->uses_core_exporter = null === $exporter;
        $this->exporter           = $exporter ?? static function (array $export_args): void {
            if (! function_exists('export_wp')) {
                require_once ABSPATH . 'wp-admin/includes/export.php';
            }
            export_wp($export_args);
        };
    }

    public function handle(array $args): array
    {
        if ($this->uses_core_exporter) {
            if (self::$has_run) {
                throw new \RuntimeException('export-content can only run once per PHP process: WordPress\'s own export_wp() cannot be safely called twice in the same process. Run this tool again in a fresh request.');
            }
            self::$has_run = true;
        }

        $export_args = ['content' => 'all'];
        if (! empty($args['content'])) {
            $export_args['content'] = sanitize_key((string) $args['content']);
        }
        if (! empty($args['author'])) {
            $export_args['author'] = (int) $args['author'];
        }
        if (! empty($args['start_date'])) {
            $export_args['start_date'] = (string) $args['start_date'];
        }
        if (! empty($args['end_date'])) {
            $export_args['end_date'] = (string) $args['end_date'];
        }
        if (! empty($args['status'])) {
            $export_args['status'] = sanitize_key((string) $args['status']);
        }

        $xml = $this->capture_export($export_args);

        $dir = Export_Dir::path();
        Export_Dir::protect($dir);

        $filename = 'wpmcp-export-' . gmdate('Y-m-d-His') . '-' . substr(wp_generate_uuid4(), 0, 8) . '.xml';
        $path     = trailingslashit($dir) . $filename;

        if (false === file_put_contents($path, $xml)) {
            throw new \RuntimeException(sprintf('Failed to write the export file to %s.', esc_html($path)));
        }

        return [
            'file'       => $path,
            'size'       => filesize($path),
            'item_count' => substr_count($xml, '<item>'),
        ];
    }

    /**
     * Run the exporter with the buffer, error handler and response headers
     * restored on every exit path, and return the WXR it echoed.
     */
    private function capture_export(array $export_args): string
    {
        // Snapshot the response headers so export_wp()'s Content-Type and
        // Content-Disposition can be rolled back afterwards. headers_sent()
        // is the guard because header_remove() itself warns once output has
        // been flushed (in which case export_wp()'s header() calls are no-ops
        // anyway and there is nothing to roll back).
        $headers_mutable  = ! headers_sent();
        $headers_snapshot = $headers_mutable ? headers_list() : [];

        // The handler installed before ours, captured by reference so the
        // closure can chain to it: set_error_handler() replaces rather than
        // stacks, and a handler that returns false hands the error to PHP's
        // built-in handling, not to the previous user handler. Under
        // display_errors that built-in handling prints "Warning: ..." into
        // whatever output buffer is open, which here is the export capture.
        $previous = null;

        $suppressor = static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previous): bool {
            // Narrow on purpose: claim only the warning export_wp()'s own
            // header() calls raise. Everything else goes to the handler that
            // was installed before this one, so a monitoring plugin still
            // sees warnings from third-party export hooks; with no previous
            // handler, false defers to PHP's normal handling and error log.
            if (false !== strpos($errstr, 'Cannot modify header information')) {
                return true;
            }

            return null !== $previous && false !== $previous($errno, $errstr, $errfile, $errline);
        };

        $ob_level = ob_get_level();
        ob_start();
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- installed only for the exporter call below, matches a single core warning string and chains everything else to the previous handler, and is popped by unwind_error_handler() in the finally block.
        $previous = set_error_handler($suppressor, E_WARNING);

        try {
            ($this->exporter)($export_args);

            // Drain anything a hook opened above this method's own buffer
            // first, so the capture below reads the buffer this method opened
            // rather than a stray one (which would silently write the wrong
            // payload out as the export).
            self::drain_buffers_to($ob_level + 1);

            // If a hook closed this method's buffer instead, ob_get_clean()
            // would read and remove the caller's buffer (the REST transport's
            // or PHPUnit's) as the export payload. Fail loudly instead.
            if (ob_get_level() !== $ob_level + 1) {
                throw new \RuntimeException('The export produced no WXR output. A plugin hooked into export_wp() closed an output buffer it did not open.');
            }

            $xml = ob_get_clean();
        } finally {
            self::unwind_error_handler($suppressor, $previous);
            self::drain_buffers_to($ob_level);

            if ($headers_mutable && ! headers_sent()) {
                header_remove();
                foreach ($headers_snapshot as $header) {
                    header($header, false);
                }
            }
        }

        // ob_get_clean() returns false when the buffer could not be removed
        // (a hook opened a non-cleanable one). Writing that out as an empty
        // file and reporting size 0 as a successful export hides a real
        // failure, so raise instead.
        if (! is_string($xml) || false === strpos($xml, '<rss')) {
            throw new \RuntimeException('The export produced no WXR output. A plugin hooked into export_wp() may have interfered with the export or the output buffer.');
        }

        return $xml;
    }

    /**
     * Pop error handlers until the one this class installed has been removed,
     * without touching anything that was installed before it.
     *
     * restore_error_handler() pops whatever is on top of the stack, not a
     * specific handler, so a single call is wrong in both directions: if code
     * running inside the exporter installed a handler and never restored it,
     * one pop removes that handler and leaves the suppressor live for the
     * rest of the request; if that code called restore_error_handler() once
     * too often, the suppressor is already gone and one pop removes a handler
     * that was there before this class ran (a monitoring plugin's). Probe the
     * top of the stack before each pop: stop after popping the suppressor,
     * stop without popping once the frame beneath it ($previous) is on top,
     * and only ever pop frames that were pushed above ours. Bounded so a
     * pathological stack cannot spin.
     *
     * $previous is the return value of the set_error_handler() call that
     * installed $suppressor. When it is null, a null on top of the stack is
     * ambiguous: either the stack is empty (the exporter over-popped) or the
     * exporter left a set_error_handler(null) frame above the suppressor. That
     * case is settled by popping the null and looking one frame further down;
     * whatever is found there is put back exactly as it was.
     *
     * Known limit: PHP offers no way to read the stack without popping it, so
     * a hook that pops two or more frames beneath ours (removing $previous
     * itself) leaves whatever is under it indistinguishable from leftovers,
     * and the walk pops those too. That hook has already destroyed the
     * handler that was installed before this class ran; nothing here can
     * undo that.
     */
    private static function unwind_error_handler(callable $suppressor, ?callable $previous): void
    {
        for ($i = 0; $i < self::UNWIND_LIMIT; $i++) {
            // set_error_handler(null) returns the handler currently on top
            // and pushes a frame; restore_error_handler() pops that probe
            // frame straight back off, leaving the stack as it was.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- read-only probe: PHP exposes no getter for the installed handler, and the frame this pushes is popped on the next line.
            $top = set_error_handler(null);
            restore_error_handler();

            if ($top === $suppressor) {
                restore_error_handler();
                return;
            }

            if (null !== $top && $top === $previous) {
                // The frame beneath ours is already on top: inner code popped
                // the suppressor itself. Nothing of ours is left to remove and
                // everything below belongs to whoever installed it.
                return;
            }

            if (null === $top && null === $previous) {
                self::settle_null_frame($suppressor);
                return;
            }

            // Either a handler pushed inside the exporter and never restored,
            // or a null frame pushed above ours while $previous is non-null
            // (so it cannot be the stack bottom). Pop it and keep walking.
            restore_error_handler();
        }
    }

    /**
     * Resolve the ambiguous case of a null handler on top of the stack when the
     * suppressor was installed over a null handler: pop the null and look at
     * the frame beneath. If it is the suppressor, pop that too. Otherwise the
     * probe frame pushed by the look is itself a null frame in the position
     * the popped one occupied, so leaving it restores the stack exactly.
     */
    private static function settle_null_frame(callable $suppressor): void
    {
        restore_error_handler();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- read-only probe, see unwind_error_handler(); the frame it pushes either replaces the null frame popped above or is popped again below.
        $below = set_error_handler(null);

        if ($below === $suppressor) {
            restore_error_handler();
            restore_error_handler();
            return;
        }

        if (null === $below) {
            // Empty stack, or another null frame: the pop above changed
            // nothing observable, and neither should the probe.
            restore_error_handler();
        }
    }

    /**
     * Close output buffers until the level is back down to $target.
     *
     * ob_end_clean() returns false without decrementing the level when the
     * buffer on top is not removable, so an unchecked
     * `while (ob_get_level() > $target)` loop hangs the request instead of
     * reporting the failed export. Honour the return value and cap the loop.
     */
    private static function drain_buffers_to(int $target): void
    {
        for ($i = 0; $i < self::UNWIND_LIMIT && ob_get_level() > $target; $i++) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ob_end_clean() notices on a non-removable buffer are exactly the failure this loop reports through its return value; on the finally path the suppressor is already gone, so the notice would otherwise surface to the caller.
            if (! @ob_end_clean()) {
                return;
            }
        }
    }
}
