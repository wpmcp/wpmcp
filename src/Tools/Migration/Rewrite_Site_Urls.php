<?php

namespace WPMCP\Tools\Migration;

use WPMCP\Tools\Backup\Url_Rewriter;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Phase 1 of site-to-site migration (issue #191): rewrite every embedded
 * URL in the database from one site URL to another, serialization-aware.
 *
 * This is restore's missing half. After an archive from site A is restored
 * onto site B, every URL the database carries still points at A: media in
 * post content, widget settings, theme mods, Elementor data. A naive SQL
 * REPLACE corrupts PHP-serialized values (the declared byte lengths stop
 * matching), which is how "the migration worked but the widgets are gone"
 * happens. Url_Rewriter walks the decoded structure instead and refuses to
 * touch any value containing an object rather than risk mangling it; those
 * refusals are counted and reported here, never silently passed over.
 *
 * Tables walked, in batches by primary key, pre-filtered with a LIKE on the
 * bare host of from_url. The host (not the scheme-relative "//host" form)
 * is the needle because it is the one literal substring common to every
 * form Url_Rewriter::replacement_pairs() produces: the JSON-escaped form
 * carries "https:\/\/host" and the percent-encoded form "https%3A%2F%2Fhost",
 * neither of which contains "//host", while rawurlencode() leaves the
 * alphanumerics, dots and hyphens of a hostname untouched. The pre-filter
 * only has to be a superset; the rewriter decides what actually changes.
 *   wp_options   (option_value)
 *   wp_postmeta  (meta_value)
 *   wp_posts     (post_content, post_excerpt)
 *   wp_termmeta  (meta_value)
 *   wp_usermeta  (meta_value)
 *   wp_comments  (comment_content, comment_author_url)
 *
 * post GUIDs are deliberately NOT rewritten: WordPress documents GUIDs as
 * permanent identifiers that must not change when a site moves.
 *
 * Tables the Database safety layer marks protected (users and usermeta by
 * default, see Database_Guard::is_protected() and the
 * wpmcp_db_protected_tables filter) are not written by this tool either;
 * they are reported as skipped with the reason so the operator can lift the
 * protection deliberately rather than discover a stale URL later.
 *
 * dry_run defaults to true: the default invocation reports what WOULD
 * change per table (rows_matched, rows_skipped_object) without writing a
 * byte. Applying requires dry_run:false AND confirm:true.
 *
 * Recoverability: an applied pass is NOT snapshot-backed. The per-object
 * Safety\Snapshot model does not fit a full-DB rewrite, so the response
 * reports recoverable:false with a reason, every table write is recorded in
 * Database_Guard's audit log, and rollback-operation cannot undo it.
 * TODO(#191): snapshot-first. The definition of done requires the whole
 * pass to be recoverable: the apply path must first produce a database
 * backup archive (Backup\Trigger_Backup, type=database) and refuse to run
 * until that job completes.
 * TODO(#191): time-bounded batching with a resumable cursor for very large
 * tables (currently the pass runs to completion within the request).
 */
class Rewrite_Site_Urls
{
    private const BATCH_SIZE = 200;

    public const RECOVERABLE_REASON = 'Not snapshot-backed: a site-wide rewrite of six core tables does not fit the per-object snapshot model; take a database backup (trigger-backup type=database) before applying. rollback-operation cannot undo this pass.';

    /**
     * @var array<string, array{table: string, pk: string, columns: array<int, string>}>
     */
    private const TABLES = [
        'options'  => ['table' => 'options', 'pk' => 'option_id', 'columns' => ['option_value']],
        'postmeta' => ['table' => 'postmeta', 'pk' => 'meta_id', 'columns' => ['meta_value']],
        'posts'    => ['table' => 'posts', 'pk' => 'ID', 'columns' => ['post_content', 'post_excerpt']],
        'termmeta' => ['table' => 'termmeta', 'pk' => 'meta_id', 'columns' => ['meta_value']],
        'usermeta' => ['table' => 'usermeta', 'pk' => 'umeta_id', 'columns' => ['meta_value']],
        'comments' => ['table' => 'comments', 'pk' => 'comment_ID', 'columns' => ['comment_content', 'comment_author_url']],
    ];

    public function handle(array $args): array
    {
        $from_url = isset($args['from_url']) ? untrailingslashit((string) $args['from_url']) : '';
        $to_url   = isset($args['to_url']) ? untrailingslashit((string) $args['to_url']) : '';
        // Only a literal false turns the dry run off; anything else (absent,
        // true, the string "false") stays on the side that writes nothing.
        $dry_run = false !== ($args['dry_run'] ?? true);
        $confirm = true === ($args['confirm'] ?? null);

        foreach (['from_url' => $from_url, 'to_url' => $to_url] as $name => $url) {
            if ('' === $url || ! preg_match('#^https?://#', $url)) {
                throw new \InvalidArgumentException(sprintf('%s must be an absolute http(s) URL.', esc_html($name)));
            }
        }
        if ($from_url === $to_url) {
            throw new \InvalidArgumentException('from_url and to_url are identical; nothing to rewrite.');
        }
        if (! $dry_run && ! $confirm) {
            throw new \InvalidArgumentException(
                'Applying a site-wide URL rewrite requires confirm:true. Run with dry_run:true first to see what would change.'
            );
        }

        $host = (string) wp_parse_url($from_url, PHP_URL_HOST);
        if ('' === $host) {
            throw new \InvalidArgumentException('from_url must carry a host.');
        }

        $tables = self::TABLES;
        if (! empty($args['tables']) && is_array($args['tables'])) {
            $tables = array_intersect_key($tables, array_flip(array_map('strval', $args['tables'])));
            if (empty($tables)) {
                throw new \InvalidArgumentException('tables filter matched none of: ' . esc_html(implode(', ', array_keys(self::TABLES))));
            }
        }

        global $wpdb;

        $rewriter    = new Url_Rewriter();
        $report      = [];
        $any_changed = false;
        foreach ($tables as $key => $spec) {
            $table_name = (string) $wpdb->{$spec['table']};
            if (Database_Guard::is_protected($table_name)) {
                $report[ $key ] = [
                    'skipped'        => true,
                    'skipped_reason' => sprintf(
                        '%s is a protected table (wpmcp_db_protected_tables); lift the protection to include it.',
                        $table_name
                    ),
                ];
                continue;
            }

            $report[ $key ] = $this->walk_table($spec, $rewriter, $from_url, $to_url, $host, $dry_run);
            if (! $dry_run && $report[ $key ]['rows_changed'] > 0) {
                $any_changed = true;
                Database_Guard::audit('rewrite-site-urls', $table_name, $report[ $key ]['rows_changed']);
            }
        }

        if ($any_changed) {
            // Rows were written with raw $wpdb->update(), which bypasses
            // clean_post_cache() and friends; with a persistent object cache
            // every get_option / get_post_meta / get_post would otherwise
            // keep serving the old URLs. One flush after the whole pass.
            wp_cache_flush();
        }

        $out = [
            'from_url' => $from_url,
            'to_url'   => $to_url,
            'dry_run'  => $dry_run,
            'tables'   => $report,
        ];

        if (! $dry_run) {
            $out['recoverable']        = false;
            $out['recoverable_reason'] = self::RECOVERABLE_REASON;
        }

        return $out;
    }

    /**
     * Walk one table in primary-key batches, rewriting matching rows.
     *
     * @param array{table: string, pk: string, columns: array<int, string>} $spec
     * @return array{rows_scanned: int, rows_changed: int, rows_failed: int, rows_skipped_object: int, rows_skipped_undecodable: int, first_error?: string}
     */
    private function walk_table(array $spec, Url_Rewriter $rewriter, string $from_url, string $to_url, string $host, bool $dry_run): array
    {
        global $wpdb;

        $table   = $wpdb->{$spec['table']};
        $pk      = $spec['pk'];
        $columns = $spec['columns'];

        $scanned     = 0;
        $changed     = 0;
        $failed      = 0;
        $skipped     = 0;
        $undecodable = 0;
        $first_error = null;
        $last        = 0;

        $like      = '%' . $wpdb->esc_like($host) . '%';
        $col_list  = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $where_any = implode(' OR ', array_map(static fn (string $c): string => "`{$c}` LIKE %s", $columns));

        while (true) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names come from the const map above, not input.
            $sql  = "SELECT `{$pk}`, {$col_list} FROM `{$table}` WHERE `{$pk}` > %d AND ({$where_any}) ORDER BY `{$pk}` ASC LIMIT %d";
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table/column names come from the const map above; every value is bound by prepare().
            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, array_merge([$last], array_fill(0, count($columns), $like), [self::BATCH_SIZE])),
                ARRAY_A
            );
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $last = (int) $row[ $pk ];
                $scanned++;

                $updates         = [];
                $row_object      = false;
                $row_undecodable = false;
                foreach ($columns as $column) {
                    $original = $row[ $column ];
                    if (! is_string($original) || '' === $original) {
                        continue;
                    }
                    $rewritten = $rewriter->rewrite_url($original, $from_url, $to_url);
                    if ($rewritten !== $original) {
                        $updates[ $column ] = $rewritten;
                        continue;
                    }
                    if (! $this->contains_replacement_form($original, $rewriter, $from_url, $to_url)) {
                        continue;
                    }
                    // A replacement form is literally present but the
                    // rewriter declined. Either the decoded structure holds
                    // an object (deliberate refusal) or the value looks
                    // serialized and does not decode. Reported, not hidden.
                    if ($rewriter->would_rewrite($original)) {
                        $row_undecodable = true;
                    } else {
                        $row_object = true;
                    }
                }

                // A row can be both changed (one column) and partially
                // skipped (another); the skip is counted either way.
                if ($row_object) {
                    $skipped++;
                }
                if ($row_undecodable) {
                    $undecodable++;
                }
                if (empty($updates)) {
                    continue;
                }

                if ($dry_run) {
                    $changed++;
                    continue;
                }

                $result = $wpdb->update($table, $updates, [ $pk => $row[ $pk ] ]);
                if (false === $result) {
                    $failed++;
                    if (null === $first_error) {
                        $first_error = sprintf(
                            '%s %s=%d: %s',
                            $table,
                            $pk,
                            $row[ $pk ],
                            $wpdb->last_error ?: 'update failed'
                        );
                    }
                    continue;
                }
                $changed++;
            }
        }

        $out = [
            'rows_scanned'             => $scanned,
            'rows_changed'             => $changed,
            'rows_failed'              => $failed,
            'rows_skipped_object'      => $skipped,
            'rows_skipped_undecodable' => $undecodable,
        ];
        if (null !== $first_error) {
            $out['first_error'] = $first_error;
        }

        return $out;
    }

    /**
     * Distinguish "nothing to replace" from "the rewriter refused".
     *
     * rewrite_url() returning the input unchanged is ambiguous: either no
     * replacement form occurs in the value, or the value is serialized data
     * that Url_Rewriter declines to touch. A replacement form literally
     * present in the string while rewrite_url() changed nothing is the
     * signature of the refusal case.
     */
    private function contains_replacement_form(string $value, Url_Rewriter $rewriter, string $from_url, string $to_url): bool
    {
        foreach ($rewriter->replacement_pairs($from_url, $to_url) as $pair) {
            if (false !== strpos($value, $pair['from'])) {
                return true;
            }
        }

        return false;
    }
}
