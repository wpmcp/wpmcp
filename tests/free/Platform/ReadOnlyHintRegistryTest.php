<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Ability;

/**
 * No ability that can cause a side effect may advertise readOnlyHint: true
 * (issue #420).
 *
 * Some MCP clients run a read-only tool without asking the person, and the
 * built-in chat (Tool_Executor::requires_approval()) and memory block rules
 * (Memory_Guard) skip read-only abilities too. A tool that writes, sends
 * mail, starts a job or writes a file while advertising read-only therefore
 * runs with no human approval. get-site-health did exactly that: its
 * mail_test argument plus confirm:true sends a test email.
 *
 * "Can cause a side effect" is defined from what the registry already
 * declares, so nothing here needs a hand-maintained list of tool names:
 *
 *  1. The operation classification. create, update and delete derive
 *     readOnlyHint false in Ability; an explicit override must never claim
 *     read-only for them.
 *  2. A confirm argument. confirm exists only to gate a write, send or
 *     destructive step, so a tool that accepts one has a side-effect path.
 *  3. The handler's own source. A read-only ability whose handler class is
 *     dedicated to read-only abilities must not call a function that
 *     persists state, schedules work, writes files or sends mail. Classes
 *     that also serve a non-read-only ability (the paired integration
 *     read/write tools) are skipped: their write half legitimately lives
 *     in the same file. Caches of a tool's own result (set_transient) are
 *     not side effects and are not listed.
 *
 * An ability that has a side-effect path but is still classified 'read' for
 * governance (so its plain reads stay reads under scoped identities) passes
 * read_only_hint: false explicitly in its registration.
 */
class ReadOnlyHintRegistryTest extends \WP_UnitTestCase
{
    /** Calls that persist state, schedule work, write files or send mail. */
    private const SIDE_EFFECT_CALLS = '/\b(?:wp_mail|wp_schedule_single_event|wp_schedule_event|'
        . 'file_put_contents|fwrite|unlink|rename|wp_insert_\w+|wp_update_\w+|wp_delete_\w+|'
        . '(?:add|update|delete)_option|(?:add|update|delete)_(?:post|user|term|comment)_meta|'
        . 'wp_(?:safe_)?remote_post)\s*\(|\$wpdb->(?:insert|update|delete|replace)\s*\(/';

    /** @return Ability[] */
    private static function read_only(): array
    {
        return array_values(array_filter(
            RegisteredAbilities::all(),
            static fn(Ability $a): bool => $a->read_only_hint
        ));
    }

    public function test_only_read_operations_advertise_read_only(): void
    {
        $bad = [];
        foreach (self::read_only() as $ability) {
            if ('read' !== $ability->operation || $ability->destructive_hint) {
                $bad[] = $ability->name . ' (' . $ability->operation . ')';
            }
        }

        $this->assertSame([], $bad, 'These abilities write but advertise readOnlyHint true.');
    }

    public function test_read_only_abilities_take_no_confirm_argument(): void
    {
        $bad = [];
        foreach (self::read_only() as $ability) {
            if (array_key_exists('confirm', $ability->input_schema['properties'] ?? [])) {
                $bad[] = $ability->name;
            }
        }

        $this->assertSame(
            [],
            $bad,
            'confirm gates a write, send or destructive step, so these abilities can cause a side effect '
            . 'and must pass read_only_hint: false.'
        );
    }

    public function test_read_only_handlers_call_nothing_with_a_side_effect(): void
    {
        $writer_classes = [];
        foreach (RegisteredAbilities::all() as $ability) {
            if (! $ability->read_only_hint && is_array($ability->handler)) {
                $writer_classes[ self::class_of($ability->handler) ] = true;
            }
        }

        $bad = [];
        foreach (self::read_only() as $ability) {
            $source = self::handler_source($ability->handler, $writer_classes);
            if (null !== $source && preg_match_all(self::SIDE_EFFECT_CALLS, $source, $m)) {
                $bad[] = $ability->name . ': ' . implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame([], $bad, 'These read-only abilities can cause a side effect.');
    }

    public function test_get_site_health_is_not_read_only_but_stays_a_non_destructive_read(): void
    {
        $found = null;
        foreach (RegisteredAbilities::all() as $ability) {
            if ('wpmcp/get-site-health' === $ability->name) {
                $found = $ability;
            }
        }

        $this->assertNotNull($found);
        // mail_test + confirm sends a test email, so clients must not treat it as read-only.
        $this->assertFalse($found->read_only_hint);
        $this->assertFalse($found->destructive_hint);
        // Each confirmed send is a new email.
        $this->assertFalse($found->idempotent_hint);
        // Governance still classifies Site Health reads as reads.
        $this->assertSame('read', $found->operation);
    }

    /** @param array<int, mixed> $handler */
    private static function class_of(array $handler): string
    {
        return is_object($handler[0]) ? get_class($handler[0]) : (string) $handler[0];
    }

    /**
     * The handler's source with comments stripped, or null when the handler
     * class also serves a non-read-only ability.
     *
     * @param mixed               $handler
     * @param array<string, true> $writer_classes
     */
    private static function handler_source($handler, array $writer_classes): ?string
    {
        if (is_array($handler)) {
            $class = self::class_of($handler);
            if (isset($writer_classes[ $class ])) {
                return null;
            }
            $file = (new \ReflectionClass($class))->getFileName();
            $code = (string) file_get_contents($file);
        } elseif ($handler instanceof \Closure) {
            $fn    = new \ReflectionFunction($handler);
            $lines = file((string) $fn->getFileName());
            $code  = '<?php ' . implode('', array_slice($lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
        } else {
            return null;
        }

        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }
}
