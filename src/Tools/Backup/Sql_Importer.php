<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Imports a WP MCP SQL dump statement by statement (issue #190).
 *
 * Two passes over the same file. scan() parses and classifies every
 * statement without executing anything: a truncated dump, a statement the
 * policy refuses, or one larger than the server's max_allowed_packet all
 * surface there, before the first table is dropped. import() then executes
 * the statements in order and stops at the first failure, reporting which
 * statement it was (ordinal, byte offset, kind, table) and the database
 * error. Each statement is atomic on its own; the import as a whole is not
 * (MySQL commits DDL implicitly, so no transaction can span it), which is
 * why the caller takes a safety archive first.
 *
 * Also repairs one historical defect: dumps written before this change
 * escaped values through $wpdb->prepare() without removing its per-request
 * placeholder, so every "%" in the data was written as a random
 * "{64 hex chars}" token (a permalink structure of /%postname%/ became
 * unreadable). The token is constant within one dump, so scan() learns it
 * from the first occurrence and import() turns it back into "%".
 */
class Sql_Importer
{
    private const PLACEHOLDER_PATTERN = '/\{[0-9a-f]{64}\}/';

    private string $path;
    private Sql_Import_Policy $policy;

    public function __construct(string $path, Sql_Import_Policy $policy)
    {
        $this->path   = $path;
        $this->policy = $policy;
    }

    /**
     * Validate the whole dump without executing it.
     *
     * @param int $max_packet The server's max_allowed_packet, or 0 to skip that check.
     * @return array{statements: int, tables: string[], largest_statement: int, placeholder: ?string}
     * @throws \RuntimeException Naming the offending statement.
     */
    public function scan(int $max_packet = 0): array
    {
        $count       = 0;
        $tables      = [];
        $largest     = 0;
        $placeholder = null;

        foreach ($this->reader()->statements() as $statement) {
            $count++;
            $bytes   = strlen($statement['sql']);
            $largest = max($largest, $bytes);

            try {
                $class = $this->policy->classify($statement['sql']);
            } catch (\RuntimeException $e) {
                $where = sprintf('Statement %d (byte %d) is not allowed: ', (int) $statement['index'], (int) $statement['offset']);
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sql_Import_Policy escapes every table name it interpolates; escaping its message again would entity-encode the plugin's own quotes.
                throw new \RuntimeException($where . $e->getMessage());
            }

            if ($max_packet > 0 && $bytes >= $max_packet) {
                throw new \RuntimeException(sprintf(
                    'Statement %d (byte %d) is %d bytes, larger than this server\'s max_allowed_packet of %d; the import would fail part-way.',
                    (int) $statement['index'],
                    (int) $statement['offset'],
                    (int) $bytes,
                    (int) $max_packet
                ));
            }

            if (null !== $class['table']) {
                $tables[ $class['table'] ] = true;
            }

            if (null === $placeholder && Sql_Import_Policy::KIND_INSERT === $class['kind'] && preg_match(self::PLACEHOLDER_PATTERN, $statement['sql'], $m)) {
                $placeholder = $m[0];
            }
        }

        if (0 === $count) {
            throw new \RuntimeException('The SQL dump contains no statements.');
        }

        return [
            'statements'        => $count,
            'tables'            => array_keys($tables),
            'largest_statement' => $largest,
            'placeholder'       => $placeholder,
        ];
    }

    /**
     * Execute the dump. Stops at the first failing statement.
     *
     * @param callable(array, array): void|null $after Called after each
     *        successful statement with the statement and its classification.
     * @return array{executed: int, failure: ?array}
     */
    public function import(?string $placeholder = null, ?callable $after = null): array
    {
        global $wpdb;

        $executed = 0;
        $suppress = $wpdb->suppress_errors(true);

        try {
            foreach ($this->reader()->statements() as $statement) {
                $sql = $statement['sql'];

                try {
                    $class = $this->policy->classify($sql);
                } catch (\RuntimeException $e) {
                    return [
                        'executed' => $executed,
                        'failure'  => self::failure($statement, null, $e->getMessage()),
                    ];
                }

                if (null !== $placeholder && Sql_Import_Policy::KIND_INSERT === $class['kind']) {
                    $sql = str_replace($placeholder, '%', $sql);
                }

                // The dump is our own $wpdb-escaped output, already checked
                // by the policy; wpdb's per-query charset scan would reject
                // BLOB rows outright and costs a full pass over every 512KB
                // INSERT for nothing.
                $wpdb->check_current_query = false;
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Replaying a WP MCP dump: every value in it was escaped by $wpdb->prepare() when it was written, and Sql_Import_Policy (src/Tools/Backup/Sql_Import_Policy.php) has admitted this statement as a SET, DROP/CREATE TABLE or literal-only INSERT on this site's own prefix.
                $result = $wpdb->query($sql);

                self::forget_saved_query();

                if (false === $result || '' !== (string) $wpdb->last_error) {
                    $error = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'The database rejected the statement.';
                    return [
                        'executed' => $executed,
                        'failure'  => self::failure($statement, $class, $error),
                    ];
                }

                $executed++;

                if (null !== $after) {
                    $after($statement, $class);
                }
            }
        } catch (\RuntimeException $e) {
            // The reader failed mid-file (the scratch file changed or became
            // unreadable after scan()).
            return [
                'executed' => $executed,
                'failure'  => [
                    'statement' => $executed + 1,
                    'offset'    => null,
                    'kind'      => null,
                    'table'     => null,
                    'error'     => $e->getMessage(),
                ],
            ];
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        return ['executed' => $executed, 'failure' => null];
    }

    private function reader(): Sql_Statement_Reader
    {
        return new Sql_Statement_Reader($this->path);
    }

    /** @return array{statement: int, offset: int, kind: ?string, table: ?string, error: string} */
    private static function failure(array $statement, ?array $class, string $error): array
    {
        return [
            'statement' => (int) $statement['index'],
            'offset'    => (int) $statement['offset'],
            'kind'      => null !== $class ? $class['kind'] : null,
            'table'     => null !== $class ? $class['table'] : null,
            'error'     => $error,
        ];
    }

    /**
     * With SAVEQUERIES on, wpdb keeps every query string it ran; across a
     * whole dump that is the database held in memory twice.
     */
    private static function forget_saved_query(): void
    {
        global $wpdb;

        if (defined('SAVEQUERIES') && SAVEQUERIES && is_array($wpdb->queries) && [] !== $wpdb->queries) {
            array_pop($wpdb->queries);
        }
    }
}
