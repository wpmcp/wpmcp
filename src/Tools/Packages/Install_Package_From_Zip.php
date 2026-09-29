<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Install a plugin or theme from a ZIP the site owner uploaded to the Media
 * Library (issue #282), which is how most commercial packages are delivered.
 *
 * The order is the safety model:
 *
 *  1. Default-off gate (Package_Guard::zip_install_enabled(), listed in
 *     Governance\Opt_In_Gates), then confirm:true, then the capability the
 *     matching wp-admin screen requires: install_plugins / install_themes,
 *     plus update_plugins / update_themes when an installed package would be
 *     replaced, as core's own "replace current with uploaded" flow does.
 *  2. The attachment is copied to a private temp file, and every later step
 *     reads that copy, so the bytes hashed are the bytes installed.
 *  3. The SHA-256 of the copy must equal the caller's expected hash.
 *  4. Package_Archive reads the whole central directory and refuses
 *     traversal, absolute paths, symlinks, more than one top-level root and
 *     anything that is not the plugin or theme the caller said it is.
 *  5. A protected plugin directory (this plugin, Elementor) is refused.
 *
 * Nothing is written until all of that has passed. Then the prior directory,
 * when there is one, is archived with File_Backup, the snapshot is recorded
 * through Safe_Mutation, and core's Plugin_Upgrader / Theme_Upgrader installs
 * from the local copy. rollback-operation removes a fresh install or puts the
 * archived prior version back; a failed install is unwound the same way
 * before this returns. Every refusal and every install is audited.
 *
 * Nothing is activated: activate-plugin and switch-theme do that, snapshotted.
 */
class Install_Package_From_Zip
{
    public const ABILITY = 'wpmcp/install-package-from-zip';

    public const REASON_GATE_CLOSED       = 'zip-install-gate-closed';
    public const REASON_CONFIRM_REQUIRED  = 'confirm-required';
    public const REASON_INVALID_ARGS      = 'invalid-arguments';
    public const REASON_CAPABILITY        = 'missing-capability';
    public const REASON_NOT_A_ZIP         = 'not-a-zip-attachment';
    public const REASON_HASH_MISMATCH     = 'hash-mismatch';
    public const REASON_ARCHIVE_REJECTED  = 'archive-rejected';
    public const REASON_PROTECTED         = 'protected-package';
    public const REASON_DESTINATION       = 'destination-not-directory';
    public const REASON_BACKUP_FAILED     = 'backup-failed';
    public const REASON_INSTALL_FAILED    = 'install-failed';
    public const REASON_INSTALLED         = 'installed';

    private const TYPES = ['plugin', 'theme'];

    private string $upgrader_error = '';

    private string $plugin_file = '';

    public function handle(array $args): array
    {
        if (! Package_Guard::zip_install_enabled()) {
            $this->refuse(
                new \RuntimeException('Installing packages from an uploaded ZIP is disabled. Enable it with the WPMCP_ENABLE_ZIP_INSTALL constant or the wpmcp_enable_zip_install filter.'),
                self::REASON_GATE_CLOSED
            );
        }

        $type          = (string) ($args['type'] ?? '');
        $attachment_id = (int) ($args['attachment_id'] ?? 0);
        $expected      = strtolower(trim((string) ($args['sha256'] ?? '')));

        if (! in_array($type, self::TYPES, true)) {
            $this->refuse(new \InvalidArgumentException('type must be "plugin" or "theme".'), self::REASON_INVALID_ARGS);
        }
        if ($attachment_id < 1) {
            $this->refuse(new \InvalidArgumentException('attachment_id is required.'), self::REASON_INVALID_ARGS);
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $expected)) {
            $this->refuse(new \InvalidArgumentException('sha256 must be the 64-character hex SHA-256 of the uploaded ZIP.'), self::REASON_INVALID_ARGS);
        }
        if (true !== ($args['confirm'] ?? null)) {
            $this->refuse(
                new \WPMCP\MCP\Confirmation_Required('Installing a package from a ZIP puts its code on the site. Pass confirm:true to proceed.'),
                self::REASON_CONFIRM_REQUIRED
            );
        }

        $this->require_capability('plugin' === $type ? 'install_plugins' : 'install_themes');

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to install packages.');
        }

        $source = $this->attachment_zip($attachment_id);

        $work = wp_tempnam('wpmcp-package.zip');
        try {
            if (! $work || ! copy($source, $work)) {
                throw new \RuntimeException('The uploaded ZIP could not be read.');
            }
            return $this->install_from($work, $type, $expected, $args);
        } finally {
            if ($work && is_file($work)) {
                wp_delete_file($work);
            }
        }
    }

    /** Steps 3 to 5, then the snapshotted install. */
    private function install_from(string $work, string $type, string $expected, array $args): array
    {
        if (! hash_equals($expected, (string) hash_file('sha256', $work))) {
            $this->refuse(
                new \RuntimeException('The uploaded ZIP does not match the expected SHA-256; nothing was installed.'),
                self::REASON_HASH_MISMATCH
            );
        }

        try {
            $inspected = Package_Archive::inspect($work, $type);
        } catch (Package_Rejected $e) {
            $this->refuse(new \RuntimeException('Refused: ' . $e->getMessage() . ' Nothing was installed.'), self::REASON_ARCHIVE_REJECTED . ':' . $e->reason);
        }
        $slug = $inspected['root'];

        if ('plugin' === $type && Package_Guard::is_protected_plugin_dir($slug)) {
            $this->refuse(
                new \RuntimeException(sprintf('Refusing to install over the protected plugin directory "%s".', esc_html($slug))),
                self::REASON_PROTECTED
            );
        }

        $dest = trailingslashit('theme' === $type ? get_theme_root() : WP_PLUGIN_DIR) . $slug;
        if (is_link($dest) || (file_exists($dest) && ! is_dir($dest))) {
            $this->refuse(
                new \RuntimeException(sprintf('The destination for "%s" is not a plain directory; nothing was installed.', esc_html($slug))),
                self::REASON_DESTINATION
            );
        }

        $existed = is_dir($dest);
        if ($existed) {
            $this->require_capability('plugin' === $type ? 'update_plugins' : 'update_themes');
        }
        $previous_version = $existed ? self::installed_version($type, $slug) : null;

        $operation_id = wp_generate_uuid4();
        $backed_up    = false;
        if ($existed && [] !== array_diff((array) scandir($dest), ['.', '..'])) {
            if (! File_Backup::backup_directory($operation_id, $dest)) {
                $this->refuse(
                    new \RuntimeException(sprintf('The installed "%s" could not be backed up, so it was not replaced.', esc_html($slug))),
                    self::REASON_BACKUP_FAILED
                );
            }
            $backed_up = true;
        }

        $started = false;
        try {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => 'package_install',
                    'object_id'           => $type . ':' . $slug,
                    'session_id'          => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'           => 'install-package-from-zip',
                    'args'                => $args,
                    'operation_id'        => $operation_id,
                    'extra_snapshot_data' => ['backup_operation_id' => $backed_up ? $operation_id : ''],
                ],
                function () use ($type, $work, $existed, &$started) {
                    $started = true;
                    return $this->run_upgrader($type, $work, $existed);
                },
                static function ($result) use ($dest): bool {
                    return true === $result && is_dir($dest);
                }
            );
        } catch (Mutation_Failed $e) {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, self::REASON_INSTALL_FAILED);
            throw new \RuntimeException(sprintf(
                'Installing "%s" failed and the change was rolled back: %s',
                esc_html($slug),
                esc_html('' !== $this->upgrader_error ? $this->upgrader_error : 'unknown error')
            ));
        } catch (\Throwable $e) {
            // The snapshot never landed, so nothing can claim this backup.
            if (! $started && $backed_up) {
                File_Backup::delete_backup_dir($operation_id);
            }
            throw $e;
        }

        Governance_Audit_Log::record_quietly(self::ABILITY, true, self::REASON_INSTALLED);

        $result = [
            'installed'        => true,
            'type'             => $type,
            'slug'             => $slug,
            'replaced'         => $existed,
            'previous_version' => $previous_version,
            'version'          => self::installed_version($type, $slug),
            'activated'        => false,
            'operation_id'     => $out['operation_id'],
            'recoverable'      => true,
        ];
        if ('plugin' === $type) {
            $result['plugin_file'] = '' !== $this->plugin_file ? $this->plugin_file : $slug . '/' . $inspected['main_file'];
        }
        return $result;
    }

    /** Core's upgrader against the validated local copy. True on success. */
    private function run_upgrader(string $type, string $package, bool $overwrite): bool
    {
        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = 'plugin' === $type ? new \Plugin_Upgrader($skin) : new \Theme_Upgrader($skin);
        $result   = self::without_update_checks(
            static fn () => $upgrader->install($package, ['overwrite_package' => $overwrite])
        );

        if (is_wp_error($result)) {
            $this->upgrader_error = $result->get_error_message();
            return false;
        }
        if (true !== $result) {
            $messages             = $skin->get_upgrade_messages();
            $this->upgrader_error = (string) (end($messages) ?: '');
            return false;
        }

        if ($upgrader instanceof \Plugin_Upgrader) {
            $this->plugin_file = (string) $upgrader->plugin_info();
        }
        return true;
    }

    /**
     * Core hooks its wordpress.org update checks onto upgrader_process_complete,
     * so every upgrader run would make up to three outbound requests before
     * returning. The package here is local, and the upgrader already clears
     * the update transients, so core refreshes them on its own schedule; the
     * install (and the snapshot a rollback depends on) must not wait on, or
     * fail with, wordpress.org (issue #323). The checks are unhooked for this
     * run only and put back afterwards, even when the install throws.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     */
    private static function without_update_checks(callable $run)
    {
        $unhooked = [];
        foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $check) {
            $priority = has_action('upgrader_process_complete', $check);
            if (false !== $priority) {
                remove_action('upgrader_process_complete', $check, $priority);
                $unhooked[ $check ] = $priority;
            }
        }

        try {
            return $run();
        } finally {
            foreach ($unhooked as $check => $priority) {
                add_action('upgrader_process_complete', $check, $priority, 0);
            }
        }
    }

    /** The attachment's file, when it is an existing .zip. */
    private function attachment_zip(int $attachment_id): string
    {
        $post = get_post($attachment_id);
        $file = $post && 'attachment' === $post->post_type ? (string) get_attached_file($attachment_id) : '';

        if ('' === $file || ! is_file($file) || is_link($file) || 'zip' !== strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            $this->refuse(
                new \RuntimeException(sprintf('Attachment %d is not an uploaded .zip file.', $attachment_id)),
                self::REASON_NOT_A_ZIP
            );
        }
        return $file;
    }

    /** Version header of an installed plugin directory or theme, or null. */
    private static function installed_version(string $type, string $slug): ?string
    {
        if ('theme' === $type) {
            wp_clean_themes_cache();
            $theme = wp_get_theme($slug);
            return $theme->exists() ? (string) $theme->get('Version') : null;
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        wp_clean_plugins_cache(false);
        foreach (get_plugins('/' . $slug) as $data) {
            return (string) ($data['Version'] ?? '');
        }
        return null;
    }

    private function require_capability(string $capability): void
    {
        if (! current_user_can($capability)) {
            $this->refuse(
                new \RuntimeException(sprintf('Installing this package requires the %s capability.', $capability)),
                self::REASON_CAPABILITY
            );
        }
    }

    /**
     * Audit the refusal, then raise it. Never returns.
     *
     * @return never
     */
    private function refuse(\Exception $e, string $reason): void
    {
        Governance_Audit_Log::record_quietly(self::ABILITY, false, $reason);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- every message is built above with esc_html() on its interpolated operands.
        throw $e;
    }
}
