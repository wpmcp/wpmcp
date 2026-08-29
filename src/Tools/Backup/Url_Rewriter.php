<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Serialization-aware search and replace, the piece every WordPress
 * migration gets wrong.
 *
 * WordPress stores PHP-serialized arrays as text in the database
 * (widget settings, theme mods, Elementor's own data, half of wp_options).
 * A serialized string carries its own byte length: s:19:"https://old.example".
 * A naive SQL REPLACE changes the bytes but not the declared length, so
 * unserialize() rejects the whole value and the option silently reads back
 * as false. That is how "the migration worked but the widgets are gone"
 * happens.
 *
 * This walks the decoded structure instead: unserialize, replace inside the
 * leaf strings, reserialize with correct lengths. Values that are not
 * serialized are replaced as plain text, and anything that fails to decode
 * is returned untouched rather than guessed at.
 *
 * Recursion is depth-bounded. Serialized data coming out of a database is
 * attacker-influenced on a compromised site, and an unbounded walk over a
 * deeply nested structure is a stack-exhaustion crash during the one
 * operation the user most needs to succeed.
 *
 * Objects are handled without ever instantiating them: unserialize() runs
 * with allowed_classes => false so a crafted value cannot trigger a PHP
 * object injection gadget chain during a restore.
 *
 * A value whose decoded structure contains an object ANYWHERE is then left
 * byte-for-byte untouched, rather than rewritten. With allowed_classes =>
 * false every object decodes to __PHP_Incomplete_Class, and re-serializing
 * that does not reliably reproduce the original bytes: private and protected
 * property names carry NUL-delimited class prefixes that do not survive the
 * round trip intact. Rewriting such a value would hand back something that
 * unserializes into a subtly different object, which is a worse outcome
 * during a migration than a URL that was not rewritten. contains_object()
 * exists to make that refusal explicit and testable rather than incidental;
 * callers that need to know it happened can check with would_rewrite().
 */
class Url_Rewriter
{
    public const MAX_DEPTH = 32;

    /**
     * Replace every occurrence of $from with $to inside $value, preserving
     * serialized structure.
     *
     * @param mixed $value
     * @return mixed The rewritten value, of the same shape as the input.
     */
    public function rewrite($value, string $from, string $to, int $depth = 0)
    {
        if ('' === $from || $from === $to) {
            return $value;
        }

        if ($depth > self::MAX_DEPTH) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $new_key         = is_string($key) ? $this->rewrite($key, $from, $to, $depth + 1) : $key;
                $out[ $new_key ] = $this->rewrite($item, $from, $to, $depth + 1);
            }
            return $out;
        }

        if (! is_string($value)) {
            // Integers, floats, booleans and nulls contain no URLs and must
            // keep their type: casting them to string here is what turns an
            // int option into a numeric string and breaks strict comparisons
            // in the theme that reads it back.
            return $value;
        }

        if ($this->is_serialized($value)) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- decodes WP's own PHP-serialized option/meta values with allowed_classes disabled; JSON cannot read this fixed format.
            $decoded = @unserialize($value, ['allowed_classes' => false]);

            // unserialize() returns false both for a genuine serialized false
            // and for a failure, so the literal 'b:0;' is checked explicitly
            // before treating false as undecodable.
            if (false === $decoded && 'b:0;' !== $value) {
                return $value;
            }

            if ($this->contains_object($decoded)) {
                return $value;
            }

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- re-encodes in the same PHP-serialized format WP wrote; consumers of the option/meta expect that format, not JSON.
            return serialize($this->rewrite($decoded, $from, $to, $depth + 1));
        }

        return str_replace($from, $to, $value);
    }

    /**
     * Whether a decoded structure holds an object at any depth (including
     * one nested inside a further serialized string), in which case the
     * value is not safe to reserialize. See the class docblock.
     *
     * @param mixed $value
     */
    private function contains_object($value, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            // Past the bound the structure cannot be inspected any further,
            // so it is reported as unsafe. Refusing to rewrite is the
            // conservative side of this decision.
            return true;
        }

        if (is_object($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->contains_object($item, $depth + 1)) {
                    return true;
                }
            }
            return false;
        }

        if (is_string($value) && $this->is_serialized($value)) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- decodes WP's own PHP-serialized values with allowed_classes disabled, only to check for embedded objects.
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if (false === $decoded && 'b:0;' !== $value) {
                return false;
            }
            return $this->contains_object($decoded, $depth + 1);
        }

        return false;
    }

    /**
     * Whether rewrite() would actually modify this value, as opposed to
     * returning it untouched because it holds an object. Lets a migration
     * report which rows it deliberately skipped instead of leaving the user
     * to discover a stale URL later.
     *
     * @param mixed $value
     */
    public function would_rewrite($value): bool
    {
        return ! $this->contains_object($value);
    }

    /**
     * Whether a string looks like PHP-serialized data.
     *
     * Delegates to WordPress core's is_serialized() when available so this
     * agrees exactly with what maybe_unserialize() will do to the same value
     * on read; the fallback exists only for unit tests running outside a
     * loaded WordPress.
     */
    private function is_serialized(string $value): bool
    {
        if (function_exists('is_serialized')) {
            return is_serialized($value);
        }

        return (bool) preg_match('/^[aOs]:\d+:|^[bid]:[^;]*;/', $value);
    }

    /**
     * Every URL form that has to be rewritten when a site moves from
     * $from_url to $to_url, most specific first.
     *
     * Order matters and is not cosmetic. The scheme-relative and
     * host-only forms are substrings of the full URL, so replacing the
     * shortest form first would consume the longer ones and leave a
     * half-rewritten URL behind. The JSON-escaped form ("https:\/\/host")
     * is how block editor content stores URLs, and it will not match the
     * plain form at all.
     *
     * @return array<int, array{from: string, to: string}>
     */
    public function replacement_pairs(string $from_url, string $to_url): array
    {
        $from = untrailingslashit($from_url);
        $to   = untrailingslashit($to_url);

        if ('' === $from || $from === $to) {
            return [];
        }

        $pairs = [
            ['from' => $from, 'to' => $to],
            [
                'from' => str_replace('/', '\\/', $from),
                'to'   => str_replace('/', '\\/', $to),
            ],
            [
                'from' => rawurlencode($from),
                'to'   => rawurlencode($to),
            ],
            [
                'from' => (string) preg_replace('#^https?:#', '', $from),
                'to'   => (string) preg_replace('#^https?:#', '', $to),
            ],
        ];

        // Deduplicate while preserving order: on a same-host scheme change
        // several of these forms collapse to the same pair, and applying one
        // twice would double-rewrite an already-migrated value.
        $seen = [];
        $out  = [];
        foreach ($pairs as $pair) {
            if ('' === $pair['from'] || $pair['from'] === $pair['to'] || isset($seen[ $pair['from'] ])) {
                continue;
            }
            $seen[ $pair['from'] ] = true;
            $out[]                 = $pair;
        }

        return $out;
    }

    /**
     * Apply every form from replacement_pairs() to a single value.
     *
     * @param mixed $value
     * @return mixed
     */
    public function rewrite_url($value, string $from_url, string $to_url)
    {
        foreach ($this->replacement_pairs($from_url, $to_url) as $pair) {
            $value = $this->rewrite($value, $pair['from'], $pair['to']);
        }

        return $value;
    }
}
