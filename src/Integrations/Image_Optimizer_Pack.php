<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\{File_Backup, Rollback_Service, Safe_Mutation, Snapshot_Store};

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Image optimization plugins (issue #693): Smush, ShortPixel, EWWW Image
 * Optimizer and Imagify, as free ops on the theme dispatcher pair (through
 * Ops_Status_Packs), so they add no top-level tools:
 *
 *  - get-image-optimizer-status (read): for each active optimizer its
 *    version, the compression level, automatic optimization on upload,
 *    backups, WebP and AVIF generation, how many library images it has
 *    optimized and how many are pending, whether an API key is set (never
 *    the key), and its quota where the plugin keeps one cached locally
 *    (ShortPixel). Nothing calls the vendor's API.
 *  - optimize-attachment (write): optimize one library image through the
 *    plugin's own API. Off until the site opts in
 *    (wpmcp_enable_image_optimizer_write). Snapshotted like optimize-media:
 *    the attachment row and meta plus every file it owns are backed up first
 *    and the WebP or AVIF copies the plugin may add are recorded, so
 *    rollback-operation puts the original bytes and meta back and deletes
 *    the copies. ShortPixel and Imagify optimize in the background on their
 *    own servers: the call answers status "queued", and an undo still puts
 *    the backed-up originals back, but the plugin's own records (ShortPixel
 *    and EWWW keep them in their own tables) may still call the image
 *    optimized until it is re-checked there.
 *
 * Presence is the plugin's own version constant, filterable per plugin
 * through wpmcp_image_optimizer_active ($active, $slug). Each plugin's
 * single-image call is filterable through wpmcp_image_optimizer_callback
 * ($callable, $slug), a callable(int $attachment_id): array{status: string}.
 *
 * The reads build their answers from allowlisted settings only; API keys,
 * account emails and the vendor's raw quota payload never come back.
 */
