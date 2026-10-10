<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Privacy;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Request_Log;
use WPMCP\Memory\Memory_Store;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP MCP's stores in WordPress's personal data export and erasure (issue
 * #478): Tools > Export Personal Data and Erase Personal Data, and anything
 * that drives them, cover the undo snapshots, the activity logs (request
 * log and governance audit log), agent memory and the search index. The Pro
 * chat registers its own pair from Pro\Chat\Conversation_Store.
 *
 * No tool is added. Each store is its own exporter and eraser, so core pages
 * each one separately, and each page reads a bounded batch keyed on a row id
 * cursor (see cursor()). Everything registers at PRIORITY, after the
 * integrations' own erasers (WooCommerce, forms plugins), so their erasure
 * runs first and WP MCP's copies of their rows are redacted after it.
 *
 * Every store is per site ($wpdb->prefix, per-site options and posts), so
 * on multisite a request run on one site covers that site's stores.
 */
class Personal_Data
{
    /** Filter priority: after integrations, which register at the default. */
    public const PRIORITY = 1000;

    /** Snapshot rows decoded per page (each is a compressed whole object). */
    public const SNAPSHOT_PAGE = 50;

    /** Memory entries, index rows or conversations per page. */
    public const ROW_PAGE = 100;

    private const CURSOR_PREFIX = 'wpmcp_privacy_cursor_';
    private const IPS_PREFIX    = 'wpmcp_privacy_ips_';

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [ self::class, 'exporters' ], self::PRIORITY);
        add_filter('wp_privacy_personal_data_erasers', [ self::class, 'erasers' ], self::PRIORITY);
    }

    /** @return array<string, string> key => friendly name, in the order core runs them. */
    private static function stores(): array
    {
        return [
            'wpmcp-snapshots'     => __('WP MCP undo snapshots', 'wpmcp'),
            'wpmcp-activity-logs' => __('WP MCP activity logs', 'wpmcp'),
            'wpmcp-memory'        => __('WP MCP agent memory', 'wpmcp'),
            'wpmcp-search-index'  => __('WP MCP search index', 'wpmcp'),
        ];
    }

    public static function exporters($list): array
    {
        $list      = (array) $list;
        $callbacks = [
            'wpmcp-snapshots'     => 'export_snapshots',
            'wpmcp-activity-logs' => 'export_logs',
            'wpmcp-memory'        => 'export_memory',
            'wpmcp-search-index'  => 'export_search_index',
        ];
        foreach (self::stores() as $key => $name) {
            $list[ $key ] = [ 'exporter_friendly_name' => $name, 'callback' => [ self::class, $callbacks[ $key ] ] ];
        }
        return $list;
    }

    public static function erasers($list): array
    {
        $list      = (array) $list;
        $callbacks = [
            'wpmcp-snapshots'     => 'erase_snapshots',
            'wpmcp-activity-logs' => 'erase_logs',
            'wpmcp-memory'        => 'erase_memory',
            'wpmcp-search-index'  => 'erase_search_index',
        ];
        foreach (self::stores() as $key => $name) {
            $list[ $key ] = [ 'eraser_friendly_name' => $name, 'callback' => [ self::class, $callbacks[ $key ] ] ];
        }
        return $list;
    }

    // ------------------------------------------------------------------
    // Shared helpers (also used by the Pro chat store).
    // ------------------------------------------------------------------

    /** The account that owns $email on this site, or 0. */
    public static function user_id(string $email): int
    {
        $user = '' === $email ? false : get_user_by('email', $email);
        return $user instanceof \WP_User ? (int) $user->ID : 0;
    }

    /**
     * The row id a page continues after. Page 1 starts over; later pages
     * resume where the previous page stopped. A cursor rather than an
     * offset, because an erasure changes or deletes the rows it pages
     * through, and a prune can delete snapshot rows between two pages.
     */
    public static function cursor(string $scope, string $email, int $page): int
    {
        if ($page <= 1) {
            delete_transient(self::cursor_key($scope, $email));
            return 0;
        }
        return max(0, (int) get_transient(self::cursor_key($scope, $email)));
    }

    /** Record where the next page continues, or forget the cursor when done. */
    public static function advance(string $scope, string $email, int $last_id, bool $done): void
    {
        if ($done) {
            delete_transient(self::cursor_key($scope, $email));
            return;
        }
        set_transient(self::cursor_key($scope, $email), $last_id, HOUR_IN_SECONDS);
    }

    private static function cursor_key(string $scope, string $email): string
    {
        return self::CURSOR_PREFIX . md5($scope . '|' . strtolower(trim($email)));
    }

    /**
     * One export item.
     *
     * @param array<string, scalar|null> $fields
     */
    public static function item(string $group_id, string $group_label, string $description, string $item_id, array $fields): array
    {
        $data = [];
        foreach ($fields as $name => $value) {
            if (null === $value || '' === (string) $value) {
                continue;
            }
            $data[] = [ 'name' => (string) $name, 'value' => (string) $value ];
        }
        return [
            'group_id'          => $group_id,
            'group_label'       => $group_label,
            'group_description' => $description,
            'item_id'           => $item_id,
            'data'              => $data,
        ];
    }

    /**
     * Every scalar leaf of $tree as "a > b > c" => value, for an export.
     *
     * @return array<string, string>
     */
    public static function flatten(array $tree, string $prefix = '', int $cap = 200): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            if (count($out) >= $cap) {
                break;
            }
            $name = '' === $prefix ? (string) $key : $prefix . ' > ' . $key;
            if (is_array($value)) {
                $out += self::flatten($value, $name, $cap - count($out));
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (is_scalar($value) && '' !== (string) $value) {
                $out[ $name ] = (string) $value;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Undo snapshots.
    // ------------------------------------------------------------------

    public static function export_snapshots($email, $page = 1): array
    {
        $email   = trim((string) $email);
        $user_id = self::user_id($email);
        $rows    = '' === $email ? [] : Snapshot_Store::page_after(self::cursor('export-snapshots', $email, (int) $page), self::SNAPSHOT_PAGE);
        $items   = [];
        $last    = 0;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            try {
                $snapshot = Snapshot::unserialize((string) $row['before_blob']);
            } catch (\RuntimeException $e) {
                unset($e);
                continue;
            }
            $records  = Snapshot_Redactor::records($snapshot, $email, $user_id);
            $operator = $user_id > 0 && (int) $row['user_id'] === $user_id;
            if ([] === $records && ! $operator) {
                continue;
            }
            $fields = [
                __('Operation', 'wpmcp')      => $row['operation_id'],
                __('Tool', 'wpmcp')           => $row['tool_name'],
                __('Saved (UTC)', 'wpmcp')    => $row['created_at'],
                __('Object', 'wpmcp')         => $row['object_type'] . ' ' . ($row['object_id'] ? '#' . $row['object_id'] : ''),
                __('Made by this account', 'wpmcp') => $operator ? __('yes', 'wpmcp') : null,
            ];
            foreach ($records as $path => $record) {
                foreach (self::flatten($record, $path) as $name => $value) {
                    $fields[ $name ] = $value;
                }
            }
            $items[] = self::item(
                'wpmcp-snapshots',
                __('WP MCP undo snapshots', 'wpmcp'),
                __('Copies of site data WP MCP saved before an AI agent changed it, so the change can be undone.', 'wpmcp'),
                'wpmcp-snapshot-' . $row['id'],
                $fields
            );
        }
        $done = count($rows) < self::SNAPSHOT_PAGE;
        self::advance('export-snapshots', $email, $last, $done);
        return [ 'data' => $items, 'done' => $done ];
    }

    public static function erase_snapshots($email, $page = 1): array
    {
        $email   = trim((string) $email);
        $user_id = self::user_id($email);
        $rows    = '' === $email ? [] : Snapshot_Store::page_after(self::cursor('erase-snapshots', $email, (int) $page), self::SNAPSHOT_PAGE);
        $removed = false;
        $ips     = [];
        $last    = 0;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            try {
                $snapshot = Snapshot::unserialize((string) $row['before_blob']);
            } catch (\RuntimeException $e) {
                unset($e);
                continue;
            }
            $redacted = Snapshot_Redactor::redact($snapshot, $email, $user_id, $ips);
            if (null !== $redacted) {
                Snapshot_Store::replace_snapshot((int) $row['id'], $redacted);
                $removed = true;
            }
        }
        if ([] !== $ips) {
            // Handed to the activity log eraser, which runs next: a log row
            // that recorded only a client address is matched by it.
            $known = (array) get_transient(self::ips_key($email));
            set_transient(self::ips_key($email), array_values(array_unique(array_merge($known, $ips))), HOUR_IN_SECONDS);
        }

        $done     = count($rows) < self::SNAPSHOT_PAGE;
        $retained = false;
        $messages = [];
        if ($done) {
            $made = $user_id > 0 ? Snapshot_Store::count_for_user($user_id) : 0;
            if ($made > 0) {
                $retained   = true;
                $messages[] = sprintf(
                    /* translators: %d: number of undo snapshots. */
                    __('WP MCP kept the account id recorded on %d undo snapshots this account made (no name, email or address): rollback uses it to decide who may undo those changes.', 'wpmcp'),
                    $made
                );
            }
            if (self::has_site_backups()) {
                $retained   = true;
                $messages[] = __('WP MCP site backup archives are full copies of the database and were not changed. Delete any archive made before this request to remove the person from it.', 'wpmcp');
            }
        }
        self::advance('erase-snapshots', $email, $last, $done);
        return [ 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => $done ];
    }

    private static function has_site_backups(): bool
    {
        if (! class_exists(Site_Backup_Dir::class)) {
            return false;
        }
        $files = glob(Site_Backup_Dir::path() . '/*');
        foreach ((array) $files as $file) {
            if (! in_array(basename((string) $file), [ '.htaccess', 'index.php', 'README.txt' ], true)) {
                return true;
            }
        }
        return false;
    }

    private static function ips_key(string $email): string
    {
        return self::IPS_PREFIX . md5(strtolower(trim($email)));
    }

    // ------------------------------------------------------------------
    // Activity logs: the MCP request log and the governance audit log.
    // Both are capped options, so one page covers them.
    // ------------------------------------------------------------------

    /** @return string[] The audit identities that name this account. */
    private static function audit_identities(int $user_id): array
    {
        if ($user_id <= 0) {
            return [];
        }
        $user = get_userdata($user_id);
        return array_values(array_filter([ 'user:' . $user_id, $user ? 'user:' . $user->user_login : '' ]));
    }

    private static function request_row_is_theirs(array $row, string $email, int $user_id): bool
    {
        if ($user_id > 0 && (isset($row['user_id']) ? (int) $row['user_id'] === $user_id : false)) {
            return true;
        }
        if ($user_id > 0 && 'user:' . $user_id === (string) ($row['client'] ?? '')) {
            return true;
        }
        return Snapshot_Redactor::contains_email($row, $email);
    }

    public static function export_logs($email, $page = 1): array
    {
        unset($page);
        $email   = trim((string) $email);
        $user_id = self::user_id($email);
        $items   = [];
        if ('' === $email) {
            return [ 'data' => [], 'done' => true ];
        }
        $label       = __('WP MCP activity logs', 'wpmcp');
        $description = __('Records of AI agent requests and of the permission decisions WP MCP made about them.', 'wpmcp');

        foreach ((array) get_option(Request_Log::OPTION, []) as $i => $row) {
            if (! is_array($row) || ! self::request_row_is_theirs($row, $email, $user_id)) {
                continue;
            }
            $items[] = self::item('wpmcp-activity-logs', $label, $description, 'wpmcp-request-log-' . $i, [
                __('Log', 'wpmcp')         => __('MCP request log', 'wpmcp'),
                __('Time (UTC)', 'wpmcp')  => gmdate('Y-m-d H:i:s', (int) ($row['timestamp'] ?? 0)),
                __('Tool', 'wpmcp')        => (string) ($row['tool'] ?? ''),
                __('Client', 'wpmcp')      => (string) ($row['client'] ?? ''),
                __('Outcome', 'wpmcp')     => empty($row['ok']) ? 'error' : 'ok',
                __('Error', 'wpmcp')       => trim((string) ($row['error_code'] ?? '') . ' ' . (string) ($row['error_message'] ?? '')),
                __('Arguments', 'wpmcp')   => isset($row['args']) ? (string) wp_json_encode($row['args']) : null,
            ]);
        }

        $identities = self::audit_identities($user_id);
        foreach ((array) get_option(Governance_Audit_Log::OPTION, []) as $i => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! in_array((string) ($entry['identity'] ?? ''), $identities, true) && ! Snapshot_Redactor::contains_email($entry, $email)) {
                continue;
            }
            $items[] = self::item('wpmcp-activity-logs', $label, $description, 'wpmcp-audit-log-' . $i, [
                __('Log', 'wpmcp')        => __('Governance audit log', 'wpmcp'),
                __('Time (UTC)', 'wpmcp') => gmdate('Y-m-d H:i:s', (int) ($entry['timestamp'] ?? 0)),
                __('Ability', 'wpmcp')    => (string) ($entry['ability'] ?? ''),
                __('Identity', 'wpmcp')   => (string) ($entry['identity'] ?? ''),
                __('Allowed', 'wpmcp')    => empty($entry['allowed']) ? 'no' : 'yes',
                __('Reason', 'wpmcp')     => (string) ($entry['reason'] ?? ''),
                __('Source', 'wpmcp')     => (string) ($entry['source'] ?? ''),
            ]);
        }
        return [ 'data' => $items, 'done' => true ];
    }

    public static function erase_logs($email, $page = 1): array
    {
        unset($page);
        $email   = trim((string) $email);
        $user_id = self::user_id($email);
        if ('' === $email) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }
        $ips = (array) get_transient(self::ips_key($email));
        delete_transient(self::ips_key($email));
        $removed = false;

        // Request log: unlink the account and drop every value holding the
        // email (captured arguments, error messages). Rows stay, anonymized.
        $rows    = (array) get_option(Request_Log::OPTION, []);
        $changed = false;
        foreach ($rows as $i => $row) {
            if (! is_array($row) || ! self::request_row_is_theirs($row, $email, $user_id)) {
                continue;
            }
            $before = $row;
            if ($user_id > 0 && (int) ($row['user_id'] ?? 0) === $user_id) {
                $row['user_id'] = 0;
            }
            if ($user_id > 0 && 'user:' . $user_id === (string) ($row['client'] ?? '')) {
                $row['client'] = '';
            }
            $row = Snapshot_Redactor::without_email($row, $email);
            if ($row !== $before) {
                $rows[ $i ] = $row;
                $changed    = true;
            }
        }
        if ($changed) {
            update_option(Request_Log::OPTION, $rows, false);
            $removed = true;
        }

        // Audit log: rows this account made lose the account and have the
        // client address anonymized; any row that recorded an address the
        // snapshots tied to the person has it anonymized too.
        $identities = self::audit_identities($user_id);
        $entries    = (array) get_option(Governance_Audit_Log::OPTION, []);
        $changed    = false;
        foreach ($entries as $i => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $before = $entry;
            $own    = in_array((string) ($entry['identity'] ?? ''), $identities, true);
            if ($own) {
                $entry['identity'] = 'erased';
            }
            $reason = (string) ($entry['reason'] ?? '');
            if (1 === preg_match('/^(.*client:)(.+)$/', $reason, $m)) {
                $ip = $m[2];
                if (false !== filter_var($ip, FILTER_VALIDATE_IP) && ($own || in_array($ip, $ips, true))) {
                    $entry['reason'] = $m[1] . wp_privacy_anonymize_ip($ip);
                }
            }
            $entry = Snapshot_Redactor::without_email($entry, $email);
            if ($entry !== $before) {
                $entries[ $i ] = $entry;
                $changed       = true;
            }
        }
        if ($changed) {
            update_option(Governance_Audit_Log::OPTION, $entries);
            $removed = true;
        }

        return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true ];
    }

    // ------------------------------------------------------------------
    // Agent memory.
    // ------------------------------------------------------------------

    /** @return int[] Memory entry ids after $after that mention the email or were written by the account. */
    private static function memory_ids(string $email, int $user_id, int $after): array
    {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($email) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded page of a privacy request over the wpmcp_memory post type; the text, title and targets meta must all be searched, which WP_Query cannot OR together.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.ID > %d AND ((%d > 0 AND p.post_author = %d) OR p.post_title LIKE %s OR p.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s)) ORDER BY p.ID ASC LIMIT %d",
            Memory_Store::POST_TYPE,
            $after,
            $user_id,
            $user_id,
            $like,
            $like,
            Memory_Store::META_TARGETS,
            $like,
            self::ROW_PAGE
        ));
        return array_map('intval', (array) $ids);
    }

    public static function export_memory($email, $page = 1): array
    {
        $email = trim((string) $email);
        if ('' === $email) {
            return [ 'data' => [], 'done' => true ];
        }
        $user_id = self::user_id($email);
        $ids     = self::memory_ids($email, $user_id, self::cursor('export-memory', $email, (int) $page));
        $items   = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (! $post) {
                continue;
            }
            $items[] = self::item(
                'wpmcp-memory',
                __('WP MCP agent memory', 'wpmcp'),
                __('Notes and rules AI agents keep about this site.', 'wpmcp'),
                'wpmcp-memory-' . $id,
                [
                    __('Title', 'wpmcp')        => $post->post_title,
                    __('Text', 'wpmcp')         => $post->post_content,
                    __('Kind', 'wpmcp')         => (string) get_post_meta($id, Memory_Store::META_KIND, true),
                    __('Targets', 'wpmcp')      => implode(', ', array_filter((array) get_post_meta($id, Memory_Store::META_TARGETS, true), 'is_string')),
                    __('Status', 'wpmcp')       => $post->post_status,
                    __('Created (UTC)', 'wpmcp') => $post->post_date_gmt,
                    __('Written by this account', 'wpmcp') => $user_id > 0 && (int) $post->post_author === $user_id ? __('yes', 'wpmcp') : null,
                ]
            );
        }
        $done = count($ids) < self::ROW_PAGE;
        self::advance('export-memory', $email, $ids ? (int) end($ids) : 0, $done);
        return [ 'data' => $items, 'done' => $done ];
    }

    public static function erase_memory($email, $page = 1): array
    {
        $email = trim((string) $email);
        if ('' === $email) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }
        global $wpdb;
        $user_id = self::user_id($email);
        $ids     = self::memory_ids($email, $user_id, self::cursor('erase-memory', $email, (int) $page));
        $removed = false;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (! $post) {
                continue;
            }
            $targets = get_post_meta($id, Memory_Store::META_TARGETS, true);
            if (Snapshot_Redactor::contains_email([ $post->post_title, $post->post_content, $targets ], $email)) {
                // An entry about the person is deleted outright.
                $removed = (bool) wp_delete_post($id, true) || $removed;
                continue;
            }
            // An entry they only wrote keeps its text and loses its author.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- unlinking the author of a wpmcp_memory entry without firing save hooks that would re-run memory validation; the post cache is cleaned on the next line.
            $wpdb->update($wpdb->posts, [ 'post_author' => 0 ], [ 'ID' => $id ]);
            clean_post_cache($id);
            $removed = true;
        }
        Memory_Store::flush_rules_cache();
        $done = count($ids) < self::ROW_PAGE;
        self::advance('erase-memory', $email, $ids ? (int) end($ids) : 0, $done);
        return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => $done ];
    }

    // ------------------------------------------------------------------
    // Search index. Read by table name, so a build that ships without the
    // search tools still clears a table an earlier install left behind.
    // ------------------------------------------------------------------

    private static function search_table(): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wpmcp_search_index';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check for this plugin's own wpmcp_search_index table.
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table ? $table : null;
    }

    /** @return array[] Index rows after $after that hold the email. */
    private static function search_rows(string $email, int $after): array
    {
        global $wpdb;
        $table = self::search_table();
        if (null === $table) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded page of a privacy request over this plugin's own derived index.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, object_type, object_id, source, field, content FROM %i WHERE id > %d AND content LIKE %s ORDER BY id ASC LIMIT %d',
            $table,
            $after,
            '%' . $wpdb->esc_like($email) . '%',
            self::ROW_PAGE
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function export_search_index($email, $page = 1): array
    {
        $email = trim((string) $email);
        $rows  = '' === $email ? [] : self::search_rows($email, self::cursor('export-index', $email, (int) $page));
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::item(
                'wpmcp-search-index',
                __('WP MCP search index', 'wpmcp'),
                __('Text WP MCP copied from site content so AI agents can search it.', 'wpmcp'),
                'wpmcp-search-index-' . $row['id'],
                [
                    __('Indexed object', 'wpmcp') => $row['object_type'] . ' #' . $row['object_id'],
                    __('Source', 'wpmcp')         => $row['source'],
                    __('Field', 'wpmcp')          => $row['field'],
                    __('Text', 'wpmcp')           => $row['content'],
                ]
            );
        }
        $done = count($rows) < self::ROW_PAGE;
        self::advance('export-index', $email, $rows ? (int) end($rows)['id'] : 0, $done);
        return [ 'data' => $items, 'done' => $done ];
    }

    public static function erase_search_index($email, $page = 1): array
    {
        global $wpdb;
        $email = trim((string) $email);
        $rows  = '' === $email ? [] : self::search_rows($email, self::cursor('erase-index', $email, (int) $page));
        $ids   = array_map('intval', array_column($rows, 'id'));
        if ([] !== $ids) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- deletes this page's matched rows from this plugin's own derived index; every id is an int bound through %d.
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', array_merge([ (string) self::search_table() ], $ids)));
        }
        $done = count($rows) < self::ROW_PAGE;
        self::advance('erase-index', $email, $ids ? (int) end($ids) : 0, $done);
        return [
            'items_removed'  => [] !== $ids,
            'items_retained' => false,
            'messages'       => [] !== $ids && $done ? [ __('WP MCP removed search index rows that held the person\'s email; rebuilding the index copies the content again only if the site content still holds it.', 'wpmcp') ] : [],
            'done'           => $done,
        ];
    }
}
