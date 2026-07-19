<?php

namespace WPMCP\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Also enforces per-request scoped-identity narrowing and records every
 * governance decision to the audit log (see Governance::is_within_identity_scope()
 * and Governance_Audit_Log), on top of the pre-existing capability +
 * Governance gating (issue #50).
 */
class Registrar
{
    /** @var Ability[] */
    private array $abilities = [];

    public function register(Ability $a): void
    {
        if ('pro' === $a->tier && ! Gate::is_pro()) {
            return;
        }
        if (! Governance::is_ability_enabled($a)) {
            return;
        }
        $this->abilities[ $a->name ] = $a;
        if (function_exists('wp_register_ability') && doing_action('wp_abilities_api_init')) {
            wp_register_ability($a->name, [
                'label'               => $a->description,
                'description'         => $a->description,
                'category'            => 'wpmcp',
                'input_schema'        => $a->input_schema,
                'execute_callback'    => $this->throttled($a),
                'permission_callback' => fn () => $this->is_permitted($a),
            ]);
        }
    }

    /**
     * Permission decision for one ability invocation. On top of the
     * pre-existing capability + Governance + identity-scope gating, 'pro'
     * tier abilities re-check the live license here (issue #54): the
     * Abilities API runs this before every execution, so a license that
     * lapses after registration cannot keep a pro tool usable. The
     * decision is audited exactly as before.
     */
    public function is_permitted(Ability $a): bool
    {
        $allowed = ('pro' !== $a->tier || Gate::is_pro())
            && current_user_can($a->capability)
            && Governance::is_ability_enabled($a)
            && Governance::is_within_identity_scope($a);
        $this->record_audit($a, $allowed);
        return $allowed;
    }

    /** @return Ability[] */
    public function all(): array
    {
        return array_values($this->abilities);
    }

    /**
     * Look up one registered ability by name. Used by the meta-tools
     * (issue #79): list-tools/get-tool-schema read the registered contract,
     * and call-tool allowlists dispatch to wpmcp's own surface with it.
     */
    public function get(string $name): ?Ability
    {
        return $this->abilities[ $name ] ?? null;
    }

    /**
     * Record a governance-decision outcome to Governance_Audit_Log. Wrapped
     * in a try/catch so a logging failure (e.g. an option-write error) can
     * never turn an otherwise-successful permission check into a fatal
     * error; the allow/deny decision itself is always returned regardless
     * of whether this succeeds.
     */
    private function record_audit(Ability $a, bool $allowed): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record($a->name, $identity, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the permission check it is observing.
        }
    }

    /**
     * Wraps an ability's handler with a rate-limit check that runs BEFORE the
     * real tool. The permission_callback contract (capability + Governance)
     * is untouched; this only sits in front of execute_callback, so a client
     * over budget never reaches the tool at all. The budget is a single
     * counter per client shared across every ability (Rate_Limiter::check()
     * keys only on client identity, not on ability name), matching "global
     * per-client counter across all abilities".
     */
    private function throttled(Ability $a): callable
    {
        return function (...$args) use ($a) {
            $status = Rate_Limiter::check(Rate_Limiter::client_key());
            if (! $status['allowed']) {
                return new \WP_Error(
                    'wpmcp_rate_limited',
                    sprintf(
                        'Rate limit exceeded for "%s". Retry after %d second(s).',
                        $a->name,
                        $status['retry_after']
                    ),
                    [
                        'retry_after' => $status['retry_after'],
                        'remaining'   => $status['remaining'],
                    ]
                );
            }
            return ($a->handler)(...$args);
        };
    }
}
