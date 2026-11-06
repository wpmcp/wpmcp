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
 * Other requests keep running during a restore and may recreate a table or
 * write a row the dump is about to write (issue #745). A CREATE TABLE that
 * finds its table recreated drops it and runs again, and an INSERT that
 * finds a row with its key runs again as REPLACE, so the dump's schema and
 * rows end up in place either way (see run()).
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

    /** MySQL ER_TABLE_EXISTS_ERROR: CREATE TABLE on a name that exists. */
    private const ER_TABLE_EXISTS = 1050;

    /** MySQL ER_DUP_ENTRY: a row with the same primary or unique key exists. */
    private const ER_DUP_ENTRY = 1062;

    /** Times one statement is retried after colliding with another request. */
    private const RETRIES = 3;

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
        $literal     = false;

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

            if (Sql_Import_Policy::KIND_INSERT === $class['kind']) {
                if (null === $placeholder && preg_match(self::PLACEHOLDER_PATTERN, $statement['sql'], $m)) {
                    $placeholder = $m[0];
                }
                if (! $literal && str_contains($statement['sql'], '%')) {
                    $literal = true;
                }
            }
        }

        // A legacy dump has no literal "%" in any value: every one became
        // the token. A dump that does contain "%" was written after the fix,
        // so a {64 hex} string in it is real data and must not be touched.
        if ($literal) {
            $placeholder = null;
        }

        if (0 === $count) {
            throw new \RuntimeException('The SQL dump contains no statements; it is empty or truncated.');
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

                // wpdb still runs its own charset check on each statement
                // (check_current_query is not settable from outside). A row
                // holding bytes that are invalid in its table's charset is
                // therefore rejected here rather than silently mangled; the
                // import stops, reports it, and the caller rolls back.
                if (! self::run($sql, $class)) {
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

    /**
     * Run one admitted statement, tolerating what another request can do
     * to the tables in the middle of a restore (issue #745).
     *
     * A restore does not stop other requests: the get-backup-status poll
     * the tool asks clients to make, REST calls and WP-Cron all load every
     * plugin, and a plugin that finds its table or option missing creates
     * it. Two statements can then collide with that write:
     *
     *  - CREATE TABLE, when the table was recreated between the dump's
     *    DROP and this CREATE. The table is dropped again and the CREATE
     *    retried, so the dump's own definition is the one that ends up in
     *    place.
     *  - INSERT, when a row with the same primary or unique key was written
     *    into the freshly created table. The statement is retried as
     *    REPLACE with the same VALUES, so the dump's row replaces the
     *    other request's one. A multi-row INSERT that failed part-way left
     *    either nothing (InnoDB rolls the statement back) or a prefix of
     *    the dump's own rows (MyISAM), and REPLACE writes those again with
     *    the same values.
     *
     * Only those two database errors are retried, and only as the same
     * statement: the policy has already admitted it, and the rewrite
     * changes the keyword and nothing else. Every other error is reported
     * as before, and the caller rolls back.
     */
    private static function run(string $sql, array $class): bool
    {
        global $wpdb;

        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Replaying a WP MCP dump: every value in it was escaped by $wpdb->prepare() when it was written, and Sql_Import_Policy (src/Tools/Backup/Sql_Import_Policy.php) has admitted this statement as a SET, DROP/CREATE TABLE or literal-only INSERT on this site's own prefix. The only rewrite is INSERT INTO to REPLACE INTO in self::retry().
            $result = $wpdb->query($sql);

            self::forget_saved_query();

            if (false !== $result && '' === (string) $wpdb->last_error) {
                return true;
            }

            $retry = $attempt < self::RETRIES ? self::retry($sql, $class) : null;
            if (null === $retry) {
                return false;
            }
            $sql = $retry;
        }

        return false;
    }

    /**
     * The statement to run again after a collision with another request,
     * or null when the error is not one.
     */
    private static function retry(string $sql, array $class): ?string
    {
        global $wpdb;

        $errno   = self::last_errno();
        $message = (string) $wpdb->last_error;
        $exists  = self::ER_TABLE_EXISTS === $errno || (0 === $errno && str_contains($message, 'already exists'));
        $dup     = self::ER_DUP_ENTRY === $errno || (0 === $errno && str_contains($message, 'Duplicate entry'));

        if (Sql_Import_Policy::KIND_CREATE === $class['kind'] && null !== $class['table'] && $exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The same DROP TABLE IF EXISTS the dump ran one statement earlier, on the table its admitted CREATE names; %i quotes it.
            $dropped = $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $class['table']));
            self::forget_saved_query();
            return false === $dropped ? null : $sql;
        }

        if (Sql_Import_Policy::KIND_INSERT === $class['kind'] && $dup) {
            $replace = preg_replace('/^\s*INSERT\s+INTO\b/i', 'REPLACE INTO', $sql, 1, $count);
            return 1 === $count && is_string($replace) ? $replace : null;
        }

        return null;
    }

    /** The MySQL error number of the last query, or 0 when it cannot be read. */
    private static function last_errno(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- wpdb keeps only the message; the number is what tells a collision from any other error regardless of the server's language.
        return $wpdb->dbh instanceof \mysqli ? (int) mysqli_errno($wpdb->dbh) : 0;
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
