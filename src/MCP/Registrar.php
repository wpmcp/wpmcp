<?php

namespace WPMCP\MCP;

use WPMCP\Auth\Client_Access;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Memory\Memory_Guard;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Operation_Context;
use WPMCP\Tools\Content\Content_Guard;

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

    /** @var Ability[] every ability handed to register(), before any gating. */
    private array $declared = [];

    /**
     * Abilities left unregistered because their host plugin is not active
     * (issue #790), with the plugin to name in the refusal. Kept apart from
     * $declared: they are not part of the surface the ability grid shows.
     *
     * @var array<string, array{ability: Ability, plugin: string, several: bool, operations: array<string, array{name: string, several: bool}>}>
     */
    private array $held_for_plugin = [];

    /** @var array<string, true> abilities whose execute() has begun but not yet checked permission. */
    private static array $invoked = [];

    /** The last decision recorded by a check made outside execute(). */
    private static ?string $standalone = null;

    /** Depth of quietly() probes running: above 0, decisions are not audited. */
    private static int $quiet = 0;

    /** How many tool calls are running, counting a tool one dispatches. */
    private static int $depth = 0;

    /** Incremented as each outermost tool call starts. */
    private static int $call_id = 0;

    /**
     * The outermost tool call running right now, or 0 outside one. Lets a
     * hook tell work a wpmcp tool did from work anything else did in the
     * same request (issue #432: optimizing only the uploads the tools make).
     */
    public static function current_call(): int
    {
        return self::$depth > 0 ? self::$call_id : 0;
    }

    /**
     * Whether this install can run abilities of a given tier.
     *
     * The single site of the tier rule. register() (registration),
     * is_permitted() (execution) and Ability_Grid_Page (the admin read and
     * write model) all ask here, so those three models cannot drift, and the
     * directory build has exactly one body to collapse instead of three
     * hand-copied predicates.
     */
    public static function tier_permitted(string $tier): bool
    {
        return 'pro' !== $tier || Gate::is_pro();
    }

    public function register(Ability $a): void
    {
        // Record the declaration BEFORE the tier/governance gates: the
        // ability grid (issue #78) lists governance-disabled abilities so an
        // admin can see and re-enable them, and it narrows this set by tier
        // itself (issue #161) rather than being handed a pre-narrowed one.
        // Only all()/get() feed the exposed MCP surface; declared() is a
        // display catalog and grants nothing.
        $this->declared[ $a->name ] = $a;
        if (! self::tier_permitted($a->tier)) {
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
                // Registration is not exposure. WP_Ability defaults
                // show_in_rest to false, and core gates BOTH the abilities
                // list controller and the run controller on it, so without
                // this every tool here is invisible to discovery AND
                // impossible to execute over REST/MCP. Asserted at the
                // transport boundary by tests/free/Rest/AbilityRestExposureTest.php.
                'meta'                => [ 'show_in_rest' => true ],
                // The Abilities API hands the invocation input to the
                // permission callback (WP_Ability::check_permissions($input)),
                // which is what lets a project-memory rule targeting a post id
                // or post type be decided here rather than inside each tool.
                // permission() rather than is_permitted() so a read-only
                // OAuth connection is told which scope it lacks (issue #454).
                'permission_callback' => fn ($input = null) => $this->permission($a, is_array($input) ? $input : []),
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
     *
     * Project-memory guardrails (issue #131) are enforced here too, and
     * deliberately at this exact spot: this method is the one gate every
     * ability passes through, so a published severity=block memory entry
     * denies uniformly across the whole surface, including abilities written
     * after the rule was created. The check runs LAST and can only narrow,
     * it never turns a denial into an allow, and a memory denial records the
     * blocking entry id in the governance audit log.
     *
     * @param array<string, mixed> $input The invocation arguments, when the
     *                                    caller has them; matching for
     *                                    post_id/post_type targets needs
     *                                    them, tool targets do not.
     */
    public function is_permitted(Ability $a, array $input = []): bool
    {
        $reason  = $this->denial_reason($a, $input);
        $allowed = null === $reason;

        if (self::$quiet > 0) {
            // A probe inside quietly(): the decision is the same one, but no
            // call is being made, so there is nothing to audit (issue #733).
            return $allowed;
        }

        // One call, one row (issue #412). The adapter's tools/call and
        // core's REST run route both check permission and then call
        // execute(), which checks again. The check inside execute() (marked
        // by wp_ability_invoked) is not recorded when it repeats the
        // decision the standalone check just recorded.
        $in_execute = isset(self::$invoked[ $a->name ]);
        unset(self::$invoked[ $a->name ]);
        $signature = implode("\0", [$a->name, Identity_Context::current() ?? 'none', $allowed ? '1' : '0', (string) $reason]);
        if ($in_execute && self::$standalone === $signature) {
            self::$standalone = null;
            return $allowed;
        }

        $this->record_audit($a, $allowed, (string) $reason);
        self::$standalone = $in_execute ? null : $signature;
        return $allowed;
    }

    /**
     * is_permitted() for the Abilities API permission callback: true, or
     * false, or the insufficient_scope WP_Error when the only thing standing
     * in the way is a read-only OAuth connection (issue #454), so the MCP
     * client is told which scope the call needs, or the WP_Error naming the
     * memory tools when the input names an agent memory entry (issue #517).
     * The decision and its audit row are is_permitted()'s own.
     *
     * @param array<string, mixed> $input As for is_permitted().
     */
    public function permission(Ability $a, array $input = []): bool|\WP_Error
    {
        if ($this->is_permitted($a, $input)) {
            return true;
        }

        // An input naming an agent memory entry is told to use the memory
        // tools (issue #517) rather than handed a bare permission denial.
        return Client_Access::denial($a) ?? self::plugin_missing($a) ?? Content_Guard::input_refusal($a, $input) ?? false;
    }

    /**
     * Governance domains whose every tool needs one plugin loaded, keyed by
     * domain: the plugin's name for the refusal, its slug for the error
     * data, and the class or function that is present only while the plugin
     * is active (issue #631). Named as strings: the directory build strips
     * some of these integrations, and this core class must not name a class
     * a build lacks.
     *
     * Integration dispatcher pairs are not listed: they answer
     * integration_unavailable themselves, and their list-operations op works
     * without the plugin. Neither is Elementor: its tools read stored
     * Elementor data and detect the version without Elementor loaded, and
     * each one that needs the runtime refuses with elementor_not_active.
     *
     * @var array<string, array{name: string, slug: string, class?: string, function?: string}>
     */
    private const PLUGIN_DOMAINS = [
        'woocommerce' => [ 'name' => 'WooCommerce', 'slug' => 'woocommerce', 'class' => 'WooCommerce' ],
        'acf'         => [ 'name' => 'Advanced Custom Fields', 'slug' => 'advanced-custom-fields', 'function' => 'acf_get_field_groups' ],
    ];

    /** @var array<string, bool> Test seam: per domain, stands in for whether its plugin is loaded. */
    private static array $plugin_loaded_for_tests = [];

    public static function set_plugin_loaded_for_tests(string $domain, ?bool $loaded): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        if (null === $loaded) {
            unset(self::$plugin_loaded_for_tests[ $domain ]);
            return;
        }
        self::$plugin_loaded_for_tests[ $domain ] = $loaded;
    }

    public static function set_woocommerce_loaded_for_tests(?bool $loaded): void
    {
        self::set_plugin_loaded_for_tests('woocommerce', $loaded);
    }

    /** Whether the plugin a domain depends on is loaded; true for a domain with none. */
    private static function plugin_loaded(string $domain): bool
    {
        $dependency = self::PLUGIN_DOMAINS[ $domain ] ?? null;
        if (null === $dependency) {
            return true;
        }
        if (isset(self::$plugin_loaded_for_tests[ $domain ])) {
            return self::$plugin_loaded_for_tests[ $domain ];
        }
        if (isset($dependency['class'])) {
            return class_exists($dependency['class']);
        }
        return function_exists($dependency['function'] ?? '');
    }

    /**
     * The refusal for a tool whose plugin is not active (issue #631), or
     * null when the plugin is loaded or the tool needs none. Asked twice:
     * after a permission denial, because a plugin never installed leaves no
     * capability and "Permission denied" points at the wrong fix; and before
     * every callback, because a deactivated plugin (WooCommerce) leaves its
     * capabilities on the administrator role, so the permission check passes
     * and the callback would call functions that are not there. It never
     * allows anything.
     */
    private static function plugin_missing(Ability $a): ?\WP_Error
    {
        if (self::plugin_loaded($a->domain)) {
            return null;
        }
        $dependency = self::PLUGIN_DOMAINS[ $a->domain ];

        return new \WP_Error(
            'plugin_missing',
            sprintf(
                '%1$s is not active on this site, so %2$s cannot run. Install and activate %1$s, then try again.',
                $dependency['name'],
                $a->name
            ),
            [ 'status' => 403, 'plugin' => $dependency['slug'] ]
        );
    }

    /**
     * wp_ability_invoked (WordPress 7.1): the next permission check for
     * $name is the one inside execute(). See is_permitted().
     *
     * @param mixed $name The ability name.
     */
    public static function note_invoked($name): void
    {
        self::$invoked[ (string) $name ] = true;
    }

    /**
     * Runs $probe with audit rows suppressed, for a caller that must ask the
     * full permission path (the registered permission_callback and core's
     * wp_ability_permission_result filter, which would_permit() does not
     * reach) about a call nobody is making. The handshake summary (issue
     * #733) is one: it asks whether the connecting client could call
     * get-site-context, and recording that as a call wrote a row, and so
     * rewrote the whole audit option, on every initialize and
     * server/discover.
     *
     * @template T
     * @param callable(): T $probe
     * @return T
     */
    public static function quietly(callable $probe)
    {
        self::$quiet++;
        try {
            return $probe();
        } finally {
            self::$quiet--;
        }
    }

    /**
     * The same decision as is_permitted(), without writing an audit row.
     *
     * For callers that need to know what a caller COULD run rather than
     * running it: the in-admin chat (issue #73) builds its advertised tool
     * inventory from this, and asking is_permitted() for every ability on
     * every turn would push a few hundred synthetic rows into a 500-row
     * audit log. It is the same predicate, not a parallel one, so the
     * inventory cannot drift from what execution then enforces; execution
     * itself still goes through is_permitted() and is audited as always.
     *
     * @param array<string, mixed> $input As for is_permitted().
     */
    public function would_permit(Ability $a, array $input = []): bool
    {
        return null === $this->denial_reason($a, $input);
    }

    /**
     * Null when the ability is permitted for the current caller, otherwise
     * the audit reason ('' for a capability/tier/governance/identity denial,
     * 'private-post' for an input naming a plugin-private post or post type,
     * 'post-capability' for a post the caller may not read, edit or delete,
     * 'protected-post' for password-protected content the caller may not
     * see, 'object-capability' for a term, user, comment, order or entry,
     * 'memory-block:<id>' for a project-memory denial, 'insufficient_scope'
     * for a non-read ability called over a read-only OAuth connection).
     *
     * @param array<string, mixed> $input
     */
    private function denial_reason(Ability $a, array $input): ?string
    {
        // The OAuth access level (issue #454) comes first: a read-only
        // connection may run read abilities only, whatever its user could
        // otherwise do. Keyed on the registered operation, so no ability
        // can opt out. An identity-bound connection is narrowed below, by
        // is_within_identity_scope(), through the wpmcp_current_identity
        // filter.
        if (null !== Client_Access::denial($a)) {
            return 'insufficient_scope';
        }

        $allowed = self::tier_permitted($a->tier)
            && current_user_can($a->capability)
            && Governance::is_ability_enabled($a)
            && Governance::is_within_identity_scope($a);

        if (! $allowed) {
            return '';
        }

        // Every post and post type the input names: plugin-private and
        // non-public types the caller may not reach, and core's per-post
        // meta capability (read_post, edit_post, delete_post) for the
        // ability's operation. One check here instead of one per tool.
        $post_denial = Content_Guard::input_denial($a, $input);
        if (null !== $post_denial) {
            return $post_denial;
        }

        // The objects an integration pack op names inside its args, which
        // the keys above never see (issue #450): the same per-object rule.
        if (null !== $a->objects) {
            $object_denial = Object_Guard::denial((array) ($a->objects)($input));
            if (null !== $object_denial) {
                return $object_denial;
            }
        }

        $rule = Memory_Guard::blocking_rule($a, $input);
        if (null !== $rule) {
            return 'memory-block:' . (int) $rule['id'];
        }

        return null;
    }

    /** @return Ability[] */
    public function all(): array
    {
        return array_values($this->abilities);
    }

    /**
     * The full declared surface: every ability register() was handed,
     * including ones the tier gate or governance then dropped. Display-only
     * (the ability grid, issue #78) - nothing here is registered with the
     * Abilities API or reachable over MCP unless it also passed the gates
     * into all().
     *
     * It is a superset of what any one screen shows: the grid narrows it by
     * tier through tier_permitted() (issue #161) and keeps only the
     * governance-disabled rows, which are the ones an admin can act on.
     *
     * @return Ability[]
     */
    public function declared(): array
    {
        return array_values($this->declared);
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
     * The refusal for a name that is not registered because it is a Pro
     * tool and this install has no active license (issue #631), or null
     * when that is not the reason. Lets the meta-tools say "needs WP MCP
     * Pro" instead of "no tool by that name", with the same upgrade offer
     * the admin banner carries (Pro_Refusal). Grants nothing: the tool is
     * still unregistered and unreachable.
     */
    public function pro_required(string $name): ?\WP_Error
    {
        $declared = $this->declared[ $name ] ?? $this->held_for_plugin[ $name ]['ability'] ?? null;
        if (null === $declared || isset($this->abilities[ $name ]) || self::tier_permitted($declared->tier)) {
            return null;
        }

        return new \WP_Error(
            'pro_required',
            sprintf(
                '"%s" is a WP MCP Pro tool, and this site has no active WP MCP Pro license, so the tool is not available here. Activate a WP MCP Pro license on this site to use it.',
                $name
            ) . Pro_Refusal::upgrade_sentence()
        );
    }

    /**
     * Note an ability that is not registered because the plugin it works
     * with is not active (issue #790): a pair whose integration registers
     * only while its host plugin is loaded. $plugin names that plugin, or,
     * with $several, the plugins any one of which would do. $operations
     * names, for a dispatcher pair, the plugin each op needs (op name =>
     * ['name', 'several'], issue #815), so a refusal for one op names that
     * op's plugin rather than every plugin the pair works with. Grants
     * nothing.
     *
     * @param array<string, array{name: string, several: bool}> $operations
     */
    public function hold_for_plugin(Ability $a, string $plugin, bool $several = false, array $operations = []): void
    {
        $this->held_for_plugin[ $a->name ] = [ 'ability' => $a, 'plugin' => $plugin, 'several' => $several, 'operations' => $operations ];
    }

    /**
     * The refusal for a name that is not registered because its plugin is
     * not active (issue #790), or null when that is not the reason. Names
     * the plugin to activate instead of claiming no such tool exists. A
     * tool this install's tier cannot run is left to the tier refusal,
     * since activating the plugin alone would not bring it back. When
     * $arguments name an 'operation' whose plugin the pair declared, the
     * refusal names that op's plugin (issue #815). Grants nothing: the
     * tool stays unregistered and unreachable.
     *
     * @param array<string, mixed> $arguments the call's arguments, if any
     */
    public function plugin_inactive(string $name, array $arguments = []): ?\WP_Error
    {
        $held = $this->held_for_plugin[ $name ] ?? null;
        if (null === $held || isset($this->abilities[ $name ]) || ! self::tier_permitted($held['ability']->tier)) {
            return null;
        }

        $operation = is_string($arguments['operation'] ?? null) ? $arguments['operation'] : '';
        $host      = '' === $operation ? null : ( $held['operations'][ $operation ] ?? null );
        if (null !== $host) {
            $message = $host['several']
                ? sprintf(
                    'Op "%1$s" of %2$s works with %3$s, and none of them is active on this site, so it cannot run. Install and activate one of them, then try again.',
                    $operation,
                    $name,
                    $host['name']
                )
                : sprintf(
                    '%1$s is not active on this site, so op "%2$s" of %3$s cannot run. Install and activate %1$s, then try again.',
                    $host['name'],
                    $operation,
                    $name
                );

            return new \WP_Error('plugin_missing', $message, [ 'plugin' => $host['name'], 'operation' => $operation ]);
        }

        $message = $held['several']
            ? sprintf(
                'None of the plugins %1$s works with is active on this site (%2$s), so %1$s cannot run. Install and activate one of them, then try again.',
                $name,
                $held['plugin']
            )
            : sprintf(
                '%1$s is not active on this site, so %2$s cannot run. Install and activate %1$s, then try again.',
                $held['plugin'],
                $name
            );

        return new \WP_Error('plugin_missing', $message, [ 'plugin' => $held['plugin'] ]);
    }

    /**
     * The refusal for a name that is declared but not registered because
     * governance switched it off (issue #772), or null when that is not
     * the reason. Names the layer that decided (an ability, domain or
     * operation toggle, or one of the wpmcp_*_enabled filters) and where
     * to switch it back on, instead of the "not registered" answer an
     * unknown name gets. Grants nothing: the tool stays unregistered.
     */
    public function governance_disabled(string $name): ?\WP_Error
    {
        $declared = $this->declared[ $name ] ?? null;
        if (null === $declared || isset($this->abilities[ $name ]) || ! self::tier_permitted($declared->tier)) {
            return null;
        }
        $layer = Governance::explain($declared)['layer'];
        if (null === $layer) {
            return null;
        }

        [ $dimension, $kind ] = explode('_', $layer, 2);
        $key                  = 'ability' === $dimension ? $name : $declared->{$dimension};
        if ('toggle' === $kind) {
            $cause = sprintf('the %s toggle for "%s" is off', $dimension, $key);
            $fix   = 'An administrator can switch it back on under WP MCP > Abilities in wp-admin, or with wpmcp/update-governance-settings.';
        } else {
            $cause = sprintf('the wpmcp_%s_enabled filter on this site switches off "%s"', $dimension, $key);
            $fix   = 'An administrator or developer has to change that filter in the site code before it can be used.';
        }

        return new \WP_Error(
            'governance_disabled',
            sprintf('"%s" is registered on this site but disabled by governance: %s. %s', $name, $cause, $fix),
            [ 'layer' => $layer, 'dimension' => $dimension, 'key' => $key ]
        );
    }

    /**
     * Record a governance-decision outcome to Governance_Audit_Log. Wrapped
     * in a try/catch so a logging failure (e.g. an option-write error) can
     * never turn an otherwise-successful permission check into a fatal
     * error; the allow/deny decision itself is always returned regardless
     * of whether this succeeds.
     */
    private function record_audit(Ability $a, bool $allowed, string $reason = ''): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record($a->name, $identity, $allowed, $reason);
        } catch (\Throwable $e) {
            // Auditing must never break the permission check it is observing.
        }
    }

    /**
     * Wraps an ability's handler with a rate-limit check that runs BEFORE the
     * real tool, and records the outcome of every call that gets past it. The
     * permission_callback contract (capability + Governance) is untouched;
     * this only sits in front of execute_callback, so a client over budget
     * never reaches the tool at all. The budget is a single counter per client
     * shared across every ability (Rate_Limiter::check() keys only on client
     * identity, not on ability name), matching "global per-client counter
     * across all abilities".
     *
     * Because every ability passes through this single choke point, the
     * outcome log (issue #134) covers reads as well as writes with no
     * per-tool changes: a throttled call, a WP_Error return, a thrown
     * exception and a success all leave exactly one row.
     */
    private function throttled(Ability $a): callable
    {
        return function (...$args) use ($a) {
            $client = Rate_Limiter::client_key();
            $status = Rate_Limiter::check($client);
            if (! $status['allowed']) {
                $error = new \WP_Error(
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
                $this->record_outcome($a, $client, $error, 0, $args, null);
                return $error;
            }

            // A tool whose plugin is not active never reaches its callback
            // (issue #631), whatever the permission check decided.
            $missing = self::plugin_missing($a);
            if (null !== $missing) {
                $this->record_outcome($a, $client, $missing, 0, $args, null);
                return $missing;
            }

            // Bracket the call rather than resetting the context, so a tool
            // that dispatches another tool cannot steal this call's undo point.
            $mark    = Operation_Context::mark();
            $started = microtime(true);
            if (0 === self::$depth++) {
                ++self::$call_id;
            }
            try {
                $result = ($a->handler)(...$args);
                if (1 === self::$depth && ! is_wp_error($result)) {
                    /**
                     * Filters an outermost tool call's successful result
                     * while the call is still current, so follow-up work
                     * on what it did stays part of it (issue #432).
                     *
                     * @param mixed  $result The handler's result.
                     * @param string $name   The ability name.
                     * @param array  $input  The call's arguments.
                     */
                    $result = apply_filters('wpmcp_tool_result', $result, $a->name, isset($args[0]) && is_array($args[0]) ? $args[0] : []);
                }
            } catch (\WPMCP\Safety\Post_Locked $e) {
                // Another user is editing the post (issue #452): a refusal
                // the caller can act on, returned as its WP_Error.
                $result = $e->error();
            } catch (\Throwable $e) {
                if ($e instanceof Confirmation_Required) {
                    // Typed here, wrapped by core past this point (issue #387).
                    Confirmation_Required::observe($e);
                }
                // A failed write still leaves a snapshot behind, so the row
                // keeps its undo point, and the row keeps the real exception
                // class, which the WP_Error below no longer carries.
                $this->record_outcome(
                    $a,
                    $client,
                    $e,
                    self::elapsed_ms($started),
                    $args,
                    Operation_Context::since($mark)
                );
                // Returned, not re-thrown (issue #612). WordPress 7.1 turns
                // anything a callback throws into this same WP_Error inside
                // WP_Ability, but 6.9 has no such catch: there the exception
                // left execute(), the MCP Adapter answered with an opaque
                // JSON-RPC "Failed to execute tool" and the client never saw
                // the reason. Building core 7.1's error here gives every
                // supported WordPress the same outcome.
                return self::exception_error($a, $e);
            } finally {
                --self::$depth;
            }

            $this->record_outcome(
                $a,
                $client,
                $result,
                self::elapsed_ms($started),
                $args,
                Operation_Context::since($mark)
            );
            return $result;
        };
    }

    /**
     * The WP_Error a thrown exception becomes. This wrapper catches what a
     * callback throws before core 7.1's own catch in WP_Ability can, so the
     * outcome is the same on every supported WordPress (issue #612).
     *
     * The message is plain text (issue #724). An MCP tool error is not
     * HTML, and tools escape the names they put into exception messages
     * (the WordPress coding standards ask for it), so this decodes those
     * entities rather than adding more: a refusal reaches the client as
     * "wpmcp_db_write_audit_log", not &quot;wpmcp_db_write_audit_log&quot;.
     * Whatever prints a message into wp-admin escapes it there.
     *
     * An expected refusal (any \Exception: the tools throw
     * InvalidArgumentException, RuntimeException and their subclasses on
     * purpose) carries only its own message (issue #631). The core-style
     * "callback threw an exception" prefix made a clear refusal read like a
     * crash. A PHP engine error (\Error, such as a TypeError) is a real
     * crash, so it keeps the prefix. An Opt_In_Required refusal gets the
     * opt_in_required code; everything else keeps ability_callback_exception.
     */
    private static function exception_error(Ability $a, \Throwable $e): \WP_Error
    {
        $message = self::plain_text($e->getMessage());

        if (! $e instanceof \Exception) {
            return new \WP_Error(
                'ability_callback_exception',
                sprintf(
                    /* translators: 1: Ability name, 2: Exception message. */
                    __('Ability "%1$s" callback threw an exception: %2$s', 'wpmcp'),
                    $a->name,
                    $message
                )
            );
        }

        return new \WP_Error(
            $e instanceof Opt_In_Required ? 'opt_in_required' : 'ability_callback_exception',
            $message
        );
    }

    /**
     * A message as plain text for the MCP wire (issue #724): the HTML
     * special characters esc_html() encodes, decoded once, so a message a
     * tool escaped reads as it was written.
     */
    public static function plain_text(string $message): string
    {
        return wp_specialchars_decode($message, ENT_QUOTES);
    }

    private static function elapsed_ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * Append one Request_Log row for a finished (or throttled) call. Wrapped
     * in a try/catch for the same reason as record_audit(): observability must
     * never turn a working tool call into a failure.
     *
     * @param mixed        $outcome The handler's return value, or the Throwable it threw.
     * @param array<mixed> $args    Positional handler arguments, as passed by the Abilities API.
     */
    private function record_outcome(
        Ability $a,
        string $client,
        $outcome,
        int $duration_ms,
        array $args,
        ?string $operation_id
    ): void {
        try {
            $entry = [
                'tool'        => $a->name,
                'client'      => $client,
                'ok'          => true,
                'duration_ms' => $duration_ms,
                'args'        => isset($args[0]) && is_array($args[0]) ? $args[0] : [],
                'secret_args' => Request_Log::secret_fields($a->input_schema),
            ];

            if ($outcome instanceof \Throwable) {
                $parts          = explode('\\', get_class($outcome));
                $entry['ok']            = false;
                $entry['error_code']    = 'exception:' . end($parts);
                $entry['error_message'] = $outcome->getMessage();
            } elseif (is_wp_error($outcome)) {
                $entry['ok']            = false;
                $entry['error_code']    = (string) $outcome->get_error_code();
                $entry['error_message'] = (string) $outcome->get_error_message();
            }

            // Tools that report their own operation_id (the Safe_Mutation
            // return shape) are honored as a fallback for any write that does
            // not route through Safe_Mutation itself.
            if (null === $operation_id && is_array($outcome) && ! empty($outcome['operation_id'])) {
                $operation_id = (string) $outcome['operation_id'];
            }
            $entry['operation_id'] = (string) $operation_id;

            Request_Log::record($entry);
        } catch (\Throwable $e) {
            // Logging must never break the call it is observing.
        }
    }
}
