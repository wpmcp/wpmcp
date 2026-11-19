<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A gettext .po file read and edited in place (issue #691, Loco Translate).
 *
 * parse() walks the file line by line and records, for every entry, its
 * context, original, plural original, translations, flags, references and
 * extracted comments, plus the line numbers of its msgstr block and of its
 * flags line. edit() rewrites only those lines for the entries it changes,
 * so every other byte of the file (translator comments, wrapping, obsolete
 * #~ entries, the order) stays as Loco or msgmerge wrote it, and a diff of
 * the file shows exactly the strings that were set.
 *
 * compile() builds the .mo with WordPress core's own MO writer from the
 * parsed entries, leaving out fuzzy and untranslated ones the way msgfmt
 * and Loco's compiler do.
 */
final class Po_File
{
    /**
     * @return array{eol: string, lines: string[], header: ?array, entries: list<array>}
     */
    public static function parse(string $text): array
    {
        $eol     = false !== strpos($text, "\r\n") ? "\r\n" : "\n";
        $lines   = explode("\n", str_replace("\r\n", "\n", $text));
        $entries = [];
        $header  = null;
        $current = null;
        $last    = null;

        $finish = static function () use (&$current, &$entries, &$header, &$last): void {
            if (null !== $current && null !== $current['msgid']) {
                if ('' === $current['msgid'] && null === $current['msgctxt'] && ! $current['obsolete'] && null === $header) {
                    $header = $current;
                } elseif (! $current['obsolete']) {
                    $entries[] = $current;
                }
            }
            $current = null;
            $last    = null;
        };
        $fresh = static fn (int $i): array => [
            'start'        => $i,
            'keyword_line' => null,
            'flags_line'   => null,
            'msgstr_start' => null,
            'msgstr_end'   => null,
            'msgctxt'      => null,
            'msgid'        => null,
            'msgid_plural' => null,
            'msgstr'       => [],
            'flags'        => [],
            'references'   => [],
            'comments'     => [],
            'obsolete'     => false,
        ];

        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            if ('' === $line) {
                $finish();
                continue;
            }
            if (0 === strpos($line, '#~')) {
                if (null !== $current && (null !== $current['msgstr_start'] || ! $current['obsolete'] && null !== $current['msgid'])) {
                    $finish();
                }
                $current             = $current ?? $fresh($i);
                $current['obsolete'] = true;
                $current['msgid']    = $current['msgid'] ?? '#~';
                continue;
            }
            if ('#' === $line[0]) {
                if (null !== $current && null !== $current['msgstr_start']) {
                    $finish();
                }
                $current = $current ?? $fresh($i);
                $body    = trim(substr($line, 2));
                $kind    = substr($line, 0, 2);
                if ('#,' === $kind) {
                    $current['flags_line'] = $i;
                    $current['flags']      = array_values(array_filter(array_map('trim', explode(',', $body)), 'strlen'));
                } elseif ('#:' === $kind) {
                    $current['references'] = array_merge($current['references'], preg_split('/\s+/', $body, -1, PREG_SPLIT_NO_EMPTY));
                } elseif ('#.' === $kind) {
                    $current['comments'][] = $body;
                }
                continue;
            }
            if (1 === preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/s', $line, $m)) {
                $keyword = $m[1];
                if (null !== $current && null !== $current['msgstr_start'] && 0 !== strpos($keyword, 'msgstr')) {
                    $finish();
                }
                $current = $current ?? $fresh($i);
                if (in_array($keyword, [ 'msgctxt', 'msgid' ], true) && null === $current['keyword_line']) {
                    $current['keyword_line'] = $i;
                }
                $value = self::unquote($m[3]);
                if (0 === strpos($keyword, 'msgstr')) {
                    $index                         = '' === ($m[2] ?? '') ? 0 : (int) $m[2];
                    $current['msgstr'][ $index ]   = $value;
                    $current['msgstr_start']       = $current['msgstr_start'] ?? $i;
                    $current['msgstr_end']         = $i + 1;
                    $last                          = [ 'msgstr', $index ];
                } else {
                    $current[ $keyword ] = $value;
                    $last                = [ $keyword, null ];
                }
                continue;
            }
            if ('"' === $line[0] && null !== $current && null !== $last) {
                $value = self::unquote($line);
                if ('msgstr' === $last[0]) {
                    $current['msgstr'][ $last[1] ] .= $value;
                    $current['msgstr_end']           = $i + 1;
                } else {
                    $current[ $last[0] ] .= $value;
                }
            }
        }
        $finish();

        return [ 'eol' => $eol, 'lines' => $lines, 'header' => $header, 'entries' => $entries ];
    }

    /** The lookup key of an entry: context, EOT, original (core's own key shape). */
    public static function key(?string $context, string $msgid): string
    {
        return null === $context || '' === $context ? $msgid : $context . "\4" . $msgid;
    }

    /** @return array<string, array> entries by key; the first of a duplicate wins */
    public static function index(array $parsed): array
    {
        $out = [];
        foreach ($parsed['entries'] as $entry) {
            $key = self::key($entry['msgctxt'], $entry['msgid']);
            if (! isset($out[ $key ])) {
                $out[ $key ] = $entry;
            }
        }
        return $out;
    }

    /** untranslated, fuzzy or translated. */
    public static function status(array $entry): string
    {
        $msgstr = $entry['msgstr'];
        if ([] === $msgstr || in_array('', $msgstr, true)) {
            return 'untranslated';
        }
        return in_array('fuzzy', $entry['flags'], true) ? 'fuzzy' : 'translated';
    }

    /** The value of one header line ('Plural-Forms', 'Language'), or null. */
    public static function header(array $parsed, string $name): ?string
    {
        $header = (string) ($parsed['header']['msgstr'][0] ?? '');
        foreach (explode("\n", $header) as $line) {
            $pair = explode(':', $line, 2);
            if (2 === count($pair) && 0 === strcasecmp(trim($pair[0]), $name)) {
                return trim($pair[1]);
            }
        }
        return null;
    }

    /**
     * Rewrite the msgstr block (and the fuzzy flag) of each entry in
     * $updates, keyed as key() keys them, each ['msgstr' => string[] by
     * plural index, 'fuzzy' => bool]. $revision, when given, replaces the
     * header's PO-Revision-Date. Every other line is kept as it was.
     *
     * @param array<string, array{msgstr: string[], fuzzy: bool}> $updates
     */
    public static function edit(array $parsed, array $updates, ?string $revision = null): string
    {
        $lines   = $parsed['lines'];
        $index   = self::index($parsed);
        $patches = [];
        foreach ($updates as $key => $update) {
            $entry = $index[ $key ];
            $patches[] = [ $entry, self::msgstr_lines($entry, $update['msgstr']), $update['fuzzy'] ];
        }
        $header = $parsed['header'];
        if (null !== $revision && null !== $header && null !== self::header($parsed, 'PO-Revision-Date')) {
            $value     = (string) preg_replace('/^PO-Revision-Date:.*$/mi', 'PO-Revision-Date: ' . $revision, (string) $header['msgstr'][0]);
            $patches[] = [ $header, self::block('msgstr', $value, true), null ];
        }

        // Bottom-up, so earlier line numbers stay valid.
        usort($patches, static fn (array $a, array $b): int => $b[0]['msgstr_start'] <=> $a[0]['msgstr_start']);
        foreach ($patches as [ $entry, $block, $fuzzy ]) {
            array_splice($lines, $entry['msgstr_start'], $entry['msgstr_end'] - $entry['msgstr_start'], $block);
            if (null !== $fuzzy) {
                self::set_fuzzy($lines, $entry, $fuzzy);
            }
        }

        return implode($parsed['eol'], $lines);
    }

    /** @return string[] the msgstr lines for $entry holding $msgstr */
    private static function msgstr_lines(array $entry, array $msgstr): array
    {
        if (null === $entry['msgid_plural']) {
            return self::block('msgstr', (string) $msgstr[0], false);
        }
        $out = [];
        foreach (array_values($msgstr) as $i => $value) {
            $out = array_merge($out, self::block('msgstr[' . $i . ']', (string) $value, false));
        }
        return $out;
    }

    /**
     * One keyword and its quoted value, split after each newline the way
     * msgcat wraps multi-line strings ($split forces the split form, which
     * the header always uses).
     *
     * @return string[]
     */
    private static function block(string $keyword, string $value, bool $split): array
    {
        $parts = preg_split('/(?<=\n)/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (! $split && count($parts) <= 1) {
            return [ $keyword . ' "' . self::escape($value) . '"' ];
        }
        $out = [ $keyword . ' ""' ];
        foreach ($parts as $part) {
            $out[] = '"' . self::escape($part) . '"';
        }
        return $out;
    }

    /** Add or remove the fuzzy flag of $entry, in place in $lines. */
    private static function set_fuzzy(array &$lines, array $entry, bool $fuzzy): void
    {
        $flags = array_values(array_diff($entry['flags'], [ 'fuzzy' ]));
        if ($fuzzy) {
            $flags[] = 'fuzzy';
        }
        if ($flags === $entry['flags']) {
            return;
        }
        if (null !== $entry['flags_line']) {
            if ([] === $flags) {
                array_splice($lines, $entry['flags_line'], 1);
            } else {
                $lines[ $entry['flags_line'] ] = '#, ' . implode(', ', $flags);
            }
            return;
        }
        array_splice($lines, (int) $entry['keyword_line'], 0, [ '#, ' . implode(', ', $flags) ]);
    }

    private static function unquote(string $quoted): string
    {
        $inner = substr(trim($quoted), 1, -1);
        return (string) preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $m): string => [ 'n' => "\n", 't' => "\t", 'r' => "\r", '"' => '"', '\\' => '\\', 'a' => "\x07", 'b' => "\x08", 'f' => "\f", 'v' => "\v" ][ $m[1] ] ?? $m[1],
            $inner
        );
    }

    private static function escape(string $value): string
    {
        return strtr($value, [ '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\t" => '\\t', "\r" => '\\r' ]);
    }

    /**
     * The .mo bytes of a parsed file and how many strings they hold:
     * every entry that is fully translated and not fuzzy.
     *
     * @return array{bytes: string, compiled: int}
     */
    public static function compile(array $parsed): array
    {
        require_once ABSPATH . WPINC . '/pomo/mo.php';
        $mo = new \MO();
        $mo->set_headers($mo->make_headers((string) ($parsed['header']['msgstr'][0] ?? '')));
        $compiled = 0;
        foreach ($parsed['entries'] as $entry) {
            if ('translated' !== self::status($entry)) {
                continue;
            }
            ksort($entry['msgstr']);
            $args = [ 'singular' => $entry['msgid'], 'translations' => array_values($entry['msgstr']) ];
            if (null !== $entry['msgctxt']) {
                $args['context'] = $entry['msgctxt'];
            }
            if (null !== $entry['msgid_plural']) {
                $args['plural'] = $entry['msgid_plural'];
            }
            if ($mo->add_entry(new \Translation_Entry($args))) {
                ++$compiled;
            }
        }
        $bytes = $mo->export();
        if (false === $bytes) {
            throw new Operation_Refused('compile_failed', 'WordPress could not build the .mo file.');
        }
        return [ 'bytes' => (string) $bytes, 'compiled' => $compiled ];
    }
}
