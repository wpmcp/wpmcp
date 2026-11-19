<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Why a tool cannot run here, and what a human has to do (issue #627).
 *
 * One value for every unavailability: a fixed reason code, who acts and what
 * they do (with a wp-admin link when there is one), a docs anchor per reason,
 * a retry hint, and whether the tool is left out of tools/list for this
 * connection. Availability::explain() returns one of these; the refusals the
 * Registrar and the transports send carry its array form under the
 * 'unavailable' key of the WP_Error data, next to the codes and data they
 * already had, so nothing that reads the old shape changes.
 *
 * `hidden` describes what the plugin does today, not a wish: plugin, license
 * and governance reasons leave the tool out of tools/list; an opt-in, scope,
 * capability or input reason lists it and refuses at call time.
 */
final class Unavailable
{
    public const DOCS_URL = 'https://wpmcp-pro.com/docs.html';

    public const ACTORS = [ 'account_owner', 'site_admin', 'developer', 'user', 'none' ];

    public const RETRYABLE = [ 'no', 'later', 'after_human_action', 'with_different_input' ];

    /**
     * Every reason code, with its default actor, retry hint and whether the
     * tool is hidden from tools/list. Site-level reasons first, in the order
     * Availability walks them.
     *
     * @var array<string, array{who: string, retryable: string, hidden: bool}>
     */
    public const REASONS = [
        'environment'             => [ 'who' => 'developer', 'retryable' => 'after_human_action', 'hidden' => true ],
        'pro_required'            => [ 'who' => 'account_owner', 'retryable' => 'after_human_action', 'hidden' => true ],
        'license_expired'         => [ 'who' => 'account_owner', 'retryable' => 'after_human_action', 'hidden' => true ],
        'plugin_missing'          => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => true ],
        'plugin_inactive'         => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => true ],
        'plugin_version'          => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => true ],
        'multisite_required'      => [ 'who' => 'developer', 'retryable' => 'no', 'hidden' => true ],
        'disabled_by_admin'       => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => true ],
        'disabled_by_code'        => [ 'who' => 'developer', 'retryable' => 'after_human_action', 'hidden' => true ],
        'opt_in_required'         => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => false ],
        'wp_constant'             => [ 'who' => 'developer', 'retryable' => 'after_human_action', 'hidden' => false ],
        'scope_read_only'         => [ 'who' => 'user', 'retryable' => 'after_human_action', 'hidden' => false ],
        'identity_scope'          => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => false ],
        'capability_missing'      => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => false ],
        'network_admin_required'  => [ 'who' => 'site_admin', 'retryable' => 'after_human_action', 'hidden' => false ],
        'rate_limited'            => [ 'who' => 'none', 'retryable' => 'later', 'hidden' => false ],
        'memory_blocked'          => [ 'who' => 'site_admin', 'retryable' => 'with_different_input', 'hidden' => false ],
        'object_denied'           => [ 'who' => 'site_admin', 'retryable' => 'with_different_input', 'hidden' => false ],
    ];

    public string $reason;
    public string $message;
    public string $who;
    public string $action;
    public string $admin_url;
    public string $retryable;
    public int $retry_after;
    public bool $hidden;

    /**
     * @param string $reason  One of REASONS.
     * @param string $message What stops the tool, in one or two sentences.
     * @param string $action  One sentence: what the actor does.
     * @param array{who?: string, admin_url?: string, retryable?: string, retry_after?: int, hidden?: bool} $overrides
     */
    public function __construct(string $reason, string $message, string $action, array $overrides = [])
    {
        if (! isset(self::REASONS[ $reason ])) {
            throw new \InvalidArgumentException(sprintf('Unknown unavailability reason "%s".', esc_html($reason)));
        }
        $defaults = self::REASONS[ $reason ];
        $who      = (string) ($overrides['who'] ?? $defaults['who']);
        $retry    = (string) ($overrides['retryable'] ?? $defaults['retryable']);
        if (! in_array($who, self::ACTORS, true) || ! in_array($retry, self::RETRYABLE, true)) {
            throw new \InvalidArgumentException('Unknown actor or retry hint.');
        }

        $this->reason      = $reason;
        $this->message     = $message;
        $this->action      = $action;
        $this->who         = $who;
        $this->admin_url   = (string) ($overrides['admin_url'] ?? '');
        $this->retryable   = $retry;
        $this->retry_after = max(0, (int) ($overrides['retry_after'] ?? 0));
        $this->hidden      = (bool) ($overrides['hidden'] ?? $defaults['hidden']);
    }

    /** The docs anchor for a reason: one per reason, on the flat docs page. */
    public static function docs_url(string $reason): string
    {
        return self::DOCS_URL . '#unavailable-' . str_replace('_', '-', $reason);
    }

    /**
     * The wire form, as it goes under the 'unavailable' key of a refusal's
     * data.
     *
     * @return array{reason: string, action: array{who: string, text: string, admin_url?: string}, docs_url: string, retryable: string, retry_after?: int, hidden: bool}
     */
    public function to_array(): array
    {
        $action = [ 'who' => $this->who, 'text' => $this->action ];
        if ('' !== $this->admin_url) {
            $action['admin_url'] = $this->admin_url;
        }
        $out = [
            'reason'    => $this->reason,
            'action'    => $action,
            'docs_url'  => self::docs_url($this->reason),
            'retryable' => $this->retryable,
        ];
        if ('later' === $this->retryable && $this->retry_after > 0) {
            $out['retry_after'] = $this->retry_after;
        }
        $out['hidden'] = $this->hidden;

        return $out;
    }

    /**
     * A refusal for this reason: the message followed by the action, with
     * the contract under 'unavailable'. $code defaults to the reason; a
     * caller that already answers with an established code passes it.
     *
     * @param array<string, mixed> $data Extra error data.
     */
    public function to_wp_error(string $code = '', array $data = []): \WP_Error
    {
        $text = trim($this->message . ' ' . $this->action);

        return new \WP_Error('' === $code ? $this->reason : $code, $text, $data + [ 'status' => 403, 'unavailable' => $this->to_array() ]);
    }

    /**
     * $error with this contract added to its data under 'unavailable'. Its
     * code, message and other data stay as they were.
     */
    public function attach(\WP_Error $error): \WP_Error
    {
        $code = $error->get_error_code();
        $data = $error->get_error_data($code);
        $data = is_array($data) ? $data : [];
        $data['unavailable'] = $this->to_array();
        $error->add_data($data, $code);

        return $error;
    }
}
