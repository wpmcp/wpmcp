<?php

namespace WPMCP\Tools\Media;

use WPMCP\Safety\{File_Backup, Safe_Mutation};

if (! defined('ABSPATH')) {
    exit;
}

/**
 * optimize-media (issue #380): recompress JPEG and PNG attachments at a
 * chosen quality, optionally cap the longest edge of the full-size file, and
 * write WebP and AVIF copies beside the full size and every sub-size.
 *
 * Everything runs through the server's own image editor (WP_Image_Editor,
 * Imagick or GD); no image leaves the site. A format the editor cannot write
 * is reported with a reason instead of failing the call. A recompressed file
 * only replaces the original when it is smaller, so nothing gets heavier.
 *
 * Each attachment is its own Safe_Mutation: the attachment's files are
 * backed up through File_Backup first and the snapshot records the copies
 * about to be written, so rollback-operation puts the original bytes and
 * metadata back and deletes the WebP/AVIF files. A batch shares a session id,
 * so rollback-session undoes all of it.
 *
 * Without media_id the library is walked in id order, a capped number of
 * images per call and within a time budget (at least one image is always
 * handled, so repeated calls always finish), handing back next_cursor.
 *
 * When an image-optimization plugin is active the tool defers to it unless
 * force is passed: two optimizers fighting over the same files would each
 * recompress the other's output.
 *
 * background:true (issue #432) queues the library walk as a WP-Cron job
 * instead (Optimize_Media_Job) with progress read back by job_id; the
 * optional upload and front-end features live in Optimize_Uploads and
 * Modern_Image_Delivery.
 */
class Optimize_Media
{
    /** Post meta recording what was done, so a batch can skip finished images. */
    public const META_KEY = '_wpmcp_optimized';

    private const DEFAULT_QUALITY = 82;
    private const BATCH_SIZE      = 10;
    private const TIME_BUDGET     = 20.0;
    private const SOURCE_MIMES    = ['image/jpeg', 'image/png'];

    /** Format argument => [mime type, label used in messages]. */
    private const FORMATS = [
        'webp' => ['image/webp', 'WebP'],
        'avif' => ['image/avif', 'AVIF'],
    ];

    private const OPTIMIZERS_TRANSIENT = 'wpmcp_image_optimizer_scan';

    /** @var array<string,string[]> active-plugins key => optimizer names, for this request */
    private static array $optimizers_cache = [];

    private int $quality = self::DEFAULT_QUALITY;

