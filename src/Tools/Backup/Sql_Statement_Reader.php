<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Splits a SQL dump on disk into individual statements with a bounded read
 * (issue #190).
 *
 * The dump is never loaded whole. It is read in CHUNK_BYTES slices with
 * file_get_contents() at an offset (one of the names the directory review
 * ruleset excludes from WordPress.WP.AlternativeFunctions, unlike the
 * fopen/fread handle functions), and only the statement currently being
 * assembled is held in memory. A statement larger than the cap is an error
 * rather than an allocation: Db_Dumper caps its INSERTs at 512KB, so
 * anything near the cap is either a single enormous row or a file this
 * plugin did not write.
 *
 * The splitter is quote-aware ('...', "...", `...` with backslash escapes
 * and doubled quotes) and skips "-- ", "#" and block comments that sit
 * between statements, so a semicolon inside a value or a column comment
 * never ends a statement. A dump that ends part-way through a statement or
 * inside a quoted value is reported as truncated: a restore must refuse
 * such a file before it drops a single table, not discover the problem
 * half-way through.
 */
class Sql_Statement_Reader
{
    /** Bytes read from disk per slice. */
    public const CHUNK_BYTES = 1048576;

    /** Largest single statement accepted, in bytes. */
    public const MAX_STATEMENT_BYTES = 67108864;

    private const NORMAL        = 0;
    private const SINGLE_QUOTE  = 1;
    private const DOUBLE_QUOTE  = 2;
    private const BACKTICK      = 3;
    private const LINE_COMMENT  = 4;
    private const BLOCK_COMMENT = 5;

    private string $path;
    private int $chunk_bytes;
    private int $max_statement_bytes;

    public function __construct(string $path, int $max_statement_bytes = self::MAX_STATEMENT_BYTES, int $chunk_bytes = self::CHUNK_BYTES)
    {
        $this->path                = $path;
        $this->max_statement_bytes = max(1, $max_statement_bytes);
        $this->chunk_bytes         = max(16, $chunk_bytes);
    }

    /**
     * Yield each statement in order, without its terminating semicolon and
     * without leading whitespace or comments.
     *
     * @return \Generator<int, array{sql: string, offset: int, index: int}>
     * @throws \RuntimeException On an unreadable file, an oversized
     *                           statement, or a truncated dump.
     */
    public function statements(): \Generator
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new \RuntimeException('The SQL dump could not be read.');
        }

        $buffer      = '';
        $base        = 0;     // File offset of $buffer[0].
        $read_offset = 0;     // Next file offset to read from.
        $eof         = false;
        $pos         = 0;
        $state       = self::NORMAL;
        $start       = -1;    // Buffer index where the current statement began.
        $index       = 0;

        while (true) {
            $length = strlen($buffer);

            // Keep three bytes of lookahead ("-- " comment openers, escapes,
            // doubled quotes) unless the file is exhausted.
            if ($pos + 2 >= $length && ! $eof) {
                // Drop everything before the current statement (or the scan
                // position, between statements) so the buffer tracks one
                // statement plus one slice, not the whole file.
                $keep_from = $start >= 0 ? $start : $pos;
                if ($keep_from > 0) {
                    $buffer = (string) substr($buffer, $keep_from);
                    $base  += $keep_from;
                    $pos   -= $keep_from;
                    if ($start >= 0) {
                        $start = 0;
                    }
                }

                $chunk = file_get_contents($this->path, false, null, $read_offset, $this->chunk_bytes);
                if (false === $chunk || '' === $chunk) {
                    $eof = true;
                } else {
                    $buffer      .= $chunk;
                    $read_offset += strlen($chunk);
                }
                continue;
            }

            if ($pos >= $length) {
                break;
            }

            if ($start >= 0 && ($pos - $start) > $this->max_statement_bytes) {
                throw new \RuntimeException(sprintf(
                    'Statement %d starting at byte %d is larger than the %d byte limit; refusing to buffer it.',
                    (int) ($index + 1),
                    (int) ($base + $start),
                    (int) $this->max_statement_bytes
                ));
            }

            $c    = $buffer[ $pos ];
            $next = $pos + 1 < $length ? $buffer[ $pos + 1 ] : '';

            switch ($state) {
                case self::NORMAL:
                    if ($start < 0) {
                        if (ctype_space($c)) {
                            $pos++;
                            break;
                        }
                        if ('-' === $c && '-' === $next && $this->dash_comment_follows($buffer, $pos, $eof)) {
                            $state = self::LINE_COMMENT;
                            $pos  += 2;
                            break;
                        }
                        if ('#' === $c) {
                            $state = self::LINE_COMMENT;
                            $pos++;
                            break;
                        }
                        if ('/' === $c && '*' === $next) {
                            $state = self::BLOCK_COMMENT;
                            $pos  += 2;
                            break;
                        }
                        if (';' === $c) {
                            // An empty statement: nothing to yield.
                            $pos++;
                            break;
                        }
                        $start = $pos;
                    }

                    // Jump to the next byte that can change state.
                    $skip = strcspn($buffer, "'\"`;-#/", $pos);
                    if ($skip > 0) {
                        $pos += $skip;
                        break;
                    }

                    if ("'" === $c) {
                        $state = self::SINGLE_QUOTE;
                        $pos++;
                    } elseif ('"' === $c) {
                        $state = self::DOUBLE_QUOTE;
                        $pos++;
                    } elseif ('`' === $c) {
                        $state = self::BACKTICK;
                        $pos++;
                    } elseif (';' === $c) {
                        $sql = rtrim((string) substr($buffer, $start, $pos - $start));
                        $pos++;
                        $offset = $base + $start;
                        $start  = -1;
                        if ('' !== $sql) {
                            $index++;
                            yield ['sql' => $sql, 'offset' => $offset, 'index' => $index];
                        }
                    } elseif ('-' === $c && '-' === $next && $this->dash_comment_follows($buffer, $pos, $eof)) {
                        $state = self::LINE_COMMENT;
                        $pos  += 2;
                    } elseif ('#' === $c) {
                        $state = self::LINE_COMMENT;
                        $pos++;
                    } elseif ('/' === $c && '*' === $next) {
                        $state = self::BLOCK_COMMENT;
                        $pos  += 2;
                    } else {
                        $pos++;
                    }
                    break;

                case self::SINGLE_QUOTE:
                case self::DOUBLE_QUOTE:
                    $quote = self::SINGLE_QUOTE === $state ? "'" : '"';
                    $skip  = strcspn($buffer, '\\' . $quote, $pos);
                    if ($skip > 0) {
                        $pos += $skip;
                        break;
                    }
                    if ('\\' === $c) {
                        $pos += 2;
                    } elseif ($quote === $next) {
                        $pos += 2;
                    } else {
                        $state = self::NORMAL;
                        $pos++;
                    }
                    break;

                case self::BACKTICK:
                    $skip = strcspn($buffer, '`', $pos);
                    if ($skip > 0) {
                        $pos += $skip;
                        break;
                    }
                    if ('`' === $next) {
                        $pos += 2;
                    } else {
                        $state = self::NORMAL;
                        $pos++;
                    }
                    break;

                case self::LINE_COMMENT:
                    $skip = strcspn($buffer, "\n", $pos);
                    if ($skip > 0) {
                        $pos += $skip;
                        break;
                    }
                    $state = self::NORMAL;
                    $pos++;
                    break;

                case self::BLOCK_COMMENT:
                    if ('*' === $c && '/' === $next) {
                        $state = self::NORMAL;
                        $pos  += 2;
                        break;
                    }
                    $skip = strcspn($buffer, '*', $pos + 1);
                    $pos += 1 + $skip;
                    break;
            }
        }

        if (in_array($state, [self::SINGLE_QUOTE, self::DOUBLE_QUOTE, self::BACKTICK], true)) {
            throw new \RuntimeException(sprintf(
                'The SQL dump ends inside a quoted value (statement %d at byte %d); it is truncated.',
                (int) ($index + 1),
                (int) ($base + max(0, $start))
            ));
        }

        if (self::BLOCK_COMMENT === $state) {
            throw new \RuntimeException('The SQL dump ends inside an unterminated comment; it is truncated.');
        }

        if ($start >= 0 && '' !== trim((string) substr($buffer, $start))) {
            throw new \RuntimeException(sprintf(
                'The SQL dump ends part-way through statement %d at byte %d (no terminating semicolon); it is truncated.',
                (int) ($index + 1),
                (int) ($base + $start)
            ));
        }
    }

    /**
     * MySQL only treats "--" as a comment when it is followed by whitespace
     * (or ends the input), so "a--1" stays an expression.
     */
    private function dash_comment_follows(string $buffer, int $pos, bool $eof): bool
    {
        if (! isset($buffer[ $pos + 2 ])) {
            return $eof;
        }

        return ctype_space($buffer[ $pos + 2 ]);
    }
}
