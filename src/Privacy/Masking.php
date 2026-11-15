<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP\Privacy;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one masking rule for personal data in LIST and EXPORT reads (issues
 * #708 and #866, and the list masking audit that followed them).
 *
 * A list or export returns many people's data in one call, so it hides the
 * personal part on the server before the data reaches the model: an email
 * keeps its first character and its domain (j***@example.com), any other
 * value keeps its first character (J***). A single-item read may return the
 * full value, under the capability its pack already requires.
 *
 * Packs call value() for names, emails, phones, IPs, user agents and every
 * free-text field that can hold them, and add masked:true to the response.
 */
final class Masking
{
    /**
     * A value with the personal part hidden. Lists and maps are masked item
     * by item (keys stay); null, booleans and empty strings stay as they are.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function value($value)
    {
        if (is_array($value)) {
            return array_map([ self::class, 'value' ], $value);
        }
        if (null === $value || is_bool($value) || '' === $value) {
            return $value;
        }
        $text = (string) $value;
        if (1 === preg_match('/^([^@\s])[^@\s]*@([^@\s]+)$/u', $text, $m)) {
            return $m[1] . '***@' . $m[2];
        }
        return mb_substr($text, 0, 1) . '***';
    }

    /** An email address masked (j***@example.com); other text as value() does. */
    public static function email(string $email): string
    {
        return (string) self::value(trim($email));
    }

    /**
     * Masks only the named keys of a row, leaving the rest (ids, dates,
     * statuses) readable.
     *
     * @param array<string, mixed> $row
     * @param string[]             $keys
     * @return array<string, mixed>
     */
    public static function keys(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $row[ $key ] = self::value($row[ $key ]);
            }
        }
        return $row;
    }
}
