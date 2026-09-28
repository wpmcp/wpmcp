<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The allowlist every statement in a dump must pass before a restore
 * executes anything (issue #190).
 *
 * A restore runs whatever db.sql says with the site's own database
 * credentials, and those credentials usually reach more than this install:
 * other prefixes in a shared database, sometimes other databases on the
 * server. The archive path is contained by Archive_Locator, but the bytes
 * inside the archive are only as trustworthy as whoever put the zip there.
 * So a dump is accepted only if it looks exactly like what Db_Dumper
 * writes:
 *
 *  - SET for the four session variables the dump preamble and footer use;
 *  - DROP TABLE IF EXISTS / CREATE TABLE / INSERT INTO a table named with
 *    this site's prefix and, when the manifest lists its tables, one of
 *    those tables;
 *  - CREATE TABLE with a column list, never CREATE ... SELECT or LIKE;
 *  - INSERT ... VALUES whose tuples hold only quoted literals, NULL and
 *    plain numbers, never a subquery or function call.
 *
 * Anything else refuses the whole archive, before the first write. The
 * dump format is ours, so this costs nothing for a genuine archive and
 * turns a hand-edited one into a clear refusal instead of arbitrary SQL.
 */
class Sql_Import_Policy
{
    public const KIND_SET    = 'set';
    public const KIND_DROP   = 'drop';
    public const KIND_CREATE = 'create';
    public const KIND_INSERT = 'insert';

    private const IDENTIFIER = '`((?:[^`]|``)+)`';

    private string $prefix;

    /** @var array<string, true>|null */
    private ?array $tables;

    /**
     * @param string        $prefix The table prefix every statement must stay inside.
     * @param string[]|null $tables The manifest's table list, or null to allow
     *                              any table with the prefix.
     */
    public function __construct(string $prefix, ?array $tables = null)
    {
        $this->prefix = $prefix;
        $this->tables = null === $tables ? null : array_fill_keys(array_map('strval', $tables), true);
    }

    /**
     * Classify one statement, or throw with the reason it is not allowed.
     *
     * @return array{kind: string, table: ?string}
     * @throws \RuntimeException
     */
    public function classify(string $sql): array
    {
        $sql = ltrim($sql);

        if (preg_match('/^SET\s/i', $sql)) {
            $allowed = '/^SET\s+(?:'
                . "SQL_MODE\s*=\s*'[A-Z_,]*'"
                . '|FOREIGN_KEY_CHECKS\s*=\s*[01]'
                . '|UNIQUE_CHECKS\s*=\s*[01]'
                . '|NAMES\s+[A-Za-z0-9_]+(?:\s+COLLATE\s+[A-Za-z0-9_]+)?'
                . ')\s*$/i';
            if (! preg_match($allowed, $sql)) {
                throw new \RuntimeException('Only the SET statements a WP MCP dump writes (sql_mode, foreign_key_checks, unique_checks, names) are allowed.');
            }
            return ['kind' => self::KIND_SET, 'table' => null];
        }

        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+' . self::IDENTIFIER . '\s*$/i', $sql, $m)) {
            return ['kind' => self::KIND_DROP, 'table' => $this->table($m[1])];
        }

        if (preg_match('/^CREATE\s+TABLE\s+' . self::IDENTIFIER . '\s*\(/i', $sql, $m)) {
            $table = $this->table($m[1]);
            // CREATE TABLE t (...) SELECT ... copies rows out of any table
            // the credentials can read; DATA/INDEX DIRECTORY writes files
            // outside the datadir; FEDERATED/CONNECT/MERGE tables reach
            // other tables or other servers. Strip literals and identifiers
            // so a column comment or name cannot trip the check, then look
            // for the keywords anywhere in the remaining statement.
            $bare = preg_replace(["/'(?:[^'\\\\]++|\\\\.|'')*+'/s", '/"(?:[^"\\\\]++|\\\\.|"")*+"/s', '/`(?:[^`]++|``)*+`/'], "''", $sql);
            $reaches_out = '/\b(?:SELECT|LIKE|CONNECTION|UNION|(?:DATA|INDEX)\s+DIRECTORY)\b|\bENGINE\s*=\s*[\'"]?(?:FEDERATED|CONNECT|MERGE|MRG_MYISAM)\b/i';
            if (null === $bare || preg_match($reaches_out, $bare)) {
                throw new \RuntimeException(sprintf('CREATE TABLE %s reaches outside its own definition (SELECT, LIKE, a directory, or a federated/merge engine); only plain column definitions are allowed.', esc_html($table)));
            }
            return ['kind' => self::KIND_CREATE, 'table' => $table];
        }

        if (preg_match('/^INSERT\s+INTO\s+' . self::IDENTIFIER . '\s*\((?:\s*`(?:[^`]|``)+`\s*,?)+\)\s*VALUES\s*/i', $sql, $m)) {
            $table  = $this->table($m[1]);
            $values = (string) substr($sql, strlen($m[0]));
            if (! self::values_are_literals($values)) {
                throw new \RuntimeException(sprintf('INSERT INTO %s contains something other than literal values (a subquery or function call); refusing it.', esc_html($table)));
            }
            return ['kind' => self::KIND_INSERT, 'table' => $table];
        }

        throw new \RuntimeException('Only SET, DROP TABLE IF EXISTS, CREATE TABLE and INSERT INTO ... VALUES statements are allowed in a WP MCP dump.');
    }

    /**
     * Whether a VALUES list holds only (..., ...) tuples of quoted
     * literals, NULL and plain numbers. Walks the text rather than running
     * one big regex over it: a single INSERT is up to ~512KB and a
     * backtracking pattern over that is how PCRE hits its limits.
     */
    public static function values_are_literals(string $values): bool
    {
        $length = strlen($values);
        $pos    = 0;
        $depth  = 0;
        $seen   = false;

        while ($pos < $length) {
            $c = $values[ $pos ];

            if ("'" === $c || '"' === $c) {
                $pos++;
                while (true) {
                    $skip = strcspn($values, '\\' . $c, $pos);
                    $pos += $skip;
                    if ($pos >= $length) {
                        return false;
                    }
                    if ('\\' === $values[ $pos ]) {
                        $pos += 2;
                        continue;
                    }
                    if ($pos + 1 < $length && $c === $values[ $pos + 1 ]) {
                        $pos += 2;
                        continue;
                    }
                    $pos++;
                    break;
                }
                continue;
            }

            if (ctype_space($c) || ',' === $c) {
                $pos++;
                continue;
            }

            if ('(' === $c) {
                if ($depth > 0) {
                    return false; // A nested parenthesis is an expression, not a tuple.
                }
                $depth = 1;
                $seen  = true;
                $pos++;
                continue;
            }

            if (')' === $c) {
                if (1 !== $depth) {
                    return false;
                }
                $depth = 0;
                $pos++;
                continue;
            }

            if (0 === $depth) {
                return false; // Anything outside a tuple (ON DUPLICATE KEY, a trailing SELECT).
            }

            if (0 === substr_compare($values, 'NULL', $pos, 4, true)) {
                $after = $values[ $pos + 4 ] ?? '';
                if ('' === $after || ! ctype_alnum($after) && '_' !== $after) {
                    $pos += 4;
                    continue;
                }
                return false;
            }

            if (preg_match('/\G-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?/', $values, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $after = $values[ $pos ] ?? '';
                if ('' !== $after && (ctype_alpha($after) || '_' === $after)) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return $seen && 0 === $depth;
    }

    /** Unquote a backtick identifier and hold it to the prefix and table list. */
    private function table(string $quoted): string
    {
        $table = str_replace('``', '`', $quoted);

        if ('' === $this->prefix || ! str_starts_with($table, $this->prefix)) {
            throw new \RuntimeException(sprintf(
                'Table prefix mismatch inside the dump: %s does not use this site\'s prefix "%s".',
                esc_html($table),
                esc_html($this->prefix)
            ));
        }

        if (null !== $this->tables && ! isset($this->tables[ $table ])) {
            throw new \RuntimeException(sprintf(
                'The dump touches table %s, which its manifest does not list; the archive may have been edited.',
                esc_html($table)
            ));
        }

        return $table;
    }
}
