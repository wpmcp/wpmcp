<?php

namespace WPMCP\Tools\Migration;

use WPMCP\Tools\Backup\Url_Rewriter;

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
 * Tables walked, in batches by primary key, filtered to rows that can
 * possibly match (LIKE on the host-only form, which is a substring of every
 * form replacement_pairs() produces):
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
 * dry_run defaults to true: the default invocation reports what WOULD
 * change per table (rows_matched, rows_skipped_object) without writing a
 * byte. Applying requires dry_run:false AND confirm:true.
 *
 * TODO(#191): snapshot-first. The definition of done requires the whole
 * pass to be recoverable. A full-DB rewrite does not fit the per-object
 * Safety\Snapshot model, so the apply path must first produce a database
 * backup archive (Backup\Trigger_Backup, type=database) and refuse to run
 * until that job completes. Until that is wired, the apply path is gated
 * behind confirm:true only.
 * TODO(#191): time-bounded batching with a resumable cursor for very large
 * tables (currently the pass runs to completion within the request).
 */
class Rewrite_Site_Urls
{
    private const BATCH_SIZE = 200;

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
        $dry_run  = ! isset($args['dry_run']) || (bool) $args['dry_run'];
        $confirm  = ! empty($args['confirm']);

        foreach (['from_url' => $from_url, 'to_url' => $to_url] as $name => $url) {
            if ('' === $url || ! preg_match('#^https?://#', $url)) {
                return ['error' => sprintf('%s must be an absolute http(s) URL.', $name)];
            }
        }
        if ($from_url === $to_url) {
            return ['error' => 'from_url and to_url are identical; nothing to rewrite.'];
        }
        if (! $dry_run && ! $confirm) {
            return [
                'error' => 'Applying a site-wide URL rewrite requires confirm:true. Run with dry_run:true first to see what would change.',
            ];
        }

        $rewriter = new Url_Rewriter();

        // The host-only, scheme-stripped form ("//old.example.com") is a
        // substring of every replacement form, so a LIKE on it is a safe
        // pre-filter that skips the vast majority of rows entirely.
        $needle = (string) preg_replace('#^https?:#', '', $from_url);

        $tables = self::TABLES;
        if (! empty($args['tables']) && is_array($args['tables'])) {
            $tables = array_intersect_key($tables, array_flip(array_map('strval', $args['tables'])));
            if (empty($tables)) {
                return ['error' => 'tables filter matched none of: ' . implode(', ', array_keys(self::TABLES))];
            }
        }

        $report = [];
        foreach ($tables as $key => $spec) {
            $report[ $key ] = $this->walk_table($spec, $rewriter, $from_url, $to_url, $needle, $dry_run);
        }

        return [
            'from_url' => $from_url,
            'to_url'   => $to_url,
            'dry_run'  => $dry_run,
            'tables'   => $report,
        ];
    }

    /**
     * Walk one table in primary-key batches, rewriting matching rows.
     *
     * @param array{table: string, pk: string, columns: array<int, string>} $spec
     * @return array{rows_scanned: int, rows_changed: int, rows_skipped_object: int}
     */
    private function walk_table(array $spec, Url_Rewriter $rewriter, string $from_url, string $to_url, string $needle, bool $dry_run): array
    {
        global $wpdb;

        $table   = $wpdb->{$spec['table']};
        $pk      = $spec['pk'];
        $columns = $spec['columns'];

        $scanned = 0;
        $changed = 0;
        $skipped = 0;
        $last    = 0;

        $like      = '%' . $wpdb->esc_like($needle) . '%';
        $col_list  = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $where_any = implode(' OR ', array_map(static fn (string $c): string => "`{$c}` LIKE %s", $columns));

        while (true) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names come from the const map above, not input.
            $sql  = "SELECT `{$pk}`, {$col_list} FROM `{$table}` WHERE `{$pk}` > %d AND ({$where_any}) ORDER BY `{$pk}` ASC LIMIT %d";
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

                $updates     = [];
                $row_skipped = false;
                foreach ($columns as $column) {
                    $original = $row[ $column ];
                    if (! is_string($original) || '' === $original) {
                        continue;
                    }
                    $rewritten = $rewriter->rewrite_url($original, $from_url, $to_url);
                    if ($rewritten !== $original) {
                        $updates[ $column ] = $rewritten;
                    } elseif (false !== strpos($original, $needle) && $this->contains_replacement_form($original, $rewriter, $from_url, $to_url)) {
                        // The pre-filter matched but the rewriter declined:
                        // the value decodes to a structure containing an
                        // object. Deliberate refusal, reported not hidden.
                        $row_skipped = true;
                    }
                }

                if ($row_skipped && empty($updates)) {
                    $skipped++;
                    continue;
                }
                if (empty($updates)) {
                    continue;
                }

                $changed++;
                if (! $dry_run) {
                    $wpdb->update($table, $updates, [ $pk => $row[ $pk ] ]);
                }
            }
        }

        if (! $dry_run && 'options' === $spec['table']) {
            wp_cache_flush();
        }

        return [
            'rows_scanned'        => $scanned,
            'rows_changed'        => $changed,
            'rows_skipped_object' => $skipped,
        ];
    }

    /**
     * Distinguish "nothing to replace" from "the rewriter refused".
     *
     * rewrite_url() returning the input unchanged is ambiguous: either no
     * replacement form occurs in the value, or the value is serialized data
     * containing an object that Url_Rewriter declines to touch. A
     * replacement form literally present in the string while rewrite_url()
     * changed nothing is the signature of the refusal case.
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