    public function handle(array $args): array
    {
        // A background run's status, or its cancellation (issue #432).
        $job_id = (int) ($args['job_id'] ?? 0);
        if ($job_id > 0) {
            return empty($args['cancel']) ? Optimize_Media_Job::read($job_id) : Optimize_Media_Job::cancel($job_id);
        }

        $this->quality = max(1, min(100, (int) ($args['quality'] ?? self::DEFAULT_QUALITY)));
        $max_edge      = max(0, (int) ($args['max_edge'] ?? 0));
        $formats       = self::formats($args['formats'] ?? []);
        $dry_run       = ! empty($args['dry_run']);
        $force         = ! empty($args['force']);
        $session_id    = (string) ($args['session_id'] ?? 'default');
        $media_id      = (int) ($args['media_id'] ?? 0);

        if (! empty($args['background'])) {
            if ($media_id) {
                throw new \InvalidArgumentException('background runs over the whole library; drop media_id, or drop background to optimize one image now.');
            }
            return $this->queue($max_edge, $formats, $dry_run, $force, $session_id);
        }

        if ($media_id) {
            $post = get_post($media_id);
            if (! $post || 'attachment' !== $post->post_type) {
                throw new \InvalidArgumentException('Media not found');
            }
            if (! in_array((string) $post->post_mime_type, self::SOURCE_MIMES, true)) {
                throw new \InvalidArgumentException('optimize-media works on JPEG and PNG images only.');
            }
        }

        $unsupported = self::unsupported($formats);
        $out         = [
            'dry_run'     => $dry_run,
            'items'       => [],
            'unsupported' => $unsupported,
            'totals'      => [ 'before_bytes' => 0, 'after_bytes' => 0, 'saved_bytes' => 0, 'generated_bytes' => 0 ],
            'next_cursor' => null,
        ];

        $optimizers = self::active_optimizers();
        if ([] !== $optimizers && ! $force) {
            $out['deferred_to'] = $optimizers;
            $out['reason']      = 'An active image-optimization plugin already handles this. Pass force:true to run anyway.';
            if (! $dry_run) {
                return $out;
            }
        }

        $cursor = max(0, (int) ($args['cursor'] ?? 0));
        $ids    = $media_id ? [ $media_id ] : self::batch($cursor, max(1, (int) apply_filters('wpmcp_optimize_media_batch_size', self::BATCH_SIZE)));
        $budget = (float) apply_filters('wpmcp_optimize_media_time_budget', self::TIME_BUDGET);

        $writable = array_values(array_diff($formats, array_keys($unsupported)));
        $started  = microtime(true);
        $last_id  = $cursor;

        $this->hook_editor_filters();
        try {
            foreach ($ids as $index => $id) {
                if ($index > 0 && (microtime(true) - $started) >= $budget) {
                    break;
                }
                $last_id = $id;
                $item    = $this->optimize((int) $id, $max_edge, $writable, $dry_run, $force, $session_id);

                $out['items'][] = $item;
                if (isset($item['before_bytes'])) {
                    $out['totals']['before_bytes']    += $item['before_bytes'];
                    $out['totals']['after_bytes']     += $item['after_bytes'];
                    $out['totals']['saved_bytes']     += $item['saved_bytes'];
                    $out['totals']['generated_bytes'] += (int) array_sum(array_column($item['generated'], 'bytes'));
                }
            }
        } finally {
            $this->unhook_editor_filters();
        }

        if (! $media_id) {
            $remaining          = self::count_after($last_id);
            $out['remaining']   = $remaining;
            $out['next_cursor'] = $remaining > 0 ? $last_id : null;
        }

        return $out;
    }

    /**
     * Queue a background run over the library (issue #432). Nothing is
     * queued while an optimizer plugin is active (unless forced) or while
     * another run is still going: two runs over the same library would
     * each skip or redo the other's images.
     *
     * @param string[] $formats
     */
    private function queue(int $max_edge, array $formats, bool $dry_run, bool $force, string $session_id): array
    {
        $optimizers = self::active_optimizers();
        if ([] !== $optimizers && ! $force) {
            return [
                'deferred_to' => $optimizers,
                'reason'      => 'An active image-optimization plugin already handles this. Pass force:true to run anyway.',
            ];
        }

        $active = Optimize_Media_Job::active();
        if (null !== $active) {
            throw new \InvalidArgumentException(sprintf(
                'optimize-media job %d is still %s. Read it with job_id, or cancel it (job_id with cancel:true) first.',
                (int) $active['id'],
                esc_html((string) $active['status'])
            ));
        }

        $job = Optimize_Media_Job::create(
            [
                'quality'    => $this->quality,
                'max_edge'   => $max_edge,
                'formats'    => $formats,
                'dry_run'    => $dry_run,
                'force'      => $force,
                'session_id' => $session_id,
            ],
            get_current_user_id(),
            self::count_after(0)
        );

        return [
            'job_id'      => (int) $job['id'],
            'status'      => (string) $job['status'],
            'session_id'  => (string) $job['session_id'],
            'total'       => (int) $job['progress']['total'],
            'unsupported' => self::unsupported($formats),
        ];
    }

