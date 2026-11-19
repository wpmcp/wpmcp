<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FileBird media folders (issue #906) on the plugin-data pair, free tier, so
 * it adds no top-level tools. Ops are prefixed filebird- because the pair is
 * shared.
 *
 * FileBird (6.5.9, includes/Install.php and includes/Model/Folder.php) keeps
 * its folders in {prefix}fbv (id, name, parent with 0 for the root, type 0
 * folder or 1 collection, ord, created_by) and puts an attachment in a folder
 * with a row of {prefix}fbv_attachment_folder (folder_id, attachment_id). An
 * attachment with no row in the current owner's folders is "Uncategorized".
 * With "folder per user" on (option njt_fbv_folder_per_user = '1') every
 * user has a tree of their own (created_by = the user id), otherwise all
 * folders are shared (created_by = 0); every op works in the tree of the
 * current user, as FileBird's own screen does. The colour of a folder is in
 * the option fbv_folder_colors, keyed by folder id.
 *
 * Reads: filebird-list-folders (the tree with attachment counts and the
 * Uncategorized count), filebird-get-folder (one folder, its path, children
 * and a page of its attachments the caller may read) and
 * filebird-get-attachment-folder.
 *
 * Writes (off until a site opts in with wpmcp_enable_filebird_write):
 * create, rename and move a folder, assign up to 200 attachments to a folder
 * (or to Uncategorized with folder_id 0), and delete a folder with its
 * sub-folders. They write the rows the way FileBird's Folder model does
 * (name sanitised the same way, a new folder appended at the end of its
 * parent's order, a move into the folder itself or its descendants refused)
 * and fire FileBird's own fbv_after_* actions. Each is snapshotted as a
 * 'plugin_table_rows' row image: kind fbv for folder rows (a create reserves
 * its id first, so undo removes it) and kind fbv_attachment for the folder
 * rows of the attachments touched. A delete never touches an attachment:
 * it removes the assignment rows, the folder rows and the folders' colours,
 * each under its own snapshot in one session, so rollback-session puts all
 * three back.
 *
 * Every op takes upload_files, the capability FileBird gates its folder API
 * behind; assigning also takes edit_post on each attachment. Presence is
 * filterable (wpmcp_filebird_active, default: the NJFB_VERSION constant).
 */
final class FileBird_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes FileBird's own tables live; it has no cached API for them.

    public const CAPABILITY = 'upload_files';

    public const OPT_IN_FILTER = 'wpmcp_enable_filebird_write';

    public const PLUGIN_FILE = 'filebird/filebird.php';

    public const COLORS_OPTION = 'fbv_folder_colors';

    public const PER_USER_OPTION = 'njt_fbv_folder_per_user';

    public const MAX_ASSIGN = 200;

    public const MAX_PAGE_SIZE = 100;

    /** A delete that would uncategorize more attachments than this is refused (the undo image would be too large). */
    public const MAX_DELETE_ATTACHMENTS = 5000;

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_filebird_active', defined('NJFB_VERSION'));
    }

    public static function folders_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'fbv';
    }

    public static function map_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'fbv_attachment_folder';
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (! self::active()) {
            $refusal = Plugin_Refusal::make('plugin_missing', 'FileBird', self::PLUGIN_FILE);
            if ('inactive' === ($refusal['data']['state'] ?? '')) {
                $refusal['code'] = 'plugin_inactive';
            }
            return $refusal;
        }
        foreach ([ self::folders_table(), self::map_table() ] as $table) {
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [
                    'code'    => 'filebird_tables_missing',
                    'message' => sprintf('FileBird\'s table %s does not exist on this site. Deactivate and reactivate FileBird so it creates its tables, then try again.', $table),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    /** Whose folder tree the current user works in: their own in per-user mode, the shared one (0) otherwise. */
    public static function owner(): int
    {
        return '1' === (string) get_option(self::PER_USER_OPTION, '0') ? get_current_user_id() : 0;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $parent = [ 'type' => 'integer', 'minimum' => 0 ];
        $name   = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 250 ];
        $one    = [ 'type' => 'object', 'properties' => [ 'folder_id' => $id ], 'required' => [ 'folder_id' ] ];
        $base   = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_filebird_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $folder = static fn (array $args): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('fbv', 'fbv', [ (int) $args['folder_id'] ]),
        ];

        return [
            'filebird-list-folders'          => $base + [
                'mode'         => 'read',
                'description'  => 'FileBird media folder tree of the current user\'s folders: id, name, parent, type (folder or collection), attachment_count and children, in FileBird\'s order, plus the Uncategorized count and owner_scope (per-user mode)',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::list_folders(),
            ],
            'filebird-get-folder'            => $base + [
                'mode'         => 'read',
                'description'  => 'One FileBird folder: name, parent, path, children and a page of its attachments (id, title, mime type) the caller may read. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'folder_id' => $id,
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                    'required'   => [ 'folder_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::folder_refusal((int) $args['folder_id']),
                'handler'      => static fn (array $args): array => self::get_folder($args),
            ],
            'filebird-get-attachment-folder' => $base + [
                'mode'         => 'read',
                'objects'      => [ 'attachment_id' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'The FileBird folder (id, name, path) one attachment is in, or folder null when it is Uncategorized',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'attachment_id' => $id ], 'required' => [ 'attachment_id' ] ],
                'validate'     => static fn (array $args): ?array => self::attachments_refusal([ (int) $args['attachment_id'] ], false),
                'handler'      => static fn (array $args): array => self::attachment_folder((int) $args['attachment_id']),
            ],
            'filebird-create-folder'         => $write + [
                'mode'         => 'write',
                'description'  => 'Create a FileBird media folder: name (sanitised as FileBird does), parent_id (0 or omitted: top level). A name its parent already holds is refused. Undo: rollback-operation removes it',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'name' => $name, 'parent_id' => $parent ], 'required' => [ 'name' ] ],
                'validate'     => static fn (array $args): ?array => self::create_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('fbv', 'fbv', [ self::next_id() ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'filebird-rename-folder'         => $write + [
                'mode'         => 'write',
                'description'  => 'Rename a FileBird folder. A name a sibling already holds is refused. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'folder_id' => $id, 'name' => $name ], 'required' => [ 'folder_id', 'name' ] ],
                'validate'     => static fn (array $args): ?array => self::rename_refusal($args),
                'snapshot'     => $folder,
                'handler'      => static fn (array $args): array => self::rename($args),
            ],
            'filebird-move-folder'           => $write + [
                'mode'         => 'write',
                'description'  => 'Move a FileBird folder under parent_id (0: top level), last in its new parent\'s order. A move into itself or one of its sub-folders, or next to a folder of the same name, is refused. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'folder_id' => $id, 'parent_id' => $parent ], 'required' => [ 'folder_id', 'parent_id' ] ],
                'validate'     => static fn (array $args): ?array => self::move_refusal($args),
                'snapshot'     => $folder,
                'handler'      => static fn (array $args): array => self::move($args),
            ],
            'filebird-assign-attachments'    => $write + [
                'mode'         => 'write',
                'objects'      => [ 'attachment_ids.*' => 'post', 'attachment_ids' => 'post' ],
                'description'  => 'Put attachments into a FileBird folder (folder_id 0: Uncategorized), out of whatever folder of the current tree they were in. attachment_ids up to 200; each reports moved or already. Undo: rollback-operation restores the whole batch',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'folder_id'      => $parent,
                        'attachment_ids' => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => self::MAX_ASSIGN, 'uniqueItems' => true ],
                    ],
                    'required'   => [ 'folder_id', 'attachment_ids' ],
                ],
                'validate'     => static fn (array $args): ?array => self::assign_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('fbv_attachment', 'attachment_folder', array_map('intval', (array) $args['attachment_ids'])),
                ],
                'handler'      => static fn (array $args): array => self::assign($args),
            ],
            'filebird-delete-folder'         => $write + [
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'description'       => 'Delete a FileBird folder and all its sub-folders. No attachment is deleted: the ones inside become Uncategorized. Needs confirm:true after a preview of the folders and how many attachments it affects. Undo: rollback-session restores the folders, their colours and every assignment',
                'input_schema'      => $one,
                'validate'          => static fn (array $args): ?array => self::delete_refusal((int) $args['folder_id']),
                'confirm_preview'   => static fn (array $args): array => self::delete_preview((int) $args['folder_id']),
                'handler'           => static fn (array $args, array $context): array => self::delete((int) $args['folder_id'], $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array<string, string|null>|null A folder row of the current tree, or null. */
    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND created_by = %d', self::folders_table(), $id, self::owner()), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, string|null>> Every folder row of the current tree, in FileBird's order. */
    private static function rows(): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE created_by = %d ORDER BY ord ASC, id ASC', self::folders_table(), self::owner()), ARRAY_A);
    }

    /** The name the way FileBird's Folder model stores it. */
    public static function clean_name(string $name): string
    {
        $name = (string) preg_replace('/^[\s=+\-@|]+/u', '', $name);
        return sanitize_text_field(wp_kses_post($name));
    }

    private static function sibling_named(string $name, int $parent, int $except = 0): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE name = %s AND parent = %d AND created_by = %d AND id != %d', self::folders_table(), $name, $parent, self::owner(), $except));
        return null === $id ? null : (int) $id;
    }

    private static function next_id(): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', self::folders_table()));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::folders_table()), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    private static function last_ord(int $parent): int
    {
        global $wpdb;
        $ord = $wpdb->get_var($wpdb->prepare('SELECT MAX(ord) FROM %i WHERE parent = %d AND created_by = %d', self::folders_table(), $parent, self::owner()));
        return null === $ord ? 0 : (int) $ord + 1;
    }

    /** @return string[] Names from the top level down to the folder. */
    private static function path(int $id): array
    {
        $names = [];
        $seen  = [];
        while ($id > 0 && ! isset($seen[ $id ])) {
            $seen[ $id ] = true;
            $row         = self::row($id);
            if (null === $row) {
                break;
            }
            array_unshift($names, (string) $row['name']);
            $id = (int) $row['parent'];
        }
        return $names;
    }

    /** @return int[] The folder and every folder below it, in the current tree. */
    private static function subtree(int $id): array
    {
        global $wpdb;
        $out   = [];
        $queue = [ $id ];
        while ([] !== $queue) {
            $next = (int) array_shift($queue);
            if (isset($out[ $next ])) {
                continue;
            }
            $out[ $next ] = true;
            $children     = (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE parent = %d AND created_by = %d', self::folders_table(), $next, self::owner()));
            foreach ($children as $child) {
                $queue[] = (int) $child;
            }
        }
        return array_keys($out);
    }

    /**
     * Attachments in any of the folders, distinct.
     *
     * @param int[] $folders
     * @return int[]
     */
    private static function attachments_in(array $folders): array
    {
        global $wpdb;
        if ([] === $folders) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($folders), '%d'));
        $sql = "SELECT DISTINCT attachment_id FROM %i WHERE folder_id IN ($in) ORDER BY attachment_id";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, self::map_table(), ...$folders)));
    }

    /** @return array<int, int> folder id => attachments in it that are not in the trash. */
    private static function counts(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT m.folder_id, COUNT(*) AS c FROM %i m
             INNER JOIN %i p ON p.ID = m.attachment_id AND p.post_type = 'attachment' AND p.post_status != 'trash'
             INNER JOIN %i f ON f.id = m.folder_id AND f.created_by = %d
             GROUP BY m.folder_id",
            self::map_table(),
            $wpdb->posts,
            self::folders_table(),
            self::owner()
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[ (int) $row['folder_id'] ] = (int) $row['c'];
        }
        return $out;
    }

    private static function uncategorized(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i p WHERE p.post_type = 'attachment' AND p.post_status != 'trash'
             AND NOT EXISTS (SELECT 1 FROM %i m INNER JOIN %i f ON f.id = m.folder_id AND f.created_by = %d WHERE m.attachment_id = p.ID)",
            $wpdb->posts,
            self::map_table(),
            self::folders_table(),
            self::owner()
        ));
    }

    /** @return array<string, mixed> */
    private static function view(array $row, ?int $count = null): array
    {
        $view = [
            'id'     => (int) $row['id'],
            'name'   => (string) $row['name'],
            'parent' => (int) $row['parent'],
            'type'   => 1 === (int) $row['type'] ? 'collection' : 'folder',
            'ord'    => (int) $row['ord'],
        ];
        if (null !== $count) {
            $view['attachment_count'] = $count;
        }
        return $view;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_folders(): array
    {
        $rows   = self::rows();
        $counts = self::counts();
        $ids    = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $by     = [];
        foreach ($rows as $row) {
            $parent          = (int) $row['parent'];
            $parent          = in_array($parent, $ids, true) && $parent !== (int) $row['id'] ? $parent : 0;
            $by[ $parent ][] = $row;
        }
        $seen  = [];
        $build = static function (int $parent) use (&$build, &$seen, $by, $counts): array {
            $out = [];
            foreach ($by[ $parent ] ?? [] as $row) {
                $id = (int) $row['id'];
                if (isset($seen[ $id ])) {
                    continue;
                }
                $seen[ $id ]     = true;
                $view            = self::view($row, $counts[ $id ] ?? 0);
                $view['children'] = $build($id);
                $out[]           = $view;
            }
            return $out;
        };

        return [
            'folders'       => $build(0),
            'total_folders' => count($rows),
            'uncategorized' => self::uncategorized(),
            'owner_scope'   => [ 'per_user' => '1' === (string) get_option(self::PER_USER_OPTION, '0'), 'owner' => self::owner() ],
        ];
    }

    /** @return array<string, mixed> */
    private static function get_folder(array $args): array
    {
        global $wpdb;
        $id       = (int) $args['folder_id'];
        $per_page = min(self::MAX_PAGE_SIZE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $row      = (array) self::row($id);
        $children = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE parent = %d AND created_by = %d ORDER BY ord ASC, id ASC', self::folders_table(), $id, self::owner()), ARRAY_A);

        $from  = "FROM %i m INNER JOIN %i p ON p.ID = m.attachment_id AND p.post_type = 'attachment' AND p.post_status != 'trash' WHERE m.folder_id = %d";
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) ' . $from, self::map_table(), $wpdb->posts, $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- literal fragment with %i and %d placeholders only.
        $ids   = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT p.ID ' . $from . ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d', self::map_table(), $wpdb->posts, $id, $per_page, ($page - 1) * $per_page))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- literal fragment with %i and %d placeholders only.

        $attachments = [];
        foreach ($ids as $attachment) {
            if (! current_user_can('read_post', $attachment)) {
                continue;
            }
            $post          = get_post($attachment);
            $attachments[] = [ 'id' => $attachment, 'title' => (string) $post->post_title, 'mime_type' => (string) $post->post_mime_type ];
        }

        $folder             = self::view($row, $total);
        $folder['path']     = self::path($id);
        $folder['children'] = array_map(static fn (array $child): array => self::view($child), $children);

        return [ 'folder' => $folder, 'attachments' => $attachments, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    /** @return array<string, mixed> */
    private static function attachment_folder(int $attachment): array
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT m.folder_id FROM %i m INNER JOIN %i f ON f.id = m.folder_id AND f.created_by = %d WHERE m.attachment_id = %d ORDER BY m.folder_id LIMIT 1',
            self::map_table(),
            self::folders_table(),
            self::owner(),
            $attachment
        ));
        if (null === $id) {
            return [ 'attachment_id' => $attachment, 'folder' => null ];
        }
        $row = (array) self::row((int) $id);
        return [ 'attachment_id' => $attachment, 'folder' => [ 'id' => (int) $id, 'name' => (string) ($row['name'] ?? ''), 'path' => self::path((int) $id) ] ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        global $wpdb;
        $parsed = Plugin_Table_Rows_Snapshot::parse((string) ($context['target']['object_id'] ?? ''));
        $id     = (int) ($parsed['ids'][0] ?? 0);
        $parent = (int) ($args['parent_id'] ?? 0);
        $name   = self::clean_name((string) $args['name']);
        $data   = [
            'id'         => $id,
            'name'       => $name,
            'parent'     => $parent,
            'type'       => 0,
            'ord'        => self::last_ord($parent),
            'created_by' => self::owner(),
        ];
        if ($id < 1 || false === $wpdb->insert(self::folders_table(), $data)) {
            throw new Operation_Refused('folder_not_created', 'FileBird folder could not be created: ' . $wpdb->last_error . ' Try again.');
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FileBird's own hook, run so its extensions see the write.
        do_action('fbv_after_folder_created', $id, [ 'title' => $name, 'id' => $id, 'key' => $id, 'type' => 0, 'parent' => $parent, 'children' => [], 'data-count' => 0, 'data-id' => $id ]);
        return [ 'folder' => self::view((array) self::row($id), 0) + [ 'path' => self::path($id) ] ];
    }

    /** @return array<string, mixed> */
    private static function rename(array $args): array
    {
        global $wpdb;
        $id   = (int) $args['folder_id'];
        $name = self::clean_name((string) $args['name']);
        if (false === $wpdb->update(self::folders_table(), [ 'name' => $name ], [ 'id' => $id ], [ '%s' ], [ '%d' ])) {
            throw new Operation_Refused('folder_not_updated', 'FileBird folder could not be renamed: ' . $wpdb->last_error);
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FileBird's own hook, run so its extensions see the write.
        do_action('fbv_after_folder_renamed', $id, $name);
        return [ 'folder' => self::view((array) self::row($id)) + [ 'path' => self::path($id) ] ];
    }

    /** @return array<string, mixed> */
    private static function move(array $args): array
    {
        global $wpdb;
        $id     = (int) $args['folder_id'];
        $parent = (int) $args['parent_id'];
        if (false === $wpdb->update(self::folders_table(), [ 'parent' => $parent, 'ord' => self::last_ord($parent) ], [ 'id' => $id ], [ '%d', '%d' ], [ '%d' ])) {
            throw new Operation_Refused('folder_not_updated', 'FileBird folder could not be moved: ' . $wpdb->last_error);
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FileBird's own hook, run so its extensions see the write.
        do_action('fbv_after_parent_updated', $id, $parent);
        return [ 'folder' => self::view((array) self::row($id)) + [ 'path' => self::path($id) ] ];
    }

    /** @return array<string, mixed> */
    private static function assign(array $args): array
    {
        global $wpdb;
        $folder = (int) $args['folder_id'];
        $ids    = array_values(array_unique(array_map('intval', (array) $args['attachment_ids'])));
        $result = [];
        foreach ($ids as $attachment) {
            $current = $wpdb->get_col($wpdb->prepare(
                'SELECT m.folder_id FROM %i m INNER JOIN %i f ON f.id = m.folder_id AND f.created_by = %d WHERE m.attachment_id = %d',
                self::map_table(),
                self::folders_table(),
                self::owner(),
                $attachment
            ));
            $current = array_map('intval', (array) $current);
            if ((0 === $folder && [] === $current) || [ $folder ] === $current) {
                $result[] = [ 'id' => $attachment, 'status' => 'already' ];
                continue;
            }
            $deleted = $wpdb->query($wpdb->prepare(
                'DELETE m FROM %i m INNER JOIN %i f ON f.id = m.folder_id AND f.created_by = %d WHERE m.attachment_id = %d',
                self::map_table(),
                self::folders_table(),
                self::owner(),
                $attachment
            ));
            if (false === $deleted || ($folder > 0 && false === $wpdb->insert(self::map_table(), [ 'folder_id' => $folder, 'attachment_id' => $attachment ], [ '%d', '%d' ]))) {
                throw new Operation_Refused('assign_failed', sprintf('Attachment %d could not be put in FileBird folder %d: %s', $attachment, $folder, $wpdb->last_error));
            }
            clean_post_cache($attachment);
            $result[] = [ 'id' => $attachment, 'status' => 'moved' ];
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FileBird's own hook, run so its extensions see the write.
        do_action('fbv_after_assign_folder', $folder, $ids);
        return [ 'folder_id' => $folder, 'attachments' => $result ];
    }

    /** @return array<string, mixed> */
    private static function delete_preview(int $id): array
    {
        $folders = self::subtree($id);
        $names   = [];
        foreach ($folders as $folder) {
            $row     = self::row($folder);
            $names[] = [ 'id' => $folder, 'name' => (string) ($row['name'] ?? ''), 'path' => self::path($folder) ];
        }
        $count = count(self::attachments_in($folders));
        return [
            'message' => sprintf(
                'Deletes %d FileBird folder(s): %s. No attachment is deleted; the %d in them become Uncategorized. Undo: rollback-session.',
                count($folders),
                implode(', ', array_map(static fn (array $f): string => implode(' / ', $f['path']), $names)),
                $count
            ),
            'data'    => [ 'folders' => $names, 'attachments_uncategorized' => $count ],
        ];
    }

    /**
     * The assignment rows, the folder rows and the colours, each under its
     * own snapshot in the call's session.
     *
     * @return array{result: array<string, mixed>, operation_ids: string[]}
     */
    private static function delete(int $id, array $context): array
    {
        global $wpdb;
        $folders     = self::subtree($id);
        $attachments = self::attachments_in($folders);
        $in          = implode(',', array_fill(0, count($folders), '%d'));
        $ops         = [];
        $base        = [
            'session_id' => (string) $context['session_id'],
            'tool_name'  => (string) $context['tool_name'],
            'args'       => [ 'operation' => (string) $context['operation'], 'args' => [ 'folder_id' => $id ] ],
        ];

        if ([] !== $attachments) {
            $out   = Safe_Mutation::run(
                $base + [ 'object_type' => Plugin_Table_Rows_Snapshot::TYPE, 'object_id' => Plugin_Table_Rows_Snapshot::key('fbv_attachment', 'attachment_folder', $attachments) ],
                static function () use ($wpdb, $in, $folders): int {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
                    $done = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE folder_id IN ($in)", self::map_table(), ...$folders));
                    if (false === $done) {
                        throw new Operation_Refused('delete_failed', 'FileBird folder assignments could not be removed: ' . $wpdb->last_error);
                    }
                    return (int) $done;
                }
            );
            $ops[] = $out['operation_id'];
        }

        $out   = Safe_Mutation::run(
            $base + [ 'object_type' => Plugin_Table_Rows_Snapshot::TYPE, 'object_id' => Plugin_Table_Rows_Snapshot::key('fbv', 'fbv', $folders) ],
            static function () use ($wpdb, $in, $folders): int {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
                $done = $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ($in)", self::folders_table(), ...$folders));
                if (false === $done) {
                    throw new Operation_Refused('delete_failed', 'FileBird folders could not be deleted: ' . $wpdb->last_error);
                }
                foreach ($folders as $folder) {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FileBird's own hook, run so its extensions see the write.
                    do_action('fbv_after_folder_deleted', $folder);
                }
                return (int) $done;
            }
        );
        $ops[] = $out['operation_id'];

        $colors = get_option(self::COLORS_OPTION, []);
        if (is_array($colors) && [] !== array_intersect_key($colors, array_flip($folders))) {
            $out   = Safe_Mutation::run(
                $base + [
                    'object_type'         => 'option',
                    'object_id'           => self::COLORS_OPTION,
                    'extra_snapshot_data' => [ 'restore_capability' => self::CAPABILITY ],
                ],
                static fn (): bool => update_option(self::COLORS_OPTION, array_diff_key($colors, array_flip($folders)))
            );
            $ops[] = $out['operation_id'];
        }

        foreach ($attachments as $attachment) {
            clean_post_cache($attachment);
        }

        return [
            'result'        => [ 'deleted_folders' => $folders, 'attachments_uncategorized' => count($attachments) ],
            'operation_ids' => $ops,
        ];
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    private static function folder_refusal(int $id): ?array
    {
        if (null === self::row($id)) {
            return self::refusal('folder_not_found', sprintf('FileBird folder %d does not exist in your folder tree. Call filebird-list-folders for the folders there are.', $id), [ 'folder_id' => $id ]);
        }
        return null;
    }

    private static function parent_refusal(int $parent): ?array
    {
        return $parent > 0 ? self::folder_refusal($parent) : null;
    }

    private static function name_refusal(string $raw, int $parent, int $except = 0): ?array
    {
        $name = self::clean_name($raw);
        if ('' === $name) {
            return self::refusal('invalid_name', 'The folder name is empty once sanitised. Give a name with letters or digits.', []);
        }
        $other = self::sibling_named($name, $parent, $except);
        if (null !== $other) {
            return self::refusal('folder_exists', sprintf('A FileBird folder named "%s" already exists there (id %d).', $name, $other), [ 'folder_id' => $other ]);
        }
        return null;
    }

    private static function create_refusal(array $args): ?array
    {
        $parent = (int) ($args['parent_id'] ?? 0);
        return self::parent_refusal($parent) ?? self::name_refusal((string) $args['name'], $parent);
    }

    private static function rename_refusal(array $args): ?array
    {
        $id = (int) $args['folder_id'];
        return self::folder_refusal($id) ?? self::name_refusal((string) $args['name'], (int) (self::row($id)['parent'] ?? 0), $id);
    }

    private static function move_refusal(array $args): ?array
    {
        $id     = (int) $args['folder_id'];
        $parent = (int) $args['parent_id'];
        $none   = self::folder_refusal($id) ?? self::parent_refusal($parent);
        if (null !== $none) {
            return $none;
        }
        if (in_array($parent, self::subtree($id), true)) {
            return self::refusal('folder_cycle', sprintf('FileBird folder %d cannot move into itself or one of its sub-folders (%d).', $id, $parent), [ 'folder_id' => $id, 'parent_id' => $parent ]);
        }
        return self::name_refusal((string) (self::row($id)['name'] ?? ''), $parent, $id);
    }

    /** @param int[] $ids */
    private static function attachments_refusal(array $ids, bool $write): ?array
    {
        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (! $post instanceof \WP_Post || 'attachment' !== $post->post_type) {
                return self::refusal('attachment_not_found', sprintf('%d is not an attachment in the Media Library.', (int) $id), [ 'id' => (int) $id ]);
            }
            if (! current_user_can($write ? 'edit_post' : 'read_post', (int) $id)) {
                return self::refusal('operation_denied', sprintf('You are not allowed to %s attachment %d.', $write ? 'edit' : 'read', (int) $id), [ 'reason' => 'capability', 'id' => (int) $id ]);
            }
        }
        return null;
    }

    private static function assign_refusal(array $args): ?array
    {
        return self::parent_refusal((int) $args['folder_id']) ?? self::attachments_refusal(array_map('intval', (array) $args['attachment_ids']), true);
    }

    private static function delete_refusal(int $id): ?array
    {
        $none = self::folder_refusal($id);
        if (null !== $none) {
            return $none;
        }
        $count = count(self::attachments_in(self::subtree($id)));
        if ($count > self::MAX_DELETE_ATTACHMENTS) {
            return self::refusal('too_many_attachments', sprintf('FileBird folder %d and its sub-folders hold %d attachments, more than %d. Move some out with filebird-assign-attachments first, then delete it.', $id, $count, self::MAX_DELETE_ATTACHMENTS), [ 'folder_id' => $id, 'attachments' => $count ]);
        }
        return null;
    }
}
