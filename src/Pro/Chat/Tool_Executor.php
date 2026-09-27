<?php

namespace WPMCP\Pro\Chat;

use WPMCP\MCP\Ability;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Runs one chat tool call through the governed ability path (issue #73).
 *
 * There is no second execution path here. A call is resolved against the
 * advertised inventory and then handed to the registered WP_Ability's own
 * execute(), which is the same entry point the MCP adapter's tools/call and
 * the core abilities REST run controller use. That one call applies, in
 * order: input validation against the ability schema, the permission
 * callback (Registrar::is_permitted(): tier, capability, the six-layer
 * governance walk, identity scope, project-memory blocks, audited), and the
 * wrapped execute callback (Rate_Limiter, the Request_Log outcome row, and
 * the tool itself, whose writes go through Safe_Mutation's snapshot first).
 * Everything runs under Chat_Identity, so identity narrowing applies and the
 * audit log attributes the call to the chat.
 *
 * On top of that shared path the chat adds exactly one extra requirement:
 * any ability that is not a pure read needs a single-use Approval_Gate token
 * bound to this user, this ability and these exact arguments. The gate is
 * derived from the ability's own operation and annotations, never from a
 * list, so a mutating ability is covered the day it ships. The token is
 * checked and consumed HERE, inside the executor, so no caller can reach a
 * mutating execute() without one.
 */
final class Tool_Executor
{
    public const OK                = 'ok';
    public const ERROR             = 'error';
    public const APPROVAL_REQUIRED = 'approval_required';

    public function __construct(
        private ?Tool_Inventory $inventory = null,
        private ?Approval_Gate $gate = null
    ) {
    }

    private function inventory(): Tool_Inventory
    {
        return $this->inventory ??= new Tool_Inventory();
    }

    private function gate(): Approval_Gate
    {
        return $this->gate ??= new Approval_Gate();
    }

    /**
     * Whether a call to this ability needs a human approval token. Anything
     * that is not a plain read does: the approval gate keys off the type
     * system, so an ability declared as an update or annotated as not
     * read-only is gated without anyone remembering to list it.
     */
    public static function requires_approval(Ability $ability): bool
    {
        return 'read' !== $ability->operation
            || ! $ability->read_only_hint
            || $ability->destructive_hint;
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: string, result?: mixed, code?: string, message?: string}
     */
    public function execute(int $user_id, string $ability_name, array $args, ?string $approval_token = null): array
    {
        // The governed path checks capabilities against the CURRENT user. A
        // caller asking to act for anyone else is refused outright rather
        // than silently evaluated against a different account.
        if ($user_id <= 0 || get_current_user_id() !== $user_id) {
            return self::error('user_mismatch', 'Tool calls run as the signed-in administrator only.');
        }

        return Chat_Identity::run(function () use ($user_id, $ability_name, $args, $approval_token): array {
            $ability = $this->inventory()->resolve_ability($ability_name);
            if (null === $ability) {
                return self::error(
                    'tool_not_available',
                    sprintf('The tool %s is not available to this chat.', $ability_name)
                );
            }

            if (self::requires_approval($ability)) {
                if (null === $approval_token || '' === $approval_token) {
                    return ['status' => self::APPROVAL_REQUIRED];
                }
                if (! $this->gate()->validate_and_consume($approval_token, $user_id, $ability->name, $args)) {
                    return self::error(
                        'invalid_approval',
                        'The approval is invalid, expired, already used, or for a different call.'
                    );
                }
            }

            if (! function_exists('wp_get_ability')) {
                return self::error('abilities_api_missing', 'The WordPress Abilities API is not available.');
            }
            $registered = wp_get_ability($ability->name);
            if (null === $registered) {
                return self::error('tool_not_available', sprintf('The tool %s is not registered.', $ability->name));
            }

            // The MCP adapter normalises arguments this way before calling
            // execute(), so the chat hands abilities exactly what an external
            // MCP client's tools/call would.
            $input = $args;
            if (class_exists('\WP\MCP\Domain\Utils\AbilityArgumentNormalizer')) {
                $input = \WP\MCP\Domain\Utils\AbilityArgumentNormalizer::normalize($registered, $args);
            }

            try {
                $result = $registered->execute($input);
            } catch (\Throwable $e) {
                return self::error('execution_failed', $e->getMessage());
            }

            if (is_wp_error($result)) {
                return self::error((string) $result->get_error_code(), (string) $result->get_error_message());
            }

            return ['status' => self::OK, 'result' => $result];
        });
    }

    /** @return array{status: string, code: string, message: string} */
    private static function error(string $code, string $message): array
    {
        return ['status' => self::ERROR, 'code' => $code, 'message' => $message];
    }
}