    /**
     * Whether a plugin's Name and Description read like an image optimizer.
     * Generic on purpose: no plugin is named, so a new optimizer is deferred
     * to as soon as it describes itself.
     */
    public static function looks_like_optimizer(string $name, string $description): bool
    {
        $text = $name . ' ' . $description;
        return (bool) preg_match(
            '/\b(?:images?|photos?|media)\b.{0,40}\b(?:optimi[sz]\w*|compress\w*)|\b(?:optimi[sz]\w*|compress\w*)\b.{0,40}\b(?:images?|photos?)\b|\b(?:webp|avif)\b/i',
            $text
        );
    }

    /**
     * Names of active plugins that look like image optimizers. The
     * wpmcp_image_optimizer_plugins filter can pin the answer (an array of
     * names) for a site whose optimizer describes itself differently, or
     * return an empty array to stop deferring.
     *
     * @return string[]
     */
    public static function active_optimizers(): array
    {
        $pinned = apply_filters('wpmcp_image_optimizer_plugins', null);
        if (is_array($pinned)) {
            return array_values(array_filter(array_map('strval', $pinned)));
        }

        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        $self = defined('WPMCP_FILE') ? plugin_basename(WPMCP_FILE) : '';

        // Front-end delivery asks on every page view (issue #432), so the
        // header scan is remembered per set of active plugins: activating
        // or deactivating one changes the key and rescans.
        $key = md5(implode("\n", $active));
        if (isset(self::$optimizers_cache[ $key ])) {
            return self::$optimizers_cache[ $key ];
        }
        $cached = get_transient(self::OPTIMIZERS_TRANSIENT);
        if (is_array($cached) && ($cached['key'] ?? '') === $key && is_array($cached['names'] ?? null)) {
            return self::$optimizers_cache[ $key ] = array_map('strval', $cached['names']);
        }

        $found = [];
        foreach (array_unique(array_map('strval', $active)) as $relative) {
            $file = WP_PLUGIN_DIR . '/' . $relative;
            if ($relative === $self || ! is_file($file)) {
                continue;
            }
            $data = get_plugin_data($file, false, false);
            if (self::looks_like_optimizer((string) $data['Name'], (string) $data['Description'])) {
                $found[] = (string) $data['Name'];
            }
        }
        set_transient(self::OPTIMIZERS_TRANSIENT, [ 'key' => $key, 'names' => $found ], DAY_IN_SECONDS);
        return self::$optimizers_cache[ $key ] = $found;
    }

