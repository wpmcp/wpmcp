<?php

namespace WPMCP\Integrations;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Ability;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base for third-party plugin integrations exposed as a single
 * {integration}-read / {integration}-write dispatcher pair instead of N flat
 * tools (issue #65). The tool surface stays at two abilities per integration
 * while the operation catalog underneath can grow freely.
 *
 * A concrete integration supplies:
 *  - integration():  slug used in ability names ("acf" -> wpmcp/acf-read)
 *  - is_available(): whether the host plugin is loaded; when false every
 *    real operation returns a structured integration_unavailable error (the
 *    reserved list-operations op still answers, reporting available:false),
 *    so a missing host plugin can never fatal
 *  - operations():   op name => definition. Definition keys:
 *      'mode'               'read' | 'write' | 'destructive' (required)
 *      'handler'            callable(array $args): mixed (required)
 *      'description'        string
 *      'input_schema'       JSON schema validated BEFORE dispatch via
 *                           rest_validate_value_from_schema(); malformed args
 *                           are rejected with no side effects
 *      'capability'         per-op WordPress capability override, checked ON
 *                           TOP of the dispatcher ability's own capability so
 *                           one risky op can demand more than its siblings
 *      'enabled_by_default' bool (default true); a default-off op is refused
 *                           until the site opts in via the
 *                           wpmcp_integration_op_enabled filter
 *      'validate'           callable(array $args) returning null to proceed or
 *                           ['code', 'message', 'data'] to refuse. Runs after
 *                           schema validation and BEFORE the handler and
 *                           before any snapshot is captured, so an op's own
 *                           preconditions refuse with no side effects. A
 *                           handler may also throw Operation_Refused for a
 *                           failure only discoverable mid-write; both surface
 *                           as the ordinary top-level error envelope
 *      'snapshot'           write/destructive ops only: callable(array $args)
 *                           returning ['object_type' => ..., 'object_id' => ...]
 *                           (or null) naming the snapshotable target. When it
 *                           yields a target the write routes through
 *                           Safe_Mutation (snapshot first, operation_id out,
 *                           restorable via rollback-operation); when absent
 *                           the op runs directly and the response carries
 *                           recoverable:false so the caller knows
 *
 * Dispatch order (each step short-circuits into a structured
 * ['error' => ['code', 'message', 'data']] payload, and the op handler is
 * only ever reached after ALL of them pass — a rejected call has no side
 * effects and writes no snapshot):
 *   availability -> op exists in this channel -> enabled flag/filter ->
 *   op-level governance -> per-op capability -> destructive confirm:true ->
 *   schema validation -> per-op validate() -> handler.
 *
 * Layering with the platform gates: the pair's own capability, Governance,
 * identity scope, and pro-tier gates all apply unchanged through
 * Registrar::is_permitted() before a dispatcher ability executes at all.
 * Op-granular governance is layered on top by evaluating a synthetic
 * per-op Ability named "wpmcp/{integration}-{op}" through
 * Governance::is_ability_enabled(), so the full six-layer AND-of-narrowing
 * model (stored toggles + filters across ability/domain/operation) can
 * disable a single op without touching the pair. Identity scope is
 * deliberately NOT re-evaluated against the synthetic per-op names: an
 * allow-mode identity scoped to abilities=[wpmcp/acf-write] must keep
 * working, and the real identity narrowing already ran against the pair.
 */
abstract class Integration_Dispatcher
{
    private const MODES = [ 'read', 'write', 'destructive' ];

    /** Reserved read operation exposing the catalog; always answers. */
    private const LIST_OPERATIONS = 'list-operations';

    /** Slug of the integration, e.g. 'acf'. Used in ability and synthetic op names. */
    abstract public function integration(): string;

    /** Whether the host plugin is loaded in this process. */
    abstract public function is_available(): bool;

    /** @return array<string, array> op name => definition (see class docblock). */
    abstract protected function operations(): array;

    /** Tier of the dispatcher pair; Registrar drops 'pro' pairs without a license. */
    public function tier(): string
    {
        return 'free';
    }

    /** Dispatcher-level capability for BOTH halves; per-op overrides only add on top. */
    public function capability(): string
    {
        return 'edit_posts';
    }

    /** Governance domain for the pair and for every synthetic per-op ability. */
    public function domain(): string
    {
        return $this->integration();
    }

    /** Human description of what the integration covers, used in ability descriptions. */
    protected function summary(): string
    {
        return sprintf('the %s integration', $this->integration());
    }

    /**
     * The read/write Ability pair for this integration, ready for
     * Registrar::register(). The write half carries destructive_hint when
     * any registered op is destructive.
     */
    public function abilities(): array
    {
        $slug            = $this->integration();
        $has_destructive = false;
        foreach ($this->operations() as $def) {
            if ('destructive' === ($def['mode'] ?? '')) {
                $has_destructive = true;
                break;
            }
        }

        $read = new Ability(
            "wpmcp/{$slug}-read",
            $this->tier(),
            sprintf(
                'Dispatch a read operation against %s. Pass operation (use the reserved "list-operations" to discover every operation with its input schema) plus args matching that operation\'s schema. Read-only',
                $this->summary()
            ),
            $this->dispatcher_schema(false),
            [ $this, 'handle_read' ],
            $this->capability(),
            $this->domain(),
            'read'
        );

        $write = new Ability(
            "wpmcp/{$slug}-write",
            $this->tier(),
            sprintf(
                'Dispatch a write operation against %s. Pass operation plus args matching that operation\'s schema (discoverable via list-operations on the read half). Every operation with a snapshotable target is snapshotted first via Safe_Mutation and restorable with rollback-operation; destructive operations additionally require confirm:true',
                $this->summary()
            ),
            $this->dispatcher_schema(true),
            [ $this, 'handle_write' ],
            $this->capability(),
            $this->domain(),
            'update',
            null,
            $has_destructive ? true : null
        );

        return [ $read, $write ];
    }

    /** Entry point for the {integration}-read ability. */
    public function handle_read(array $args): array
    {
        return $this->dispatch('read', $args);
    }

    /** Entry point for the {integration}-write ability. */
    public function handle_write(array $args): array
    {
        return $this->dispatch('write', $args);
    }

    /**
     * The operation catalog: every op with mode, description, capability,
     * enabled state, confirm requirement, and input schema, plus whether the
     * host plugin is currently available.
     */
    public function catalog(): array
    {
        $ops = [];
        foreach ($this->operations() as $name => $def) {
            $ops[] = [
                'name'             => $name,
                'mode'             => (string) ($def['mode'] ?? ''),
                'description'      => (string) ($def['description'] ?? ''),
                'capability'       => $def['capability'] ?? $this->capability(),
                'enabled'          => $this->is_op_enabled($name, $def),
                'requires_confirm' => 'destructive' === ($def['mode'] ?? ''),
                'input_schema'     => $def['input_schema'] ?? [ 'type' => 'object' ],
            ];
        }

        return [
            'integration' => $this->integration(),
            'available'   => $this->is_available(),
            'operations'  => $ops,
        ];
    }

    /**
     * Run one operation through the guard chain. $channel is which dispatcher
     * half was invoked: 'read' sees only mode=read ops, 'write' sees
     * write + destructive ops. See the class docblock for the full order.
     */
    private function dispatch(string $channel, array $args): array
    {
        $op = (string) ($args['operation'] ?? '');

        if ('read' === $channel && self::LIST_OPERATIONS === $op) {
            return $this->ok(self::LIST_OPERATIONS, $this->catalog());
        }

        if (! $this->is_available()) {
            return $this->error('integration_unavailable', sprintf(
                'The %s integration is not available: its host plugin is not active on this site.',
                $this->integration()
            ));
        }

        $ops = $this->channel_operations($channel);
        if (! isset($ops[ $op ])) {
            return $this->error('unknown_operation', sprintf(
                'Unknown %s operation "%s" for the %s integration.',
                $channel,
                $op,
                $this->integration()
            ), [ 'operations' => array_keys($ops) ]);
        }
        $def = $ops[ $op ];

        if (! $this->is_op_enabled($op, $def)) {
            return $this->error('operation_disabled', sprintf(
                'Operation "%s" is disabled by default. Enable it with the wpmcp_integration_op_enabled filter.',
                $op
            ));
        }

        if (! $this->passes_op_governance($op, $def)) {
            return $this->error('operation_denied', sprintf(
                'Operation "%s" has been disabled by governance policy.',
                $op
            ), [ 'reason' => 'governance' ]);
        }

        $capability = $def['capability'] ?? null;
        if (null !== $capability && ! current_user_can($capability)) {
            return $this->error('operation_denied', sprintf(
                'Operation "%s" requires the "%s" capability.',
                $op,
                $capability
            ), [ 'reason' => 'capability' ]);
        }

        if ('destructive' === $def['mode'] && true !== ($args['confirm'] ?? false)) {
            return $this->error('confirmation_required', sprintf(
                'Operation "%s" is destructive and requires confirm:true.',
                $op
            ));
        }

        $op_args = $args['args'] ?? [];
        if (! is_array($op_args)) {
            $op_args = (array) $op_args;
        }
        $schema = $def['input_schema'] ?? [ 'type' => 'object' ];
        $valid  = rest_validate_value_from_schema($op_args, $schema, 'args');
        if (is_wp_error($valid)) {
            return $this->error('invalid_args', $valid->get_error_message(), [
                'operation'    => $op,
                'input_schema' => $schema,
            ]);
        }

        // Pre-dispatch refusal hook: an op whose own preconditions (an
        // allowlist, a filesystem gate, a slug that will not confine) can be
        // decided from the args alone rejects HERE, before any handler runs
        // and, crucially, before run_write() captures a snapshot. That keeps
        // the guarantee above literally true rather than nearly true.
        if (isset($def['validate'])) {
            $refusal = ($def['validate'])($op_args);
            if (is_array($refusal) && isset($refusal['code'])) {
                return $this->error(
                    (string) $refusal['code'],
                    (string) ($refusal['message'] ?? ''),
                    (array) ($refusal['data'] ?? [])
                );
            }
        }

        try {
            if ('read' === $channel) {
                return $this->ok($op, ($def['handler'])($op_args));
            }

            return $this->run_write($op, $def, $op_args, (string) ($args['session_id'] ?? 'default'));
        } catch (Operation_Refused $e) {
            // Mid-write failures (mkdir, file write) surface as the same
            // top-level envelope as every other refusal, never as a
            // successful result carrying an 'error' key.
            return $this->error($e->error_code(), $e->getMessage(), $e->error_data());
        }
    }

    /**
     * Execute a write/destructive op, snapshot-first whenever the op names a
     * snapshotable target. Ops without a target run directly and are
     * honestly flagged recoverable:false.
     */
    private function run_write(string $op, array $def, array $op_args, string $session_id): array
    {
        $target = isset($def['snapshot']) ? ($def['snapshot'])($op_args) : null;

        if (null === $target) {
            return $this->ok($op, ($def['handler'])($op_args)) + [ 'recoverable' => false ];
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => (string) $target['object_type'],
                'object_id'   => $target['object_id'],
                'session_id'  => $session_id,
                'tool_name'   => sprintf('%s-write', $this->integration()),
                'args'        => [ 'operation' => $op, 'args' => $op_args ],
            ],
            fn () => ($def['handler'])($op_args)
        );

        return $this->ok($op, $out['result']) + [
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /** Ops visible to one dispatcher half; write sees write + destructive. */
    private function channel_operations(string $channel): array
    {
        $out = [];
        foreach ($this->operations() as $name => $def) {
            $mode = $def['mode'] ?? '';
            if (! in_array($mode, self::MODES, true) || ! isset($def['handler'])) {
                continue; // Malformed definitions are simply not exposed.
            }
            $is_read = 'read' === $mode;
            if (('read' === $channel) === $is_read) {
                $out[ $name ] = $def;
            }
        }
        return $out;
    }

    /**
     * Default-enabled flag, filterable per op: a site opts a default-off op
     * in (or switches a default-on op off) with the
     * wpmcp_integration_op_enabled filter. Governance narrowing is layered
     * separately in passes_op_governance() and stays AND-only.
     */
    private function is_op_enabled(string $op, array $def): bool
    {
        $default = (bool) ($def['enabled_by_default'] ?? true);
        return (bool) apply_filters('wpmcp_integration_op_enabled', $default, $this->integration(), $op);
    }

    /**
     * Op-granular governance: evaluate a synthetic Ability named
     * "wpmcp/{integration}-{op}" through the full six-layer model so stored
     * toggles and filters can disable one op without touching the pair. The
     * decision is audited like any other governance decision; audit failure
     * never breaks the check itself.
     */
    private function passes_op_governance(string $op, array $def): bool
    {
        $synthetic = new Ability(
            sprintf('wpmcp/%s-%s', $this->integration(), $op),
            $this->tier(),
            (string) ($def['description'] ?? $op),
            [ 'type' => 'object' ],
            static fn () => null,
            $def['capability'] ?? $this->capability(),
            $this->domain(),
            $this->governance_operation((string) $def['mode'])
        );

        $allowed = Governance::is_ability_enabled($synthetic);

        try {
            Governance_Audit_Log::record($synthetic->name, Identity_Context::current() ?? 'none', $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the permission check it is observing.
        }

        return $allowed;
    }

    /** Map a dispatcher op mode onto the Ability operation vocabulary. */
    private function governance_operation(string $mode): string
    {
        return [ 'read' => 'read', 'write' => 'update', 'destructive' => 'delete' ][ $mode ] ?? 'update';
    }

    /** Input schema of a dispatcher ability itself (not of any single op). */
    private function dispatcher_schema(bool $write): array
    {
        $properties = [
            'operation' => [ 'type' => 'string' ],
            'args'      => [ 'type' => 'object' ],
        ];
        if ($write) {
            $properties['confirm']    = [ 'type' => 'boolean' ];
            $properties['session_id'] = [ 'type' => 'string' ];
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => [ 'operation' ],
        ];
    }

    private function ok(string $op, $result): array
    {
        return [
            'integration' => $this->integration(),
            'operation'   => $op,
            'result'      => $result,
        ];
    }

    private function error(string $code, string $message, array $data = []): array
    {
        return [
            'integration' => $this->integration(),
            'error'       => [
                'code'    => $code,
                'message' => $message,
                'data'    => $data,
            ],
        ];
    }
}
