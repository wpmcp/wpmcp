<?php

namespace WPMCP\Integrations;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Content_Guard;

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
 *      'requires'           callable(): true|array, a per-op dependency check
 *                           for an op that needs a companion plugin the
 *                           integration as a whole does not (CF7 entries need
 *                           Flamingo). Returns true when satisfied, or
 *                           ['code' => ..., 'message' => ...] naming what is
 *                           missing, which the dispatcher emits as its own
 *                           top-level error. The op stays in the catalog
 *                           (flagged dependency_met:false) so list-operations still
 *                           documents it, but the handler is never reached and
 *                           no snapshot is written
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
 *                           (plus optional 'extra_snapshot_data', merged into
 *                           the persisted snapshot, e.g. a db_rows
 *                           before-image) or null, naming the snapshotable
 *                           target. When it
 *                           yields a target the write routes through
 *                           Safe_Mutation (snapshot first, operation_id out,
 *                           restorable via rollback-operation); when absent
 *                           the op runs directly and the response carries
 *                           recoverable:false so the caller knows. The
 *                           target is also handed to the handler as
 *                           $context['target'], so a create whose key is
 *                           chosen in the snapshot callable writes under
 *                           exactly the key that was captured, along with
 *                           $context['operation_id'], so a handler whose
 *                           write is refused after the snapshot can void
 *                           that undo point (Snapshot_Store::delete_operation())
 *      'self_snapshotting'  write ops only: true when the op touches several
 *                           objects and runs its own Safe_Mutation per
 *                           object. The handler is called as
 *                           handler($args, $context) with
 *                           $context['session_id'] (the caller's session_id,
 *                           or a fresh one) and returns ['result' => ...,
 *                           'operation_ids' => [...]]; the response carries
 *                           the session_id, so one rollback-session undoes
 *                           the whole call
 *      'objects'            args key => object kind, for every args key
 *                           that names a WordPress object by id (issue
 *                           #450): 'post', 'term', 'user', 'comment',
 *                           'order' or 'entry'. A key inside a list of
 *                           objects is written 'updates.*.post_id'. The
 *                           value may instead be ['type' => kind, 'access'
 *                           => 'read'|'write'|'delete' (default: from the
 *                           op's mode), 'own_type' => true for a post key
 *                           the op only accepts as its host plugin's own
 *                           post type, 'capability' => the plugin's entry
 *                           capability (default: the op's)]. Both halves'
 *                           permission decision then checks the matching
 *                           per-object capability for each id through
 *                           Object_Guard, before the op runs, so a pack
 *                           cannot forget it. See declared_objects()
 *      'tier'               'free' (default) or another tier. An op whose
 *                           tier Registrar::tier_permitted() refuses on this
 *                           install is left out of the catalog and of
 *                           dispatch, the op-level mirror of how Registrar
 *                           drops a whole ability
 *
 * Dispatch order (each step short-circuits into a structured
 * ['error' => ['code', 'message', 'data']] payload, and the op handler is
 * only ever reached after ALL of them pass - a rejected call has no side
 * effects and writes no snapshot):
 *   availability -> op exists in this channel -> enabled flag/filter ->
 *   op-level governance -> per-op capability -> per-op 'requires' dependency ->
 *   destructive confirm:true -> schema validation -> per-op validate() -> handler.
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

    /**
     * Whether the pair registers only while its host plugin is loaded. The
     * default keeps the #65 contract (register unconditionally so
     * list-operations can report available:false); an integration family can
     * opt into "absent plugin, absent tools" instead, which keeps a site's
     * tool list free of pairs that could only ever answer
     * integration_unavailable (issue #66 does this for every forms adapter).
     */
    public function registers_only_when_available(): bool
    {
        return false;
    }

    /**
     * Whether Plugin should register this pair on the current site right now.
     * Filterable through wpmcp_integration_should_register (bool, slug,
     * integration) so a site can keep an integration's tools off its surface
     * entirely, or list a forms pair even while its plugin is absent.
     */
    public function should_register(): bool
    {
        $default = ! $this->registers_only_when_available() || $this->is_available();
        return (bool) apply_filters('wpmcp_integration_should_register', $default, $this->integration(), $this);
    }

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
                'Read op on %s: operation + matching args (list-operations lists each op\'s schema)',
                $this->summary()
            ),
            $this->dispatcher_schema(false),
            [ $this, 'handle_read' ],
            $this->capability(),
            $this->domain(),
            'read',
            objects: \Closure::fromCallable([ $this, 'named_objects' ])
        );

        $write = new Ability(
            "wpmcp/{$slug}-write",
            $this->tier(),
            sprintf(
                'Write op on %s: operation + args per its schema (list-operations on the read half). Snapshot-first if possible (undo: rollback-operation); destructive ops need confirm:true',
                $this->summary()
            ),
            $this->dispatcher_schema(true),
            [ $this, 'handle_write' ],
            $this->capability(),
            $this->domain(),
            'update',
            null,
            $has_destructive ? true : null,
            objects: \Closure::fromCallable([ $this, 'named_objects' ])
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
     * enabled state, confirm requirement, input schema, and whether its own
     * 'requires' dependency is satisfied (per-op 'dependency_met'), plus the
     * top-level 'available' saying whether the HOST plugin is loaded. The two
     * answer different questions and are deliberately named differently.
     */
    public function catalog(): array
    {
        $ops = [];
        foreach ($this->permitted_operations() as $name => $def) {
            $ops[] = [
                'name'             => $name,
                'mode'             => (string) ($def['mode'] ?? ''),
                'description'      => (string) ($def['description'] ?? ''),
                'capability'       => $def['capability'] ?? $this->capability(),
                'enabled'          => $this->is_op_enabled($name, $def),
                'requires_confirm' => 'destructive' === ($def['mode'] ?? ''),
                'dependency_met'   => true === self::op_requirement($def),
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
     * Every op's declared objects (the 'objects' key, issue #450),
     * normalized: op name => ['mode' => ..., 'objects' => [args key path =>
     * ['type', 'access', 'own_type', 'capability']]]. Ops that name no
     * object are left out.
     *
     * @return array<string, array{mode: string, objects: array<string, array{type: string, access: string, own_type: bool, capability: string}>}>
     */
    public function declared_objects(): array
    {
        $out = [];
        foreach ($this->permitted_operations() as $name => $def) {
            if (empty($def['objects']) || ! is_array($def['objects'])) {
                continue;
            }
            $mode    = (string) ($def['mode'] ?? '');
            $access  = [ 'read' => 'read', 'destructive' => 'delete' ][ $mode ] ?? 'write';
            $objects = [];
            foreach ($def['objects'] as $path => $spec) {
                $spec                      = is_array($spec) ? $spec : [ 'type' => (string) $spec ];
                $objects[ (string) $path ] = [
                    'type'       => (string) ($spec['type'] ?? ''),
                    'access'     => (string) ($spec['access'] ?? $access),
                    'own_type'   => ! empty($spec['own_type']),
                    'capability' => (string) ($spec['capability'] ?? ($def['capability'] ?? $this->capability())),
                ];
            }
            $out[ (string) $name ] = [ 'mode' => $mode, 'objects' => $objects ];
        }
        return $out;
    }

    /**
     * Run a post listing kept to the rows the current user may read (issue
     * #461), for a pack op that lists its host plugin's posts. WP_Query
     * applies no read permission to 'any' or an explicit status, so other
     * users' drafts and private rows would otherwise be listed; the filter
     * is core's read_post rule in SQL (Content_Guard::readable_posts_where(),
     * as list-posts uses), so found_posts stays an honest total.
     *
     * @param array<string, mixed> $query_args WP_Query arguments with a post_type.
     */
    protected static function readable_query(array $query_args): \WP_Query
    {
        global $wpdb;
        $where = Content_Guard::readable_posts_where((array) ($query_args['post_type'] ?? 'post'), $wpdb->posts);
        $query = new \WP_Query();
        $scope = static function ($sql, $q) use (&$query, $where) {
            return $q === $query ? $sql . $where : $sql;
        };
        if ('' !== $where) {
            add_filter('posts_where', $scope, 10, 2);
        }
        try {
            $query->query($query_args);
        } finally {
            remove_filter('posts_where', $scope, 10);
        }
        return $query;
    }

    /**
     * The objects one invocation of either half names in its args, for
     * Object_Guard (Ability::$objects). Values that are not numeric ids are
     * left to schema validation, which refuses them before the op runs.
     *
     * @param array<string, mixed> $input The dispatcher ability's input.
     * @return array<int, array<string, mixed>>
     */
    public function named_objects(array $input): array
    {
        $declared = $this->declared_objects()[ (string) ($input['operation'] ?? '') ] ?? null;
        if (null === $declared) {
            return [];
        }
        $out = [];
        foreach ($declared['objects'] as $path => $spec) {
            foreach (self::values_at($input['args'] ?? [], explode('.', $path)) as $value) {
                foreach ((array) $value as $id) {
                    if (is_numeric($id)) {
                        $out[] = $spec + [ 'id' => (int) $id ];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The values at a key path in decoded args; '*' walks every item of a
     * list.
     *
     * @param mixed    $data
     * @param string[] $parts
     * @return array<int, mixed>
     */
    private static function values_at($data, array $parts): array
    {
        if ([] === $parts) {
            return [ $data ];
        }
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (! is_array($data)) {
            return [];
        }
        $part = array_shift($parts);
        if ('*' === $part) {
            $out = [];
            foreach ($data as $item) {
                $out = array_merge($out, self::values_at($item, $parts));
            }
            return $out;
        }
        return array_key_exists($part, $data) ? self::values_at($data[ $part ], $parts) : [];
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

        $requirement = self::op_requirement($def);
        if (true !== $requirement) {
            return $this->error(
                (string) ($requirement['code'] ?? 'dependency_unavailable'),
                (string) ($requirement['message'] ?? sprintf('Operation "%s" is missing a dependency.', $op)),
                [ 'operation' => $op ]
            );
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

            return $this->run_write($op, $def, $op_args, isset($args['session_id']) ? (string) $args['session_id'] : null);
        } catch (Operation_Error $e) {
            // A handler-raised refusal belongs on the SAME top-level error
            // channel as the dispatcher's own guards. Returning it inside the
            // success envelope would leave an agent unable to tell a refusal
            // from an empty result.
            return $this->error(
                $e->error_code(),
                $e->getMessage(),
                $e->error_data() + [ 'operation' => $op ]
            );
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
    private function run_write(string $op, array $def, array $op_args, ?string $session_id): array
    {
        // A session another user started is refused before anything runs
        // (issue #461); Snapshot_Store::save() refuses it again per row.
        $denial = null === $session_id ? null : Snapshot_Store::session_write_denial($session_id);
        if (null !== $denial) {
            return $this->error('session_not_owned', $denial, [ 'operation' => $op ]);
        }

        if (! empty($def['self_snapshotting'])) {
            // A multi-object op gets its own session unless the caller named
            // one, so rollback-session undoes exactly this call and nothing
            // else that happened to share the 'default' session.
            $session_id = $session_id ?? wp_generate_uuid4();
            $out        = ($def['handler'])($op_args, [
                'session_id' => $session_id,
                'tool_name'  => sprintf('%s-write', $this->integration()),
                'operation'  => $op,
            ]);
            $ids        = array_values((array) ($out['operation_ids'] ?? []));

            return $this->ok($op, $out['result'] ?? null) + [
                'operation_ids' => $ids,
                'session_id'    => $session_id,
                'recoverable'   => [] !== $ids,
            ];
        }

        $session_id = $session_id ?? 'default';
        $target     = isset($def['snapshot']) ? ($def['snapshot'])($op_args) : null;

        if (null === $target) {
            return $this->ok($op, ($def['handler'])($op_args, [ 'session_id' => $session_id, 'target' => null ])) + [ 'recoverable' => false ];
        }

        // Generated here rather than inside Safe_Mutation so the handler can
        // void its own undo point (Snapshot_Store::delete_operation()) when
        // its write is refused after the snapshot was persisted.
        $operation_id = wp_generate_uuid4();
        $context      = [
            'operation_id' => $operation_id,
            'object_type'  => (string) $target['object_type'],
            'object_id'    => $target['object_id'],
            'session_id'   => $session_id,
            'tool_name'    => sprintf('%s-write', $this->integration()),
            'args'         => [ 'operation' => $op, 'args' => $op_args ],
        ];
        // A target whose object type needs caller-captured recovery data (a
        // db_rows before-image for a row in a host plugin's own table) hands
        // it over here; Safe_Mutation merges it into the persisted snapshot.
        if (! empty($target['extra_snapshot_data']) && is_array($target['extra_snapshot_data'])) {
            $context['extra_snapshot_data'] = $target['extra_snapshot_data'];
        }

        $out = Safe_Mutation::run($context, fn () => ($def['handler'])($op_args, [ 'session_id' => $session_id, 'target' => $target, 'operation_id' => $operation_id ]));

        return $this->ok($op, $out['result']) + [
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /**
     * Evaluate an op's optional 'requires' dependency check. Returns true when
     * the op has no check or the check passes, otherwise the
     * ['code', 'message'] payload the dispatcher turns into a top-level error.
     *
     * @return true|array<string, string>
     */
    private static function op_requirement(array $def)
    {
        if (! isset($def['requires'])) {
            return true;
        }
        if (! is_callable($def['requires'])) {
            // Fail CLOSED. A present-but-malformed gate (the easy misreading is
            // 'requires' => self::check(), which stores the RESULT rather than
            // the callable) must not silently delete the gate and let the
            // handler fatal on a class the dependency was meant to guarantee.
            return [
                'code'    => 'dependency_check_invalid',
                'message' => 'This operation declares a dependency check that is not callable, so the dependency cannot be verified and the operation is refused.',
            ];
        }
        try {
            $out = ($def['requires'])();
        } catch (\Throwable $e) {
            // list-operations must answer for every integration, host plugin or
            // not, so a throwing check degrades to "unavailable", never a fatal.
            return [
                'code'    => 'dependency_unavailable',
                'message' => sprintf('This operation\'s dependency check could not complete: %s', $e->getMessage()),
            ];
        }
        return true === $out ? true : (array) $out;
    }

    /** Ops this install may run: every op whose tier the registrar permits. */
    private function permitted_operations(): array
    {
        return array_filter(
            $this->operations(),
            static fn (array $def): bool => Registrar::tier_permitted((string) ($def['tier'] ?? 'free'))
        );
    }

    /** Ops visible to one dispatcher half; write sees write + destructive. */
    private function channel_operations(string $channel): array
    {
        $out = [];
        foreach ($this->permitted_operations() as $name => $def) {
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
