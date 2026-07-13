<?php

namespace WPMCP\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;

if (! defined('ABSPATH')) {
    exit;
}

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
                'permission_callback' => fn() => current_user_can($a->capability) && Governance::is_ability_enabled($a),
            ]);
        }
    }

    /** @return Ability[] */
    public function all(): array
    {
        return array_values($this->abilities);
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