    /** @return string[] validated format keys */
    private static function formats($raw): array
    {
        $formats = [];
        foreach ((array) $raw as $format) {
            $format = strtolower(trim((string) $format));
            if (! isset(self::FORMATS[ $format ])) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown format "%s". Use webp, avif or both.',
                    esc_html($format)
                ));
            }
            $formats[ $format ] = $format;
        }
        return array_values($formats);
    }

    /**
     * Formats no image editor on this server can write, each with a reason
     * the agent can relay, instead of a failed call.
     *
     * @return array<string,string>
     */
    private static function unsupported(array $formats): array
    {
        $out = [];
        foreach ($formats as $format) {
            [$mime, $label] = self::FORMATS[ $format ];
            if (wp_image_editor_supports([ 'mime_type' => $mime ])) {
                continue;
            }
            $out[ $format ] = sprintf(
                '%1$s copies skipped: no image editor on this server (Imagick or GD) can write %2$s. The host can enable %1$s support in either.',
                $label,
                $mime
            );
        }
        return $out;
    }

    /** @return int[] the next attachment ids after $cursor */
    private static function batch(int $cursor, int $limit): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset page over attachments; a cursor walk cannot be expressed through WP_Query.
        $ids = (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png') AND ID > %d ORDER BY ID ASC LIMIT %d", $cursor, $limit));
        return array_map('intval', $ids);
    }

    private static function count_after(int $cursor): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- how many images the cursor has left.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png') AND ID > %d", $cursor));
    }

    /**
     * Optimize one attachment, or estimate it on a dry run.
     *
     * @param string[] $formats formats the server can write
     */
    private function optimize(int $id, int $max_edge, array $formats, bool $dry_run, bool $force, string $session_id): array
    {
        if (! current_user_can('edit_post', $id)) {
            return [ 'media_id' => $id, 'skipped' => 'not permitted to edit this attachment' ];
        }
        if (! $force && '' !== get_post_meta($id, self::META_KEY, true)) {
            return [ 'media_id' => $id, 'skipped' => 'already optimized' ];
        }
        $main = (string) get_attached_file($id);
        if ('' === $main || ! is_file($main)) {
            return [ 'media_id' => $id, 'skipped' => 'file missing on disk' ];
        }

        $files  = self::files($id, $main);
        $before = 0;
        foreach (array_keys($files) as $path) {
            $before += (int) filesize($path);
        }

        // The copies this run will write: only paths that do not exist yet,
        // so a rollback never deletes a file something else put there.
        $created = [];
        foreach (array_keys($files) as $path) {
            foreach ($formats as $format) {
                if (! file_exists($path . '.' . $format)) {
                    $created[] = $path . '.' . $format;
                }
            }
        }

        if ($dry_run) {
            return $this->estimate($id, $files, $before, $max_edge, $formats, $created);
        }

        $operation_id = wp_generate_uuid4();
        $backup       = File_Backup::collect_attachment_files($id);
        $manifest     = File_Backup::backup($operation_id, $backup);
        if (count($manifest) < count($backup)) {
            File_Backup::delete_backup_dir($operation_id);
            return [ 'media_id' => $id, 'skipped' => 'could not back up the original files, so nothing was changed' ];
        }

        try {
            $run = Safe_Mutation::run(
                [
                    'object_type'         => 'post',
                    'object_id'           => $id,
                    'session_id'          => $session_id,
                    'tool_name'           => 'optimize-media',
                    'args'                => [ 'media_id' => $id, 'quality' => $this->quality, 'max_edge' => $max_edge, 'formats' => $formats ],
                    'operation_id'        => $operation_id,
                    'extra_snapshot_data' => [ 'files' => [ 'operation_id' => $operation_id, 'manifest' => $manifest, 'created' => $created ] ],
                ],
                fn (): array => $this->apply($id, $files, $before, $max_edge, $formats, $created)
            );
        } catch (\Throwable $e) {
            File_Backup::restore($operation_id, $manifest);
            File_Backup::remove_created($created, $manifest);
            return [ 'media_id' => $id, 'error' => $e->getMessage() ];
        }

        return [ 'media_id' => $id, 'operation_id' => $run['operation_id'] ] + $run['result'];
    }

    /**
     * The attachment's full-size file and every sub-size file, deduplicated
     * (two sizes can share one file). The pre-scale original WordPress keeps
     * beside a "-scaled" upload is left alone: it is the untouched source
     * that core regenerates sizes from.
     *
     * @return array<string,string[]> absolute path => size names using it ('full' for the main file)
     */
    private static function files(int $id, string $main): array
    {
        $dir   = trailingslashit(dirname($main));
        $files = [ $main => [ 'full' ] ];
        $meta  = wp_get_attachment_metadata($id);
        foreach ((array) (is_array($meta) ? ($meta['sizes'] ?? []) : []) as $name => $size) {
            $path = $dir . (string) ($size['file'] ?? '');
            if (! empty($size['file']) && is_file($path)) {
                $files[ $path ][] = (string) $name;
            }
        }
        return $files;
    }

    /** @param string[] $formats @param string[] $created */
    private function apply(int $id, array $files, int $before, int $max_edge, array $formats, array $created): array
    {
        $meta      = wp_get_attachment_metadata($id);
        $meta      = is_array($meta) ? $meta : [];
        $after     = 0;
        $generated = [];
        $notes     = [];

        foreach ($files as $path => $sizes) {
            $is_main = in_array('full', $sizes, true);
            $cap     = $is_main ? $max_edge : 0;
            $mime    = (string) wp_get_image_mime($path);

            $result = $this->encode($path, $mime, $mime, $cap, self::temp_name($path));
            if (is_array($result)) {
                if ($result['bytes'] < (int) filesize($path) || $result['resized']) {
                    copy($result['path'], $path);
                    if ($is_main && $result['resized']) {
                        $meta['width']  = $result['width'];
                        $meta['height'] = $result['height'];
                    }
                }
                wp_delete_file($result['path']);
            } else {
                $notes[] = basename($path) . ': ' . $result;
            }
            clearstatcache(true, $path);
            $bytes  = (int) filesize($path);
            $after += $bytes;
            self::record_filesize($meta, $sizes, $bytes);

            foreach ($formats as $format) {
                $target = $path . '.' . $format;
                if (! in_array($target, $created, true)) {
                    continue;
                }
                $copy = $this->encode($path, $mime, self::FORMATS[ $format ][0], $cap, $target);
                if (! is_array($copy)) {
                    $notes[] = basename($target) . ': ' . $copy;
                    continue;
                }
                if ($copy['bytes'] >= $bytes) {
                    wp_delete_file($copy['path']);
                    $notes[] = basename($target) . ': not smaller than the source, discarded';
                    continue;
                }
                $generated[ $format ]['files'][] = basename($target);
                $generated[ $format ]['bytes']   = ($generated[ $format ]['bytes'] ?? 0) + $copy['bytes'];
            }
        }

        wp_update_attachment_metadata($id, $meta);
        update_post_meta($id, self::META_KEY, [
            'quality'      => $this->quality,
            'max_edge'     => $max_edge,
            'before_bytes' => $before,
            'after_bytes'  => $after,
            'formats'      => array_map(static fn ($g) => $g['files'], $generated),
            'time'         => time(),
        ]);

        return self::item_numbers($before, $after, $generated, $notes);
    }

    /**
     * Encode every file to scratch files in the temp directory and measure
     * them, without touching the attachment. The same encoder as a real run, so the
     * estimate is what a real run at these settings would save.
     */
    private function estimate(int $id, array $files, int $before, int $max_edge, array $formats, array $created): array
    {
        $scratch   = trailingslashit(get_temp_dir()) . 'wpmcp-optimize-' . wp_generate_password(12, false) . '-';
        $written   = [];
        $after     = 0;
        $generated = [];
        $notes     = [];

        foreach ($files as $path => $sizes) {
            $cap       = in_array('full', $sizes, true) ? $max_edge : 0;
            $mime      = (string) wp_get_image_mime($path);
            $current   = (int) filesize($path);
            $written[] = $scratch . basename($path);
            $result    = $this->encode($path, $mime, $mime, $cap, (string) end($written));
            $bytes     = is_array($result) && ($result['bytes'] < $current || $result['resized']) ? $result['bytes'] : $current;
            $after    += $bytes;
            if (! is_array($result)) {
                $notes[] = basename($path) . ': ' . $result;
            }
            foreach ($formats as $format) {
                if (! in_array($path . '.' . $format, $created, true)) {
                    continue;
                }
                $written[] = $scratch . basename($path) . '.' . $format;
                $copy      = $this->encode($path, $mime, self::FORMATS[ $format ][0], $cap, (string) end($written));
                if (is_array($copy) && $copy['bytes'] < $bytes) {
                    $generated[ $format ]['files'][] = basename($path) . '.' . $format;
                    $generated[ $format ]['bytes']   = ($generated[ $format ]['bytes'] ?? 0) + $copy['bytes'];
                }
            }
        }

        foreach ($written as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }

        return [ 'media_id' => $id ] + self::item_numbers($before, $after, $generated, $notes);
    }

    private static function item_numbers(int $before, int $after, array $generated, array $notes): array
    {
        $item = [
            'before_bytes' => $before,
            'after_bytes'  => $after,
            'saved_bytes'  => $before - $after,
            'generated'    => $generated,
        ];
        if ([] !== $notes) {
            $item['notes'] = $notes;
        }
        return $item;
    }

    /**
     * Load $path in the server's image editor, cap its longest edge when
     * asked, and save it as $out_mime to $target.
     *
     * @return array{path:string,bytes:int,resized:bool,width:int,height:int}|string the saved file, or why it could not be written
     */
    private function encode(string $path, string $mime, string $out_mime, int $max_edge, string $target)
    {
        $editor = wp_get_image_editor($path, [ 'mime_type' => $mime, 'output_mime_type' => $out_mime ]);
        if (is_wp_error($editor)) {
            return $editor->get_error_message();
        }
        if (! call_user_func([ get_class($editor), 'supports_mime_type' ], $out_mime)) {
            return 'the image editor cannot write ' . $out_mime;
        }
        $editor->set_quality($this->quality);

        $size    = $editor->get_size();
        $resized = false;
        if ($max_edge > 0 && max((int) $size['width'], (int) $size['height']) > $max_edge) {
            $done = $editor->resize($max_edge, $max_edge, false);
            if (is_wp_error($done)) {
                return $done->get_error_message();
            }
            $size    = $editor->get_size();
            $resized = true;
        }

        $saved = $editor->save($target, $out_mime);
        if (is_wp_error($saved)) {
            return $saved->get_error_message();
        }
        $written = (string) ($saved['path'] ?? '');
        if ($written !== $target || ($saved['mime-type'] ?? '') !== $out_mime) {
            if ('' !== $written && is_file($written)) {
                wp_delete_file($written);
            }
            return 'the image editor wrote a different format than ' . $out_mime;
        }
        clearstatcache(true, $target);

        return [
            'path'    => $target,
            'bytes'   => (int) filesize($target),
            'resized' => $resized,
            'width'   => (int) $size['width'],
            'height'  => (int) $size['height'],
        ];
    }

    /** A scratch name beside $path that keeps its extension, so the editor keeps its format. */
    private static function temp_name(string $path): string
    {
        return trailingslashit(dirname($path)) . '.wpmcp-optimize-' . wp_generate_password(8, false) . '-' . basename($path);
    }

    /** Keep the filesize WordPress records per file in step with the disk. */
    private static function record_filesize(array &$meta, array $sizes, int $bytes): void
    {
        foreach ($sizes as $size) {
            if ('full' === $size) {
                if (isset($meta['filesize'])) {
                    $meta['filesize'] = $bytes;
                }
            } elseif (isset($meta['sizes'][ $size ]['filesize'])) {
                $meta['sizes'][ $size ]['filesize'] = $bytes;
            }
        }
    }

    /**
     * For the length of a call, the quality the caller asked for is the one
     * every save uses (the editor otherwise resets it to a per-format default
     * when it converts), and no output-format mapping another plugin has
     * registered can turn a JPEG recompress into a different format.
     */
    private function hook_editor_filters(): void
    {
        wp_raise_memory_limit('image');
        add_filter('wp_editor_set_quality', [ $this, 'quality' ], PHP_INT_MAX);
        add_filter('jpeg_quality', [ $this, 'quality' ], PHP_INT_MAX);
        add_filter('image_editor_output_format', '__return_empty_array', PHP_INT_MAX);
    }

    private function unhook_editor_filters(): void
    {
        remove_filter('wp_editor_set_quality', [ $this, 'quality' ], PHP_INT_MAX);
        remove_filter('jpeg_quality', [ $this, 'quality' ], PHP_INT_MAX);
        remove_filter('image_editor_output_format', '__return_empty_array', PHP_INT_MAX);
    }

    /** @internal filter callback */
    public function quality(): int
    {
        return $this->quality;
    }
}
