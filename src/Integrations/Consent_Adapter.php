<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One cookie consent plugin for Consent_Pack (issue #686): where the plugin
 * keeps its banner, its texts and its cookie list, and how a text change is
 * written and undone. Consent_Pack builds the same ops over every adapter.
 */
abstract class Consent_Adapter
{
    public const MAX_TEXT = 5000;

    /** Plugin slug as the ops name it (complianz, cookieyes, cookie-notice). */
    abstract public function slug(): string;

    abstract public function label(): string;

    /** Main plugin file relative to the plugins directory. */
    abstract public function plugin_file(): string;

    /** Whether the plugin's code is loaded on this request. */
    abstract protected function loaded(): bool;

    abstract public function version(): string;

    /** @return array<string, mixed> banner on/off, who manages it, last scan, counts. */
    abstract public function status(): array;

    /** @return array<string, mixed> the allowlisted consent settings, credentials and personal data masked. */
    abstract public function settings(): array;

    /** @return array<string, string> text key => 'html' or 'text'. */
    abstract public function text_keys(): array;

    /**
     * The banner a text call reads or writes, or a refusal.
     *
     * @return array<string, mixed>|array{code: string, message: string}
     */
    abstract public function banner(array $args): array;

    /** @return array<string, string> text key => current text of $banner. */
    abstract public function texts(array $banner): array;

    /** @param array<string, string> $changes sanitized text key => new text. */
    abstract public function write_texts(array $banner, array $changes): void;

    /**
     * The snapshot target of a text write on $banner.
     *
     * @param array<string, string> $changes sanitized text key => new text
     * @return array<string, mixed>|null
     */
    abstract public function snapshot(array $banner, array $changes): ?array;

    /** Presence is filterable per plugin: wpmcp_complianz_active, wpmcp_cookieyes_active, wpmcp_cookie_notice_active. */
    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_' . str_replace('-', '_', $this->slug()) . '_active', $this->loaded());
    }

    public function installed(): bool
    {
        return defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $this->plugin_file());
    }

    /** Why this plugin's texts cannot be written now (a cloud managed banner), or null. */
    public function write_refusal(array $banner): ?array
    {
        return null;
    }

    /**
     * Detected cookies, or null when the plugin keeps no local list.
     *
     * @return array{total: int, cookies: array<int, array<string, mixed>>}|null
     */
    public function cookies(int $limit, int $offset): ?array
    {
        return null;
    }

    /** Why a scan cannot start, or null. */
    public function scan_refusal(): ?array
    {
        return [
            'code'    => 'not_supported',
            'message' => sprintf('%s has no cookie scan WP MCP can start on this site.', $this->label()),
        ];
    }

    /** @return array<string, mixed> */
    public function start_scan(): array
    {
        return [];
    }

    /** Refresh what the plugin derives from $table after an undo restored rows in it. */
    public function after_rows_restored(string $table): void
    {
    }

    /** A sanitized text in the form the plugin's own save stores it. */
    public function sanitize(string $key, string $value): string
    {
        $value = trim($value);
        return 'html' === ($this->text_keys()[ $key ] ?? 'text') ? wp_kses_post($value) : sanitize_text_field($value);
    }

    /**
     * The value at a dotted path, or null.
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    protected static function path_get(array $data, string $path)
    {
        foreach (explode('.', $path) as $part) {
            if (! is_array($data) || ! array_key_exists($part, $data)) {
                return null;
            }
            $data = $data[ $part ];
        }
        return $data;
    }

    /**
     * $data with $value at a dotted path, creating the arrays on the way.
     *
     * @param array<string, mixed> $data
     * @param mixed                $value
     * @return array<string, mixed>
     */
    protected static function path_set(array $data, string $path, $value): array
    {
        $parts = explode('.', $path);
        $head  = array_shift($parts);
        if ([] === $parts) {
            $data[ $head ] = $value;
            return $data;
        }
        $child         = isset($data[ $head ]) && is_array($data[ $head ]) ? $data[ $head ] : [];
        $data[ $head ] = self::path_set($child, implode('.', $parts), $value);
        return $data;
    }

    /** The site's real name of a plugin table, or null when it does not exist. */
    protected static function table(string $suffix): ?string
    {
        global $wpdb;
        $table = Database_Guard::valid_table($wpdb->prefix . $suffix);
        return is_wp_error($table) ? null : $table;
    }

    /**
     * A db_rows before-image of one row keyed by $pk, for a text update.
     *
     * @param array<string, mixed> $set the columns the write changes
     * @return array<string, mixed>|null
     */
    protected static function row_snapshot(string $table, string $pk, int $id, array $set): ?array
    {
        $where  = [ $pk => $id ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [
                'table'       => $table,
                'operation'   => 'update',
                'primary_key' => [ $pk ],
                'where'       => $where,
                'set'         => $set,
                'rows'        => $before,
            ],
        ];
    }

    /** @return array{code: string, message: string} */
    protected static function not_found(string $what): array
    {
        return [ 'code' => 'not_found', 'message' => $what ];
    }
}
