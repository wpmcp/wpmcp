<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared base for the forms adapters (issue #66).
 *
 * What every forms adapter agrees on lives here rather than being re-derived
 * in each one, and tests/support/Forms_Adapter_Conformance.php holds every
 * subclass to it:
 *
 *  - Registration follows the host plugin. A forms pair registers only while
 *    its plugin is loaded, so a site without Gravity Forms is not offered two
 *    tools that could only ever answer integration_unavailable. The dispatcher
 *    contract underneath is unchanged: a pair that IS registered still
 *    answers list-operations and refuses every other op in a structured way
 *    if the plugin goes away mid-request.
 *  - One paging vocabulary for entry listings: page_size (default 20, max
 *    100) plus offset.
 *  - Submissions are user data, so entry reads and writes carry a per-op
 *    capability above the pair's own edit_posts. ENTRY_CAPABILITY is the
 *    default; an adapter whose host maps entry access to a different core
 *    capability (Contact Form 7's Flamingo maps it to edit_users) uses that
 *    instead, and the conformance suite accepts either.
 *  - A status change on an entry that lives in a host plugin's own table is
 *    snapshotted as a db_rows before-image of exactly that row (see
 *    row_snapshot()), so it is restorable with rollback-operation like every
 *    other write. Restoring raw table rows is an administrator action in
 *    Rollback_Service, which is one more reason the entry ops sit at
 *    manage_options.
 */
abstract class Forms_Integration extends Integration_Dispatcher
{
    /** Default capability for submission (PII) operations. */
    public const ENTRY_CAPABILITY = 'manage_options';

    /**
     * The one opt-in switch for every forms write that edits configuration
     * (notifications, confirmations) or deletes an entry (issues #674, #675).
     * Off by default; refused with opt_in_required until a site returns true.
     */
    public const OPT_IN_FILTER = 'wpmcp_enable_forms_write';

    /** Default and ceiling for page_size on entry listings. */
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE     = 100;

    public function registers_only_when_available(): bool
    {
        return true;
    }

    /**
     * One plugin per forms adapter. Every adapter's summary opens with its
     * plugin's name followed by what it covers in parentheses ("Ninja Forms
     * (forms, fields, ...)"), and the conformance suite holds each one to it.
     */
    public function host_plugin(): array
    {
        $summary = $this->summary();
        $cut     = strpos($summary, ' (');

        return [ 'name' => false === $cut ? $summary : substr($summary, 0, $cut), 'several' => false ];
    }

    /** The page_size + offset schema properties every entry listing shares. */
    protected static function paging_properties(): array
    {
        return [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
    }

    /** @return array{0:int,1:int} [page_size, offset] from already-validated args. */
    protected static function page_window(array $args): array
    {
        $size = (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE);
        $size = max(1, min(self::MAX_PAGE_SIZE, $size));

        return [ $size, max(0, (int) ($args['offset'] ?? 0)) ];
    }

    /**
     * Snapshot target for an in-place update of ONE row in a host plugin's own
     * table: a db_rows before-image of that row, keyed by its primary key,
     * which Rollback_Service restores exactly (warning if the row changed
     * again in between).
     *
     * Returns null when there is nothing honest to snapshot: the table does
     * not exist on this site, or no row has that id. The dispatcher then runs
     * the op unsnapshotted and flags it recoverable:false, and every caller
     * of this helper also refuses a missing row itself, so the null case
     * never mutates anything in practice.
     *
     * @param string               $table_suffix Table name without $wpdb->prefix.
     * @param array<string, mixed> $set          Column => value the op will write.
     */
    protected static function row_snapshot(string $table_suffix, string $primary_key, int $id, array $set): ?array
    {
        global $wpdb;

        $table = Database_Guard::valid_table($wpdb->prefix . $table_suffix);
        if (is_wp_error($table) || $id < 1) {
            return null;
        }

        $where  = [ $primary_key => $id ];
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
                'primary_key' => [ $primary_key ],
                'where'       => $where,
                'set'         => $set,
                'rows'        => $before,
            ],
        ];
    }
    /**
     * The flags every default-off forms write carries (Core_Site_Pack shape):
     * off unless the site opts in through OPT_IN_FILTER, and the refusal names
     * that filter.
     *
     * @return array{enabled_by_default: bool, opt_in_filter: string}
     */
    protected static function write_flags(): array
    {
        return [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_forms_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
    }

    /**
     * Snapshot target for deleting ONE row of a host plugin's own table: a
     * db_rows before-image (operation delete) that rollback-operation puts
     * back at its original primary key. Null when the table or the row does
     * not exist, so a wrong id never snapshots anything.
     */
    protected static function delete_row_snapshot(string $table_suffix, string $primary_key, int $id): ?array
    {
        global $wpdb;

        $table = Database_Guard::valid_table($wpdb->prefix . $table_suffix);
        if (is_wp_error($table) || $id < 1) {
            return null;
        }
        $where  = [ $primary_key => $id ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }

        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [
                'table'       => $table,
                'operation'   => 'delete',
                'primary_key' => [ $primary_key ],
                'where'       => $where,
                'set'         => [],
                'rows'        => $before,
            ],
        ];
    }

    /**
     * The confirmation every forms delete-entry shows (issue #946), so the
     * person approving it reads, in one wording across adapters, which entry
     * goes, how many stored values it holds, and that the delete is not an
     * erasure: the undo snapshot keeps a plaintext copy until it is pruned.
     *
     * @param string               $entry      What is deleted, e.g. "Fluent Forms entry 3 of form 2".
     * @param int                  $entry_id   The entry id, repeated in data.
     * @param int                  $values     Stored values the entry holds.
     * @param string               $undo       The undo tool: rollback-operation or rollback-session.
     * @param array<string, mixed> $data       Extra preview data (form_id, date, ...), kept in that order after entry_id.
     * @param bool                 $files_stay Whether the plugin keeps uploads as files the delete leaves on disk.
     * @param string               $extra      Appended inside the parentheses, e.g. ", 1 child entries".
     * @return array{message: string, data: array<string, mixed>}
     */
    protected static function delete_entry_preview(string $entry, int $entry_id, int $values, string $undo, array $data = [], bool $files_stay = true, string $extra = ''): array
    {
        $message = sprintf('It permanently deletes %s (%d stored values%s).', $entry, $values, $extra);
        if ($files_stay) {
            $message .= ' Uploaded files stay on disk.';
        }
        $message .= ' This is not an erasure: the undo snapshot keeps a plaintext copy of the entry until the snapshot is pruned.';
        $message .= ' Undo: ' . $undo . '.';

        return [
            'message' => $message,
            'data'    => [ 'entry_id' => $entry_id ] + $data + [
                'values'             => $values,
                'files_stay_on_disk' => $files_stay,
                'erasure'            => false,
            ],
        ];
    }

    /**
     * How many rows a delete would remove from one table (capped at the
     * before-image cap plus one), 0 when the table is absent.
     */
    protected static function count_rows(string $table_suffix, string $column, int $value): int
    {
        global $wpdb;

        $table = $wpdb->prefix . $table_suffix;
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return 0;
        }

        return count(Database_Guard::before_image($table, [ $column => $value ], Database_Guard::BEFORE_IMAGE_CAP + 1));
    }

    /**
     * A refusal when the entry has financial records, which an entry delete
     * never removes: orders, transactions and subscriptions are accounting
     * data the site and the payment processor still rely on.
     *
     * @param array<int, array{0: string, 1: string}> $tables [table suffix, column naming the entry] pairs
     * @return array<string, mixed>|null
     */
    protected static function payment_refusal(int $entry_id, array $tables): ?array
    {
        foreach ($tables as [ $table, $column ]) {
            if (self::count_rows($table, $column, $entry_id) > 0) {
                return [
                    'code'    => 'entry_has_payments',
                    'message' => 'This entry has payment records (orders, transactions or subscriptions). Financial records are not deleted here.',
                    'data'    => [ 'entry_id' => $entry_id, 'table' => $table ],
                ];
            }
        }

        return null;
    }

    /**
     * Delete rows from several tables of a host plugin in ONE session, each
     * table's rows under its own db_rows before-image (the WS Form delete
     * pattern, #676), so a single rollback-session puts everything back.
     * Rows are removed in the order given: children first, the entry row last.
     * More rows than the before-image holds are refused, never truncated.
     *
     * @param array<int, array{table: string, primary_key: string[], where: array<string, mixed>, optional?: bool}> $steps
     * @param array<string, mixed> $args    The op's args, recorded with each snapshot.
     * @param array<string, mixed> $context The dispatcher's self-snapshotting context.
     * @return array{result: array<string, int>, operation_ids: string[]} Rows deleted per table.
     */
    protected static function delete_rows_in_session(array $steps, array $args, array $context): array
    {
        global $wpdb;

        // Capture every before-image first so a refusal (cap, missing table)
        // happens before the first row is touched.
        $plan = [];
        foreach ($steps as $step) {
            $name = $wpdb->prefix . $step['table'];
            if (! Plugin_Table_Rows_Snapshot::table_exists($name)) {
                if (! empty($step['optional'])) {
                    continue;
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                throw new Operation_Refused('forms_tables_missing', sprintf('The table %s is missing.', $name));
            }
            $table = Database_Guard::valid_table($name);
            if (is_wp_error($table)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                throw new Operation_Refused('forms_tables_missing', sprintf('The table %s is missing.', $name));
            }
            $before = Database_Guard::before_image($table, $step['where'], Database_Guard::BEFORE_IMAGE_CAP + 1);
            if (count($before) > Database_Guard::BEFORE_IMAGE_CAP) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                throw new Operation_Refused('entry_too_large', sprintf('The entry has more than %d rows in %s, more than a snapshot can hold, so it is not deleted.', Database_Guard::BEFORE_IMAGE_CAP, $name));
            }
            if ([] !== $before) {
                $plan[] = [ $table, $step, $before ];
            }
        }

        $ids    = [];
        $counts = [];
        try {
            foreach ($plan as [ $table, $step, $before ]) {
                $where = $step['where'];
                $out   = Safe_Mutation::run(
                    [
                    'object_type'         => 'db_rows',
                    'object_id'           => $table,
                    'session_id'          => (string) $context['session_id'],
                    'tool_name'           => (string) $context['tool_name'],
                    'args'                => [ 'operation' => (string) $context['operation'], 'args' => $args ],
                    'extra_snapshot_data' => [
                        'table'       => $table,
                        'operation'   => 'delete',
                        'primary_key' => $step['primary_key'],
                        'where'       => $where,
                        'set'         => [],
                        'rows'        => $before,
                    ],
                    ],
                    static function () use ($table, $where, $before): int {
                        global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the rows of the host plugin's own table, which the db_rows before-image above covers; the object cache is invalidated right after.
                        $affected = $wpdb->delete($table, $where);
                        if (false === $affected) {
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
                            throw new Operation_Refused('delete_failed', sprintf('Could not delete rows of %s: %s', $table, $wpdb->last_error));
                        }
                        Database_Guard::invalidate_caches($table, [ 'rows' => $before, 'where' => $where ]);
                        return (int) $affected;
                    }
                );
                $ids[]                   = (string) $out['operation_id'];
                $counts[ $step['table'] ] = (int) $out['result'];
            }
        } catch (\Throwable $e) {
            // All or nothing: put back every table already deleted, newest first,
            // so a failure part-way never leaves an entry half removed.
            $undone = [];
            $failed = [];
            foreach (array_reverse($ids) as $operation_id) {
                try {
                    $result = ( new \WPMCP\Tools\Rollback_Operation() )->handle([ 'operation_id' => $operation_id ]);
                    if (! empty($result['restored'])) {
                        $undone[] = $operation_id;
                    } else {
                        $failed[] = $operation_id;
                    }
                } catch (\Throwable $undo_error) {
                    $failed[] = $operation_id;
                }
            }
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
            throw new Operation_Refused(
                [] === $failed ? 'delete_failed' : 'delete_partial',
                [] === $failed
                    ? 'The delete failed part-way and every table already deleted was put back; nothing was removed.'
                    : 'The delete failed part-way and some tables could not be put back automatically: restore them with rollback-operation using the operation ids.',
                [ 'restored' => $undone, 'not_restored' => $failed, 'cause' => $e->getMessage() ]
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return [ 'result' => $counts, 'operation_ids' => $ids ];
    }

    // ------------------------------------------------------------------
    // Notification and confirmation edits (issue #674)
    // ------------------------------------------------------------------

    /**
     * Why $value is not an acceptable address list, or null when it is.
     *
     * Each comma-separated item must be a plain address, "Name <address>", or
     * a merge tag of the form plugin ([your-email], {admin_email},
     * {field:ID}); a line break anywhere is refused (header injection).
     * Syntax only: a site that wants a domain allowlist filters
     * wpmcp_forms_allowed_recipient_domains, which receives the domains found
     * and returns the ones that may stay.
     */
    protected static function address_problem(string $value, string $label, bool $allow_empty, bool $single = false): ?string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            return sprintf('%s must not contain line breaks.', $label);
        }
        $value = trim($value);
        if ('' === $value) {
            return $allow_empty ? null : sprintf('%s must not be empty.', $label);
        }
        $items = $single ? [ $value ] : array_map('trim', explode(',', $value));
        $found = [];
        foreach ($items as $item) {
            if ('' === $item) {
                return sprintf('%s has an empty item between commas.', $label);
            }
            $address = $item;
            if (preg_match('/^[^<>]*<([^<>]+)>$/', $item, $m)) {
                $address = trim($m[1]);
            }
            if (preg_match('/^(\[[A-Za-z0-9_:.\-]+\]|\{[A-Za-z0-9_:.="\' \-]+\})$/', $address)) {
                continue;
            }
            if (! is_email($address)) {
                return sprintf('%s: "%s" is not an email address or a merge tag.', $label, $item);
            }
            $found[] = strtolower((string) substr(strrchr($address, '@'), 1));
        }
        $allowed = apply_filters('wpmcp_forms_allowed_recipient_domains', null, $found);
        if (is_array($allowed) && array_diff($found, array_map('strtolower', $allowed))) {
            return sprintf('%s: a domain is not on this site\'s allowed recipient list (wpmcp_forms_allowed_recipient_domains).', $label);
        }

        return null;
    }

    /**
     * Why a confirmation redirect or page is not acceptable, or null.
     *
     * @param array<string, mixed> $final type, message, page and url as they will be stored
     */
    protected static function confirmation_problem(array $final): ?string
    {
        switch ($final['type']) {
            case 'message':
                return '' === trim((string) $final['message']) ? 'A message confirmation needs a message.' : null;
            case 'page':
                $page = get_post((int) $final['page']);
                if (! $page instanceof \WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status) {
                    return 'A page confirmation needs the id of a published page.';
                }
                return null;
            case 'redirect':
                $url = (string) $final['url'];
                if (! empty($final['url_unchanged'])) {
                    // Renaming or switching off a confirmation that already redirects does not re-judge its url.
                    return '' === $url || ! preg_match('#^https?://[^\s]+$#i', $url) ? 'A redirect confirmation needs an http or https url.' : null;
                }
                if (null !== ($tags = self::redirect_tag_problem($url))) {
                    return $tags;
                }
                if ('' === $url || ! preg_match('#^https?://[^\s]+$#i', $url) || (! preg_match('/[{}\[\]]/', $url) && $url !== esc_url_raw($url))) {
                    return 'A redirect confirmation needs an http or https url.';
                }
                return null;
        }

        return 'The confirmation type must be message, page or redirect.';
    }

    /**
     * A redirect confirmation sends the visitor to its url, and the form
     * plugins fill smart tags in that url with the submission's values. A tag
     * in a url that leaves this site would hand those values to another host,
     * so it is refused unless a site opts in with
     * wpmcp_forms_allow_redirect_smart_tags (which receives the url).
     */
    protected static function redirect_tag_problem(string $url): ?string
    {
        if (! preg_match('/[{}\[\]]/', $url)) {
            return null;
        }
        $bad = static fn (string $why): string => 'This redirect url is refused: ' . $why;

        // Normalise before judging the host. A browser reads a backslash as a
        // slash and ignores what precedes an @ in the authority, so a url that
        // wp_parse_url() sees on this site can send the visitor elsewhere.
        if (preg_match('/[<>\x00-\x20\x7f\\\\]/', $url)) {
            return $bad('it contains whitespace, control characters, markup or a backslash.');
        }
        if (! preg_match('#^https?://([^/?\#]*)#i', $url, $m)) {
            return $bad('it must start with http:// or https:// (no protocol-relative or scheme-less form).');
        }
        $authority = $m[1];
        if ('' === $authority || str_contains($authority, '@')) {
            return $bad('its host part is empty or carries user information (an @).');
        }
        if (preg_match('/[{}\[\]]/', $authority)) {
            return $bad('a smart tag in the host part could name any host.');
        }
        // Outside the tags the url must survive esc_url_raw() untouched, and a
        // tag may only use the characters the form plugins' own tags use.
        $plain = preg_replace('/\{[A-Za-z0-9_:.="\' \-]+\}|\[[A-Za-z0-9_:.\-]+\]/', 'x', $url);
        if (null === $plain || preg_match('/[{}\[\]]/', $plain) || esc_url_raw($plain) !== $plain) {
            return $bad('a smart tag is malformed or the rest of the url has characters WordPress would strip.');
        }

        $host = wp_parse_url($plain, PHP_URL_HOST);
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_string($host) && is_string($home) && 0 === strcasecmp($host, $home)) {
            return null;
        }
        if (true === apply_filters('wpmcp_forms_allow_redirect_smart_tags', false, $url)) {
            return null;
        }

        return 'A redirect to another site must not contain smart tags or shortcodes, which would send submitted values to that host (a site can allow it with wpmcp_forms_allow_redirect_smart_tags).';
    }

    /** Why a header-bound text (a subject, a sender name, a notification name) is refused, or null. */
    protected static function header_text_problem(string $value, string $label): ?string
    {
        return preg_match('/[\r\n\0]/', $value) ? sprintf('%s must not contain line breaks.', $label) : null;
    }

    /**
     * What changed between two flat arrays, for the before/after a write
     * returns: only the keys that differ, each with both values.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    protected static function diff_fields(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $key => $value) {
            if (($before[ $key ] ?? null) !== $value) {
                $out[ $key ] = [ 'before' => $before[ $key ] ?? null, 'after' => $value ];
            }
        }

        return $out;
    }

    /** Snapshot target for a write to one post: the post, its meta and terms. */
    protected static function post_snapshot(int $id, string $post_type): ?array
    {
        $post = get_post($id);

        return $post instanceof \WP_Post && $post_type === $post->post_type ? [ 'object_type' => 'post', 'object_id' => $id ] : null;
    }

    /**
     * The sentence every entry delete carries about erasure: the snapshot is
     * a verbatim copy until Snapshot_Store prunes it.
     */
    protected const NOT_ERASURE = 'Deletion is NOT erasure: the snapshot keeps a verbatim copy of the submission until Snapshot_Store prunes it.';
}
