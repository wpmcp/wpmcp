<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Diagnostics\Mail_Check;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What the FluentSMTP and Post SMTP packs share (issue #696): the opt-in
 * switch for their writes, value-based scrubbing, recipient masking and the
 * test send. Both packs follow WP_Mail_SMTP_Status; this keeps the two new
 * ones from carrying the same helpers twice.
 */
final class SMTP_Pack_Support
{
    /** The site-wide opt-in for the set-from and test-send ops of both packs. */
    public const ENABLE_FILTER = 'wpmcp_enable_smtp_write';

    /** Shortest credential value scrubbed from free text; shorter ones would mask unrelated words. */
    public const SCRUB_MIN = 6;

    /** Longest error or subject text a log row returns. */
    public const TEXT_MAX = 300;

    /** Whether the SMTP writes are switched on for this site. */
    public static function writes_enabled(): bool
    {
        // The literal name, so the hook stays greppable.
        return (bool) apply_filters('wpmcp_enable_smtp_write', false);
    }

    /**
     * Every string under a secret key, at any depth, plus every string under a
     * key of an array that sits under one, added to $secrets. A base64 form is
     * added too, since Post SMTP stores several credentials that way.
     *
     * @param array<mixed>  $values
     * @param string[]      $keys    Lower-case key names whose values are credentials.
     * @param string[]      $secrets
     */
    public static function collect(array $values, array $keys, array &$secrets, bool $under_secret = false): void
    {
        foreach ($values as $key => $value) {
            $secret = $under_secret || in_array(strtolower((string) $key), $keys, true);
            if (is_array($value)) {
                self::collect($value, $keys, $secrets, $secret);
            } elseif ($secret) {
                self::add_secret($value, $secrets);
            }
        }
    }

    /**
     * @param mixed    $value
     * @param string[] $secrets
     */
    public static function add_secret($value, array &$secrets): void
    {
        if (! is_string($value) || strlen($value) < self::SCRUB_MIN) {
            return;
        }
        $secrets[] = $value;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Post SMTP stores credentials base64 encoded; decoding finds the second form to scrub, nothing is executed.
        $decoded   = base64_decode($value, true);
        if (false !== $decoded && strlen($decoded) >= self::SCRUB_MIN && $decoded !== $value && 1 === preg_match('/^[\x20-\x7e]+$/', $decoded)) {
            $secrets[] = $decoded;
        }
    }

    /**
     * $data with every secret replaced by the redaction mark wherever it
     * appears in a string, longest first so a secret holding another goes whole.
     *
     * @param array<mixed> $data
     * @param string[]     $secrets
     * @return array<mixed>
     */
    public static function scrub(array $data, array $secrets): array
    {
        $secrets = array_values(array_unique($secrets));
        if ([] === $secrets) {
            return $data;
        }
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        array_walk_recursive($data, static function (&$item) use ($secrets): void {
            if (is_string($item)) {
                $item = str_replace($secrets, Ops_Status_Packs::REDACTED, $item);
            }
        });
        return $data;
    }

    /**
     * The values of the user constants whose names start with $prefix and name
     * a credential, and the names of every constant under the prefix.
     *
     * @param string[] $skip Names (without the prefix test) that are never reported.
     * @param string[] $secrets
     * @return string[] Names of the constants defined, sorted.
     */
    public static function constants(string $prefix, array $skip, array &$secrets): array
    {
        $user  = get_defined_constants(true)['user'] ?? [];
        $names = [];
        foreach ($user as $name => $value) {
            $name = (string) $name;
            if (0 !== strpos($name, $prefix) || in_array($name, $skip, true)) {
                continue;
            }
            $names[] = $name;
            if (1 === preg_match('/(KEY|SECRET|PASSWORD|TOKEN|SALT)/', $name)) {
                self::add_secret($value, $secrets);
            }
        }
        sort($names);
        return $names;
    }

    /**
     * A log field the plugin itself serialized (a recipient list, a response),
     * decoded without instantiating any class. Never call it on a field a
     * visitor controls, such as a subject.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function decode($value)
    {
        if (is_string($value) && is_serialized($value)) {
            $decoded = @unserialize(trim($value), [ 'allowed_classes' => false ]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- a plugin-written log field, no class is instantiated.
            return false === $decoded && 'b:0;' !== trim($value) ? $value : $decoded;
        }
        return $value;
    }

    /** Every email address inside a value (a string or a nested array), masked as j***@example.com. Pass $serialized for a field the plugin serialized. @param mixed $value @return string[] */
    public static function masked_addresses($value, bool $serialized = false): array
    {
        $found = [];
        self::addresses($serialized ? self::decode($value) : $value, $found);
        return array_values(array_unique(array_map([ self::class, 'mask_email' ], $found)));
    }

    /** An address with all but the first character of its local part hidden: jane.doe@example.com reads j***@example.com. */
    public static function mask_email(string $email): string
    {
        $email = trim($email);
        $at    = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '' === $email ? '' : '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * @param mixed    $value
     * @param string[] $found
     */
    private static function addresses($value, array &$found): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::addresses($key, $found);
                self::addresses($item, $found);
            }
        } elseif (is_string($value) && preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $value, $m)) {
            array_push($found, ...$m[0]);
        }
    }

    /**
     * Free text from a log row, flattened, scrubbed of $secrets and only then
     * cut to TEXT_MAX, so a secret straddling the cut cannot leak as a prefix.
     * Pass $serialized for a field the plugin serialized (never for a subject).
     *
     * @param mixed    $value
     * @param string[] $secrets
     */
    public static function text($value, array $secrets = [], bool $serialized = false): string
    {
        $value = $serialized ? self::decode($value) : $value;
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function ($item) use (&$parts): void {
                if (is_scalar($item) && '' !== trim((string) $item)) {
                    $parts[] = trim((string) $item);
                }
            });
            $value = implode(' | ', $parts);
        }
        $text = trim(wp_strip_all_tags(is_scalar($value) ? (string) $value : ''));
        $text = (string) self::scrub([ $text ], $secrets)[0];
        return function_exists('mb_substr') && mb_strlen($text) > self::TEXT_MAX ? mb_substr($text, 0, self::TEXT_MAX) . '...' : $text;
    }

    /**
     * The test send, already confirmed by the dispatcher (destructive op):
     * Mail_Check's recipient rule (the caller's own or the admin address) and
     * its shared hourly limit still apply.
     *
     * @return array<string,mixed>
     */
    public static function send_test(string $recipient): array
    {
        try {
            return (new Mail_Check(static fn (): int => time()))->run($recipient, true);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Refused('mail_test_recipient_refused', esc_html($e->getMessage()));
        } catch (\RuntimeException $e) {
            throw new Operation_Refused('mail_test_refused', esc_html($e->getMessage()));
        }
    }

    /** The input schema of a send-*-test op. @return array<string,mixed> */
    public static function test_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'recipient' => [ 'type' => 'string' ] ],
            'required'   => [ 'recipient' ],
        ];
    }

    /** The description tail every gated op shares. */
    public static function gate_note(): string
    {
        return ' Disabled by default (wpmcp_enable_smtp_write filter).';
    }
}