final class Image_Optimizer_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_image_optimizer_write';

    /** Slug => [display name, version constant]. */
    private const PLUGINS = [
        'smush'      => [ 'Smush', 'WP_SMUSH_VERSION' ],
        'shortpixel' => [ 'ShortPixel', 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ],
        'ewww'       => [ 'EWWW Image Optimizer', 'EWWW_IMAGE_OPTIMIZER_VERSION' ],
        'imagify'    => [ 'Imagify', 'IMAGIFY_VERSION' ],
    ];

    /** The image types the four plugins optimize in the media library. */
    private const IMAGE_MIMES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => [] !== self::active() ? true : Plugin_Refusal::any_of(
            'image_optimizer_inactive',
            'No supported image optimization plugin is active (Smush, ShortPixel, EWWW Image Optimizer or Imagify).',
            [ 'plugins' => array_column(self::PLUGINS, 0) ]
        );
        $plugin   = [ 'type' => 'string', 'enum' => array_keys(self::PLUGINS) ];
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_image_optimizer_write', false);

        $status             = Ops_Status_Packs::read_op(
            'Image optimization plugin status (Smush, ShortPixel, EWWW Image Optimizer, Imagify): compression level, auto-optimize on upload, backups, WebP and AVIF generation, library images optimized and pending, whether an API key is set, and the locally cached quota. plugin narrows it to one',
            $requires,
            static fn (array $args): array => self::status((string) ($args['plugin'] ?? '')),
            [ 'plugin' => $plugin ],
            [ 'api_key_set' ]
        );
        $status['validate'] = static fn (array $args): ?array => self::plugin_refusal((string) ($args['plugin'] ?? ''));

        return [
            'get-image-optimizer-status' => $status,
            'optimize-attachment'        => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Optimize one media library image through the active image optimization plugin (Smush, ShortPixel, EWWW Image Optimizer or Imagify; name plugin when several are active). ShortPixel and Imagify queue it on their servers (status queued). The attachment, its meta and its files are snapshotted first; undo: rollback-operation restores the original files and deletes new WebP or AVIF copies. Disabled by default (wpmcp_enable_image_optimizer_write filter)',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'media_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'plugin'   => $plugin,
                    ],
                    'required'   => [ 'media_id' ],
                ],
                'objects'            => [ 'media_id' => 'post' ],
                'requires'           => $requires,
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'self_snapshotting'  => true,
                'validate'           => static fn (array $args): ?array => self::validate_optimize($args),
                'handler'            => static fn (array $args, array $context = []): array => self::optimize($args, $context),
            ],
        ];
    }

    /** @return list<string> the slugs of the active optimizers, in table order. */
    public static function active(): array
    {
        $out = [];
        foreach (self::PLUGINS as $slug => [ , $constant ]) {
            if ((bool) apply_filters('wpmcp_image_optimizer_active', defined($constant), $slug)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** The refusal for a named plugin that is not active, or null. */
    private static function plugin_refusal(string $slug): ?array
    {
        if ('' === $slug || in_array($slug, self::active(), true)) {
            return null;
        }
        return Plugin_Refusal::make('image_optimizer_inactive', self::PLUGINS[ $slug ][0]);
    }

    /** @return array<string,mixed> */
    private static function status(string $only): array
    {
        $images = self::library_images();
        $out    = [];
        foreach (self::active() as $slug) {
            if ('' !== $only && $only !== $slug) {
                continue;
            }
            $optimized = self::optimized_count($slug);
            $out[]     = [
                'plugin'      => $slug,
                'name'        => self::PLUGINS[ $slug ][0],
                'version'     => self::version($slug),
                'settings'    => self::settings($slug),
                'api_key_set' => self::api_key_set($slug),
                'library'     => [
                    'images'    => $images,
                    'optimized' => $optimized,
                    'pending'   => null === $optimized ? null : max(0, $images - $optimized),
                ],
                'quota'       => self::quota($slug),
            ];
        }
        return [ 'optimizers' => $out ];
    }

    private static function version(string $slug): ?string
    {
        $constant = self::PLUGINS[ $slug ][1];
        return defined($constant) ? (string) constant($constant) : null;
    }

    /**
     * The allowlisted settings, the same five facts for every plugin:
     * level (the plugin's own name for it), auto_optimize, backup, webp, avif.
     *
     * @return array<string,mixed>
     */
    private static function settings(string $slug): array
    {
        switch ($slug) {
            case 'smush':
                $s      = (array) get_option('wp-smush-settings', []);
                $levels = [ 0 => 'basic', 1 => 'super', 2 => 'ultra' ];
                return [
                    'level'         => $levels[ (int) ($s['lossy'] ?? 0) ] ?? 'basic',
                    'auto_optimize' => (bool) ($s['auto'] ?? true),
                    'backup'        => (bool) ($s['backup'] ?? true),
                    'webp'          => (bool) ($s['webp_mod'] ?? false),
                    'avif'          => (bool) ($s['avif_mod'] ?? false),
                ];
            case 'shortpixel':
                $s      = (array) get_option('spio_settings', []);
                $levels = [ 0 => 'lossless', 1 => 'lossy', 2 => 'glossy' ];
                return [
                    'level'         => $levels[ (int) ($s['compressionType'] ?? 1) ] ?? 'lossy',
                    'auto_optimize' => (bool) ($s['autoMediaLibrary'] ?? true),
                    'backup'        => (bool) ($s['backupImages'] ?? true),
                    'webp'          => (bool) ($s['createWebp'] ?? false),
                    'avif'          => (bool) ($s['createAvif'] ?? false),
                ];
            case 'ewww':
                $backup = self::ewww_option('ewww_image_optimizer_backup_files');
                return [
                    'level'         => (string) self::ewww_option('ewww_image_optimizer_jpg_level'),
                    'auto_optimize' => ! self::ewww_option('ewww_image_optimizer_noauto'),
                    'backup'        => ! empty($backup),
                    'webp'          => (bool) self::ewww_option('ewww_image_optimizer_webp'),
                    'avif'          => false,
                ];
            default:
                $s      = (array) get_option('imagify_settings', []);
                $levels = [ 0 => 'normal', 1 => 'aggressive', 2 => 'smart' ];
                $format = (string) ($s['optimization_format'] ?? '');
                return [
                    'level'         => $levels[ (int) ($s['optimization_level'] ?? 2) ] ?? 'smart',
                    'auto_optimize' => (bool) ($s['auto_optimize'] ?? false),
                    'backup'        => (bool) ($s['backup'] ?? false),
                    'webp'          => ! empty($s['convert_to_webp']) || 'webp' === $format,
                    'avif'          => ! empty($s['convert_to_avif']) || 'avif' === $format,
                ];
        }
    }

    /** An EWWW setting through its own getter (network aware), else the option. */
    private static function ewww_option(string $name)
    {
        return function_exists('ewww_image_optimizer_get_option') ? ewww_image_optimizer_get_option($name) : get_option($name);
    }

    private static function api_key_set(string $slug): bool
    {
        switch ($slug) {
            case 'shortpixel':
                $key = (array) get_option('spio_key', []);
                return ! empty($key['apiKey']);
            case 'ewww':
                return '' !== (string) self::ewww_option('ewww_image_optimizer_cloud_key');
            case 'imagify':
                $s = (array) get_option('imagify_settings', []);
                return '' !== (string) ($s['api_key'] ?? '') || (defined('IMAGIFY_API_KEY') && '' !== (string) constant('IMAGIFY_API_KEY'));
            default:
                return false;
        }
    }

    /**
     * The quota the plugin keeps cached locally, as plain numbers. Only
     * ShortPixel caches one (the quotaData transient, from its own API
     * call); the others answer null rather than calling the vendor.
     *
     * @return array<string,mixed>|null
     */
    private static function quota(string $slug): ?array
    {
        if ('shortpixel' !== $slug) {
            return null;
        }
        $q = get_transient('quotaData');
        if (! is_array($q) || ! isset($q['APICallsQuota'])) {
            return null;
        }
        $monthly  = (int) $q['APICallsQuota'];
        $used     = (int) ($q['APICallsMade'] ?? 0);
        $one_time = (int) ($q['APICallsQuotaOneTime'] ?? 0);
        $one_used = (int) ($q['APICallsMadeOneTime'] ?? 0);
        return [
            'unlimited'      => ! empty($q['Unlimited']),
            'monthly_total'  => $monthly,
            'monthly_used'   => $used,
            'one_time_total' => $one_time,
            'one_time_used'  => $one_used,
            'remaining'      => max(0, $monthly - $used) + max(0, $one_time - $one_used),
        ];
    }

    private static function library_images(): int
    {
        global $wpdb;
        $in = implode(',', array_fill(0, count(self::IMAGE_MIMES), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($in)", self::IMAGE_MIMES));
    }

    /** How many library images the plugin has optimized, or null when its records are not there. */
    private static function optimized_count(string $slug): ?int
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb.
        switch ($slug) {
            case 'smush':
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", 'wp-smpro-smush-data'));
            case 'imagify':
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ('success', 'already_optimized')", '_imagify_status'));
            case 'shortpixel':
                $table = $wpdb->prefix . 'shortpixel_postmeta';
                // status 2 is ImageModel::FILE_STATUS_SUCCESS, image_type 0 the main file.
                return Ops_Status_Packs::table_exists($table) ? (int) $wpdb->get_var("SELECT COUNT(DISTINCT attach_id) FROM {$table} WHERE status = 2 AND image_type = 0") : null;
            default:
                $table = $wpdb->prefix . 'ewwwio_images';
                return Ops_Status_Packs::table_exists($table) ? (int) $wpdb->get_var("SELECT COUNT(DISTINCT attachment_id) FROM {$table} WHERE gallery = 'media' AND pending = 0") : null;
        }
        // phpcs:enable
    }

    /** The plugin an optimize call runs through, or the refusal. @return string|array */
    private static function pick(array $args)
    {
        $slug = (string) ($args['plugin'] ?? '');
        if ('' !== $slug) {
            return self::plugin_refusal($slug) ?? $slug;
        }
        $active = self::active();
        if (1 === count($active)) {
            return $active[0];
        }
        return [
            'code'    => 'image_optimizer_ambiguous',
            'message' => sprintf('Several image optimization plugins are active (%s). Pass plugin to choose one.', implode(', ', $active)),
            'data'    => [ 'active' => $active ],
        ];
    }

    private static function validate_optimize(array $args): ?array
    {
        $picked = self::pick($args);
        if (is_array($picked)) {
            return $picked;
        }
        $id   = (int) $args['media_id'];
        $post = get_post($id);
        if (! $post || 'attachment' !== $post->post_type) {
            return [ 'code' => 'media_not_found', 'message' => sprintf('No media library item has id %d.', $id) ];
        }
        if (! in_array((string) $post->post_mime_type, self::IMAGE_MIMES, true)) {
            return [ 'code' => 'not_an_image', 'message' => sprintf('Media %d is %s; the optimizers handle JPEG, PNG, GIF and WebP images.', $id, (string) $post->post_mime_type) ];
        }
        $main = (string) get_attached_file($id);
        if ('' === $main || ! is_file($main)) {
            return [ 'code' => 'media_file_missing', 'message' => sprintf('The file of media %d is missing on disk, so there is nothing to optimize.', $id) ];
        }
        if (! is_callable(self::callback($picked))) {
            return [ 'code' => 'image_optimizer_unavailable', 'message' => sprintf('%s is active but its optimization API is not available.', self::PLUGINS[ $picked ][0]) ];
        }
        return null;
    }

    /** @return array{result: array<string,mixed>, operation_ids: list<string>} */
    private static function optimize(array $args, array $context): array
    {
        $slug = (string) self::pick($args);
        $id   = (int) $args['media_id'];

        $operation_id = wp_generate_uuid4();
        $backup       = File_Backup::collect_attachment_files($id);
        $manifest     = File_Backup::backup($operation_id, $backup);
        if (count($manifest) < count($backup)) {
            File_Backup::delete_backup_dir($operation_id);
            throw new Operation_Refused('backup_failed', 'Could not back up the original files, so nothing was changed.');
        }

        try {
            $run = Safe_Mutation::run(
                [
                    'object_type'         => 'post',
                    'object_id'           => $id,
                    'session_id'          => (string) ($context['session_id'] ?? 'default'),
                    'tool_name'           => (string) ($context['tool_name'] ?? 'theme-write'),
                    'args'                => [ 'operation' => 'optimize-attachment', 'args' => $args ],
                    'operation_id'        => $operation_id,
                    'extra_snapshot_data' => [ 'files' => [ 'operation_id' => $operation_id, 'manifest' => $manifest, 'created' => self::next_gen_candidates($backup) ] ],
                ],
                static fn () => self::call($slug, $id)
            );
        } catch (\Throwable $e) {
            // Put everything back (row, meta, files) and drop the undo point.
            try {
                Rollback_Service::restore_operation($operation_id);
            } catch (\Throwable $restore_error) {
                File_Backup::restore($operation_id, $manifest);
            }
            Snapshot_Store::delete_operation($operation_id);
            if ($e instanceof Operation_Refused) {
                throw $e;
            }
            throw new Operation_Refused('image_optimizer_failed', esc_html(sprintf('%s could not optimize media %d: %s', self::PLUGINS[ $slug ][0], $id, $e->getMessage())));
        }

        return [
            'result'        => [ 'plugin' => $slug, 'media_id' => $id ] + $run['result'],
            'operation_ids' => [ $run['operation_id'] ],
        ];
    }

    /** @return array{status: string, message?: string} */
    private static function call(string $slug, int $id): array
    {
        $out = call_user_func(self::callback($slug), $id);
        if (is_wp_error($out)) {
            throw new Operation_Refused('image_optimizer_failed', esc_html(sprintf('%s: %s', self::PLUGINS[ $slug ][0], $out->get_error_message())));
        }
        $status = is_array($out) && isset($out['status']) ? (string) $out['status'] : 'optimized';
        return [ 'status' => $status ] + (is_array($out) && isset($out['message']) ? [ 'message' => (string) $out['message'] ] : []);
    }

    /**
     * WebP and AVIF copies a plugin may write next to each file (file.jpg.webp
     * and file.webp), plus Smush's mirror under uploads/smush-webp, that do
     * not exist yet: only those, so an undo never deletes a file something
     * else put there.
     *
     * @param string[] $files
     * @return list<string>
     */
    private static function next_gen_candidates(array $files): array
    {
        $uploads = wp_get_upload_dir();
        $base    = trailingslashit((string) $uploads['basedir']);
        $out     = [];
        foreach ($files as $path) {
            $stem = (string) preg_replace('/\.[^.\/]+$/', '', $path);
            foreach ([ 'webp', 'avif' ] as $ext) {
                $out[] = $path . '.' . $ext;
                $out[] = $stem . '.' . $ext;
                if (0 === strpos($path, $base)) {
                    $out[] = $base . 'smush-' . $ext . '/' . substr($path, strlen($base)) . '.' . $ext;
                }
            }
        }
        return array_values(array_filter(array_unique($out), static fn (string $p): bool => ! in_array($p, $files, true) && ! file_exists($p)));
    }

    /** @return mixed the plugin's single-image call, or null when its API is missing. */
    private static function callback(string $slug)
    {
        return apply_filters('wpmcp_image_optimizer_callback', self::native_callback($slug), $slug);
    }

    /** @return callable|null */
    private static function native_callback(string $slug)
    {
        switch ($slug) {
            case 'smush':
                if (! class_exists('\Smush\Core\Optimizer')) {
                    return null;
                }
                return static function (int $id): array {
                    $optimizer = \Smush\Core\Optimizer::get_instance();
                    if (! $optimizer->optimize($id)) {
                        $errors = $optimizer->get_errors();
                        $reason = is_wp_error($errors) && $errors->has_errors() ? $errors->get_error_message() : 'the image was not optimized.';
                        throw new Operation_Refused('image_optimizer_failed', esc_html('Smush: ' . $reason));
                    }
                    return [ 'status' => 'optimized' ];
                };
            case 'ewww':
                if (! function_exists('ewww_image_optimizer_resize_from_meta_data')) {
                    return null;
                }
                return static function (int $id): array {
                    if (function_exists('ewwwio')) {
                        ewwwio()->defer = false;
                    }
                    $meta = ewww_image_optimizer_resize_from_meta_data(wp_get_attachment_metadata($id), $id);
                    if (is_array($meta)) {
                        wp_update_attachment_metadata($id, $meta);
                    }
                    return [ 'status' => 'optimized' ];
                };
            case 'shortpixel':
                if (! function_exists('wpSPIO') || ! class_exists('\ShortPixel\Controller\QueueController')) {
                    return null;
                }
                return static function (int $id): array {
                    $image = \wpSPIO()->filesystem()->getMediaImage($id);
                    if (! is_object($image)) {
                        throw new Operation_Refused('image_optimizer_failed', 'ShortPixel could not load this image.');
                    }
                    $result = (new \ShortPixel\Controller\QueueController())->addItemToQueue($image);
                    if (is_object($result) && ! empty($result->is_error)) {
                        throw new Operation_Refused('image_optimizer_failed', esc_html('ShortPixel: ' . wp_strip_all_tags((string) ($result->message ?? 'the image was not queued.'))));
                    }
                    return [ 'status' => 'queued' ];
                };
            default:
                if (! function_exists('imagify_get_optimization_process')) {
                    return null;
                }
                return static function (int $id): array {
                    $result = imagify_get_optimization_process($id, 'wp')->optimize();
                    if (is_wp_error($result)) {
                        throw new Operation_Refused('image_optimizer_failed', esc_html('Imagify: ' . $result->get_error_message()));
                    }
                    return [ 'status' => 'queued' ];
                };
        }
    }
}
