<?php

namespace WPMCP\Tools\Media;

use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Optimize the images the tools upload (issue #432), and the two
 * optimize-media settings.
 *
 * With the wpmcp_optimize_uploads option on (off by default), a JPEG or PNG
 * that a wpmcp tool call adds to the Media Library gets optimize-media's
 * recompression (default quality) and a WebP copy when the tool call
 * finishes, under the call's own session_id, so rolling the upload's
 * session back undoes both. An upload from anywhere else (the media
 * screen, another plugin, a cron job) is never touched: the attachment is
 * only picked up while Registrar reports a tool call running, and only
 * handled by that same call. An active optimizer plugin still wins, as it
 * does for optimize-media itself. The tool's result gains an "optimized"
 * entry (one item, or a list for a call that added several) saying what
 * was done.
 *
 * Both settings are written and read through update-settings and
 * get-settings (media group), added to their allowlist here so they exist
 * only where the feature does.
 *
 * Deleting an attachment also deletes the WebP/AVIF copies optimize-media
 * recorded for it, which core does not know about and would leave behind.
 * That cleanup runs on every install, licensed or not: files this plugin
 * wrote must not outlive their attachment.
 */
class Optimize_Uploads
{
    public const OPTION          = 'wpmcp_optimize_uploads';
    public const DELIVERY_OPTION = 'wpmcp_serve_modern_images';

    /** @var array<int,int[]> tool call id => attachment ids it added */
    private static array $pending = [];

    public static function register(): void
    {
        add_action('add_attachment', [ self::class, 'note_attachment' ]);
        add_filter('wpmcp_tool_result', [ self::class, 'optimize_added' ], 10, 3);
        add_action('delete_attachment', [ self::class, 'delete_copies' ]);
        add_filter('wpmcp_settings_registry', [ self::class, 'settings' ]);
    }

    /** @internal add_attachment callback */
    public static function note_attachment($id): void
    {
        $call = Registrar::current_call();
        if (0 === $call) {
            return;
        }
        // Only the running call's list is kept, so nothing an earlier call
        // left (one that threw) is picked up by a later one.
        self::$pending = [ $call => array_merge(self::$pending[ $call ] ?? [], [ (int) $id ]) ];
    }

    /**
     * @internal wpmcp_tool_result callback
     *
     * @param mixed $result
     * @return mixed
     */
    public static function optimize_added($result, string $name = '', array $input = [])
    {
        $ids           = self::$pending[ Registrar::current_call() ] ?? [];
        self::$pending = [];
        if ([] === $ids || ! self::enabled(self::OPTION)) {
            return $result;
        }

        $done = [];
        foreach (array_unique($ids) as $id) {
            if (! in_array((string) get_post_mime_type($id), [ 'image/jpeg', 'image/png' ], true)) {
                continue;
            }
            try {
                $out = (new Optimize_Media())->handle([
                    'media_id'   => $id,
                    'formats'    => (array) apply_filters('wpmcp_optimize_uploads_formats', [ 'webp' ]),
                    'session_id' => (string) ($input['session_id'] ?? 'default'),
                ]);
            } catch (\Throwable $e) {
                $done[] = [ 'media_id' => $id, 'error' => $e->getMessage() ];
                continue;
            }
            if (isset($out['items'][0])) {
                $done[] = $out['items'][0];
            }
        }

        if ([] !== $done && is_array($result) && ! array_is_list($result)) {
            $result['optimized'] = 1 === count($done) ? $done[0] : $done;
        }
        return $result;
    }

    /** @internal delete_attachment callback */
    public static function delete_copies($id): void
    {
        $record = get_post_meta((int) $id, Optimize_Media::META_KEY, true);
        $main   = (string) get_attached_file((int) $id);
        if (! is_array($record) || '' === $main) {
            return;
        }
        $dir = trailingslashit(dirname($main));
        foreach ((array) ($record['formats'] ?? []) as $files) {
            foreach ((array) $files as $file) {
                $path = $dir . basename((string) $file);
                if (is_file($path)) {
                    wp_delete_file($path);
                }
            }
        }
    }

    /**
     * @internal wpmcp_settings_registry callback
     *
     * @param array<string,array> $schema
     * @return array<string,array>
     */
    public static function settings(array $schema): array
    {
        if (! Gate::is_pro()) {
            return $schema;
        }
        $schema[ self::OPTION ]          = [ 'group' => 'media', 'type' => 'bool', 'writable' => true ];
        $schema[ self::DELIVERY_OPTION ] = [ 'group' => 'media', 'type' => 'bool', 'writable' => true ];
        return $schema;
    }

    /** Whether an off-by-default feature option is on, on a licensed install. */
    public static function enabled(string $option): bool
    {
        return (bool) get_option($option, false) && Gate::is_pro();
    }
}
